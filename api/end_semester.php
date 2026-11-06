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

    $pdo = getPDO();
    requireHeadTeacherAdministrativeVerification($pdo);

    $semesterId = isset($payload['semester_id']) ? (int) $payload['semester_id'] : 0;
    if ($semesterId <= 0) {
        $stmt = $pdo->query("SELECT id FROM semesters WHERE semester_status = 'active' ORDER BY start_date ASC, semester_no ASC LIMIT 1");
        $semesterId = (int) ($stmt->fetchColumn() ?: 0);
    }
    if ($semesterId <= 0) {
        throw new InvalidArgumentException('No active semester found.');
    }
    $statusStmt = $pdo->prepare('SELECT semester_status, practicum_status FROM semesters WHERE id = :semester_id LIMIT 1');
    $statusStmt->execute(['semester_id' => $semesterId]);
    $status = $statusStmt->fetch();
    if ($status === false || ($status['semester_status'] ?? '') !== 'active' || !in_array((string) ($status['practicum_status'] ?? ''), ['not_started', 'not_yet_started', 'scheduled', 'ongoing'], true)) {
        throw new InvalidArgumentException('Only an active semester that has not been completed can be ended.');
    }

    $service = new AcademicControlService($pdo);
    $result = $service->endSemester($semesterId);

    ensureSessionStarted();
    $_SESSION['ht_flash'] = [
        'type' => 'success',
        'message' => trim(sprintf(
            'Semester ended successfully. Students completed: %d, supervisor assignments archived: %d, supervisors deactivated: %d, coordinator deployments ended: %d, coordinators deactivated: %d, schools deactivated: %d.',
            (int) ($result['completed_students'] ?? 0),
            (int) ($result['archived_supervisor_assignments'] ?? 0),
            (int) ($result['deactivated_supervisors'] ?? 0),
            (int) ($result['ended_coordinator_deployments'] ?? 0),
            (int) ($result['deactivated_coordinators'] ?? 0),
            (int) ($result['deactivated_partner_schools'] ?? 0)
        )),
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Semester ended successfully.',
        'data' => $result,
    ]);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
