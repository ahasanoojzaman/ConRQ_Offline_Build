<?php
/**
 * Adds journal voucher numbering fields to tenant_settings (needed for
 * Master Accounting Journal Entries).
 *
 * Run once via SSH:
 *   php database/add_journal_numbering.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM tenant_settings LIKE 'journal_prefix'")->fetch();
if ($check) {
    echo "Journal numbering columns already exist. Nothing to do.\n";
    exit(0);
}

$db->exec("ALTER TABLE tenant_settings
           ADD COLUMN journal_prefix VARCHAR(10) NOT NULL DEFAULT 'JV-' AFTER next_purchase_no,
           ADD COLUMN next_journal_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER journal_prefix");

echo "Added journal_prefix and next_journal_no to tenant_settings.\n";
