<?php
/**
 * ConrQ ERP - Core Configuration
 * Edit these values only if your credentials change.
 */

// ---- Database ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'wqftutpg_conrq');
define('DB_USER', 'wqftutpg_conrq_user');
define('DB_PASS', 'Malda@732101');
define('DB_CHARSET', 'utf8mb4');

// ---- App ----
define('APP_NAME', 'ConrQ');
define('APP_URL', 'https://conrq.krenx.in');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_ENV', 'production'); // production | development

// ---- Security ----
// IMPORTANT: change this to a random 32+ char string before going live.
define('APP_KEY', 'CHANGE_THIS_TO_A_RANDOM_LONG_SECRET_STRING_732101');
define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours

// ---- Company (Owner / Product operator) contact - shown on landing page ----
define('BRAND_COMPANY', 'Remesys Technologies');
define('BRAND_ADDRESS', 'Novel Tech Park, Kudlu Gate, Bangalore - 560068');
define('BRAND_PHONE', '8073338746');
define('BRAND_WHATSAPP', '919120619120'); // international format, no + or spaces
define('BRAND_EMAIL', 'contact@remesys.in');
define('BRAND_EAST_ZONE_PHONE', '9378314758');
define('BRAND_EAST_ZONE_STATES', 'West Bengal, Bihar, Jharkhand, Assam, Sikkim, Meghalaya, Tripura');
define('BRAND_EAST_ZONE_HOURS', 'Mon to Sat, 10am to 7pm');

// ---- Optional integrations (fill in later from Settings or here) ----
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_FROM', 'noreply@conrq.krenx.in');
define('SMTP_FROM_NAME', 'ConrQ');

define('SMS_GATEWAY_URL', '');   // e.g. your SMS provider's HTTP API endpoint
define('SMS_GATEWAY_API_KEY', '');

// ---- Paths ----
define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('UPLOAD_PATH', ROOT_PATH . '/assets/uploads');

date_default_timezone_set(APP_TIMEZONE);

if (APP_ENV === 'production') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
