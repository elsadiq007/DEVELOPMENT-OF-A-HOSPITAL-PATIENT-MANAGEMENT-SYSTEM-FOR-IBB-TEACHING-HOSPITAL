<?php
require 'config.php';

$user = require_auth(); // any logged-in staff member
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $patient = $stmt->fetch();
        if (!$patient) respond(['error' => 'Patient not found'], 404);
        respond($patient);
    }

    // optional search by name/phone: /patients.php?search=musa
    if (isset($_GET['search'])) {
        $like = '%' . $_GET['search'] . '%';
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE name LIKE ? OR phone LIKE ? ORDER BY created_at DESC");
        $stmt->execute([$like, $like]);
        respond($stmt->fetchAll());
    }

    $stmt = $pdo->query("SELECT * FROM patients ORDER BY created_at DESC");
    respond($stmt->fetchAll());
}

if ($method === 'POST') {
    // only front desk / admin register patients
    require_auth(['admin', 'receptionist']);
    $body = json_body();

    if (empty($body['name'])) respond(['error' => 'Patient name is required'], 400);

    $id = gen_id('pat');
    $stmt = $pdo->prepare(
        "INSERT INTO patients (id, name, phone, age, gender, address) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $id,
        $body['name'],
        $body['phone'] ?? null,
        $body['age'] ?? null,
        $body['gender'] ?? null,
        $body['address'] ?? null,
    ]);

    log_activity($pdo, $user, "Registered patient {$body['name']}");
    respond(['id' => $id, 'message' => 'Patient registered'], 201);
}

if ($method === 'PUT') {
    require_auth(['admin', 'receptionist']);
    $body = json_body();
    if (empty($body['id'])) respond(['error' => 'Patient id required'], 400);

    $stmt = $pdo->prepare(
        "UPDATE patients SET name=?, phone=?, age=?, gender=?, address=? WHERE id=?"
    );
    $stmt->execute([
        $body['name'] ?? '',
        $body['phone'] ?? null,
        $body['age'] ?? null,
        $body['gender'] ?? null,
        $body['address'] ?? null,
        $body['id'],
    ]);

    log_activity($pdo, $user, "Updated patient record {$body['id']}");
    respond(['message' => 'Patient updated']);
}

respond(['error' => 'Method not allowed'], 405);
