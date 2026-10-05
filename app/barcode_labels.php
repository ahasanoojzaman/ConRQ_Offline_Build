<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'products', 'Barcode Labels');
$tid = Auth::tenantId();
$pageTitle = 'Barcode Labels';
$activeNav = 'barcode_labels';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_missing_skus') {
    require_csrf();
    $stmt = $db->prepare("SELECT id FROM products WHERE tenant_id=? AND is_active=1 AND (sku IS NULL OR sku='')");
    $stmt->execute([$tid]);
    $missing = $stmt->fetchAll();
    $upd = $db->prepare("UPDATE products SET sku=? WHERE id=? AND tenant_id=?");
    foreach ($missing as $m) {
        $upd->execute(['P' . str_pad($m['id'], 6, '0', STR_PAD_LEFT), $m['id'], $tid]);
    }
    flash('success', count($missing) . ' product(s) were assigned an auto-generated SKU/barcode value.');
    redirect(base_url('app/barcode_labels.php'));
}

require __DIR__ . '/partials/header.php';
$searchUrl = base_url('app/ajax_product_search.php');

$missingCountStmt = $db->prepare("SELECT COUNT(*) c FROM products WHERE tenant_id=? AND is_active=1 AND (sku IS NULL OR sku='')");
$missingCountStmt->execute([$tid]);
$missingCount = (int)$missingCountStmt->fetch()['c'];
?>
<div class="page-head">
  <div><h1>Barcode Labels</h1><div class="sub">Generate and print barcode labels for your products</div></div>
</div>

<?php if ($missingCount > 0): ?>
<div class="alert alert-info" style="background:#eef2fb;color:#223056;padding:12px 16px;border-radius:6px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
  <span><?= $missingCount ?> product(s) don't have a SKU/barcode value set yet - they need one before a label can be printed.</span>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_missing_skus"><button class="btn btn-outline btn-sm">Auto-Assign Missing SKUs</button></form>
</div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:16px">
  <div class="form-row">
    <div class="form-group" style="position:relative">
      <label>Add Product</label>
      <input type="text" class="form-control" id="productSearch" placeholder="Search product name or SKU..." autocomplete="off">
      <div id="searchSuggest" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e2ddd0;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,.12);z-index:20;max-height:220px;overflow-y:auto"></div>
    </div>
    <div class="form-group">
      <label>Label Size</label>
      <select class="form-control" id="labelSize">
        <option value="thermal">Thermal Roll (50mm x 25mm, single column)</option>
        <option value="sheet">A4 Sheet (grid, multiple per page)</option>
      </select>
    </div>
  </div>
</div>

<div class="table-wrap" style="margin-bottom:16px">
<table class="data">
  <thead><tr><th>Product</th><th>SKU</th><th class="num">Price</th><th class="num">Copies</th><th></th></tr></thead>
  <tbody id="selectedBody">
    <tr id="emptyRow"><td colspan="5"><div class="empty-state"><h3>No products selected</h3><p>Search above to add products for labels.</p></div></td></tr>
  </tbody>
</table>
</div>

<button class="btn btn-primary" id="printBtn" onclick="printLabels()" disabled>🖨️ Print Labels</button>

<div id="printArea" style="display:none"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.12.3/JsBarcode.all.min.js"></script>
<script>
const searchUrl = <?= json_encode($searchUrl) ?>;
let selected = {}; // product_id -> {name, sku, sale_price, copies}
let searchTimer;

