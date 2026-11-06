<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');
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

$forcePasswordChange = isset($_GET['force_password_change'])
    && $_GET['force_password_change'] === '1'
    && !empty($_SESSION['must_change_password']);

$profileStmt = $pdo->prepare(
    "SELECT up.first_name, up.middle_name, up.last_name, up.phone, up.photo_path, u.email, u.school_id
     FROM users u
     LEFT JOIN user_profiles up ON up.user_id = u.id
     WHERE u.id = :user_id
     LIMIT 1"
);
$profileStmt->execute(['user_id' => $userId]);
$profile = $profileStmt->fetch() ?: [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'phone' => '',
    'photo_path' => '',
    'email' => '',
    'school_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['settings_action'] ?? ''));

    try {
        if ($action === 'upload_photo') {
            $file = $_FILES['profile_photo'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a photo to upload.');
            }
            if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Profile photo must be 2 MB or smaller.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file((string) $file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) {
                throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
            }

            $uploadDirectory = __DIR__ . '/../assets/uploads/profile';
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
                throw new RuntimeException('Profile photo storage could not be created.');
            }
            $filename = 'coordinator_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
            if (!move_uploaded_file((string) $file['tmp_name'], $uploadDirectory . '/' . $filename)) {
                throw new RuntimeException('The uploaded photo could not be saved.');
            }

            $photoPath = appUrl('assets/uploads/profile/' . $filename);
            $photoStmt = $pdo->prepare(
                "INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, phone, photo_path)
                 VALUES (:user_id, '', '', '', NULL, :photo_path)
                 ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path)"
            );
            $photoStmt->execute(['user_id' => $userId, 'photo_path' => $photoPath]);
            coordinatorSetFlash('success', 'Profile photo updated successfully.');
        } elseif ($action === 'update_profile') {
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
            coordinatorSetFlash('success', 'Profile updated successfully.');
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

            $newHash = hashUserPassword($newPassword);
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
            $forcePasswordChange = false;
            coordinatorSetFlash('success', 'Password changed successfully.');
        }
    } catch (Throwable $e) {
        coordinatorSetFlash('error', $e->getMessage());
    }

    header('Location: ' . appUrl('coordinator/settings.php') . ($forcePasswordChange ? '?force_password_change=1' : ''));
    exit;
}

$flash = coordinatorGetFlash();

