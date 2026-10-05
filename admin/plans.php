<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
$pageTitle = 'Plans';
$activeNav = 'plans';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price_monthly'] ?? 0);
        $maxUsers = (int)($_POST['max_users'] ?? 1);
        $maxInv = (int)($_POST['max_invoices_month'] ?? 100);
        $featuresRaw = trim($_POST['features'] ?? '');
        $features = json_encode(array_filter(array_map('trim', explode("\n", $featuresRaw))));
        $featStorefront = isset($_POST['feature_storefront']) ? 1 : 0;
        $featAccounting = isset($_POST['feature_master_accounting']) ? 1 : 0;

        if ($name === '') {
            flash('error', 'Plan name is required.');
        } elseif ($id) {
            $db->prepare("UPDATE plans SET name=?, price_monthly=?, max_users=?, max_invoices_month=?, features=?, feature_storefront=?, feature_master_accounting=? WHERE id=?")
               ->execute([$name, $price, $maxUsers, $maxInv, $features, $featStorefront, $featAccounting, $id]);
            flash('success', 'Plan updated.');
        } else {
            $sort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 s FROM plans")->fetch()['s'];
            $db->prepare("INSERT INTO plans (name, price_monthly, max_users, max_invoices_month, features, feature_storefront, feature_master_accounting, is_active, sort_order) VALUES (?,?,?,?,?,?,?,1,?)")
               ->execute([$name, $price, $maxUsers, $maxInv, $features, $featStorefront, $featAccounting, $sort]);
            flash('success', 'Plan created.');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT is_active FROM plans WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $db->prepare("UPDATE plans SET is_active=? WHERE id=?")->execute([$row['is_active'] ? 0 : 1, $id]);
            flash('success', 'Plan status updated.');
        }
    }
    redirect(base_url('admin/plans.php'));
}

$plans = $db->query("SELECT * FROM plans ORDER BY sort_order")->fetchAll();
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1>Plans</h1><div class="sub">Pricing shown on the landing page &amp; used when assigning tenants</div></div>
<button class="btn btn-primary" onclick="openForm()">+ New Plan</button></div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Name</th><th class="num">Price/mo</th><th class="num">Max Users</th><th>Add-ons</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($plans as $p): ?>
    <tr>
      <td><?= e($p['name']) ?></td>
      <td class="num">₹<?= money($p['price_monthly']) ?></td>
      <td class="num"><?= (int)$p['max_users'] ?></td>
      <td>
        <?php if ($p['feature_storefront']): ?><span class="badge badge-paid" style="margin-right:4px">Storefront</span><?php endif; ?>
        <?php if ($p['feature_master_accounting']): ?><span class="badge badge-paid">Master Accounting</span><?php endif; ?>
      </td>
      <td><span class="badge <?= $p['is_active']?'badge-paid':'badge-cancelled' ?>"><?= $p['is_active']?'Active':'Disabled' ?></span></td>
      <td style="white-space:nowrap">
        <button class="btn btn-outline btn-sm" onclick='openForm(<?= json_encode($p) ?>)'>Edit</button>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-outline btn-sm"><?= $p['is_active']?'Disable':'Enable' ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="formModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:440px;width:100%">
    <h3 id="fTitle" style="margin-bottom:16px">New Plan</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
      <div class="form-group"><label>Plan Name</label><input class="form-control" name="name" id="f_name" required></div>
      <div class="form-row">
        <div class="form-group"><label>Price / Month (₹)</label><input class="form-control" type="number" step="0.01" name="price_monthly" id="f_price"></div>
        <div class="form-group"><label>Max Users</label><input class="form-control" type="number" name="max_users" id="f_users"></div>
      </div>
      <div class="form-group"><label>Max Invoices / Month</label><input class="form-control" type="number" name="max_invoices_month" id="f_inv"></div>
      <div class="form-group"><label>Features (one per line, shown on landing page)</label><textarea class="form-control" name="features" id="f_feat" rows="4"></textarea></div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-weight:400"><input type="checkbox" name="feature_storefront" id="f_storefront" style="width:auto"> Include Storefront (public online ordering page)</label>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-top:6px"><input type="checkbox" name="feature_master_accounting" id="f_accounting" style="width:auto"> Include Master Accounting (Chart of Accounts, Journal, Trial Balance)</label>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('formModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
function openForm(p){
  document.getElementById('formModal').style.display='flex';
  document.getElementById('fTitle').textContent = p ? 'Edit ' + p.name : 'New Plan';
  document.getElementById('f_id').value = p ? p.id : '';
  document.getElementById('f_name').value = p ? p.name : '';
  document.getElementById('f_price').value = p ? p.price_monthly : 0;
  document.getElementById('f_users').value = p ? p.max_users : 2;
  document.getElementById('f_inv').value = p ? p.max_invoices_month : 200;
  document.getElementById('f_feat').value = p && p.features ? JSON.parse(p.features).join("\n") : '';
  document.getElementById('f_storefront').checked = !!(p && parseInt(p.feature_storefront) === 1);
  document.getElementById('f_accounting').checked = !!(p && parseInt(p.feature_master_accounting) === 1);
}
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
