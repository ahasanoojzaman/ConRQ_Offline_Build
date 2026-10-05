<?php
/**
 * Multi-location inventory - Part 1: locations table, seeded with a
 * "Main Location" for every existing tenant so nothing changes for
 * single-location tenants until they choose to add more locations.
 *
 * Run once via SSH:
 *   php database/add_locations.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$db->exec("CREATE TABLE IF NOT EXISTS locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_loc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_loc_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "locations table ready.\n";

$tenants = $db->query("SELECT id, company_name FROM tenants")->fetchAll();
$check = $db->prepare("SELECT id FROM locations WHERE tenant_id=? LIMIT 1");
$ins = $db->prepare("INSERT INTO locations (tenant_id, name, is_default, is_active) VALUES (?, 'Main Location', 1, 1)");
$seeded = 0;
foreach ($tenants as $t) {
    $check->execute([$t['id']]);
    if ($check->fetch()) continue;
    $ins->execute([$t['id']]);
    $seeded++;
}
echo "Seeded a default Main Location for $seeded tenant(s) that did not have one yet.\n";
