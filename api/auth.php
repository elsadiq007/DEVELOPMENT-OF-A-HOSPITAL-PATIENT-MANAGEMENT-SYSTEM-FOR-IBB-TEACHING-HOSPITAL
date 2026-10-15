<?php
require 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'login') {
    $body = json_body();
    $username = trim($body['username'] ?? '');
    $pin      = trim($body['pin'] ?? '');

    if ($username === '' || $pin === '') {
        respond(['error' => 'Username and PIN are required'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pin, $user['pin_hash'])) {
        respond(['error' => 'Invalid username or PIN'], 401);
    }

    $sessionUser = [
        'id' => $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
    $_SESSION['user'] = $sessionUser;

    log_activity($pdo, $sessionUser, $sessionUser['name'] . ' logged in');
    respond(['user' => $sessionUser]);
}

if ($method === 'POST' && $action === 'logout') {
    if (isset($_SESSION['user'])) {
        log_activity($pdo, $_SESSION['user'], $_SESSION['user']['name'] . ' logged out');
    }
    $_SESSION = [];
    session_destroy();
    respond(['message' => 'Logged out']);
}

if ($method === 'GET' && $action === 'session') {
    respond(['user' => $_SESSION['user'] ?? null]);
}

respond(['error' => 'Unknown action'], 404);
