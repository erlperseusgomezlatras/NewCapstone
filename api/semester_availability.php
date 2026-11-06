<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new InvalidArgumentException('Method not allowed.');
    }

    $yearLabel = trim((string) ($_GET['school_year_label'] ?? ''));
    if (!preg_match('/^\d{4}-\d{4}$/', $yearLabel)) {
        throw new InvalidArgumentException('A valid school year is required.');
    }

    $pdo = getPDO();
    $schoolYearStmt = $pdo->prepare('SELECT id FROM school_years WHERE year_label = :year_label LIMIT 1');
    $schoolYearStmt->execute(['year_label' => $yearLabel]);
    $schoolYearId = (int) ($schoolYearStmt->fetchColumn() ?: 0);

    $semesterStates = [];
    if ($schoolYearId > 0) {
        $semesterStmt = $pdo->prepare('SELECT semester_no, semester_status FROM semesters WHERE school_year_id = :school_year_id');
        $semesterStmt->execute(['school_year_id' => $schoolYearId]);
        foreach ($semesterStmt->fetchAll(PDO::FETCH_ASSOC) as $semester) {
            $semesterStates[(int) $semester['semester_no']] = (string) $semester['semester_status'];
        }
    }

    error_log(sprintf(
        'Semester availability check: year_label=%s; school_year_id=%d; semester_states=%s',
        $yearLabel,
        $schoolYearId,
        json_encode($semesterStates)
    ));

    $definitions = [1 => '1st Semester', 2 => '2nd Semester', 3 => 'Summer'];
    $semesters = [];
    foreach ($definitions as $semesterNo => $name) {
        $status = $semesterStates[$semesterNo] ?? '';
        $semesters[] = [
            'semester_no' => $semesterNo,
            'name' => $name,
            // A planned definition is intentionally reusable. Only a term
            // that has actually progressed beyond planning is unavailable.
            'available' => $status === '' || $status === 'planned',
        ];
    }

    echo json_encode(['success' => true, 'data' => ['school_year_label' => $yearLabel, 'semesters' => $semesters]]);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
