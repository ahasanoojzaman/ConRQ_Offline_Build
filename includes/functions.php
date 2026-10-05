<?php
/**
 * Common helper functions.
 */

function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function money(float $amount): string
{
    return number_format($amount, 2);
}

function today(): string
{
    return date('Y-m-d');
}

function flash(string $key, ?string $msg = null)
{
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
        return null;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $val = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $val;
    }
    return null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function require_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_verify()) {
        http_response_code(419);
        die('Your session expired or the request was invalid. Please go back and try again.');
    }
}

function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    return $text ?: 'tenant';
}

/**
 * Generate the next document number for a tenant (invoice/quotation/proforma/purchase)
 * and atomically increments the counter in tenant_settings.
 */
function next_doc_number(PDO $db, int $tenantId, string $docType): string
{
    $map = [
        'invoice'   => ['prefix_col' => 'invoice_prefix',   'next_col' => 'next_invoice_no'],
        'proforma'  => ['prefix_col' => 'proforma_prefix',  'next_col' => 'next_proforma_no'],
        'quotation' => ['prefix_col' => 'quotation_prefix', 'next_col' => 'next_quotation_no'],
        'purchase'  => ['prefix_col' => 'purchase_prefix',  'next_col' => 'next_purchase_no'],
        'credit_note' => ['prefix_col' => 'invoice_prefix', 'next_col' => 'next_invoice_no'],
        'debit_note'  => ['prefix_col' => 'invoice_prefix', 'next_col' => 'next_invoice_no'],
        'journal'     => ['prefix_col' => 'journal_prefix', 'next_col' => 'next_journal_no'],
        'delivery_challan' => ['prefix_col' => 'challan_prefix', 'next_col' => 'next_challan_no'],
        'stock_transfer' => ['prefix_col' => 'transfer_prefix', 'next_col' => 'next_transfer_no'],
    ];
    $cols = $map[$docType] ?? $map['invoice'];

    // If we are already inside a transaction (e.g. called from the invoice
    // save flow), reuse it instead of opening a nested one - PDO/MySQL do not
    // support nested transactions and will throw "There is already an active
    // transaction" if we try.
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $stmt = $db->prepare("SELECT {$cols['prefix_col']} AS prefix, {$cols['next_col']} AS next_no
                               FROM tenant_settings WHERE tenant_id = ? FOR UPDATE");
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Tenant settings not found.');
        }
        $number = $row['next_no'];
        $upd = $db->prepare("UPDATE tenant_settings SET {$cols['next_col']} = {$cols['next_col']} + 1 WHERE tenant_id = ?");
        $upd->execute([$tenantId]);
        if ($ownsTransaction) {
            $db->commit();
        }

        $fy = fy_label();
        return $row['prefix'] . $fy . str_pad((string)$number, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $ex) {
        if ($ownsTransaction) {
            $db->rollBack();
        }
        throw $ex;
    }
}

/** Financial year label like 25-26 (India FY: Apr-Mar). */
function fy_label(): string
{
    $y = (int)date('Y');
    $m = (int)date('n');
    if ($m >= 4) {
        $start = $y;
        $end = $y + 1;
    } else {
        $start = $y - 1;
        $end = $y;
    }
    return substr((string)$start, 2, 2) . substr((string)$end, 2, 2) . '/';
}

/**
 * Calculate GST split (CGST/SGST vs IGST) for a line item.
 * $sameState true => intra-state (CGST+SGST split), false => inter-state (IGST).
 */
function calc_gst(float $taxableAmount, float $rate, bool $sameState): array
{
    $tax = round($taxableAmount * $rate / 100, 2);
    if ($sameState) {
        $half = round($tax / 2, 2);
        return ['cgst' => $half, 'sgst' => $tax - $half, 'igst' => 0.0, 'total_tax' => $tax];
    }
    return ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => $tax, 'total_tax' => $tax];
}

/**
 * Returns the public share token for an invoice, generating and storing
 * one on first use if it does not have one yet. This token is what lets
 * customers view/print a single document via a public link (WhatsApp,
 * email) without ever needing a login.
 */
