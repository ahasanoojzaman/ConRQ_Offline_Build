<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim($_GET['shop'] ?? '');
if ($slug === '') { http_response_code(404); die('Store not found.'); }

$tenantStmt = $db->prepare("SELECT t.*, p.feature_storefront FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.slug = ?");
$tenantStmt->execute([$slug]);
$shop = $tenantStmt->fetch();

if (!$shop || !in_array($shop['status'], ['trial', 'active'], true)) {
    http_response_code(404);
    die('This store is not currently available.');
}
if (empty($shop['feature_storefront'])) {
    http_response_code(404);
    die('Online ordering is not enabled for this store.');
}

$tid = (int)$shop['id'];
$settingsStmt = $db->prepare("SELECT * FROM tenant_settings WHERE tenant_id=?");
$settingsStmt->execute([$tid]);
$settings = $settingsStmt->fetch();

// Cart lives in the visitor own session, keyed per shop so browsing two
// different ConrQ stores in the same browser never mixes carts.
if (!isset($_SESSION['storefront_cart'])) $_SESSION['storefront_cart'] = [];
if (!isset($_SESSION['storefront_cart'][$slug])) $_SESSION['storefront_cart'][$slug] = [];
$cart = &$_SESSION['storefront_cart'][$slug];

$view = $_GET['view'] ?? 'catalog';
$orderPlaced = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_to_cart') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        if ($pid) {
            $cart[$pid] = ($cart[$pid] ?? 0) + $qty;
        }
        redirect(base_url('store.php?shop=' . urlencode($slug)));
    } elseif ($action === 'update_cart') {
        foreach (($_POST['qty'] ?? []) as $pid => $qty) {
            $pid = (int)$pid; $qty = (int)$qty;
            if ($qty <= 0) { unset($cart[$pid]); } else { $cart[$pid] = $qty; }
        }
        redirect(base_url('store.php?shop=' . urlencode($slug) . '&view=cart'));
    } elseif ($action === 'place_order') {
        $name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['customer_phone'] ?? '');
        $email = trim($_POST['customer_email'] ?? '');
        $address = trim($_POST['customer_address'] ?? '');

        if ($name === '' || $phone === '') {
            $errors[] = 'Please share your name and phone number so the store can confirm your order.';
        }
        if (empty($cart)) {
            $errors[] = 'Your cart is empty.';
        }

        if (!$errors) {
            $ids = array_keys($cart);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $prodStmt = $db->prepare("SELECT * FROM products WHERE id IN ($in) AND tenant_id=? AND is_active=1");
            $prodStmt->execute([...$ids, $tid]);
            $products = $prodStmt->fetchAll();
            $productsById = [];
            foreach ($products as $p) { $productsById[$p['id']] = $p; }

            $items = [];
            $subtotal = 0; $taxTotal = 0;
            foreach ($cart as $pid => $qty) {
                if (!isset($productsById[$pid])) continue;
                $p = $productsById[$pid];
                $rate = (float)$p['sale_price'];
                if ($p['price_type'] === 'inclusive' && (float)$p['tax_rate'] > 0) {
                    $rate = round($rate / (1 + (float)$p['tax_rate'] / 100), 2);
                }
                $lineGross = $qty * $rate;
                $gst = calc_gst($lineGross, (float)$p['tax_rate'], true); // assume same-state for online orders
                $items[] = [
                    'product_id' => $p['id'], 'description' => $p['name'], 'hsn_sac' => $p['hsn_sac'],
                    'qty' => $qty, 'unit' => $p['unit'], 'rate' => $rate, 'discount_percent' => 0, 'discount_amount' => 0,
                    'tax_rate' => $p['tax_rate'], 'cgst_amount' => $gst['cgst'], 'sgst_amount' => $gst['sgst'], 'igst_amount' => $gst['igst'],
                    'total' => round($lineGross + $gst['total_tax'], 2),
                ];
                $subtotal += $lineGross;
                $taxTotal += $gst['total_tax'];
            }

            if (!$items) {
                $errors[] = 'None of the items in your cart are available anymore.';
            } else {
                $db->beginTransaction();
                try {
                    $partyStmt = $db->prepare("SELECT id FROM parties WHERE tenant_id=? AND phone=? AND is_active=1 LIMIT 1");
                    $partyStmt->execute([$tid, $phone]);
                    $partyRow = $partyStmt->fetch();
                    if ($partyRow) {
                        $partyId = (int)$partyRow['id'];
                    } else {
                        $db->prepare("INSERT INTO parties (tenant_id, type, name, phone, email, billing_address, opening_balance, balance_type) VALUES (?, 'customer', ?, ?, ?, ?, 0, 'dr')")
                           ->execute([$tid, $name, $phone, $email ?: null, $address ?: null]);
                        $partyId = (int)$db->lastInsertId();
                    }

                    $grandRaw = $subtotal + $taxTotal;
                    $grandRounded = round($grandRaw);
                    $roundOff = round($grandRounded - $grandRaw, 2);
                    // Online orders get their own lightweight numbering (timestamp-based)
                    // rather than sharing the invoice sequence - keeps this fully separate
                    // from your real invoice numbering until the shop owner converts it.
                    $docNo = 'WEB-' . date('ymd-His') . '-' . random_int(100, 999);

                    $stmt = $db->prepare("INSERT INTO invoices (tenant_id, doc_type, doc_no, party_id, doc_date, status, subtotal, discount_amount, tax_amount, round_off, total_amount, notes)
                                           VALUES (?, 'online_order', ?, ?, CURDATE(), 'final', ?, 0, ?, ?, ?, ?)");
                    $stmt->execute([$tid, $docNo, $partyId, $subtotal, $taxTotal, $roundOff, $grandRounded, 'Placed via online store. Delivery/pickup address: ' . $address]);
                    $orderId = (int)$db->lastInsertId();

                    $sort = 0;
                    $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, product_id, description, hsn_sac, qty, unit, rate, discount_percent, discount_amount, tax_rate, cgst_amount, sgst_amount, igst_amount, total, sort_order)
                                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    foreach ($items as $it) {
                        $itemStmt->execute([$orderId, $it['product_id'], $it['description'], $it['hsn_sac'], $it['qty'], $it['unit'], $it['rate'], $it['discount_percent'], $it['discount_amount'], $it['tax_rate'], $it['cgst_amount'], $it['sgst_amount'], $it['igst_amount'], $it['total'], $sort++]);
                    }
                    // Online orders do not move stock automatically - the shop owner
                    // reviews and converts to a real Invoice, at which point stock updates.

                    $db->commit();
                    unset($_SESSION['storefront_cart'][$slug]);
                    $orderPlaced = $docNo;
                    $view = 'confirmation';
                } catch (Throwable $ex) {
                    $db->rollBack();
                    $errors[] = 'Could not place your order. Please try again.';
                }
            }
        }
        if ($errors) { $view = 'checkout'; }
    }
}

