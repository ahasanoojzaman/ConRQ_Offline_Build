<?php
/**
 * Unit conversion - e.g. purchase in "Box" of 12, stock/sell tracked in
 * "pcs". Adds an optional secondary purchase unit + conversion factor
 * per product. Leaving purchase_unit blank means no conversion (business
 * as usual - buy and sell in the same unit, exactly like before).
 *
 * Run once via SSH: php database/add_unit_conversion.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM products LIKE 'purchase_unit'")->fetch();
if ($check) {
    echo "Unit conversion columns already exist. Nothing to do.\n";
    exit(0);
}

$db->exec("ALTER TABLE products
           ADD COLUMN purchase_unit VARCHAR(20) NULL AFTER unit,
           ADD COLUMN purchase_unit_factor DECIMAL(10,3) NOT NULL DEFAULT 1 AFTER purchase_unit");

echo "Added purchase_unit and purchase_unit_factor to products.\n";
echo "Example: unit='pcs', purchase_unit='Box', purchase_unit_factor=12 means\n";
echo "1 Box purchased = 12 pcs added to stock.\n";
