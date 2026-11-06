<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/PasswordService.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$user = currentUser() ?? [];
$supervisorId = (int) ($user['id'] ?? 0);
if ($supervisorId <= 0) {
    header('Location: ' . appUrl('public/login.php'));
    exit;
}

if (empty($_SESSION['supervisor_profile_csrf']) || !is_string($_SESSION['supervisor_profile_csrf'])) {
    $_SESSION['supervisor_profile_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['supervisor_profile_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session has expired. Please refresh the page and try again.');
        }

        $action = trim((string) ($_POST['profile_action'] ?? ''));
        if ($action === 'update_profile') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            if ($firstName === '' || $lastName === '') {
                throw new RuntimeException('First name and last name are required.');
            }
            if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
                throw new RuntimeException('Contact number format is invalid.');
            }

            $update = $pdo->prepare(
                "INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, phone, photo_path)
                 VALUES (:user_id, :first_name, NULL, :last_name, :phone, NULL)
                 ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), phone = VALUES(phone)"
            );
            $update->execute(['user_id' => $supervisorId, 'first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone !== '' ? $phone : null]);
            $_SESSION['name'] = trim($firstName . ' ' . $lastName);
            $_SESSION['supervisor_profile_flash'] = ['type' => 'success', 'message' => 'Profile updated successfully.'];
        } elseif ($action === 'change_password') {
            $passwordStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :user_id LIMIT 1');
            $passwordStmt->execute(['user_id' => $supervisorId]);
            $passwordHash = (string) ($passwordStmt->fetchColumn() ?: '');
            $errors = passwordChangeValidationErrors((string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? ''), $passwordHash);
            if ($errors !== []) {
                throw new RuntimeException(implode(' ', $errors));
            }
            $changePassword = $pdo->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :user_id');
            $changePassword->execute(['password_hash' => hashUserPassword((string) $_POST['new_password']), 'user_id' => $supervisorId]);
            $_SESSION['must_change_password'] = false;
            $_SESSION['supervisor_profile_flash'] = ['type' => 'success', 'message' => 'Password changed successfully.'];
        } else {
            throw new RuntimeException('Invalid profile request.');
        }
    } catch (Throwable $e) {
        $_SESSION['supervisor_profile_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }
    header('Location: ' . appUrl('supervisor/profile.php'));
    exit;
}

$profileStmt = $pdo->prepare(
    "SELECT u.email, u.school_id, up.first_name, up.last_name, up.phone, sp.partnered_school
     FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id LEFT JOIN supervisor_profiles sp ON sp.user_id = u.id
     WHERE u.id = :supervisor_id LIMIT 1"
);
$profileStmt->execute(['supervisor_id' => $supervisorId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$flash = $_SESSION['supervisor_profile_flash'] ?? null;
unset($_SESSION['supervisor_profile_flash']);
$safe = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

renderSupervisorStart($user, 'profile');
?>
<section class="mt-6 max-w-5xl space-y-6">
    <?php if (is_array($flash)): ?><div class="rounded-2xl border px-5 py-4 text-sm font-medium <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>"><?= $safe($flash['message'] ?? '') ?></div><?php endif; ?>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-6 py-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Account profile</p><h3 class="mt-1 text-2xl font-bold text-slate-900">Personal information</h3><p class="mt-1 text-sm text-slate-500">Keep your contact information current for practicum coordination.</p></div>
        <form method="post" class="grid gap-5 p-6 md:grid-cols-2"><input type="hidden" name="csrf_token" value="<?= $safe($csrfToken) ?>"><input type="hidden" name="profile_action" value="update_profile">
            <div><label class="mb-2 block text-sm font-semibold text-slate-700">First Name</label><input name="first_name" value="<?= $safe($profile['first_name'] ?? '') ?>" required class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"></div>
            <div><label class="mb-2 block text-sm font-semibold text-slate-700">Last Name</label><input name="last_name" value="<?= $safe($profile['last_name'] ?? '') ?>" required class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"></div>
            <div><label class="mb-2 block text-sm font-semibold text-slate-700">Phone Number</label><input name="phone" value="<?= $safe($profile['phone'] ?? '') ?>" inputmode="tel" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100" placeholder="09XX XXX XXXX"></div>
            <div><label class="mb-2 block text-sm font-semibold text-slate-700">Email Address</label><input value="<?= $safe($profile['email'] ?? '') ?>" readonly class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500"></div>
            <div><label class="mb-2 block text-sm font-semibold text-slate-700">Supervisor ID</label><input value="<?= $safe($profile['school_id'] ?? '') ?>" readonly class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500"></div>
            <div><label class="mb-2 block text-sm font-semibold text-slate-700">Assigned School</label><input value="<?= $safe($profile['partnered_school'] ?? 'Not assigned') ?>" readonly class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500"></div>
            <div class="md:col-span-2"><button class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-800">Save Profile</button></div>
        </form>
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-6 py-5"><h3 class="text-2xl font-bold text-slate-900">Change Password</h3><p class="mt-1 text-sm text-slate-500">Use a strong, private password to protect practicum records.</p></div>
        <form method="post" data-password-form class="grid gap-5 p-6 md:grid-cols-2"><input type="hidden" name="csrf_token" value="<?= $safe($csrfToken) ?>"><input type="hidden" name="profile_action" value="change_password">
            <?php foreach ([['current_password', 'Current Password', 'current-password'], ['new_password', 'New Password', 'new-password'], ['confirm_password', 'Confirm New Password', 'new-password']] as [$field, $label, $autocomplete]): ?><div class="<?= $field === 'current_password' ? 'md:col-span-2' : '' ?>"><label class="mb-2 block text-sm font-semibold text-slate-700"><?= $label ?></label><div class="relative"><input id="<?= $field ?>" name="<?= $field ?>" type="password" autocomplete="<?= $autocomplete ?>" <?= $field === 'new_password' ? 'data-password-new' : ($field === 'confirm_password' ? 'data-password-confirm' : '') ?> required class="w-full rounded-xl border border-slate-300 px-4 py-3 pr-12 text-sm outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"><button type="button" data-password-toggle="<?= $field ?>" class="absolute inset-y-0 right-0 w-11 text-slate-400 hover:text-emerald-700" aria-label="Show <?= strtolower($label) ?>">&#128065;</button></div><?php if ($field === 'confirm_password'): ?><p data-password-match class="mt-1 text-sm"></p><?php endif; ?></div><?php endforeach; ?>
            <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-sm font-semibold text-slate-700">Password strength: <span data-password-strength class="text-slate-500">Not set</span></p><ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2"><li data-password-rule="length" class="text-slate-500"><span data-password-rule-icon>○</span> At least 8 characters</li><li data-password-rule="uppercase" class="text-slate-500"><span data-password-rule-icon>○</span> One uppercase letter</li><li data-password-rule="lowercase" class="text-slate-500"><span data-password-rule-icon>○</span> One lowercase letter</li><li data-password-rule="number" class="text-slate-500"><span data-password-rule-icon>○</span> One number</li><li data-password-rule="special" class="text-slate-500"><span data-password-rule-icon>○</span> One special character</li></ul></div>
            <div class="md:col-span-2"><button data-password-submit disabled class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition enabled:hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">Change Password</button></div>
        </form>
    </section>
</section>
<script src="<?= $safe(appUrl('assets/js/password-strength.js?v=2')) ?>"></script>
<script>document.querySelectorAll('[data-password-toggle]').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.passwordToggle);if(!input)return;input.type=input.type==='password'?'text':'password';}));</script>
<?php renderSupervisorEnd(); ?>
