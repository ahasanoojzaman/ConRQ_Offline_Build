<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'payments', 'Party Ledger');
$tid = Auth::tenantId();
$pageTitle = 'Party Ledger';
$activeNav = 'ledger';

$partyId = (int)($_GET['party_id'] ?? 0);
$partiesStmt = $db->prepare("SELECT id,name,opening_balance,balance_type FROM parties WHERE tenant_id=? AND is_active=1 ORDER BY name");
$partiesStmt->execute([$tid]);
$parties = $partiesStmt->fetchAll();

$entries = [];
$party = null;
$closingBalance = 0;
$balanceType = 'dr';

if ($partyId) {
    foreach ($parties as $p) { if ($p['id'] == $partyId) { $party = $p; break; } }
    if ($party) {
        // Invoices (sales -> Dr, purchase -> Cr)
        $invStmt = $db->prepare("SELECT doc_date AS d, doc_type, doc_no, total_amount FROM invoices WHERE tenant_id=? AND party_id=? AND status!='cancelled' AND doc_type IN ('invoice','purchase') ORDER BY doc_date, id");
        $invStmt->execute([$tid, $partyId]);
        foreach ($invStmt->fetchAll() as $inv) {
            $entries[] = [
                'date' => $inv['d'],
                'particular' => ucfirst($inv['doc_type']) . ' ' . $inv['doc_no'],
                'debit' => $inv['doc_type'] === 'invoice' ? (float)$inv['total_amount'] : 0,
                'credit' => $inv['doc_type'] === 'purchase' ? (float)$inv['total_amount'] : 0,
            ];
        }
        $payStmt = $db->prepare("SELECT payment_date AS d, direction, mode, amount FROM payments WHERE tenant_id=? AND party_id=? ORDER BY payment_date, id");
        $payStmt->execute([$tid, $partyId]);
        foreach ($payStmt->fetchAll() as $pay) {
            $entries[] = [
                'date' => $pay['d'],
                'particular' => 'Payment ' . ($pay['direction']==='in' ? 'Received' : 'Paid') . ' (' . ucfirst($pay['mode']) . ')',
                'debit' => $pay['direction'] === 'out' ? (float)$pay['amount'] : 0,
                'credit' => $pay['direction'] === 'in' ? (float)$pay['amount'] : 0,
            ];
        }
        usort($entries, fn($a,$b) => strcmp($a['date'], $b['date']));

        $balance = $party['balance_type'] === 'dr' ? (float)$party['opening_balance'] : -(float)$party['opening_balance'];
        foreach ($entries as &$e) {
            $balance += $e['debit'] - $e['credit'];
            $e['balance'] = $balance;
        }
        unset($e);
        $closingBalance = abs($balance);
        $balanceType = $balance >= 0 ? 'dr' : 'cr';
    }
}

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Party Ledger</h1><div class="sub">Running balance / statement of account</div></div>
</div>

<form method="get" class="form-group" style="max-width:320px;margin-bottom:20px">
  <select class="form-control" name="party_id" onchange="this.form.submit()">
    <option value="">-- Select a party --</option>
    <?php foreach ($parties as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= $partyId==$p['id']?'selected':'' ?>><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<?php if ($party): ?>
<div class="card card-pad" style="margin-bottom:18px">
  <div class="grid grid-3">
    <div class="stat" style="padding:0"><div class="label">Party</div><div class="value" style="font-size:1.2rem"><?= e($party['name']) ?></div></div>
    <div class="stat" style="padding:0"><div class="label">Closing Balance</div><div class="value" style="font-size:1.3rem;color:<?= $balanceType==='dr'?'#b5443a':'#2f7d5e' ?>">₹<?= money($closingBalance) ?> <?= strtoupper($balanceType) ?></div></div>
    <div class="stat" style="padding:0"><div class="label">Meaning</div><div class="value" style="font-size:.95rem;font-family:inherit"><?= $balanceType==='dr' ? 'They owe you' : 'You owe them' ?></div></div>
  </div>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Date</th><th>Particulars</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
  <tbody>
  <?php if (!$entries): ?>
    <tr><td colspan="5"><div class="empty-state"><h3>No transactions yet</h3></div></td></tr>
  <?php endif; ?>
  <?php foreach ($entries as $e): ?>
    <tr>
      <td><?= date('d M Y', strtotime($e['date'])) ?></td>
      <td><?= e($e['particular']) ?></td>
      <td class="num"><?= $e['debit'] ? '₹'.money($e['debit']) : '' ?></td>
      <td class="num"><?= $e['credit'] ? '₹'.money($e['credit']) : '' ?></td>
      <td class="num">₹<?= money(abs($e['balance'])) ?> <?= $e['balance']>=0 ? 'Dr' : 'Cr' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state"><h3>Select a party</h3><p>Choose a customer or supplier above to view their ledger.</p></div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
