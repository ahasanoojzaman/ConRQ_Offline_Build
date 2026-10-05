<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'products', 'Batches & Lots');
$tid = Auth::tenantId();
$pageTitle = 'Batches & Lots';
$activeNav = 'batches';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive') {
    require_csrf();
    $productId = (int)($_POST['product_id'] ?? 0);
    $batchNo = trim($_POST['batch_no'] ?? '');
    $mfgDate = $_POST['mfg_date'] ?? '';
    $expiryDate = $_POST['expiry_date'] ?? '';
    $qty = (float)($_POST['qty'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0) ?: null;
    $purchasePrice = (float)($_POST['purchase_price'] ?? 0);

    if (!$productId || $batchNo === '' || $qty <= 0) {
        flash('error', 'Product, batch number, and a positive quantity are required.');
    } else {
        $db->beginTransaction();
        try {
            $batchId = get_or_create_batch($db, $tid, $productId, $batchNo, $mfgDate ?: null, $expiryDate ?: null, $purchasePrice);
            adjust_product_stock($db, $tid, $productId, $locationId, $batchId, $qty, 'adjustment');
            $db->commit();
            flash('success', "Batch $batchNo stock received.");
        } catch (Throwable $ex) {
            $db->rollBack();
            flash('error', 'Could not save this batch.');
        }
    }
    redirect(base_url('app/batches.php'));
}

require __DIR__ . '/partials/header.php';

$batchStmt = $db->prepare("SELECT b.*, p.name AS product_name, p.unit,
                                   COALESCE(SUM(ps.qty), 0) available_qty
                            FROM product_batches b
                            JOIN products p ON p.id = b.product_id
                            LEFT JOIN product_stock ps ON ps.batch_id = b.id
                            WHERE b.tenant_id = ?
                            GROUP BY b.id
                            ORDER BY (b.expiry_date IS NULL), b.expiry_date ASC");
$batchStmt->execute([$tid]);
$batches = $batchStmt->fetchAll();

$productsStmt = $db->prepare("SELECT id, name, unit FROM products WHERE tenant_id=? AND type='product' AND is_active=1 AND track_batches=1 ORDER BY name");
$productsStmt->execute([$tid]);
$batchProducts = $productsStmt->fetchAll();

$locStmt = $db->prepare("SELECT id, name FROM locations WHERE tenant_id=? AND is_active=1 ORDER BY is_default DESC, name");
$locStmt->execute([$tid]);
$locations = $locStmt->fetchAll();

function expiry_status(?string $expiryDate): array
{
    if (!$expiryDate) return ['label' => '—', 'class' => 'badge-draft'];
    $days = (int)((strtotime($expiryDate) - strtotime(today())) / 86400);
    if ($days < 0) return ['label' => 'Expired', 'class' => 'badge-unpaid'];
    if ($days <= 30) return ['label' => "$days days left", 'class' => 'badge-partial'];
    return ['label' => date('d M Y', strtotime($expiryDate)), 'class' => 'badge-paid'];
}
?>
<div class="page-head">
  <div><h1>Batches &amp; Lots</h1><div class="sub">Track expiry-sensitive stock by batch number</div></div>
  <div style="display:flex;gap:8px">
    <a href="<?= base_url('app/expiring_stock.php') ?>" class="btn btn-outline">⏰ Expiring Soon</a>
    <button class="btn btn-primary" onclick="document.getElementById('receiveModal').style.display='flex'">+ Receive Batch</button>
  </div>
</div>

<?php if (!$batchProducts): ?>
<div class="alert alert-info" style="background:#eef2fb;color:#223056;padding:12px 16px;border-radius:6px;margin-bottom:16px">
  No products are marked for batch tracking yet. Edit a product on the <a href="<?= base_url('app/products.php') ?>">Products &amp; Services</a> page and turn on "Track Batches / Expiry".
</div>
<?php endif; ?>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Product</th><th>Batch No</th><th>Mfg Date</th><th>Expiry</th><th class="num">Available Qty</th></tr></thead>
  <tbody>
  <?php if (!$batches): ?><tr><td colspan="5"><div class="empty-state"><h3>No batches yet</h3></div></td></tr><?php endif; ?>
  <?php foreach ($batches as $b): $status = expiry_status($b['expiry_date']); ?>
    <tr>
      <td><?= e($b['product_name']) ?></td>
      <td><?= e($b['batch_no']) ?></td>
      <td><?= $b['mfg_date'] ? date('d M Y', strtotime($b['mfg_date'])) : '—' ?></td>
      <td><span class="badge <?= $status['class'] ?>"><?= e($status['label']) ?></span></td>
      <td class="num"><?= rtrim(rtrim(number_format($b['available_qty'],3),'0'),'.') ?> <?= e($b['unit']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="receiveModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:420px;width:100%">
    <h3 style="margin-bottom:16px">Receive Batch Stock</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="receive">
      <div class="form-group"><label>Product</label>
        <select class="form-control" name="product_id" required>
          <option value="">-- Select --</option>
          <?php foreach ($batchProducts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Batch / Lot Number</label><input class="form-control" name="batch_no" required></div>
      <div class="form-row">
        <div class="form-group"><label>Mfg Date</label><input class="form-control" type="date" name="mfg_date"></div>
        <div class="form-group"><label>Expiry Date</label><input class="form-control" type="date" name="expiry_date"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Quantity</label><input class="form-control" type="number" step="0.001" name="qty" required></div>
        <div class="form-group"><label>Cost Price (per unit)</label><input class="form-control" type="number" step="0.01" name="purchase_price"></div>
      </div>
      <?php if (count($locations) > 1): ?>
      <div class="form-group"><label>Location</label>
        <select class="form-control" name="location_id">
          <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('receiveModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
