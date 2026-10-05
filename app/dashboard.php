<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'dashboard', 'Dashboard');
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$tid = Auth::tenantId();

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$today = today();

$salesStmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) t FROM invoices WHERE tenant_id=? AND doc_type='invoice' AND status!='cancelled' AND doc_date BETWEEN ? AND ?");
$salesStmt->execute([$tid, $monthStart, $monthEnd]);
$salesThisMonth = (float)$salesStmt->fetch()['t'];

$dueStmt = $db->prepare("SELECT COALESCE(SUM(total_amount - paid_amount),0) t FROM invoices WHERE tenant_id=? AND doc_type='invoice' AND status IN ('unpaid','partial')");
$dueStmt->execute([$tid]);
$outstanding = (float)$dueStmt->fetch()['t'];

$invCountStmt = $db->prepare("SELECT COUNT(*) c FROM invoices WHERE tenant_id=? AND doc_type='invoice' AND doc_date BETWEEN ? AND ?");
$invCountStmt->execute([$tid, $monthStart, $monthEnd]);
$invCount = (int)$invCountStmt->fetch()['c'];

$lowStockStmt = $db->prepare("SELECT COUNT(*) c FROM products WHERE tenant_id=? AND type='product' AND is_active=1 AND current_stock <= low_stock_alert");
$lowStockStmt->execute([$tid]);
$lowStockCount = (int)$lowStockStmt->fetch()['c'];

$recentStmt = $db->prepare("SELECT i.*, p.name AS party_name FROM invoices i LEFT JOIN parties p ON p.id=i.party_id
                             WHERE i.tenant_id=? AND i.doc_type='invoice' ORDER BY i.id DESC LIMIT 8");
$recentStmt->execute([$tid]);
$recent = $recentStmt->fetchAll();

$lowStockListStmt = $db->prepare("SELECT name, current_stock, unit, low_stock_alert FROM products WHERE tenant_id=? AND type='product' AND is_active=1 AND current_stock <= low_stock_alert ORDER BY current_stock ASC LIMIT 6");
$lowStockListStmt->execute([$tid]);
$lowStockList = $lowStockListStmt->fetchAll();

// ---- Analytics: last 30 days sales trend ----
$trendStart = date('Y-m-d', strtotime('-29 days'));
$trendStmt = $db->prepare("SELECT doc_date, SUM(total_amount) t FROM invoices
                            WHERE tenant_id=? AND doc_type='invoice' AND status != 'cancelled' AND doc_date BETWEEN ? AND ?
                            GROUP BY doc_date");
$trendStmt->execute([$tid, $trendStart, $today]);
$trendByDate = [];
foreach ($trendStmt->fetchAll() as $row) { $trendByDate[$row['doc_date']] = (float)$row['t']; }
$trend = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trend[] = ['date' => $d, 'amount' => $trendByDate[$d] ?? 0];
}
$trendMax = max(array_column($trend, 'amount')) ?: 1;

// ---- Analytics: top products by revenue (last 30 days) ----
$topProductsStmt = $db->prepare("SELECT ii.description, SUM(ii.total) revenue, SUM(ii.qty) qty FROM invoice_items ii
                                  JOIN invoices i ON i.id = ii.invoice_id
                                  WHERE i.tenant_id=? AND i.doc_type='invoice' AND i.status != 'cancelled' AND i.doc_date BETWEEN ? AND ?
                                  GROUP BY ii.description ORDER BY revenue DESC LIMIT 5");
$topProductsStmt->execute([$tid, $trendStart, $today]);
$topProducts = $topProductsStmt->fetchAll();

// ---- Analytics: top customers by revenue (last 30 days) ----
$topCustomersStmt = $db->prepare("SELECT p.name, SUM(i.total_amount) revenue FROM invoices i
                                   JOIN parties p ON p.id = i.party_id
                                   WHERE i.tenant_id=? AND i.doc_type='invoice' AND i.status != 'cancelled' AND i.doc_date BETWEEN ? AND ?
                                   GROUP BY i.party_id ORDER BY revenue DESC LIMIT 5");
$topCustomersStmt->execute([$tid, $trendStart, $today]);
$topCustomers = $topCustomersStmt->fetchAll();

// ---- Analytics: month-over-month ----
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));
$lastMonthStmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) t FROM invoices WHERE tenant_id=? AND doc_type='invoice' AND status != 'cancelled' AND doc_date BETWEEN ? AND ?");
$lastMonthStmt->execute([$tid, $lastMonthStart, $lastMonthEnd]);
$salesLastMonth = (float)$lastMonthStmt->fetch()['t'];
$momChange = $salesLastMonth > 0 ? (($salesThisMonth - $salesLastMonth) / $salesLastMonth) * 100 : ($salesThisMonth > 0 ? 100 : 0);

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <div class="sub"><?= date('F Y') ?> overview</div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="<?= base_url('app/pos.php') ?>" class="btn btn-outline">🖥️ POS Billing</a>
    <a href="<?= base_url('app/invoice_form.php?type=invoice') ?>" class="btn btn-primary">+ New Invoice</a>
  </div>
