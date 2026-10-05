<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT i.*, p.name AS party_name, p.phone AS party_phone
                       FROM invoices i LEFT JOIN parties p ON p.id=i.party_id WHERE i.id=? AND i.tenant_id=?");
$stmt->execute([$id, $tid]);
$invoice = $stmt->fetch();
if (!$invoice) { die('Document not found.'); }
enforce_permission($db, $tid, Auth::user()['role'], $invoice['doc_type'] === 'purchase' ? 'purchases' : 'sales', $invoice['doc_type'] === 'purchase' ? 'Purchase Bills' : 'Sales Documents');

$itemsStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$tenantStmt = $db->prepare("SELECT t.company_name, t.phone, t.gstin, t.address, s.invoice_footer
                             FROM tenants t LEFT JOIN tenant_settings s ON s.tenant_id=t.id WHERE t.id=?");
$tenantStmt->execute([$tid]);
$company = $tenantStmt->fetch();

$typeLabels = ['invoice'=>'INVOICE','proforma'=>'PROFORMA','quotation'=>'QUOTATION','purchase'=>'PURCHASE','online_order'=>'ONLINE ORDER','delivery_challan'=>'DELIVERY CHALLAN'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($invoice['doc_no']) ?> — Thermal Print</title>
<style>
  @page { size: 80mm auto; margin: 0; }
  *{box-sizing:border-box}
  body{width:80mm;margin:0 auto;font-family:'Courier New',monospace;font-size:12px;color:#000;padding:6px}
  .toolbar{padding:10px;text-align:center;background:#16213a}
  .toolbar button{padding:8px 20px;border-radius:6px;border:0;background:#c8862b;color:#fff;font-weight:700}
  .center{text-align:center}
  .bold{font-weight:700}
  .dashed{border-top:1px dashed #000;margin:6px 0}
  table{width:100%;border-collapse:collapse;font-size:11px}
  td{padding:2px 0;vertical-align:top}
  .r{text-align:right}
  .co-name{font-size:15px;font-weight:700}
  .totals td{padding:3px 0}
  .grand{font-size:14px;font-weight:700}
  @media print{ .toolbar{display:none} body{padding:0} }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">🖨️ Print Receipt</button></div>

<div class="center co-name"><?= e($company['company_name']) ?></div>
<div class="center"><?= e($company['address']) ?></div>
<?php if ($company['phone']): ?><div class="center">Ph: <?= e($company['phone']) ?></div><?php endif; ?>
<?php if ($company['gstin']): ?><div class="center">GSTIN: <?= e($company['gstin']) ?></div><?php endif; ?>
<div class="dashed"></div>
<div class="center bold"><?= e($typeLabels[$invoice['doc_type']]) ?></div>
<table>
  <tr><td>No:</td><td class="r"><?= e($invoice['doc_no']) ?></td></tr>
  <tr><td>Date:</td><td class="r"><?= date('d-m-Y H:i', strtotime($invoice['doc_date'])) ?></td></tr>
  <?php if ($invoice['party_name']): ?><tr><td>Party:</td><td class="r"><?= e($invoice['party_name']) ?></td></tr><?php endif; ?>
</table>
<div class="dashed"></div>
<table>
  <?php foreach ($items as $it): ?>
  <tr><td colspan="2"><?= e($it['description']) ?></td></tr>
  <tr>
    <td><?= rtrim(rtrim(number_format($it['qty'],3),'0'),'.') ?> x <?= money($it['rate']) ?></td>
    <td class="r"><?= money($it['total']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<div class="dashed"></div>
<table class="totals">
  <tr><td>Subtotal</td><td class="r"><?= money($invoice['subtotal']) ?></td></tr>
  <tr><td>Discount</td><td class="r">-<?= money($invoice['discount_amount']) ?></td></tr>
  <tr><td>Tax</td><td class="r"><?= money($invoice['tax_amount']) ?></td></tr>
  <tr class="grand"><td>TOTAL</td><td class="r">₹<?= money($invoice['total_amount']) ?></td></tr>
</table>
<div class="dashed"></div>
<?php if ($invoice['notes']): ?>
<div><?= nl2br(e($invoice['notes'])) ?></div>
<div class="dashed"></div>
<?php endif; ?>
<div class="center"><?= e($company['invoice_footer'] ?: 'Thank you for your business!') ?></div>
<div style="height:10px"></div>
</body>
</html>
