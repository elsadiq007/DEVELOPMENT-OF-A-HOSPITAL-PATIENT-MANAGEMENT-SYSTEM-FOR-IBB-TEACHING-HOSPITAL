<?php
require 'config.php';

// Public on purpose: only exposes name/username/role for active staff so the
// login screen can show a picker. Never exposes pin_hash.
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT name, username, role FROM users WHERE active = 1 ORDER BY name");
    respond($stmt->fetchAll());
}

respond(['error' => 'Method not allowed'], 405);
