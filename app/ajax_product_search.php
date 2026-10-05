<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
enforce_subscription_gate($db, Auth::tenantId());
$tid = Auth::tenantId();

$q = trim($_GET['q'] ?? '');
$cols = "id, name, sku, hsn_sac, unit, sale_price, price_type, purchase_price, tax_rate, current_stock, type, track_batches, purchase_unit, purchase_unit_factor";

if ($q === '') {
    // No search term: return a full browsable list (used by the POS grid on
    // load, and the "Browse Products" picker on the classic invoice form).
    $stmt = $db->prepare("SELECT $cols FROM products WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC LIMIT 300");
    $stmt->execute([$tid]);
    json_out(['items' => $stmt->fetchAll()]);
}

$stmt = $db->prepare("SELECT $cols FROM products WHERE tenant_id = ? AND is_active = 1 AND (name LIKE ? OR sku LIKE ?)
                       ORDER BY name ASC LIMIT 50");
$like = "%$q%";
$stmt->execute([$tid, $like, $like]);
json_out(['items' => $stmt->fetchAll()]);
