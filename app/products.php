<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'products', 'Products & Services');
$tid = Auth::tenantId();
$pageTitle = 'Products & Services';
$activeNav = 'products';

// ---- Handle Create/Update/Delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['product','service']) ? $_POST['type'] : 'product';
        $sku = trim($_POST['sku'] ?? '');
        $hsn = trim($_POST['hsn_sac'] ?? '');
        $unit = trim($_POST['unit'] ?? 'pcs') ?: 'pcs';
        $salePrice = (float)($_POST['sale_price'] ?? 0);
        $priceType = ($_POST['price_type'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
        $purchasePrice = (float)($_POST['purchase_price'] ?? 0);
        $taxRate = (float)($_POST['tax_rate'] ?? 0);
        $openingStock = (float)($_POST['opening_stock'] ?? 0);
        $lowStock = (float)($_POST['low_stock_alert'] ?? 5);
        $trackBatches = isset($_POST['track_batches']) ? 1 : 0;
        $purchaseUnit = trim($_POST['purchase_unit'] ?? '');
        $purchaseUnitFactor = (float)($_POST['purchase_unit_factor'] ?? 1) ?: 1;

        if ($name === '') {
            flash('error', 'Product/service name is required.');
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE products SET name=?, type=?, sku=?, hsn_sac=?, unit=?, sale_price=?, price_type=?, purchase_price=?, tax_rate=?, low_stock_alert=?, track_batches=?, purchase_unit=?, purchase_unit_factor=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$name,$type,$sku,$hsn,$unit,$salePrice,$priceType,$purchasePrice,$taxRate,$lowStock,$trackBatches,$purchaseUnit ?: null,$purchaseUnitFactor,$id,$tid]);
                flash('success', 'Product updated.');
            } else {
                $stmt = $db->prepare("INSERT INTO products (tenant_id,name,type,sku,hsn_sac,unit,sale_price,price_type,purchase_price,tax_rate,opening_stock,current_stock,low_stock_alert,track_batches,purchase_unit,purchase_unit_factor)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?)");
                $stmt->execute([$tid,$name,$type,$sku,$hsn,$unit,$salePrice,$priceType,$purchasePrice,$taxRate,$openingStock,$lowStock,$trackBatches,$purchaseUnit ?: null,$purchaseUnitFactor]);
                $newId = (int)$db->lastInsertId();
                if ($openingStock != 0 && $type === 'product') {
                    adjust_product_stock($db, $tid, $newId, null, null, $openingStock, 'opening');
                }
                flash('success', 'Product added.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE products SET is_active=0 WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
        flash('success', 'Product removed.');
    } elseif ($action === 'adjust_stock') {
        $id = (int)($_POST['id'] ?? 0);
        $newQty = (float)($_POST['new_qty'] ?? 0);
        $stmt = $db->prepare("SELECT current_stock FROM products WHERE id=? AND tenant_id=?");
        $stmt->execute([$id, $tid]);
        $cur = $stmt->fetch();
        if ($cur) {
            $diff = $newQty - (float)$cur['current_stock'];
            adjust_product_stock($db, $tid, $id, null, null, $diff, 'adjustment');
            flash('success', 'Stock adjusted.');
        }
    }
    redirect(base_url('app/products.php'));
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM products WHERE tenant_id=? AND is_active=1";
$params = [$tid];
if ($search !== '') {
    $sql .= " AND (name LIKE ? OR sku LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
$sql .= " ORDER BY name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Total stock value always reflects ALL inventory, regardless of any search filter above.
$valueStmt = $db->prepare("SELECT COALESCE(SUM(current_stock * purchase_price), 0) cost_value,
                                   COALESCE(SUM(current_stock * sale_price), 0) sale_value,
                                   COUNT(*) item_count
                            FROM products WHERE tenant_id=? AND type='product' AND is_active=1 AND current_stock > 0");
$valueStmt->execute([$tid]);
$valueRow = $valueStmt->fetch();
$totalStockCostValue = (float)$valueRow['cost_value'];
$totalStockSaleValue = (float)$valueRow['sale_value'];
$stockedItemCount = (int)$valueRow['item_count'];

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Products &amp; Services</h1><div class="sub"><?= count($products) ?> active items</div></div>
  <button class="btn btn-primary" onclick="openForm()">+ Add Product / Service</button>
</div>

<div class="grid grid-3" style="margin-bottom:20px">
  <div class="card stat accent"><div class="label">Total Stock Value (at cost)</div><div class="value money"><?= money($totalStockCostValue) ?></div></div>
  <div class="card stat"><div class="label">Total Stock Value (at sale price)</div><div class="value money"><?= money($totalStockSaleValue) ?></div></div>
  <div class="card stat"><div class="label">Items In Stock</div><div class="value"><?= $stockedItemCount ?></div></div>
</div>

<form method="get" style="margin-bottom:16px;max-width:340px">
  <input class="form-control" type="text" name="q" placeholder="Search by name or SKU..." value="<?= e($search) ?>" onchange="this.form.submit()">
</form>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Name</th><th>Type</th><th>SKU</th><th>HSN/SAC</th><th class="num">Sale Price</th><th class="num">Tax %</th><th class="num">Stock</th><th></th></tr></thead>
  <tbody>
  <?php if (!$products): ?>
    <tr><td colspan="8"><div class="empty-state"><h3>No products yet</h3><p>Add your first product or service to start invoicing.</p></div></td></tr>
  <?php endif; ?>
  <?php foreach ($products as $p): ?>
    <tr>
      <td><?= e($p['name']) ?></td>
      <td><?= e(ucfirst($p['type'])) ?></td>
      <td><?= e($p['sku']) ?></td>
      <td><?= e($p['hsn_sac']) ?></td>
      <td class="num">₹<?= money($p['sale_price']) ?> <span class="badge <?= $p['price_type']==='inclusive'?'badge-partial':'badge-draft' ?>" style="font-size:.62rem;padding:1px 6px"><?= $p['price_type']==='inclusive' ? 'Incl. GST' : 'Excl. GST' ?></span></td>
      <td class="num"><?= money($p['tax_rate']) ?>%</td>
      <td class="num">
        <?php if ($p['type'] === 'product'): ?>
          <span style="color:<?= $p['current_stock'] <= $p['low_stock_alert'] ? '#b5443a' : 'inherit' ?>">
            <?= rtrim(rtrim(number_format($p['current_stock'],2),'0'),'.') ?> <?= e($p['unit']) ?>
          </span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <button class="btn btn-outline btn-sm" onclick='openForm(<?= json_encode($p) ?>)'>Edit</button>
        <?php if ($p['type']==='product'): ?>
        <button class="btn btn-outline btn-sm" onclick="openStock(<?= (int)$p['id'] ?>,'<?= e($p['name']) ?>',<?= (float)$p['current_stock'] ?>)">Stock</button>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove this item?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn btn-danger btn-sm">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Add/Edit Modal -->
<div id="formModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:520px;width:100%;max-height:90vh;overflow-y:auto">
    <h3 id="formTitle" style="margin-bottom:16px">Add Product / Service</h3>
    <form method="post" id="prodForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="f_id" value="">
      <div class="form-row">
        <div class="form-group"><label>Name *</label><input class="form-control" name="name" id="f_name" required></div>
        <div class="form-group"><label>Type</label>
          <select class="form-control" name="type" id="f_type">
            <option value="product">Product</option>
            <option value="service">Service</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>SKU</label><input class="form-control" name="sku" id="f_sku"></div>
        <div class="form-group"><label>HSN/SAC Code</label><input class="form-control" name="hsn_sac" id="f_hsn"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Unit</label><input class="form-control" name="unit" id="f_unit" value="pcs"></div>
        <div class="form-group"><label>Tax Rate (%)</label><input class="form-control" type="number" step="0.01" name="tax_rate" id="f_tax" value="18"></div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Sale Price *</label>
          <input class="form-control" type="number" step="0.01" name="sale_price" id="f_sale" required>
          <div style="display:flex;gap:6px;margin-top:6px">
            <button type="button" class="btn btn-outline btn-sm price-type-btn" data-val="exclusive" onclick="setPriceType('exclusive')" style="flex:1">Excl. GST</button>
            <button type="button" class="btn btn-outline btn-sm price-type-btn" data-val="inclusive" onclick="setPriceType('inclusive')" style="flex:1">Incl. GST</button>
          </div>
          <input type="hidden" name="price_type" id="f_price_type" value="exclusive">
          <div class="hint" id="priceTypeHint">Tax will be added on top of this price on invoices.</div>
        </div>
        <div class="form-group"><label>Purchase Price</label><input class="form-control" type="number" step="0.01" name="purchase_price" id="f_purchase"></div>
      </div>
      <div class="form-row">
        <div class="form-group" id="opStockWrap"><label>Opening Stock</label><input class="form-control" type="number" step="0.001" name="opening_stock" id="f_opening" value="0"></div>
        <div class="form-group"><label>Low Stock Alert</label><input class="form-control" type="number" step="0.001" name="low_stock_alert" id="f_low" value="5"></div>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-weight:400"><input type="checkbox" name="track_batches" id="f_track_batches" style="width:auto"> Track Batches / Expiry (for perishable or lot-controlled items)</label>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Purchase Unit <span class="hint">(optional, e.g. "Box")</span></label><input class="form-control" name="purchase_unit" id="f_purchase_unit" placeholder="Leave blank if same as base unit"></div>
        <div class="form-group"><label>Units per Purchase Unit</label><input class="form-control" type="number" step="0.001" name="purchase_unit_factor" id="f_purchase_unit_factor" value="1"></div>
      </div>
      <p class="hint" style="margin-top:-6px;margin-bottom:10px">Example: base unit "pcs", purchase unit "Box", 12 units per box - buying 1 Box on a Purchase Bill adds 12 pcs to stock.</p>
      <div style="display:flex;gap:10px;margin-top:10px">
        <button type="submit" class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-outline" onclick="closeForm()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Stock Adjust Modal -->
<div id="stockModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:380px;width:100%">
    <h3 id="stockTitle" style="margin-bottom:16px">Adjust Stock</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="adjust_stock">
      <input type="hidden" name="id" id="s_id">
      <div class="form-group"><label>New Stock Quantity</label><input class="form-control" type="number" step="0.001" name="new_qty" id="s_qty" required></div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary">Update</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('stockModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openForm(p){
  document.getElementById('formModal').style.display='flex';
  const f = {id:'',name:'',type:'product',sku:'',hsn_sac:'',unit:'pcs',tax_rate:18,sale_price:'',price_type:'exclusive',purchase_price:'',opening_stock:0,low_stock_alert:5,track_batches:0,purchase_unit:'',purchase_unit_factor:1};
  const d = p ? Object.assign(f,p) : f;
  document.getElementById('formTitle').textContent = p ? 'Edit ' + d.name : 'Add Product / Service';
  document.getElementById('f_id').value = d.id;
  document.getElementById('f_name').value = d.name;
  document.getElementById('f_type').value = d.type;
  document.getElementById('f_sku').value = d.sku||'';
  document.getElementById('f_hsn').value = d.hsn_sac||'';
  document.getElementById('f_unit').value = d.unit||'pcs';
  document.getElementById('f_tax').value = d.tax_rate;
  document.getElementById('f_sale').value = d.sale_price;
  document.getElementById('f_purchase').value = d.purchase_price;
  document.getElementById('f_opening').value = d.opening_stock;
  document.getElementById('f_low').value = d.low_stock_alert;
  document.getElementById('f_track_batches').checked = !!(parseInt(d.track_batches) === 1);
  document.getElementById('f_purchase_unit').value = d.purchase_unit || '';
  document.getElementById('f_purchase_unit_factor').value = d.purchase_unit_factor || 1;
  document.getElementById('opStockWrap').style.display = p ? 'none' : 'block';
  setPriceType(d.price_type || 'exclusive');
}
function setPriceType(val){
  document.getElementById('f_price_type').value = val;
  document.querySelectorAll('.price-type-btn').forEach(b => {
    const active = b.dataset.val === val;
    b.classList.toggle('btn-navy', active);
    b.style.background = active ? '#16213a' : '';
    b.style.color = active ? '#fff' : '';
  });
  document.getElementById('priceTypeHint').textContent = val === 'inclusive'
    ? 'This price already includes GST - tax will be back-calculated on invoices.'
    : 'Tax will be added on top of this price on invoices.';
}
function closeForm(){ document.getElementById('formModal').style.display='none'; }
function openStock(id,name,qty){
  document.getElementById('stockModal').style.display='flex';
  document.getElementById('stockTitle').textContent = 'Adjust Stock — ' + name;
  document.getElementById('s_id').value = id;
  document.getElementById('s_qty').value = qty;
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
