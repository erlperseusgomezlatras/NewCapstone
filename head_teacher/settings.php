<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PasswordService.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0) {
    header('Location: ' . appUrl('public/login.php'));
    exit;
}

$profileStmt = $pdo->prepare(
    "SELECT first_name, middle_name, last_name, phone
     FROM user_profiles
     WHERE user_id = :user_id
     LIMIT 1"
);
$profileStmt->execute(['user_id' => $userId]);
$profile = $profileStmt->fetch() ?: [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'phone' => '',
];

$accountStmt = $pdo->prepare(
    "SELECT u.email, u.school_id
     FROM users u
     WHERE u.id = :user_id
     LIMIT 1"
);
$accountStmt->execute(['user_id' => $userId]);
$account = $accountStmt->fetch() ?: ['email' => '', 'school_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['settings_action'] ?? ''));

    try {
        if ($action === 'update_profile') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                throw new RuntimeException('First name and last name are required.');
            }
            if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
                throw new RuntimeException('Contact number format is invalid.');
            }

            $upsertProfile = $pdo->prepare(
                "INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, phone, photo_path)
                 VALUES (:user_id, :first_name, :middle_name, :last_name, :phone, NULL)
                 ON DUPLICATE KEY UPDATE
                     first_name = VALUES(first_name),
                     middle_name = VALUES(middle_name),
                     last_name = VALUES(last_name),
                     phone = VALUES(phone)"
            );
            $upsertProfile->execute([
                'user_id' => $userId,
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'phone' => $phone !== '' ? $phone : null,
            ]);

            $_SESSION['name'] = trim($firstName . ' ' . $lastName);
            htSetFlash('success', 'Profile updated successfully.');
        } elseif ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            $userStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :user_id LIMIT 1");
            $userStmt->execute(['user_id' => $userId]);
            $passwordHash = (string) $userStmt->fetchColumn();

            $passwordErrors = passwordChangeValidationErrors($currentPassword, $newPassword, $confirmPassword, $passwordHash);
            if ($passwordErrors !== []) {
                throw new RuntimeException(implode(' ', $passwordErrors));
            }

            $newHash = hashUserPassword($newPassword);
            $hasMustChangePassword = false;
            try {
                $columnCheck = $pdo->prepare(
                    "SELECT 1
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'must_change_password'
                     LIMIT 1"
                );
                $columnCheck->execute();
                $hasMustChangePassword = $columnCheck->fetchColumn() !== false;
            } catch (Throwable) {
                $hasMustChangePassword = false;
            }

            $updatePass = $pdo->prepare(
                $hasMustChangePassword
                    ? "UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :user_id"
                    : "UPDATE users SET password_hash = :password_hash WHERE id = :user_id"
            );
            $updatePass->execute([
                'password_hash' => $newHash,
                'user_id' => $userId,
            ]);
            $_SESSION['must_change_password'] = false;

            htSetFlash('success', 'Password changed successfully.');
        }
    } catch (Throwable $e) {
        htSetFlash('error', $e->getMessage());
    }

    header('Location: ' . appUrl('head_teacher/settings.php'));
    exit;
}

