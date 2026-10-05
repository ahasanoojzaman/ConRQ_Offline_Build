<?php
Auth::requireAdmin();
$a = Auth::adminUser();
$pageTitle = $pageTitle ?? 'Admin';
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — ConrQ Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<div class="topbar">
  <button class="menu-btn" onclick="document.querySelector('.sidebar').classList.toggle('open');document.querySelector('.overlay').classList.toggle('show')">☰</button>
  <div class="brand">Conr<span style="color:#c8862b">Q</span> <span style="font-size:.7rem;color:#c9d2e8;font-family:'Inter',sans-serif;font-weight:600">ADMIN</span></div>
  <div class="spacer"></div>
  <span style="font-size:.85rem;color:#c9d2e8"><?= e($a['name']) ?></span>
  <a href="<?= base_url('admin/logout.php') ?>" class="icon-btn" title="Logout">⏻</a>
</div>
<div class="overlay" onclick="this.classList.remove('show');document.querySelector('.sidebar').classList.remove('open')"></div>
<div class="layout">
  <aside class="sidebar">
    <a href="<?= base_url('admin/dashboard.php') ?>" class="<?= $activeNav==='dashboard'?'active':'' ?>">📊 Dashboard</a>
    <a href="<?= base_url('admin/tenants.php') ?>" class="<?= $activeNav==='tenants'?'active':'' ?>">🏢 Tenants</a>
    <a href="<?= base_url('admin/plans.php') ?>" class="<?= $activeNav==='plans'?'active':'' ?>">💳 Plans</a>
    <a href="<?= base_url('admin/demo_requests.php') ?>" class="<?= $activeNav==='demo'?'active':'' ?>">📩 Demo Requests</a>
    <a href="<?= base_url('index.php') ?>" target="_blank">🌐 View Landing Page</a>
  </aside>
  <main class="main">
    <?php $f = flash('success'); if ($f): ?><div class="alert alert-success"><?= e($f) ?></div><?php endif; ?>
    <?php $f = flash('error'); if ($f): ?><div class="alert alert-error"><?= e($f) ?></div><?php endif; ?>
