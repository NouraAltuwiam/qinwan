<?php
// ============================================================
// قِنوان — db_connect.php
// اتصال قاعدة البيانات — يُضمَّن في كل صفحة PHP
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'qinwan');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    return $pdo;
}

// دالة مساعدة: رد JSON
function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// دالة مساعدة: الجلسة الحالية
function getSession(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) return null;
    return [
        'user_id'    => $_SESSION['user_id'],
        'role'       => $_SESSION['role'],
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name'  => $_SESSION['last_name']  ?? '',
        'farmer_id'  => $_SESSION['farmer_id']  ?? null,
        'investor_id'=> $_SESSION['investor_id'] ?? null,
    ];
}
