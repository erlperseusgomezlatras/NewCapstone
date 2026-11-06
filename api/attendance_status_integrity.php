<?php
declare(strict_types=1);
require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceStatusService.php';
require_once __DIR__ . '/../services/AttendanceService.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = getPDO();
    new AttendanceService($pdo);
    $stmt = $pdo->query(
        "SELECT ar.id, ar.student_user_id, u.school_id, up.first_name, up.last_name, ar.attendance_date, ar.time_in, ar.time_out, ar.total_hours, ar.attendance_status, ar.remarks
         FROM attendance_records ar JOIN users u ON u.id=ar.student_user_id JOIN user_profiles up ON up.user_id=u.id
         WHERE ar.attendance_date = CURDATE() AND (ar.time_in IS NOT NULL OR ar.total_hours > 0)
         ORDER BY up.last_name, up.first_name"
    );
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $affected = [];
    foreach ($records as $record) {
        $status = AttendanceStatusService::todayStatus($record);
        if ((float) $record['total_hours'] > 0 && $status === 'ABSENT') $affected[] = $record;
    }
    $summary = $pdo->query(
        "SELECT
            SUM(time_in IS NOT NULL AND time_out IS NULL) AS open_sessions,
            SUM(time_in IS NOT NULL AND time_out IS NULL AND attendance_date < CURDATE()) AS sessions_older_than_24h,
            SUM(COALESCE(total_hours, 0) > 8.00) AS hours_exceeding_daily_cap,
            SUM(attendance_status = 'INCA') AS hours_without_timeout,
            SUM((attendance_status = 'ABSENT' AND (time_in IS NOT NULL OR COALESCE(total_hours, 0) > 0))
                OR (attendance_status = 'LIVE' AND attendance_date < CURDATE())) AS status_hour_mismatches
         FROM attendance_records"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['success' => true, 'checked' => count($records), 'affected_count' => count($affected), 'affected_records' => $affected, 'summary' => $summary]);
} catch (Throwable $e) {
    error_log('Attendance integrity report failed: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['success' => false, 'message' => 'Unable to generate attendance integrity report.']);
}
