<?php
declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getPDO();
    $semesterId = (int) ($pdo->query("SELECT id FROM semesters WHERE semester_status = 'active' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
    $schoolId = max(0, (int) ($_GET['school_id'] ?? 0));
    $violationsOnly = (string) ($_GET['violations_only'] ?? '') === '1';
    $payload = ['success' => true, 'schools' => [], 'students' => [], 'stats' => ['timed_in' => 0, 'outside' => 0, 'active_schools' => 0, 'gps_reporting' => 0]];
    if ($semesterId <= 0) {
        echo json_encode($payload);
        exit;
    }

    $schoolFilter = $schoolId > 0 ? ' AND g.id = :school_id' : '';
    $schools = $pdo->prepare(
        "SELECT DISTINCT g.id, g.name, g.latitude, g.longitude, g.radius_meters
         FROM geofence_locations g
         JOIN student_geofence_assignments sga ON sga.geofence_id = g.id AND sga.semester_id = :semester_id AND sga.assignment_status = 'active'
         WHERE g.is_active = 1{$schoolFilter} ORDER BY g.name"
    );
    $params = ['semester_id' => $semesterId];
    if ($schoolId > 0) $params['school_id'] = $schoolId;
    $schools->execute($params);
    $payload['schools'] = $schools->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $studentFilter = $schoolId > 0 ? ' AND g.id = :school_id' : '';
    $students = $pdo->prepare(
        "SELECT ar.id AS attendance_id, ar.student_user_id, ar.time_in, ar.total_hours, ar.geofence_inside, ar.geofence_last_seen_at,
                u.school_id, up.first_name, up.last_name, g.id AS assigned_school_id, g.name AS assigned_school,
                g.latitude AS school_latitude, g.longitude AS school_longitude, g.radius_meters,
                latest.latitude, latest.longitude, latest.distance_from_center, latest.inside_radius, latest.logged_at
         FROM attendance_records ar
         JOIN users u ON u.id = ar.student_user_id
         JOIN user_profiles up ON up.user_id = u.id
         JOIN student_geofence_assignments sga ON sga.student_user_id = ar.student_user_id AND sga.semester_id = ar.semester_id AND sga.assignment_status = 'active'
         JOIN geofence_locations g ON g.id = sga.geofence_id AND g.is_active = 1
         LEFT JOIN attendance_geofence_logs latest ON latest.id = (
            SELECT l.id FROM attendance_geofence_logs l WHERE l.attendance_id = ar.id ORDER BY l.logged_at DESC, l.id DESC LIMIT 1
         )
         WHERE ar.semester_id = :semester_id AND ar.attendance_date = CURDATE() AND ar.time_in IS NOT NULL AND ar.time_out IS NULL{$studentFilter}
         ORDER BY up.last_name, up.first_name"
    );
    $students->execute($params);
    foreach ($students->fetchAll(PDO::FETCH_ASSOC) ?: [] as $student) {
        $lastUpdate = (string) ($student['logged_at'] ?: $student['geofence_last_seen_at'] ?: '');
        $isStale = $lastUpdate === '' || strtotime($lastUpdate) < time() - 60;
        $inside = (int) ($student['inside_radius'] ?? $student['geofence_inside'] ?? 0) === 1;
        $distance = isset($student['distance_from_center']) ? (float) $student['distance_from_center'] : null;
        $radius = (float) $student['radius_meters'];
        $status = $isStale ? 'gps_stale' : (!$inside ? 'outside' : ($distance !== null && $distance >= $radius * .8 ? 'near_boundary' : 'inside'));
        if ($violationsOnly && !in_array($status, ['outside', 'gps_stale'], true)) continue;
        $student['status'] = $status;
        $student['hours_worked'] = round((float) $student['total_hours'], 2);
        $payload['students'][] = $student;
    }
    $payload['stats']['timed_in'] = count($payload['students']);
    $payload['stats']['outside'] = count(array_filter($payload['students'], static fn(array $row): bool => $row['status'] === 'outside'));
    $payload['stats']['active_schools'] = count($payload['schools']);
    $payload['stats']['gps_reporting'] = count(array_filter($payload['students'], static fn(array $row): bool => $row['status'] !== 'gps_stale'));
    echo json_encode($payload);
} catch (Throwable $e) {
    error_log('Live location monitoring failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load live location monitoring.']);
}
