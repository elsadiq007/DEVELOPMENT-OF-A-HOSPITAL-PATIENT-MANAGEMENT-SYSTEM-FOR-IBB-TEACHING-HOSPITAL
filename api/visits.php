<?php
require 'config.php';

$user = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';


// Ensure admission and room fields exist for older installations.
function ensure_admission_columns($pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $admitted = $pdo->query("SHOW COLUMNS FROM visits LIKE 'admitted'")->fetch();
    if (!$admitted) {
        $pdo->exec("ALTER TABLE visits ADD COLUMN admitted TINYINT(1) NOT NULL DEFAULT 0 AFTER patient_id");
    }

    $room = $pdo->query("SHOW COLUMNS FROM visits LIKE 'room_number'")->fetch();
    if (!$room) {
        $pdo->exec("ALTER TABLE visits ADD COLUMN room_number VARCHAR(30) NULL AFTER admitted");
    }

    try { $pdo->exec("CREATE INDEX idx_visit_room ON visits(room_number)"); } catch (Throwable $e) { /* index may already exist */ }
}

// ---------------------------------------------------------
// GET: list visits, optionally filtered by ?stage= or ?patient_id=
// ---------------------------------------------------------
if ($method === 'GET') {
    ensure_admission_columns($pdo);
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare(
            "SELECT v.*, p.name AS patient_name, p.phone AS patient_phone
             FROM visits v JOIN patients p ON p.id = v.patient_id
             WHERE v.id = ?"
        );
        $stmt->execute([$_GET['id']]);
        $visit = $stmt->fetch();
        if (!$visit) respond(['error' => 'Visit not found'], 404);

        $rx = $pdo->prepare("SELECT * FROM prescriptions WHERE visit_id = ?");
        $rx->execute([$_GET['id']]);
        $visit['prescriptions'] = $rx->fetchAll();

        respond($visit);
    }

    $sql = "SELECT v.*, p.name AS patient_name, p.phone AS patient_phone
            FROM visits v JOIN patients p ON p.id = v.patient_id WHERE 1=1";
    $params = [];

    if (!empty($_GET['stage'])) {
        $sql .= " AND v.stage = ?";
        $params[] = $_GET['stage'];
    }
    if (!empty($_GET['patient_id'])) {
        $sql .= " AND v.patient_id = ?";
        $params[] = $_GET['patient_id'];
    }
    $sql .= " ORDER BY v.checkin_time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $visits = $stmt->fetchAll();

    if ($visits) {
        $ids = array_column($visits, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE visit_id IN ($placeholders)");
        $rxStmt->execute($ids);
        $allRx = $rxStmt->fetchAll();

        foreach ($visits as &$v) {
            $v['prescriptions'] = array_values(array_filter($allRx, fn($rx) => $rx['visit_id'] === $v['id']));
        }
        unset($v);
    }

    respond($visits);
}

// ---------------------------------------------------------
// POST: check in a patient (creates a new visit)
// ---------------------------------------------------------
if ($method === 'POST' && $action === 'checkin') {
    require_auth(['admin', 'receptionist']);
    ensure_admission_columns($pdo);
    $body = json_body();
    if (empty($body['patient_id'])) respond(['error' => 'patient_id is required'], 400);

    $id = gen_id('vis');
    $stmt = $pdo->prepare(
        "INSERT INTO visits (id, patient_id, admitted, room_number, stage, created_by) VALUES (?, ?, 0, NULL, 'checked_in', ?)"
    );
    $stmt->execute([$id, $body['patient_id'], $user['id']]);

    log_activity($pdo, $user, "Checked in patient {$body['patient_id']}");
    respond(['id' => $id, 'message' => 'Patient checked in'], 201);
}

// ---------------------------------------------------------
// PUT: update a patient's room number (receptionist/admin)
// body: { visit_id, room_number }
// ---------------------------------------------------------
if ($method === 'PUT' && $action === 'room') {
    require_auth(['admin', 'receptionist']);
    ensure_admission_columns($pdo);
    $body = json_body();
    if (empty($body['visit_id'])) respond(['error' => 'visit_id is required'], 400);

    $room = trim($body['room_number'] ?? '');
    if ($room === '') respond(['error' => 'Room number is required for an admitted patient'], 400);

    $check = $pdo->prepare("SELECT admitted, stage FROM visits WHERE id=?");
    $check->execute([$body['visit_id']]);
    $visit = $check->fetch();
    if (!$visit) respond(['error' => 'Visit not found'], 404);
    if ((int)$visit['admitted'] !== 1) respond(['error' => 'Only admitted patients can be assigned a room'], 400);
    if ($visit['stage'] === 'completed') respond(['error' => 'Completed patients cannot be assigned a room'], 400);

    $stmt = $pdo->prepare("UPDATE visits SET room_number=? WHERE id=?");
    $stmt->execute([$room, $body['visit_id']]);

    log_activity($pdo, $user, "Assigned room number for admitted visit {$body['visit_id']}");
    respond(['message' => 'Room number updated']);
}

