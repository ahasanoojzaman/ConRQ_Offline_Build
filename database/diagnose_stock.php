<?php
/**
 * Diagnose why a specific invoice may not have deducted stock correctly.
 *
 * Usage (run via SSH):
 *   php database/diagnose_stock.php <invoice_id>
 *   php database/diagnose_stock.php --recent      (checks the 5 most recent sales invoices)
 *   php database/diagnose_stock.php --product "Basmati Rice 25kg"   (full history for one product)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();
$arg = $argv[1] ?? null;

function show_invoice(PDO $db, int $invoiceId): void
{
    $inv = $db->prepare("SELECT * FROM invoices WHERE id=?");
    $inv->execute([$invoiceId]);
    $invoice = $inv->fetch();
    if (!$invoice) {
        echo "Invoice #$invoiceId not found.\n";
        return;
    }

    echo "----------------------------------------------------------\n";
    echo "Invoice #{$invoice['id']}  {$invoice['doc_no']}  ({$invoice['doc_type']}, status={$invoice['status']})\n";
    echo "Tenant: {$invoice['tenant_id']}   Date: {$invoice['doc_date']}   Created: {$invoice['created_at']}\n";
    echo "----------------------------------------------------------\n";

    $items = $db->prepare("SELECT ii.*, p.name AS product_name, p.type AS product_type, p.current_stock
                            FROM invoice_items ii LEFT JOIN products p ON p.id = ii.product_id
                            WHERE ii.invoice_id = ?");
    $items->execute([$invoiceId]);
    $rows = $items->fetchAll();

    if (!$rows) {
        echo "  No line items found for this invoice.\n";
        return;
    }

    foreach ($rows as $r) {
        echo "  Line: \"{$r['description']}\"  qty={$r['qty']}\n";
        if (empty($r['product_id'])) {
            echo "    -> product_id is EMPTY. This line is NOT linked to any product in inventory,\n";
            echo "       so stock could not have been touched for this line. This is the #1 cause\n";
            echo "       of 'stock did not update' - the item was typed but a product row was never\n";
            echo "       created or matched for it.\n";
        } else {
            echo "    -> linked to product #{$r['product_id']}: \"" . ($r['product_name'] ?? '[DELETED PRODUCT]') . "\"\n";
            echo "       product type: " . ($r['product_type'] ?? '?') . " (stock only tracked for type=product, not service)\n";
            echo "       product current_stock right now: " . ($r['current_stock'] ?? '?') . "\n";
        }
    }

    echo "\n  Stock movements logged against this invoice's products around that time:\n";
    $moves = $db->prepare("SELECT sm.*, p.name AS product_name FROM stock_movements sm
                            LEFT JOIN products p ON p.id = sm.product_id
                            WHERE sm.tenant_id = ? AND sm.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 5 MINUTE)
                            ORDER BY sm.created_at");
    $moves->execute([$invoice['tenant_id'], $invoice['created_at'], $invoice['created_at']]);
    $moveRows = $moves->fetchAll();
    if (!$moveRows) {
        echo "    (none found in that time window - if the line items above ARE linked to real\n";
        echo "     products of type 'product', this means apply_stock() never ran for this invoice\n";
        echo "     at all, which would point to a save-flow bug rather than a data-linking issue.)\n";
    } else {
        foreach ($moveRows as $m) {
            echo "    " . $m['created_at'] . "  " . ($m['product_name'] ?? '?') . "  {$m['movement_type']}  change={$m['qty_change']}  balance_after={$m['balance_after']}\n";
        }
    }
    echo "\n";
}

if ($arg === '--recent') {
    $rows = $db->query("SELECT id FROM invoices WHERE doc_type='invoice' ORDER BY id DESC LIMIT 5")->fetchAll();
    if (!$rows) { echo "No invoices found.\n"; exit; }
    foreach ($rows as $r) { show_invoice($db, (int)$r['id']); }
} elseif ($arg === '--product') {
    $name = $argv[2] ?? '';
    if ($name === '') { echo "Usage: php database/diagnose_stock.php --product \"Product Name\"\n"; exit(1); }
    $p = $db->prepare("SELECT * FROM products WHERE name LIKE ?");
    $p->execute(["%$name%"]);
    $prod = $p->fetch();
    if (!$prod) { echo "No product matching '$name' found.\n"; exit; }
    echo "Product #{$prod['id']}: {$prod['name']}  current_stock={$prod['current_stock']}  opening_stock={$prod['opening_stock']}\n\n";
    $moves = $db->prepare("SELECT * FROM stock_movements WHERE product_id=? ORDER BY created_at");
    $moves->execute([$prod['id']]);
    $rows = $moves->fetchAll();
    if (!$rows) {
        echo "No stock movements recorded for this product at all - it has never been sold,\n";
        echo "purchased, or adjusted since it was created (or before this feature existed).\n";
    }
    foreach ($rows as $m) {
        echo "  {$m['created_at']}  {$m['movement_type']}  change={$m['qty_change']}  balance_after={$m['balance_after']}  (invoice_id=" . ($m['invoice_id'] ?? '-') . ")\n";
    }
} elseif ($arg && is_numeric($arg)) {
    show_invoice($db, (int)$arg);
} else {
    echo "Usage:\n";
    echo "  php database/diagnose_stock.php <invoice_id>\n";
    echo "  php database/diagnose_stock.php --recent\n";
    echo "  php database/diagnose_stock.php --product \"Product Name\"\n";
}
