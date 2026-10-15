<?php
require 'config.php';

$user = require_auth(['admin']); // staff & roles management is admin-only
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, name, username, role, active, created_at FROM users ORDER BY created_at");
    respond($stmt->fetchAll());
}

if ($method === 'POST') {
    $body = json_body();
    foreach (['name', 'username', 'pin', 'role'] as $field) {
        if (empty($body[$field])) respond(['error' => "$field is required"], 400);
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $check->execute([$body['username']]);
    if ($check->fetchColumn() > 0) respond(['error' => 'Username already exists'], 409);

    $id = gen_id('usr');
    $stmt = $pdo->prepare(
        "INSERT INTO users (id, name, username, pin_hash, role, active) VALUES (?, ?, ?, ?, ?, 1)"
    );
    $stmt->execute([
        $id, $body['name'], $body['username'],
        password_hash($body['pin'], PASSWORD_DEFAULT), $body['role'],
    ]);

    log_activity($pdo, $user, "Created staff account {$body['username']} ({$body['role']})");
    respond(['id' => $id, 'message' => 'User created'], 201);
}

if ($method === 'PUT') {
    $body = json_body();
    if (empty($body['id'])) respond(['error' => 'id is required'], 400);

    if (!empty($body['pin'])) {
        $stmt = $pdo->prepare(
            "UPDATE users SET name=?, role=?, active=?, pin_hash=? WHERE id=?"
        );
        $stmt->execute([
            $body['name'] ?? '', $body['role'] ?? '', $body['active'] ?? 1,
            password_hash($body['pin'], PASSWORD_DEFAULT), $body['id'],
        ]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE users SET name=?, role=?, active=? WHERE id=?"
        );
        $stmt->execute([$body['name'] ?? '', $body['role'] ?? '', $body['active'] ?? 1, $body['id']]);
    }

    log_activity($pdo, $user, "Updated staff account {$body['id']}");
    respond(['message' => 'User updated']);
}

respond(['error' => 'Method not allowed'], 405);