function get_or_create_share_token(PDO $db, int $invoiceId, ?string $existingToken): string
{
    if ($existingToken) {
        return $existingToken;
    }
    $token = bin2hex(random_bytes(20));
    $db->prepare("UPDATE invoices SET share_token = ? WHERE id = ?")->execute([$token, $invoiceId]);
    return $token;
}

/**
 * Apply (or reverse, with sign=-1) the stock effect of a set of invoice
 * line items for a given document type. Shared by the classic invoice
 * form and the POS screen so stock logic never diverges between them.
 * Only 'invoice' (reduces stock) and 'purchase' (increases stock) affect
 * inventory; proforma/quotation never touch stock.
 */
/**
 * Returns the tenant default location, used whenever a caller does not
 * specify one - this is what keeps every pre-existing single-location
 * tenant working exactly as before, with multi-location being purely
 * additive/opt-in.
 */
function get_default_location(PDO $db, int $tid): ?array
{
    $stmt = $db->prepare("SELECT * FROM locations WHERE tenant_id=? AND is_default=1 AND is_active=1 LIMIT 1");
    $stmt->execute([$tid]);
    $loc = $stmt->fetch();
    if ($loc) return $loc;

    // Defensive fallback (should not normally happen - every tenant gets one
    // at creation, and the migration seeded existing tenants): create it now.
    $db->prepare("INSERT INTO locations (tenant_id, name, is_default, is_active) VALUES (?, 'Main Location', 1, 1)")->execute([$tid]);
    $newId = (int)$db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM locations WHERE id=?");
    $stmt->execute([$newId]);
    return $stmt->fetch() ?: null;
}

/**
 * The single low-level primitive for changing stock. Updates the specific
 * (product, location, batch) row, keeps products.current_stock in sync as
 * the SUM across all locations/batches (so every existing page that reads
 * current_stock keeps working whether or not a tenant uses locations or
 * batches), and logs a stock_movements audit row.
 */
