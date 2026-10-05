<?php
/**
 * One-time migration: adds the share_token column to an already-deployed
 * database (schema.sql only applies to fresh installs).
 *
 * Run once via SSH:
 *   php database/add_share_token.php
 *
 * Safe to re-run - checks if the column already exists first.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM invoices LIKE 'share_token'")->fetch();
if ($check) {
    echo "Column 'share_token' already exists on invoices. Nothing to do.\n";
    exit(0);
}

$db->exec("ALTER TABLE invoices
           ADD COLUMN share_token VARCHAR(48) NULL COMMENT 'Public read-only share link token' AFTER ref_invoice_id,
           ADD UNIQUE KEY uniq_share_token (share_token)");

echo "Added 'share_token' column and unique index to invoices table.\n";
