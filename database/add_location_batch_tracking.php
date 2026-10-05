<?php
/**
 * Adds location_id + batch_id to stock_movements (proper audit trail)
 * and batch_id to invoice_items (which batch a sale/purchase line used).
 *
 * Run once via SSH: php database/add_location_batch_tracking.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM stock_movements LIKE 'location_id'")->fetch();
if (!$check) {
    $db->exec("ALTER TABLE stock_movements
               ADD COLUMN location_id INT UNSIGNED NULL AFTER product_id,
               ADD COLUMN batch_id INT UNSIGNED NULL AFTER location_id");
    echo "Added location_id and batch_id to stock_movements.\n";
} else {
    echo "stock_movements location/batch columns already exist.\n";
}

$typeCol = $db->query("SHOW COLUMNS FROM stock_movements LIKE 'movement_type'")->fetch();
if ($typeCol && strpos($typeCol['Type'], 'transfer') === false) {
    $db->exec("ALTER TABLE stock_movements MODIFY COLUMN movement_type ENUM('sale','purchase','adjustment','opening','transfer') NOT NULL");
    echo "stock_movements.movement_type now accepts 'transfer'.\n";
} else {
    echo "stock_movements.movement_type already accepts 'transfer'.\n";
}

$check2 = $db->query("SHOW COLUMNS FROM invoice_items LIKE 'batch_id'")->fetch();
if (!$check2) {
    $db->exec("ALTER TABLE invoice_items ADD COLUMN batch_id INT UNSIGNED NULL AFTER product_id");
    echo "Added batch_id to invoice_items.\n";
} else {
    echo "invoice_items.batch_id already exists.\n";
}

$check3 = $db->query("SHOW COLUMNS FROM invoice_items LIKE 'location_id'")->fetch();
if (!$check3) {
    $db->exec("ALTER TABLE invoice_items ADD COLUMN location_id INT UNSIGNED NULL AFTER batch_id");
    echo "Added location_id to invoice_items.\n";
} else {
    echo "invoice_items.location_id already exists.\n";
}