function adjust_product_stock(PDO $db, int $tid, int $productId, ?int $locationId, ?int $batchId, float $qtyChange, string $movementType, ?int $invoiceId = null): void
{
    if ($locationId === null) {
        $loc = get_default_location($db, $tid);
        $locationId = $loc ? (int)$loc['id'] : null;
    }
    if ($locationId === null) return; // no location could be resolved - nothing safe to do

    $db->prepare("INSERT INTO product_stock (tenant_id, product_id, location_id, batch_id, qty) VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)")
       ->execute([$tid, $productId, $locationId, $batchId, $qtyChange]);

    $sumStmt = $db->prepare("SELECT COALESCE(SUM(qty),0) t FROM product_stock WHERE product_id=?");
    $sumStmt->execute([$productId]);
    $newTotal = (float)$sumStmt->fetch()['t'];
    $db->prepare("UPDATE products SET current_stock=? WHERE id=? AND tenant_id=?")->execute([$newTotal, $productId, $tid]);

    $db->prepare("INSERT INTO stock_movements (tenant_id, product_id, location_id, batch_id, invoice_id, movement_type, qty_change, balance_after) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$tid, $productId, $locationId, $batchId, $invoiceId, $movementType, $qtyChange, $newTotal]);
}

/**
 * Applies (or reverses, sign=-1) the stock effect of a set of invoice line
 * items. Each item may optionally carry location_id and batch_id keys -
 * callers that do not know about locations/batches (the vast majority of
 * existing code) simply omit them and get the exact same behavior as
 * before (default location, no batch).
 */
function apply_stock(PDO $db, int $tid, string $docType, array $items, int $sign, ?int $invoiceId = null): void
{
    if (!in_array($docType, ['invoice', 'purchase', 'delivery_challan'], true)) return;
    $direction = in_array($docType, ['invoice', 'delivery_challan'], true) ? -1 : 1;
    $movementType = in_array($docType, ['invoice', 'delivery_challan'], true) ? 'sale' : 'purchase';

    foreach ($items as $it) {
        if (empty($it['product_id'])) continue;
        $stmt = $db->prepare("SELECT type FROM products WHERE id=? AND tenant_id=?");
        $stmt->execute([$it['product_id'], $tid]);
        $prod = $stmt->fetch();
        if (!$prod || $prod['type'] !== 'product') continue;

        $qtyChange = $direction * $sign * (float)$it['qty'];
        $locationId = isset($it['location_id']) && $it['location_id'] ? (int)$it['location_id'] : null;
        $batchId = isset($it['batch_id']) && $it['batch_id'] ? (int)$it['batch_id'] : null;
        adjust_product_stock($db, $tid, (int)$it['product_id'], $locationId, $batchId, $qtyChange, $movementType, $invoiceId);
    }
}

/**
 * Finds or creates a batch for a product (used when receiving stock for a
 * batch-tracked product on a Purchase Bill).
 */
function get_or_create_batch(PDO $db, int $tid, int $productId, string $batchNo, ?string $mfgDate, ?string $expiryDate, float $purchasePrice): int
{
    $stmt = $db->prepare("SELECT id FROM product_batches WHERE tenant_id=? AND product_id=? AND batch_no=? LIMIT 1");
    $stmt->execute([$tid, $productId, $batchNo]);
    $existing = $stmt->fetch();
    if ($existing) return (int)$existing['id'];

    $db->prepare("INSERT INTO product_batches (tenant_id, product_id, batch_no, mfg_date, expiry_date, purchase_price) VALUES (?,?,?,?,?,?)")
       ->execute([$tid, $productId, $batchNo, $mfgDate ?: null, $expiryDate ?: null, $purchasePrice]);
    return (int)$db->lastInsertId();
}

/**
 * Batches with remaining stock for a product, oldest-expiry-first (FEFO -
 * first-expiry-first-out), the standard approach for perishable goods.
 */
function get_available_batches(PDO $db, int $productId, ?int $locationId = null): array
{
    $sql = "SELECT b.*, COALESCE(SUM(ps.qty), 0) available_qty
            FROM product_batches b
            LEFT JOIN product_stock ps ON ps.batch_id = b.id AND ps.product_id = b.product_id" .
            ($locationId ? " AND ps.location_id = ?" : "") . "
            WHERE b.product_id = ?
            GROUP BY b.id
            HAVING available_qty > 0
            ORDER BY (b.expiry_date IS NULL), b.expiry_date ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($locationId ? [$locationId, $productId] : [$productId]);
    return $stmt->fetchAll();
}

/**
 * Determines whether the tenant trial/subscription is in good standing,
 * approaching expiry (warning), or has ended (blocked). Used to show the
 * renewal popup/banner on every tenant-facing page.
 */
function tenant_subscription_status(array $tenant): array
{
    $today = new DateTime(today());
    $blocked = false;
    $warningDaysLeft = null;
    $reason = '';

    if ($tenant['status'] === 'suspended') {
        $blocked = true;
        $reason = 'suspended';
    } elseif ($tenant['status'] === 'expired') {
        $blocked = true;
        $reason = 'expired';
    } elseif ($tenant['status'] === 'trial') {
        if (!empty($tenant['trial_ends_at'])) {
            $end = new DateTime($tenant['trial_ends_at']);
            $daysLeft = (int)$today->diff($end)->format('%r%a');
            if ($daysLeft < 0) {
                $blocked = true;
                $reason = 'trial_ended';
            } elseif ($daysLeft <= 7) {
                $warningDaysLeft = $daysLeft;
            }
        }
    } elseif ($tenant['status'] === 'active') {
        if (!empty($tenant['subscription_ends_at'])) {
            $end = new DateTime($tenant['subscription_ends_at']);
            $daysLeft = (int)$today->diff($end)->format('%r%a');
            if ($daysLeft < 0) {
                $blocked = true;
                $reason = 'subscription_ended';
            } elseif ($daysLeft <= 7) {
                $warningDaysLeft = $daysLeft;
            }
        }
    }

    return ['blocked' => $blocked, 'warning_days_left' => $warningDaysLeft, 'reason' => $reason];
}

/**
 * Returns which paid features the tenant current plan includes, e.g.
 * ['storefront' => true, 'master_accounting' => false].
 */
function tenant_plan_features(PDO $db, int $tenantId): array
{
    $stmt = $db->prepare("SELECT p.feature_storefront, p.feature_master_accounting
                           FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id
                           WHERE t.id = ?");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    return [
        'storefront' => $row ? (bool)$row['feature_storefront'] : false,
        'master_accounting' => $row ? (bool)$row['feature_master_accounting'] : false,
    ];
}

/**
 * Gate an entire page behind a plan feature. If the tenant plan does not
 * include it, renders an upsell notice (inside the normal app layout) and
 * stops the script - callers should place this after partials/header.php
 * is required, so the sidebar/topbar still render normally.
 */
function enforce_feature_gate(PDO $db, int $tenantId, string $feature, string $featureLabel): void
{
    $features = tenant_plan_features($db, $tenantId);
    if (!empty($features[$feature])) {
        return;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($featureLabel) ?> — ConrQ</title>
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body style="margin:0;background:#f2ede1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;font-family:'Inter',-apple-system,sans-serif">
      <div style="background:#fff;border:1px solid #e2ddd0;border-radius:12px;max-width:480px;width:100%;padding:36px 28px;text-align:center">
        <div style="font-size:2.4rem;margin-bottom:10px">🔒</div>
        <h2 style="font-family:'Source Serif 4',Georgia,serif;color:#16213a;margin:0 0 10px"><?= e($featureLabel) ?> is a Growth/Business feature</h2>
        <p style="color:#4a5470;margin:0 0 20px">Your current plan does not include <?= e($featureLabel) ?>. Upgrade your plan to unlock it, or contact us to discuss your options.</p>
        <a href="<?= base_url('index.php#contact') ?>" style="display:inline-block;background:#c8862b;color:#fff;padding:11px 22px;border-radius:6px;font-weight:700;text-decoration:none">Contact Us to Upgrade</a>
        <div style="margin-top:16px"><a href="<?= base_url('app/dashboard.php') ?>" style="color:#8a93ab;font-size:.85rem">← Back to Dashboard</a></div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Call this immediately after Auth::requireLogin() at the very top of
 * every /app/*.php page - BEFORE any POST handling runs. This is what
 * actually prevents a blocked tenant from bypassing the renewal screen
 * by submitting a form directly (the header-embedded version of this
 * check only ever ran on page display, which POST handlers skip since
 * they redirect() before reaching it).
 */
function enforce_subscription_gate(PDO $db, int $tid): void
{
    $stmt = $db->prepare("SELECT company_name, status, trial_ends_at, subscription_ends_at FROM tenants WHERE id=?");
    $stmt->execute([$tid]);
    $tenant = $stmt->fetch();
    if (!$tenant) return;

    $subStatus = tenant_subscription_status($tenant);
    if (!$subStatus['blocked']) return;

    $reasonLabel = [
        'suspended' => 'Your Account Has Been Suspended',
        'expired' => 'Your Subscription Has Ended',
        'trial_ended' => 'Your Free Trial Has Ended',
        'subscription_ended' => 'Your Subscription Has Ended',
    ][$subStatus['reason']] ?? 'Access Paused';
    $qrUrl = base_url('assets/img/renewal-payment-qr.png');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renewal Required — ConrQ</title>
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body style="margin:0;background:#16213a;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;font-family:'Inter',-apple-system,sans-serif">
      <div style="background:#fff;border-radius:14px;max-width:520px;width:100%;padding:32px 28px;text-align:center">
        <div style="font-size:2.6rem;margin-bottom:8px">⏳</div>
        <h2 style="font-family:'Source Serif 4',Georgia,serif;color:#16213a;margin:0 0 8px;font-size:1.5rem"><?= e($reasonLabel) ?></h2>
        <p style="color:#4a5470;margin:0 0 20px;font-size:.95rem">To continue using <?= e($tenant['company_name']) ?>'s ConrQ account, please renew with us.</p>

        <img src="<?= e($qrUrl) ?>" alt="Payment QR Code" style="width:180px;height:180px;object-fit:contain;border:1px solid #e2ddd0;border-radius:10px;padding:8px;margin-bottom:20px">

        <div style="text-align:left;background:#faf7f0;border-radius:10px;padding:16px 18px;margin-bottom:16px;font-size:.88rem;color:#16213a;line-height:1.8">
          <div style="font-weight:700;margin-bottom:6px">Remesys Technologies</div>
          <div>📧 <a href="mailto:<?= e(BRAND_EMAIL) ?>" style="color:#16213a"><?= e(BRAND_EMAIL) ?></a></div>
          <div>📞 <a href="tel:<?= e(BRAND_PHONE) ?>" style="color:#16213a"><?= e(BRAND_PHONE) ?></a></div>
          <div>💬 <a href="https://wa.me/<?= e(BRAND_WHATSAPP) ?>" target="_blank" style="color:#16213a">WhatsApp: +91 91206 19120</a></div>
          <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e2ddd0">
            <div style="font-weight:700;margin-bottom:4px">East Zone (<?= e(BRAND_EAST_ZONE_STATES) ?>)</div>
            <div>📞 <a href="tel:<?= e(BRAND_EAST_ZONE_PHONE) ?>" style="color:#16213a"><?= e(BRAND_EAST_ZONE_PHONE) ?></a> — <?= e(BRAND_EAST_ZONE_HOURS) ?></div>
          </div>
        </div>

        <a href="<?= base_url('logout.php') ?>" style="display:inline-block;color:#8a93ab;font-size:.82rem;text-decoration:underline">Logout</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Creates a sensible default Chart of Accounts for a tenant the first
 * time they open Master Accounting, so they're not starting from a
 * completely blank ledger structure.
 */
function ensure_default_chart_of_accounts(PDO $db, int $tid): void
{
    $count = $db->prepare("SELECT COUNT(*) c FROM chart_of_accounts WHERE tenant_id=?");
    $count->execute([$tid]);
    if ((int)$count->fetch()['c'] > 0) return;

    $defaults = [
        ['1000', 'Cash in Hand', 'asset'],
        ['1010', 'Bank Account', 'asset'],
        ['1100', 'Sundry Debtors (Receivables)', 'asset'],
        ['1200', 'Inventory', 'asset'],
        ['2000', 'Sundry Creditors (Payables)', 'liability'],
        ['2100', 'GST Payable (Output Tax)', 'liability'],
        ['2110', 'GST Input Credit', 'asset'],
        ['3000', "Owner's Capital", 'equity'],
        ['3100', 'Drawings', 'equity'],
        ['4000', 'Sales Revenue', 'income'],
        ['5000', 'Purchases / Cost of Goods Sold', 'expense'],
        ['5100', 'Business Expenses', 'expense'],
    ];
    $stmt = $db->prepare("INSERT INTO chart_of_accounts (tenant_id, code, name, type, is_system) VALUES (?,?,?,?,1)");
    foreach ($defaults as $d) {
        $stmt->execute([$tid, $d[0], $d[1], $d[2]]);
    }
}

/**
 * The permission keys used throughout the app, grouped for display on the
 * Access Control page. Owner and Admin always have every permission and
 * are never restricted by this system - it only applies to the more
 * limited roles below.
 */
function permission_catalog(): array
{
    return [
        'General' => ['dashboard' => 'Dashboard', 'pos' => 'POS Billing'],
        'Sales & Purchase' => ['sales' => 'Invoices, Proforma, Quotations, Challans, Online Orders', 'purchases' => 'Purchase Bills'],
        'Inventory' => ['products' => 'Products, Reorder, Locations, Batches, Barcode Labels'],
        'Contacts' => ['parties' => 'Customers & Suppliers'],
        'Accounting' => ['payments' => 'Payments, Due Reminders, Party Ledger', 'expenses' => 'Expenses', 'gst_export' => 'GST Export', 'daybook' => 'Daybook', 'reports' => 'Reports', 'accounting' => 'Master Accounting'],
        'Setup' => ['settings' => 'Settings'],
    ];
}

/** Default permission presets applied when a role is first used. */
function default_permissions_for_role(string $role): array
{
    $presets = [
        'manager'    => ['dashboard','pos','sales','purchases','products','parties','payments','expenses','gst_export','daybook','reports','accounting','settings'],
        'accountant' => ['dashboard','sales','purchases','payments','expenses','gst_export','daybook','reports','accounting'],
        'front_desk' => ['dashboard','pos','sales','parties'],
        'cashier'    => ['dashboard','pos'],
        'staff'      => ['dashboard','pos'],
    ];
    return $presets[$role] ?? ['dashboard'];
}

/**
 * Makes sure a role has SOME permission rows before it is ever used, so a
 * newly created staff member is never left completely locked out. Safe to
 * call repeatedly - only inserts if nothing exists yet for this role.
 */
function ensure_default_role_permissions(PDO $db, int $tid, string $role): void
{
    if (in_array($role, ['owner', 'admin'], true)) return; // always full access, never gated
    $check = $db->prepare("SELECT COUNT(*) c FROM role_permissions WHERE tenant_id=? AND role=?");
    $check->execute([$tid, $role]);
    if ((int)$check->fetch()['c'] > 0) return;

    $allowed = default_permissions_for_role($role);
    $stmt = $db->prepare("INSERT INTO role_permissions (tenant_id, role, permission_key, allowed) VALUES (?,?,?,1)");
    foreach ($allowed as $key) {
        $stmt->execute([$tid, $role, $key]);
    }
}

/** Whether a role can access a given permission key for this tenant. */
function role_can(PDO $db, int $tid, string $role, string $permissionKey): bool
{
    if (in_array($role, ['owner', 'admin'], true)) return true;
    $stmt = $db->prepare("SELECT allowed FROM role_permissions WHERE tenant_id=? AND role=? AND permission_key=?");
    $stmt->execute([$tid, $role, $permissionKey]);
    $row = $stmt->fetch();
    return $row ? (bool)$row['allowed'] : false;
}

/**
 * Gate an entire page behind a permission key. Call immediately after
 * enforce_subscription_gate(), before any POST processing, for the same
 * anti-bypass reason as the subscription/feature gates.
 */
function enforce_permission(PDO $db, int $tid, string $role, string $permissionKey, string $label): void
{
    ensure_default_role_permissions($db, $tid, $role);
    if (role_can($db, $tid, $role, $permissionKey)) return;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Restricted — ConrQ</title>
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body style="margin:0;background:#f2ede1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;font-family:'Inter',-apple-system,sans-serif">
      <div style="background:#fff;border:1px solid #e2ddd0;border-radius:12px;max-width:440px;width:100%;padding:32px 26px;text-align:center">
        <div style="font-size:2.2rem;margin-bottom:10px">🚫</div>
        <h2 style="font-family:'Source Serif 4',Georgia,serif;color:#16213a;margin:0 0 10px">Access Restricted</h2>
        <p style="color:#4a5470;margin:0 0 20px">Your account does not have access to <?= e($label) ?>. Ask your account owner to grant access from Access Control if you believe this is a mistake.</p>
        <a href="<?= base_url('app/dashboard.php') ?>" style="display:inline-block;background:#c8862b;color:#fff;padding:10px 22px;border-radius:6px;font-weight:700;text-decoration:none">Back to Dashboard</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Records an entry in the audit trail. Cheap, best-effort - failures here
 * should never block the actual business action, so callers can fire and
 * forget.
 */
function log_audit(PDO $db, int $tid, string $action, string $entityType, ?int $entityId, string $label): void
{
    try {
        $user = Auth::user();
        $db->prepare("INSERT INTO audit_log (tenant_id, user_id, user_name, action, entity_type, entity_id, entity_label) VALUES (?,?,?,?,?,?,?)")
           ->execute([$tid, $user['id'] ?? null, $user['name'] ?? 'System', $action, $entityType, $entityId, $label]);
    } catch (Throwable $e) {
        // Never let audit logging break the actual operation.
    }
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function old(string $key, $default = '')
{
    return e($_POST[$key] ?? $default);
}

function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
