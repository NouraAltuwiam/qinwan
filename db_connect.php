<?php
// ============================================================
// قِنوان — db_connect.php  (Updated)
// ============================================================

define('DB_HOST',    'localhost');
define('DB_PORT',    '8889');   // MAMP default; change to 3306 for standard MySQL
define('DB_NAME',    'qinwan');
define('DB_USER',    'root');
define('DB_PASS',    'root');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn  = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }

    // Ensure activity log table exists on every boot
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS qw_activity_log (
            log_id       INT          NOT NULL AUTO_INCREMENT,
            user_id      INT          NOT NULL,
            action_type  VARCHAR(80)  NOT NULL,
            entity_type  VARCHAR(80)  DEFAULT NULL,
            entity_id    INT          DEFAULT NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (log_id),
            KEY idx_user (user_id),
            KEY idx_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    return $pdo;
}

/**
 * Convenience: log an admin / user action
 */
function logActivity(int $userId, string $actionType, string $entityType = null, int $entityId = null): void {
    try {
        $pdo = getDB();
        $pdo->prepare("INSERT INTO qw_activity_log (user_id, action_type, entity_type, entity_id) VALUES (?,?,?,?)")
            ->execute([$userId, $actionType, $entityType, $entityId]);
    } catch (Exception $e) {
        // Non-fatal — never crash the app because of a log failure
    }
}

/** Send a JSON response and exit */
function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Return current session data or null */
function getSession(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) return null;
    return [
        'user_id'     => $_SESSION['user_id'],
        'role'        => $_SESSION['role'],
        'first_name'  => $_SESSION['first_name']  ?? '',
        'last_name'   => $_SESSION['last_name']   ?? '',
        'farmer_id'   => $_SESSION['farmer_id']   ?? null,
        'investor_id' => $_SESSION['investor_id'] ?? null,
    ];
}
?>