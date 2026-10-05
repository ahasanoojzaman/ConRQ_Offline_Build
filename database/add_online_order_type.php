<?php
/**
 * Adds 'online_order' as a valid invoices.doc_type, needed for the
 * Storefront feature (customer-placed orders that the shop owner then
 * reviews and converts into a real invoice).
 *
 * Run once via SSH:
 *   php database/add_online_order_type.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$col = $db->query("SHOW COLUMNS FROM invoices LIKE 'doc_type'")->fetch();
if ($col && strpos($col['Type'], 'online_order') !== false) {
    echo "invoices.doc_type already includes 'online_order'. Nothing to do.\n";
    exit(0);
}

$db->exec("ALTER TABLE invoices MODIFY COLUMN doc_type
           ENUM('invoice','proforma','quotation','purchase','credit_note','debit_note','online_order')
           NOT NULL DEFAULT 'invoice'");

echo "invoices.doc_type now accepts 'online_order'.\n";