</div>

<div class="grid grid-4" style="margin-bottom:22px">
  <div class="card stat accent"><div class="label">Sales This Month</div><div class="value money"><?= money($salesThisMonth) ?></div></div>
  <div class="card stat"><div class="label">Outstanding Dues</div><div class="value money"><?= money($outstanding) ?></div></div>
  <div class="card stat"><div class="label">Invoices This Month</div><div class="value"><?= $invCount ?></div></div>
  <div class="card stat"><div class="label">Low Stock Items</div><div class="value" style="color:<?= $lowStockCount ? '#b5443a' : 'inherit' ?>"><?= $lowStockCount ?></div></div>
</div>

<div class="card card-pad" style="margin-bottom:22px">
  <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:14px;flex-wrap:wrap;gap:8px">
    <h3>Sales Trend — Last 30 Days</h3>
    <div style="font-size:.85rem;color:<?= $momChange >= 0 ? '#2f7d5e' : '#b5443a' ?>">
      <?= $momChange >= 0 ? '▲' : '▼' ?> <?= number_format(abs($momChange), 1) ?>% vs last month (₹<?= money($salesLastMonth) ?>)
    </div>
  </div>
  <div style="display:flex;align-items:flex-end;gap:2px;height:100px;overflow-x:auto;padding-bottom:4px">
    <?php foreach ($trend as $t): ?>
      <div title="<?= date('d M', strtotime($t['date'])) ?>: ₹<?= money($t['amount']) ?>"
           style="flex:1;min-width:8px;background:<?= $t['amount'] > 0 ? '#c8862b' : '#f0ece0' ?>;height:<?= max(3, round($t['amount'] / $trendMax * 100)) ?>%;border-radius:2px 2px 0 0"></div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:.72rem;color:#8a93ab;margin-top:6px">
    <span><?= date('d M', strtotime($trendStart)) ?></span>
    <span>Today</span>
  </div>
</div>

<div class="grid grid-2" style="margin-bottom:22px">
  <div class="card card-pad">
    <h3 style="margin-bottom:14px">Top Products <span class="hint">(last 30 days)</span></h3>
    <?php if (!$topProducts): ?>
      <div class="empty-state"><h3>No sales yet</h3></div>
    <?php else: ?>
      <table class="data">
        <thead><tr><th>Product</th><th class="num">Qty Sold</th><th class="num">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($topProducts as $p): ?>
          <tr><td><?= e($p['description']) ?></td><td class="num"><?= rtrim(rtrim(number_format($p['qty'],2),'0'),'.') ?></td><td class="num">₹<?= money($p['revenue']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <div class="card card-pad">
    <h3 style="margin-bottom:14px">Top Customers <span class="hint">(last 30 days)</span></h3>
    <?php if (!$topCustomers): ?>
      <div class="empty-state"><h3>No sales yet</h3></div>
    <?php else: ?>
      <table class="data">
        <thead><tr><th>Customer</th><th class="num">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($topCustomers as $c): ?>
          <tr><td><?= e($c['name']) ?></td><td class="num">₹<?= money($c['revenue']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-2">
  <div class="card card-pad">
    <h3 style="margin-bottom:14px">Recent Invoices</h3>
    <?php if (!$recent): ?>
      <div class="empty-state"><h3>No invoices yet</h3><p>Create your first invoice to see it here.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Doc No</th><th>Party</th><th>Date</th><th class="num">Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr onclick="location='<?= base_url('app/invoice_view.php?id=' . $r['id']) ?>'" style="cursor:pointer">
            <td><?= e($r['doc_no']) ?></td>
            <td><?= e($r['party_name'] ?? '—') ?></td>
            <td><?= date('d M Y', strtotime($r['doc_date'])) ?></td>
            <td class="num">₹<?= money($r['total_amount']) ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card card-pad">
    <h3 style="margin-bottom:14px">Low Stock Alerts</h3>
    <?php if (!$lowStockList): ?>
      <div class="empty-state"><h3>All good</h3><p>No items are below their reorder level.</p></div>
    <?php else: ?>
      <table class="data">
        <thead><tr><th>Product</th><th class="num">Stock</th><th class="num">Alert At</th></tr></thead>
        <tbody>
        <?php foreach ($lowStockList as $p): ?>
          <tr><td><?= e($p['name']) ?></td><td class="num" style="color:#b5443a"><?= rtrim(rtrim(number_format($p['current_stock'],2),'0'),'.') ?> <?= e($p['unit']) ?></td><td class="num"><?= rtrim(rtrim(number_format($p['low_stock_alert'],2),'0'),'.') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <a href="<?= base_url('app/reorder.php') ?>" class="btn btn-outline btn-sm" style="margin-top:14px">View Reorder Suggestions</a>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
