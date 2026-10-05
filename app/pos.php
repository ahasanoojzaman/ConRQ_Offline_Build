<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'pos', 'POS Billing');
$tid = Auth::tenantId();
$pageTitle = 'POS Billing';
$activeNav = 'pos';

$tenantStmt = $db->prepare("SELECT state FROM tenants WHERE id=?");
$tenantStmt->execute([$tid]);
$tenantState = $tenantStmt->fetch()['state'] ?? '';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $phone = trim($_POST['customer_phone'] ?? '');
    $name = trim($_POST['customer_name'] ?? '');
    $paymentMode = $_POST['payment_mode'] ?? 'cash';
    $markPaid = ($_POST['mark_paid'] ?? '1') === '1';
    $locationId = (int)($_POST['location_id'] ?? 0) ?: null;
    $rawItems = json_decode($_POST['cart'] ?? '[]', true) ?: [];

    $items = [];
    $subtotal = 0; $discountTotal = 0; $taxTotal = 0;

    foreach ($rawItems as $row) {
        $qty = (float)($row['qty'] ?? 0);
        $desc = trim($row['name'] ?? '');
        if ($desc === '' || $qty <= 0) continue;
        $rate = (float)($row['rate'] ?? 0);
        $taxRate = (float)($row['tax_rate'] ?? 0);
        $lineGross = $qty * $rate;
        $taxable = $lineGross;
        $gst = calc_gst($taxable, $taxRate, true); // POS sales are almost always intra-state (in-person, walk-in)
        $lineTotal = round($taxable + $gst['total_tax'], 2);

        $items[] = [
            'product_id' => $row['product_id'] ?: null,
            'description' => $desc,
            'hsn_sac' => $row['hsn_sac'] ?? '',
            'qty' => $qty,
            'unit' => $row['unit'] ?? 'pcs',
            'rate' => $rate,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_rate' => $taxRate,
            'cgst_amount' => $gst['cgst'],
            'sgst_amount' => $gst['sgst'],
            'igst_amount' => $gst['igst'],
            'total' => $lineTotal,
            'location_id' => $locationId,
        ];
        $subtotal += $lineGross;
        $taxTotal += $gst['total_tax'];
    }

    if (!$items) {
        $errors[] = 'Cart is empty - add at least one item.';
    }

    if (!$errors) {
        $grandRaw = $subtotal - $discountTotal + $taxTotal;
        $grandRounded = round($grandRaw);
        $roundOff = round($grandRounded - $grandRaw, 2);

        $db->beginTransaction();
        try {
            // Walk-in customer: reuse by phone if given, else leave party_id null (cash sale, no party).
            $partyId = null;
            if ($phone !== '') {
                $existing = $db->prepare("SELECT id FROM parties WHERE tenant_id=? AND phone=? AND is_active=1 LIMIT 1");
                $existing->execute([$tid, $phone]);
                $row = $existing->fetch();
                if ($row) {
                    $partyId = (int)$row['id'];
                } else {
                    $walkInName = $name !== '' ? $name : ('Walk-in (' . $phone . ')');
                    $db->prepare("INSERT INTO parties (tenant_id, type, name, phone, opening_balance, balance_type) VALUES (?, 'customer', ?, ?, 0, 'dr')")
                       ->execute([$tid, $walkInName, $phone]);
                    $partyId = (int)$db->lastInsertId();
                }
            }

            $docNo = next_doc_number($db, $tid, 'invoice');
            $paidAmount = $markPaid ? $grandRounded : 0;
            $status = $markPaid ? 'paid' : 'unpaid';

            $stmt = $db->prepare("INSERT INTO invoices (tenant_id, doc_type, doc_no, party_id, doc_date, place_of_supply, status, subtotal, discount_amount, tax_amount, round_off, total_amount, paid_amount, notes, created_by)
                                   VALUES (?, 'invoice', ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, 'POS sale', ?)");
            $stmt->execute([$tid, $docNo, $partyId, $tenantState, $status, $subtotal, $discountTotal, $taxTotal, $roundOff, $grandRounded, $paidAmount, Auth::user()['id']]);
            $invoiceId = (int)$db->lastInsertId();

            $costStmt = $db->prepare("SELECT purchase_price, track_batches FROM products WHERE id=? AND tenant_id=?");
            foreach ($items as &$it) {
                $it['cost_price'] = 0;
                $it['batch_id'] = null;
                if (!empty($it['product_id'])) {
                    $costStmt->execute([$it['product_id'], $tid]);
                    $costRow = $costStmt->fetch();
                    $it['cost_price'] = $costRow ? (float)$costRow['purchase_price'] : 0;
                    // FEFO: automatically sell from the earliest-expiring batch,
                    // no picker needed - keeps counter checkout fast.
                    if ($costRow && (int)$costRow['track_batches'] === 1) {
                        $available = get_available_batches($db, (int)$it['product_id'], $locationId);
                        if ($available) {
                            $it['batch_id'] = (int)$available[0]['id'];
                        }
                    }
                }
            }
            unset($it);

            $sort = 0;
            $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, product_id, batch_id, location_id, description, hsn_sac, qty, unit, rate, cost_price, discount_percent, discount_amount, tax_rate, cgst_amount, sgst_amount, igst_amount, total, sort_order)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($items as $it) {
                $itemStmt->execute([$invoiceId, $it['product_id'], $it['batch_id'], $it['location_id'] ?? null, $it['description'], $it['hsn_sac'], $it['qty'], $it['unit'], $it['rate'], $it['cost_price'], $it['discount_percent'], $it['discount_amount'], $it['tax_rate'], $it['cgst_amount'], $it['sgst_amount'], $it['igst_amount'], $it['total'], $sort++]);
            }

            apply_stock($db, $tid, 'invoice', $items, 1, $invoiceId);

            if ($markPaid && $grandRounded > 0) {
                $db->prepare("INSERT INTO payments (tenant_id, party_id, invoice_id, direction, mode, amount, payment_date, created_by) VALUES (?,?,?, 'in', ?, ?, CURDATE(), ?)")
                   ->execute([$tid, $partyId, $invoiceId, $paymentMode, $grandRounded, Auth::user()['id']]);
            }

            $db->commit();
            json_out(['success' => true, 'invoice_id' => $invoiceId, 'doc_no' => $docNo]);
        } catch (Throwable $ex) {
            $db->rollBack();
            error_log('POS sale failed: ' . $ex->getMessage());
            json_out(['success' => false, 'error' => 'Could not complete this sale. Please try again.'], 500);
        }
    } else {
        json_out(['success' => false, 'error' => implode(' ', $errors)], 422);
    }
}

