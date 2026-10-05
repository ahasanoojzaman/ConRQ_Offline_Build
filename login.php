<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    redirect(base_url('app/dashboard.php'));
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (Auth::attempt($db, $email, $password)) {
        redirect(base_url('app/dashboard.php'));
    } else {
        $error = 'Incorrect email or password, or your account is not active.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — ConrQ</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand">Conr<span style="color:#c8862b">Q</span></div>
    <div class="tag">Sign in to your account</div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Email</label>
        <input class="form-control" type="email" name="email" required autofocus value="<?= old('email') ?>">
      </div>
      <div class="form-group">
        <label>Password</label>
        <input class="form-control" type="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Sign In</button>
    </form>
    <p style="text-align:center;margin-top:18px;font-size:.85rem;color:#4a5470">
      New here? <a href="index.php#contact" style="color:#c8862b;font-weight:600">Request a demo</a>
    </p>
    <p style="text-align:center;margin-top:8px;font-size:.78rem;color:#9aa3ba">
      Try it: demo@conrq.krenx.in / demo1234
    </p>
  </div>
</div>
</body>
</html>
