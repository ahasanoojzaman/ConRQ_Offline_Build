<?php
/**
 * Adds feature-gating columns to plans (Storefront, Master Accounting)
 * and turns them on for Growth/Business plans.
 *
 * Run once via SSH:
 *   php database/add_plan_features.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = DB::conn();

$check = $db->query("SHOW COLUMNS FROM plans LIKE 'feature_storefront'")->fetch();
if (!$check) {
    $db->exec("ALTER TABLE plans
               ADD COLUMN feature_storefront TINYINT(1) NOT NULL DEFAULT 0 AFTER features,
               ADD COLUMN feature_master_accounting TINYINT(1) NOT NULL DEFAULT 0 AFTER feature_storefront");
    echo "Added feature_storefront and feature_master_accounting columns to plans.\n";
} else {
    echo "Columns already exist on plans.\n";
}

$upd = $db->prepare("UPDATE plans SET feature_storefront=1, feature_master_accounting=1 WHERE name IN ('Growth','Business')");
$upd->execute();
echo "Enabled Storefront + Master Accounting for Growth and Business plans (" . $upd->rowCount() . " row(s) updated).\n";
echo "Starter plan remains without these features - edit any plan any time from Admin > Plans.\n";
