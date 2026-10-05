<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT i.*, p.name AS party_name, p.phone AS party_phone, p.email AS party_email, p.gstin AS party_gstin, p.billing_address AS party_address
                       FROM invoices i LEFT JOIN parties p ON p.id=i.party_id WHERE i.id=? AND i.tenant_id=?");
$stmt->execute([$id, $tid]);
$invoice = $stmt->fetch();
if (!$invoice) { flash('error', 'Document not found.'); redirect(base_url('app/invoices.php')); }
enforce_permission($db, $tid, Auth::user()['role'], $invoice['doc_type'] === 'purchase' ? 'purchases' : 'sales', $invoice['doc_type'] === 'purchase' ? 'Purchase Bills' : 'Sales Documents');

$itemsStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$typeLabels = ['invoice'=>'Invoice','proforma'=>'Proforma Invoice','quotation'=>'Quotation','purchase'=>'Purchase Bill','online_order'=>'Online Order','delivery_challan'=>'Delivery Challan'];
$pageTitle = $invoice['doc_no'];
$activeNav = $invoice['doc_type'];

// ---- Record payment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    require_csrf();
    $amount = (float)($_POST['amount'] ?? 0);
    $mode = $_POST['mode'] ?? 'cash';
    $payDate = $_POST['payment_date'] ?? today();
    $direction = $invoice['doc_type'] === 'purchase' ? 'out' : 'in';

    if ($amount > 0) {
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO payments (tenant_id, party_id, invoice_id, direction, mode, amount, payment_date, created_by) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$tid, $invoice['party_id'], $id, $direction, $mode, $amount, $payDate, Auth::user()['id']]);

            $newPaid = (float)$invoice['paid_amount'] + $amount;
            $status = $newPaid >= (float)$invoice['total_amount'] ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
            $db->prepare("UPDATE invoices SET paid_amount=?, status=? WHERE id=? AND tenant_id=?")
               ->execute([$newPaid, $status, $id, $tid]);
            $db->commit();
            flash('success', 'Payment recorded.');
        } catch (Throwable $ex) {
            $db->rollBack();
            flash('error', 'Could not record payment.');
        }
    }
    redirect(base_url('app/invoice_view.php?id=' . $id));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_doc') {
    require_csrf();

    $db->beginTransaction();
    try {
        // Only reverse stock if this document had actually applied a stock
        // effect (i.e. it was finalized, not left as a draft, and is not
        // already cancelled). This is what was missing before: cancelling
        // an invoice/purchase left its stock deduction/addition in place
        // forever, silently leaving inventory numbers wrong.
        if (!in_array($invoice['status'], ['draft', 'cancelled'], true)) {
            apply_stock($db, $tid, $invoice['doc_type'], $items, -1, $id);
        }
        $db->prepare("UPDATE invoices SET status='cancelled' WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
        $db->commit();
        flash('success', 'Document cancelled and any stock it affected has been restored.');
    } catch (Throwable $ex) {
        $db->rollBack();
        flash('error', 'Could not cancel this document. Please try again.');
    }
    redirect(base_url('app/invoice_view.php?id=' . $id));
}

require __DIR__ . '/partials/header.php';

$balanceDue = (float)$invoice['total_amount'] - (float)$invoice['paid_amount'];

// Public, no-login link for anything sent to the customer (WhatsApp/email/copy link).
// Internal staff printing still uses the login-gated /app/invoice_print_*.php pages.
$shareToken = get_or_create_share_token($db, $id, $invoice['share_token']);
$shareUrl = base_url('share.php?token=' . $shareToken);

$whatsappMsg = rawurlencode("Hi " . ($invoice['party_name'] ?? '') . ", here is your " . $typeLabels[$invoice['doc_type']] . " " . $invoice['doc_no'] . " for ₹" . money($invoice['total_amount']) . " from " . Auth::user()['company'] . ". View: " . $shareUrl);
$waNumber = preg_replace('/\D/', '', $invoice['party_phone'] ?? '');
?>
<div class="page-head">
  <div><h1><?= e($typeLabels[$invoice['doc_type']]) ?> <?= e($invoice['doc_no']) ?></h1>
    <div class="sub"><?= e($invoice['party_name'] ?? 'No party') ?> · <?= date('d M Y', strtotime($invoice['doc_date'])) ?> · <span class="badge badge-<?= e($invoice['status']) ?>"><?= e($invoice['status']) ?></span></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?= base_url('app/invoice_print_a4.php?id=' . $id) ?>" target="_blank" class="btn btn-outline">🖨️ Print A4</a>
    <a href="<?= base_url('app/invoice_print_thermal.php?id=' . $id) ?>" target="_blank" class="btn btn-outline">🧾 Thermal</a>
    <?php if ($waNumber): ?><a href="https://wa.me/91<?= e($waNumber) ?>?text=<?= $whatsappMsg ?>" target="_blank" class="btn btn-outline">💬 WhatsApp</a><?php endif; ?>
    <?php if ($invoice['party_email']): ?><a href="mailto:<?= e($invoice['party_email']) ?>?subject=<?= rawurlencode($typeLabels[$invoice['doc_type']].' '.$invoice['doc_no']) ?>&body=<?= $whatsappMsg ?>" class="btn btn-outline">✉️ Email</a><?php endif; ?>
    <button type="button" class="btn btn-outline" onclick="navigator.clipboard.writeText('<?= e($shareUrl) ?>').then(()=>{this.textContent='✅ Copied!';setTimeout(()=>this.textContent='🔗 Copy Link',1500)})">🔗 Copy Link</button>
    <?php if ($invoice['doc_type'] !== 'online_order'): ?>
    <a href="<?= base_url('app/invoice_form.php?type=' . $invoice['doc_type'] . '&id=' . $id) ?>" class="btn btn-outline">✏️ Edit</a>
    <?php endif; ?>
    <?php if (in_array($invoice['doc_type'], ['quotation','proforma','online_order'])): ?>
      <a href="<?= base_url('app/invoice_form.php?type=invoice&from=' . $id) ?>" class="btn btn-primary">Convert to Invoice</a>
    <?php endif; ?>
  </div>
