<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = currentUser() ?? [];
    $coordinatorUserId = (int) ($user['id'] ?? 0);
    $studentUserId = max(0, (int) ($_GET['student_id'] ?? 0));
    $weekStart = trim((string) ($_GET['week_start'] ?? ''));

    if ($studentUserId <= 0 || $weekStart === '') {
        throw new InvalidArgumentException('Invalid attendance log request.');
    }

    $weekStartDate = new DateTimeImmutable($weekStart);
    $weekStart = $weekStartDate->modify('monday this week')->format('Y-m-d');
    $weekEnd = $weekStartDate->modify('monday this week')->modify('+4 days')->format('Y-m-d');

    $pdo = getPDO();
    $attendanceService = new AttendanceService($pdo);
    $studentContext = $attendanceService->getCoordinatorManagedStudentContext($coordinatorUserId, $studentUserId);
    if ($studentContext === null) {
        throw new RuntimeException('Student attendance is not available to this coordinator.');
    }

    $stmt = $pdo->prepare(
        "SELECT attendance_date, time_in, time_out, total_hours, remarks
         FROM attendance_records
         WHERE student_user_id = :student_user_id
           AND semester_id = :semester_id
           AND attendance_date BETWEEN :week_start AND :week_end
         ORDER BY attendance_date ASC, time_in ASC"
    );
    $stmt->execute([
        'student_user_id' => $studentUserId,
        'semester_id' => (int) ($studentContext['semester_id'] ?? 0),
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
    ]);

    echo json_encode([
        'ok' => true,
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
