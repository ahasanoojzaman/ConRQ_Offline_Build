<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'payments', 'Due Payment Reminders');
$tid = Auth::tenantId();
$pageTitle = 'Due Payment Reminders';
$activeNav = 'due_reminders';

$stmt = $db->prepare("SELECT p.id AS party_id, p.name, p.phone, p.email,
                              SUM(i.total_amount - i.paid_amount) total_due,
                              COUNT(*) invoice_count,
                              MIN(i.doc_date) oldest_due_date,
                              GROUP_CONCAT(i.doc_no ORDER BY i.doc_date SEPARATOR ', ') doc_numbers
                       FROM invoices i JOIN parties p ON p.id = i.party_id
                       WHERE i.tenant_id=? AND i.doc_type='invoice' AND i.status IN ('unpaid','partial')
                       GROUP BY p.id
                       ORDER BY total_due DESC");
$stmt->execute([$tid]);
$dues = $stmt->fetchAll();

$tenantStmt = $db->prepare("SELECT company_name FROM tenants WHERE id=?");
$tenantStmt->execute([$tid]);
$companyName = $tenantStmt->fetch()['company_name'];

$totalOutstanding = array_sum(array_column($dues, 'total_due'));

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Due Payment Reminders</h1><div class="sub"><?= count($dues) ?> customer(s) with outstanding balance · ₹<?= money($totalOutstanding) ?> total</div></div>
</div>

<?php if (!$dues): ?>
  <div class="empty-state"><h3>All caught up</h3><p>No customers currently have an outstanding balance.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
  <thead><tr><th>Customer</th><th>Invoices</th><th>Oldest Due</th><th class="num">Amount Due</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($dues as $d):
    $daysOld = (int)((strtotime(today()) - strtotime($d['oldest_due_date'])) / 86400);
    $msg = "Hi {$d['name']}, this is a reminder that ₹" . money($d['total_due']) . " is outstanding on invoice(s) {$d['doc_numbers']} with $companyName. Kindly arrange payment at your earliest convenience. Thank you!";
    $waNumber = preg_replace('/\D/', '', $d['phone'] ?? '');
  ?>
    <tr>
      <td><a href="<?= base_url('app/ledger.php?party_id=' . $d['party_id']) ?>" style="color:#c8862b;font-weight:600"><?= e($d['name']) ?></a></td>
      <td><?= e($d['doc_numbers']) ?></td>
      <td><?= date('d M Y', strtotime($d['oldest_due_date'])) ?> <span class="hint">(<?= $daysOld ?>d ago)</span></td>
      <td class="num" style="color:#b5443a;font-weight:700">₹<?= money($d['total_due']) ?></td>
      <td style="white-space:nowrap">
        <?php if ($waNumber): ?><a href="https://wa.me/91<?= e($waNumber) ?>?text=<?= rawurlencode($msg) ?>" target="_blank" class="btn btn-outline btn-sm">💬 WhatsApp</a><?php endif; ?>
        <?php if ($d['email']): ?><a href="mailto:<?= e($d['email']) ?>?subject=<?= rawurlencode('Payment Reminder - ' . $companyName) ?>&body=<?= rawurlencode($msg) ?>" class="btn btn-outline btn-sm">✉️ Email</a><?php endif; ?>
        <?php if (!$waNumber && !$d['email']): ?><span class="hint">No contact info</span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
