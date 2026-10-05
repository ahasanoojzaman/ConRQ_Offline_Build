<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
$pageTitle = 'Tenant';
$activeNav = 'tenants';

$id = (int)($_GET['id'] ?? 0);
$tenant = null;
$owner = null;
if ($id) {
    $stmt = $db->prepare("SELECT * FROM tenants WHERE id=?");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    if (!$tenant) { flash('error', 'Tenant not found.'); redirect(base_url('admin/tenants.php')); }
    $ownStmt = $db->prepare("SELECT * FROM users WHERE tenant_id=? AND role='owner' LIMIT 1");
    $ownStmt->execute([$id]);
    $owner = $ownStmt->fetch();
}
$plans = $db->query("SELECT * FROM plans WHERE is_active=1 ORDER BY sort_order")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $companyName = trim($_POST['company_name'] ?? '');
    $ownerName = trim($_POST['owner_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gstin = trim($_POST['gstin'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $planId = (int)($_POST['plan_id'] ?? 0) ?: null;
    $status = $_POST['status'] ?? 'trial';
    $trialEnds = $_POST['trial_ends_at'] ?? null;
    $subEnds = $_POST['subscription_ends_at'] ?? null;
    $loginPassword = $_POST['login_password'] ?? '';

    if ($companyName === '' || $ownerName === '' || $email === '') {
        $errors[] = 'Company name, owner name and email are required.';
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            if ($id) {
                $stmt = $db->prepare("UPDATE tenants SET company_name=?, owner_name=?, email=?, phone=?, gstin=?, address=?, city=?, state=?, pincode=?, plan_id=?, status=?, trial_ends_at=?, subscription_ends_at=? WHERE id=?");
                $stmt->execute([$companyName,$ownerName,$email,$phone,$gstin,$address,$city,$state,$pincode,$planId,$status,$trialEnds?:null,$subEnds?:null,$id]);
                $tenantId = $id;

                if ($loginPassword !== '' && $owner) {
                    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($loginPassword, PASSWORD_DEFAULT), $owner['id']]);
                }
                flash('success', 'Tenant updated.');
            } else {
                $slugBase = slugify($companyName);
                $slug = $slugBase;
                $n = 1;
                $checkStmt = $db->prepare("SELECT COUNT(*) c FROM tenants WHERE slug=?");
                do {
                    $checkStmt->execute([$slug]);
                    if ($checkStmt->fetch()['c'] == 0) break;
                    $slug = $slugBase . '-' . (++$n);
                } while (true);

                $stmt = $db->prepare("INSERT INTO tenants (company_name, slug, owner_name, email, phone, gstin, address, city, state, pincode, plan_id, status, trial_ends_at, subscription_ends_at)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$companyName,$slug,$ownerName,$email,$phone,$gstin,$address,$city,$state,$pincode,$planId,$status,$trialEnds?:null,$subEnds?:null]);
                $tenantId = (int)$db->lastInsertId();

                $db->prepare("INSERT INTO tenant_settings (tenant_id) VALUES (?)")->execute([$tenantId]);
                $db->prepare("INSERT INTO locations (tenant_id, name, is_default, is_active) VALUES (?, 'Main Location', 1, 1)")->execute([$tenantId]);

                $pwd = $loginPassword !== '' ? $loginPassword : substr(bin2hex(random_bytes(4)), 0, 8);
                $db->prepare("INSERT INTO users (tenant_id, name, email, phone, password_hash, role) VALUES (?,?,?,?,?, 'owner')")
                   ->execute([$tenantId, $ownerName, $email, $phone, password_hash($pwd, PASSWORD_DEFAULT)]);

                flash('success', "Tenant created. Owner login: $email / $pwd (share this with the client securely).");
            }
            $db->commit();
            redirect(base_url('admin/tenant_form.php?id=' . $tenantId));
        } catch (Throwable $ex) {
            $db->rollBack();
            $errors[] = 'Could not save tenant: ' . $ex->getMessage();
        }
    }
}

require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1><?= $id ? 'Manage Tenant' : 'New Tenant' ?></h1></div>
<a href="<?= base_url('admin/tenants.php') ?>" class="btn btn-outline">← Back to Tenants</a></div>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2">
    <div class="card card-pad">
      <h3 style="margin-bottom:14px">Company Details</h3>
      <div class="form-group"><label>Company Name *</label><input class="form-control" name="company_name" value="<?= e($tenant['company_name'] ?? '') ?>" required></div>
      <div class="form-row">
        <div class="form-group"><label>GSTIN</label><input class="form-control" name="gstin" value="<?= e($tenant['gstin'] ?? '') ?>"></div>
        <div class="form-group"><label>Phone</label><input class="form-control" name="phone" value="<?= e($tenant['phone'] ?? '') ?>"></div>
      </div>
      <div class="form-group"><label>Address</label><textarea class="form-control" name="address" rows="2"><?= e($tenant['address'] ?? '') ?></textarea></div>
      <div class="form-row">
        <div class="form-group"><label>City</label><input class="form-control" name="city" value="<?= e($tenant['city'] ?? '') ?>"></div>
        <div class="form-group"><label>State</label><input class="form-control" name="state" value="<?= e($tenant['state'] ?? '') ?>"></div>
        <div class="form-group"><label>Pincode</label><input class="form-control" name="pincode" value="<?= e($tenant['pincode'] ?? '') ?>"></div>
      </div>
    </div>

    <div class="card card-pad">
      <h3 style="margin-bottom:14px">Owner Login &amp; Plan</h3>
      <div class="form-group"><label>Owner Name *</label><input class="form-control" name="owner_name" value="<?= e($tenant['owner_name'] ?? '') ?>" required></div>
      <div class="form-group"><label>Login Email *</label><input class="form-control" type="email" name="email" value="<?= e($tenant['email'] ?? '') ?>" required></div>
      <div class="form-group"><label><?= $id ? 'Reset Password (leave blank to keep unchanged)' : 'Set Password (leave blank to auto-generate)' ?></label><input class="form-control" type="text" name="login_password" placeholder="e.g. Welcome@123"></div>

      <div class="form-row">
        <div class="form-group"><label>Plan</label>
          <select class="form-control" name="plan_id">
            <option value="">-- No plan --</option>
            <?php foreach ($plans as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (($tenant['plan_id'] ?? null) == $p['id']) ? 'selected' : '' ?>><?= e($p['name']) ?> — ₹<?= money($p['price_monthly']) ?>/mo</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Status</label>
          <select class="form-control" name="status">
            <?php foreach (['trial','active','suspended','expired'] as $s): ?>
              <option value="<?= $s ?>" <?= (($tenant['status'] ?? 'trial')===$s)?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Trial Ends</label><input class="form-control" type="date" name="trial_ends_at" value="<?= e($tenant['trial_ends_at'] ?? '') ?>"></div>
        <div class="form-group"><label>Subscription Ends</label><input class="form-control" type="date" name="subscription_ends_at" value="<?= e($tenant['subscription_ends_at'] ?? '') ?>"></div>
      </div>
      <?php if ($tenant): ?>
        <p class="hint">Tenant login URL: <a href="<?= base_url('login.php') ?>" target="_blank"><?= base_url('login.php') ?></a></p>
      <?php endif; ?>
    </div>
  </div>
  <button class="btn btn-primary" style="margin-top:16px"><?= $id ? 'Save Changes' : 'Create Tenant' ?></button>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
