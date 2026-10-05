<?php
require_once __DIR__ . '/includes/bootstrap.php';

$errors = [];
$success = flash('demo_success');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo_request'])) {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $phone === '') {
        $errors[] = 'Please share your name and phone number so we can reach you.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address doesn\'t look right.';
    }

    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO demo_requests (name, company, phone, email, message) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $company, $phone, $email, $message]);
        flash('demo_success', 'Thanks! Our team will contact you within 24 hours to set up your free demo.');
        redirect(base_url('index.php') . '#contact');
    }
}
?>
<!DOCTYPE html>
<html lang="en-IN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ConrQ — Best Billing, Inventory &amp; GST Accounting Software for Indian Businesses</title>
<meta name="description" content="ConrQ is GST-ready billing, inventory and accounting software for retailers, wholesalers, distributors and small businesses across India. Fast invoicing, POS billing, e-way bills, delivery challans, multi-location stock, and a free demo — no commission online store included.">
<meta name="keywords" content="billing software India, GST billing software, best ERP software India, inventory management software, POS billing software, invoicing software for small business, Tally alternative, Vyapar alternative, GST invoice software, retail billing software, wholesale billing software, distributor billing software, delivery challan software, e-way bill software">
<meta name="robots" content="index, follow, max-image-preview:large">
<meta name="author" content="Remesys Technologies">
<link rel="canonical" href="https://conrq.krenx.in/">

<!-- Open Graph / Facebook / WhatsApp link previews -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://conrq.krenx.in/">
<meta property="og:site_name" content="ConrQ">
<meta property="og:title" content="ConrQ — Best Billing, Inventory &amp; GST Accounting Software for Indian Businesses">
<meta property="og:description" content="GST-ready billing, inventory and accounting software for Indian retailers, wholesalers and distributors. Fast invoicing, POS billing, e-way bills, and a free demo.">
<meta property="og:locale" content="en_IN">
<meta property="og:image" content="https://conrq.krenx.in/assets/img/og-share.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="ConrQ - Billing, Inventory and GST Accounting software for Indian businesses">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="ConrQ — Best Billing, Inventory &amp; GST Accounting Software for Indian Businesses">
<meta name="twitter:description" content="GST-ready billing, inventory and accounting software for Indian retailers, wholesalers and distributors. Fast invoicing, POS billing, e-way bills, and a free demo.">
<meta name="twitter:image" content="https://conrq.krenx.in/assets/img/og-share.png">

<!--
  Search engine ownership verification - fill these in once you create the
  accounts (both are free):
  1. Google Search Console: https://search.google.com/search-console
     -> Add property -> URL prefix -> "HTML tag" method -> paste the
        content="..." value below, then click Verify.
  2. Bing Webmaster Tools: https://www.bing.com/webmasters
     -> Add site -> "Meta tag" method -> paste the content="..." value below.
  Submit sitemap.xml in both once verified - this is the single most
  important step for getting the site actually crawled and indexed.
-->
<!-- <meta name="google-site-verification" content="PASTE_YOUR_CODE_HERE"> -->
<!-- <meta name="msvalidate.01" content="PASTE_YOUR_CODE_HERE"> -->

