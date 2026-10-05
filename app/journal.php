<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'accounting', 'Master Accounting');
$tid = Auth::tenantId();
$pageTitle = 'Journal Entries';
$activeNav = 'journal';
enforce_feature_gate($db, $tid, 'master_accounting', 'Master Accounting');
ensure_default_chart_of_accounts($db, $tid);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $entryDate = $_POST['entry_date'] ?? today();
    $narration = trim($_POST['narration'] ?? '');
    $lines = $_POST['lines'] ?? [];

    $cleanLines = [];
    $totalDebit = 0; $totalCredit = 0;
    foreach ($lines as $line) {
        $accountId = (int)($line['account_id'] ?? 0);
        $debit = (float)($line['debit'] ?? 0);
        $credit = (float)($line['credit'] ?? 0);
        if (!$accountId || ($debit <= 0 && $credit <= 0)) continue;
        $cleanLines[] = ['account_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'notes' => trim($line['notes'] ?? '')];
        $totalDebit += $debit;
        $totalCredit += $credit;
    }

    if (count($cleanLines) < 2) {
        $errors[] = 'A journal entry needs at least two lines (one debit, one credit).';
    } elseif (round($totalDebit, 2) !== round($totalCredit, 2)) {
        $errors[] = 'This entry does not balance: total debit ₹' . money($totalDebit) . ' vs total credit ₹' . money($totalCredit) . '. They must be equal.';
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            $entryNo = next_doc_number($db, $tid, 'journal');
            $stmt = $db->prepare("INSERT INTO journal_entries (tenant_id, entry_no, entry_date, narration, created_by) VALUES (?,?,?,?,?)");
            $stmt->execute([$tid, $entryNo, $entryDate, $narration, Auth::user()['id']]);
            $entryId = (int)$db->lastInsertId();

            $sort = 0;
            $lineStmt = $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, notes, sort_order) VALUES (?,?,?,?,?,?)");
            foreach ($cleanLines as $l) {
                $lineStmt->execute([$entryId, $l['account_id'], $l['debit'], $l['credit'], $l['notes'], $sort++]);
            }
            $db->commit();
            flash('success', "Journal entry $entryNo saved.");
            redirect(base_url('app/journal.php'));
        } catch (Throwable $ex) {
            $db->rollBack();
            $errors[] = 'Could not save this entry. Please try again.';
        }
    }
}

require __DIR__ . '/partials/header.php';

$accStmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE tenant_id=? AND is_active=1 ORDER BY code");
$accStmt->execute([$tid]);
$accounts = $accStmt->fetchAll();

$entriesStmt = $db->prepare("SELECT je.*, COALESCE(SUM(jl.debit),0) total FROM journal_entries je
                              LEFT JOIN journal_lines jl ON jl.entry_id = je.id
                              WHERE je.tenant_id=? GROUP BY je.id ORDER BY je.entry_date DESC, je.id DESC LIMIT 100");
$entriesStmt->execute([$tid]);
$entries = $entriesStmt->fetchAll();
?>
<div class="page-head">
  <div><h1>Journal Entries</h1><div class="sub">Manual double-entry postings</div></div>
  <button class="btn btn-primary" onclick="document.getElementById('jeModal').style.display='flex'">+ New Journal Entry</button>
</div>

<div class="doc-type-tabs">
  <a href="<?= base_url('app/accounts.php') ?>">Chart of Accounts</a>
  <a href="<?= base_url('app/journal.php') ?>" class="active">Journal Entries</a>
  <a href="<?= base_url('app/trial_balance.php') ?>">Trial Balance</a>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Entry No</th><th>Date</th><th>Narration</th><th class="num">Amount</th></tr></thead>
  <tbody>
  <?php if (!$entries): ?><tr><td colspan="4"><div class="empty-state"><h3>No journal entries yet</h3><p>Use this for anything the automatic sales/purchase/payment flows don't cover - opening balances, depreciation, bank charges, owner's capital, and so on.</p></div></td></tr><?php endif; ?>
  <?php foreach ($entries as $e): ?>
    <tr onclick="location='<?= base_url('app/journal_view.php?id=' . $e['id']) ?>'" style="cursor:pointer">
      <td><?= e($e['entry_no']) ?></td>
      <td><?= date('d M Y', strtotime($e['entry_date'])) ?></td>
      <td><?= e($e['narration']) ?></td>
      <td class="num">₹<?= money($e['total']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="jeModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:600px;width:100%;max-height:88vh;overflow-y:auto">
    <h3 style="margin-bottom:16px">New Journal Entry</h3>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-row">
        <div class="form-group"><label>Date</label><input class="form-control" type="date" name="entry_date" value="<?= today() ?>"></div>
        <div class="form-group" style="flex:2"><label>Narration</label><input class="form-control" name="narration" placeholder="What is this entry for?"></div>
      </div>
      <table class="data" style="margin-bottom:10px">
        <thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
        <tbody id="jeLines"></tbody>
      </table>
      <button type="button" class="btn btn-outline btn-sm" onclick="addLine()">+ Add Line</button>
      <div style="display:flex;justify-content:space-between;margin:14px 0;font-size:.9rem">
        <span>Total Debit: <b id="jeTotalDebit">0.00</b></span>
        <span>Total Credit: <b id="jeTotalCredit">0.00</b></span>
        <span id="jeBalanceMsg" style="font-weight:700"></span>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary">Save Entry</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('jeModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
const accounts = <?= json_encode(array_map(fn($a) => ['id'=>$a['id'],'label'=>$a['code'].' - '.$a['name']], $accounts)) ?>;
let lineIdx = 0;

function accountOptions(){
  return accounts.map(a => `<option value="${a.id}">${a.label}</option>`).join('');
}

function lineRow(idx){
  return `<tr>
    <td><select class="form-control" name="lines[${idx}][account_id]"><option value="">-- Select --</option>${accountOptions()}</select></td>
    <td><input type="number" step="0.01" class="form-control je-debit" name="lines[${idx}][debit]" value="0" oninput="calcJeTotals()"></td>
    <td><input type="number" step="0.01" class="form-control je-credit" name="lines[${idx}][credit]" value="0" oninput="calcJeTotals()"></td>
  </tr>`;
}

function addLine(){
  document.getElementById('jeLines').insertAdjacentHTML('beforeend', lineRow(lineIdx));
  lineIdx++;
}

function calcJeTotals(){
  let debit = 0, credit = 0;
  document.querySelectorAll('.je-debit').forEach(i => debit += parseFloat(i.value) || 0);
  document.querySelectorAll('.je-credit').forEach(i => credit += parseFloat(i.value) || 0);
  document.getElementById('jeTotalDebit').textContent = debit.toFixed(2);
  document.getElementById('jeTotalCredit').textContent = credit.toFixed(2);
  const msg = document.getElementById('jeBalanceMsg');
  if (Math.abs(debit - credit) < 0.005 && debit > 0) {
    msg.textContent = '✓ Balanced';
    msg.style.color = '#2f7d5e';
  } else {
    msg.textContent = 'Not balanced';
    msg.style.color = '#b5443a';
  }
}

addLine(); addLine();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
