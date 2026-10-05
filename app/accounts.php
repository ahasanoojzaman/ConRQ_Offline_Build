<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'accounting', 'Master Accounting');
$tid = Auth::tenantId();
$pageTitle = 'Chart of Accounts';
$activeNav = 'accounts';
enforce_feature_gate($db, $tid, 'master_accounting', 'Master Accounting');
ensure_default_chart_of_accounts($db, $tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['asset','liability','equity','income','expense'], true) ? $_POST['type'] : 'asset';

        if ($code === '' || $name === '') {
            flash('error', 'Code and name are required.');
        } else {
            try {
                if ($id) {
                    $db->prepare("UPDATE chart_of_accounts SET code=?, name=?, type=? WHERE id=? AND tenant_id=? AND is_system=0")
                       ->execute([$code, $name, $type, $id, $tid]);
                    flash('success', 'Account updated.');
                } else {
                    $db->prepare("INSERT INTO chart_of_accounts (tenant_id, code, name, type) VALUES (?,?,?,?)")
                       ->execute([$tid, $code, $name, $type]);
                    flash('success', 'Account added.');
                }
            } catch (PDOException $e) {
                flash('error', 'An account with that code already exists.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = $db->prepare("SELECT COUNT(*) c FROM journal_lines jl JOIN chart_of_accounts a ON a.id=jl.account_id WHERE a.id=? AND a.tenant_id=?");
        $used->execute([$id, $tid]);
        if ((int)$used->fetch()['c'] > 0) {
            flash('error', 'This account has journal entries against it and cannot be deleted. Mark it inactive instead if needed.');
        } else {
            $db->prepare("DELETE FROM chart_of_accounts WHERE id=? AND tenant_id=? AND is_system=0")->execute([$id, $tid]);
            flash('success', 'Account deleted.');
        }
    }
    redirect(base_url('app/accounts.php'));
}

require __DIR__ . '/partials/header.php';

$accStmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE tenant_id=? AND is_active=1 ORDER BY code");
$accStmt->execute([$tid]);
$accounts = $accStmt->fetchAll();
$typeLabels = ['asset'=>'Asset','liability'=>'Liability','equity'=>'Equity','income'=>'Income','expense'=>'Expense'];
?>
<div class="page-head">
  <div><h1>Chart of Accounts</h1><div class="sub">The account structure used for Journal Entries and Trial Balance</div></div>
  <button class="btn btn-primary" onclick="openForm()">+ Add Account</button>
</div>

<div class="doc-type-tabs">
  <a href="<?= base_url('app/accounts.php') ?>" class="active">Chart of Accounts</a>
  <a href="<?= base_url('app/journal.php') ?>">Journal Entries</a>
  <a href="<?= base_url('app/trial_balance.php') ?>">Trial Balance</a>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Code</th><th>Name</th><th>Type</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($accounts as $a): ?>
    <tr>
      <td><?= e($a['code']) ?></td>
      <td><?= e($a['name']) ?> <?php if ($a['is_system']): ?><span class="badge badge-draft" style="font-size:.62rem">default</span><?php endif; ?></td>
      <td><?= e($typeLabels[$a['type']]) ?></td>
      <td style="white-space:nowrap">
        <?php if (!$a['is_system']): ?>
        <button class="btn btn-outline btn-sm" onclick='openForm(<?= json_encode($a) ?>)'>Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this account?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="btn btn-danger btn-sm">Delete</button>
        </form>
        <?php else: ?>
        <span class="hint">system default</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="formModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:380px;width:100%">
    <h3 id="fTitle" style="margin-bottom:16px">Add Account</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
      <div class="form-group"><label>Code</label><input class="form-control" name="code" id="f_code" required></div>
      <div class="form-group"><label>Name</label><input class="form-control" name="name" id="f_name" required></div>
      <div class="form-group"><label>Type</label>
        <select class="form-control" name="type" id="f_type">
          <option value="asset">Asset</option>
          <option value="liability">Liability</option>
          <option value="equity">Equity</option>
          <option value="income">Income</option>
          <option value="expense">Expense</option>
        </select>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('formModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
function openForm(a){
  document.getElementById('formModal').style.display='flex';
  document.getElementById('fTitle').textContent = a ? 'Edit Account' : 'Add Account';
  document.getElementById('f_id').value = a ? a.id : '';
  document.getElementById('f_code').value = a ? a.code : '';
  document.getElementById('f_name').value = a ? a.name : '';
  document.getElementById('f_type').value = a ? a.type : 'asset';
}
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
