<?php
/**
 * Multi-location inventory - Part 4: stock transfers between locations.
 * Run once via SSH: php database/add_stock_transfers.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$db->exec("CREATE TABLE IF NOT EXISTS stock_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    transfer_no VARCHAR(30) NOT NULL,
    from_location_id INT UNSIGNED NOT NULL,
    to_location_id INT UNSIGNED NOT NULL,
    transfer_date DATE NOT NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_st_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_st_from FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_st_to FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    UNIQUE KEY uniq_tenant_transfer (tenant_id, transfer_no),
    INDEX idx_st_tenant_date (tenant_id, transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS stock_transfer_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    batch_id INT UNSIGNED NULL,
    qty DECIMAL(12,3) NOT NULL,
    CONSTRAINT fk_sti_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sti_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_sti_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL,
    INDEX idx_sti_transfer (transfer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "stock_transfers and stock_transfer_items tables ready.\n";

$check = $db->query("SHOW COLUMNS FROM tenant_settings LIKE 'transfer_prefix'")->fetch();
if (!$check) {
    $db->exec("ALTER TABLE tenant_settings
               ADD COLUMN transfer_prefix VARCHAR(10) NOT NULL DEFAULT 'TR-' AFTER next_challan_no,
               ADD COLUMN next_transfer_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER transfer_prefix");
    echo "Added transfer numbering fields to tenant_settings.\n";
} else {
    echo "Transfer numbering fields already exist.\n";
}
