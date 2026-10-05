<?php
/**
 * Adds Delivery Challan numbering fields to tenant_settings.
 * Run once via SSH:
 *   php database/add_challan_numbering.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM tenant_settings LIKE 'challan_prefix'")->fetch();
if ($check) {
    echo "Column already exists. Nothing to do.\n";
    exit(0);
}

$db->exec("ALTER TABLE tenant_settings
           ADD COLUMN challan_prefix VARCHAR(10) NOT NULL DEFAULT 'DC-' AFTER next_journal_no,
           ADD COLUMN next_challan_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER challan_prefix");

echo "Added challan_prefix and next_challan_no to tenant_settings.\n";
