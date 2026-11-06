<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/_portal.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . appUrl('coordinator/dashboard.php'));
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$attendanceId = (int) ($_POST['attendance_id'] ?? 0);
$weekStart = trim((string) ($_POST['week_start'] ?? ''));
$redirectUrl = appUrl('coordinator/dashboard.php') . ($weekStart !== '' ? '?week_start=' . urlencode($weekStart) : '');

try {
    if (!in_array($action, ['approve_inca', 'reject_inca'], true)) {
        throw new InvalidArgumentException('Invalid attendance review action.');
    }
    $verifiedHours = $action === 'approve_inca' && isset($_POST['verified_hours']) && $_POST['verified_hours'] !== ''
        ? (float) $_POST['verified_hours']
        : null;
    $result = (new AttendanceService(getPDO()))->reviewIncompleteAttendance(
        (int) ((currentUser() ?? [])['id'] ?? 0),
        $attendanceId,
        $action === 'approve_inca',
        $verifiedHours
    );
    coordinatorSetFlash('success', $result['status'] === 'PRESENT'
        ? 'Incomplete attendance approved and credited.'
        : 'Incomplete attendance rejected with no credit.');
} catch (Throwable $e) {
    error_log('INCA review failed: ' . $e->getMessage());
    coordinatorSetFlash('error', 'Unable to review incomplete attendance.');
}

header('Location: ' . $redirectUrl);
exit;
