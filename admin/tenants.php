<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
$pageTitle = 'Tenants';
$activeNav = 'tenants';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['trial','active','suspended','expired'], true)) {
        $db->prepare("UPDATE tenants SET status=? WHERE id=?")->execute([$status, $id]);
        flash('success', 'Tenant status updated.');
    }
    redirect(base_url('admin/tenants.php'));
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT t.*, p.name AS plan_name FROM tenants t LEFT JOIN plans p ON p.id=t.plan_id WHERE 1=1";
$params = [];
if ($search !== '') { $sql .= " AND (t.company_name LIKE ? OR t.email LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
$sql .= " ORDER BY t.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll();

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Tenants</h1><div class="sub"><?= count($tenants) ?> companies</div></div>
  <a href="<?= base_url('admin/tenant_form.php') ?>" class="btn btn-primary">+ New Tenant</a>
</div>

<form method="get" style="margin-bottom:16px;max-width:320px">
  <input class="form-control" type="text" name="q" placeholder="Search company or email..." value="<?= e($search) ?>" onchange="this.form.submit()">
</form>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Company</th><th>Owner</th><th>Plan</th><th>Status</th><th>Since</th><th></th></tr></thead>
  <tbody>
  <?php if (!$tenants): ?><tr><td colspan="6"><div class="empty-state"><h3>No tenants yet</h3></div></td></tr><?php endif; ?>
  <?php foreach ($tenants as $t): ?>
    <tr>
      <td><?= e($t['company_name']) ?><div style="font-size:.75rem;color:#8a93ab"><?= e($t['email']) ?></div></td>
      <td><?= e($t['owner_name']) ?><div style="font-size:.75rem;color:#8a93ab"><?= e($t['phone']) ?></div></td>
      <td><?= e($t['plan_name'] ?? '—') ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <select name="status" class="form-control" style="padding:4px 8px;font-size:.8rem" onchange="this.form.submit()">
            <?php foreach (['trial','active','suspended','expired'] as $s): ?>
              <option value="<?= $s ?>" <?= $t['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td><?= date('d M Y', strtotime($t['created_at'])) ?></td>
      <td><a href="<?= base_url('admin/tenant_form.php?id=' . $t['id']) ?>" class="btn btn-outline btn-sm">Manage</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
