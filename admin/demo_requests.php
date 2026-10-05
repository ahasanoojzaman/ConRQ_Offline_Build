<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
$pageTitle = 'Demo Requests';
$activeNav = 'demo';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['new','contacted','converted','closed'], true)) {
        $db->prepare("UPDATE demo_requests SET status=? WHERE id=?")->execute([$status, $id]);
    }
    redirect(base_url('admin/demo_requests.php'));
}

$rows = $db->query("SELECT * FROM demo_requests ORDER BY id DESC")->fetchAll();
require __DIR__ . '/partials/header.php';
?>
<div class="page-head"><div><h1>Demo Requests</h1><div class="sub"><?= count($rows) ?> total submissions from the landing page</div></div></div>

<div class="table-wrap">
<table class="data">
  <thead><tr><th>Date</th><th>Name</th><th>Company</th><th>Phone</th><th>Email</th><th>Message</th><th>Status</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7"><div class="empty-state"><h3>No requests yet</h3></div></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
      <td><?= e($r['name']) ?></td>
      <td><?= e($r['company']) ?></td>
      <td><a href="https://wa.me/91<?= e(preg_replace('/\D/','',$r['phone'])) ?>" target="_blank"><?= e($r['phone']) ?></a></td>
      <td><?= e($r['email']) ?></td>
      <td style="max-width:220px"><?= e($r['message']) ?></td>
      <td>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <select name="status" class="form-control" style="padding:4px 8px;font-size:.8rem" onchange="this.form.submit()">
            <?php foreach (['new','contacted','converted','closed'] as $s): ?>
              <option value="<?= $s ?>" <?= $r['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