<meta name="geo.region" content="IN-KA">
<meta name="geo.placename" content="Bangalore">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@500;600;700&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/landing.css">

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Organization",
      "@id": "https://conrq.krenx.in/#organization",
      "name": "Remesys Technologies",
      "url": "https://conrq.krenx.in/",
      "email": "contact@remesys.in",
      "telephone": "+91-8073338746",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Novel Tech Park, Kudlu Gate",
        "addressLocality": "Bangalore",
        "addressRegion": "Karnataka",
        "postalCode": "560068",
        "addressCountry": "IN"
      },
      "areaServed": "IN",
      "contactPoint": [{
        "@type": "ContactPoint",
        "telephone": "+91-8073338746",
        "contactType": "sales",
        "areaServed": "IN",
        "availableLanguage": ["English", "Hindi"]
      }]
    },
    {
      "@type": "SoftwareApplication",
      "@id": "https://conrq.krenx.in/#software",
      "name": "ConrQ",
      "applicationCategory": "BusinessApplication",
      "applicationSubCategory": "Billing and Accounting Software",
      "operatingSystem": "Web, Android, iOS",
      "description": "GST-ready billing, inventory and accounting software for Indian retailers, wholesalers, distributors and manufacturers. Includes fast invoicing, POS billing, e-way bills, delivery challans, multi-location inventory, and batch/expiry tracking.",
      "url": "https://conrq.krenx.in/",
      "publisher": { "@id": "https://conrq.krenx.in/#organization" },
      "offers": [
        { "@type": "Offer", "name": "Starter Plan", "price": "499", "priceCurrency": "INR", "priceValidUntil": "2027-12-31", "url": "https://conrq.krenx.in/#pricing" },
        { "@type": "Offer", "name": "Growth Plan", "price": "999", "priceCurrency": "INR", "priceValidUntil": "2027-12-31", "url": "https://conrq.krenx.in/#pricing" },
        { "@type": "Offer", "name": "Business Plan", "price": "2499", "priceCurrency": "INR", "priceValidUntil": "2027-12-31", "url": "https://conrq.krenx.in/#pricing" }
      ]
    },
    {
      "@type": "FAQPage",
      "@id": "https://conrq.krenx.in/#faq",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "What is the best billing software for small businesses in India?",
          "acceptedAnswer": { "@type": "Answer", "text": "The best billing software depends on your business type, but for retailers, wholesalers and distributors, ConrQ combines GST-ready invoicing, POS billing, inventory management and basic accounting in one system, built specifically around how Indian businesses actually bill and manage stock." }
        },
        {
          "@type": "Question",
          "name": "Is ConrQ GST compliant?",
          "acceptedAnswer": { "@type": "Answer", "text": "Yes. ConrQ automatically splits CGST/SGST versus IGST based on place of supply, supports HSN/SAC codes on every line item, and provides a structured JSON export you or your accountant can use for GST return filing." }
        },
        {
          "@type": "Question",
          "name": "Can ConrQ replace Tally or Vyapar?",
          "acceptedAnswer": { "@type": "Answer", "text": "ConrQ covers the billing, inventory, POS and basic accounting workflows that most small and mid-sized retailers, wholesalers and distributors need day to day, with GST invoicing, e-way bill fields, delivery challans, and a Master Accounting module (Chart of Accounts, Journal Entries, Trial Balance) for businesses that need more formal bookkeeping." }
        },
        {
          "@type": "Question",
          "name": "Does ConrQ work on mobile phones?",
          "acceptedAnswer": { "@type": "Answer", "text": "Yes. ConrQ is fully mobile-responsive and includes a dedicated touch-friendly POS billing screen designed for counter staff to use on a phone or tablet." }
        },
        {
          "@type": "Question",
          "name": "What does ConrQ cost?",
          "acceptedAnswer": { "@type": "Answer", "text": "ConrQ starts at Rs. 499 per month for the Starter plan, Rs. 999 per month for Growth, and Rs. 2,499 per month for Business, which adds a commission-free online storefront and Master Accounting tools. A free demo is available on request." }
        },
        {
          "@type": "Question",
          "name": "Can I generate delivery challans and e-way bill details in ConrQ?",
          "acceptedAnswer": { "@type": "Answer", "text": "Yes. ConrQ supports Delivery Challans as a document type, and lets you record vehicle number, transport mode and e-way bill number on invoices, purchase bills and challans." }
        },
        {
          "@type": "Question",
          "name": "Does ConrQ support multiple shops or warehouses?",
          "acceptedAnswer": { "@type": "Answer", "text": "Yes. ConrQ supports multi-location inventory with stock transfers between locations, plus batch and lot tracking with expiry dates for businesses selling perishable or lot-controlled goods." }
        },
        {
          "@type": "Question",
          "name": "Can I try ConrQ before buying?",
          "acceptedAnswer": { "@type": "Answer", "text": "Yes. Request a free, no-obligation demo and the ConrQ team will set it up with a sample of your own products and customers so you can see it working before you decide." }
        }
      ]
    }
  ]
}
</script>
</head>
<body>

<nav class="nav">
  <div class="nav-inner">
    <div class="logo">Conr<span>Q</span></div>
    <div class="nav-links">
      <a href="#features">Features</a>
      <a href="#how-it-works">Tutorial</a>
      <a href="#testimonials">Testimonials</a>
      <a href="#pricing">Pricing</a>
      <a href="#faq">FAQ</a>
      <a href="blog/">Blog</a>
      <a href="#contact">Contact</a>
    </div>
    <a href="login.php" class="cta">Login</a>
    <button class="menu-toggle" onclick="document.querySelector('.nav-links').classList.toggle('mshow')">☰</button>
  </div>
