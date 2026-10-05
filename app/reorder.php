<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'products', 'Reorder Suggestions');
$tid = Auth::tenantId();
$pageTitle = 'Reorder Suggestions';
$activeNav = 'reorder';

// ---- Low stock items ----
$lowStockStmt = $db->prepare("SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone, s.email AS supplier_email
                               FROM products p LEFT JOIN parties s ON s.id = p.preferred_supplier_id
                               WHERE p.tenant_id=? AND p.type='product' AND p.is_active=1 AND p.current_stock <= p.low_stock_alert
                               ORDER BY (p.current_stock / GREATEST(p.low_stock_alert, 0.001)) ASC");
$lowStockStmt->execute([$tid]);
$lowStockItems = $lowStockStmt->fetchAll();

// ---- Sales velocity (units sold per day, last 30 days) per product, for smarter suggestions ----
$velocityStmt = $db->prepare("SELECT product_id, SUM(ABS(qty_change)) total_out FROM stock_movements
                               WHERE tenant_id=? AND movement_type='sale' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                               GROUP BY product_id");
$velocityStmt->execute([$tid]);
$velocity = [];
foreach ($velocityStmt->fetchAll() as $v) { $velocity[$v['product_id']] = (float)$v['total_out'] / 30; }

// All suppliers, for the quick-assign dropdown
$suppliersStmt = $db->prepare("SELECT id, name FROM parties WHERE tenant_id=? AND type IN ('supplier','both') AND is_active=1 ORDER BY name");
$suppliersStmt->execute([$tid]);
$suppliers = $suppliersStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_supplier') {
    require_csrf();
    $productId = (int)($_POST['product_id'] ?? 0);
    $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $db->prepare("UPDATE products SET preferred_supplier_id=? WHERE id=? AND tenant_id=?")->execute([$supplierId, $productId, $tid]);
    flash('success', 'Preferred supplier updated.');
    redirect(base_url('app/reorder.php'));
}

require __DIR__ . '/partials/header.php';

$leadTimeDays = 14; // assume ~2 weeks to restock; suggestion covers that gap plus refills to a safe buffer
?>
<div class="page-head">
  <div><h1>Reorder Suggestions</h1><div class="sub"><?= count($lowStockItems) ?> item(s) at or below their reorder level</div></div>
  <a href="<?= base_url('app/products.php') ?>" class="btn btn-outline">Manage Inventory</a>
</div>

<?php if (!$lowStockItems): ?>
  <div class="empty-state"><h3>All stocked up</h3><p>No products are currently at or below their low-stock alert level.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
  <thead><tr><th>Product</th><th class="num">Current Stock</th><th class="num">Avg Daily Sales</th><th class="num">Suggested Reorder Qty</th><th>Preferred Supplier</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lowStockItems as $p): ?>
    <?php
      $dailyVelocity = $velocity[$p['id']] ?? 0;
      $velocityBasedQty = ceil($dailyVelocity * $leadTimeDays);
      $bufferQty = ceil((float)$p['low_stock_alert'] * 2 - (float)$p['current_stock']);
      $suggestedQty = max(1, $velocityBasedQty, $bufferQty);
    ?>
    <tr>
      <td><?= e($p['name']) ?><?php if ($p['sku']): ?><div class="hint">SKU: <?= e($p['sku']) ?></div><?php endif; ?></td>
      <td class="num" style="color:#b5443a"><?= rtrim(rtrim(number_format($p['current_stock'],2),'0'),'.') ?> <?= e($p['unit']) ?></td>
      <td class="num"><?= $dailyVelocity > 0 ? number_format($dailyVelocity, 1) : '—' ?></td>
      <td class="num" style="font-weight:700"><?= $suggestedQty ?> <?= e($p['unit']) ?></td>
      <td>
        <?php if ($p['supplier_name']): ?>
          <div><?= e($p['supplier_name']) ?></div>
          <div style="display:flex;gap:6px;margin-top:2px">
            <?php if ($p['supplier_phone']): ?><a href="https://wa.me/91<?= e(preg_replace('/\D/','',$p['supplier_phone'])) ?>?text=<?= rawurlencode("Hi, I'd like to reorder $suggestedQty {$p['unit']} of {$p['name']}.") ?>" target="_blank" class="btn btn-outline btn-sm">💬 WhatsApp</a><?php endif; ?>
          </div>
        <?php else: ?>
          <form method="post" style="display:flex;gap:4px">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_supplier"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <select name="supplier_id" class="form-control" style="padding:5px 8px;font-size:.78rem" onchange="this.form.submit()">
              <option value="">Set supplier...</option>
              <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
      </td>
      <td>
        <a href="<?= base_url('app/invoice_form.php?type=purchase' . ($p['preferred_supplier_id'] ? '&party_id=' . (int)$p['preferred_supplier_id'] : '')) ?>" class="btn btn-primary btn-sm">Create Purchase Bill</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="hint" style="margin-top:16px">Suggested quantity covers roughly <?= $leadTimeDays ?> days of recent sales, or brings stock back to double your alert level - whichever is higher. Adjust as needed when creating the purchase bill.</p>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
