<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'payments', 'Payments');
$tid = Auth::tenantId();
$pageTitle = 'Payments';
$activeNav = 'payments';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $partyId = (int)($_POST['party_id'] ?? 0) ?: null;
    $direction = $_POST['direction'] === 'out' ? 'out' : 'in';
    $mode = $_POST['mode'] ?? 'cash';
    $amount = (float)($_POST['amount'] ?? 0);
    $date = $_POST['payment_date'] ?? today();
    $notes = trim($_POST['notes'] ?? '');

    if ($amount > 0) {
        $db->prepare("INSERT INTO payments (tenant_id, party_id, direction, mode, amount, payment_date, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$tid, $partyId, $direction, $mode, $amount, $date, $notes, Auth::user()['id']]);
        flash('success', 'Payment recorded.');
    } else {
        flash('error', 'Amount must be greater than zero.');
    }
    redirect(base_url('app/payments.php'));
}

$partiesStmt = $db->prepare("SELECT id,name FROM parties WHERE tenant_id=? AND is_active=1 ORDER BY name");
$partiesStmt->execute([$tid]);
$parties = $partiesStmt->fetchAll();

$stmt = $db->prepare("SELECT pm.*, pt.name AS party_name, inv.doc_no FROM payments pm
                       LEFT JOIN parties pt ON pt.id = pm.party_id
                       LEFT JOIN invoices inv ON inv.id = pm.invoice_id
                       WHERE pm.tenant_id=? ORDER BY pm.id DESC LIMIT 200");
$stmt->execute([$tid]);
$payments = $stmt->fetchAll();

$totalIn = array_sum(array_map(fn($p)=> $p['direction']==='in' ? $p['amount'] : 0, $payments));
$totalOut = array_sum(array_map(fn($p)=> $p['direction']==='out' ? $p['amount'] : 0, $payments));

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Payments</h1><div class="sub">₹<?= money($totalIn) ?> received · ₹<?= money($totalOut) ?> paid out</div></div>
  <button class="btn btn-primary" onclick="document.getElementById('payModal').style.display='flex'">+ Record Payment</button>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Date</th><th>Party</th><th>Direction</th><th>Mode</th><th>Invoice</th><th class="num">Amount</th><th>Notes</th></tr></thead>
  <tbody>
  <?php if (!$payments): ?>
    <tr><td colspan="7"><div class="empty-state"><h3>No payments yet</h3></div></td></tr>
  <?php endif; ?>
  <?php foreach ($payments as $p): ?>
    <tr>
      <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
      <td><?= e($p['party_name'] ?? '—') ?></td>
      <td><span class="badge <?= $p['direction']==='in' ? 'badge-paid' : 'badge-unpaid' ?>"><?= $p['direction']==='in' ? 'Received' : 'Paid Out' ?></span></td>
      <td><?= e(ucfirst($p['mode'])) ?></td>
      <td><?= e($p['doc_no'] ?? '—') ?></td>
      <td class="num">₹<?= money($p['amount']) ?></td>
      <td><?= e($p['notes']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="payModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:420px;width:100%">
    <h3 style="margin-bottom:16px">Record Payment</h3>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group"><label>Party</label>
        <select class="form-control" name="party_id">
          <option value="">-- General / Not linked --</option>
          <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Direction</label>
          <select class="form-control" name="direction">
            <option value="in">Received (In)</option><option value="out">Paid Out</option>
          </select>
        </div>
        <div class="form-group"><label>Mode</label>
          <select class="form-control" name="mode">
            <option value="cash">Cash</option><option value="bank">Bank</option><option value="upi">UPI</option><option value="cheque">Cheque</option><option value="card">Card</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Amount</label><input class="form-control" type="number" step="0.01" name="amount" required></div>
        <div class="form-group"><label>Date</label><input class="form-control" type="date" name="payment_date" value="<?= today() ?>"></div>
      </div>
      <div class="form-group"><label>Notes</label><input class="form-control" name="notes"></div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('payModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