</nav>

<header class="hero">
  <div class="container hero-grid">
    <div>
      <div class="eyebrow">GST Billing Software · Inventory · Accounting</div>
      <h1>Invoice in <em>seconds</em>, not spreadsheets.</h1>
      <p class="lede">ConrQ is GST-ready billing software with inventory and accounting built in — made for Indian retailers, wholesalers and distributors — fast enough to replace your notebook, powerful enough to replace Tally.</p>
      <div class="hero-cta">
        <a href="#contact" class="btn btn-amber">Request a free demo</a>
        <a href="#how-it-works" class="btn btn-outline">See how it works</a>
      </div>
      <div class="hero-meta">
        <div class="m"><b>&lt; 30 sec</b>to raise an invoice</div>
        <div class="m"><b>GST</b>JSON-ready export</div>
        <div class="m"><b>Any device</b>mobile, tablet, desktop</div>
      </div>
    </div>
    <div class="ledger-mock">
      <div class="lm-head">
        <div class="co">Sharma Traders</div>
        <div class="no">INVOICE<br>25-26/0417</div>
      </div>
      <table>
        <tr><td>Basmati Rice 25kg</td><td class="r">2 × ₹1,450</td></tr>
        <tr><td>Sunflower Oil 15L</td><td class="r">1 × ₹2,180</td></tr>
        <tr><td>Toor Dal 10kg</td><td class="r">3 × ₹980</td></tr>
      </table>
      <div class="lm-total"><span>Total (incl. GST)</span><span class="amt">₹8,960.00</span></div>
      <div class="stamp">PAID ✓</div>
    </div>
  </div>
</header>

<div class="strip">
  <div class="container">
    <span>Retail Stores</span><span>Wholesalers</span><span>Distributors</span><span>Manufacturers</span><span>Service Providers</span><span>Traders</span>
  </div>
</div>

<section id="features">
  <div class="container">
    <div class="sec-head">
      <div class="eyebrow">Features</div>
      <h2>Everything your ledger, biller and stock register used to do — in one screen.</h2>
      <p>Built specifically for how Indian businesses actually invoice, stock and collect payments.</p>
    </div>
    <div class="feat-grid">
      <div class="feat"><div class="ico">⚡</div><h3>Fast invoicing</h3><p>Raise an Invoice, Proforma, Quotation or Delivery Challan in a single flowing screen — barcode/search-as-you-type product entry, auto tax calculation, no page reloads.</p></div>
      <div class="feat"><div class="ico">🖥️</div><h3>POS billing counter</h3><p>A dedicated touch-first billing screen for shop counters — tap-to-add products, barcode scanning, walk-in customer capture, and instant thermal receipt printing.</p></div>
      <div class="feat"><div class="ico">📦</div><h3>Multi-location inventory</h3><p>Track stock across multiple shops or warehouses, transfer inventory between locations, and get low-stock reorder suggestions based on real sales velocity.</p></div>
      <div class="feat"><div class="ico">🧊</div><h3>Batch &amp; expiry tracking</h3><p>Lot/batch numbers with expiry dates for perishable or lot-controlled goods, automatic first-expiry-first-out selection at the time of sale, and expiring-stock alerts.</p></div>
      <div class="feat"><div class="ico">🧾</div><h3>GST-ready, e-way bill fields</h3><p>CGST/SGST/IGST auto-split by place of supply, HSN/SAC on every line, vehicle/transport/e-way bill number capture, and one-click JSON export for GST return filing.</p></div>
      <div class="feat"><div class="ico">🏷️</div><h3>Barcode label printing</h3><p>Generate and print real, scannable barcode labels for any product — thermal roll or A4 sheet layouts.</p></div>
      <div class="feat"><div class="ico">🛒</div><h3>Online storefront</h3><p>A commission-free online ordering page customers can use to buy directly from you — no marketplace cut, no third party in between.</p></div>
      <div class="feat"><div class="ico">🖨️</div><h3>Print anywhere</h3><p>One invoice, two layouts — a clean A4 colour printout for office records and a thermal receipt for the counter.</p></div>
      <div class="feat"><div class="ico">💬</div><h3>Share instantly</h3><p>Send any bill straight to WhatsApp or Email the moment it's saved — no downloading, no re-uploading.</p></div>
      <div class="feat"><div class="ico">📒</div><h3>Accounting that fits how you work</h3><p>Party-wise ledgers, daybook, profit &amp; loss and stock valuation reports, plus optional Master Accounting (Chart of Accounts, Journal Entries, Trial Balance) for formal bookkeeping.</p></div>
      <div class="feat"><div class="ico">🔔</div><h3>Reorder &amp; due-payment reminders</h3><p>Automatic low-stock reorder suggestions with supplier contact details, and one-click WhatsApp/email reminders for customers with outstanding dues.</p></div>
      <div class="feat"><div class="ico">📱</div><h3>Mobile-first</h3><p>Built to be used on a phone at the counter as comfortably as on a desktop in the back office — no app install required.</p></div>
    </div>
  </div>
