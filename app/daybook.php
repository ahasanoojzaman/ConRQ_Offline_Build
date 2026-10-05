<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'daybook', 'Daybook');
$tid = Auth::tenantId();
$pageTitle = 'Daybook';
$activeNav = 'daybook';

$date = $_GET['date'] ?? today();
// basic sanity check on the date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = today();
}

$typeLabels = ['invoice'=>'Invoice','proforma'=>'Proforma','quotation'=>'Quotation','purchase'=>'Purchase Bill','credit_note'=>'Credit Note','debit_note'=>'Debit Note','online_order'=>'Online Order','delivery_challan'=>'Delivery Challan'];

// ---- Documents (invoices/purchases/quotations/proforma) dated today ----
$docStmt = $db->prepare("SELECT i.*, p.name AS party_name FROM invoices i LEFT JOIN parties p ON p.id = i.party_id
                          WHERE i.tenant_id=? AND i.doc_date=? ORDER BY i.created_at");
$docStmt->execute([$tid, $date]);
$docs = $docStmt->fetchAll();

// ---- Payments dated today ----
$payStmt = $db->prepare("SELECT pm.*, pt.name AS party_name, inv.doc_no FROM payments pm
                          LEFT JOIN parties pt ON pt.id = pm.party_id
                          LEFT JOIN invoices inv ON inv.id = pm.invoice_id
                          WHERE pm.tenant_id=? AND pm.payment_date=? ORDER BY pm.created_at");
$payStmt->execute([$tid, $date]);
$payments = $payStmt->fetchAll();

// ---- Expenses dated today ----
$expStmt = $db->prepare("SELECT * FROM expenses WHERE tenant_id=? AND expense_date=? ORDER BY created_at");
$expStmt->execute([$tid, $date]);
$expenses = $expStmt->fetchAll();

// ---- Build a single chronological timeline ----
$timeline = [];
foreach ($docs as $d) {
    $timeline[] = [
        'time' => $d['created_at'],
        'kind' => $d['doc_type'],
        'label' => $typeLabels[$d['doc_type']] . ' ' . $d['doc_no'],
        'party' => $d['party_name'],
        'amount' => (float)$d['total_amount'],
        'sign' => in_array($d['doc_type'], ['invoice','credit_note']) ? '+' : (in_array($d['doc_type'], ['purchase','debit_note']) ? '-' : '='),
        'status' => $d['status'],
        'link' => base_url('app/invoice_view.php?id=' . $d['id']),
    ];
}
foreach ($payments as $p) {
    $timeline[] = [
        'time' => $p['created_at'],
        'kind' => 'payment',
        'label' => 'Payment ' . ($p['direction'] === 'in' ? 'Received' : 'Paid') . ' (' . ucfirst($p['mode']) . ')' . ($p['doc_no'] ? ' — ' . $p['doc_no'] : ''),
        'party' => $p['party_name'],
        'amount' => (float)$p['amount'],
        'sign' => $p['direction'] === 'in' ? '+' : '-',
        'status' => null,
        'link' => base_url('app/payments.php'),
    ];
}
foreach ($expenses as $e) {
    $timeline[] = [
        'time' => $e['created_at'],
        'kind' => 'expense',
        'label' => 'Expense — ' . $e['category'],
        'party' => $e['notes'],
        'amount' => (float)$e['amount'],
        'sign' => '-',
        'status' => null,
        'link' => base_url('app/expenses.php'),
    ];
}
usort($timeline, fn($a, $b) => strcmp($a['time'], $b['time']));

// ---- Day summary ----
$totalSales = array_sum(array_map(fn($d) => $d['doc_type'] === 'invoice' ? (float)$d['total_amount'] : 0, $docs));
$totalPurchases = array_sum(array_map(fn($d) => $d['doc_type'] === 'purchase' ? (float)$d['total_amount'] : 0, $docs));
$totalReceived = array_sum(array_map(fn($p) => $p['direction'] === 'in' ? (float)$p['amount'] : 0, $payments));
$totalPaidOut = array_sum(array_map(fn($p) => $p['direction'] === 'out' ? (float)$p['amount'] : 0, $payments));
$totalExpenses = array_sum(array_column($expenses, 'amount'));
$netCashFlow = $totalReceived - $totalPaidOut - $totalExpenses;

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Daybook</h1><div class="sub">Everything that happened on this date, in one place</div></div>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <a href="?date=<?= date('Y-m-d', strtotime($date . ' -1 day')) ?>" class="btn btn-outline btn-sm">← Prev</a>
    <input class="form-control" type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()">
    <a href="?date=<?= date('Y-m-d', strtotime($date . ' +1 day')) ?>" class="btn btn-outline btn-sm">Next →</a>
    <a href="?date=<?= today() ?>" class="btn btn-outline btn-sm">Today</a>
  </form>
</div>

<div class="grid grid-4" style="margin-bottom:18px">
  <div class="card stat accent"><div class="label">Sales</div><div class="value money"><?= money($totalSales) ?></div></div>
  <div class="card stat"><div class="label">Purchases</div><div class="value money"><?= money($totalPurchases) ?></div></div>
  <div class="card stat"><div class="label">Received / Paid Out</div><div class="value" style="font-size:1.2rem">₹<?= money($totalReceived) ?> / ₹<?= money($totalPaidOut) ?></div></div>
  <div class="card stat"><div class="label">Net Cash Flow</div><div class="value" style="color:<?= $netCashFlow >= 0 ? '#2f7d5e' : '#b5443a' ?>">₹<?= money($netCashFlow) ?></div></div>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Time</th><th>Type</th><th>Details</th><th>Party</th><th class="num">Amount</th><th>Status</th></tr></thead>
  <tbody>
  <?php if (!$timeline): ?>
    <tr><td colspan="6"><div class="empty-state"><h3>No activity on this date</h3><p>Invoices, purchases, payments and expenses dated <?= date('d M Y', strtotime($date)) ?> will appear here.</p></div></td></tr>
  <?php endif; ?>
  <?php foreach ($timeline as $t): ?>
    <tr onclick="location='<?= e($t['link']) ?>'" style="cursor:pointer">
      <td><?= date('h:i A', strtotime($t['time'])) ?></td>
      <td><span class="badge badge-draft"><?= e(ucfirst($t['kind'])) ?></span></td>
      <td><?= e($t['label']) ?></td>
      <td><?= e($t['party'] ?? '—') ?></td>
      <td class="num" style="color:<?= $t['sign']==='+' ? '#2f7d5e' : ($t['sign']==='-' ? '#b5443a' : 'inherit') ?>">
        <?= $t['sign'] === '=' ? '' : $t['sign'] ?>₹<?= money($t['amount']) ?>
      </td>
      <td><?php if ($t['status']): ?><span class="badge badge-<?= e($t['status']) ?>"><?= e($t['status']) ?></span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
