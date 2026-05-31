<?php
define('DB_HOST', getenv('DB_HOST') ?: 'rmik.gyatechindonesia.com');
define('DB_NAME', getenv('DB_NAME') ?: 'gyae2735_rmik');
define('DB_USER', getenv('DB_USER') ?: 'gyae2735_rmik_user');
define('DB_PASS', getenv('DB_PASS') ?: '*rmik_gyatech');


define('APP_NAME', 'RMIK Automator');
define('APP_URL', 'https://rmik.gyatechindonesia.com');

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            require_once __DIR__ . '/../includes/functions.php';
            system_log_error('Koneksi Database Gagal: ' . $e->getMessage(), 'Database');
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'DB connection failed']);
            exit;
        }
    }
    return $pdo;
}
