<?php
// -----------------------------------------------
// Hospital Software - Auto Fix & Diagnostic Tool
// Drop this file in your hospital/ folder
// Then open: http://localhost:8888/hospital/fix.php
// -----------------------------------------------

define('DB_SOCKET', '/Applications/MAMP/tmp/mysql/mysql.sock');
define('DB_NAME',   'hospital_db');
define('DB_USER',   'root');
define('DB_PASS',   'root');

try {
    $pdo = new PDO(
        "mysql:dbname=" . DB_NAME . ";unix_socket=" . DB_SOCKET . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("<h2 style='color:red'>❌ Database connection failed: " . $e->getMessage() . "</h2>");
}

$log = [];

// 1. Add 'admitted' column if missing
$admitted = $pdo->query("SHOW COLUMNS FROM visits LIKE 'admitted'")->fetch();
if (!$admitted) {
    $pdo->exec("ALTER TABLE visits ADD COLUMN admitted TINYINT(1) NOT NULL DEFAULT 0");
    $log[] = "✅ Added missing 'admitted' column to visits table.";
} else {
    $log[] = "✔️ 'admitted' column already exists.";
}

// 2. Add 'room_number' column if missing
$room = $pdo->query("SHOW COLUMNS FROM visits LIKE 'room_number'")->fetch();
if (!$room) {
    $pdo->exec("ALTER TABLE visits ADD COLUMN room_number VARCHAR(30) NULL");
    $log[] = "✅ Added missing 'room_number' column to visits table.";
} else {
    $log[] = "✔️ 'room_number' column already exists.";
}

// 3. Fetch recent visits
$visits = $pdo->query("
    SELECT v.id, p.name AS patient_name, v.admitted, v.room_number, v.stage
    FROM visits v
    LEFT JOIN patients p ON p.id = v.patient_id
    ORDER BY v.id DESC
    LIMIT 10
")->fetchAll();

?>
<!DOCTYPE html>
<html>
<head>
<title>Hospital Fix Tool</title>
<style>
  body { font-family: sans-serif; max-width: 860px; margin: 40px auto; padding: 0 20px; }
  h2 { color: #1a73e8; }
  .log { background: #f0fdf4; border: 1px solid #86efac; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; }
  .log p { margin: 4px 0; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #1a73e8; color: white; padding: 10px; text-align: left; }
  td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
  tr:hover td { background: #f9fafb; }
  .yes { color: green; font-weight: bold; }
  .no  { color: red; }
  .note { background: #fefce8; border: 1px solid #fde047; padding: 12px 16px; border-radius: 8px; margin-top: 24px; }
</style>
</head>
<body>

<h2>🏥 Hospital Software — Fix & Diagnostic Tool</h2>

<div class="log">
  <?php foreach ($log as $l): ?>
    <p><?= $l ?></p>
  <?php endforeach; ?>
</div>

<h3>Last 10 Visits in Database</h3>
<table>
  <tr>
    <th>Visit ID</th>
    <th>Patient Name</th>
    <th>Stage</th>
    <th>Admitted (DB)</th>
    <th>Room Number</th>
  </tr>
  <?php foreach ($visits as $v): ?>
  <tr>
    <td><?= htmlspecialchars($v['id']) ?></td>
    <td><?= htmlspecialchars($v['patient_name'] ?? '—') ?></td>
    <td><?= htmlspecialchars($v['stage']) ?></td>
    <td class="<?= $v['admitted'] ? 'yes' : 'no' ?>"><?= $v['admitted'] ? '✅ YES' : '❌ NO' ?></td>
    <td><?= htmlspecialchars($v['room_number'] ?? '—') ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<div class="note">
  <b>What to check:</b><br>
  If the patient the doctor admitted shows <b style="color:red">❌ NO</b> under "Admitted (DB)", 
  it means the doctor did <b>not tick the "Admit patient" checkbox</b> before clicking Save.<br><br>
  Ask the doctor to open the patient again → tick <b>"Admit patient"</b> → click <b>Save & Route Patient</b>.<br>
  Then go to Room Search — the patient will appear.
</div>

</body>
</html>
