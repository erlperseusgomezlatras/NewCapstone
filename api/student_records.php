<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/role_check.php';
require_once __DIR__ . '/../services/StudentRecordsService.php';

date_default_timezone_set('Asia/Manila');
requireRole('student');

$user = currentUser() ?? [];
$studentId = (int) ($user['id'] ?? 0);
$semesterId = max(0, (int) ($_GET['semester_id'] ?? 0));
$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));

if (!in_array($format, ['json', 'pdf', 'excel'], true)) {
    http_response_code(400);
    die('Unsupported records format.');
}

try {
    $pdo = getPDO();
    $records = (new StudentRecordsService($pdo))->getSemesterRecords($studentId, $semesterId);
    $identityStmt = $pdo->prepare(
        "SELECT
            TRIM(CONCAT_WS(' ', NULLIF(up.first_name, ''), NULLIF(up.middle_name, ''), NULLIF(up.last_name, ''))) AS student_name,
            u.school_id
         FROM users u
         LEFT JOIN user_profiles up ON up.user_id = u.id
         WHERE u.id = :student_id
         LIMIT 1"
    );
    $identityStmt->execute(['student_id' => $studentId]);
    $identity = $identityStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $records['student'] = [
        'name' => trim((string) ($identity['student_name'] ?? '')) ?: (string) ($user['name'] ?? 'Student'),
        'school_id' => (string) ($identity['school_id'] ?? ''),
    ];
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    exit;
} catch (Throwable $e) {
    error_log('Student records export failed: ' . $e->getMessage());
    http_response_code(500);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unable to load student semester records.']);
    } else {
        echo 'Unable to generate student records.';
    }
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($records, JSON_THROW_ON_ERROR);
    exit;
}

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$hours = static function (float $value): string {
    $minutes = (int) floor(max(0, $value) * 60 + 0.00001);
    return intdiv($minutes, 60) . '.' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
};
$semesterTitle = trim($records['semester']['year_label'] . ' ' . $records['semester']['semester_name']);
$studentName = (string) ($records['student']['name'] ?? 'Student');
$studentSchoolId = (string) ($records['student']['school_id'] ?? '');
$dayKeys = ['M', 'T', 'W', 'TH', 'F'];
$rowsHtml = '';

foreach ($records['records'] as $row) {
    $rowsHtml .= '<tr><td>' . (int) $row['index'] . '</td>';
    $rowsHtml .= '<td>' . $escape((new DateTimeImmutable($row['start']))->format('M j'))
        . ' - ' . $escape((new DateTimeImmutable($row['end']))->format('M j, Y')) . '</td>';
    foreach ($dayKeys as $dayKey) {
        $mark = (string) ($row['days'][$dayKey] ?? 'future');
        $rowsHtml .= '<td class="center">' . ($mark === 'present' ? '&#10003;' : ($mark === 'absent' ? '&#10007;' : '&mdash;')) . '</td>';
    }
    $journalLabel = $row['status'] === 'upcoming'
        ? 'Not Due Yet'
        : (!empty($row['journal_submitted'])
            ? (int) ($row['journal_points'] ?? 0) . ' / ' . (int) ($row['journal_maximum'] ?? 50)
                . ' (' . ucwords(str_replace('_', ' ', (string) ($row['journal_status'] ?? 'pending'))) . ')'
            : 'Not Submitted');
    $checklistLabel = $row['checklist_points'] === null || (int) $row['checklist_maximum'] === 0
        ? '-'
        : (int) $row['checklist_points'] . ' / ' . (int) $row['checklist_maximum'];
    $creditedHours = $row['status'] === 'upcoming' ? '-' : $hours((float) $row['credited_hours']);
    $presentDays = $row['status'] === 'upcoming' ? '-' : (string) (int) $row['present_days'];
    $rowsHtml .= '<td>' . $escape($journalLabel) . '</td>';
    $rowsHtml .= '<td class="center">' . $escape($checklistLabel) . '</td>';
    $rowsHtml .= '<td class="center">' . $escape($creditedHours) . '</td>';
    $rowsHtml .= '<td class="center">' . $escape($presentDays) . '</td>';
    $rowsHtml .= '<td>' . $escape($row['status_label']) . '</td></tr>';
}

$totals = $records['totals'];
$footer = '<tr class="total"><td colspan="2">Total</td>';
foreach ($dayKeys as $dayKey) {
    $footer .= '<td class="center">' . (int) $totals['days'][$dayKey] . '</td>';
}
$footer .= '<td class="center">' . (int) ($totals['journal_points'] ?? 0) . ' / ' . (int) ($totals['journal_maximum'] ?? 0) . '</td>';
$footer .= '<td class="center">' . ((int) $totals['checklist_maximum'] > 0
    ? (int) $totals['checklist_points'] . ' / ' . (int) $totals['checklist_maximum']
    : '-') . '</td>';
$footer .= '<td class="center">' . $hours((float) $totals['credited_hours']) . ' / 180</td>';
$footer .= '<td class="center">' . (int) $totals['present_days'] . ' / ' . count($records['records']) . '</td>';
$footer .= '<td class="center">' . (int) $totals['attendance_percent'] . '%</td></tr>';

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:Arial,sans-serif;color:#1f2937;font-size:9px}h1{text-align:center;color:#047857;font-size:18px}
p{text-align:center;color:#475569;margin:4px 0 14px}.identity{text-align:left;margin:12px 0 8px;color:#1f2937}table{width:100%;border-collapse:collapse;table-layout:auto}
th,td{border:1px solid #cbd5e1;padding:5px;text-align:left}th{background:#f1f5f9;text-transform:uppercase;font-size:8px}
.center{text-align:center}.total{background:#dcfce7;font-weight:bold}
</style></head><body><h1>Student Weekly Records</h1><p>' . $escape($semesterTitle) . '</p>
<p class="identity"><strong>Student Name:</strong> ' . $escape($studentName) . ' &nbsp;&nbsp; <strong>Student ID:</strong> ' . $escape($studentSchoolId) . '</p>
<table><thead><tr><th>Week</th><th>Date Range</th><th>M</th><th>T</th><th>W</th><th>TH</th><th>F</th>
<th>Journal Score / Status</th><th>Checklist Points</th><th>Credited Hours</th><th>Present Days</th><th>Status / Remarks</th></tr></thead>
<tbody>' . ($rowsHtml !== '' ? $rowsHtml : '<tr><td colspan="12" class="center">No weekly records are available.</td></tr>') . $footer . '</tbody></table></body></html>';

$baseFilename = 'Student_Records_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $semesterTitle);
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseFilename . '.xls"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "\xEF\xBB\xBF" . $html;
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A3-L',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 10,
        'margin_bottom' => 10,
    ]);
    $mpdf->WriteHTML($html);
    $filename = $baseFilename . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $mpdf->Output($filename, 'D');
} catch (Throwable $e) {
    error_log('Student records PDF generation failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Unable to generate the student records PDF.';
}
