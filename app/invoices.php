<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();

$docType = $_GET['type'] ?? 'invoice';
if (!in_array($docType, ['invoice','proforma','quotation','purchase','online_order','delivery_challan'], true)) $docType = 'invoice';
enforce_permission($db, $tid, Auth::user()['role'], $docType === 'purchase' ? 'purchases' : 'sales', $docType === 'purchase' ? 'Purchase Bills' : 'Sales Documents');
$typeLabels = ['invoice'=>'Invoices','proforma'=>'Proforma Invoices','quotation'=>'Quotations','purchase'=>'Purchase Bills','online_order'=>'Online Orders','delivery_challan'=>'Delivery Challans'];
$pageTitle = $typeLabels[$docType];
$activeNav = $docType;

// ---- Quick stock adjustment (only relevant from the Purchase Bills view) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_stock') {
    require_csrf();
    $productId = (int)($_POST['product_id'] ?? 0);
    $newQty = (float)($_POST['new_qty'] ?? 0);

    $stmt = $db->prepare("SELECT current_stock FROM products WHERE id=? AND tenant_id=? AND type='product'");
    $stmt->execute([$productId, $tid]);
    $cur = $stmt->fetch();
    if ($cur) {
        $diff = $newQty - (float)$cur['current_stock'];
        adjust_product_stock($db, $tid, $productId, null, null, $diff, 'adjustment');
        flash('success', 'Stock adjusted.');
    } else {
        flash('error', 'Product not found.');
    }
    redirect(base_url('app/invoices.php?type=' . $docType));
}

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT i.*, p.name AS party_name FROM invoices i LEFT JOIN parties p ON p.id = i.party_id
        WHERE i.tenant_id=? AND i.doc_type=?";
$params = [$tid, $docType];
if ($search !== '') {
    $sql .= " AND (i.doc_no LIKE ? OR p.name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($statusFilter !== '') {
    $sql .= " AND i.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY i.id DESC LIMIT 200";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalAmt = array_sum(array_column($rows, 'total_amount'));

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><?= e($pageTitle) ?></h1><div class="sub"><?= count($rows) ?> documents · ₹<?= money($totalAmt) ?> total</div></div>
  <div style="display:flex;gap:8px">
    <?php if ($docType === 'purchase'): ?>
    <button type="button" class="btn btn-outline" onclick="document.getElementById('adjustStockModal').style.display='flex'">📦 Adjust Stock</button>
    <?php endif; ?>
    <?php if ($docType === 'online_order'): ?>
    <span class="hint" style="align-self:center">Orders are placed by customers via your storefront - review and convert them to invoices below.</span>
    <?php else: ?>
    <a href="<?= base_url('app/invoice_form.php?type=' . $docType) ?>" class="btn btn-primary">+ New <?= e(rtrim($typeLabels[$docType],'s')) ?></a>
    <?php endif; ?>
  </div>
</div>

<form method="get" class="form-row" style="margin-bottom:16px">
  <input type="hidden" name="type" value="<?= e($docType) ?>">
  <div class="form-group" style="max-width:260px"><input class="form-control" type="text" name="q" placeholder="Search doc no / party..." value="<?= e($search) ?>"></div>
  <?php if ($docType === 'invoice' || $docType === 'purchase'): ?>
  <div class="form-group" style="max-width:180px">
    <select class="form-control" name="status" onchange="this.form.submit()">
      <option value="">All Status</option>
      <option value="unpaid" <?= $statusFilter==='unpaid'?'selected':'' ?>>Unpaid</option>
      <option value="partial" <?= $statusFilter==='partial'?'selected':'' ?>>Partial</option>
      <option value="paid" <?= $statusFilter==='paid'?'selected':'' ?>>Paid</option>
      <option value="draft" <?= $statusFilter==='draft'?'selected':'' ?>>Draft</option>
    </select>
  </div>
  <?php endif; ?>
  <button class="btn btn-outline">Filter</button>
</form>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Doc No</th><th>Party</th><th>Date</th><th class="num">Total</th><?php if($docType==='invoice'||$docType==='purchase'):?><th class="num">Due</th><?php endif; ?><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7"><div class="empty-state"><h3>No <?= e(strtolower($pageTitle)) ?> yet</h3><p>Create your first one to see it here.</p></div></td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= base_url('app/invoice_view.php?id=' . $r['id']) ?>" style="color:#c8862b;font-weight:600"><?= e($r['doc_no']) ?></a></td>
      <td><?= e($r['party_name'] ?? '—') ?></td>
      <td><?= date('d M Y', strtotime($r['doc_date'])) ?></td>
      <td class="num">₹<?= money($r['total_amount']) ?></td>
      <?php if($docType==='invoice'||$docType==='purchase'): ?>
      <td class="num">₹<?= money($r['total_amount'] - $r['paid_amount']) ?></td>
      <?php endif; ?>
      <td><span class="badge badge-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
      <td style="white-space:nowrap">
        <a href="<?= base_url('app/invoice_view.php?id=' . $r['id']) ?>" class="btn btn-outline btn-sm">View</a>
        <?php if ($docType !== 'online_order'): ?>
        <a href="<?= base_url('app/invoice_form.php?type=' . $docType . '&id=' . $r['id']) ?>" class="btn btn-outline btn-sm">Edit</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($docType === 'purchase'): ?>
<div id="adjustStockModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:400px;width:100%">
    <h3 style="margin-bottom:16px">Adjust Stock</h3>
    <form method="post" id="adjustStockForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="adjust_stock">
      <input type="hidden" name="product_id" id="as_product_id" required>
      <div class="form-group" style="position:relative">
        <label>Product</label>
        <input type="text" class="form-control" id="as_search" placeholder="Search product name or SKU..." autocomplete="off" oninput="asSearchProduct(this)">
        <div id="asSuggest" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e2ddd0;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,.12);z-index:20;max-height:220px;overflow-y:auto"></div>
      </div>
      <div class="form-group" id="asCurrentWrap" style="display:none">
        <label>Current Stock</label>
        <input type="text" class="form-control" id="as_current" disabled>
      </div>
      <div class="form-group">
        <label>New Stock Quantity</label>
        <input class="form-control" type="number" step="0.001" name="new_qty" id="as_new_qty" required>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" id="asSubmitBtn" disabled>Update Stock</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('adjustStockModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
