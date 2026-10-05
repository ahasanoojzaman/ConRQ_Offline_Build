<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::adminLogout();
redirect(base_url('admin/login.php'));
