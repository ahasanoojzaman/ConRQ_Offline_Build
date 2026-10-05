<?php
/**
 * Run this once from SSH after migrate.php:
 *   php database/seed.php
 *
 * Creates: subscription plans, your Super Admin login, and one
 * demo tenant (with a sample product, party & invoice) so you can
 * see the app working immediately.
 *
 * Re-running this script is safe - it will skip records that already exist.
 *
 * NOTE: on shared hosting, MySQL connections often have a short idle
 * timeout. Because this script pauses to wait for keyboard input, the
 * original connection can time out ("MySQL server has gone away") by
 * the time we're ready to write. To avoid that, we open a FRESH
 * connection immediately before every write, right after any input step.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function fresh_db(): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

function ask(string $prompt, string $default = ''): string
{
    echo $prompt . ($default ? " [$default]" : '') . ': ';
    $line = trim(fgets(STDIN));
    return $line !== '' ? $line : $default;
}

echo "=====================================\n";
echo " ConrQ ERP - First Time Setup Wizard\n";
echo "=====================================\n\n";

// ---------------- Plans ----------------
$db = fresh_db();
$existingPlans = $db->query("SELECT COUNT(*) c FROM plans")->fetch()['c'];
if ($existingPlans == 0) {
    echo "Creating subscription plans...\n";
    $plans = [
        ['Starter', 499.00, 2, 200, '["Invoicing","Inventory","Basic Reports","1 Company"]'],
        ['Growth',  999.00, 5, 1000, '["Everything in Starter","GST Reports","Multi-user","Priority Support"]'],
        ['Business', 2499.00, 15, 999999, '["Everything in Growth","Unlimited Invoices","Advanced Accounting","Custom Branding"]'],
    ];
    $stmt = $db->prepare("INSERT INTO plans (name, price_monthly, max_users, max_invoices_month, features, is_active, sort_order) VALUES (?,?,?,?,?,1,?)");
    foreach ($plans as $i => $p) {
        $stmt->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $i + 1]);
    }
    echo "  -> 3 plans created (Starter 499, Growth 999, Business 2499)\n\n";
} else {
    echo "Plans already exist, skipping.\n\n";
}

// ---------------- Super Admin ----------------
$db = fresh_db();
$existingAdmin = $db->query("SELECT COUNT(*) c FROM admin_users")->fetch()['c'];
if ($existingAdmin == 0) {
    echo "--- Create your Super Admin account ---\n";
    $name = ask('Your name', 'Super Admin');
    $email = ask('Login email', 'admin@remesys.in');
    $password = ask('Password (min 8 chars)', 'ChangeMe@123');

    // Re-open the connection now - the one above may have timed out
    // while we were waiting on keyboard input above.
    $db = fresh_db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO admin_users (name, email, password_hash) VALUES (?,?,?)");
    $stmt->execute([$name, $email, $hash]);
    echo "  -> Super Admin created. Login at /admin/login.php with $email\n\n";
} else {
    echo "Super Admin already exists, skipping.\n\n";
}

// ---------------- Demo Tenant ----------------
$db = fresh_db();
$existingDemo = $db->query("SELECT COUNT(*) c FROM tenants WHERE slug = 'demo'")->fetch()['c'];
if ($existingDemo == 0) {
    echo "Creating demo tenant (company: Demo Retail Co.)...\n";

    $growthPlan = $db->query("SELECT id FROM plans WHERE name = 'Growth' LIMIT 1")->fetch();
    $planId = $growthPlan ? $growthPlan['id'] : null;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO tenants (company_name, slug, owner_name, email, phone, gstin, address, city, state, pincode, plan_id, status, trial_ends_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?, 'active', NULL)");
        $stmt->execute(['Demo Retail Co.', 'demo', 'Demo Owner', 'demo@conrq.krenx.in', '9999999999',
                         '29ABCDE1234F1Z5', '123 MG Road', 'Bengaluru', 'Karnataka', '560001', $planId]);
        $tenantId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO tenant_settings (tenant_id, bank_name, upi_id, terms_conditions, invoice_footer)
                      VALUES (?,?,?,?,?)")
           ->execute([$tenantId, 'Demo Bank Ltd', 'demo@upi', 'Payment due within 15 days.', 'Thank you for your business!']);

        $pass = password_hash('demo1234', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (tenant_id, name, email, phone, password_hash, role) VALUES (?,?,?,?,?, 'owner')")
           ->execute([$tenantId, 'Demo Owner', 'demo@conrq.krenx.in', '9999999999', $pass]);

        // Sample product
        $db->prepare("INSERT INTO products (tenant_id, name, type, sku, hsn_sac, unit, sale_price, purchase_price, tax_rate, opening_stock, current_stock, low_stock_alert)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$tenantId, 'Sample Product A', 'product', 'SKU-001', '1905', 'pcs', 250.00, 180.00, 18.00, 100, 100, 10]);

        // Sample party
        $db->prepare("INSERT INTO parties (tenant_id, type, name, phone, email, gstin, billing_address, state, opening_balance, balance_type)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$tenantId, 'customer', 'Walk-in Customer', '9000000000', null, null, 'Bengaluru', 'Karnataka', 0, 'dr']);

        $db->commit();
        echo "  -> Demo tenant created.\n";
        echo "  -> Login at /login.php with demo@conrq.krenx.in / demo1234\n\n";
    } catch (Throwable $ex) {
        $db->rollBack();
        echo "  !! Failed to create demo tenant: " . $ex->getMessage() . "\n\n";
    }
} else {
    echo "Demo tenant already exists, skipping.\n\n";
}

echo "=====================================\n";
echo " Setup complete!\n";
echo "=====================================\n";
