<?php
/**
 * Creates the audit_log table - tracks who created, updated, cancelled or
 * deleted key records (invoices, products, parties, payments, users).
 *
 * Run once via SSH: php database/add_audit_log.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$db->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(100) NULL,
    action ENUM('create','update','delete','cancel') NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id INT UNSIGNED NULL,
    entity_label VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_audit_tenant_date (tenant_id, created_at),
    INDEX idx_audit_entity (tenant_id, entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "audit_log table ready.\n";
