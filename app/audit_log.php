<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();
$u = Auth::user();
$pageTitle = 'Audit Log';
$activeNav = 'audit_log';

if ($u['role'] !== 'owner') {
    flash('error', 'Only the account owner can view the Audit Log.');
    redirect(base_url('app/dashboard.php'));
}

$stmt = $db->prepare("SELECT * FROM audit_log WHERE tenant_id=? ORDER BY id DESC LIMIT 200");
$stmt->execute([$tid]);
$rows = $stmt->fetchAll();

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Audit Log</h1><div class="sub">Recent account activity - who did what, and when</div></div>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="4"><div class="empty-state"><h3>No activity logged yet</h3><p>Team member changes and permission updates will show up here.</p></div></td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
      <td><?= e($r['user_name'] ?? 'System') ?></td>
      <td><span class="badge badge-draft"><?= e(ucfirst($r['action'])) ?> <?= e($r['entity_type']) ?></span></td>
      <td><?= e($r['entity_label']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="hint" style="margin-top:14px">Currently logs team member and permission changes. More entity types (invoices, products, payments) can be added to the trail on request.</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
