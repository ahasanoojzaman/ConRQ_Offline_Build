<?php
/**
 * Creates the Master Accounting tables: Chart of Accounts, Journal
 * Entries, and Journal Lines (proper double-entry bookkeeping tools,
 * alongside - not replacing - the existing simplified party ledger /
 * payments / expenses system).
 *
 * Run once via SSH:
 *   php database/add_master_accounting.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$db->exec("CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(120) NOT NULL,
    type ENUM('asset','liability','equity','income','expense') NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'default accounts created automatically, cannot be deleted',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_coa_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_code (tenant_id, code),
    INDEX idx_coa_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS journal_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    entry_no VARCHAR(30) NOT NULL,
    entry_date DATE NOT NULL,
    narration VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_je_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_entry (tenant_id, entry_no),
    INDEX idx_je_tenant_date (tenant_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS journal_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    debit DECIMAL(12,2) NOT NULL DEFAULT 0,
    credit DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_jl_entry FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id) ON DELETE RESTRICT,
    INDEX idx_jl_entry (entry_id),
    INDEX idx_jl_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "Master Accounting tables ready: chart_of_accounts, journal_entries, journal_lines.\n";
echo "Default accounts will be created automatically the first time each tenant opens Master Accounting.\n";
