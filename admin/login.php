<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (Auth::adminCheck()) {
    redirect(base_url('admin/dashboard.php'));
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (Auth::adminAttempt($db, $email, $password)) {
        redirect(base_url('admin/dashboard.php'));
    } else {
        $error = 'Incorrect email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin Login — ConrQ</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand">Conr<span style="color:#c8862b">Q</span></div>
    <div class="tag">Super Admin Console</div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" required autofocus></div>
      <div class="form-group"><label>Password</label><input class="form-control" type="password" name="password" required></div>
      <button type="submit" class="btn btn-primary btn-block">Sign In</button>
    </form>
  </div>
</div>
</body>
</html>
