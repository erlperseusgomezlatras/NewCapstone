<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('student');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $user = currentUser() ?? [];
    $studentUserId = (int) ($user['id'] ?? 0);
    if ($studentUserId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthenticated']);
        exit;
    }

    $attendanceDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $latitude = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
    $longitude = filter_input(INPUT_GET, 'lng', FILTER_VALIDATE_FLOAT);
    $service = new AttendanceService(getPDO());
    echo json_encode($service->getStudentLiveSnapshot($studentUserId, $attendanceDate, $latitude === false ? null : $latitude, $longitude === false ? null : $longitude, true));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
