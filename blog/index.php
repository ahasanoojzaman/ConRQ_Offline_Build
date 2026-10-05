<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$posts = [
    [
        'slug' => 'eway-bill-rules-explained',
        'title' => 'E-Way Bill Rules in India: When You Need One and What Must Be On It',
        'excerpt' => 'A plain-language explainer on e-way bills for Indian businesses moving goods - when one is required, what details it needs, and how delivery challans fit in.',
        'date' => '2026-08-27',
    ],
    [
        'slug' => 'pos-billing-software-retail-shops',
        'title' => 'POS Billing Software for Retail Shops: What to Look For',
        'excerpt' => 'What actually matters when choosing point-of-sale billing software for a retail counter in India - speed, barcode scanning, offline reliability, and GST correctness.',
        'date' => '2026-08-24',
    ],
    [
        'slug' => 'tally-vyapar-conrq-comparison',
        'title' => 'Tally vs Vyapar vs ConrQ: Which Billing Software Should You Choose?',
        'excerpt' => 'A practical, honest comparison for Indian retailers and wholesalers choosing between the most common billing and accounting options in 2026.',
        'date' => '2026-08-18',
    ],
    [
        'slug' => 'gst-invoice-format-india',
        'title' => 'GST Invoice Format in India: A Complete Guide for Small Businesses',
        'excerpt' => 'Everything a GST-registered retailer, wholesaler or distributor needs to know about what a compliant GST invoice must contain, common mistakes, and how software can get it right automatically.',
        'date' => '2026-08-10',
    ],
];
?>
<!DOCTYPE html>
<html lang="en-IN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Blog — Billing, GST &amp; Inventory Guides for Indian Businesses | ConrQ</title>
<meta name="description" content="Practical guides on GST invoicing, inventory management and billing software for Indian retailers, wholesalers and distributors, from the team behind ConrQ.">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://conrq.krenx.in/blog/">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/landing.css">
<style>.blog-list{max-width:760px;margin:0 auto}.blog-card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:26px;margin-bottom:18px}.blog-card h2{font-size:1.3rem;margin-bottom:8px}.blog-card .meta{font-size:.8rem;color:var(--ink-soft);margin-bottom:10px}.blog-card a.read{color:var(--amber);font-weight:700;font-size:.9rem}</style>
</head>
<body>
<nav class="nav">
  <div class="nav-inner">
    <div class="logo"><a href="../index.php" style="color:inherit;text-decoration:none">Conr<span>Q</span></a></div>
    <div class="nav-links">
      <a href="../index.php#features">Features</a>
      <a href="../index.php#pricing">Pricing</a>
      <a href="./">Blog</a>
      <a href="../index.php#contact">Contact</a>
    </div>
    <a href="../login.php" class="cta">Login</a>
  </div>
</nav>

<header style="padding:56px 0 30px">
  <div class="container" style="text-align:center">
    <div class="eyebrow">ConrQ Blog</div>
    <h1 style="font-size:2.2rem;margin-top:10px">Billing, GST &amp; inventory guides</h1>
    <p style="color:var(--ink-soft);max-width:560px;margin:14px auto 0">Practical, no-fluff guides for Indian retailers, wholesalers and distributors.</p>
  </div>
</header>

<section style="padding-top:0">
  <div class="container blog-list">
    <?php foreach ($posts as $p): ?>
    <div class="blog-card">
      <div class="meta"><?= date('d M Y', strtotime($p['date'])) ?></div>
      <h2><a href="<?= e($p['slug']) ?>.php" style="color:var(--navy);text-decoration:none"><?= e($p['title']) ?></a></h2>
      <p style="color:var(--ink-soft);margin-bottom:14px"><?= e($p['excerpt']) ?></p>
      <a href="<?= e($p['slug']) ?>.php" class="read">Read article →</a>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<footer>
  <div class="container">
    <div class="footer-bottom">&copy; <?= date('Y') ?> <?= e(BRAND_COMPANY) ?>. <a href="../index.php" style="color:#9fabc9">Back to ConrQ home</a></div>
  </div>
</footer>
</body>
</html>
