<?php
/**
 * Included at top of every /app/*.php page.
 * Expects $db, and that bootstrap.php was already required.
 * Optional: $pageTitle, $activeNav
 */
Auth::requireLogin();
$u = Auth::user();
$tid = Auth::tenantId();
$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';

// Nav links are hidden (not just page-blocked) for roles that cannot see
// them, so staff never hit a confusing dead-end click. Owner/Admin always
// see everything - can() is a fast no-query shortcut for them.
function can($key) {
    global $db, $tid, $u;
    return role_can($db, $tid, $u['role'], $key);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — ConrQ</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<?php require __DIR__ . '/subscription_gate.php'; ?>
<div class="topbar">
  <button class="menu-btn" onclick="document.querySelector('.sidebar').classList.toggle('open');document.querySelector('.overlay').classList.toggle('show')">☰</button>
  <div class="brand">Conr<span style="color:#c8862b">Q</span></div>
  <div class="company-tag"><?= e($u['company']) ?></div>
  <div class="spacer"></div>
  <span style="font-size:.85rem;color:#c9d2e8"><?= e($u['name']) ?> (<?= e(ucfirst(str_replace('_',' ',$u['role']))) ?>)</span>
  <a href="<?= base_url('logout.php') ?>" class="icon-btn" title="Logout">⏻</a>
</div>
<div class="overlay" onclick="this.classList.remove('show');document.querySelector('.sidebar').classList.remove('open')"></div>
<div class="layout">
  <aside class="sidebar">
    <?php if (can('dashboard')): ?><a href="<?= base_url('app/dashboard.php') ?>" class="<?= $activeNav==='dashboard'?'active':'' ?>">📊 Dashboard</a><?php endif; ?>

    <?php if (can('pos') || can('sales')): ?>
    <div class="sec-label">Sales</div>
    <?php if (can('pos')): ?><a href="<?= base_url('app/pos.php') ?>" class="<?= $activeNav==='pos'?'active':'' ?>">🖥️ POS Billing</a><?php endif; ?>
    <?php if (can('sales')): ?>
    <a href="<?= base_url('app/invoices.php?type=invoice') ?>" class="<?= $activeNav==='invoice'?'active':'' ?>">🧾 Invoices</a>
    <a href="<?= base_url('app/invoices.php?type=delivery_challan') ?>" class="<?= $activeNav==='delivery_challan'?'active':'' ?>">🚚 Delivery Challans</a>
    <a href="<?= base_url('app/invoices.php?type=online_order') ?>" class="<?= $activeNav==='online_order'?'active':'' ?>">🛒 Online Orders</a>
    <a href="<?= base_url('app/invoices.php?type=proforma') ?>" class="<?= $activeNav==='proforma'?'active':'' ?>">📄 Proforma</a>
    <a href="<?= base_url('app/invoices.php?type=quotation') ?>" class="<?= $activeNav==='quotation'?'active':'' ?>">📝 Quotations</a>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (can('purchases')): ?>
    <div class="sec-label">Purchase</div>
    <a href="<?= base_url('app/invoices.php?type=purchase') ?>" class="<?= $activeNav==='purchase'?'active':'' ?>">📥 Purchase Bills</a>
    <?php endif; ?>

    <?php if (can('products')): ?>
    <div class="sec-label">Inventory</div>
    <a href="<?= base_url('app/products.php') ?>" class="<?= $activeNav==='products'?'active':'' ?>">📦 Products &amp; Services</a>
    <a href="<?= base_url('app/reorder.php') ?>" class="<?= $activeNav==='reorder'?'active':'' ?>">🔁 Reorder Suggestions</a>
    <a href="<?= base_url('app/locations.php') ?>" class="<?= $activeNav==='locations'?'active':'' ?>">🏬 Locations</a>
    <a href="<?= base_url('app/batches.php') ?>" class="<?= $activeNav==='batches'?'active':'' ?>">📋 Batches &amp; Lots</a>
    <a href="<?= base_url('app/barcode_labels.php') ?>" class="<?= $activeNav==='barcode_labels'?'active':'' ?>">🏷️ Barcode Labels</a>
    <?php endif; ?>

    <?php if (can('parties')): ?>
    <div class="sec-label">Contacts</div>
    <a href="<?= base_url('app/parties.php') ?>" class="<?= $activeNav==='parties'?'active':'' ?>">👥 Customers &amp; Suppliers</a>
    <?php endif; ?>

    <?php if (can('daybook') || can('reports') || can('payments') || can('expenses') || can('gst_export')): ?>
    <div class="sec-label">Accounting</div>
    <?php if (can('daybook')): ?><a href="<?= base_url('app/daybook.php') ?>" class="<?= $activeNav==='daybook'?'active':'' ?>">📅 Daybook</a><?php endif; ?>
    <?php if (can('reports')): ?><a href="<?= base_url('app/reports.php') ?>" class="<?= $activeNav==='reports'?'active':'' ?>">📈 Reports</a><?php endif; ?>
    <?php if (can('payments')): ?>
    <a href="<?= base_url('app/payments.php') ?>" class="<?= $activeNav==='payments'?'active':'' ?>">💰 Payments</a>
    <a href="<?= base_url('app/due_reminders.php') ?>" class="<?= $activeNav==='due_reminders'?'active':'' ?>">🔔 Due Reminders</a>
    <a href="<?= base_url('app/ledger.php') ?>" class="<?= $activeNav==='ledger'?'active':'' ?>">📒 Party Ledger</a>
    <?php endif; ?>
    <?php if (can('expenses')): ?><a href="<?= base_url('app/expenses.php') ?>" class="<?= $activeNav==='expenses'?'active':'' ?>">🧮 Expenses</a><?php endif; ?>
    <?php if (can('gst_export')): ?><a href="<?= base_url('app/gst_export.php') ?>" class="<?= $activeNav==='gst'?'active':'' ?>">🏛️ GST Export</a><?php endif; ?>
    <?php endif; ?>

    <?php if (can('settings') || $u['role'] === 'owner'): ?>
    <div class="sec-label">Setup</div>
    <?php if (can('settings')): ?><a href="<?= base_url('app/settings.php') ?>" class="<?= $activeNav==='settings'?'active':'' ?>">⚙️ Settings</a><?php endif; ?>
    <?php if ($u['role'] === 'owner'): ?>
    <a href="<?= base_url('app/users.php') ?>" class="<?= $activeNav==='users'?'active':'' ?>">🔑 Team / Users</a>
    <a href="<?= base_url('app/access_control.php') ?>" class="<?= $activeNav==='access_control'?'active':'' ?>">🔒 Access Control</a>
    <a href="<?= base_url('app/audit_log.php') ?>" class="<?= $activeNav==='audit_log'?'active':'' ?>">🗒️ Audit Log</a>
    <?php endif; ?>
    <?php endif; ?>

    <?php $planFeatures = tenant_plan_features($db, $tid); if ($planFeatures['master_accounting'] && can('accounting')): ?>
    <div class="sec-label">Master Accounting</div>
    <a href="<?= base_url('app/accounts.php') ?>" class="<?= $activeNav==='accounts'?'active':'' ?>">📚 Chart of Accounts</a>
    <a href="<?= base_url('app/journal.php') ?>" class="<?= $activeNav==='journal'?'active':'' ?>">📓 Journal Entries</a>
    <a href="<?= base_url('app/trial_balance.php') ?>" class="<?= $activeNav==='trial_balance'?'active':'' ?>">⚖️ Trial Balance</a>
    <?php endif; ?>
  </aside>
  <main class="main">
    <?php $f = flash('success'); if ($f): ?><div class="alert alert-success"><?= e($f) ?></div><?php endif; ?>
    <?php $f = flash('error'); if ($f): ?><div class="alert alert-error"><?= e($f) ?></div><?php endif; ?>