</section>

<section id="how-it-works" style="background:var(--paper-2)">
  <div class="container">
    <div class="sec-head">
      <div class="eyebrow">Tutorial</div>
      <h2>From sign-up to your first invoice in four steps.</h2>
    </div>
    <div class="steps">
      <div class="step"><div class="num">01</div><h4>Set up your company</h4><p>Add your business name, GSTIN, logo and bank/UPI details — takes under two minutes.</p></div>
      <div class="step"><div class="num">02</div><h4>Add products & parties</h4><p>Add items and customers manually, or import them from your existing spreadsheet or software.</p></div>
      <div class="step"><div class="num">03</div><h4>Raise your first bill</h4><p>Pick a customer, search a product, quantity auto-fills the rate and tax — done in under 30 seconds.</p></div>
      <div class="step"><div class="num">04</div><h4>Print, share & get paid</h4><p>Print on A4 or thermal, send via WhatsApp/SMS/Email, then record the payment when it lands.</p></div>
    </div>
    <div class="video-frame">
      <div class="play">▶</div>
      <div class="lbl">Watch: Creating your first invoice (2 min)</div>
    </div>
  </div>
</section>

<section id="testimonials">
  <div class="container">
    <div class="sec-head">
      <div class="eyebrow">Testimonials</div>
      <h2>Trusted at the counter, not just in the back office.</h2>
    </div>
    <div class="testi-grid">
      <div class="testi">
        <p class="quote">We switched from a notebook to ConrQ in an afternoon. My staff bill customers faster than the queue forms now.</p>
        <div class="who"><div class="avatar">R</div><div><div class="name">Ramesh Iyer</div><div class="role">Owner, General Store — Bengaluru</div></div></div>
      </div>
      <div class="testi">
        <p class="quote">GST filing used to take my accountant a full day. The JSON export alone has paid for the subscription many times over.</p>
        <div class="who"><div class="avatar">P</div><div><div class="name">Priya Nair</div><div class="role">Distributor, FMCG — Kochi</div></div></div>
      </div>
      <div class="testi">
        <p class="quote">Thermal printing at the billing counter and A4 invoices for our dealers, from the same system. Exactly what we needed.</p>
        <div class="who"><div class="avatar">S</div><div><div class="name">Suresh Patil</div><div class="role">Wholesaler — Pune</div></div></div>
      </div>
    </div>
  </div>
</section>

