<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();
$u = Auth::user();
$pageTitle = 'Team / Users';
$activeNav = 'users';

if ($u['role'] !== 'owner') {
    flash('error', 'Only the account owner can manage team members.');
    redirect(base_url('app/dashboard.php'));
}

$roleLabels = ['owner' => 'Owner', 'admin' => 'Admin', 'manager' => 'Manager', 'accountant' => 'Accountant', 'front_desk' => 'Front Desk Executive', 'cashier' => 'Cashier', 'staff' => 'Staff'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = array_key_exists($_POST['role'] ?? '', $roleLabels) && $_POST['role'] !== 'owner' ? $_POST['role'] : 'staff';
        $password = $_POST['password'] ?? '';

        if ($name === '' || $email === '' || strlen($password) < 6) {
            flash('error', 'Name, email and a password (min 6 chars) are required.');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare("INSERT INTO users (tenant_id, name, email, password_hash, role) VALUES (?,?,?,?,?)")
                   ->execute([$tid, $name, $email, $hash, $role]);
                $newId = (int)$db->lastInsertId();
                ensure_default_role_permissions($db, $tid, $role);
                log_audit($db, $tid, 'create', 'user', $newId, "Added team member: $name ({$roleLabels[$role]})");
                flash('success', 'Team member added.');
            } catch (PDOException $e) {
                flash('error', 'A user with that email already exists.');
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT status, name FROM users WHERE id=? AND tenant_id=? AND role != 'owner'");
        $stmt->execute([$id, $tid]);
        $row = $stmt->fetch();
        if ($row) {
            $newStatus = $row['status'] === 'active' ? 'disabled' : 'active';
            $db->prepare("UPDATE users SET status=? WHERE id=? AND tenant_id=?")->execute([$newStatus, $id, $tid]);
            log_audit($db, $tid, 'update', 'user', $id, "Set {$row['name']} to $newStatus");
            flash('success', 'User status updated.');
        }
    } elseif ($action === 'change_role') {
        $id = (int)($_POST['id'] ?? 0);
        $newRole = array_key_exists($_POST['role'] ?? '', $roleLabels) && $_POST['role'] !== 'owner' ? $_POST['role'] : null;
        if ($newRole) {
            $stmt = $db->prepare("SELECT name FROM users WHERE id=? AND tenant_id=? AND role != 'owner'");
            $stmt->execute([$id, $tid]);
            $row = $stmt->fetch();
            if ($row) {
                $db->prepare("UPDATE users SET role=? WHERE id=? AND tenant_id=?")->execute([$newRole, $id, $tid]);
                ensure_default_role_permissions($db, $tid, $newRole);
                log_audit($db, $tid, 'update', 'user', $id, "Changed {$row['name']}'s role to {$roleLabels[$newRole]}");
                flash('success', 'Role updated.');
            }
        }
    }
    redirect(base_url('app/users.php'));
}

$stmt = $db->prepare("SELECT * FROM users WHERE tenant_id=? ORDER BY FIELD(role,'owner','admin','manager','accountant','front_desk','cashier','staff'), name");
$stmt->execute([$tid]);
$users = $stmt->fetchAll();

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Team / Users</h1><div class="sub"><?= count($users) ?> members</div></div>
  <div style="display:flex;gap:8px">
    <a href="<?= base_url('app/access_control.php') ?>" class="btn btn-outline">🔒 Access Control</a>
    <button class="btn btn-primary" onclick="document.getElementById('userModal').style.display='flex'">+ Add Team Member</button>
  </div>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td>
      <td><?= e($r['email']) ?></td>
      <td>
        <?php if ($r['role'] === 'owner'): ?>
          Owner
        <?php else: ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="change_role"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <select name="role" class="form-control" style="padding:4px 8px;font-size:.8rem" onchange="this.form.submit()">
              <?php foreach ($roleLabels as $rk => $rl): if ($rk === 'owner') continue; ?>
                <option value="<?= e($rk) ?>" <?= $r['role'] === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
      </td>
      <td><span class="badge <?= $r['status']==='active'?'badge-paid':'badge-cancelled' ?>"><?= e($r['status']) ?></span></td>
      <td><?= $r['last_login_at'] ? date('d M Y H:i', strtotime($r['last_login_at'])) : 'Never' ?></td>
      <td>
        <?php if ($r['role'] !== 'owner'): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-outline btn-sm"><?= $r['status']==='active' ? 'Disable' : 'Enable' ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="userModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:400px;width:100%">
    <h3 style="margin-bottom:16px">Add Team Member</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <div class="form-group"><label>Name</label><input class="form-control" name="name" required></div>
      <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" required></div>
      <div class="form-group"><label>Role</label>
        <select class="form-control" name="role">
          <?php foreach ($roleLabels as $rk => $rl): if ($rk === 'owner') continue; ?>
            <option value="<?= e($rk) ?>" <?= $rk === 'staff' ? 'selected' : '' ?>><?= e($rl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Password</label><input class="form-control" type="password" name="password" required></div>
      <p class="hint">New roles start with sensible default access - fine-tune anytime from Access Control.</p>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary">Add</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('userModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
