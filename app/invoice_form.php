<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();

$docType = $_GET['type'] ?? ($_POST['doc_type'] ?? 'invoice');
if (!in_array($docType, ['invoice','proforma','quotation','purchase','delivery_challan'], true)) $docType = 'invoice';
enforce_permission($db, $tid, Auth::user()['role'], $docType === 'purchase' ? 'purchases' : 'sales', $docType === 'purchase' ? 'Purchase Bills' : 'Sales Documents');
$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$fromId = (int)($_GET['from'] ?? 0); // convert quotation/proforma -> invoice

$typeLabels = ['invoice'=>'Invoice','proforma'=>'Proforma Invoice','quotation'=>'Quotation','purchase'=>'Purchase Bill','delivery_challan'=>'Delivery Challan'];
$pageTitle = ($editId ? 'Edit ' : 'New ') . $typeLabels[$docType];
$activeNav = $docType;

$tenantStmt = $db->prepare("SELECT state FROM tenants WHERE id=?");
$tenantStmt->execute([$tid]);
$tenantState = $tenantStmt->fetch()['state'] ?? '';

$settingsStmt = $db->prepare("SELECT terms_conditions FROM tenant_settings WHERE tenant_id=?");
$settingsStmt->execute([$tid]);
$tenantSettings = $settingsStmt->fetch();
$defaultTerms = $tenantSettings['terms_conditions'] ?? '';

$partyTypes = $docType === 'purchase' ? ['supplier', 'both'] : ['customer', 'both'];
$partiesStmt = $db->prepare("SELECT id,name,phone,gstin,state,balance_type,opening_balance FROM parties WHERE tenant_id=? AND is_active=1 AND type IN (?,?) ORDER BY name");
$partiesStmt->execute([$tid, $partyTypes[0], $partyTypes[1]]);
$parties = $partiesStmt->fetchAll();

$locStmt = $db->prepare("SELECT id, name, is_default FROM locations WHERE tenant_id=? AND is_active=1 ORDER BY is_default DESC, name");
$locStmt->execute([$tid]);
$formLocations = $locStmt->fetchAll();

/**
 * For any line item typed manually (no product_id from search/browse),
 * find an existing product with the same name, or create a new one so it
 * enters inventory going forward. This is what makes buying/selling a
 * brand-new item automatically show up in Products & Services, and lets
 * apply_stock() actually have something to update.
 */
