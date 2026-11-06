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

$user = currentUser() ?? [];
$coordinatorUserId = (int) ($user['id'] ?? 0);
$studentUserId = (int) ($_POST['student_id'] ?? 0);
$attendanceDate = trim((string) ($_POST['attendance_date'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));
$weekStart = trim((string) ($_POST['week_start'] ?? ''));
$overrideWeekStart = trim((string) ($_POST['override_week_start'] ?? ''));
$overrideWeekday = trim((string) ($_POST['override_weekday'] ?? ''));

$redirectUrl = appUrl('coordinator/dashboard.php');
if ($weekStart !== '') {
    $redirectUrl .= '?week_start=' . urlencode($weekStart);
}

try {
    if ($overrideWeekStart !== '' || $overrideWeekday !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $overrideWeekStart) || !preg_match('/^[0-4]$/', $overrideWeekday)) {
            throw new InvalidArgumentException('Select a valid practicum week and weekday.');
        }

        $week = new DateTimeImmutable($overrideWeekStart, new DateTimeZone('Asia/Manila'));
        if ($week->format('Y-m-d') !== $overrideWeekStart || (int) $week->format('N') !== 1) {
            throw new InvalidArgumentException('Selected attendance week must begin on Monday.');
        }

        $attendanceDate = $week->modify('+' . (int) $overrideWeekday . ' days')->format('Y-m-d');
    }

    $service = new AttendanceService(getPDO());
    $service->markEmergencyAttendanceOverride($coordinatorUserId, $studentUserId, $attendanceDate, $reason);
    coordinatorSetFlash('success', 'Emergency attendance override recorded successfully.');
} catch (Throwable $e) {
    coordinatorSetFlash('error', $e->getMessage());
}

header('Location: ' . $redirectUrl);
exit;
