<?php
/**
 * Role-Based Access Control - Part 1: expands users.role beyond
 * owner/admin/staff to include Manager, Accountant, Front Desk and
 * Cashier, and creates the role_permissions table the Owner uses to
 * control what each role can see (Access Control page).
 *
 * Owner and Admin always have full access and are not affected by
 * role_permissions - only the newer, more limited roles are gated by it.
 *
 * Run once via SSH: php database/add_role_permissions.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
if ($col && strpos($col['Type'], 'manager') === false) {
    $db->exec("ALTER TABLE users MODIFY COLUMN role
               ENUM('owner','admin','manager','accountant','front_desk','cashier','staff')
               NOT NULL DEFAULT 'staff'");
    echo "users.role now accepts manager, accountant, front_desk, cashier.\n";
} else {
    echo "users.role already accepts the new roles.\n";
}

$db->exec("CREATE TABLE IF NOT EXISTS role_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    role VARCHAR(30) NOT NULL,
    permission_key VARCHAR(60) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_rp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_role_perm (tenant_id, role, permission_key),
    INDEX idx_rp_tenant_role (tenant_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "role_permissions table ready.\n";
echo "Default permissions will be created automatically the first time each\n";
echo "role is used, or when the Owner opens the Access Control page.\n";
