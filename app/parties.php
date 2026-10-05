<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'parties', 'Customers & Suppliers');
$tid = Auth::tenantId();
$pageTitle = 'Customers & Suppliers';
$activeNav = 'parties';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['customer','supplier','both']) ? $_POST['type'] : 'customer';
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $gstin = trim($_POST['gstin'] ?? '');
        $billing = trim($_POST['billing_address'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $opening = (float)($_POST['opening_balance'] ?? 0);
        $balType = $_POST['balance_type'] === 'cr' ? 'cr' : 'dr';

        if ($name === '') {
            flash('error', 'Name is required.');
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE parties SET name=?,type=?,phone=?,email=?,gstin=?,billing_address=?,state=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$name,$type,$phone,$email,$gstin,$billing,$state,$id,$tid]);
                flash('success', 'Contact updated.');
            } else {
                $stmt = $db->prepare("INSERT INTO parties (tenant_id,type,name,phone,email,gstin,billing_address,state,opening_balance,balance_type) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tid,$type,$name,$phone,$email,$gstin,$billing,$state,$opening,$balType]);
                flash('success', 'Contact added.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE parties SET is_active=0 WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
        flash('success', 'Contact removed.');
    }
    redirect(base_url('app/parties.php'));
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM parties WHERE tenant_id=? AND is_active=1";
$params = [$tid];
if ($search !== '') { $sql .= " AND (name LIKE ? OR phone LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
$sql .= " ORDER BY name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$parties = $stmt->fetchAll();

require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1>Customers &amp; Suppliers</h1><div class="sub"><?= count($parties) ?> contacts</div></div>
  <button class="btn btn-primary" onclick="openForm()">+ Add Contact</button>
</div>

<form method="get" style="margin-bottom:16px;max-width:340px">
  <input class="form-control" type="text" name="q" placeholder="Search by name or phone..." value="<?= e($search) ?>" onchange="this.form.submit()">
</form>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Name</th><th>Type</th><th>Phone</th><th>GSTIN</th><th>State</th><th class="num">Opening Balance</th><th></th></tr></thead>
  <tbody>
  <?php if (!$parties): ?>
    <tr><td colspan="7"><div class="empty-state"><h3>No contacts yet</h3><p>Add a customer or supplier to get started.</p></div></td></tr>
  <?php endif; ?>
  <?php foreach ($parties as $p): ?>
    <tr>
      <td><a href="<?= base_url('app/ledger.php?party_id=' . $p['id']) ?>" style="color:#c8862b;font-weight:600"><?= e($p['name']) ?></a></td>
      <td><?= e(ucfirst($p['type'])) ?></td>
      <td><?= e($p['phone']) ?></td>
      <td><?= e($p['gstin']) ?></td>
      <td><?= e($p['state']) ?></td>
      <td class="num">₹<?= money($p['opening_balance']) ?> <?= e(strtoupper($p['balance_type'])) ?></td>
      <td style="white-space:nowrap">
        <button class="btn btn-outline btn-sm" onclick='openForm(<?= json_encode($p) ?>)'>Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove this contact?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn btn-danger btn-sm">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="formModal" style="display:none;position:fixed;inset:0;background:rgba(22,33,58,.5);z-index:100;align-items:center;justify-content:center;padding:16px">
  <div class="card card-pad" style="max-width:520px;width:100%;max-height:90vh;overflow-y:auto">
    <h3 id="formTitle" style="margin-bottom:16px">Add Contact</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="f_id">
      <div class="form-row">
        <div class="form-group"><label>Name *</label><input class="form-control" name="name" id="f_name" required></div>
        <div class="form-group"><label>Type</label>
          <select class="form-control" name="type" id="f_type">
            <option value="customer">Customer</option>
            <option value="supplier">Supplier</option>
            <option value="both">Both</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Phone</label><input class="form-control" name="phone" id="f_phone"></div>
        <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" id="f_email"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>GSTIN</label><input class="form-control" name="gstin" id="f_gstin"></div>
        <div class="form-group"><label>State</label><input class="form-control" name="state" id="f_state"></div>
      </div>
      <div class="form-group"><label>Billing Address</label><textarea class="form-control" name="billing_address" id="f_addr" rows="2"></textarea></div>
      <div class="form-row" id="openingWrap">
        <div class="form-group"><label>Opening Balance</label><input class="form-control" type="number" step="0.01" name="opening_balance" id="f_open" value="0"></div>
        <div class="form-group"><label>Balance Type</label>
          <select class="form-control" name="balance_type" id="f_bal">
            <option value="dr">They owe you (Dr)</option>
            <option value="cr">You owe them (Cr)</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:10px">
        <button type="submit" class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('formModal').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openForm(p){
  document.getElementById('formModal').style.display='flex';
  const f = {id:'',name:'',type:'customer',phone:'',email:'',gstin:'',billing_address:'',state:'',opening_balance:0,balance_type:'dr'};
  const d = p ? Object.assign(f,p) : f;
  document.getElementById('formTitle').textContent = p ? 'Edit ' + d.name : 'Add Contact';
  document.getElementById('f_id').value = d.id;
  document.getElementById('f_name').value = d.name;
  document.getElementById('f_type').value = d.type;
  document.getElementById('f_phone').value = d.phone||'';
  document.getElementById('f_email').value = d.email||'';
  document.getElementById('f_gstin').value = d.gstin||'';
  document.getElementById('f_state').value = d.state||'';
  document.getElementById('f_addr').value = d.billing_address||'';
  document.getElementById('f_open').value = d.opening_balance;
  document.getElementById('f_bal').value = d.balance_type;
  document.getElementById('openingWrap').style.display = p ? 'none' : 'flex';
}
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