renderCoordinatorPortalStart(
    $user ?? [],
    'settings',
    'Coordinator Settings',
    null,
    '<a href="' . htmlspecialchars(appUrl('coordinator/dashboard.php'), ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Student List</a>'
);
?>
<section class="space-y-6">
    <?php if ($flash): ?>
        <div id="coordinatorFlash" class="hidden" data-type="<?= htmlspecialchars((string) ($flash['type'] ?? 'error'), ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>
    <?php if ($forcePasswordChange): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            Your coordinator account is using the default password. Please change it now before continuing.
        </div>
    <?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-[320px,minmax(0,1fr)]">
        <aside class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-emerald-100 text-xl font-bold text-emerald-700">
                    <?php if (!empty($profile['photo_path'])): ?><img src="<?= htmlspecialchars((string) $profile['photo_path']) ?>" alt="Profile photo" class="h-full w-full object-cover"><?php else: ?><?= htmlspecialchars(strtoupper(substr((string) ($profile['first_name'] ?: 'C'), 0, 1))) ?><?php endif; ?>
                </div>
                <div>
                    <p class="text-lg font-semibold text-slate-950"><?= htmlspecialchars(trim((string) $profile['first_name'] . ' ' . (string) $profile['last_name'])) ?></p>
                    <p class="text-sm text-slate-500"><?= htmlspecialchars((string) $profile['school_id']) ?></p>
                </div>
            </div>
            <div class="mt-5 space-y-2 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                <p><span class="font-semibold">Email:</span> <?= htmlspecialchars((string) $profile['email']) ?></p>
                <p><span class="font-semibold">Role:</span> Coordinator</p>
            </div>
        </aside>

        <div class="space-y-6">
            <section class="rounded-[28px] border border-slate-200 bg-white shadow-panel">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-950">Profile</h1>
                </div>
                <form method="post" class="grid gap-4 p-6 md:grid-cols-2">
                    <input type="hidden" name="settings_action" value="update_profile">
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">First Name</label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars((string) $profile['first_name']) ?>" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100" required>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Last Name</label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars((string) $profile['last_name']) ?>" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100" required>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Middle Name</label>
                        <input type="text" name="middle_name" value="<?= htmlspecialchars((string) $profile['middle_name']) ?>" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Contact Number</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars((string) $profile['phone']) ?>" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit" class="rounded-2xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-800">Save Profile</button>
                    </div>
                </form>
                <form method="post" enctype="multipart/form-data" class="border-t border-slate-100 p-6">
                    <input type="hidden" name="settings_action" value="upload_photo">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1"><label for="profile_photo" class="mb-2 block text-sm font-semibold text-slate-700">Profile Photo</label><input id="profile_photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" class="block w-full rounded-xl border border-slate-300 bg-white text-sm text-slate-700 file:mr-4 file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-emerald-700 hover:file:bg-emerald-100" required><p class="mt-2 text-xs text-slate-500">JPG, PNG, or WEBP · maximum 2 MB.</p></div>
                        <button type="submit" class="rounded-2xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-800">Upload Photo</button>
                    </div>
                </form>
            </section>

            <section class="rounded-[28px] border border-slate-200 bg-white shadow-panel">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h2 class="text-2xl font-bold tracking-tight text-slate-950">Change Password</h2>
                </div>
                <form method="post" data-password-form class="grid gap-4 p-6 md:grid-cols-2">
                    <input type="hidden" name="settings_action" value="change_password">
                    <div class="md:col-span-2 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        Sign in with your default school ID password once, then replace it with a new private password here.
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Current Password</label>
                        <input type="password" name="current_password" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100" required>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">New Password</label>
                        <input type="password" name="new_password" data-password-new autocomplete="new-password" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100" required>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Confirm New Password</label>
                        <input type="password" name="confirm_password" data-password-confirm autocomplete="new-password" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100" required>
                        <p data-password-match class="text-sm"></p>
                    </div>
                    <div class="md:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-700">Password strength: <span data-password-strength class="text-slate-500">Not set</span></p>
                        <ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                            <li data-password-rule="length" class="text-slate-500"><span data-password-rule-icon>○</span> At least 8 characters</li>
                            <li data-password-rule="uppercase" class="text-slate-500"><span data-password-rule-icon>○</span> One uppercase letter</li>
                            <li data-password-rule="lowercase" class="text-slate-500"><span data-password-rule-icon>○</span> One lowercase letter</li>
                            <li data-password-rule="number" class="text-slate-500"><span data-password-rule-icon>○</span> One number</li>
                            <li data-password-rule="special" class="text-slate-500"><span data-password-rule-icon>○</span> One special character</li>
                        </ul>
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit" data-password-submit disabled class="rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition enabled:hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">Update Password</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</section>
<script src="<?= htmlspecialchars(appUrl('assets/js/password-strength.js?v=2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script><?php readfile(__DIR__ . '/../assets/js/password-strength.js'); ?></script>
<script>
(() => {
    const flash = document.getElementById('coordinatorFlash');
    if (flash && typeof Swal !== 'undefined') {
        const success = flash.dataset.type === 'success';
        Swal.fire({ icon: success ? 'success' : 'error', title: success ? 'Success' : 'Unable to save', text: flash.textContent.trim(), confirmButtonColor: success ? '#047857' : '#be123c' });
    }
    document.querySelectorAll('form[data-password-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.passwordConfirmed === '1' || typeof Swal === 'undefined') return;
            event.preventDefault();
            const result = await Swal.fire({ icon: 'warning', title: 'Change password?', text: 'Your current password will stop working after this change.', showCancelButton: true, confirmButtonText: 'Yes, change it', cancelButtonText: 'Cancel', confirmButtonColor: '#047857', cancelButtonColor: '#64748b' });
            if (result.isConfirmed) { form.dataset.passwordConfirmed = '1'; form.requestSubmit(); }
        });
    });
})();
</script>
<?php renderCoordinatorPortalEnd(); ?>
