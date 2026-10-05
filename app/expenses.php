<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'expenses', 'Expenses');
$tid = Auth::tenantId();
$pageTitle = 'Expenses';
$activeNav = 'expenses';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $category = trim($_POST['category'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $date = $_POST['expense_date'] ?? today();
        $notes = trim($_POST['notes'] ?? '');
        if ($category === '' || $amount <= 0) {
            flash('error', 'Category and a valid amount are required.');
        } else {
            $db->prepare("INSERT INTO expenses (tenant_id, category, amount, expense_date, notes, created_by) VALUES (?,?,?,?,?,?)")
               ->execute([$tid, $category, $amount, $date, $notes, Auth::user()['id']]);
            flash('success', 'Expense recorded.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM expenses WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
        flash('success', 'Expense deleted.');
    }
    redirect(base_url('app/expenses.php'));
}

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$stmt = $db->prepare("SELECT * FROM expenses WHERE tenant_id=? ORDER BY expense_date DESC, id DESC LIMIT 200");
$stmt->execute([$tid]);
$expenses = $stmt->fetchAll();

$monthTotalStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE tenant_id=? AND expense_date BETWEEN ? AND ?");
$monthTotalStmt->execute([$tid, $monthStart, $monthEnd]);
$monthTotal = (float)$monthTotalStmt->fetch()['t'];

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Expenses</h1><div class="sub">₹<?= money($monthTotal) ?> spent this month</div></div>
  <button class="btn btn-primary" onclick="document.getElementById('expModal').style.display='flex'">+ Add Expense</button>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Date</th><th>Category</th><th>Notes</th><th class="num">Amount</th><th></th></tr></thead>
  <tbody>
  <?php if (!$expenses): ?><tr><td colspan="5"><div class="empty-state"><h3>No expenses recorded</h3></div></td></tr><?php endif; ?>
  <?php foreach ($expenses as $ex): ?>
    <tr>
      <td><?= date('d M Y', strtotime($ex['expense_date'])) ?></td>
      <td><?= e($ex['category']) ?></td>
      <td><?= e($ex['notes']) ?></td>
      <td class="num">₹<?= money($ex['amount']) ?></td>
      <td>
        <form method="post" onsubmit="return confirm('Delete this expense?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
          <button class="btn btn-danger btn-sm">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="expModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:400px;width:100%">
    <h3 style="margin-bottom:16px">Add Expense</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <div class="form-group"><label>Category</label><input class="form-control" name="category" placeholder="Rent, Electricity, Transport..." required></div>
      <div class="form-row">
        <div class="form-group"><label>Amount</label><input class="form-control" type="number" step="0.01" name="amount" required></div>
        <div class="form-group"><label>Date</label><input class="form-control" type="date" name="expense_date" value="<?= today() ?>"></div>
      </div>
      <div class="form-group"><label>Notes</label><input class="form-control" name="notes"></div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('expModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
