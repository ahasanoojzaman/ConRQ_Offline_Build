<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'accounting', 'Master Accounting');
$tid = Auth::tenantId();
enforce_feature_gate($db, $tid, 'master_accounting', 'Master Accounting');

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $db->prepare("DELETE FROM journal_entries WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
    flash('success', 'Journal entry deleted.');
    redirect(base_url('app/journal.php'));
}

$entryStmt = $db->prepare("SELECT * FROM journal_entries WHERE id=? AND tenant_id=?");
$entryStmt->execute([$id, $tid]);
$entry = $entryStmt->fetch();
if (!$entry) { flash('error', 'Journal entry not found.'); redirect(base_url('app/journal.php')); }

$linesStmt = $db->prepare("SELECT jl.*, a.code, a.name FROM journal_lines jl JOIN chart_of_accounts a ON a.id = jl.account_id
                            WHERE jl.entry_id=? ORDER BY jl.sort_order");
$linesStmt->execute([$id]);
$lines = $linesStmt->fetchAll();

$pageTitle = 'Journal Entry ' . $entry['entry_no'];
$activeNav = 'journal';
require __DIR__ . '/partials/header.php';

$totalDebit = array_sum(array_column($lines, 'debit'));
$totalCredit = array_sum(array_column($lines, 'credit'));
?>
<div class="page-head">
  <div><h1>Journal Entry <?= e($entry['entry_no']) ?></h1><div class="sub"><?= date('d M Y', strtotime($entry['entry_date'])) ?><?= $entry['narration'] ? ' · ' . e($entry['narration']) : '' ?></div></div>
  <form method="post" onsubmit="return confirm('Delete this journal entry?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <button class="btn btn-danger">Delete Entry</button>
  </form>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Account</th><th>Notes</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr>
      <td><?= e($l['code']) ?> - <?= e($l['name']) ?></td>
      <td><?= e($l['notes']) ?></td>
      <td class="num"><?= $l['debit'] > 0 ? '₹' . money($l['debit']) : '' ?></td>
      <td class="num"><?= $l['credit'] > 0 ? '₹' . money($l['credit']) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <tr style="font-weight:700;border-top:2px solid #16213a">
    <td colspan="2">Total</td>
    <td class="num">₹<?= money($totalDebit) ?></td>
    <td class="num">₹<?= money($totalCredit) ?></td>
  </tr>
  </tbody>
</table>
</div>

<a href="<?= base_url('app/journal.php') ?>" class="btn btn-outline" style="margin-top:16px">← Back to Journal Entries</a>

<?php require __DIR__ . '/partials/footer.php'; ?>