// ---- Data for display ----
$search = trim($_GET['q'] ?? '');
$prodSql = "SELECT * FROM products WHERE tenant_id=? AND is_active=1";
$prodParams = [$tid];
if ($search !== '') { $prodSql .= " AND name LIKE ?"; $prodParams[] = "%$search%"; }
$prodSql .= " ORDER BY name ASC LIMIT 200";
$prodStmt = $db->prepare($prodSql);
$prodStmt->execute($prodParams);
$catalog = $prodStmt->fetchAll();

$cartItems = [];
$cartTotal = 0;
if ($cart) {
    $ids = array_keys($cart);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $cartStmt = $db->prepare("SELECT * FROM products WHERE id IN ($in) AND tenant_id=?");
    $cartStmt->execute([...$ids, $tid]);
    foreach ($cartStmt->fetchAll() as $p) {
        $qty = $cart[$p['id']];
        $rate = (float)$p['sale_price'];
        $lineTotal = $rate * $qty;
        $cartTotal += $lineTotal;
        $cartItems[] = ['product' => $p, 'qty' => $qty, 'line_total' => $lineTotal];
    }
}
$cartCount = array_sum($cart);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($shop['company_name']) ?> — Online Store</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{--ink:#16213a;--paper:#faf7f0;--paper2:#f2ede1;--line:#e2ddd0;--amber:#c8862b}
  *{box-sizing:border-box}
  body{margin:0;background:var(--paper2);color:var(--ink);font-family:'Inter',sans-serif}
  h1,h2,h3{font-family:'Source Serif 4',serif;margin:0}
  .topbar{background:var(--ink);color:#fff;padding:14px 18px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:20}
  .topbar .name{font-size:1.15rem;font-weight:700}
  .topbar .spacer{flex:1}
  .cart-btn{background:var(--amber);color:#fff;padding:8px 14px;border-radius:20px;text-decoration:none;font-weight:700;font-size:.85rem;position:relative}
  .wrap{max-width:1000px;margin:0 auto;padding:20px 16px 60px}
  .search-bar{margin-bottom:18px}
  .search-bar input{width:100%;padding:12px 14px;border-radius:8px;border:1px solid var(--line);font-size:1rem}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}
  .card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px;display:flex;flex-direction:column}
  .card .n{font-weight:600;font-size:.9rem;margin-bottom:6px;min-height:2.4em}
  .card .p{color:var(--amber);font-weight:700;font-size:1.05rem;margin-bottom:10px}
  .card form{margin-top:auto}
  .btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border-radius:6px;border:0;background:var(--ink);color:#fff;font-weight:700;font-size:.85rem;cursor:pointer;width:100%}
  .btn-amber{background:var(--amber)}
  .empty{text-align:center;padding:60px 20px;color:#8a93ab}
  .cart-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid var(--line)}
  .cart-row .n{flex:1;font-weight:600;font-size:.92rem}
  .cart-row input[type=number]{width:64px;padding:6px;border:1px solid var(--line);border-radius:6px}
  .totals{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px;margin-top:16px}
  .totals .row{display:flex;justify-content:space-between;padding:5px 0}
  .totals .row.grand{font-weight:700;font-size:1.2rem;border-top:2px solid var(--ink);margin-top:6px;padding-top:10px}
  .form-group{margin-bottom:14px}
  .form-group label{display:block;font-size:.82rem;font-weight:600;margin-bottom:5px}
  .form-group input,.form-group textarea{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:6px;font-family:inherit}
  .alert-error{background:#fbe9e7;color:#b5443a;padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:.9rem}
  .confirm-box{background:#fff;border:1px solid var(--line);border-radius:12px;padding:40px 24px;text-align:center;margin-top:30px}
</style>
</head>
<body>
<div class="topbar">
  <div class="name"><?= e($shop['company_name']) ?></div>
  <div class="spacer"></div>
  <a href="?shop=<?= urlencode($slug) ?>&view=cart" class="cart-btn">🛒 Cart<?= $cartCount ? " ($cartCount)" : '' ?></a>
</div>

<div class="wrap">

<?php if ($view === 'confirmation' && $orderPlaced): ?>
  <div class="confirm-box">
    <div style="font-size:2.6rem;margin-bottom:10px">✅</div>
    <h2 style="margin-bottom:8px">Order Placed!</h2>
    <p style="color:#4a5470">Your order <b><?= e($orderPlaced) ?></b> has been sent to <?= e($shop['company_name']) ?>. They'll contact you shortly to confirm.</p>
    <a href="?shop=<?= urlencode($slug) ?>" class="btn btn-amber" style="width:auto;margin-top:16px;padding:10px 24px;display:inline-flex">Continue Shopping</a>
  </div>

<?php elseif ($view === 'checkout'): ?>
  <h2 style="margin-bottom:16px">Checkout</h2>
  <?php foreach ($errors as $err): ?><div class="alert-error"><?= e($err) ?></div><?php endforeach; ?>
  <div class="totals" style="margin-bottom:20px">
    <?php foreach ($cartItems as $ci): ?>
      <div class="row"><span><?= e($ci['product']['name']) ?> × <?= $ci['qty'] ?></span><span>₹<?= money($ci['line_total']) ?></span></div>
    <?php endforeach; ?>
    <div class="row grand"><span>Total</span><span>₹<?= money($cartTotal) ?></span></div>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="place_order">
    <div class="form-group"><label>Your Name *</label><input type="text" name="customer_name" required></div>
    <div class="form-group"><label>Phone Number *</label><input type="tel" name="customer_phone" required></div>
    <div class="form-group"><label>Email (optional)</label><input type="email" name="customer_email"></div>
    <div class="form-group"><label>Delivery / Pickup Address</label><textarea name="customer_address" rows="2"></textarea></div>
    <button type="submit" class="btn btn-amber">Place Order</button>
  </form>
  <p style="font-size:.8rem;color:#8a93ab;margin-top:12px">Payment is collected on delivery/pickup or as arranged directly with the store - this order is a request for the store to confirm.</p>

<?php elseif ($view === 'cart'): ?>
  <h2 style="margin-bottom:16px">Your Cart</h2>
  <?php if (!$cartItems): ?>
    <div class="empty"><h3>Your cart is empty</h3><p><a href="?shop=<?= urlencode($slug) ?>">Browse products</a> to get started.</p></div>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_cart">
      <?php foreach ($cartItems as $ci): ?>
        <div class="cart-row">
          <div class="n"><?= e($ci['product']['name']) ?><div style="font-size:.78rem;color:#8a93ab">₹<?= money($ci['product']['sale_price']) ?> each</div></div>
          <input type="number" name="qty[<?= (int)$ci['product']['id'] ?>]" value="<?= (int)$ci['qty'] ?>" min="0">
          <div style="width:80px;text-align:right;font-weight:600">₹<?= money($ci['line_total']) ?></div>
        </div>
      <?php endforeach; ?>
      <button type="submit" class="btn" style="width:auto;padding:8px 18px;margin-top:14px">Update Cart</button>
    </form>
    <div class="totals">
      <div class="row grand"><span>Total</span><span>₹<?= money($cartTotal) ?></span></div>
    </div>
    <a href="?shop=<?= urlencode($slug) ?>&view=checkout" class="btn btn-amber" style="margin-top:16px">Proceed to Checkout</a>
  <?php endif; ?>

<?php else: ?>
  <form method="get" class="search-bar">
    <input type="hidden" name="shop" value="<?= e($slug) ?>">
    <input type="text" name="q" placeholder="Search products..." value="<?= e($search) ?>">
  </form>
  <?php if (!$catalog): ?>
    <div class="empty"><h3>No products available yet</h3><p>Check back soon.</p></div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($catalog as $p): ?>
      <div class="card">
        <div class="n"><?= e($p['name']) ?></div>
        <div class="p">₹<?= money($p['sale_price']) ?></div>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_to_cart">
          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <button type="submit" class="btn">Add to Cart</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
