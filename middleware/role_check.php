<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function rolePathMap(): array
{
    return [
        'head_teacher' => '/practicum_system/head_teacher/dashboard.php',
        'coordinator' => '/practicum_system/coordinator/dashboard.php',
        'student' => '/practicum_system/student/dashboard.php',
    ];
}

function redirectByRole(string $role): void
{
    $map = rolePathMap();
    $path = $map[$role] ?? '/practicum_system/public/login.php';

    header('Location: ' . $path);
    exit;
}

function requireRole(string $role): void
{
    requireLogin();
    $user = currentUser();

    if (($user['role'] ?? '') !== $role) {
        header('Location: /practicum_system/public/login.php');
        exit;
    }
}
