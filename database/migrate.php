<?php
/**
 * Run this once from SSH to create all tables:
 *   php database/migrate.php
 *
 * Safe to re-run - all statements use CREATE TABLE IF NOT EXISTS.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "ConrQ ERP - Database Migration\n";
echo "================================\n";

try {
    $db = DB::conn();
    echo "Connected to database '" . DB_NAME . "' successfully.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$sqlFile = __DIR__ . '/schema.sql';
if (!file_exists($sqlFile)) {
    fwrite(STDERR, "schema.sql not found at $sqlFile\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
// Normalise line endings
$sql = str_replace("\r\n", "\n", $sql);

// Strip full-line comments (lines starting with -- ), keep everything else as-is.
$lines = explode("\n", $sql);
$clean = [];
foreach ($lines as $line) {
    if (preg_match('/^\s*--/', $line)) {
        continue;
    }
    $clean[] = $line;
}
$sql = implode("\n", $clean);

// Our schema has no semicolons inside string literals, so a plain split on ';' is safe.
$statements = array_filter(array_map('trim', explode(';', $sql)));

$count = 0;
foreach ($statements as $stmt) {
    if ($stmt === '') {
        continue;
    }
    try {
        $db->exec($stmt);
        $count++;
        // Print a short progress marker so it's obvious this isn't stuck.
        if (preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $stmt, $m)) {
            echo "  -> table '{$m[1]}' OK\n";
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "Error running statement:\n$stmt\n\n" . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Executed $count statements.\n";
echo "Migration complete. Now run: php database/seed.php\n";
