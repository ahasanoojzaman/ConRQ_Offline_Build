<?php
/**
 * Adds a richer set of sample data to the "demo" tenant so you can
 * actually test search, low-stock alerts, GST split, ledgers, etc.
 *
 * Run once via SSH:
 *   php database/demo_data.php
 *
 * Safe to re-run - it checks for existing rows by name/SKU before inserting.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

function fresh_db(): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

$db = fresh_db();
$tenant = $db->query("SELECT id, state FROM tenants WHERE slug = 'demo'")->fetch();
if (!$tenant) {
    fwrite(STDERR, "Demo tenant not found. Run database/seed.php first.\n");
    exit(1);
}
$tid = (int)$tenant['id'];
echo "Adding sample data to tenant #$tid (Demo Retail Co.)...\n\n";

// ---------------- Products ----------------
$products = [
    // name, sku, hsn, unit, sale_price, purchase_price, tax_rate, opening_stock, low_stock_alert
    ['Basmati Rice 25kg',      'RICE-25',  '1006', 'bag', 1450.00, 1250.00, 5,  40, 10],
    ['Sunflower Oil 15L',      'OIL-15L',  '1512', 'can', 2180.00, 1950.00, 5,  25, 8],
    ['Toor Dal 10kg',          'DAL-10',   '0713', 'bag',  980.00,  850.00, 5,  30, 10],
    ['Refined Wheat Flour 50kg','ATTA-50', '1101', 'bag', 1650.00, 1450.00, 5,   3, 10], // intentionally low stock
    ['Sugar 50kg',             'SUGAR-50', '1701', 'bag', 2050.00, 1850.00, 5,  15, 10],
    ['Tea Powder 1kg',         'TEA-1KG',  '0902', 'pcs',  420.00,  340.00, 5, 120, 20],
    ['Detergent Powder 5kg',   'DET-5KG',  '3402', 'pcs',  580.00,  480.00, 18, 60, 15],
    ['LED Bulb 9W',            'LED-9W',   '8539', 'pcs',  120.00,   80.00, 18, 200, 50],
    ['Notebook 200pg',         'NB-200',   '4820', 'pcs',   60.00,   38.00, 12, 300, 60],
    ['Packing Tape 2in',       'TAPE-2IN', '3919', 'roll',  35.00,   22.00, 18, 5, 20], // intentionally low stock
    ['Bike Delivery Service',  'SVC-BIKE', '9967', 'trip', 150.00,    0.00, 18, 0, 0], // service, no stock tracking
    ['Custom Packaging Design','SVC-DSGN', '9983', 'job', 2500.00,    0.00, 18, 0, 0], // service
];

$checkP = $db->prepare("SELECT id FROM products WHERE tenant_id=? AND sku=?");
$insP = $db->prepare("INSERT INTO products (tenant_id,name,type,sku,hsn_sac,unit,sale_price,purchase_price,tax_rate,opening_stock,current_stock,low_stock_alert)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
$added = 0;
foreach ($products as $p) {
    [$name, $sku, $hsn, $unit, $sale, $purch, $tax, $open, $low] = $p;
    $checkP->execute([$tid, $sku]);
    if ($checkP->fetch()) { continue; }
    $type = str_starts_with($sku, 'SVC-') ? 'service' : 'product';
    $insP->execute([$tid, $name, $type, $sku, $hsn, $unit, $sale, $purch, $tax, $open, $open, $low]);
    $added++;
}
echo "Products added: $added (skipped any that already existed)\n";

// ---------------- Parties ----------------
$parties = [
    // type, name, phone, email, gstin, address, state, opening_balance, balance_type
    ['customer', 'Sharma Traders',        '9830011122', 'sharma.traders@example.com', '19AAAAA0000A1Z5', 'Park Street, Kolkata',   'West Bengal', 5000, 'dr'],
    ['customer', 'Ramesh General Store',  '9830022233', null,                          null,              'MG Road, Malda',         'West Bengal', 0,    'dr'],
    ['customer', 'Priya Distributors',    '9840033344', 'priya.dist@example.com',      '32AAAAA0000A1Z5', 'Kochi',                  'Kerala',      1200, 'dr'],
    ['supplier', 'Bengal FMCG Supplies',  '9830044455', 'orders@bengalfmcg.example.com','19BBBBB0000B1Z5', 'Howrah Industrial Area', 'West Bengal', 3000, 'cr'],
    ['supplier', 'National Packaging Co.','9830055566', null,                          '27CCCCC0000C1Z5', 'Mumbai',                 'Maharashtra', 0,    'cr'],
    ['both',     'Suresh Wholesale Mart', '9830066677', 'suresh.mart@example.com',     '19DDDDD0000D1Z5', 'Siliguri',               'West Bengal', 0,    'dr'],
];

$checkPt = $db->prepare("SELECT id FROM parties WHERE tenant_id=? AND name=?");
$insPt = $db->prepare("INSERT INTO parties (tenant_id,type,name,phone,email,gstin,billing_address,state,opening_balance,balance_type)
                        VALUES (?,?,?,?,?,?,?,?,?,?)");
$added = 0;
foreach ($parties as $p) {
    [$type, $name, $phone, $email, $gstin, $addr, $state, $opening, $balType] = $p;
    $checkPt->execute([$tid, $name]);
    if ($checkPt->fetch()) { continue; }
    $insPt->execute([$tid, $type, $name, $phone, $email, $gstin, $addr, $state, $opening, $balType]);
    $added++;
}
echo "Parties added: $added (skipped any that already existed)\n\n";

echo "Done. Log in at /login.php with demo@conrq.krenx.in / demo1234 and try:\n";
echo "  - New Invoice -> pick 'Sharma Traders' -> search 'rice' or 'oil' in the item box\n";
echo "  - Dashboard -> Low Stock Alerts should show 'Refined Wheat Flour 50kg' and 'Packing Tape 2in'\n";
echo "  - Party Ledger -> select 'Sharma Traders' to see their opening balance\n";