<section id="pricing" style="background:var(--paper-2)">
  <div class="container">
    <div class="sec-head">
      <div class="eyebrow">Pricing</div>
      <h2>Simple plans that grow with your business.</h2>
      <p>All plans include unlimited products, GST invoicing and free onboarding support. No setup fee.</p>
    </div>
    <div class="pricing-grid">
      <div class="plan">
        <h3>Starter</h3>
        <div class="price">₹499<span class="per">/month</span></div>
        <div class="desc">For small shops just getting started with digital billing.</div>
        <ul>
          <li>Up to 2 users</li>
          <li>200 invoices / month</li>
          <li>Inventory & basic reports</li>
          <li>A4 &amp; thermal printing</li>
          <li>WhatsApp &amp; email sharing</li>
        </ul>
        <a href="#contact" class="btn btn-outline">Request demo</a>
      </div>
      <div class="plan featured">
        <div class="tag">Most popular</div>
        <h3>Growth</h3>
        <div class="price">₹999<span class="per">/month</span></div>
        <div class="desc">For growing retailers, wholesalers and distributors.</div>
        <ul>
          <li>Up to 5 users</li>
          <li>1,000 invoices / month</li>
          <li>GST reports &amp; JSON export</li>
          <li>Party-wise ledgers &amp; payments</li>
          <li>Priority WhatsApp support</li>
        </ul>
        <a href="#contact" class="btn btn-amber">Request demo</a>
      </div>
      <div class="plan">
        <h3>Business</h3>
        <div class="price">₹2,499<span class="per">/month</span></div>
        <div class="desc">For multi-branch, multi-location operations with high invoice volume.</div>
        <ul>
          <li>Up to 15 users</li>
          <li>Unlimited invoices</li>
          <li>Commission-free online storefront</li>
          <li>Master Accounting (Chart of Accounts, Journal, Trial Balance)</li>
          <li>Multi-location inventory &amp; stock transfers</li>
          <li>Dedicated onboarding</li>
        </ul>
        <a href="#contact" class="btn btn-outline">Request demo</a>
      </div>
    </div>
  </div>
</section>

<section class="cta-band">
  <div class="container">
    <h2>See ConrQ running on your own product list.</h2>
    <p>Book a free, no-obligation demo — we'll set up a sample of your actual products and customers.</p>
    <a href="#contact" class="btn btn-amber">Request a demo</a>
  </div>
</section>

<section id="faq">
  <div class="container">
    <div class="sec-head">
      <div class="eyebrow">FAQ</div>
      <h2>Frequently asked questions</h2>
      <p>Common questions from retailers, wholesalers and distributors evaluating billing software in India.</p>
    </div>
    <div style="max-width:760px;margin:0 auto">
      <div class="feat" style="margin-bottom:14px">
        <h3>What is the best billing software for small businesses in India?</h3>
        <p>The best billing software depends on your business type, but for retailers, wholesalers and distributors, ConrQ combines GST-ready invoicing, POS billing, inventory management and basic accounting in one system, built specifically around how Indian businesses actually bill and manage stock.</p>
      </div>
      <div class="feat" style="margin-bottom:14px">
        <h3>Is ConrQ GST compliant?</h3>
        <p>Yes. ConrQ automatically splits CGST/SGST versus IGST based on place of supply, supports HSN/SAC codes on every line item, and provides a structured JSON export you or your accountant can use for GST return filing.</p>
      </div>
      <div class="feat" style="margin-bottom:14px">
        <h3>Can ConrQ replace Tally or Vyapar?</h3>
        <p>ConrQ covers the billing, inventory, POS and basic accounting workflows most small and mid-sized retailers, wholesalers and distributors need day to day — GST invoicing, e-way bill fields, delivery challans, and a Master Accounting module (Chart of Accounts, Journal Entries, Trial Balance) for businesses that want more formal bookkeeping.</p>
      </div>
      <div class="feat" style="margin-bottom:14px">
        <h3>Does ConrQ work on mobile phones?</h3>
        <p>Yes. ConrQ is fully mobile-responsive and includes a dedicated touch-friendly POS billing screen designed for counter staff to use on a phone or tablet, with barcode scanning support.</p>
      </div>
      <div class="feat" style="margin-bottom:14px">
        <h3>What does ConrQ cost?</h3>
        <p>ConrQ starts at ₹499/month for Starter, ₹999/month for Growth, and ₹2,499/month for Business, which adds a commission-free online storefront and Master Accounting tools. A free demo is available on request.</p>
      </div>
      <div class="feat" style="margin-bottom:14px">
        <h3>Can I generate delivery challans and e-way bill details in ConrQ?</h3>
        <p>Yes. ConrQ supports Delivery Challans as a document type, and lets you record vehicle number, transport mode and e-way bill number on invoices, purchase bills and challans.</p>
      </div>
      <div class="feat" style="margin-bottom:14px">
        <h3>Does ConrQ support multiple shops or warehouses?</h3>
        <p>Yes. ConrQ supports multi-location inventory with stock transfers between locations, plus batch and lot tracking with expiry dates for businesses selling perishable or lot-controlled goods.</p>
      </div>
      <div class="feat">
        <h3>Can I try ConrQ before buying?</h3>
        <p>Yes. Request a free, no-obligation demo and our team will set it up with a sample of your own products and customers so you can see it working before you decide.</p>
      </div>
    </div>
  </div>
