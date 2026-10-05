<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT i.*, p.name AS party_name, p.phone AS party_phone, p.email AS party_email, p.gstin AS party_gstin,
                       p.billing_address AS party_address, p.state AS party_state
                       FROM invoices i LEFT JOIN parties p ON p.id=i.party_id WHERE i.id=? AND i.tenant_id=?");
$stmt->execute([$id, $tid]);
$invoice = $stmt->fetch();
if (!$invoice) { die('Document not found.'); }
enforce_permission($db, $tid, Auth::user()['role'], $invoice['doc_type'] === 'purchase' ? 'purchases' : 'sales', $invoice['doc_type'] === 'purchase' ? 'Purchase Bills' : 'Sales Documents');

$itemsStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$tenantStmt = $db->prepare("SELECT t.*, s.bank_name, s.bank_account_no, s.bank_ifsc, s.upi_id, s.terms_conditions, s.invoice_footer, s.logo_path
                             FROM tenants t LEFT JOIN tenant_settings s ON s.tenant_id=t.id WHERE t.id=?");
$tenantStmt->execute([$tid]);
$company = $tenantStmt->fetch();

$typeLabels = ['invoice'=>'TAX INVOICE','proforma'=>'PROFORMA INVOICE','quotation'=>'QUOTATION','purchase'=>'PURCHASE BILL','online_order'=>'ONLINE ORDER','delivery_challan'=>'DELIVERY CHALLAN'];
$hasIgst = array_sum(array_column($items, 'igst_amount')) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($invoice['doc_no']) ?> — Print</title>
<style>
  @page { size: A4; margin: 14mm; }
  *{box-sizing:border-box}
  body{font-family:'Helvetica Neue',Arial,sans-serif;color:#16213a;font-size:13px;margin:0;background:#fff}
  .toolbar{padding:12px 16px;background:#16213a;display:flex;gap:10px;justify-content:flex-end}
  .toolbar button{padding:8px 16px;border-radius:6px;border:0;background:#c8862b;color:#fff;font-weight:700;cursor:pointer}
  .sheet{max-width:800px;margin:20px auto;padding:0 10px}
  .doc-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #16213a;padding-bottom:14px;margin-bottom:14px}
  .doc-head h1{font-size:20px;margin:0 0 4px;letter-spacing:.05em}
  .doc-head .co{font-size:17px;font-weight:700}
  .doc-head .doc-meta{text-align:right;font-size:12px}
  .doc-head .doc-meta .no{font-size:15px;font-weight:700;margin-bottom:2px}
  .parties{display:flex;justify-content:space-between;gap:20px;margin-bottom:18px}
  .parties .box{flex:1;font-size:12.5px;line-height:1.5}
  .parties .box .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#7d8bb0;margin-bottom:4px;font-weight:700}
  table.items{width:100%;border-collapse:collapse;margin-bottom:16px}
  table.items th{background:#16213a;color:#fff;font-size:10.5px;text-transform:uppercase;padding:8px 6px;text-align:left}
  table.items td{padding:7px 6px;border-bottom:1px solid #e2ddd0;font-size:12px}
  table.items th.num,table.items td.num{text-align:right}
  .totals{width:280px;margin-left:auto;font-size:12.5px}
  .totals .row{display:flex;justify-content:space-between;padding:5px 0}
  .totals .row.grand{font-weight:700;font-size:15px;border-top:2px solid #16213a;padding-top:8px;margin-top:4px}
  .footer-info{display:flex;justify-content:space-between;margin-top:26px;font-size:11.5px;gap:20px}
  .footer-info .box{flex:1}
  .footer-info .lbl{font-size:10px;text-transform:uppercase;color:#7d8bb0;font-weight:700;margin-bottom:4px}
  .sign{margin-top:50px;text-align:right;font-size:12px}
  .sign .line{border-top:1px solid #16213a;width:180px;margin-left:auto;padding-top:6px}
  .status-stamp{display:inline-block;border:2px solid #2f7d5e;color:#2f7d5e;font-weight:700;padding:4px 12px;border-radius:6px;transform:rotate(-6deg);font-size:12px}
  @media print { .toolbar{display:none} .sheet{margin:0;max-width:none} }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">🖨️ Print</button></div>
<div class="sheet">
  <div class="doc-head">
    <div>
      <div class="co"><?= e($company['company_name']) ?></div>
      <div style="font-size:11.5px;line-height:1.5;margin-top:4px;max-width:280px">
        <?= e($company['address']) ?><?= $company['city'] ? ', '.e($company['city']) : '' ?><?= $company['state'] ? ', '.e($company['state']) : '' ?> <?= e($company['pincode']) ?><br>
        <?php if($company['gstin']): ?>GSTIN: <?= e($company['gstin']) ?><br><?php endif; ?>
        Ph: <?= e($company['phone']) ?> <?= $company['email'] ? '· '.e($company['email']) : '' ?>
      </div>
    </div>
    <div class="doc-meta">
      <h1><?= e($typeLabels[$invoice['doc_type']]) ?></h1>
      <div class="no"><?= e($invoice['doc_no']) ?></div>
      <div>Date: <?= date('d-m-Y', strtotime($invoice['doc_date'])) ?></div>
      <?php if ($invoice['due_date']): ?><div>Due: <?= date('d-m-Y', strtotime($invoice['due_date'])) ?></div><?php endif; ?>
      <?php if ($invoice['status']==='paid'): ?><div style="margin-top:8px"><span class="status-stamp">PAID</span></div><?php endif; ?>
    </div>
  </div>

  <div class="parties">
    <div class="box">
      <div class="lbl"><?= $invoice['doc_type']==='purchase' ? 'Supplier' : 'Bill To' ?></div>
      <b><?= e($invoice['party_name'] ?? '—') ?></b><br>
      <?= nl2br(e($invoice['party_address'] ?? '')) ?><br>
      <?= e($invoice['party_phone'] ?? '') ?><br>
      <?php if($invoice['party_gstin']): ?>GSTIN: <?= e($invoice['party_gstin']) ?><?php endif; ?>
    </div>
    <div class="box" style="text-align:right">
      <div class="lbl">Place of Supply</div>
      <?= e($invoice['place_of_supply'] ?? '—') ?>
      <?php if ($invoice['vehicle_no'] || $invoice['transport_mode'] || $invoice['eway_bill_no']): ?>
      <div class="lbl" style="margin-top:10px">Transport / E-Way Bill</div>
      <?php if ($invoice['vehicle_no']): ?>Vehicle: <?= e($invoice['vehicle_no']) ?><br><?php endif; ?>
      <?php if ($invoice['transport_mode']): ?>Mode: <?= e(ucfirst($invoice['transport_mode'])) ?><br><?php endif; ?>
      <?php if ($invoice['eway_bill_no']): ?>E-Way Bill No: <?= e($invoice['eway_bill_no']) ?><br><span style="font-size:9px;color:#7d8bb0">(self-declared, not government-verified)</span><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th>#</th><th>Item</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Rate</th>
        <?php if ($hasIgst): ?><th class="num">IGST</th><?php else: ?><th class="num">CGST</th><th class="num">SGST</th><?php endif; ?>
        <th class="num">Amount</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $i => $it): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td><?= e($it['description']) ?></td>
        <td><?= e($it['hsn_sac']) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format($it['qty'],3),'0'),'.') ?> <?= e($it['unit']) ?></td>
        <td class="num"><?= money($it['rate']) ?></td>
        <?php if ($hasIgst): ?>
          <td class="num"><?= money($it['igst_amount']) ?></td>
        <?php else: ?>
          <td class="num"><?= money($it['cgst_amount']) ?></td>
          <td class="num"><?= money($it['sgst_amount']) ?></td>
        <?php endif; ?>
        <td class="num"><?= money($it['total']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals">
    <div class="row"><span>Subtotal</span><span><?= money($invoice['subtotal']) ?></span></div>
    <div class="row"><span>Discount</span><span>-<?= money($invoice['discount_amount']) ?></span></div>
    <div class="row"><span>Total Tax</span><span><?= money($invoice['tax_amount']) ?></span></div>
    <div class="row"><span>Round Off</span><span><?= money($invoice['round_off']) ?></span></div>
    <div class="row grand"><span>Total Payable</span><span>₹<?= money($invoice['total_amount']) ?></span></div>
  </div>

  <?php if ($invoice['notes']): ?>
  <div style="margin-bottom:16px;font-size:12px"><b>Notes:</b> <?= nl2br(e($invoice['notes'])) ?></div>
  <?php endif; ?>

  <div class="footer-info">
    <?php if ($company['bank_name'] || $company['upi_id']): ?>
    <div class="box">
      <div class="lbl">Payment Details</div>
      <?php if ($company['bank_name']): ?><?= e($company['bank_name']) ?><br>A/C: <?= e($company['bank_account_no']) ?><br>IFSC: <?= e($company['bank_ifsc']) ?><br><?php endif; ?>
      <?php if ($company['upi_id']): ?>UPI: <?= e($company['upi_id']) ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="box">
      <div class="lbl">Terms &amp; Conditions</div>
      <?= nl2br(e($invoice['terms'] ?: $company['terms_conditions'] ?: '')) ?>
    </div>
  </div>

  <div class="sign">
    <div class="line">Authorised Signatory — <?= e($company['company_name']) ?></div>
  </div>

  <?php if ($company['invoice_footer']): ?>
  <div style="text-align:center;margin-top:26px;font-size:11px;color:#7d8bb0"><?= e($company['invoice_footer']) ?></div>
  <?php endif; ?>
</div>
</body>
</html>
