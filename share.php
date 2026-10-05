<?php
/**
 * Public share link - NO LOGIN REQUIRED BY DESIGN.
 *
 * This is what customers see when you send them an invoice via WhatsApp,
 * SMS or email. Access is controlled entirely by possession of the random
 * `token` in the URL (not by session/login), so this file must never
 * accept a plain numeric ID or tenant_id from the request - only the token.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$token = $_GET['token'] ?? '';
$format = ($_GET['format'] ?? 'a4') === 'thermal' ? 'thermal' : 'a4';

if ($token === '') {
    http_response_code(404);
    die('Document not found.');
}

$stmt = $db->prepare("SELECT i.*, p.name AS party_name, p.phone AS party_phone, p.email AS party_email, p.gstin AS party_gstin,
                       p.billing_address AS party_address
                       FROM invoices i LEFT JOIN parties p ON p.id = i.party_id
                       WHERE i.share_token = ? AND i.status != 'draft' AND i.status != 'cancelled'
                       LIMIT 1");
$stmt->execute([$token]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    die('This document is not available. The link may be incorrect, or the document may have been cancelled.');
}

$tid = (int)$invoice['tenant_id'];

$itemsStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order");
$itemsStmt->execute([$invoice['id']]);
$items = $itemsStmt->fetchAll();

$tenantStmt = $db->prepare("SELECT t.*, s.bank_name, s.bank_account_no, s.bank_ifsc, s.upi_id, s.terms_conditions, s.invoice_footer
                             FROM tenants t LEFT JOIN tenant_settings s ON s.tenant_id = t.id WHERE t.id = ?");
$tenantStmt->execute([$tid]);
$company = $tenantStmt->fetch();

$typeLabels = ['invoice'=>'TAX INVOICE','proforma'=>'PROFORMA INVOICE','quotation'=>'QUOTATION','purchase'=>'PURCHASE BILL'];
$hasIgst = array_sum(array_column($items, 'igst_amount')) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($invoice['doc_no']) ?> — <?= e($company['company_name']) ?></title>
<style>
  body{font-family:'Helvetica Neue',Arial,sans-serif;color:#16213a;margin:0;background:#f2efe6}
  .bar{background:#16213a;color:#fff;padding:12px 16px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .bar a,.bar button{padding:9px 18px;border-radius:6px;border:0;background:#c8862b;color:#fff;font-weight:700;cursor:pointer;text-decoration:none;font-size:.88rem}
  .bar a.secondary{background:transparent;border:1px solid #4a5470;color:#c9d2e8}
  @media print { .bar{display:none} }
</style>
</head>
<body>
<div class="bar">
  <a href="?token=<?= e($token) ?>&format=a4">A4 View</a>
  <a href="?token=<?= e($token) ?>&format=thermal" class="secondary">Receipt View</a>
  <button onclick="window.print()">Print / Save as PDF</button>
</div>

<?php if ($format === 'thermal'): ?>
<style>
  @page { size: 80mm auto; margin: 0; }
  .receipt{width:80mm;margin:16px auto;font-family:'Courier New',monospace;font-size:12px;color:#000;padding:6px;background:#fff}
  .center{text-align:center}
  .bold{font-weight:700}
  .dashed{border-top:1px dashed #000;margin:6px 0}
  .receipt table{width:100%;border-collapse:collapse;font-size:11px}
  .receipt td{padding:2px 0;vertical-align:top}
  .r{text-align:right}
  .co-name{font-size:15px;font-weight:700}
  .grand{font-size:14px;font-weight:700}
  @media print{ body{background:#fff} .receipt{margin:0} }
</style>
<div class="receipt">
  <div class="center co-name"><?= e($company['company_name']) ?></div>
  <div class="center"><?= e($company['address']) ?></div>
  <?php if ($company['phone']): ?><div class="center">Ph: <?= e($company['phone']) ?></div><?php endif; ?>
  <?php if ($company['gstin']): ?><div class="center">GSTIN: <?= e($company['gstin']) ?></div><?php endif; ?>
  <div class="dashed"></div>
  <div class="center bold"><?= e($typeLabels[$invoice['doc_type']]) ?></div>
  <table>
    <tr><td>No:</td><td class="r"><?= e($invoice['doc_no']) ?></td></tr>
    <tr><td>Date:</td><td class="r"><?= date('d-m-Y', strtotime($invoice['doc_date'])) ?></td></tr>
    <?php if ($invoice['party_name']): ?><tr><td>Party:</td><td class="r"><?= e($invoice['party_name']) ?></td></tr><?php endif; ?>
  </table>
  <div class="dashed"></div>
  <table>
    <?php foreach ($items as $it): ?>
    <tr><td colspan="2"><?= e($it['description']) ?></td></tr>
    <tr><td><?= rtrim(rtrim(number_format($it['qty'],3),'0'),'.') ?> x <?= money($it['rate']) ?></td><td class="r"><?= money($it['total']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="dashed"></div>
  <table>
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
</div>

<?php else: ?>
<style>
  .sheet{max-width:800px;margin:20px auto;padding:24px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.1)}
  .doc-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #16213a;padding-bottom:14px;margin-bottom:14px}
  .doc-head h1{font-size:20px;margin:0 0 4px;letter-spacing:.05em}
  .doc-head .co{font-size:17px;font-weight:700}
  .doc-head .doc-meta{text-align:right;font-size:12px}
  .doc-head .doc-meta .no{font-size:15px;font-weight:700;margin-bottom:2px}
  .parties{display:flex;justify-content:space-between;gap:20px;margin-bottom:18px;font-size:12.5px}
  .parties .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#7d8bb0;margin-bottom:4px;font-weight:700}
  table.items{width:100%;border-collapse:collapse;margin-bottom:16px}
  table.items th{background:#16213a;color:#fff;font-size:10.5px;text-transform:uppercase;padding:8px 6px;text-align:left}
  table.items td{padding:7px 6px;border-bottom:1px solid #e2ddd0;font-size:12px}
  table.items th.num,table.items td.num{text-align:right}
  .totals{width:280px;margin-left:auto;font-size:12.5px}
  .totals .row{display:flex;justify-content:space-between;padding:5px 0}
  .totals .row.grand{font-weight:700;font-size:15px;border-top:2px solid #16213a;padding-top:8px;margin-top:4px}
  .footer-info{display:flex;justify-content:space-between;margin-top:26px;font-size:11.5px;gap:20px}
  .footer-info .lbl{font-size:10px;text-transform:uppercase;color:#7d8bb0;font-weight:700;margin-bottom:4px}
  @media print { .sheet{box-shadow:none;margin:0;max-width:none} }
</style>
<div class="sheet">
  <div class="doc-head">
    <div>
      <div class="co"><?= e($company['company_name']) ?></div>
      <div style="font-size:11.5px;line-height:1.5;margin-top:4px;max-width:280px">
        <?= e($company['address']) ?><?= $company['city'] ? ', '.e($company['city']) : '' ?><?= $company['state'] ? ', '.e($company['state']) : '' ?> <?= e($company['pincode']) ?><br>
        <?php if ($company['gstin']): ?>GSTIN: <?= e($company['gstin']) ?><br><?php endif; ?>
        Ph: <?= e($company['phone']) ?> <?= $company['email'] ? '· '.e($company['email']) : '' ?>
      </div>
    </div>
    <div class="doc-meta">
      <h1><?= e($typeLabels[$invoice['doc_type']]) ?></h1>
      <div class="no"><?= e($invoice['doc_no']) ?></div>
      <div>Date: <?= date('d-m-Y', strtotime($invoice['doc_date'])) ?></div>
      <?php if ($invoice['due_date']): ?><div>Due: <?= date('d-m-Y', strtotime($invoice['due_date'])) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="parties">
    <div>
      <div class="lbl">Bill To</div>
      <b><?= e($invoice['party_name'] ?? '—') ?></b><br>
      <?= nl2br(e($invoice['party_address'] ?? '')) ?><br>
      <?= e($invoice['party_phone'] ?? '') ?><br>
      <?php if ($invoice['party_gstin']): ?>GSTIN: <?= e($invoice['party_gstin']) ?><?php endif; ?>
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
    <div>
      <div class="lbl">Payment Details</div>
      <?php if ($company['bank_name']): ?><?= e($company['bank_name']) ?><br>A/C: <?= e($company['bank_account_no']) ?><br>IFSC: <?= e($company['bank_ifsc']) ?><br><?php endif; ?>
      <?php if ($company['upi_id']): ?>UPI: <?= e($company['upi_id']) ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <div>
      <div class="lbl">Terms &amp; Conditions</div>
      <?= nl2br(e($invoice['terms'] ?: $company['terms_conditions'] ?: '')) ?>
    </div>
  </div>

  <?php if ($company['invoice_footer']): ?>
  <div style="text-align:center;margin-top:26px;font-size:11px;color:#7d8bb0"><?= e($company['invoice_footer']) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