renderHeadTeacherPortalStart($user ?? [], 'settings');
?>
<section class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
    <?php
    $firstName = trim((string) ($profile['first_name'] ?? ''));
    $lastName = trim((string) ($profile['last_name'] ?? ''));
    $displayName = trim($firstName . ' ' . $lastName) ?: 'Head Teacher';
    $initials = strtoupper(substr($firstName !== '' ? $firstName : 'H', 0, 1) . substr($lastName !== '' ? $lastName : 'T', 0, 1));
    ?>
    <div class="border-b border-emerald-100 bg-emerald-50/70 px-6 py-5 sm:px-8">
        <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-700">Account Settings</p>
        <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Profile &amp; Security</h2>
        <p class="mt-1 text-sm text-slate-600">Manage your professional information and account security.</p>
    </div>
    <div class="p-5 sm:p-6 lg:p-8">
        <div class="grid items-start gap-6 xl:grid-cols-[300px_minmax(0,1fr)]">
            <aside class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5 shadow-sm">
                <div class="flex items-center gap-4 border-b border-slate-200 pb-5">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full border-4 border-white bg-emerald-100 text-lg font-bold tracking-wide text-emerald-700 shadow-sm" aria-label="Profile avatar initials">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-base font-bold text-slate-900"><?= htmlspecialchars($displayName) ?></p>
                        <span class="mt-1 inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-emerald-700">Head Teacher</span>
                    </div>
                </div>
                <dl class="mt-5 space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Employee ID</dt>
                        <dd class="mt-1 font-semibold text-slate-800"><?= htmlspecialchars((string) ($account['school_id'] ?? 'Not assigned')) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email Address</dt>
                        <dd class="mt-1 break-all text-slate-700"><?= htmlspecialchars((string) ($account['email'] ?? '')) ?></dd>
                    </div>
                </dl>
                <div class="mt-5 space-y-3 border-t border-slate-200 pt-4 text-sm">
                    <div class="flex items-center justify-between gap-3"><span class="text-slate-500">Last Login</span><span class="font-medium text-slate-700">Current session</span></div>
                    <div class="flex items-center justify-between gap-3"><span class="text-slate-500">Account Status</span><span class="inline-flex items-center gap-1.5 font-semibold text-emerald-700"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Active</span></div>
                </div>
                <p class="mt-5 rounded-xl border border-dashed border-slate-200 bg-white px-3 py-2.5 text-xs leading-5 text-slate-500">Your initials are shown until profile-photo support is enabled.</p>
            </aside>

            <div class="min-w-0 space-y-5">
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                        <h3 class="text-lg font-bold text-slate-900">Personal Information</h3>
                        <p class="mt-1 text-sm text-slate-500">Keep your contact details accurate for portal communication.</p>
                    </div>
                    <form method="post" class="grid gap-x-5 gap-y-4 p-5 sm:p-6 md:grid-cols-2">
                        <input type="hidden" name="settings_action" value="update_profile">
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">First Name</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars((string) ($profile['first_name'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-100" required>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Last Name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars((string) ($profile['last_name'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-100" required>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Middle Name (Optional)</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars((string) ($profile['middle_name'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Contact Number</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars((string) ($profile['phone'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Email Address</label>
                            <input type="email" value="<?= htmlspecialchars((string) ($account['email'] ?? '')) ?>" readonly class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-500">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Role</label>
                            <input type="text" value="Head Teacher" readonly class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-500">
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600">
                                <span aria-hidden="true">&#10003;</span> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                        <h3 class="text-lg font-bold text-slate-900">Account Security</h3>
                        <p class="mt-1 text-sm text-slate-500">Use a strong, unique password to protect administrative access.</p>
                    </div>
                    <form method="post" data-password-form class="grid gap-x-5 gap-y-4 p-5 sm:p-6 md:grid-cols-2">
                        <input type="hidden" name="settings_action" value="change_password">
                        <div class="md:col-span-2">
                            <p class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                                We will verify your current password before applying changes.
                            </p>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Current Password</label>
                            <div class="relative"><input id="currentPassword" type="password" name="current_password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 pr-11 text-sm text-slate-800 transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-100" required><button type="button" data-settings-password-toggle="currentPassword" class="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center text-slate-400 hover:text-slate-700" aria-label="Show current password"><span aria-hidden="true">&#128065;</span></button></div>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">New Password</label>
                            <div class="relative"><input id="newPassword" type="password" name="new_password" data-password-new autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 pr-11 text-sm text-slate-800 transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-100" required><button type="button" data-settings-password-toggle="newPassword" class="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center text-slate-400 hover:text-slate-700" aria-label="Show new password"><span aria-hidden="true">&#128065;</span></button></div>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Confirm New Password</label>
                            <div class="relative"><input id="confirmPassword" type="password" name="confirm_password" data-password-confirm autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 pr-11 text-sm text-slate-800 transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-100" required><button type="button" data-settings-password-toggle="confirmPassword" class="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center text-slate-400 hover:text-slate-700" aria-label="Show confirm password"><span aria-hidden="true">&#128065;</span></button></div>
                            <p data-password-match class="mt-1 text-sm"></p>
                        </div>
                        <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-700">Password strength: <span data-password-strength class="text-sm font-semibold text-slate-500">Not set</span></p>
                            <ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                                <li data-password-rule="length" class="text-slate-500"><span data-password-rule-icon>○</span> At least 8 characters</li>
                                <li data-password-rule="uppercase" class="text-slate-500"><span data-password-rule-icon>○</span> One uppercase letter</li>
                                <li data-password-rule="lowercase" class="text-slate-500"><span data-password-rule-icon>○</span> One lowercase letter</li>
                                <li data-password-rule="number" class="text-slate-500"><span data-password-rule-icon>○</span> One number</li>
                                <li data-password-rule="special" class="text-slate-500"><span data-password-rule-icon>○</span> One special character</li>
                            </ul>
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" data-password-submit disabled class="inline-flex items-center gap-1 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white enabled:hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50">
                                <span aria-hidden="true">&#128274;</span> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
<script src="<?= htmlspecialchars(appUrl('assets/js/password-strength.js?v=2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script><?php readfile(__DIR__ . '/../assets/js/password-strength.js'); ?></script>
<script>
    document.querySelectorAll('[data-settings-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.settingsPasswordToggle || '');
            if (!input) return;
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            button.querySelector('span').textContent = reveal ? '◉' : '👁';
        });
    });

    document.querySelectorAll('form[data-password-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.passwordConfirmed === '1' || typeof Swal === 'undefined') {
                return;
            }

            event.preventDefault();
            const result = await Swal.fire({
                icon: 'warning',
                title: 'Change password?',
                text: 'Your current password will stop working after this change.',
                showCancelButton: true,
                confirmButtonText: 'Yes, change it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#047857',
                cancelButtonColor: '#64748b'
            });

            if (result.isConfirmed) {
                form.dataset.passwordConfirmed = '1';
                form.requestSubmit();
            }
        });
    });
</script>
<?php renderHeadTeacherPortalEnd(); ?>
