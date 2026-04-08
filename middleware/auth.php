<?php

declare(strict_types=1);

function ensureSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function isLoggedIn(): bool
{
    ensureSessionStarted();
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

function currentUser(): ?array
{
    ensureSessionStarted();

    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'name' => (string) ($_SESSION['name'] ?? ''),
        'email' => (string) ($_SESSION['email'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? ''),
    ];
}

function requireLogin(): void
{
    ensureSessionStarted();

    if (!isLoggedIn()) {
        header('Location: /practicum_system/public/login.php');
        exit;
    }
}
