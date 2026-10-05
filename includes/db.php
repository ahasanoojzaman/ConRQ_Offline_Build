<?php
/**
 * PDO Database connection (singleton).
 */

class DB
{
    private static ?PDO $instance = null;

    public static function conn(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                error_log('DB Connection failed: ' . $e->getMessage());
                if (APP_ENV === 'production') {
                    http_response_code(500);
                    die('Service temporarily unavailable. Please try again shortly.');
                }
                die('DB Connection failed: ' . $e->getMessage());
            }
        }
        return self::$instance;
    }
}