function resolve_or_create_product(PDO $db, int $tid, string $docType, array &$items): void
{
    if (!in_array($docType, ['invoice', 'purchase', 'delivery_challan'], true)) return;

    $findStmt = $db->prepare("SELECT id FROM products WHERE tenant_id=? AND LOWER(name)=LOWER(?) AND is_active=1 LIMIT 1");
    $insStmt = $db->prepare("INSERT INTO products (tenant_id, name, type, hsn_sac, unit, sale_price, purchase_price, tax_rate, opening_stock, current_stock, low_stock_alert)
                              VALUES (?,?,'product',?,?,?,?,?,0,0,5)");

    foreach ($items as &$it) {
        if (!empty($it['product_id'])) continue;

        $findStmt->execute([$tid, $it['description']]);
        $existing = $findStmt->fetch();
        if ($existing) {
            $it['product_id'] = (int)$existing['id'];
            continue;
        }

        $salePrice = $docType === 'purchase' ? $it['rate'] : $it['rate'];
        $purchasePrice = $docType === 'purchase' ? $it['rate'] : 0;
        $insStmt->execute([$tid, $it['description'], $it['hsn_sac'], $it['unit'], $salePrice, $purchasePrice, $it['tax_rate']]);
        $it['product_id'] = (int)$db->lastInsertId();
    }
    unset($it);
}

/**
 * Resolves a batch_id for each item that needs one. On a Purchase Bill,
 * a filled-in batch number creates/finds that batch (new stock coming in).
 * On a sale (Invoice/Delivery Challan) of a batch-tracked product, the
 * earliest-expiring available batch is picked automatically (FEFO) so
 * staff never have to think about which lot to sell from.
 */
function resolve_batches_for_items(PDO $db, int $tid, string $docType, array &$items): void
{
    $trackedStmt = $db->prepare("SELECT track_batches FROM products WHERE id=? AND tenant_id=?");

    foreach ($items as &$it) {
        if (empty($it['product_id'])) continue;

        if ($docType === 'purchase') {
            $batchNo = trim($it['batch_no'] ?? '');
            if ($batchNo !== '') {
                $it['batch_id'] = get_or_create_batch(
                    $db, $tid, (int)$it['product_id'], $batchNo,
                    $it['batch_mfg_date'] ?: null, $it['batch_expiry_date'] ?: null, (float)$it['rate']
                );
            }
        } elseif (in_array($docType, ['invoice', 'delivery_challan'], true)) {
            $trackedStmt->execute([$it['product_id'], $tid]);
            $prod = $trackedStmt->fetch();
            if ($prod && (int)$prod['track_batches'] === 1) {
                $available = get_available_batches($db, (int)$it['product_id']);
                if ($available) {
                    $it['batch_id'] = (int)$available[0]['id']; // earliest expiry first
                }
            }
        }
    }
    unset($it);
}

/** apply_stock() now lives in includes/functions.php - shared with pos.php */

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $partyId = (int)($_POST['party_id'] ?? 0) ?: null;
    $docDate = $_POST['doc_date'] ?? today();
    $dueDate = $_POST['due_date'] ?? null;
    $locationId = (int)($_POST['location_id'] ?? 0) ?: null;
    $notes = trim($_POST['notes'] ?? '');
    $terms = trim($_POST['terms'] ?? '');
    $vehicleNo = trim($_POST['vehicle_no'] ?? '');
    $transportMode = in_array($_POST['transport_mode'] ?? '', ['road','rail','air','ship'], true) ? $_POST['transport_mode'] : null;
    $ewayBillNo = trim($_POST['eway_bill_no'] ?? '');
    $rawItems = $_POST['items'] ?? [];
    $finalize = ($_POST['submit_action'] ?? 'final') === 'final';

    // Determine same-state (intra vs inter GST)
    $partyState = '';
    if ($partyId) {
        foreach ($parties as $p) { if ($p['id'] == $partyId) { $partyState = $p['state']; break; } }
    }
    $sameState = $partyState !== '' ? (strcasecmp($partyState, $tenantState) === 0) : true;

    $items = [];
    $subtotal = 0; $discountTotal = 0; $taxTotal = 0;
    $factorStmt = $db->prepare("SELECT unit, purchase_unit_factor FROM products WHERE id=? AND tenant_id=?");

    foreach ($rawItems as $row) {
        $desc = trim($row['description'] ?? '');
        $qty = (float)($row['qty'] ?? 0);
        if ($desc === '' || $qty <= 0) continue;
        $rate = (float)($row['rate'] ?? 0);
        $unitLabel = trim($row['unit'] ?? 'pcs') ?: 'pcs';

        // Unit conversion: if this row was entered in the product purchase
        // unit (e.g. "Box"), convert qty/rate to base units now so everything
        // downstream (tax calc, stock ledger, invoice_items) stays consistent
        // no matter which unit was used to enter it.
        if (($row['unit_mode'] ?? 'base') === 'purchase' && !empty($row['product_id'])) {
            $factorStmt->execute([$row['product_id'], $tid]);
            $prodRow = $factorStmt->fetch();
            if ($prodRow && (float)$prodRow['purchase_unit_factor'] > 0) {
                $factor = (float)$prodRow['purchase_unit_factor'];
                $qty = $qty * $factor;
                $rate = $rate / $factor;
                $unitLabel = $prodRow['unit'];
            }
        }

        $discPct = (float)($row['discount_percent'] ?? 0);
        $taxRate = (float)($row['tax_rate'] ?? 0);
        $lineGross = $qty * $rate;
        $lineDisc = round($lineGross * $discPct / 100, 2);
        $taxable = $lineGross - $lineDisc;
        $gst = calc_gst($taxable, $taxRate, $sameState);
        $lineTotal = round($taxable + $gst['total_tax'], 2);

        $items[] = [
            'product_id' => $row['product_id'] ?: null,
            'description' => $desc,
            'hsn_sac' => trim($row['hsn_sac'] ?? ''),
            'qty' => $qty,
            'unit' => $unitLabel,
            'rate' => $rate,
            'discount_percent' => $discPct,
            'discount_amount' => $lineDisc,
            'tax_rate' => $taxRate,
            'cgst_amount' => $gst['cgst'],
            'sgst_amount' => $gst['sgst'],
            'igst_amount' => $gst['igst'],
            'total' => $lineTotal,
            'batch_no' => trim($row['batch_no'] ?? ''),
            'batch_mfg_date' => $row['batch_mfg_date'] ?? '',
            'batch_expiry_date' => $row['batch_expiry_date'] ?? '',
            'location_id' => $locationId,
        ];
        $subtotal += $lineGross;
        $discountTotal += $lineDisc;
        $taxTotal += $gst['total_tax'];
    }

    if (!$items) {
        $errors[] = 'Please add at least one valid item (with description and quantity).';
    }
    if ($docType !== 'quotation' && !$partyId) {
        $errors[] = $docType === 'purchase' ? 'Please select a supplier.' : 'Please select a customer.';
    }

    if (!$errors) {
        $grandRaw = $subtotal - $discountTotal + $taxTotal;
        $grandRounded = round($grandRaw);
        $roundOff = round($grandRounded - $grandRaw, 2);

        $db->beginTransaction();
        try {
            $wasFinal = false;
            if ($editId) {
                $oldInvStmt = $db->prepare("SELECT status FROM invoices WHERE id=? AND tenant_id=?");
                $oldInvStmt->execute([$editId, $tid]);
                $oldInv = $oldInvStmt->fetch();
                $wasFinal = $oldInv && $oldInv['status'] !== 'draft';

                // Only reverse the old stock effect if it was actually applied before
                // (i.e. this document was still a draft last time it was saved).
                if ($wasFinal) {
                    $oldItemsStmt = $db->prepare("SELECT product_id, qty, batch_id, location_id FROM invoice_items WHERE invoice_id=?");
                    $oldItemsStmt->execute([$editId]);
                    $oldItems = $oldItemsStmt->fetchAll();
                    apply_stock($db, $tid, $docType, $oldItems, -1, $editId);
                }

                $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$editId]);

                $status = in_array($docType, ['quotation', 'proforma', 'delivery_challan'], true) ? ($finalize ? 'final' : 'draft') : ($finalize ? 'unpaid' : 'draft');
                $stmt = $db->prepare("UPDATE invoices SET party_id=?, doc_date=?, due_date=?, place_of_supply=?, vehicle_no=?, transport_mode=?, eway_bill_no=?, status=?, subtotal=?, discount_amount=?, tax_amount=?, round_off=?, total_amount=?, notes=?, terms=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$partyId, $docDate, $dueDate ?: null, $partyState ?: $tenantState, $vehicleNo ?: null, $transportMode, $ewayBillNo ?: null, $status, $subtotal, $discountTotal, $taxTotal, $roundOff, $grandRounded, $notes, $terms, $editId, $tid]);
                $invoiceId = $editId;
            } else {
                $docNo = next_doc_number($db, $tid, $docType);
                $status = in_array($docType, ['quotation', 'proforma', 'delivery_challan'], true) ? ($finalize ? 'final' : 'draft') : ($finalize ? 'unpaid' : 'draft');
                $stmt = $db->prepare("INSERT INTO invoices (tenant_id, doc_type, doc_no, party_id, doc_date, due_date, place_of_supply, vehicle_no, transport_mode, eway_bill_no, status, subtotal, discount_amount, tax_amount, round_off, total_amount, notes, terms, ref_invoice_id, created_by)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tid, $docType, $docNo, $partyId, $docDate, $dueDate ?: null, $partyState ?: $tenantState, $vehicleNo ?: null, $transportMode, $ewayBillNo ?: null, $status, $subtotal, $discountTotal, $taxTotal, $roundOff, $grandRounded, $notes, $terms, $fromId ?: null, Auth::user()['id']]);
                $invoiceId = (int)$db->lastInsertId();
            }

            resolve_or_create_product($db, $tid, $docType, $items);
            resolve_batches_for_items($db, $tid, $docType, $items);

            // Snapshot each item current product cost, so profit/loss
            // reporting stays accurate even if purchase prices change later.
            $costStmt = $db->prepare("SELECT purchase_price FROM products WHERE id=? AND tenant_id=?");
            foreach ($items as &$it) {
                $it['cost_price'] = 0;
                if (!empty($it['product_id'])) {
                    $costStmt->execute([$it['product_id'], $tid]);
                    $costRow = $costStmt->fetch();
                    $it['cost_price'] = $costRow ? (float)$costRow['purchase_price'] : 0;
                }
            }
            unset($it);

            $sort = 0;
            $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, product_id, batch_id, location_id, description, hsn_sac, qty, unit, rate, cost_price, discount_percent, discount_amount, tax_rate, cgst_amount, sgst_amount, igst_amount, total, sort_order)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($items as $it) {
                $itemStmt->execute([$invoiceId, $it['product_id'], $it['batch_id'] ?? null, $it['location_id'] ?? null, $it['description'], $it['hsn_sac'], $it['qty'], $it['unit'], $it['rate'], $it['cost_price'], $it['discount_percent'], $it['discount_amount'], $it['tax_rate'], $it['cgst_amount'], $it['sgst_amount'], $it['igst_amount'], $it['total'], $sort++]);
            }

            // Only affect real inventory once this document is finalized -
            // a draft is not yet a real transaction and should never move stock.
            if ($finalize) {
                apply_stock($db, $tid, $docType, $items, 1, $invoiceId);
            }

            $db->commit();
            flash('success', $typeLabels[$docType] . ' saved successfully.');
            redirect(base_url('app/invoice_view.php?id=' . $invoiceId));
        } catch (Throwable $ex) {
            $db->rollBack();
            $errors[] = 'Something went wrong saving this document: ' . $ex->getMessage();
        }
    }
}

