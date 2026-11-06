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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit;
}

try {
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody !== false ? $rawBody : '', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $semesterId = isset($payload['semester_id']) ? (int) $payload['semester_id'] : null;
    if ($semesterId !== null && $semesterId <= 0) {
        $semesterId = null;
    }

    $pdo = getPDO();
    requireHeadTeacherAdministrativeVerification($pdo, isset($payload['admin_password']) ? (string) $payload['admin_password'] : null);
    $service = new AcademicControlService($pdo);
    $result = $service->startSemester($semesterId);
    $alreadyPrepared = (bool) ($result['already_prepared'] ?? false);

    ensureSessionStarted();
    $_SESSION['ht_flash'] = [
        'type' => 'success',
        'message' => $alreadyPrepared
            ? 'Semester has already been prepared and activated.'
            : 'Semester is active. Confirm the practicum start date to begin attendance tracking.',
    ];

    echo json_encode([
        'success' => true,
        'message' => $alreadyPrepared
            ? 'Semester has already been prepared and activated.'
            : 'Semester started successfully.',
        'data' => $result,
    ]);
} catch (Throwable $e) {
    $isClientError = $e instanceof InvalidArgumentException || $e instanceof RuntimeException;
    if (!$isClientError) {
        error_log(sprintf('start_semester API failure: %s', $e->getMessage()));
    }
    http_response_code($isClientError ? 422 : 500);
    echo json_encode([
        'success' => false,
        'message' => $isClientError
            ? $e->getMessage()
            : 'Unable to activate semester. Please contact administrator.',
    ]);
}
