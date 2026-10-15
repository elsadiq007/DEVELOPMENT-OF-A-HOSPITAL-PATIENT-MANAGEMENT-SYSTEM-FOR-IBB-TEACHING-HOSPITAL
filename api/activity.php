<?php
require 'config.php';

require_auth(); // any logged-in staff can view (matches original frontend behavior for admin nav)
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    $stmt = $pdo->prepare("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    respond($stmt->fetchAll());
}

respond(['error' => 'Method not allowed'], 405);
