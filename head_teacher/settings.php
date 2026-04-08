<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0) {
    header('Location: /practicum_system/public/login.php');
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

$sectionStmt = $pdo->prepare(
    "SELECT s.section_name
     FROM section_students ss
     JOIN sections s ON s.id = ss.section_id
     JOIN semesters sem ON sem.id = ss.semester_id
     WHERE ss.student_user_id = :user_id
       AND ss.enrollment_status = 'active'
       AND sem.semester_status = 'active'
     ORDER BY ss.id DESC
     LIMIT 1"
);
$sectionStmt->execute(['user_id' => $userId]);
$sectionName = (string) ($sectionStmt->fetchColumn() ?: 'Not assigned');

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

            htSetFlash('success', 'Profile updated successfully.');
        } elseif ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                throw new RuntimeException('All password fields are required.');
            }
            if (strlen($newPassword) < 8) {
                throw new RuntimeException('New password must be at least 8 characters.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('New password and confirmation do not match.');
            }

            $userStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :user_id LIMIT 1");
            $userStmt->execute(['user_id' => $userId]);
            $passwordHash = (string) $userStmt->fetchColumn();

            if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
                throw new RuntimeException('Current password is incorrect.');
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePass = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :user_id");
            $updatePass->execute([
                'password_hash' => $newHash,
                'user_id' => $userId,
            ]);

            htSetFlash('success', 'Password changed successfully.');
        }
    } catch (Throwable $e) {
        htSetFlash('error', $e->getMessage());
    }

    header('Location: settings.php');
    exit;
}

renderHeadTeacherPortalStart($user ?? [], 'settings');
?>
<section class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
    <div class="h-28 bg-gradient-to-r from-emerald-600 via-emerald-500 to-emerald-700"></div>
    <div class="relative p-6 pt-0">
        <div class="-mt-10 mb-6 flex flex-col gap-6 lg:flex-row">
            <div class="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 lg:w-72">
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-emerald-100 text-xl font-bold text-emerald-700">
                        <?= htmlspecialchars(strtoupper(substr((string) ($profile['first_name'] ?? 'U'), 0, 1))) ?>
                    </div>
                    <div>
                        <p class="text-base font-semibold text-slate-900"><?= htmlspecialchars(trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? '')))) ?></p>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars((string) ($account['school_id'] ?? '')) ?></p>
                    </div>
                </div>
                <div class="space-y-2 rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700">
                    <p><span class="font-semibold">Email:</span> <?= htmlspecialchars((string) ($account['email'] ?? '')) ?></p>
                    <p><span class="font-semibold">Section:</span> <?= htmlspecialchars($sectionName) ?></p>
                </div>
            </div>

            <div class="min-w-0 flex-1 space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h3 class="text-xl font-semibold text-slate-900">Personal Information</h3>
                    </div>
                    <form method="post" class="grid gap-4 p-5 md:grid-cols-2">
                        <input type="hidden" name="settings_action" value="update_profile">
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">First Name</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars((string) ($profile['first_name'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Last Name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars((string) ($profile['last_name'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Middle Name (Optional)</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars((string) ($profile['middle_name'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Contact Number</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars((string) ($profile['phone'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800">
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600">
                                <span aria-hidden="true">&#10003;</span> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h3 class="text-xl font-semibold text-slate-900">Account Security</h3>
                    </div>
                    <form method="post" class="grid gap-4 p-5 md:grid-cols-2">
                        <input type="hidden" name="settings_action" value="change_password">
                        <div class="md:col-span-2">
                            <p class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                                We will verify your current password before applying changes.
                            </p>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Current Password</label>
                            <input type="password" name="current_password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">New Password</label>
                            <input type="password" name="new_password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500">
                                <span aria-hidden="true">&#128274;</span> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
<?php renderHeadTeacherPortalEnd(); ?>
