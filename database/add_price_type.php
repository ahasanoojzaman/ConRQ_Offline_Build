<?php
/**
 * One-time migration: adds the price_type column (GST inclusive/exclusive
 * pricing) to an already-deployed database.
 *
 * Run once via SSH:
 *   php database/add_price_type.php
 *
 * Safe to re-run - checks if the column already exists first.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM products LIKE 'price_type'")->fetch();
if ($check) {
    echo "Column 'price_type' already exists on products. Nothing to do.\n";
    exit(0);
}

$db->exec("ALTER TABLE products
           ADD COLUMN price_type ENUM('exclusive','inclusive') NOT NULL DEFAULT 'exclusive'
           COMMENT 'exclusive = tax added on top of sale_price; inclusive = sale_price already includes GST'
           AFTER sale_price");

echo "Added 'price_type' column to products table (all existing products default to 'exclusive', i.e. no change in behavior).\n";
