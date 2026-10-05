<?php
/**
 * One-time repair: finds cancelled invoices/purchase bills whose stock
 * effect was never reversed (the bug just fixed in the app), and restores
 * the correct stock. Safe to run multiple times - already-repaired
 * documents are detected and skipped automatically.
 *
 * By default this is a DRY RUN - it only prints what it would change.
 * Add --apply to actually make the changes.
 *
 * Usage:
 *   php database/repair_cancelled_stock.php          (preview only)
 *   php database/repair_cancelled_stock.php --apply   (actually fix it)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();
$apply = in_array('--apply', $argv, true);

echo "==================================================\n";
echo $apply ? " Repairing cancelled-document stock (LIVE RUN)\n" : " Checking cancelled-document stock (DRY RUN)\n";
echo "==================================================\n\n";

$invoices = $db->query("SELECT * FROM invoices WHERE status='cancelled' AND doc_type IN ('invoice','purchase') ORDER BY id")->fetchAll();

if (!$invoices) {
    echo "No cancelled invoices/purchase bills found. Nothing to check.\n";
    exit(0);
}

$totalFixed = 0;
$totalSkipped = 0;

foreach ($invoices as $inv) {
    $items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=?");
    $items->execute([$inv['id']]);
    $rows = $items->fetchAll();

    foreach ($rows as $item) {
        if (empty($item['product_id'])) continue;

        // Already repaired? (a repair-generated adjustment always has this
        // invoice_id + product_id combination, which a normal manual stock
        // adjustment from the Products page never has.)
        $already = $db->prepare("SELECT id FROM stock_movements WHERE invoice_id=? AND product_id=? AND movement_type='adjustment' LIMIT 1");
        $already->execute([$inv['id'], $item['product_id']]);
        if ($already->fetch()) {
            $totalSkipped++;
            continue;
        }

        // Find the original, never-reversed movement this cancelled document caused.
        $expectedType = $inv['doc_type'] === 'invoice' ? 'sale' : 'purchase';
        $orig = $db->prepare("SELECT * FROM stock_movements
                               WHERE tenant_id=? AND product_id=? AND movement_type=? AND invoice_id IS NULL
                               AND created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 5 MINUTE)
                               AND ABS(ABS(qty_change) - ?) < 0.001
                               ORDER BY created_at LIMIT 1");
        $orig->execute([$inv['tenant_id'], $item['product_id'], $expectedType, $inv['created_at'], $inv['created_at'], (float)$item['qty']]);
        $origMove = $orig->fetch();

        if (!$origMove) {
            // Nothing found - this item stock effect was either never
            // applied in the first place (e.g. it was a service, or the
            // document was already a draft when cancelled), so there is
            // genuinely nothing to repair here.
            continue;
        }

        $prodStmt = $db->prepare("SELECT name, current_stock FROM products WHERE id=? AND tenant_id=?");
        $prodStmt->execute([$item['product_id'], $inv['tenant_id']]);
        $prod = $prodStmt->fetch();
        if (!$prod) continue;

        $correction = -1 * (float)$origMove['qty_change']; // undo exactly what was applied
        $newStock = (float)$prod['current_stock'] + $correction;

        echo "Invoice {$inv['doc_no']} (cancelled) -> \"{$prod['name']}\": ";
        echo "current stock {$prod['current_stock']} was never restored after cancellation. ";
        echo "Correcting by " . ($correction >= 0 ? '+' : '') . round($correction, 3) . " -> new stock " . round($newStock, 3) . "\n";

        if ($apply) {
            $db->prepare("UPDATE products SET current_stock=? WHERE id=? AND tenant_id=?")
               ->execute([$newStock, $item['product_id'], $inv['tenant_id']]);
            $db->prepare("INSERT INTO stock_movements (tenant_id, product_id, invoice_id, movement_type, qty_change, balance_after) VALUES (?,?,?,?,?,?)")
               ->execute([$inv['tenant_id'], $item['product_id'], $inv['id'], 'adjustment', $correction, $newStock]);
        }
        $totalFixed++;
    }
}

echo "\n==================================================\n";
if ($totalFixed === 0) {
    echo "Nothing to repair - all cancelled documents already have correct stock.\n";
} elseif ($apply) {
    echo "Fixed $totalFixed item(s). ($totalSkipped already-repaired item(s) skipped.)\n";
} else {
    echo "Found $totalFixed item(s) that need repair. Run again with --apply to fix them.\n";
}
echo "==================================================\n";
