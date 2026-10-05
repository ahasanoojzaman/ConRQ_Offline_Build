<?php
/**
 * ConrQ - Subscription warning banner (shown within 7 days of expiry).
 * The actual hard block is handled earlier by enforce_subscription_gate()
 * at the top of each page, before any POST processing - see includes/functions.php.
 * This file only renders the soft, dismissible pre-expiry reminder.
 */

$tenantRowStmt = $db->prepare("SELECT status, trial_ends_at, subscription_ends_at FROM tenants WHERE id=?");
$tenantRowStmt->execute([$tid]);
$tenantRow = $tenantRowStmt->fetch();

if ($tenantRow) {
    $subStatus = tenant_subscription_status($tenantRow);
    if (!$subStatus['blocked'] && $subStatus['warning_days_left'] !== null) {
        $daysLeft = $subStatus['warning_days_left'];
        $dayWord = $daysLeft === 1 ? 'day' : 'days';
        ?>
        <div id="renewalBanner" style="background:#fdf1de;border-bottom:1px solid #ecd9b0;padding:10px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:.85rem;color:#7a5a1e">
          <span>⏳ <?= $daysLeft === 0 ? 'Your plan ends today.' : "Your plan ends in $daysLeft $dayWord." ?> Renew to avoid interruption.</span>
          <a href="https://wa.me/<?= e(BRAND_WHATSAPP) ?>" target="_blank" style="font-weight:700;color:#16213a">WhatsApp Us</a>
          <span>·</span>
          <a href="tel:<?= e(BRAND_PHONE) ?>" style="font-weight:700;color:#16213a">Call <?= e(BRAND_PHONE) ?></a>
          <span style="margin-left:auto;cursor:pointer;color:#7a5a1e" onclick="document.getElementById('renewalBanner').style.display='none'">✕</span>
        </div>
        <?php
    }
}

<?php
$reason = $_GET['reason'] ?? 'unknown';
$message = "Your license requires attention.";

if ($reason === 'suspended') {
    $message = "This software instance has been suspended due to unauthorized modification or terms violation. Please contact support.";
} elseif ($reason === 'expired') {
    $message = "Your license has expired. Please renew your activation token to continue using the software.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>License Locked</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body style="background-color: #f8d7da; display: flex; align-items: center; justify-content: center; height: 100vh;">
    <div style="background: white; padding: 40px; border-radius: 8px; text-align: center; max-width: 500px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
        <h2 style="color: #721c24;">Access Denied</h2>
        <p style="color: #333; margin: 20px 0;"><?php echo htmlspecialchars($message); ?></p>
        <!-- Add your activation input form here so users can enter a new token -->
    </div>
</body>
</html>