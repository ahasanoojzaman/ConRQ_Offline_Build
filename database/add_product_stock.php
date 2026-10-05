<?php
/**
 * Multi-location + batch inventory - Part 3: product_stock, the real
 * per-location (and optionally per-batch) stock ledger. products.current_stock
 * keeps working exactly as before - it becomes an auto-maintained SUM
 * across all product_stock rows, so every existing page that reads it
 * (dashboard, reports, POS, low-stock alerts, reorder suggestions) keeps
 * working unchanged, whether or not a tenant ever adds a second location.
 *
 * Run once via SSH, AFTER add_locations.php and add_batches.php:
 *   php database/add_product_stock.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$db->exec("CREATE TABLE IF NOT EXISTS product_stock (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    batch_id INT UNSIGNED NULL,
    qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    CONSTRAINT fk_ps_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_product_location_batch (product_id, location_id, batch_id),
    INDEX idx_ps_tenant (tenant_id),
    INDEX idx_ps_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "product_stock table ready.\n";

// Seed: give every existing product's current stock to its tenant's
// default location, with no batch (batch_id NULL = "unbatched" stock).
// This makes multi-location math consistent from day one without
// disturbing any existing stock numbers.
$products = $db->query("SELECT id, tenant_id, current_stock FROM products WHERE type='product'")->fetchAll();
$defaultLocStmt = $db->prepare("SELECT id FROM locations WHERE tenant_id=? AND is_default=1 LIMIT 1");
$existsStmt = $db->prepare("SELECT id FROM product_stock WHERE product_id=? AND location_id=? AND batch_id IS NULL");
$insStmt = $db->prepare("INSERT INTO product_stock (tenant_id, product_id, location_id, batch_id, qty) VALUES (?,?,?,NULL,?)");

$seeded = 0;
foreach ($products as $p) {
    $defaultLocStmt->execute([$p['tenant_id']]);
    $loc = $defaultLocStmt->fetch();
    if (!$loc) continue; // tenant has no default location yet - run add_locations.php first
    $existsStmt->execute([$p['id'], $loc['id']]);
    if ($existsStmt->fetch()) continue; // already seeded (safe to re-run)
    $insStmt->execute([$p['tenant_id'], $p['id'], $loc['id'], $p['current_stock']]);
    $seeded++;
}
echo "Seeded product_stock rows for $seeded product(s).\n";