// ---- Load data for edit or convert-from ----
$invoice = null;
$loadedItems = [];
$loadId = $editId ?: $fromId;
if ($loadId) {
    $stmt = $db->prepare("SELECT * FROM invoices WHERE id=? AND tenant_id=?");
    $stmt->execute([$loadId, $tid]);
    $invoice = $stmt->fetch();
    if ($invoice) {
        $itStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
        $itStmt->execute([$loadId]);
        $loadedItems = $itStmt->fetchAll();
        // location_id lives per-line on invoice_items (all lines share the
        // same one in practice, set from the document-level selector), so
        // derive it from the first line for prefill purposes on edit.
        $invoice['location_id'] = $loadedItems[0]['location_id'] ?? null;
    }
}

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e($pageTitle) ?></h1>
    <div class="sub">GST auto-calculated based on customer state vs. your business state (<?= e($tenantState ?: 'not set') ?>)</div>
  </div>
  <?php if (!$editId && $docType === 'invoice'): ?>
  <a href="<?= base_url('app/pos.php') ?>" class="btn btn-outline">🖥️ Switch to POS Billing</a>
  <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="doc-type-tabs">
  <a href="<?= base_url('app/invoice_form.php?type=invoice') ?>" class="<?= $docType==='invoice'?'active':'' ?>">Invoice</a>
  <a href="<?= base_url('app/invoice_form.php?type=proforma') ?>" class="<?= $docType==='proforma'?'active':'' ?>">Proforma</a>
  <a href="<?= base_url('app/invoice_form.php?type=quotation') ?>" class="<?= $docType==='quotation'?'active':'' ?>">Quotation</a>
  <a href="<?= base_url('app/invoice_form.php?type=purchase') ?>" class="<?= $docType==='purchase'?'active':'' ?>">Purchase Bill</a>
  <a href="<?= base_url('app/invoice_form.php?type=delivery_challan') ?>" class="<?= $docType==='delivery_challan'?'active':'' ?>">Delivery Challan</a>
