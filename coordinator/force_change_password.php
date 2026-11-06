<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PasswordService.php';

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$dashboardUrl = appUrl('coordinator/dashboard.php');
$errorMessage = '';
$showSuccess = false;

if ($userId <= 0) {
    header('Location: ' . appUrl('public/login.php'));
    exit;
}

ensureSessionStarted();
if (!empty($_SESSION['coordinator_password_change_success'])) {
    unset($_SESSION['coordinator_password_change_success']);
    $showSuccess = true;
} else {
    $pdo = getPDO();
    $accountStmt = $pdo->prepare(
        "SELECT u.password_hash, u.must_change_password
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = :user_id
           AND r.role_code = 'coordinator'
           AND u.account_status = 'active'
         LIMIT 1"
    );
    $accountStmt->execute(['user_id' => $userId]);
    $account = $accountStmt->fetch();

    if ($account === false) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . appUrl('public/login.php'));
        exit;
    }

    if (empty($account['must_change_password'])) {
        $_SESSION['must_change_password'] = false;
        header('Location: ' . $dashboardUrl);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $errors = initialPasswordChangeValidationErrors($newPassword, $confirmPassword, (string) $account['password_hash']);

        if ($errors !== []) {
            $errorMessage = implode(' ', $errors);
        } else {
            $updateStmt = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     must_change_password = 0,
                     updated_at = NOW()
                 WHERE id = :user_id
                   AND must_change_password = 1'
            );
            $updateStmt->execute([
                'password_hash' => hashUserPassword($newPassword),
                'user_id' => $userId,
            ]);

            if ($updateStmt->rowCount() !== 1) {
                $errorMessage = 'Your password setup session has expired. Please sign in again.';
            } else {
                $_SESSION['must_change_password'] = false;
                $_SESSION['coordinator_password_change_success'] = true;
                header('Location: ' . appUrl('coordinator/force_change_password.php'));
                exit;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Security Setup</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(appUrl('assets/images/logo_college.png'), ENT_QUOTES, 'UTF-8') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <main class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6">
        <section class="w-full max-w-lg overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl shadow-slate-900/10">
            <div class="bg-gradient-to-r from-emerald-800 via-emerald-700 to-emerald-600 px-6 py-7 text-white sm:px-8">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/30">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v2"/></svg>
                </div>
                <p class="mt-5 text-xs font-bold uppercase tracking-[0.22em] text-emerald-100">Account Security Setup</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight">First-Time Login Required</h1>
                <p class="mt-3 text-sm leading-6 text-emerald-50">Your coordinator account was created by the Head Teacher using a temporary password.</p>
            </div>

            <div class="px-6 py-7 sm:px-8">
                <p class="text-sm leading-6 text-slate-600">Your account is currently using the default password assigned by the school. For security reasons, create a personal password before continuing. This protects attendance records, journal submissions, evaluations, and practicum information.</p>

                <?php if ($errorMessage !== ''): ?>
                    <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-5 text-rose-800" role="alert">
                        <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="mt-6 space-y-5" novalidate>
                    <div>
                        <label for="newPassword" class="mb-2 block text-sm font-semibold text-slate-800">New Password</label>
                        <div class="relative"><input id="newPassword" name="new_password" type="password" autocomplete="new-password" required class="w-full rounded-xl border border-slate-300 px-4 py-3 pr-12 text-sm shadow-sm outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"><button type="button" data-password-toggle="newPassword" class="absolute inset-y-0 right-0 inline-flex w-12 items-center justify-center rounded-r-xl text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500" aria-label="Show new password"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div>
                    </div>
                    <div>
                        <label for="confirmPassword" class="mb-2 block text-sm font-semibold text-slate-800">Confirm New Password</label>
                        <div class="relative"><input id="confirmPassword" name="confirm_password" type="password" autocomplete="new-password" required class="w-full rounded-xl border border-slate-300 px-4 py-3 pr-12 text-sm shadow-sm outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"><button type="button" data-password-toggle="confirmPassword" class="absolute inset-y-0 right-0 inline-flex w-12 items-center justify-center rounded-r-xl text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500" aria-label="Show confirmation password"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4" aria-live="polite">
                        <p class="text-sm font-semibold text-slate-800">Password requirements</p>
                        <ul class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-2">
                            <li data-rule="length">○ At least 8 characters</li><li data-rule="upper">○ One uppercase letter</li><li data-rule="lower">○ One lowercase letter</li><li data-rule="number">○ One number</li><li data-rule="special">○ One special character</li>
                        </ul>
                    </div>

                    <button type="submit" class="inline-flex h-12 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-200">Change Password &amp; Continue</button>
                </form>
            </div>
        </section>
    </main>
    <script>
        const newPassword = document.getElementById('newPassword');
        const rules = {
            length: (value) => value.length >= 8,
            upper: (value) => /[A-Z]/.test(value),
            lower: (value) => /[a-z]/.test(value),
            number: (value) => /[0-9]/.test(value),
            special: (value) => /[^A-Za-z0-9]/.test(value)
        };
        function updatePasswordRules() {
            const value = newPassword?.value || '';
            Object.entries(rules).forEach(([key, check]) => {
                const item = document.querySelector(`[data-rule="${key}"]`);
                if (!item) return;
                const passed = check(value);
                item.textContent = `${passed ? '✓' : '○'} ${item.textContent.substring(2)}`;
                item.classList.toggle('font-semibold', passed);
                item.classList.toggle('text-emerald-700', passed);
                item.classList.toggle('text-slate-500', !passed);
            });
        }
        newPassword?.addEventListener('input', updatePasswordRules);
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordToggle || '');
                if (!input) return;
                const visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                button.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
            });
        });
        <?php if ($showSuccess): ?>
        Swal.fire({ icon: 'success', title: 'Password Updated', text: 'Your account is now secured.', confirmButtonColor: '#047857', allowOutsideClick: false, allowEscapeKey: false })
            .then(() => { window.location.replace(<?= json_encode($dashboardUrl) ?>); });
        <?php endif; ?>
    </script>
</body>
</html>
