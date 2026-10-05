<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'products', 'Locations');
$tid = Auth::tenantId();
$pageTitle = 'Locations';
$activeNav = 'locations';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            flash('error', 'Location name is required.');
        } elseif ($id) {
            $db->prepare("UPDATE locations SET name=?, address=? WHERE id=? AND tenant_id=?")->execute([$name, $address, $id, $tid]);
            flash('success', 'Location updated.');
        } else {
            $db->prepare("INSERT INTO locations (tenant_id, name, address, is_default, is_active) VALUES (?,?,?,0,1)")->execute([$tid, $name, $address]);
            flash('success', 'Location added.');
        }
    } elseif ($action === 'set_default') {
        $id = (int)($_POST['id'] ?? 0);
        $db->beginTransaction();
        $db->prepare("UPDATE locations SET is_default=0 WHERE tenant_id=?")->execute([$tid]);
        $db->prepare("UPDATE locations SET is_default=1 WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
        $db->commit();
        flash('success', 'Default location updated.');
    } elseif ($action === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        $isDefault = $db->prepare("SELECT is_default FROM locations WHERE id=? AND tenant_id=?");
        $isDefault->execute([$id, $tid]);
        $row = $isDefault->fetch();
        if ($row && $row['is_default']) {
            flash('error', 'Cannot deactivate your default location. Set another location as default first.');
        } else {
            $db->prepare("UPDATE locations SET is_active=0 WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
            flash('success', 'Location deactivated.');
        }
    }
    redirect(base_url('app/locations.php'));
}

require __DIR__ . '/partials/header.php';

$stmt = $db->prepare("SELECT l.*, (SELECT COUNT(*) FROM product_stock ps WHERE ps.location_id=l.id AND ps.qty > 0) stocked_items
                       FROM locations l WHERE l.tenant_id=? AND l.is_active=1 ORDER BY l.is_default DESC, l.name");
$stmt->execute([$tid]);
$locations = $stmt->fetchAll();
?>
<div class="page-head">
  <div><h1>Locations</h1><div class="sub">Shops, warehouses, or godowns you stock inventory at</div></div>
  <button class="btn btn-primary" onclick="openForm()">+ Add Location</button>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Name</th><th>Address</th><th class="num">Items Stocked</th><th></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($locations as $l): ?>
    <tr>
      <td><?= e($l['name']) ?> <?php if ($l['is_default']): ?><span class="badge badge-paid" style="font-size:.62rem">default</span><?php endif; ?></td>
      <td><?= e($l['address']) ?></td>
      <td class="num"><?= (int)$l['stocked_items'] ?></td>
      <td>
        <?php if (!$l['is_default']): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="set_default"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="btn btn-outline btn-sm">Set as Default</button>
        </form>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <button class="btn btn-outline btn-sm" onclick='openForm(<?= json_encode($l) ?>)'>Edit</button>
        <?php if (!$l['is_default']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this location?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="btn btn-danger btn-sm">Deactivate</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if (count($locations) > 1): ?>
<a href="<?= base_url('app/stock_transfer.php') ?>" class="btn btn-outline" style="margin-top:16px">🔁 Transfer Stock Between Locations</a>
<?php endif; ?>

<div id="formModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:380px;width:100%">
    <h3 id="fTitle" style="margin-bottom:16px">Add Location</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
      <div class="form-group"><label>Name</label><input class="form-control" name="name" id="f_name" required></div>
      <div class="form-group"><label>Address</label><textarea class="form-control" name="address" id="f_address" rows="2"></textarea></div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('formModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
function openForm(l){
  document.getElementById('formModal').style.display='flex';
  document.getElementById('fTitle').textContent = l ? 'Edit Location' : 'Add Location';
  document.getElementById('f_id').value = l ? l.id : '';
  document.getElementById('f_name').value = l ? l.name : '';
  document.getElementById('f_address').value = l ? (l.address || '') : '';
}
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