</div>

<form method="post" class="invoice-builder" id="invForm">
  <?= csrf_field() ?>
  <input type="hidden" name="doc_type" value="<?= e($docType) ?>">
  <?php if ($editId): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>

  <div class="card card-pad" style="margin-bottom:16px">
    <div class="form-row">
      <div class="form-group">
        <label><?= $docType === 'purchase' ? 'Supplier' : 'Customer' ?> <?= $docType !== 'quotation' ? '*' : '' ?></label>
        <div style="display:flex;gap:6px">
          <select class="form-control" name="party_id" id="partySelect" required="<?= $docType !== 'quotation' ?>">
            <option value="">-- Select --</option>
            <?php $prefillPartyId = $invoice ? (int)$invoice['party_id'] : (int)($_GET['party_id'] ?? 0); ?>
            <?php foreach ($parties as $p): ?>
              <option value="<?= (int)$p['id'] ?>" data-state="<?= e($p['state']) ?>"
                <?= ($prefillPartyId === (int)$p['id']) ? 'selected' : '' ?>>
                <?= e($p['name']) ?><?= $p['phone'] ? ' — ' . e($p['phone']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-outline btn-sm" style="white-space:nowrap" onclick="document.getElementById('quickAddModal').style.display='flex'">+ New</button>
        </div>
      </div>
      <div class="form-group">
        <label>Date</label>
        <input class="form-control" type="date" name="doc_date" value="<?= e($invoice['doc_date'] ?? today()) ?>">
      </div>
      <?php if ($docType === 'invoice' || $docType === 'purchase'): ?>
      <div class="form-group">
        <label>Due Date</label>
        <input class="form-control" type="date" name="due_date" value="<?= e($invoice['due_date'] ?? '') ?>">
      </div>
      <?php endif; ?>
      <?php if (count($formLocations) > 1 && in_array($docType, ['invoice', 'purchase', 'delivery_challan'], true)): ?>
      <div class="form-group">
        <label>Location</label>
        <select class="form-control" name="location_id">
          <?php $prefillLocId = $invoice ? (int)($invoice['location_id'] ?? 0) : 0; ?>
          <?php foreach ($formLocations as $l): ?>
            <option value="<?= (int)$l['id'] ?>" <?= ($prefillLocId ? $prefillLocId === (int)$l['id'] : $l['is_default']) ? 'selected' : '' ?>><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
    <?php if (in_array($docType, ['invoice', 'purchase', 'delivery_challan'], true)): ?>
    <div class="form-row" style="margin-top:6px">
      <div class="form-group">
        <label>Vehicle Number <span class="hint">(optional)</span></label>
        <input class="form-control" name="vehicle_no" placeholder="e.g. WB73A1234" value="<?= e($invoice['vehicle_no'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Transport Mode</label>
        <select class="form-control" name="transport_mode">
          <option value="">-- Not specified --</option>
          <?php foreach (['road'=>'Road','rail'=>'Rail','air'=>'Air','ship'=>'Ship'] as $val => $label): ?>
            <option value="<?= $val ?>" <?= (($invoice['transport_mode'] ?? '') === $val) ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>E-Way Bill No. <span class="hint">(from govt. portal, optional)</span></label>
        <input class="form-control" name="eway_bill_no" placeholder="12-digit e-way bill number" value="<?= e($invoice['eway_bill_no'] ?? '') ?>">
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card card-pad" style="margin-bottom:16px">
    <div class="table-wrap" style="border:0">
      <table class="data items-table" id="itemsTable">
        <thead>
          <tr>
            <th style="min-width:200px">Item</th>
            <th>HSN/SAC</th>
            <th class="num">Qty</th>
            <th>Unit</th>
            <?php if ($docType === 'purchase'): ?>
            <th>Batch No</th>
            <th>Expiry</th>
            <?php endif; ?>
            <th class="num">Rate</th>
            <th class="num">Disc %</th>
            <th class="num">Tax %</th>
            <th class="num">Amount</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="itemsBody"></tbody>
      </table>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <button type="button" class="btn btn-outline btn-sm" onclick="addRow()">+ Add Row</button>
      <button type="button" class="btn btn-outline btn-sm" onclick="openBrowseModal()">📋 Browse Products</button>
    </div>
  </div>

  <div class="card card-pad" style="margin-bottom:16px">
    <div class="form-row">
      <div class="form-group"><label>Notes</label><textarea class="form-control" name="notes" rows="2"><?= e($invoice['notes'] ?? '') ?></textarea></div>
      <div class="form-group"><label>Terms &amp; Conditions</label><textarea class="form-control" name="terms" rows="2"><?= e($invoice['terms'] ?? $defaultTerms) ?></textarea></div>
    </div>
    <div class="totals-box">
      <div class="row"><span>Subtotal</span><span id="tSubtotal">₹0.00</span></div>
      <div class="row"><span>Discount</span><span id="tDiscount">₹0.00</span></div>
      <div class="row"><span>Tax (GST)</span><span id="tTax">₹0.00</span></div>
      <div class="row"><span>Round Off</span><span id="tRound">₹0.00</span></div>
      <div class="row grand"><span>Total</span><span id="tGrand">₹0.00</span></div>
    </div>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <button type="submit" name="submit_action" value="final" class="btn btn-primary">Save &amp; Finalize</button>
    <button type="submit" name="submit_action" value="draft" class="btn btn-outline">Save as Draft</button>
    <a href="<?= base_url('app/invoices.php?type=' . $docType) ?>" class="btn btn-outline">Cancel</a>
  </div>
</form>

<div id="quickAddModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:380px;width:100%">
    <h3 style="margin-bottom:6px">Add <?= $docType === 'purchase' ? 'Supplier' : 'Customer' ?></h3>
    <p class="hint" style="margin-bottom:14px">For a walk-in sale, phone number alone is enough - name is optional.</p>
    <div class="form-group"><label>Phone Number *</label><input class="form-control" type="text" id="qa_phone" placeholder="e.g. 9830011122" required></div>
    <div class="form-group"><label>Name (optional)</label><input class="form-control" type="text" id="qa_name" placeholder="Leave blank for walk-in"></div>
    <div id="qa_error" class="alert alert-error" style="display:none"></div>
    <div style="display:flex;gap:10px">
      <button type="button" class="btn btn-primary" onclick="quickAddParty()">Add &amp; Select</button>
      <button type="button" class="btn btn-outline" onclick="document.getElementById('quickAddModal').style.display='none'">Cancel</button>
    </div>
  </div>
</div>

<script>
async function quickAddParty(){
  const phone = document.getElementById('qa_phone').value.trim();
  const name = document.getElementById('qa_name').value.trim();
  const errBox = document.getElementById('qa_error');
  errBox.style.display = 'none';
  if (!phone) { errBox.textContent = 'Please enter a phone number.'; errBox.style.display = 'block'; return; }

  const form = new URLSearchParams();
  form.set('phone', phone);
  form.set('name', name);
  form.set('type', '<?= $docType === 'purchase' ? 'supplier' : 'customer' ?>');
  form.set('csrf_token', document.querySelector('input[name="csrf_token"]').value);

  try {
    const res = await fetch('<?= base_url('app/ajax_party_quickadd.php') ?>', { method: 'POST', body: form });
    const data = await res.json();
    if (!res.ok) {
      errBox.textContent = data.error || 'Could not add this contact.';
      errBox.style.display = 'block';
      return;
    }
    const select = document.getElementById('partySelect');
    let opt = Array.from(select.options).find(o => o.value == data.id);
    if (!opt) {
      opt = document.createElement('option');
      opt.value = data.id;
      select.appendChild(opt);
    }
    opt.textContent = data.name + (data.phone ? ' — ' + data.phone : '');
    select.value = data.id;
    document.getElementById('quickAddModal').style.display = 'none';
    document.getElementById('qa_phone').value = '';
    document.getElementById('qa_name').value = '';
  } catch (err) {
    errBox.textContent = 'Network error - please try again.';
    errBox.style.display = 'block';
  }
}
</script>

<div id="browseModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:640px;width:100%;max-height:85vh;display:flex;flex-direction:column">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h3>Browse Products &amp; Services</h3>
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('browseModal').style.display='none'">Done</button>
    </div>
    <input type="text" id="browseSearch" class="form-control" placeholder="Filter by name or SKU..." style="margin-bottom:10px">
    <div id="browseList" style="overflow-y:auto;flex:1;border:1px solid #e2ddd0;border-radius:6px">
      <div style="padding:20px;text-align:center;color:#8a93ab">Loading...</div>
    </div>
    <p class="hint" style="margin-top:8px">Tap an item to add it as a new row. You can keep tapping to add several, then click Done.</p>
  </div>
</div>

<script>
let browseAllItems = [];

async function openBrowseModal(){
  document.getElementById('browseModal').style.display = 'flex';
  if (!browseAllItems.length) {
    const res = await fetch(searchUrl);
    const data = await res.json();
    browseAllItems = data.items || [];
  }
  renderBrowseList(browseAllItems);
}

function renderBrowseList(items){
  const box = document.getElementById('browseList');
  if (!items.length) {
    box.innerHTML = '<div style="padding:20px;text-align:center;color:#8a93ab">No products found.</div>';
    return;
  }
  box.innerHTML = items.map((p, i) => `<div style="padding:10px 14px;border-bottom:1px solid #f0ece0;cursor:pointer;display:flex;justify-content:space-between;align-items:center"
      data-i="${i}" class="browse-row">
      <div>
        <div style="font-weight:600;font-size:.9rem">${p.name}</div>
        <div style="font-size:.75rem;color:#8a93ab">${p.type === 'product' ? 'Stock: ' + p.current_stock : 'Service'} ${p.sku ? '· SKU: ' + p.sku : ''}</div>
      </div>
      <div style="font-weight:700;color:#c8862b">₹${p.sale_price}${p.price_type === 'inclusive' ? ' <span style="font-size:.65rem;color:#8a93ab">(incl. GST)</span>' : ''}</div>
    </div>`).join('');
  box.dataset.items = JSON.stringify(items);
}

document.getElementById('browseList').addEventListener('click', (e) => {
  const row = e.target.closest('.browse-row');
  if (!row) return;
  const items = JSON.parse(document.getElementById('browseList').dataset.items || '[]');
  const p = items[parseInt(row.dataset.i, 10)];
  if (!p) return;
  addRow({
    product_id: p.id,
    description: p.name,
    hsn_sac: p.hsn_sac || '',
    qty: 1,
    unit: p.unit,
    rate: exclusiveRate(p),
    discount_percent: 0,
    tax_rate: p.tax_rate,
  });
});

let browseFilterTimer;
document.getElementById('browseSearch').addEventListener('input', (e) => {
  clearTimeout(browseFilterTimer);
  const q = e.target.value.trim().toLowerCase();
  browseFilterTimer = setTimeout(() => {
    if (!q) { renderBrowseList(browseAllItems); return; }
    renderBrowseList(browseAllItems.filter(p => p.name.toLowerCase().includes(q) || (p.sku||'').toLowerCase().includes(q)));
  }, 150);
});
</script>

<script>
const existingItems = <?= json_encode(array_map(function($it){
  return [
    'product_id' => $it['product_id'],
    'description' => $it['description'],
    'hsn_sac' => $it['hsn_sac'],
    'qty' => (float)$it['qty'],
    'unit' => $it['unit'],
    'rate' => (float)$it['rate'],
    'discount_percent' => (float)$it['discount_percent'],
    'tax_rate' => (float)$it['tax_rate'],
  ];
}, $loadedItems)) ?>;
const searchUrl = '<?= base_url('app/ajax_product_search.php') ?>';
const isPurchaseDoc = <?= $docType === 'purchase' ? 'true' : 'false' ?>;
let rowIndex = 0;

function rowTemplate(idx, it){
  it = it || {product_id:'',description:'',hsn_sac:'',qty:1,unit:'pcs',rate:0,discount_percent:0,tax_rate:0};
  const batchCells = isPurchaseDoc ? `
    <td><input type="text" class="form-control" name="items[${idx}][batch_no]" placeholder="Optional"></td>
    <td><input type="date" class="form-control" name="items[${idx}][batch_expiry_date]" style="width:130px"></td>` : '';
  return `<tr data-idx="${idx}">
    <td style="position:relative">
      <input type="hidden" name="items[${idx}][product_id]" class="pid" value="${it.product_id||''}">
      <input type="hidden" name="items[${idx}][unit_mode]" class="unit-mode" value="base">
      <input type="hidden" name="items[${idx}][batch_mfg_date]" class="batch-mfg" value="">
      <input type="text" class="form-control desc" name="items[${idx}][description]" value="${it.description}" placeholder="Search or type item name" autocomplete="off" oninput="searchProduct(this)" onblur="scheduleHideSuggest()">
      <div class="unit-toggle" style="display:none;font-size:.72rem;margin-top:3px;color:#c8862b;cursor:pointer"></div>
    </td>
    <td><input type="text" class="form-control" name="items[${idx}][hsn_sac]" value="${it.hsn_sac||''}"></td>
    <td><input type="number" step="0.001" class="form-control qty" name="items[${idx}][qty]" value="${it.qty}" oninput="calcRow(this)"></td>
    <td><input type="text" class="form-control unit-label" style="width:70px" name="items[${idx}][unit]" value="${it.unit}"></td>${batchCells}
    <td><input type="number" step="0.01" class="form-control rate" name="items[${idx}][rate]" value="${it.rate}" oninput="calcRow(this)"></td>
    <td><input type="number" step="0.01" class="form-control disc" name="items[${idx}][discount_percent]" value="${it.discount_percent}" oninput="calcRow(this)"></td>
    <td><input type="number" step="0.01" class="form-control tax" name="items[${idx}][tax_rate]" value="${it.tax_rate}" oninput="calcRow(this)"></td>
    <td class="num lineTotal">₹0.00</td>
    <td><button type="button" class="remove-row" onclick="removeRow(this)">✕</button></td>
  </tr>`;
}

function addRow(it){
  document.getElementById('itemsBody').insertAdjacentHTML('beforeend', rowTemplate(rowIndex, it));
  rowIndex++;
  calcAll();
}
function removeRow(btn){ btn.closest('tr').remove(); calcAll(); }

/**
 * Toggles a purchase-bill row between entering quantity in the base unit
 * (e.g. "pcs") or the product purchase unit (e.g. "Box of 12"). Only
 * appears when the selected product actually has a purchase unit set -
 * see selectProduct() below. Qty/rate are converted purely for display;
 * the server re-does the real conversion from unit_mode + product data,
 * so this preview never has to be perfectly precise to stay correct.
 */
function toggleUnitMode(tr, baseUnit, purchaseUnit, factor){
  const modeInput = tr.querySelector('.unit-mode');
  const unitLabel = tr.querySelector('.unit-label');
  const qtyInput = tr.querySelector('.qty');
  const rateInput = tr.querySelector('.rate');
  const toggle = tr.querySelector('.unit-toggle');
  const toPurchase = modeInput.value !== 'purchase';

  const qty = parseFloat(qtyInput.value) || 0;
  const rate = parseFloat(rateInput.value) || 0;
  if (toPurchase) {
    modeInput.value = 'purchase';
    unitLabel.value = purchaseUnit;
    qtyInput.value = (qty / factor).toFixed(3).replace(/\.?0+$/, '') || (qty / factor);
    rateInput.value = (rate * factor).toFixed(2);
  } else {
    modeInput.value = 'base';
    unitLabel.value = baseUnit;
    qtyInput.value = (qty * factor).toFixed(3).replace(/\.?0+$/, '') || (qty * factor);
    rateInput.value = (rate / factor).toFixed(2);
  }
  toggle.textContent = `Switch to entering in ${modeInput.value === 'purchase' ? baseUnit : purchaseUnit + ' (' + factor + ' ' + baseUnit + ' each)'}`;
  calcRow(qtyInput);
}

function calcRow(el){
  const tr = el.closest('tr');
  const qty = parseFloat(tr.querySelector('.qty').value)||0;
  const rate = parseFloat(tr.querySelector('.rate').value)||0;
  const disc = parseFloat(tr.querySelector('.disc').value)||0;
  const tax = parseFloat(tr.querySelector('.tax').value)||0;
  const gross = qty*rate;
  const discAmt = gross*disc/100;
  const taxable = gross-discAmt;
  const total = taxable + (taxable*tax/100);
  tr.querySelector('.lineTotal').textContent = '₹' + total.toFixed(2);
  calcAll();
}
function calcAll(){
  let subtotal=0, discount=0, tax=0;
  document.querySelectorAll('#itemsBody tr').forEach(tr=>{
    const qty = parseFloat(tr.querySelector('.qty').value)||0;
    const rate = parseFloat(tr.querySelector('.rate').value)||0;
    const disc = parseFloat(tr.querySelector('.disc').value)||0;
    const taxr = parseFloat(tr.querySelector('.tax').value)||0;
    const gross = qty*rate;
    const discAmt = gross*disc/100;
    const taxable = gross-discAmt;
    const taxAmt = taxable*taxr/100;
    subtotal += gross; discount += discAmt; tax += taxAmt;
  });
  const grandRaw = subtotal-discount+tax;
  const grand = Math.round(grandRaw);
  const roundOff = grand-grandRaw;
  document.getElementById('tSubtotal').textContent = '₹'+subtotal.toFixed(2);
  document.getElementById('tDiscount').textContent = '₹'+discount.toFixed(2);
  document.getElementById('tTax').textContent = '₹'+tax.toFixed(2);
  document.getElementById('tRound').textContent = '₹'+roundOff.toFixed(2);
  document.getElementById('tGrand').textContent = '₹'+grand.toFixed(2);
}

let searchTimer;
let hideTimer;
let activeRow = null;
let lastResults = [];

// Single shared suggestion box, appended to <body> and positioned with
// `fixed` coordinates computed from the active input on-screen position.
// This avoids the table overflow-x:auto container silently clipping it
// (setting overflow-x alone forces the browser to also clip overflow-y).
const suggestBox = document.createElement('div');
suggestBox.id = 'productSuggest';
suggestBox.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid #e2ddd0;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:9999;max-height:240px;overflow-y:auto';
document.body.appendChild(suggestBox);

function positionSuggestBox(input){
  const rect = input.getBoundingClientRect();
  suggestBox.style.left = rect.left + 'px';
  suggestBox.style.top = (rect.bottom + 2) + 'px';
  suggestBox.style.width = Math.max(rect.width, 260) + 'px';
}

function searchProduct(input){
  clearTimeout(searchTimer);
  clearTimeout(hideTimer);
  activeRow = input.closest('tr');
  const q = input.value.trim();
  if (q.length < 2){ suggestBox.style.display = 'none'; return; }
  searchTimer = setTimeout(async () => {
    let data;
    try {
      const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q));
      data = await res.json();
    } catch (err) {
      suggestBox.style.display = 'none';
      return;
    }
    lastResults = data.items || [];
    if (!lastResults.length) {
      suggestBox.innerHTML = '<div style="padding:10px 12px;font-size:.82rem;color:#8a93ab">No matching products - you can still type a custom item.</div>';
      positionSuggestBox(input);
      suggestBox.style.display = 'block';
      return;
    }
    suggestBox.innerHTML = lastResults.map((p, i) => `<div class="opt" style="padding:8px 12px;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f0ece0"
      data-i="${i}">
      <b>${p.name}</b> — ₹${p.sale_price} ${p.type==='product' ? '(stock: '+p.current_stock+')' : ''}
    </div>`).join('');
    positionSuggestBox(input);
    suggestBox.style.display = 'block';
  }, 200);
}

