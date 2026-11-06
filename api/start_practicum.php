<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AcademicControlService.php';
require_once __DIR__ . '/../services/HeadTeacherAdminVerification.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $semesterId = (int) ($payload['semester_id'] ?? 0);
    if ($semesterId <= 0) {
        throw new InvalidArgumentException('Invalid semester selection.');
    }
    $pdo = getPDO();
    requireHeadTeacherAdministrativeVerification($pdo);
    $practicumStartDate = trim((string) ($payload['practicum_start_date'] ?? ''));
    $result = (new AcademicControlService($pdo))->startPracticum(
        $semesterId,
        $practicumStartDate !== '' ? $practicumStartDate : null,
        (string) ($payload['date_change_confirmation'] ?? '')
    );
    $message = ($result['practicum_status'] ?? '') === 'scheduled'
        ? 'Practicum is scheduled. Attendance will open automatically on the selected date.'
        : 'Practicum is now ongoing. Attendance and monitoring are enabled.';
    echo json_encode(['success' => true, 'message' => $message, 'data' => $result]);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 403);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
