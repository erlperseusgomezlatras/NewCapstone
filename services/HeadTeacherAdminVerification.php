<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/PasswordService.php';

const HEAD_TEACHER_ADMIN_SESSION_TTL = 600;

function headTeacherAdminSessionIsValid(int $userId): bool
{
    ensureSessionStarted();
    return $userId > 0
        && (int) ($_SESSION['head_teacher_admin_user_id'] ?? 0) === $userId
        && (int) ($_SESSION['head_teacher_admin_verified_until'] ?? 0) >= time();
}

function headTeacherAdminSessionExpiresAt(): int
{
    ensureSessionStarted();
    return (int) ($_SESSION['head_teacher_admin_verified_until'] ?? 0);
}

function verifyHeadTeacherAdministrativePassword(PDO $pdo, int $userId, string $password): void
{
    if ($userId <= 0 || trim($password) === '') {
        throw new InvalidArgumentException('Enter your Head Teacher password to continue.');
    }
    $stmt = $pdo->prepare(
        "SELECT u.password_hash FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = :user_id AND r.role_code = 'head_teacher' AND u.account_status = 'active' LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId]);
    if (!verifyPasswordMatch($password, (string) ($stmt->fetchColumn() ?: ''))) {
        throw new InvalidArgumentException('The Head Teacher password is incorrect.');
    }
    ensureSessionStarted();
    $_SESSION['head_teacher_admin_user_id'] = $userId;
    $_SESSION['head_teacher_admin_verified_until'] = time() + HEAD_TEACHER_ADMIN_SESSION_TTL;
}

function requireHeadTeacherAdministrativeVerification(PDO $pdo, ?string $password = null): void
{
    $user = currentUser();
    $userId = (int) ($user['id'] ?? 0);
    if (($user['role'] ?? '') !== 'head_teacher' || $userId <= 0) {
        throw new RuntimeException('Head Teacher access is required.');
    }
    if (trim((string) $password) !== '') {
        verifyHeadTeacherAdministrativePassword($pdo, $userId, (string) $password);
        return;
    }
    if (!headTeacherAdminSessionIsValid($userId)) {
        throw new RuntimeException('Administrative verification has expired. Enter your Head Teacher password to continue.');
    }
}
