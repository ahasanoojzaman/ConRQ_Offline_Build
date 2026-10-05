<?php
/**
 * One-time migration: adds cost_price to invoice_items (needed for
 * accurate historical profit/loss reporting).
 *
 * Run once via SSH:
 *   php database/add_cost_price.php
 *
 * Safe to re-run - checks if the column already exists first.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM invoice_items LIKE 'cost_price'")->fetch();
if ($check) {
    echo "Column 'cost_price' already exists on invoice_items. Nothing to do.\n";
    exit(0);
}

$db->exec("ALTER TABLE invoice_items
           ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0
           COMMENT 'Snapshot of product purchase_price at time of sale, for accurate P&L'
           AFTER rate");

echo "Added 'cost_price' column to invoice_items.\n";
echo "Note: existing invoice line items will show cost_price = 0 (they predate this feature),\n";
echo "so profit/loss reports will only be fully accurate for invoices created from now on.\n";