</div>
<p class="hint" style="margin-top:-10px;margin-bottom:16px">Customer-facing link (no login needed): <a href="<?= e($shareUrl) ?>" target="_blank"><?= e($shareUrl) ?></a></p>

<div class="grid grid-2">
  <div class="card card-pad">
    <h3 style="margin-bottom:14px">Items</h3>

    <div class="table-wrap" style="border:0">
      <table class="data">
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Tax</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['description']) ?><?php if($it['hsn_sac']): ?><div style="font-size:.72rem;color:#8a93ab">HSN: <?= e($it['hsn_sac']) ?></div><?php endif; ?></td>
            <td class="num"><?= rtrim(rtrim(number_format($it['qty'],3),'0'),'.') ?> <?= e($it['unit']) ?></td>
            <td class="num">₹<?= money($it['rate']) ?></td>
            <td class="num"><?= money($it['tax_rate']) ?>%</td>
            <td class="num">₹<?= money($it['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="totals-box" style="margin-top:16px">
      <div class="row"><span>Subtotal</span><span>₹<?= money($invoice['subtotal']) ?></span></div>
      <div class="row"><span>Discount</span><span>₹<?= money($invoice['discount_amount']) ?></span></div>
      <div class="row"><span>Tax</span><span>₹<?= money($invoice['tax_amount']) ?></span></div>
      <div class="row"><span>Round Off</span><span>₹<?= money($invoice['round_off']) ?></span></div>
      <div class="row grand"><span>Total</span><span>₹<?= money($invoice['total_amount']) ?></span></div>
    </div>
  </div>

  <div>
    <?php if (in_array($invoice['doc_type'], ['invoice','purchase'])): ?>
    <div class="card card-pad" style="margin-bottom:16px">
      <h3 style="margin-bottom:14px">Payment</h3>
      <div class="grid grid-2" style="margin-bottom:14px">
        <div class="stat" style="padding:0"><div class="label">Paid</div><div class="value money" style="font-size:1.3rem">₹<?= money($invoice['paid_amount']) ?></div></div>
        <div class="stat" style="padding:0"><div class="label">Balance Due</div><div class="value" style="font-size:1.3rem;color:<?= $balanceDue>0?'#b5443a':'#2f7d5e' ?>">₹<?= money($balanceDue) ?></div></div>
      </div>
      <?php if ($balanceDue > 0 && $invoice['status'] !== 'cancelled'): ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="record_payment">
        <div class="form-row">
          <div class="form-group"><label>Amount</label><input class="form-control" type="number" step="0.01" name="amount" max="<?= $balanceDue ?>" value="<?= $balanceDue ?>" required></div>
          <div class="form-group"><label>Mode</label>
            <select class="form-control" name="mode">
              <option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="upi">UPI</option><option value="cheque">Cheque</option><option value="card">Card</option>
            </select>
          </div>
        </div>
        <div class="form-group"><label>Date</label><input class="form-control" type="date" name="payment_date" value="<?= today() ?>"></div>
        <button class="btn btn-primary btn-block">Record Payment</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card card-pad">
      <h3 style="margin-bottom:14px"><?= $invoice['doc_type']==='purchase' ? 'Supplier' : 'Customer' ?> Details</h3>
      <p style="margin:4px 0"><b><?= e($invoice['party_name'] ?? '—') ?></b></p>
      <p style="margin:4px 0;color:#4a5470;font-size:.9rem"><?= e($invoice['party_address'] ?? '') ?></p>
      <p style="margin:4px 0;color:#4a5470;font-size:.9rem"><?= e($invoice['party_phone'] ?? '') ?><?= $invoice['party_email'] ? ' · '.e($invoice['party_email']) : '' ?></p>
      <?php if ($invoice['party_gstin']): ?><p style="margin:4px 0;color:#4a5470;font-size:.9rem">GSTIN: <?= e($invoice['party_gstin']) ?></p><?php endif; ?>
      <?php if ($invoice['vehicle_no'] || $invoice['transport_mode'] || $invoice['eway_bill_no']): ?>
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e2ddd0;font-size:.85rem;color:#4a5470">
        <?php if ($invoice['vehicle_no']): ?><div>🚚 Vehicle: <?= e($invoice['vehicle_no']) ?></div><?php endif; ?>
        <?php if ($invoice['transport_mode']): ?><div>Mode: <?= e(ucfirst($invoice['transport_mode'])) ?></div><?php endif; ?>
        <?php if ($invoice['eway_bill_no']): ?><div>E-Way Bill No: <?= e($invoice['eway_bill_no']) ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($invoice['notes']): ?><p style="margin-top:12px;font-size:.85rem"><b>Notes:</b> <?= nl2br(e($invoice['notes'])) ?></p><?php endif; ?>
      <?php if ($invoice['status'] !== 'cancelled'): ?>
      <form method="post" style="margin-top:14px" onsubmit="return confirm('Cancel this document? This cannot be undone.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel_doc">
        <button class="btn btn-danger btn-sm">Cancel Document</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
