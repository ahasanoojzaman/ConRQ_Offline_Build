<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
$pageTitle = 'Admin Dashboard';
$activeNav = 'dashboard';

$totalTenants = (int)$db->query("SELECT COUNT(*) c FROM tenants")->fetch()['c'];
$activeTenants = (int)$db->query("SELECT COUNT(*) c FROM tenants WHERE status='active'")->fetch()['c'];
$trialTenants = (int)$db->query("SELECT COUNT(*) c FROM tenants WHERE status='trial'")->fetch()['c'];
$newDemoRequests = (int)$db->query("SELECT COUNT(*) c FROM demo_requests WHERE status='new'")->fetch()['c'];

$mrr = (float)$db->query("SELECT COALESCE(SUM(p.price_monthly),0) t FROM tenants t JOIN plans p ON p.id=t.plan_id WHERE t.status='active'")->fetch()['t'];

$recentTenants = $db->query("SELECT t.*, p.name AS plan_name FROM tenants t LEFT JOIN plans p ON p.id=t.plan_id ORDER BY t.id DESC LIMIT 8")->fetchAll();
$recentDemos = $db->query("SELECT * FROM demo_requests ORDER BY id DESC LIMIT 6")->fetchAll();

require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1>Admin Dashboard</h1><div class="sub">Platform overview</div></div>
<a href="<?= base_url('admin/tenant_form.php') ?>" class="btn btn-primary">+ New Tenant</a></div>

<div class="grid grid-4" style="margin-bottom:22px">
  <div class="card stat accent"><div class="label">Monthly Recurring Revenue</div><div class="value money"><?= money($mrr) ?></div></div>
  <div class="card stat"><div class="label">Active Tenants</div><div class="value"><?= $activeTenants ?></div></div>
  <div class="card stat"><div class="label">Trial Tenants</div><div class="value"><?= $trialTenants ?></div></div>
  <div class="card stat"><div class="label">New Demo Requests</div><div class="value" style="color:<?= $newDemoRequests?'#b5443a':'inherit' ?>"><?= $newDemoRequests ?></div></div>
</div>

<div class="grid grid-2">
  <div class="card card-pad">
    <h3 style="margin-bottom:14px">Recent Tenants</h3>
    <table class="data">
      <thead><tr><th>Company</th><th>Plan</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentTenants as $t): ?>
        <tr onclick="location='<?= base_url('admin/tenant_form.php?id=' . $t['id']) ?>'" style="cursor:pointer">
          <td><?= e($t['company_name']) ?></td>
          <td><?= e($t['plan_name'] ?? '—') ?></td>
          <td><span class="badge badge-<?= $t['status']==='active'?'paid':($t['status']==='trial'?'partial':'cancelled') ?>"><?= e($t['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card card-pad">
    <h3 style="margin-bottom:14px">Recent Demo Requests</h3>
    <table class="data">
      <thead><tr><th>Name</th><th>Phone</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentDemos as $d): ?>
        <tr><td><?= e($d['name']) ?></td><td><?= e($d['phone']) ?></td><td><span class="badge badge-draft"><?= e($d['status']) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if (!$recentDemos): ?><tr><td colspan="3"><div class="empty-state">No demo requests yet</div></td></tr><?php endif; ?>
      </tbody>
    </table>
    <a href="<?= base_url('admin/demo_requests.php') ?>" class="btn btn-outline btn-sm" style="margin-top:12px">View All</a>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