const asSearchUrl = <?= json_encode(base_url('app/ajax_product_search.php')) ?>;
let asSearchTimer;

function asSearchProduct(input){
  clearTimeout(asSearchTimer);
  const box = document.getElementById('asSuggest');
  const q = input.value.trim();
  if (q.length < 1){ box.style.display='none'; return; }
  asSearchTimer = setTimeout(async () => {
    const res = await fetch(asSearchUrl + '?q=' + encodeURIComponent(q));
    const data = await res.json();
    const items = (data.items || []).filter(p => p.type === 'product');
    if (!items.length){ box.innerHTML = '<div style="padding:10px 12px;font-size:.82rem;color:#8a93ab">No matching products.</div>'; box.style.display='block'; return; }
    box.innerHTML = items.map((p, i) => `<div class="opt" style="padding:8px 12px;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f0ece0" data-i="${i}">
      <b>${p.name}</b> - stock: ${p.current_stock}
    </div>`).join('');
    box.dataset.items = JSON.stringify(items);
    box.style.display = 'block';
  }, 200);
}

document.getElementById('asSuggest').addEventListener('mousedown', (e) => {
  const opt = e.target.closest('.opt');
  if (!opt) return;
  e.preventDefault();
  const items = JSON.parse(document.getElementById('asSuggest').dataset.items || '[]');
  const p = items[parseInt(opt.dataset.i, 10)];
  if (!p) return;
  document.getElementById('as_product_id').value = p.id;
  document.getElementById('as_search').value = p.name;
  document.getElementById('as_current').value = p.current_stock + ' ' + p.unit;
  document.getElementById('asCurrentWrap').style.display = 'block';
  document.getElementById('as_new_qty').value = p.current_stock;
  document.getElementById('asSubmitBtn').disabled = false;
  document.getElementById('asSuggest').style.display = 'none';
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
