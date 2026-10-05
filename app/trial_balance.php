<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'accounting', 'Master Accounting');
$tid = Auth::tenantId();
$pageTitle = 'Trial Balance';
$activeNav = 'trial_balance';
enforce_feature_gate($db, $tid, 'master_accounting', 'Master Accounting');
ensure_default_chart_of_accounts($db, $tid);

$asOf = $_GET['as_of'] ?? today();

$stmt = $db->prepare("SELECT a.id, a.code, a.name, a.type,
                              COALESCE(SUM(jl.debit),0) total_debit,
                              COALESCE(SUM(jl.credit),0) total_credit
                       FROM chart_of_accounts a
                       LEFT JOIN journal_lines jl ON jl.account_id = a.id
                       LEFT JOIN journal_entries je ON je.id = jl.entry_id AND je.entry_date <= ?
                       WHERE a.tenant_id = ? AND a.is_active = 1
                       GROUP BY a.id
                       ORDER BY a.code");
$stmt->execute([$asOf, $tid]);
$rows = $stmt->fetchAll();

require __DIR__ . '/partials/header.php';

$typeLabels = ['asset'=>'Asset','liability'=>'Liability','equity'=>'Equity','income'=>'Income','expense'=>'Expense'];
$grandDebit = 0; $grandCredit = 0;
$displayRows = [];
foreach ($rows as $r) {
    $net = (float)$r['total_debit'] - (float)$r['total_credit'];
    // Normal balance side depends on account type - assets/expenses are
    // debit-normal, liabilities/equity/income are credit-normal.
    $debitNormal = in_array($r['type'], ['asset', 'expense'], true);
    $debitBal = 0; $creditBal = 0;
    if ($debitNormal) {
        if ($net >= 0) { $debitBal = $net; } else { $creditBal = -$net; }
    } else {
        if ($net <= 0) { $creditBal = -$net; } else { $debitBal = $net; }
    }
    if ($debitBal == 0 && $creditBal == 0) continue; // skip untouched accounts
    $displayRows[] = ['code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'], 'debit' => $debitBal, 'credit' => $creditBal];
    $grandDebit += $debitBal;
    $grandCredit += $creditBal;
}
?>
<div class="page-head">
  <div><h1>Trial Balance</h1><div class="sub">As of <?= date('d M Y', strtotime($asOf)) ?></div></div>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <input class="form-control" type="date" name="as_of" value="<?= e($asOf) ?>" onchange="this.form.submit()">
  </form>
</div>

<div class="doc-type-tabs">
  <a href="<?= base_url('app/accounts.php') ?>">Chart of Accounts</a>
  <a href="<?= base_url('app/journal.php') ?>">Journal Entries</a>
  <a href="<?= base_url('app/trial_balance.php') ?>" class="active">Trial Balance</a>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
  <tbody>
  <?php if (!$displayRows): ?>
    <tr><td colspan="5"><div class="empty-state"><h3>No journal activity yet</h3><p>Once you post Journal Entries, their balances will show here.</p></div></td></tr>
  <?php endif; ?>
  <?php foreach ($displayRows as $r): ?>
    <tr>
      <td><?= e($r['code']) ?></td>
      <td><?= e($r['name']) ?></td>
      <td><?= e($typeLabels[$r['type']]) ?></td>
      <td class="num"><?= $r['debit'] > 0 ? '₹' . money($r['debit']) : '' ?></td>
      <td class="num"><?= $r['credit'] > 0 ? '₹' . money($r['credit']) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($displayRows): ?>
  <tr style="font-weight:700;border-top:2px solid #16213a">
    <td colspan="3">Total</td>
    <td class="num">₹<?= money($grandDebit) ?></td>
    <td class="num">₹<?= money($grandCredit) ?></td>
  </tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($displayRows && abs($grandDebit - $grandCredit) > 0.01): ?>
<div class="alert alert-error" style="margin-top:16px">Debit and credit totals don't match - this shouldn't happen since every journal entry is required to balance when saved. Please double-check recent entries.</div>
<?php endif; ?>

<p class="hint" style="margin-top:16px">This Trial Balance reflects only manually posted Journal Entries. Your everyday sales, purchases, payments and expenses are tracked separately in Invoices, Payments, and Reports - use Journal Entries here for things those flows don't cover (opening balances, depreciation, bank charges, capital introduced, etc).</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
