<?php
/**
 * Batch/lot tracking with expiry dates - Part 2: product_batches table
 * and a track_batches flag on products (opt-in per product, so simple
 * non-perishable goods are not cluttered with batch fields).
 *
 * Run once via SSH:
 *   php database/add_batches.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$db->exec("CREATE TABLE IF NOT EXISTS product_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    batch_no VARCHAR(60) NOT NULL,
    mfg_date DATE NULL,
    expiry_date DATE NULL,
    purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_batch_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_batch_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_batch_product (product_id),
    INDEX idx_batch_expiry (tenant_id, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "product_batches table ready.\n";

$check = $db->query("SHOW COLUMNS FROM products LIKE 'track_batches'")->fetch();
if (!$check) {
    $db->exec("ALTER TABLE products ADD COLUMN track_batches TINYINT(1) NOT NULL DEFAULT 0 AFTER price_type");
    echo "Added track_batches column to products.\n";
} else {
    echo "track_batches column already exists.\n";
}