// ---------------------------------------------------------
// PUT: record vitals (nurse) -> moves stage to vitals_done
// ---------------------------------------------------------
if ($method === 'PUT' && $action === 'vitals') {
    require_auth(['admin', 'nurse']);
    $body = json_body();
    if (empty($body['visit_id'])) respond(['error' => 'visit_id is required'], 400);

    $stmt = $pdo->prepare(
        "UPDATE visits SET temperature=?, blood_pressure=?, pulse=?, weight_kg=?, vitals_notes=?,
         vitals_by=?, vitals_at=NOW(), stage='with_doctor' WHERE id=?"
    );
    $stmt->execute([
        $body['temperature'] ?? null,
        $body['blood_pressure'] ?? null,
        $body['pulse'] ?? null,
        $body['weight_kg'] ?? null,
        $body['vitals_notes'] ?? null,
        $user['id'],
        $body['visit_id'],
    ]);

    log_activity($pdo, $user, "Recorded vitals for visit {$body['visit_id']}");
    respond(['message' => 'Vitals recorded']);
}

// ---------------------------------------------------------
// PUT: doctor consultation -> moves stage to with_doctor -> pharmacy
// body: { visit_id, doctor_id, diagnosis, doctor_notes, prescriptions: [{drug_name,dosage,instructions}] }
// ---------------------------------------------------------
if ($method === 'PUT' && $action === 'consult') {
    require_auth(['admin', 'doctor']);
    ensure_admission_columns($pdo);
    $body = json_body();
    if (empty($body['visit_id'])) respond(['error' => 'visit_id is required'], 400);

    $pdo->beginTransaction();
    try {
        $hasRx = !empty($body['prescriptions']) && is_array($body['prescriptions']) && count(array_filter($body['prescriptions'], fn($rx) => !empty($rx['drug_name']))) > 0;
        $nextStage = $hasRx ? 'pharmacy_pending' : 'ready_checkout';

        $stmt = $pdo->prepare(
            "UPDATE visits SET doctor_id=?, diagnosis=?, doctor_notes=?, admitted=?, seen_at=NOW(), stage=? WHERE id=?"
        );
        $stmt->execute([
            $user['id'],
            $body['diagnosis'] ?? null,
            $body['doctor_notes'] ?? null,
            !empty($body['admitted']) ? 1 : 0,
            $nextStage,
            $body['visit_id'],
        ]);

        if (empty($body['admitted'])) {
            $pdo->prepare("UPDATE visits SET room_number=NULL WHERE id=?")->execute([$body['visit_id']]);
        }

        if ($hasRx) {
            $rxStmt = $pdo->prepare(
                "INSERT INTO prescriptions (id, visit_id, drug_name, dosage, instructions) VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($body['prescriptions'] as $rx) {
                if (empty($rx['drug_name'])) continue;
                $rxStmt->execute([
                    gen_id('rx'),
                    $body['visit_id'],
                    $rx['drug_name'],
                    $rx['dosage'] ?? null,
                    $rx['instructions'] ?? null,
                ]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        respond(['error' => 'Failed to save consultation'], 500);
    }

    log_activity($pdo, $user, "Completed consultation for visit {$body['visit_id']}");
    respond(['message' => 'Consultation saved']);
}

// ---------------------------------------------------------
// PUT: pharmacy updates dispensing status for one or more prescriptions on a visit
// body: { visit_id, dispensed_ids: ["rx-xxx", "rx-yyy"] }  <- these are marked dispensed=1
// any prescription for the visit NOT in dispensed_ids is left/marked as not dispensed
// ---------------------------------------------------------
if ($method === 'PUT' && $action === 'dispense') {
    require_auth(['admin', 'pharmacy']);
    $body = json_body();
    if (empty($body['visit_id'])) respond(['error' => 'visit_id is required'], 400);
    $dispensedIds = $body['dispensed_ids'] ?? [];

    $rxStmt = $pdo->prepare("SELECT id FROM prescriptions WHERE visit_id = ?");
    $rxStmt->execute([$body['visit_id']]);
    $allIds = array_column($rxStmt->fetchAll(), 'id');

    $markDone = $pdo->prepare("UPDATE prescriptions SET dispensed=1, dispensed_by=?, dispensed_at=NOW() WHERE id=?");
    $markPending = $pdo->prepare("UPDATE prescriptions SET dispensed=0, dispensed_by=NULL, dispensed_at=NULL WHERE id=?");

    foreach ($allIds as $rxId) {
        if (in_array($rxId, $dispensedIds)) {
            $markDone->execute([$user['id'], $rxId]);
        } else {
            $markPending->execute([$rxId]);
        }
    }

    $allDone = count($allIds) > 0 && count(array_diff($allIds, $dispensedIds)) === 0;
    if ($allDone) {
        $pdo->prepare("UPDATE visits SET stage='ready_checkout' WHERE id=?")->execute([$body['visit_id']]);
    }

    log_activity($pdo, $user, "Updated dispensing status for visit {$body['visit_id']}");
    respond(['message' => 'Dispensing status updated', 'all_dispensed' => $allDone]);
}

// ---------------------------------------------------------
// PUT: check out a patient
// ---------------------------------------------------------
if ($method === 'PUT' && $action === 'checkout') {
    require_auth(['admin', 'receptionist']);
    $body = json_body();
    if (empty($body['visit_id'])) respond(['error' => 'visit_id is required'], 400);

    $stmt = $pdo->prepare(
        "UPDATE visits SET stage='completed', checkout_time=NOW() WHERE id=?"
    );
    $stmt->execute([$body['visit_id']]);

    log_activity($pdo, $user, "Checked out visit {$body['visit_id']}");
    respond(['message' => 'Patient checked out']);
}

respond(['error' => 'Unknown action or method'], 404);
