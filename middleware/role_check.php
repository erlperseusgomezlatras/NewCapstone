<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function rolePathMap(): array
{
    return [
        'head_teacher' => appUrl('head_teacher/dashboard.php'),
        'coordinator' => appUrl('coordinator/dashboard.php'),
        'student' => appUrl('student/dashboard.php'),
        'supervisor' => appUrl('supervisor/assigned_students.php'),
    ];
}

function redirectByRole(string $role): void
{
    ensureSessionStarted();
    $path = !empty($_SESSION['must_change_password'])
        ? forcePasswordChangePathForRole($role)
        : null;

    if ($path === null) {
        $map = rolePathMap();
        $path = $map[$role] ?? appUrl('public/login.php');
    }

    header('Location: ' . $path);
    exit;
}

function requireRole(string $role): void
{
    requireLogin();
    $user = currentUser();

    if (($user['role'] ?? '') !== $role) {
        header('Location: ' . appUrl('public/login.php'));
        exit;
    }

    if ($role === 'supervisor' && !supervisorHasCurrentSemesterAccess((int) ($user['id'] ?? 0))) {
        ensureSessionStarted();
        $_SESSION = [];
        session_destroy();
        header('Location: ' . appUrl('public/login.php?inactive=1'));
        exit;
    }
}

function supervisorHasCurrentSemesterAccess(int $supervisorId): bool
{
    if ($supervisorId <= 0) return false;
    require_once __DIR__ . '/../config/database.php';
    try {
        $pdo = getPDO();
        require_once __DIR__ . '/../services/SupervisorService.php';
        return (new SupervisorService($pdo))->hasCurrentAccess($supervisorId);
    } catch (Throwable) {
        return false;
    }
}
