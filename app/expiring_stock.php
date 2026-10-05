<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'products', 'Expiring Soon');
$tid = Auth::tenantId();
$pageTitle = 'Expiring Soon';
$activeNav = 'batches';

$windowDays = (int)($_GET['days'] ?? 60);

$stmt = $db->prepare("SELECT b.*, p.name AS product_name, p.unit, COALESCE(SUM(ps.qty), 0) available_qty
                       FROM product_batches b
                       JOIN products p ON p.id = b.product_id
                       LEFT JOIN product_stock ps ON ps.batch_id = b.id
                       WHERE b.tenant_id = ? AND b.expiry_date IS NOT NULL
                       GROUP BY b.id
                       HAVING available_qty > 0 AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                       ORDER BY b.expiry_date ASC");
$stmt->execute([$tid, $windowDays]);
$batches = $stmt->fetchAll();

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Expiring Soon</h1><div class="sub"><?= count($batches) ?> batch(es) expiring within <?= $windowDays ?> days</div></div>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <select class="form-control" name="days" onchange="this.form.submit()">
      <?php foreach ([30, 60, 90, 180] as $d): ?>
        <option value="<?= $d ?>" <?= $windowDays === $d ? 'selected' : '' ?>>Next <?= $d ?> days</option>
      <?php endforeach; ?>
    </select>
    <a href="<?= base_url('app/batches.php') ?>" class="btn btn-outline">All Batches</a>
  </form>
</div>

<?php if (!$batches): ?>
  <div class="empty-state"><h3>Nothing expiring soon</h3><p>No batches with stock are due to expire within the selected window.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
  <thead><tr><th>Product</th><th>Batch No</th><th>Expiry Date</th><th class="num">Days Left</th><th class="num">Available Qty</th></tr></thead>
  <tbody>
  <?php foreach ($batches as $b): $daysLeft = (int)((strtotime($b['expiry_date']) - strtotime(today())) / 86400); ?>
    <tr>
      <td><?= e($b['product_name']) ?></td>
      <td><?= e($b['batch_no']) ?></td>
      <td><?= date('d M Y', strtotime($b['expiry_date'])) ?></td>
      <td class="num" style="color:<?= $daysLeft < 0 ? '#b5443a' : ($daysLeft <= 14 ? '#c8862b' : 'inherit') ?>;font-weight:700"><?= $daysLeft < 0 ? 'Expired ' . abs($daysLeft) . 'd ago' : "$daysLeft d" ?></td>
      <td class="num"><?= rtrim(rtrim(number_format($b['available_qty'],3),'0'),'.') ?> <?= e($b['unit']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
