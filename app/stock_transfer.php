<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'products', 'Stock Transfer');
$tid = Auth::tenantId();
$pageTitle = 'Stock Transfer';
$activeNav = 'locations';

$locStmt = $db->prepare("SELECT * FROM locations WHERE tenant_id=? AND is_active=1 ORDER BY is_default DESC, name");
$locStmt->execute([$tid]);
$locations = $locStmt->fetchAll();

if (count($locations) < 2) {
    flash('error', 'You need at least two active locations to transfer stock. Add one on the Locations page first.');
    redirect(base_url('app/locations.php'));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $fromLoc = (int)($_POST['from_location_id'] ?? 0);
    $toLoc = (int)($_POST['to_location_id'] ?? 0);
    $transferDate = $_POST['transfer_date'] ?? today();
    $notes = trim($_POST['notes'] ?? '');
    $rawItems = $_POST['items'] ?? [];

    if (!$fromLoc || !$toLoc) {
        $errors[] = 'Please select both a source and destination location.';
    } elseif ($fromLoc === $toLoc) {
        $errors[] = 'Source and destination locations must be different.';
    }

    $items = [];
    foreach ($rawItems as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $qty = (float)($row['qty'] ?? 0);
        if (!$productId || $qty <= 0) continue;
        $items[] = ['product_id' => $productId, 'qty' => $qty, 'batch_id' => (int)($row['batch_id'] ?? 0) ?: null];
    }
    if (!$items) {
        $errors[] = 'Add at least one item to transfer.';
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            $transferNo = next_doc_number($db, $tid, 'stock_transfer');
            $stmt = $db->prepare("INSERT INTO stock_transfers (tenant_id, transfer_no, from_location_id, to_location_id, transfer_date, notes, created_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$tid, $transferNo, $fromLoc, $toLoc, $transferDate, $notes, Auth::user()['id']]);
            $transferId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO stock_transfer_items (transfer_id, product_id, batch_id, qty) VALUES (?,?,?,?)");
            foreach ($items as $it) {
                $itemStmt->execute([$transferId, $it['product_id'], $it['batch_id'], $it['qty']]);
                adjust_product_stock($db, $tid, $it['product_id'], $fromLoc, $it['batch_id'], -$it['qty'], 'transfer', $transferId);
                adjust_product_stock($db, $tid, $it['product_id'], $toLoc, $it['batch_id'], $it['qty'], 'transfer', $transferId);
            }

            $db->commit();
            flash('success', "Transfer $transferNo completed.");
            redirect(base_url('app/locations.php'));
        } catch (Throwable $ex) {
            $db->rollBack();
            $errors[] = 'Could not complete this transfer. Please try again.';
        }
    }
}

require __DIR__ . '/partials/header.php';
$searchUrl = base_url('app/ajax_product_search.php');
?>
<div class="page-head">
  <div><h1>Transfer Stock</h1><div class="sub">Move inventory between your locations</div></div>
  <a href="<?= base_url('app/locations.php') ?>" class="btn btn-outline">← Back to Locations</a>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" class="invoice-builder">
  <?= csrf_field() ?>
  <div class="card card-pad" style="margin-bottom:16px">
    <div class="form-row">
      <div class="form-group">
        <label>From Location</label>
        <select class="form-control" name="from_location_id" required>
          <option value="">-- Select --</option>
          <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>To Location</label>
        <select class="form-control" name="to_location_id" required>
          <option value="">-- Select --</option>
          <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Date</label>
        <input class="form-control" type="date" name="transfer_date" value="<?= today() ?>">
      </div>
    </div>
    <div class="form-group"><label>Notes</label><input class="form-control" name="notes" placeholder="Optional"></div>
  </div>

  <div class="card card-pad" style="margin-bottom:16px">
    <div class="table-wrap" style="border:0">
      <table class="data items-table">
        <thead><tr><th>Product</th><th class="num">Qty</th><th></th></tr></thead>
        <tbody id="itemsBody"></tbody>
      </table>
    </div>
    <button type="button" class="btn btn-outline btn-sm" onclick="addRow()" style="margin-top:10px">+ Add Row</button>
  </div>

  <button type="submit" class="btn btn-primary">Complete Transfer</button>
</form>

<script>
const searchUrl = <?= json_encode($searchUrl) ?>;
let rowIndex = 0;

function rowTemplate(idx){
  return `<tr data-idx="${idx}">
    <td style="position:relative">
      <input type="hidden" name="items[${idx}][product_id]" class="pid">
      <input type="text" class="form-control desc" placeholder="Search product..." autocomplete="off" oninput="searchProduct(this)" onblur="scheduleHide()">
    </td>
    <td><input type="number" step="0.001" class="form-control" name="items[${idx}][qty]" value="1"></td>
    <td><button type="button" class="remove-row" onclick="this.closest('tr').remove()">✕</button></td>
  </tr>`;
}
function addRow(){
  document.getElementById('itemsBody').insertAdjacentHTML('beforeend', rowTemplate(rowIndex));
  rowIndex++;
}

const suggestBox = document.createElement('div');
suggestBox.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid #e2ddd0;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:9999;max-height:240px;overflow-y:auto';
document.body.appendChild(suggestBox);
let activeRow = null, lastResults = [], searchTimer, hideTimer;

function searchProduct(input){
  clearTimeout(searchTimer);
  clearTimeout(hideTimer);
  activeRow = input.closest('tr');
  const q = input.value.trim();
  if (q.length < 2){ suggestBox.style.display = 'none'; return; }
  searchTimer = setTimeout(async () => {
    const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q));
    const data = await res.json();
    lastResults = (data.items || []).filter(p => p.type === 'product');
    if (!lastResults.length) { suggestBox.style.display = 'none'; return; }
    const rect = input.getBoundingClientRect();
    suggestBox.style.left = rect.left + 'px';
    suggestBox.style.top = (rect.bottom + 2) + 'px';
    suggestBox.style.width = Math.max(rect.width, 260) + 'px';
    suggestBox.innerHTML = lastResults.map((p, i) => `<div class="opt" style="padding:8px 12px;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f0ece0" data-i="${i}"><b>${p.name}</b> - stock: ${p.current_stock}</div>`).join('');
    suggestBox.style.display = 'block';
  }, 200);
}
suggestBox.addEventListener('mousedown', (e) => {
  const opt = e.target.closest('.opt');
  if (!opt || !activeRow) return;
  e.preventDefault();
  const p = lastResults[parseInt(opt.dataset.i, 10)];
  if (p) {
    activeRow.querySelector('.pid').value = p.id;
    activeRow.querySelector('.desc').value = p.name;
  }
  suggestBox.style.display = 'none';
});
function scheduleHide(){ hideTimer = setTimeout(() => { suggestBox.style.display = 'none'; }, 150); }

addRow();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
