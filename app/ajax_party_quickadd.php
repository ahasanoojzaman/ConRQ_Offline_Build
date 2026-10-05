<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Invalid request method.'], 405);
}
if (!csrf_verify()) {
    json_out(['error' => 'Session expired, please reload the page and try again.'], 419);
}

$phone = trim($_POST['phone'] ?? '');
$name = trim($_POST['name'] ?? '');
$type = in_array($_POST['type'] ?? 'customer', ['customer', 'supplier'], true) ? $_POST['type'] : 'customer';

if ($phone === '' || !preg_match('/^[0-9+\-\s]{6,15}$/', $phone)) {
    json_out(['error' => 'Please enter a valid phone number.'], 422);
}
if ($name === '') {
    $name = 'Walk-in (' . $phone . ')';
}

// If a party with this exact phone already exists for this tenant, reuse it
// instead of creating a duplicate walk-in record every time.
$existing = $db->prepare("SELECT id, name, phone FROM parties WHERE tenant_id=? AND phone=? AND is_active=1 LIMIT 1");
$existing->execute([$tid, $phone]);
$row = $existing->fetch();
if ($row) {
    json_out(['id' => (int)$row['id'], 'name' => $row['name'], 'phone' => $row['phone'], 'reused' => true]);
}

$stmt = $db->prepare("INSERT INTO parties (tenant_id, type, name, phone, opening_balance, balance_type) VALUES (?,?,?,?,0,'dr')");
$stmt->execute([$tid, $type, $name, $phone]);
$newId = (int)$db->lastInsertId();

json_out(['id' => $newId, 'name' => $name, 'phone' => $phone, 'reused' => false]);
