<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
enforce_permission($db, Auth::tenantId(), Auth::user()['role'], 'settings', 'Settings');
$tid = Auth::tenantId();
$pageTitle = 'Settings';
$activeNav = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $db->prepare("UPDATE tenants SET company_name=?, gstin=?, address=?, city=?, state=?, pincode=?, phone=?, email=? WHERE id=?")
       ->execute([
        trim($_POST['company_name'] ?? ''), trim($_POST['gstin'] ?? ''), trim($_POST['address'] ?? ''),
        trim($_POST['city'] ?? ''), trim($_POST['state'] ?? ''), trim($_POST['pincode'] ?? ''),
        trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''), $tid,
       ]);

    $db->prepare("UPDATE tenant_settings SET invoice_prefix=?, quotation_prefix=?, proforma_prefix=?, purchase_prefix=?,
                  bank_name=?, bank_account_no=?, bank_ifsc=?, upi_id=?, terms_conditions=?, invoice_footer=?, default_tax_rate=? WHERE tenant_id=?")
       ->execute([
        trim($_POST['invoice_prefix'] ?? 'INV-'), trim($_POST['quotation_prefix'] ?? 'QT-'), trim($_POST['proforma_prefix'] ?? 'PI-'), trim($_POST['purchase_prefix'] ?? 'PUR-'),
        trim($_POST['bank_name'] ?? ''), trim($_POST['bank_account_no'] ?? ''), trim($_POST['bank_ifsc'] ?? ''), trim($_POST['upi_id'] ?? ''),
        trim($_POST['terms_conditions'] ?? ''), trim($_POST['invoice_footer'] ?? ''), (float)($_POST['default_tax_rate'] ?? 18), $tid,
       ]);

    flash('success', 'Settings updated.');
    redirect(base_url('app/settings.php'));
}

$tenantStmt = $db->prepare("SELECT * FROM tenants WHERE id=?");
$tenantStmt->execute([$tid]);
$tenant = $tenantStmt->fetch();

$settingsStmt = $db->prepare("SELECT * FROM tenant_settings WHERE tenant_id=?");
$settingsStmt->execute([$tid]);
$settings = $settingsStmt->fetch();

$planFeatures = tenant_plan_features($db, $tid);
$storefrontUrl = base_url('store.php?shop=' . urlencode($tenant['slug']));

require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1>Settings</h1><div class="sub">Company profile, invoice numbering & payment details</div></div></div>

<?php if ($planFeatures['storefront']): ?>
<div class="card card-pad" style="margin-bottom:20px">
  <h3 style="margin-bottom:10px">Your Online Store</h3>
  <p class="hint" style="margin-bottom:10px">Share this link with customers - they can browse your products and place orders directly, no commission to any third party.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input class="form-control" type="text" readonly value="<?= e($storefrontUrl) ?>" style="max-width:420px" onclick="this.select()">
    <button type="button" class="btn btn-outline btn-sm" onclick="navigator.clipboard.writeText('<?= e($storefrontUrl) ?>').then(()=>{this.textContent='✅ Copied!';setTimeout(()=>this.textContent='Copy Link',1500)})">Copy Link</button>
    <a href="<?= e($storefrontUrl) ?>" target="_blank" class="btn btn-outline btn-sm">Open Store ↗</a>
  </div>
  <p class="hint" style="margin-top:10px">Orders placed here show up under <a href="<?= base_url('app/invoices.php?type=online_order') ?>">Online Orders</a> for you to review and convert to real invoices.</p>
</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2">
    <div class="card card-pad">
      <h3 style="margin-bottom:14px">Company Profile</h3>
      <div class="form-group"><label>Company Name</label><input class="form-control" name="company_name" value="<?= e($tenant['company_name']) ?>" required></div>
      <div class="form-row">
        <div class="form-group"><label>GSTIN</label><input class="form-control" name="gstin" value="<?= e($tenant['gstin']) ?>"></div>
        <div class="form-group"><label>Phone</label><input class="form-control" name="phone" value="<?= e($tenant['phone']) ?>"></div>
      </div>
      <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" value="<?= e($tenant['email']) ?>"></div>
      <div class="form-group"><label>Address</label><textarea class="form-control" name="address" rows="2"><?= e($tenant['address']) ?></textarea></div>
      <div class="form-row">
        <div class="form-group"><label>City</label><input class="form-control" name="city" value="<?= e($tenant['city']) ?>"></div>
        <div class="form-group"><label>State</label><input class="form-control" name="state" value="<?= e($tenant['state']) ?>"></div>
        <div class="form-group"><label>Pincode</label><input class="form-control" name="pincode" value="<?= e($tenant['pincode']) ?>"></div>
      </div>
      <p class="hint">Your State is used to auto-decide CGST+SGST (same state) vs IGST (different state) on every invoice.</p>
    </div>

    <div class="card card-pad">
      <h3 style="margin-bottom:14px">Invoice Numbering</h3>
      <div class="form-row">
        <div class="form-group"><label>Invoice Prefix</label><input class="form-control" name="invoice_prefix" value="<?= e($settings['invoice_prefix']) ?>"></div>
        <div class="form-group"><label>Quotation Prefix</label><input class="form-control" name="quotation_prefix" value="<?= e($settings['quotation_prefix']) ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Proforma Prefix</label><input class="form-control" name="proforma_prefix" value="<?= e($settings['proforma_prefix']) ?>"></div>
        <div class="form-group"><label>Purchase Prefix</label><input class="form-control" name="purchase_prefix" value="<?= e($settings['purchase_prefix']) ?>"></div>
      </div>
      <div class="form-group"><label>Default Tax Rate (%)</label><input class="form-control" type="number" step="0.01" name="default_tax_rate" value="<?= e($settings['default_tax_rate']) ?>"></div>

      <h3 style="margin:20px 0 14px">Payment Details (shown on invoices)</h3>
      <div class="form-row">
        <div class="form-group"><label>Bank Name</label><input class="form-control" name="bank_name" value="<?= e($settings['bank_name']) ?>"></div>
        <div class="form-group"><label>UPI ID</label><input class="form-control" name="upi_id" value="<?= e($settings['upi_id']) ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Account No.</label><input class="form-control" name="bank_account_no" value="<?= e($settings['bank_account_no']) ?>"></div>
        <div class="form-group"><label>IFSC</label><input class="form-control" name="bank_ifsc" value="<?= e($settings['bank_ifsc']) ?>"></div>
      </div>
      <div class="form-group"><label>Default Terms &amp; Conditions</label><textarea class="form-control" name="terms_conditions" rows="2"><?= e($settings['terms_conditions']) ?></textarea></div>
      <div class="form-group"><label>Invoice Footer Note</label><input class="form-control" name="invoice_footer" value="<?= e($settings['invoice_footer']) ?>"></div>
    </div>
  </div>
  <button class="btn btn-primary" style="margin-top:16px">Save Settings</button>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