$searchUrl = base_url('app/ajax_product_search.php');
$quickAddUrl = base_url('app/ajax_party_quickadd.php');
$posLocStmt = $db->prepare("SELECT id, name, is_default FROM locations WHERE tenant_id=? AND is_active=1 ORDER BY is_default DESC, name");
$posLocStmt->execute([$tid]);
$posLocations = $posLocStmt->fetchAll();
require __DIR__ . '/partials/header.php';
?>
<style>
  /* POS-specific layout: full-bleed, big touch targets, no page scroll on desktop */
  .pos-wrap{display:grid;grid-template-columns:1fr 380px;gap:16px;height:calc(100vh - 130px);min-height:500px}
  .pos-left{display:flex;flex-direction:column;min-height:0}
  .pos-search{padding:12px;background:#fff;border:1px solid #e2ddd0;border-radius:8px;margin-bottom:12px;display:flex;gap:8px}
  .pos-search input{width:100%;padding:14px 16px;font-size:1.1rem;border:1px solid #e2ddd0;border-radius:8px}
  .pos-scan-btn{flex-shrink:0;padding:0 18px;border-radius:8px;border:1px solid #e2ddd0;background:#faf7f0;font-size:1.3rem;cursor:pointer}
  #qrModal{display:none;position:fixed;inset:0;background:rgba(22,33,58,.85);z-index:300;align-items:center;justify-content:center;padding:16px}
  #qrModal .qr-card{background:#fff;border-radius:12px;max-width:420px;width:100%;padding:20px;text-align:center}
  #qrModal .qr-status{min-height:22px;font-size:.88rem;color:#2f7d5e;font-weight:600;margin-top:10px}
  #qr-reader{width:100%;border-radius:8px;overflow:hidden}
  .pos-grid{flex:1;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;padding:4px}
  .pos-tile{background:#fff;border:1px solid #e2ddd0;border-radius:10px;padding:12px 10px;cursor:pointer;text-align:left;transition:transform .08s;user-select:none}
  .pos-tile:active{transform:scale(.96);background:#faf3e5}
  .pos-tile .n{font-weight:600;font-size:.85rem;line-height:1.3;margin-bottom:6px;min-height:2.2em}
  .pos-tile .p{color:#c8862b;font-weight:700;font-size:1rem}
  .pos-tile .s{font-size:.72rem;color:#8a93ab;margin-top:2px}
  .pos-tile.low .s{color:#b5443a;font-weight:600}

  .pos-cart{background:#fff;border:1px solid #e2ddd0;border-radius:10px;display:flex;flex-direction:column;min-height:0}
  .pos-cart-head{padding:14px 16px;border-bottom:1px solid #e2ddd0;font-weight:700;display:flex;justify-content:space-between;align-items:center}
  .pos-cart-items{flex:1;overflow-y:auto;padding:8px 12px}
  .pos-cart-row{display:flex;align-items:center;gap:8px;padding:10px 4px;border-bottom:1px solid #f0ece0}
  .pos-cart-row .cn{flex:1;font-size:.85rem}
  .pos-cart-row .cn .price{font-size:.72rem;color:#8a93ab;display:block}
  .pos-qty{display:flex;align-items:center;gap:6px}
  .pos-qty button{width:28px;height:28px;border-radius:6px;border:1px solid #e2ddd0;background:#faf7f0;font-size:1rem;cursor:pointer}
  .pos-qty span{min-width:22px;text-align:center;font-weight:600}
  .pos-cart-row .lt{width:70px;text-align:right;font-weight:600;font-size:.85rem}
  .pos-cart-row .rm{color:#b5443a;background:none;border:0;cursor:pointer;font-size:1rem;padding:0 4px}
  .pos-footer{padding:14px 16px;border-top:1px solid #e2ddd0}
  .pos-total-row{display:flex;justify-content:space-between;font-size:.9rem;padding:3px 0}
  .pos-total-row.grand{font-size:1.4rem;font-weight:700;border-top:2px solid #16213a;margin-top:6px;padding-top:8px}
  .pos-customer{display:flex;gap:6px;margin-bottom:10px}
  .pos-customer input{flex:1;padding:8px 10px;border:1px solid #e2ddd0;border-radius:6px;font-size:.85rem}
  .pos-pay-modes{display:flex;gap:6px;margin:10px 0;flex-wrap:wrap}
  .pos-pay-modes button{flex:1;min-width:60px;padding:8px 6px;border:1px solid #e2ddd0;border-radius:6px;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer}
  .pos-pay-modes button.active{background:#16213a;color:#fff;border-color:#16213a}
  .btn-charge{width:100%;padding:16px;font-size:1.15rem;font-weight:700;background:#2f7d5e;color:#fff;border:0;border-radius:8px;cursor:pointer}
  .btn-charge:disabled{opacity:.5;cursor:not-allowed}
  .pos-mode-toggle{font-size:.8rem}
  @media(max-width:900px){
    .pos-wrap{grid-template-columns:1fr;height:auto}
    .pos-grid{max-height:40vh}
    .pos-cart{max-height:none}
    .pos-cart-items{max-height:220px}
  }
</style>

<div class="page-head">
  <div><h1>POS Billing</h1><div class="sub">Fast, touch-friendly checkout</div></div>
  <a href="<?= base_url('app/invoice_form.php?type=invoice') ?>" class="btn btn-outline pos-mode-toggle">Switch to Classic Invoice Form</a>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="pos-wrap">
  <div class="pos-left">
    <div class="pos-search">
      <input type="text" id="posSearch" placeholder="Search product name or SKU..." autofocus>
      <button type="button" class="pos-scan-btn" onclick="openCameraScanner()" title="Scan with camera">📷</button>
    </div>

    <div id="qrModal">
      <div class="qr-card">
        <h3 style="margin-bottom:10px">Scan Barcode / QR Code</h3>
        <div id="qr-reader"></div>
        <div class="qr-status" id="qrStatus">Point the camera at a barcode or QR code</div>
        <button type="button" class="btn btn-outline" style="margin-top:14px" onclick="closeCameraScanner()">Done Scanning</button>
      </div>
    </div>
    <div class="pos-grid" id="posGrid">
      <div style="grid-column:1/-1;text-align:center;color:#8a93ab;padding:30px">Start typing above to find products, or browse recent items.</div>
    </div>
  </div>

  <div class="pos-cart">
    <div class="pos-cart-head"><span>🛒 Cart</span><span id="cartCount">0 items</span></div>
    <div class="pos-cart-items" id="cartItems">
      <div style="text-align:center;color:#8a93ab;padding:30px;font-size:.85rem">Cart is empty. Tap a product to add it.</div>
    </div>
    <div class="pos-footer">
      <?php if (count($posLocations) > 1): ?>
      <div class="form-group" style="margin-bottom:10px">
        <select id="posLocation" style="width:100%;padding:8px 10px;border:1px solid #e2ddd0;border-radius:6px;font-size:.85rem">
          <?php foreach ($posLocations as $l): ?>
            <option value="<?= (int)$l['id'] ?>" <?= $l['is_default'] ? 'selected' : '' ?>>Selling from: <?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="pos-customer">
        <input type="text" id="custPhone" placeholder="Customer phone (optional)">
        <input type="text" id="custName" placeholder="Name (optional)">
      </div>
      <div class="pos-pay-modes" id="payModes">
        <button type="button" class="active" data-mode="cash">Cash</button>
        <button type="button" data-mode="upi">UPI</button>
        <button type="button" data-mode="card">Card</button>
        <button type="button" data-mode="bank">Bank</button>
      </div>
      <div class="pos-total-row"><span>Subtotal</span><span id="posSubtotal">₹0.00</span></div>
      <div class="pos-total-row"><span>Tax (GST)</span><span id="posTax">₹0.00</span></div>
      <div class="pos-total-row grand"><span>Total</span><span id="posGrand">₹0.00</span></div>
      <button class="btn-charge" id="chargeBtn" onclick="charge()" style="margin-top:10px" disabled>Charge ₹0.00</button>
    </div>
  </div>
</div>

<!-- Success screen -->
<div id="posSuccess" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.85);z-index:200;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:360px;width:100%;text-align:center">
    <div style="font-size:3rem;margin-bottom:10px">✅</div>
    <h3 style="margin-bottom:6px">Sale Complete</h3>
    <p class="hint" id="posSuccessDocNo" style="margin-bottom:20px"></p>
    <div style="display:flex;flex-direction:column;gap:10px">
      <a id="printThermalLink" href="#" target="_blank" class="btn btn-primary">🧾 Print Receipt</a>
      <a id="viewInvoiceLink" href="#" class="btn btn-outline">View Full Invoice</a>
      <button class="btn btn-outline" onclick="newSale()">+ Start New Sale</button>
    </div>
  </div>
</div>

<script>
const searchUrl = <?= json_encode($searchUrl) ?>;
const csrfToken = <?= json_encode(csrf_token()) ?>;
let cart = []; // {product_id, name, hsn_sac, unit, rate, tax_rate, qty}
let payMode = 'cash';
let allProducts = [];

const posSearch = document.getElementById('posSearch');
const posGrid = document.getElementById('posGrid');
let searchTimer;

posSearch.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = posSearch.value.trim();
  if (q.length < 1) {
    renderGrid(allProducts);
    return;
  }
  searchTimer = setTimeout(async () => {
    const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q));
    const data = await res.json();
    renderGrid(data.items || []);
  }, 150);
});

// Barcode scanner support: scanners act like a very fast typist followed by
// an Enter keypress. On Enter, look for an exact SKU match and add it
// straight to the cart, then clear the box so the next scan can go right in
// without the cashier touching the screen.
posSearch.addEventListener('keydown', async (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  clearTimeout(searchTimer);
  const q = posSearch.value.trim();
  if (!q) return;

  let match = allProducts.find(p => p.sku && p.sku.toLowerCase() === q.toLowerCase());
  let results = [];
  if (!match) {
    const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q));
    const data = await res.json();
    results = data.items || [];
    match = results.find(p => p.sku && p.sku.toLowerCase() === q.toLowerCase());
    if (!match && results.length === 1) match = results[0];
  }

  if (match) {
    addToCart(match);
    posSearch.value = '';
    renderGrid(allProducts);
    posSearch.focus();
  } else {
    renderGrid(results);
  }
});

/**
 * Shared lookup used by BOTH the physical barcode scanner (Enter key
 * handler above) and the camera/QR scanner below, so a decoded code is
 * treated identically no matter which input method produced it.
 */
async function lookupAndAddByCode(code){
  code = (code || '').trim();
  if (!code) return { matched: false, results: [] };
  let match = allProducts.find(p => p.sku && p.sku.toLowerCase() === code.toLowerCase());
  let results = [];
  if (!match) {
    const res = await fetch(searchUrl + '?q=' + encodeURIComponent(code));
    const data = await res.json();
    results = data.items || [];
    match = results.find(p => p.sku && p.sku.toLowerCase() === code.toLowerCase());
    if (!match && results.length === 1) match = results[0];
  }
  if (match) {
    addToCart(match);
    return { matched: true, product: match };
  }
  return { matched: false, results };
}

// ---- Camera / QR scanner ----
// Uses html5-qrcode (Apache-2.0, loaded from cdnjs) to decode both 1D
// barcodes and QR codes from the device camera - works on phones/tablets
// with no physical scanner attached. Loaded lazily, only when the Scan
// button is first used, so pages that never touch it pay no extra cost.
let qrScanner = null;
let qrLibLoading = null;
let qrLastCode = null;
let qrLastTime = 0;

function loadQrLibrary(){
  if (window.Html5Qrcode) return Promise.resolve();
  if (qrLibLoading) return qrLibLoading;
  qrLibLoading = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
    script.onload = resolve;
    script.onerror = () => reject(new Error('Could not load the scanner library. Check your internet connection.'));
    document.head.appendChild(script);
  });
  return qrLibLoading;
}

async function openCameraScanner(){
  document.getElementById('qrModal').style.display = 'flex';
  const statusEl = document.getElementById('qrStatus');
  statusEl.style.color = '#4a5470';
  statusEl.textContent = 'Starting camera...';

  try {
    await loadQrLibrary();
  } catch (err) {
    statusEl.style.color = '#b5443a';
    statusEl.textContent = err.message;
    return;
  }

  qrScanner = new Html5Qrcode('qr-reader');
  const config = { fps: 10, qrbox: { width: 240, height: 240 } };

  try {
    await qrScanner.start({ facingMode: 'environment' }, config, onCameraDecode, () => {});
    statusEl.style.color = '#4a5470';
    statusEl.textContent = 'Point the camera at a barcode or QR code';
  } catch (err) {
    statusEl.style.color = '#b5443a';
    statusEl.textContent = 'Could not access the camera. Check camera permission for this site, or use a device with a camera.';
  }
}

async function onCameraDecode(decodedText){
  // Debounce: the camera keeps reading the same code every frame while it
  // stays in view, so ignore repeats of the same code within 2 seconds
  // rather than adding the same item to the cart many times per second.
  const now = Date.now();
  if (decodedText === qrLastCode && (now - qrLastTime) < 2000) return;
  qrLastCode = decodedText;
  qrLastTime = now;

  const statusEl = document.getElementById('qrStatus');
  const result = await lookupAndAddByCode(decodedText);
  if (result.matched) {
    statusEl.style.color = '#2f7d5e';
    statusEl.textContent = `Added: ${result.product.name}`;
  } else {
    statusEl.style.color = '#b5443a';
    statusEl.textContent = `No product matches code "${decodedText}"`;
  }
}

function closeCameraScanner(){
  document.getElementById('qrModal').style.display = 'none';
  if (qrScanner) {
    qrScanner.stop().then(() => qrScanner.clear()).catch(() => {});
    qrScanner = null;
  }
  qrLastCode = null;
}

async function loadAllProducts(){
  posGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#8a93ab;padding:30px">Loading products...</div>';
  const res = await fetch(searchUrl);
  const data = await res.json();
  allProducts = data.items || [];
  renderGrid(allProducts);
}
loadAllProducts();

function renderGrid(items){
  if (!items.length) {
    posGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#8a93ab;padding:30px">No products yet - add some from the Products &amp; Services page.</div>';
    return;
  }
  posGrid.innerHTML = items.map((p, i) => {
    const low = p.type === 'product' && parseFloat(p.current_stock) <= 0;
    return `<button type="button" class="pos-tile ${low ? 'low' : ''}" data-i="${i}" ${low ? 'title="Out of stock"' : ''}>
      <div class="n">${p.name}</div>
      <div class="p">₹${p.sale_price}${p.price_type === 'inclusive' ? ' <span style="font-size:.6rem;color:#8a93ab">incl</span>' : ''}</div>
      <div class="s">${p.type === 'product' ? 'Stock: ' + p.current_stock : 'Service'}</div>
    </button>`;
  }).join('');
  posGrid.dataset.items = JSON.stringify(items);
}

posGrid.addEventListener('click', (e) => {
  const tile = e.target.closest('.pos-tile');
  if (!tile) return;
  const items = JSON.parse(posGrid.dataset.items || '[]');
  const p = items[parseInt(tile.dataset.i, 10)];
  if (p) addToCart(p);
});

// Cart rate is always stored tax-exclusive (base price). If a product is
// priced "inclusive" (GST baked into the sale price), back-calculate the
// base rate here so the tax math below (and on the server) stays simple
// and consistent regardless of how the product was priced.
function exclusiveRate(p){
  const price = parseFloat(p.sale_price) || 0;
  const tax = parseFloat(p.tax_rate) || 0;
  if (p.price_type === 'inclusive' && tax > 0) {
    return Math.round((price / (1 + tax / 100)) * 100) / 100;
  }
  return price;
}

function addToCart(p){
  const existing = cart.find(c => c.product_id == p.id);
  if (existing) {
    existing.qty += 1;
  } else {
    cart.push({ product_id: p.id, name: p.name, hsn_sac: p.hsn_sac, unit: p.unit, rate: exclusiveRate(p), tax_rate: parseFloat(p.tax_rate), qty: 1 });
  }
  renderCart();
}

function changeQty(idx, delta){
  cart[idx].qty += delta;
  if (cart[idx].qty <= 0) cart.splice(idx, 1);
  renderCart();
}

function removeItem(idx){
  cart.splice(idx, 1);
  renderCart();
}

function renderCart(){
  const cartItems = document.getElementById('cartItems');
  const cartCount = document.getElementById('cartCount');
  if (!cart.length) {
    cartItems.innerHTML = '<div style="text-align:center;color:#8a93ab;padding:30px;font-size:.85rem">Cart is empty. Tap a product to add it.</div>';
  } else {
    cartItems.innerHTML = cart.map((c, i) => {
      const lineTotal = c.qty * c.rate * (1 + c.tax_rate / 100);
      return `<div class="pos-cart-row">
        <div class="cn">${c.name}<span class="price">₹${c.rate.toFixed(2)} x ${c.qty}</span></div>
        <div class="pos-qty">
          <button type="button" onclick="changeQty(${i},-1)">−</button>
          <span>${c.qty}</span>
          <button type="button" onclick="changeQty(${i},1)">+</button>
        </div>
        <div class="lt">₹${lineTotal.toFixed(2)}</div>
        <button class="rm" onclick="removeItem(${i})">✕</button>
      </div>`;
    }).join('');
  }
  cartCount.textContent = cart.reduce((s,c)=>s+c.qty,0) + ' items';

  let subtotal = 0, tax = 0;
  cart.forEach(c => {
    const gross = c.qty * c.rate;
    subtotal += gross;
    tax += gross * c.tax_rate / 100;
  });
  const grand = Math.round(subtotal + tax);
  document.getElementById('posSubtotal').textContent = '₹' + subtotal.toFixed(2);
  document.getElementById('posTax').textContent = '₹' + tax.toFixed(2);
  document.getElementById('posGrand').textContent = '₹' + grand.toFixed(2);
  const chargeBtn = document.getElementById('chargeBtn');
  chargeBtn.textContent = 'Charge ₹' + grand.toFixed(2);
  chargeBtn.disabled = cart.length === 0;
}

document.getElementById('payModes').addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn) return;
  document.querySelectorAll('#payModes button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  payMode = btn.dataset.mode;
});

async function charge(){
  if (!cart.length) return;
  const chargeBtn = document.getElementById('chargeBtn');
  chargeBtn.disabled = true;
  chargeBtn.textContent = 'Processing...';

  const form = new URLSearchParams();
  form.set('csrf_token', csrfToken);
  form.set('customer_phone', document.getElementById('custPhone').value.trim());
  form.set('customer_name', document.getElementById('custName').value.trim());
  form.set('payment_mode', payMode);
  const posLocEl = document.getElementById('posLocation');
  if (posLocEl) form.set('location_id', posLocEl.value);
  form.set('mark_paid', '1');
  form.set('cart', JSON.stringify(cart));

  try {
    const res = await fetch(window.location.href, { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    if (!data.success) {
      alert(data.error || 'Could not complete this sale.');
      renderCart();
      return;
    }
    document.getElementById('posSuccessDocNo').textContent = data.doc_no;
    document.getElementById('printThermalLink').href = <?= json_encode(base_url('app/invoice_print_thermal.php?id=')) ?> + data.invoice_id;
    document.getElementById('viewInvoiceLink').href = <?= json_encode(base_url('app/invoice_view.php?id=')) ?> + data.invoice_id;
    document.getElementById('posSuccess').style.display = 'flex';
  } catch (err) {
    alert('Network error - please try again.');
    renderCart();
  }
}

function newSale(){
  cart = [];
  document.getElementById('custPhone').value = '';
  document.getElementById('custName').value = '';
  document.getElementById('posSuccess').style.display = 'none';
  renderCart();
  posSearch.value = '';
  posSearch.focus();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
