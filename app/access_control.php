<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();
$u = Auth::user();
$pageTitle = 'Access Control';
$activeNav = 'access_control';

// Access Control is always owner-only - not delegable through the role
// permission system itself, since letting a Manager grant themselves more
// access would defeat the point of the whole feature.
if ($u['role'] !== 'owner') {
    flash('error', 'Only the account owner can manage Access Control.');
    redirect(base_url('app/dashboard.php'));
}

$configurableRoles = ['manager' => 'Manager', 'accountant' => 'Accountant', 'front_desk' => 'Front Desk Executive', 'cashier' => 'Cashier', 'staff' => 'Staff'];
$catalog = permission_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $role = $_POST['role'] ?? '';
        if (!array_key_exists($role, $configurableRoles)) {
            flash('error', 'Unknown role.');
            redirect(base_url('app/access_control.php'));
        }
        $checked = $_POST['perm'] ?? [];
        $allAllKeys = [];
        foreach ($catalog as $group) { foreach ($group as $key => $label) { $allAllKeys[] = $key; } }

        $stmt = $db->prepare("INSERT INTO role_permissions (tenant_id, role, permission_key, allowed) VALUES (?,?,?,?)
                               ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)");
        foreach ($allAllKeys as $key) {
            $allowed = in_array($key, $checked, true) ? 1 : 0;
            $stmt->execute([$tid, $role, $key, $allowed]);
        }
        log_audit($db, $tid, 'update', 'role_permissions', null, "Updated permissions for role: {$configurableRoles[$role]}");
        flash('success', $configurableRoles[$role] . ' permissions updated.');
    } elseif ($action === 'apply_preset') {
        $role = $_POST['role'] ?? '';
        if (array_key_exists($role, $configurableRoles)) {
            $preset = default_permissions_for_role($role);
            $allAllKeys = [];
            foreach ($catalog as $group) { foreach ($group as $key => $label) { $allAllKeys[] = $key; } }
            $stmt = $db->prepare("INSERT INTO role_permissions (tenant_id, role, permission_key, allowed) VALUES (?,?,?,?)
                                   ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)");
            foreach ($allAllKeys as $key) {
                $stmt->execute([$tid, $role, $key, in_array($key, $preset, true) ? 1 : 0]);
            }
            flash('success', 'Default preset applied for ' . $configurableRoles[$role] . '.');
        }
    }
    redirect(base_url('app/access_control.php'));
}

// Make sure every configurable role has at least default rows before display.
foreach (array_keys($configurableRoles) as $role) {
    ensure_default_role_permissions($db, $tid, $role);
}

$permStmt = $db->prepare("SELECT role, permission_key, allowed FROM role_permissions WHERE tenant_id=?");
$permStmt->execute([$tid]);
$current = [];
foreach ($permStmt->fetchAll() as $row) {
    $current[$row['role']][$row['permission_key']] = (bool)$row['allowed'];
}

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Access Control</h1><div class="sub">Choose what each role can see. Owner and Admin always have full access.</div></div>
</div>

<div class="doc-type-tabs" id="roleTabs">
  <?php $first = true; foreach ($configurableRoles as $roleKey => $roleLabel): ?>
    <a href="#" data-role="<?= e($roleKey) ?>" class="role-tab <?= $first ? 'active' : '' ?>"><?= e($roleLabel) ?></a>
  <?php $first = false; endforeach; ?>
</div>

<?php foreach ($configurableRoles as $roleKey => $roleLabel): ?>
<div class="role-panel" data-role-panel="<?= e($roleKey) ?>" style="display:<?= $roleKey === array_key_first($configurableRoles) ? 'block' : 'none' ?>">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="role" value="<?= e($roleKey) ?>">
    <div class="card card-pad" style="margin-bottom:16px">
      <?php foreach ($catalog as $groupName => $items): ?>
        <h4 style="margin:16px 0 8px;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;color:#8a93ab"><?= e($groupName) ?></h4>
        <?php foreach ($items as $key => $label): ?>
          <label style="display:flex;align-items:center;gap:10px;padding:8px 0;font-weight:400;border-bottom:1px solid #f0ece0">
            <input type="checkbox" name="perm[]" value="<?= e($key) ?>" style="width:auto" <?= !empty($current[$roleKey][$key]) ? 'checked' : '' ?>>
            <?= e($label) ?>
          </label>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary">Save <?= e($roleLabel) ?> Permissions</button>
    </div>
  </form>
  <form method="post" style="margin-top:10px" onsubmit="return confirm('Reset <?= e($roleLabel) ?> to the recommended default permissions?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="apply_preset">
    <input type="hidden" name="role" value="<?= e($roleKey) ?>">
    <button type="submit" class="btn btn-outline btn-sm">Reset to Recommended Default</button>
  </form>
</div>
<?php endforeach; ?>

<script>
document.querySelectorAll('.role-tab').forEach(tab => {
  tab.addEventListener('click', (e) => {
    e.preventDefault();
    document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const role = tab.dataset.role;
    document.querySelectorAll('.role-panel').forEach(p => {
      p.style.display = p.dataset.rolePanel === role ? 'block' : 'none';
    });
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
