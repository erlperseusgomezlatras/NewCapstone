<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = currentUser() ?? [];
    $coordinatorUserId = (int) ($user['id'] ?? 0);
    if ($coordinatorUserId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthenticated']);
        exit;
    }

    $weekStart = isset($_GET['week_start']) && is_string($_GET['week_start'])
        ? practicumWeekStart($_GET['week_start'])
        : (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
    $todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');

    $service = new AttendanceService(getPDO());
    $context = $service->getCoordinatorSectionContext($coordinatorUserId) ?? [];
    if ($context === [] || !practicumCanRun((string) ($context['practicum_start_date'] ?? ''), $todayDate, (string) ($context['practicum_status'] ?? ''))) {
        echo json_encode([
            'context' => $context !== [] ? $context : null,
            'summary' => [
                'active_now' => 0,
                'logged_today' => 0,
                'completed_8h' => 0,
                'total_students' => (int) ($context['total_students'] ?? 0),
            ],
            'live_section' => null,
            'recent_logged_in' => [],
            'weekly_rows' => [],
        ]);
        exit;
    }

    $dashboard = $service->getCoordinatorDashboardData($coordinatorUserId, $todayDate, $weekStart, $weekEnd);
    echo json_encode($dashboard);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
