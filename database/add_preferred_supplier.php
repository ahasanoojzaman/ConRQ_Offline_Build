<?php
/**
 * Adds a preferred_supplier_id to products, used by the Low Stock
 * Reorder Suggestions page to show who to reorder from.
 *
 * Run once via SSH:
 *   php database/add_preferred_supplier.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM products LIKE 'preferred_supplier_id'")->fetch();
if ($check) {
    echo "Column already exists. Nothing to do.\n";
    exit(0);
}

$db->exec("ALTER TABLE products
           ADD COLUMN preferred_supplier_id INT UNSIGNED NULL AFTER low_stock_alert,
           ADD CONSTRAINT fk_prod_supplier FOREIGN KEY (preferred_supplier_id) REFERENCES parties(id) ON DELETE SET NULL");

echo "Added preferred_supplier_id to products.\n";
