-- ============================================================
-- ConrQ ERP - Database Schema
-- Multi-tenant Sales / Inventory / Accounting System
-- Engine: MySQL 5.7+/8, InnoDB, utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- SUPER ADMIN
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PLANS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_users SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    max_invoices_month INT UNSIGNED NOT NULL DEFAULT 100,
    features TEXT NULL COMMENT 'JSON list of feature flags',
    feature_storefront TINYINT(1) NOT NULL DEFAULT 0,
    feature_master_accounting TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TENANTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL,
    slug VARCHAR(60) NOT NULL UNIQUE,
    owner_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    gstin VARCHAR(20) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(80) NULL,
    state VARCHAR(80) NULL,
    pincode VARCHAR(12) NULL,
    plan_id INT UNSIGNED NULL,
    status ENUM('trial','active','suspended','expired') NOT NULL DEFAULT 'trial',
    trial_ends_at DATE NULL,
    subscription_ends_at DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenant_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
    INDEX idx_tenant_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TENANT SETTINGS (1-1 with tenant)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenant_settings (
    tenant_id INT UNSIGNED PRIMARY KEY,
    invoice_prefix VARCHAR(10) NOT NULL DEFAULT 'INV-',
    quotation_prefix VARCHAR(10) NOT NULL DEFAULT 'QT-',
    proforma_prefix VARCHAR(10) NOT NULL DEFAULT 'PI-',
    purchase_prefix VARCHAR(10) NOT NULL DEFAULT 'PUR-',
    next_invoice_no INT UNSIGNED NOT NULL DEFAULT 1,
    next_quotation_no INT UNSIGNED NOT NULL DEFAULT 1,
    next_proforma_no INT UNSIGNED NOT NULL DEFAULT 1,
    next_purchase_no INT UNSIGNED NOT NULL DEFAULT 1,
    journal_prefix VARCHAR(10) NOT NULL DEFAULT 'JV-',
    next_journal_no INT UNSIGNED NOT NULL DEFAULT 1,
    challan_prefix VARCHAR(10) NOT NULL DEFAULT 'DC-',
    next_challan_no INT UNSIGNED NOT NULL DEFAULT 1,
    transfer_prefix VARCHAR(10) NOT NULL DEFAULT 'TR-',
    next_transfer_no INT UNSIGNED NOT NULL DEFAULT 1,
    currency VARCHAR(5) NOT NULL DEFAULT 'INR',
    fy_start_month TINYINT UNSIGNED NOT NULL DEFAULT 4,
    logo_path VARCHAR(255) NULL,
    bank_name VARCHAR(100) NULL,
    bank_account_no VARCHAR(40) NULL,
    bank_ifsc VARCHAR(20) NULL,
    upi_id VARCHAR(80) NULL,
    terms_conditions TEXT NULL,
    invoice_footer VARCHAR(255) NULL,
    default_tax_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
    low_stock_alert_default INT UNSIGNED NOT NULL DEFAULT 5,
    CONSTRAINT fk_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- USERS (tenant staff/owner)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner','admin','manager','accountant','front_desk','cashier','staff') NOT NULL DEFAULT 'staff',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_email (tenant_id, email),
    INDEX idx_user_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PARTIES (customers / suppliers)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    type ENUM('customer','supplier','both') NOT NULL DEFAULT 'customer',
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(150) NULL,
    gstin VARCHAR(20) NULL,
    billing_address VARCHAR(255) NULL,
    shipping_address VARCHAR(255) NULL,
    state VARCHAR(80) NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_type ENUM('dr','cr') NOT NULL DEFAULT 'dr',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_party_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_party_tenant (tenant_id),
    INDEX idx_party_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PRODUCT CATEGORIES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    CONSTRAINT fk_cat_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_cat_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PRODUCTS / SERVICES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    type ENUM('product','service') NOT NULL DEFAULT 'product',
    sku VARCHAR(60) NULL,
    hsn_sac VARCHAR(20) NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
    sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    price_type ENUM('exclusive','inclusive') NOT NULL DEFAULT 'exclusive' COMMENT 'exclusive = tax added on top of sale_price, inclusive = sale_price already includes GST',
    track_batches TINYINT(1) NOT NULL DEFAULT 0,
    purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    purchase_unit VARCHAR(20) NULL,
    purchase_unit_factor DECIMAL(10,3) NOT NULL DEFAULT 1,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    opening_stock DECIMAL(12,3) NOT NULL DEFAULT 0,
    current_stock DECIMAL(12,3) NOT NULL DEFAULT 0,
    low_stock_alert DECIMAL(12,3) NOT NULL DEFAULT 5,
    preferred_supplier_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prod_supplier FOREIGN KEY (preferred_supplier_id) REFERENCES parties(id) ON DELETE SET NULL,
    CONSTRAINT fk_prod_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
    INDEX idx_prod_tenant (tenant_id),
    INDEX idx_prod_name (tenant_id, name),
    FULLTEXT KEY ft_prod_search (name, sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- LOCATIONS (multi-location / warehouse inventory)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_loc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_loc_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PRODUCT BATCHES (lot/expiry tracking)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    batch_no VARCHAR(60) NOT NULL,
    mfg_date DATE NULL,
    expiry_date DATE NULL,
    purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_batch_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_batch_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_batch_product (product_id),
    INDEX idx_batch_expiry (tenant_id, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PRODUCT STOCK (per-location, per-batch stock ledger)
-- products.current_stock is auto-maintained as the SUM of this table.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_stock (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    batch_id INT UNSIGNED NULL,
    qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    CONSTRAINT fk_ps_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_product_location_batch (product_id, location_id, batch_id),
    INDEX idx_ps_tenant (tenant_id),
    INDEX idx_ps_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- STOCK TRANSFERS (between locations)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    transfer_no VARCHAR(30) NOT NULL,
    from_location_id INT UNSIGNED NOT NULL,
    to_location_id INT UNSIGNED NOT NULL,
    transfer_date DATE NOT NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_st_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_st_from FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_st_to FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    UNIQUE KEY uniq_tenant_transfer (tenant_id, transfer_no),
    INDEX idx_st_tenant_date (tenant_id, transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    batch_id INT UNSIGNED NULL,
    qty DECIMAL(12,3) NOT NULL,
    CONSTRAINT fk_sti_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sti_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_sti_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL,
    INDEX idx_sti_transfer (transfer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- INVOICES (covers Invoice / Proforma / Quotation / Purchase)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    doc_type ENUM('invoice','proforma','quotation','purchase','credit_note','debit_note','online_order','delivery_challan') NOT NULL DEFAULT 'invoice',
    doc_no VARCHAR(30) NOT NULL,
    party_id INT UNSIGNED NULL,
    doc_date DATE NOT NULL,
    due_date DATE NULL,
    place_of_supply VARCHAR(80) NULL,
    vehicle_no VARCHAR(20) NULL,
    transport_mode ENUM('road','rail','air','ship') NULL,
    eway_bill_no VARCHAR(30) NULL,
    status ENUM('draft','final','paid','partial','unpaid','cancelled') NOT NULL DEFAULT 'unpaid',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    round_off DECIMAL(6,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes VARCHAR(500) NULL,
    terms VARCHAR(500) NULL,
    ref_invoice_id INT UNSIGNED NULL COMMENT 'e.g. invoice created from quotation, or credit note ref',
    share_token VARCHAR(48) NULL COMMENT 'Random public token so this one document can be viewed/printed without login (for WhatsApp/email links)',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_tenant_doc (tenant_id, doc_type, doc_no),
    UNIQUE KEY uniq_share_token (share_token),
    INDEX idx_inv_tenant_date (tenant_id, doc_date),
    INDEX idx_inv_type (tenant_id, doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- INVOICE ITEMS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    batch_id INT UNSIGNED NULL,
    location_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    hsn_sac VARCHAR(20) NULL,
    qty DECIMAL(12,3) NOT NULL DEFAULT 1,
    unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
    rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Snapshot of the product purchase_price at the time this line was sold, used for accurate profit/loss even if the product cost changes later',
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    cgst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    sgst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    igst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_item_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PAYMENTS (receipts & payouts)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    party_id INT UNSIGNED NULL,
    invoice_id INT UNSIGNED NULL,
    direction ENUM('in','out') NOT NULL DEFAULT 'in',
    mode ENUM('cash','bank','upi','cheque','card','other') NOT NULL DEFAULT 'cash',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_date DATE NOT NULL,
    reference_no VARCHAR(60) NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pay_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    INDEX idx_pay_tenant_date (tenant_id, payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- STOCK MOVEMENTS (lean audit trail for inventory)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NULL,
    batch_id INT UNSIGNED NULL,
    invoice_id INT UNSIGNED NULL,
    movement_type ENUM('sale','purchase','adjustment','opening','transfer') NOT NULL,
    qty_change DECIMAL(12,3) NOT NULL,
    balance_after DECIMAL(12,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_stock_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- EXPENSES (simple accounting - non-inventory spends)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    expense_date DATE NOT NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_exp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_exp_tenant_date (tenant_id, expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- MASTER ACCOUNTING (Chart of Accounts / Journal - Growth+ plans)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(120) NOT NULL,
    type ENUM('asset','liability','equity','income','expense') NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_coa_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_code (tenant_id, code),
    INDEX idx_coa_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entries (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_lines (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ROLE PERMISSIONS (Access Control - which roles can see what)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    role VARCHAR(30) NOT NULL,
    permission_key VARCHAR(60) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_rp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_role_perm (tenant_id, role, permission_key),
    INDEX idx_rp_tenant_role (tenant_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- AUDIT LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- DEMO REQUESTS (from landing page)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS demo_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    company VARCHAR(150) NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(150) NULL,
    message VARCHAR(500) NULL,
    status ENUM('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ACTIVITY / LOGIN LOG (kept minimal for storage economy)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
