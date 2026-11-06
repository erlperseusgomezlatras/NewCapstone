<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

function appBasePath(): string
{
    static $basePath = null;

    if (is_string($basePath)) {
        return $basePath;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = str_replace('\\', '/', dirname($scriptName));

    if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\') {
        $scriptDir = '';
    }

    $sectionDirs = ['public', 'student', 'coordinator', 'head_teacher', 'supervisor', 'api', 'middleware', 'controllers', 'models', 'services', 'config', 'assets', 'docs'];
    if ($scriptDir !== '' && in_array(basename($scriptDir), $sectionDirs, true)) {
        $scriptDir = str_replace('\\', '/', dirname($scriptDir));
    }

    if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\') {
        $scriptDir = '';
    }

    $basePath = rtrim($scriptDir, '/');

    return $basePath;
}

function appUrl(string $path = ''): string
{
    $basePath = appBasePath();
    if ($path === '') {
        return $basePath !== '' ? $basePath : '/';
    }

    return ($basePath !== '' ? $basePath : '') . '/' . ltrim($path, '/');
}

function ensureSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function isLoggedIn(): bool
{
    ensureSessionStarted();
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
        return false;
    }

    // Sessions are server-side credentials, not proof that the account still
    // exists. This prevents a deleted launch-reset account from retaining
    // access until its old PHP session naturally expires.
    try {
        require_once __DIR__ . '/../config/database.php';
        $stmt = getPDO()->prepare(
            'SELECT r.role_code
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id
               AND u.account_status = \'active\'
             LIMIT 1'
        );
        $stmt->execute(['user_id' => (int) $_SESSION['user_id']]);
        $storedRole = $stmt->fetchColumn();
        if ($storedRole !== false && hash_equals((string) $_SESSION['role'], (string) $storedRole)) {
            return true;
        }
    } catch (Throwable) {
        // Fail closed: an unavailable account lookup must never grant access.
    }

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    return false;
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
        'must_change_password' => !empty($_SESSION['must_change_password']),
    ];
}

function forcePasswordChangePathForRole(string $role): ?string
{
    return match ($role) {
        'head_teacher' => appUrl('head_teacher/settings.php?force_password_change=1'),
        'coordinator' => appUrl('coordinator/force_change_password.php'),
        'student' => appUrl('student/force_change_password.php'),
        'supervisor' => appUrl('supervisor/force_change_password.php'),
        default => null,
    };
}

function currentRequestPath(): string
{
    return str_replace('\\', '/', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
}

function enforcePasswordChangeRedirect(): void
{
    ensureSessionStarted();

    if (empty($_SESSION['must_change_password']) || empty($_SESSION['role'])) {
        return;
    }

    $role = (string) $_SESSION['role'];
    $currentPath = rtrim(currentRequestPath(), '/');
    $allowedPaths = [
        'head_teacher' => rtrim(appUrl('head_teacher/settings.php'), '/'),
        'coordinator' => rtrim(appUrl('coordinator/force_change_password.php'), '/'),
        'student' => rtrim(appUrl('student/force_change_password.php'), '/'),
        'supervisor' => rtrim(appUrl('supervisor/force_change_password.php'), '/'),
    ];

    $allowedPath = $allowedPaths[$role] ?? null;
    if ($allowedPath === null) {
        return;
    }

    if ($currentPath === $allowedPath) {
        return;
    }

    $target = forcePasswordChangePathForRole($role);
    if ($target === null) {
        return;
    }

    header('Location: ' . $target);
    exit;
}

function requireLogin(): void
{
    ensureSessionStarted();

    if (!isLoggedIn()) {
        header('Location: ' . appUrl('public/login.php'));
        exit;
    }

    enforcePasswordChangeRedirect();
}
