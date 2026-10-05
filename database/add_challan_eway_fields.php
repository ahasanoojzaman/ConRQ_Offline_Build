<?php
/**
 * Adds Delivery Challan as a document type, and E-Way Bill / transport
 * fields (vehicle no, transport mode, e-way bill no) to invoices - usable
 * on Invoices, Purchase Bills, and Delivery Challans alike.
 *
 * Run once via SSH:
 *   php database/add_challan_eway_fields.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$col = $db->query("SHOW COLUMNS FROM invoices LIKE 'doc_type'")->fetch();
if (!$col || strpos($col['Type'], 'delivery_challan') === false) {
    $db->exec("ALTER TABLE invoices MODIFY COLUMN doc_type
               ENUM('invoice','proforma','quotation','purchase','credit_note','debit_note','online_order','delivery_challan')
               NOT NULL DEFAULT 'invoice'");
    echo "invoices.doc_type now accepts 'delivery_challan'.\n";
} else {
    echo "invoices.doc_type already includes 'delivery_challan'.\n";
}

$check = $db->query("SHOW COLUMNS FROM invoices LIKE 'vehicle_no'")->fetch();
if (!$check) {
    $db->exec("ALTER TABLE invoices
               ADD COLUMN vehicle_no VARCHAR(20) NULL AFTER place_of_supply,
               ADD COLUMN transport_mode ENUM('road','rail','air','ship') NULL AFTER vehicle_no,
               ADD COLUMN eway_bill_no VARCHAR(30) NULL AFTER transport_mode");
    echo "Added vehicle_no, transport_mode, eway_bill_no to invoices.\n";
} else {
    echo "Transport/e-way columns already exist on invoices.\n";
}
