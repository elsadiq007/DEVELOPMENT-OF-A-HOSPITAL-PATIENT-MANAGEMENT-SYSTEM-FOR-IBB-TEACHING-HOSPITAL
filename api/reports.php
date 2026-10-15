<?php
require 'config.php';

require_auth(['admin']);
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    respond(['error' => 'Method not allowed'], 405);
}

/*
 * Monthly illness report:
 * - Only visits with a non-empty doctor diagnosis are counted.
 * - Percentage = diagnosis cases / all diagnosed visits in that month * 100.
 * This makes the percentages within each month add up to 100% (apart from rounding).
 */
$stmt = $pdo->query(
    "SELECT
        DATE_FORMAT(v.seen_at, '%Y-%m') AS report_month,
        DATE_FORMAT(v.seen_at, '%M %Y') AS month_label,
        TRIM(v.diagnosis) AS diagnosis,
        COUNT(*) AS cases
     FROM visits v
     WHERE v.diagnosis IS NOT NULL
       AND TRIM(v.diagnosis) <> ''
       AND v.seen_at IS NOT NULL
     GROUP BY DATE_FORMAT(v.seen_at, '%Y-%m'), DATE_FORMAT(v.seen_at, '%M %Y'), TRIM(v.diagnosis)
     ORDER BY report_month DESC, cases DESC, diagnosis ASC"
);
$rows = $stmt->fetchAll();

$totals = [];
foreach ($rows as $row) {
    $totals[$row['report_month']] = ($totals[$row['report_month']] ?? 0) + (int)$row['cases'];
}

foreach ($rows as &$row) {
    $total = $totals[$row['report_month']] ?? 0;
    $row['cases'] = (int)$row['cases'];
    $row['total_diagnosed'] = $total;
    $row['percentage'] = $total > 0 ? round(($row['cases'] / $total) * 100, 2) : 0;
}
unset($row);

respond([
    'rows' => $rows,
    'months' => array_values(array_unique(array_column($rows, 'report_month')))
]);
