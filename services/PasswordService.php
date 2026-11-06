<?php

declare(strict_types=1);

/**
 * Returns every password requirement that the supplied password does not meet.
 * Password contents are never logged, returned to the client, or stored here.
 */
function passwordValidationErrors(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }

    return $errors;
}

function validatePasswordStrength(string $password): bool
{
    return passwordValidationErrors($password) === [];
}

function hashUserPassword(string $plainText): string
{
    return password_hash($plainText, PASSWORD_DEFAULT);
}

function verifyPasswordMatch(string $plainText, string $hash): bool
{
    return $hash !== '' && password_verify($plainText, $hash);
}

function passwordChangeValidationErrors(string $current, string $new, string $confirmation, string $currentHash): array
{
    $errors = [];

    if ($current === '' || $new === '' || $confirmation === '') {
        return ['All password fields are required.'];
    }

    $errors = array_merge($errors, passwordValidationErrors($new));

    if ($new !== $confirmation) {
        $errors[] = 'New passwords do not match.';
    }

    if (!verifyPasswordMatch($current, $currentHash)) {
        $errors[] = 'Current password is incorrect.';
    } elseif (verifyPasswordMatch($new, $currentHash)) {
        $errors[] = 'New password cannot be the same as your current password.';
    }

    return array_values(array_unique($errors));
}

/** Validation used immediately after a successful default-password login. */
function initialPasswordChangeValidationErrors(string $new, string $confirmation, string $currentHash): array
{
    if ($new === '' || $confirmation === '') {
        return ['New password and confirmation are required.'];
    }

    $errors = passwordValidationErrors($new);
    if ($new !== $confirmation) {
        $errors[] = 'New passwords do not match.';
    }
    if (verifyPasswordMatch($new, $currentHash)) {
        $errors[] = 'New password cannot be the same as your default password.';
    }

    return array_values(array_unique($errors));
}