const searchInput = document.getElementById('productSearch');
const suggestBox = document.getElementById('searchSuggest');

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  if (q.length < 2) { suggestBox.style.display = 'none'; return; }
  searchTimer = setTimeout(async () => {
    const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q));
    const data = await res.json();
    const items = (data.items || []).filter(p => p.sku);
    if (!items.length) { suggestBox.innerHTML = '<div style="padding:10px 12px;font-size:.82rem;color:#8a93ab">No products with a SKU match.</div>'; suggestBox.style.display = 'block'; return; }
    suggestBox.innerHTML = items.map((p, i) => `<div class="opt" style="padding:8px 12px;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f0ece0" data-i="${i}"><b>${p.name}</b> - SKU: ${p.sku}</div>`).join('');
    suggestBox.dataset.items = JSON.stringify(items);
    suggestBox.style.display = 'block';
  }, 200);
});
suggestBox.addEventListener('mousedown', (e) => {
  const opt = e.target.closest('.opt');
  if (!opt) return;
  e.preventDefault();
  const items = JSON.parse(suggestBox.dataset.items || '[]');
  const p = items[parseInt(opt.dataset.i, 10)];
  if (p) {
    selected[p.id] = { name: p.name, sku: p.sku, sale_price: p.sale_price, copies: 1 };
    renderSelected();
  }
  suggestBox.style.display = 'none';
  searchInput.value = '';
});

function renderSelected(){
  const body = document.getElementById('selectedBody');
  const ids = Object.keys(selected);
  if (!ids.length) {
    body.innerHTML = '<tr id="emptyRow"><td colspan="5"><div class="empty-state"><h3>No products selected</h3><p>Search above to add products for labels.</p></div></td></tr>';
    document.getElementById('printBtn').disabled = true;
    return;
  }
  document.getElementById('printBtn').disabled = false;
  body.innerHTML = ids.map(id => {
    const p = selected[id];
    return `<tr>
      <td>${p.name}</td>
      <td>${p.sku}</td>
      <td class="num">₹${p.sale_price}</td>
      <td class="num"><input type="number" min="1" value="${p.copies}" style="width:70px;padding:5px" onchange="selected['${id}'].copies=parseInt(this.value)||1"></td>
      <td><button type="button" class="btn btn-danger btn-sm" onclick="delete selected['${id}']; renderSelected();">Remove</button></td>
    </tr>`;
  }).join('');
}

function printLabels(){
  const size = document.getElementById('labelSize').value;
  const printArea = document.getElementById('printArea');
  let labelsHtml = '';
  let idx = 0;
  Object.values(selected).forEach(p => {
    for (let c = 0; c < p.copies; c++) {
      labelsHtml += `<div class="label"><div class="lname">${p.name}</div><svg class="bcode" id="bc${idx}"></svg><div class="lprice">₹${p.sale_price}</div></div>`;
      idx++;
    }
  });

  const styles = size === 'thermal'
    ? `@page{size:50mm 25mm;margin:0} body{margin:0} .label{width:50mm;height:25mm;page-break-after:always;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1mm;box-sizing:border-box;font-family:Arial,sans-serif} .lname{font-size:7px;text-align:center;max-height:14px;overflow:hidden;margin-bottom:1px} .lprice{font-size:9px;font-weight:700;margin-top:1px}`
    : `@page{size:A4;margin:10mm} body{margin:0} .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:4mm} .label{border:1px dashed #ccc;padding:3mm;display:flex;flex-direction:column;align-items:center;justify-content:center;font-family:Arial,sans-serif;break-inside:avoid} .lname{font-size:9px;text-align:center;margin-bottom:2px} .lprice{font-size:11px;font-weight:700;margin-top:2px}`;

  const wrapped = size === 'thermal' ? labelsHtml : `<div class="grid">${labelsHtml}</div>`;

  const win = window.open('', '_blank');
  win.document.write(`<!DOCTYPE html><html><head><title>Barcode Labels</title><style>${styles}</style></head><body>${wrapped}</body></html>`);
  win.document.close();

  win.onload = () => {
    const script = win.document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.12.3/JsBarcode.all.min.js';
    script.onload = () => {
      let i = 0;
      Object.values(selected).forEach(p => {
        for (let c = 0; c < p.copies; c++) {
          win.JsBarcode(win.document.getElementById('bc' + i), p.sku, { format: 'CODE128', width: 1.3, height: 30, fontSize: 10, margin: 2 });
          i++;
        }
      });
      setTimeout(() => win.print(), 300);
    };
    win.document.head.appendChild(script);
  };
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
