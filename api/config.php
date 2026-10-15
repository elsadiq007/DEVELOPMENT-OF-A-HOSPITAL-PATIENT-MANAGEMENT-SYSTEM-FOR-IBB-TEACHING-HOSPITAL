<?php
/**
 * Database connection + shared helpers for all API endpoints.
 */

// ---- EDIT THESE FOR YOUR SERVER ----
define('DB_SOCKET', '/Applications/MAMP/tmp/mysql/mysql.sock'); // MAMP's default socket path
define('DB_NAME', 'hospital_db');
define('DB_USER', 'root');
define('DB_PASS', 'root');
// -------------------------------------

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // tighten this to your domain in production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// Catch ANY uncaught error/exception anywhere in the app and turn it into clean JSON
// instead of a raw PHP crash page (which silently breaks the frontend's fetch calls).
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
    exit;
});
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $pdo = new PDO(
        "mysql:dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

/** Generate an id similar to the frontend's uid() helper, e.g. "pat-abc123" */
function gen_id($prefix) {
    return $prefix . '-' . substr(bin2hex(random_bytes(6)), 0, 10);
}

/** Read JSON body of the request as an associative array */
function json_body() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/** Send a JSON response and stop execution */
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/** Require an active logged-in session; optionally restrict to specific roles */
function require_auth($roles = []) {
    if (!isset($_SESSION['user'])) {
        respond(['error' => 'Not authenticated'], 401);
    }
    if (!empty($roles) && !in_array($_SESSION['user']['role'], $roles)) {
        respond(['error' => 'Forbidden — insufficient role'], 403);
    }
    return $_SESSION['user'];
}

/** Write an entry to activity_log */
function log_activity($pdo, $user, $action) {
    $stmt = $pdo->prepare(
        "INSERT INTO activity_log (user_id, user_name, action) VALUES (?, ?, ?)"
    );
    $stmt->execute([$user['id'] ?? null, $user['name'] ?? 'System', $action]);
}