</section>

<section id="contact">
  <div class="container">
    <div class="sec-head">
      <div class="eyebrow">Contact &amp; Demo</div>
      <h2>Let's get your business billing faster.</h2>
    </div>
    <div class="contact-wrap">
      <div class="contact-card">
        <h3>Reach us directly</h3>
        <div class="contact-row"><div class="ico">🏢</div><div><div class="lbl">Company</div><div class="val"><?= e(BRAND_COMPANY) ?></div></div></div>
        <div class="contact-row"><div class="ico">📍</div><div><div class="lbl">Address</div><div class="val"><?= e(BRAND_ADDRESS) ?></div></div></div>
        <div class="contact-row"><div class="ico">📞</div><div><div class="lbl">Call</div><div class="val"><?= e(BRAND_PHONE) ?></div></div></div>
        <div class="contact-row"><div class="ico">💬</div><div><div class="lbl">WhatsApp</div><div class="val">+91 91206 19120</div></div></div>
        <div class="contact-row"><div class="ico">✉️</div><div><div class="lbl">Email</div><div class="val"><?= e(BRAND_EMAIL) ?></div></div></div>
      </div>
      <div class="contact-card">
        <h3>Request a free demo</h3>
        <?php if ($success): ?>
          <div class="alert alert-success" style="background:#e4f3ec;color:#2f7d5e;padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:.9rem"><?= e($success) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?>
          <div class="alert alert-error" style="background:#fbe9e7;color:#b5443a;padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:.9rem"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" class="demo-form" action="index.php#contact">
          <?= csrf_field() ?>
          <input type="hidden" name="demo_request" value="1">
          <div class="form-group"><label>Your Name *</label><input type="text" name="name" required value="<?= old('name') ?>"></div>
          <div class="form-group"><label>Business Name</label><input type="text" name="company" value="<?= old('company') ?>"></div>
          <div class="form-group"><label>Phone Number *</label><input type="tel" name="phone" required value="<?= old('phone') ?>"></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= old('email') ?>"></div>
          <div class="form-group"><label>What do you sell / need help with?</label><textarea name="message" rows="3"><?= old('message') ?></textarea></div>
          <button type="submit" class="btn btn-amber btn-block" style="width:100%">Request Demo</button>
        </form>
      </div>
    </div>
  </div>
</section>

<footer>
  <div class="container">
    <div class="footer-grid">
      <div>
        <div class="footer-logo">ConrQ</div>
        <p style="max-width:280px;line-height:1.6">Billing, inventory and accounting for Indian retailers, wholesalers and distributors. By <?= e(BRAND_COMPANY) ?>.</p>
      </div>
      <div>
        <h4>Product</h4>
        <a href="#features">Features</a>
        <a href="#pricing">Pricing</a>
        <a href="#how-it-works">Tutorial</a>
        <a href="blog/">Blog</a>
      </div>
      <div>
        <h4>Company</h4>
        <a href="#testimonials">Testimonials</a>
        <a href="#faq">FAQ</a>
        <a href="#contact">Contact</a>
        <a href="login.php">Login</a>
      </div>
      <div>
        <h4>Get in touch</h4>
        <a href="tel:<?= e(BRAND_PHONE) ?>"><?= e(BRAND_PHONE) ?></a>
        <a href="mailto:<?= e(BRAND_EMAIL) ?>"><?= e(BRAND_EMAIL) ?></a>
        <a href="https://wa.me/<?= e(BRAND_WHATSAPP) ?>" target="_blank">WhatsApp us</a>
      </div>
    </div>
    <div class="footer-bottom">&copy; <?= date('Y') ?> <?= e(BRAND_COMPANY) ?>. All rights reserved. · ConrQ is a product by <?= e(BRAND_COMPANY) ?></div>
  </div>
</footer>

<div class="mobile-cta">
  <a href="https://wa.me/<?= e(BRAND_WHATSAPP) ?>" class="btn btn-outline">WhatsApp</a>
  <a href="#contact" class="btn btn-amber">Request Demo</a>
</div>

</body>
</html>
