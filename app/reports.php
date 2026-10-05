<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'reports', 'Reports');
$tid = Auth::tenantId();
$pageTitle = 'Reports';
$activeNav = 'reports';

/** Revenue (taxable value), COGS, expenses, and profit for a date range. */
function calc_pnl(PDO $db, int $tid, string $from, string $to): array
{
    $revStmt = $db->prepare("SELECT COALESCE(SUM(subtotal - discount_amount), 0) v FROM invoices
                              WHERE tenant_id=? AND doc_type='invoice' AND status != 'cancelled' AND doc_date BETWEEN ? AND ?");
    $revStmt->execute([$tid, $from, $to]);
    $revenue = (float)$revStmt->fetch()['v'];

    $cogsStmt = $db->prepare("SELECT COALESCE(SUM(ii.qty * ii.cost_price), 0) v FROM invoice_items ii
                               JOIN invoices i ON i.id = ii.invoice_id
                               WHERE i.tenant_id=? AND i.doc_type='invoice' AND i.status != 'cancelled' AND i.doc_date BETWEEN ? AND ?");
    $cogsStmt->execute([$tid, $from, $to]);
    $cogs = (float)$cogsStmt->fetch()['v'];

    $expStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) v FROM expenses WHERE tenant_id=? AND expense_date BETWEEN ? AND ?");
    $expStmt->execute([$tid, $from, $to]);
    $expenses = (float)$expStmt->fetch()['v'];

    $grossProfit = $revenue - $cogs;
    $netProfit = $grossProfit - $expenses;

    return compact('revenue', 'cogs', 'expenses', 'grossProfit', 'netProfit');
}

$today = today();
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$yearStart = date('Y-01-01');
$yearEnd = date('Y-12-31');

$pnlToday = calc_pnl($db, $tid, $today, $today);
$pnlMonth = calc_pnl($db, $tid, $monthStart, $monthEnd);
$pnlYear = calc_pnl($db, $tid, $yearStart, $yearEnd);

// Custom range
$customFrom = $_GET['from'] ?? $monthStart;
$customTo = $_GET['to'] ?? $today;
$pnlCustom = calc_pnl($db, $tid, $customFrom, $customTo);

// ---- Stock valuation ----
$stockStmt = $db->prepare("SELECT name, unit, current_stock, purchase_price, sale_price, price_type
                            FROM products WHERE tenant_id=? AND type='product' AND is_active=1 AND current_stock > 0
                            ORDER BY (current_stock * purchase_price) DESC");
$stockStmt->execute([$tid]);
$stockRows = $stockStmt->fetchAll();

$totalStockValueCost = 0;
$totalStockValueSale = 0;
foreach ($stockRows as $r) {
    $totalStockValueCost += (float)$r['current_stock'] * (float)$r['purchase_price'];
    $totalStockValueSale += (float)$r['current_stock'] * (float)$r['sale_price'];
}
$potentialMargin = $totalStockValueSale - $totalStockValueCost;
$topStockItems = array_slice($stockRows, 0, 10);

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Reports</h1><div class="sub">Stock valuation and profit &amp; loss</div></div>
</div>

<h3 style="margin-bottom:12px">Inventory Valuation</h3>
<div class="grid grid-3" style="margin-bottom:26px">
  <div class="card stat accent"><div class="label">Stock Value (at cost)</div><div class="value money"><?= money($totalStockValueCost) ?></div></div>
  <div class="card stat"><div class="label">Stock Value (at sale price)</div><div class="value money"><?= money($totalStockValueSale) ?></div></div>
  <div class="card stat"><div class="label">Potential Gross Margin</div><div class="value" style="color:<?= $potentialMargin>=0?'#2f7d5e':'#b5443a' ?>">₹<?= money($potentialMargin) ?></div></div>
</div>

<?php if ($topStockItems): ?>
<div class="card card-pad" style="margin-bottom:30px">
  <h4 style="margin-bottom:10px;font-size:.95rem">Top Items by Stock Value</h4>
  <table class="data">
    <thead><tr><th>Product</th><th class="num">Stock</th><th class="num">Cost Value</th><th class="num">Sale Value</th></tr></thead>
    <tbody>
    <?php foreach ($topStockItems as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format($r['current_stock'],2),'0'),'.') ?> <?= e($r['unit']) ?></td>
        <td class="num">₹<?= money($r['current_stock'] * $r['purchase_price']) ?></td>
        <td class="num">₹<?= money($r['current_stock'] * $r['sale_price']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h3 style="margin-bottom:12px">Profit &amp; Loss</h3>
<div class="grid grid-3" style="margin-bottom:20px">
  <?php foreach (['Today' => $pnlToday, 'This Month' => $pnlMonth, 'This Year' => $pnlYear] as $label => $p): ?>
  <div class="card card-pad">
    <h4 style="margin-bottom:10px;font-size:.95rem"><?= e($label) ?></h4>
    <div style="font-size:.85rem;line-height:1.9">
      <div style="display:flex;justify-content:space-between"><span>Revenue</span><span>₹<?= money($p['revenue']) ?></span></div>
      <div style="display:flex;justify-content:space-between;color:#8a93ab"><span>− Cost of Goods Sold</span><span>₹<?= money($p['cogs']) ?></span></div>
      <div style="display:flex;justify-content:space-between;border-top:1px solid #e2ddd0;padding-top:4px;margin-top:2px"><span>Gross Profit</span><span>₹<?= money($p['grossProfit']) ?></span></div>
      <div style="display:flex;justify-content:space-between;color:#8a93ab"><span>− Expenses</span><span>₹<?= money($p['expenses']) ?></span></div>
      <div style="display:flex;justify-content:space-between;border-top:2px solid #16213a;padding-top:6px;margin-top:4px;font-weight:700;font-size:1.05rem;color:<?= $p['netProfit']>=0?'#2f7d5e':'#b5443a' ?>">
        <span>Net <?= $p['netProfit']>=0?'Profit':'Loss' ?></span><span>₹<?= money(abs($p['netProfit'])) ?></span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card card-pad">
  <h4 style="margin-bottom:12px;font-size:.95rem">Custom Date Range</h4>
  <form method="get" class="form-row" style="margin-bottom:16px">
    <div class="form-group"><label>From</label><input class="form-control" type="date" name="from" value="<?= e($customFrom) ?>"></div>
    <div class="form-group"><label>To</label><input class="form-control" type="date" name="to" value="<?= e($customTo) ?>"></div>
    <div class="form-group" style="align-self:flex-end"><button class="btn btn-outline">Calculate</button></div>
  </form>
  <div class="grid grid-4">
    <div class="stat" style="padding:0"><div class="label">Revenue</div><div class="value" style="font-size:1.2rem">₹<?= money($pnlCustom['revenue']) ?></div></div>
    <div class="stat" style="padding:0"><div class="label">COGS</div><div class="value" style="font-size:1.2rem">₹<?= money($pnlCustom['cogs']) ?></div></div>
    <div class="stat" style="padding:0"><div class="label">Expenses</div><div class="value" style="font-size:1.2rem">₹<?= money($pnlCustom['expenses']) ?></div></div>
    <div class="stat" style="padding:0"><div class="label">Net <?= $pnlCustom['netProfit']>=0?'Profit':'Loss' ?></div><div class="value" style="font-size:1.2rem;color:<?= $pnlCustom['netProfit']>=0?'#2f7d5e':'#b5443a' ?>">₹<?= money(abs($pnlCustom['netProfit'])) ?></div></div>
  </div>
</div>

<p class="hint" style="margin-top:20px">Profit figures use each item's cost price at the time it was sold. Invoices created before this feature was added will show ₹0 cost for those older lines, so historical profit before today may read higher than actual until enough new sales accumulate.</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
