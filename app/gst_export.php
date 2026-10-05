<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'gst_export', 'GST Export');
$tid = Auth::tenantId();
$pageTitle = 'GST Export';
$activeNav = 'gst';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');

$stmt = $db->prepare("SELECT i.*, p.name AS party_name, p.gstin AS party_gstin, p.state AS party_state
                       FROM invoices i LEFT JOIN parties p ON p.id=i.party_id
                       WHERE i.tenant_id=? AND i.doc_type='invoice' AND i.status!='cancelled' AND i.doc_date BETWEEN ? AND ?
                       ORDER BY i.doc_date, i.id");
$stmt->execute([$tid, $from, $to]);
$invoices = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'json') {
    $tenantStmt = $db->prepare("SELECT company_name, gstin FROM tenants WHERE id=?");
    $tenantStmt->execute([$tid]);
    $company = $tenantStmt->fetch();

    $data = [
        'export_type' => 'ConrQ GST Sales Summary',
        'company_name' => $company['company_name'],
        'gstin' => $company['gstin'],
        'period' => ['from' => $from, 'to' => $to],
        'generated_at' => date('c'),
        'invoices' => [],
    ];
    foreach ($invoices as $inv) {
        $itStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=?");
        $itStmt->execute([$inv['id']]);
        $data['invoices'][] = [
            'invoice_no' => $inv['doc_no'],
            'invoice_date' => $inv['doc_date'],
            'customer_name' => $inv['party_name'],
            'customer_gstin' => $inv['party_gstin'],
            'place_of_supply' => $inv['place_of_supply'],
            'taxable_value' => round((float)$inv['subtotal'] - (float)$inv['discount_amount'], 2),
            'total_tax' => (float)$inv['tax_amount'],
            'invoice_value' => (float)$inv['total_amount'],
            'items' => array_map(function($it){
                return [
                    'description' => $it['description'],
                    'hsn_sac' => $it['hsn_sac'],
                    'qty' => (float)$it['qty'],
                    'rate' => (float)$it['rate'],
                    'taxable_amount' => round(((float)$it['qty']*(float)$it['rate']) - (float)$it['discount_amount'], 2),
                    'tax_rate' => (float)$it['tax_rate'],
                    'cgst' => (float)$it['cgst_amount'],
                    'sgst' => (float)$it['sgst_amount'],
                    'igst' => (float)$it['igst_amount'],
                ];
            }, $itStmt->fetchAll()),
        ];
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="gst-export-' . $from . '_to_' . $to . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

$totalTaxable = array_sum(array_map(fn($i)=> (float)$i['subtotal']-(float)$i['discount_amount'], $invoices));
$totalTax = array_sum(array_column($invoices, 'tax_amount'));
$totalValue = array_sum(array_column($invoices, 'total_amount'));

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>GST Export</h1><div class="sub">Sales summary for return filing, exported as structured JSON</div></div>
</div>

<form method="get" class="form-row" style="margin-bottom:18px">
  <div class="form-group"><label>From</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="form-group"><label>To</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="form-group" style="align-self:flex-end"><button class="btn btn-outline">Apply</button></div>
  <div class="form-group" style="align-self:flex-end"><a class="btn btn-primary" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&export=json">⬇ Download JSON</a></div>
</form>

<div class="grid grid-3" style="margin-bottom:18px">
  <div class="card stat"><div class="label">Taxable Value</div><div class="value money"><?= money($totalTaxable) ?></div></div>
  <div class="card stat"><div class="label">Total GST</div><div class="value money"><?= money($totalTax) ?></div></div>
  <div class="card stat accent"><div class="label">Invoice Value</div><div class="value money"><?= money($totalValue) ?></div></div>
</div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Invoice No</th><th>Date</th><th>Customer</th><th>GSTIN</th><th class="num">Taxable</th><th class="num">Tax</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php if (!$invoices): ?><tr><td colspan="7"><div class="empty-state"><h3>No invoices in this period</h3></div></td></tr><?php endif; ?>
  <?php foreach ($invoices as $inv): ?>
    <tr>
      <td><?= e($inv['doc_no']) ?></td>
      <td><?= date('d M Y', strtotime($inv['doc_date'])) ?></td>
      <td><?= e($inv['party_name'] ?? '—') ?></td>
      <td><?= e($inv['party_gstin'] ?? '—') ?></td>
      <td class="num">₹<?= money($inv['subtotal'] - $inv['discount_amount']) ?></td>
      <td class="num">₹<?= money($inv['tax_amount']) ?></td>
      <td class="num">₹<?= money($inv['total_amount']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="hint" style="margin-top:14px">This export is a structured summary for your accountant / GST software — it is not the official GSTN offline-tool JSON schema.</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