// Use mousedown (fires before the input blur) so a click can select a
// suggestion before the box gets hidden.
suggestBox.addEventListener('mousedown', (e) => {
  const opt = e.target.closest('.opt');
  if (!opt || !activeRow) return;
  e.preventDefault();
  const p = lastResults[parseInt(opt.dataset.i, 10)];
  if (p) selectProduct(activeRow, p);
  suggestBox.style.display = 'none';
});

function scheduleHideSuggest(){
  hideTimer = setTimeout(() => { suggestBox.style.display = 'none'; }, 150);
}

window.addEventListener('scroll', () => { suggestBox.style.display = 'none'; }, true);
window.addEventListener('resize', () => { suggestBox.style.display = 'none'; });

// Invoice line items are always stored/calculated as tax-exclusive (base)
// rate, so if a product price is marked "inclusive" (GST already baked
// into the sale price), we back-calculate the base rate here at selection
// time - everything downstream (calcRow, server-side calc_gst) then works
// exactly as before, unaware the product was ever priced inclusively.
function exclusiveRate(p){
  const price = parseFloat(p.sale_price) || 0;
  const tax = parseFloat(p.tax_rate) || 0;
  if (p.price_type === 'inclusive' && tax > 0) {
    return Math.round((price / (1 + tax / 100)) * 100) / 100;
  }
  return price;
}

function selectProduct(tr, p){
  tr.querySelector('.pid').value = p.id;
  tr.querySelector('.desc').value = p.name;
  tr.querySelector('[name$="[hsn_sac]"]').value = p.hsn_sac||'';
  tr.querySelector('.rate').value = exclusiveRate(p);
  tr.querySelector('.tax').value = p.tax_rate;
  tr.querySelector('.unit-label').value = p.unit;
  tr.querySelector('.unit-mode').value = 'base';

  const toggle = tr.querySelector('.unit-toggle');
  if (isPurchaseDoc && p.purchase_unit && parseFloat(p.purchase_unit_factor) > 1) {
    toggle.style.display = 'block';
    toggle.textContent = `Switch to entering in ${p.purchase_unit} (${p.purchase_unit_factor} ${p.unit} each)`;
    toggle.onclick = () => toggleUnitMode(tr, p.unit, p.purchase_unit, parseFloat(p.purchase_unit_factor));
  } else if (toggle) {
    toggle.style.display = 'none';
  }

  calcRow(tr.querySelector('.qty'));
}

// init rows
if (existingItems.length){
  existingItems.forEach(it=>addRow(it));
} else {
  addRow();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
