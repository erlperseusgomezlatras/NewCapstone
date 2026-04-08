<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
ensureSessionStarted();

if (isLoggedIn()) {
    header('Location: /practicum_system/public/login.php');
    exit;
}

$error = '';
$success = '';
$email = '';
$otpCode = '';

function forgotPasswordToken(): string
{
    if (empty($_SESSION['forgot_password_csrf']) || !is_string($_SESSION['forgot_password_csrf'])) {
        $_SESSION['forgot_password_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['forgot_password_csrf'];
}

function forgotPasswordValue(string $key): string
{
    $value = (string) ($_POST[$key] ?? '');
    return trim(filter_var($value, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
}

function forgotPasswordState(): array
{
    $state = $_SESSION['forgot_password_state'] ?? null;
    if (!is_array($state)) {
        $state = [
            'email' => '',
            'otp_hash' => '',
            'expires_at' => 0,
            'resend_available_at' => 0,
            'verified' => false,
            'attempts' => 0,
        ];
        $_SESSION['forgot_password_state'] = $state;
    }

    return $state;
}

function setForgotPasswordState(array $state): void
{
    $_SESSION['forgot_password_state'] = $state;
}

function clearForgotPasswordState(): void
{
    unset($_SESSION['forgot_password_state']);
}

function mailOtpCode(string $email, string $otp): bool
{
    $subject = 'PHINMA Practicum Password Reset OTP';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: no-reply@phinmaed.com',
    ];
    $message = implode("\n", [
        'Your password reset code is: ' . $otp,
        '',
        'This code will expire in 10 minutes.',
        'If you did not request this, you can ignore this email.',
    ]);

    return @mail($email, $subject, $message, implode("\r\n", $headers));
}

function getUserIdByEmail(PDO $pdo, string $email): int
{
    $stmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

$state = forgotPasswordState();
$email = (string) ($state['email'] ?? '');
$showResetForm = (bool) ($state['verified'] ?? false);
$showOtpForm = !$showResetForm && $email !== '' && (string) ($state['otp_hash'] ?? '') !== '' && ((int) ($state['expires_at'] ?? 0) >= time());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $action = forgotPasswordValue('action');

    if ($submittedToken === '' || !hash_equals(forgotPasswordToken(), $submittedToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        try {
            $pdo = getPDO();

            if ($action === 'send_otp') {
                $email = strtolower(forgotPasswordValue('email'));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Enter a valid email address.';
                } else {
                    $now = time();
                    $userId = getUserIdByEmail($pdo, $email);

                    $state = [
                        'email' => $email,
                        'otp_hash' => '',
                        'expires_at' => 0,
                        'resend_available_at' => $now + 30,
                        'verified' => false,
                        'attempts' => 0,
                    ];

                    if ($userId > 0) {
                        $otp = (string) random_int(100000, 999999);
                        $state['otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
                        $state['expires_at'] = $now + 600;

                        if (!mailOtpCode($email, $otp)) {
                            $error = 'Unable to send the OTP email right now. Check your mail configuration and try again.';
                        }
                    }

                    if ($error === '') {
                        setForgotPasswordState($state);
                        $success = 'If that email is registered, a 6-digit OTP has been sent.';
                        $showOtpForm = true;
                        $showResetForm = false;
                    }
                }
            } elseif ($action === 'verify_otp') {
                $otpCode = forgotPasswordValue('otp_code');
                $state = forgotPasswordState();
                $email = (string) ($state['email'] ?? '');
                $showOtpForm = true;

                if ($email === '' || (string) ($state['otp_hash'] ?? '') === '') {
                    $error = 'Start with your email address first.';
                } elseif ((int) ($state['expires_at'] ?? 0) < time()) {
                    clearForgotPasswordState();
                    $showOtpForm = false;
                    $error = 'The OTP expired. Request a new code.';
                } elseif (!preg_match('/^\d{6}$/', $otpCode)) {
                    $error = 'Enter the 6-digit OTP.';
                } else {
                    $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
                    if ($state['attempts'] > 5) {
                        clearForgotPasswordState();
                        $showOtpForm = false;
                        $error = 'Too many incorrect OTP attempts. Request a new code.';
                    } elseif (password_verify($otpCode, (string) $state['otp_hash'])) {
                        $state['verified'] = true;
                        setForgotPasswordState($state);
                        $success = 'OTP verified. You can now set a new password.';
                        $showOtpForm = false;
                        $showResetForm = true;
                    } else {
                        setForgotPasswordState($state);
                        $error = 'Incorrect OTP. Please try again.';
                    }
                }
            } elseif ($action === 'resend_otp') {
                $state = forgotPasswordState();
                $email = (string) ($state['email'] ?? '');
                $showOtpForm = true;

                if ($email === '') {
                    $error = 'Start with your email address first.';
                } elseif ((int) ($state['resend_available_at'] ?? 0) > time()) {
                    $error = 'Please wait a bit before requesting another OTP.';
                } else {
                    $userId = getUserIdByEmail($pdo, $email);
                    $now = time();
                    $state['resend_available_at'] = $now + 30;
                    $state['attempts'] = 0;

                    if ($userId > 0) {
                        $otp = (string) random_int(100000, 999999);
                        $state['otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
                        $state['expires_at'] = $now + 600;

                        if (!mailOtpCode($email, $otp)) {
                            $error = 'Unable to send the OTP email right now. Check your mail configuration and try again.';
                        }
                    }

                    if ($error === '') {
                        setForgotPasswordState($state);
                        $success = 'If that email is registered, a new OTP has been sent.';
                    }
                }
            } elseif ($action === 'reset_password') {
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
                $state = forgotPasswordState();
                $email = (string) ($state['email'] ?? '');
                $showResetForm = true;

                if ($email === '' || !($state['verified'] ?? false)) {
                    $error = 'Verify the OTP before setting a new password.';
                    $showResetForm = false;
                    $showOtpForm = true;
                } elseif ($newPassword === '' || $confirmPassword === '') {
                    $error = 'Enter and confirm your new password.';
                } elseif (strlen($newPassword) < 8) {
                    $error = 'New password must be at least 8 characters.';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'New password and confirmation do not match.';
                } else {
                    $userId = getUserIdByEmail($pdo, $email);
                    if ($userId > 0) {
                        $update = $pdo->prepare(
                            'UPDATE users
                             SET password_hash = :password_hash
                             WHERE id = :id'
                        );
                        $update->execute([
                            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                            'id' => $userId,
                        ]);
                    }

                    clearForgotPasswordState();
                    $_SESSION['login_flash_success'] = 'Password reset successful. You can now log in.';
                    $_SESSION['login_flash_email'] = $email;
                    header('Location: /practicum_system/public/login.php');
                    exit;
                }
            }
        } catch (PDOException) {
            $error = 'Password reset is temporarily unavailable. Please try again later.';
        }
    }
}

$state = forgotPasswordState();
$email = $email !== '' ? $email : (string) ($state['email'] ?? '');
$showResetForm = (bool) ($state['verified'] ?? false) || $showResetForm;
$showOtpForm = !$showResetForm && $email !== '' && (string) ($state['otp_hash'] ?? '') !== '' && ((int) ($state['expires_at'] ?? 0) >= time());
$resendAvailableAt = (int) ($state['resend_available_at'] ?? 0);
$secondsUntilResend = max(0, $resendAvailableAt - time());
$csrfToken = forgotPasswordToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | PHINMA Practicum</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#ecfdf5',
                            100: '#d1fae5',
                            500: '#10b981',
                            600: '#059669',
                            700: '#047857',
                            900: '#064e3b'
                        }
                    }
                }
            }
        };
    </script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <main class="min-h-screen lg:grid lg:grid-cols-2">
        <section class="relative hidden overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-emerald-400 px-6 py-14 sm:px-10 lg:block lg:px-16">
            <div class="absolute -left-12 -top-12 h-44 w-44 rounded-full bg-white/10 blur-xl"></div>
            <div class="absolute -bottom-16 -right-16 h-56 w-56 rounded-full bg-white/10 blur-xl"></div>
            <div class="relative mx-auto flex h-full max-w-xl flex-col items-center justify-center text-center text-white">
                <img src="../assets/images/logo_college.png" alt="Cagayan De Oro College Logo" class="mb-6 w-40 drop-shadow-xl sm:w-52">
                <h1 class="text-3xl font-extrabold leading-tight sm:text-4xl lg:text-5xl">Password Recovery</h1>
                <p class="mt-4 max-w-2xl text-sm text-emerald-50 sm:text-base">
                    Enter your email, verify the OTP sent to that inbox, then choose a new password.
                </p>
            </div>
        </section>

        <section class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-8">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8">
                <a href="login.php" class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 transition hover:translate-x-0.5 hover:text-brand-900">
                    <span aria-hidden="true">&larr;</span>
                    <span>Back to Login</span>
                </a>

                <img src="../assets/images/logo.png" alt="PHINMA Mark" class="mx-auto mt-5 w-20">
                <h2 class="mt-4 text-center text-4xl font-extrabold leading-none tracking-tight">Forgot Password</h2>
                <p class="mt-3 text-center text-sm text-slate-500">Email-only reset with OTP verification.</p>

                <?php if ($error !== ''): ?>
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if (!$showOtpForm && !$showResetForm): ?>
                    <form method="post" class="mt-5 space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="send_otp">

                        <div>
                            <label for="email" class="mb-1 block text-sm font-semibold text-slate-700">Registered Email</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= htmlspecialchars($email) ?>"
                                placeholder="your.email@phinmaed.com"
                                required
                                class="h-12 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                            >
                        </div>

                        <button type="submit" class="inline-flex h-12 w-full items-center justify-center rounded-xl bg-gradient-to-r from-brand-600 to-emerald-500 px-4 text-sm font-bold uppercase tracking-wide text-white transition duration-300 hover:scale-[1.01] hover:from-brand-700 hover:to-emerald-600 focus:outline-none focus:ring-4 focus:ring-brand-100">
                            Send OTP
                        </button>
                    </form>
                <?php elseif ($showOtpForm): ?>
                    <form method="post" class="mt-5 space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="verify_otp">

                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            OTP sent to: <span class="font-semibold"><?= htmlspecialchars($email) ?></span>
                        </div>

                        <div>
                            <label for="otp_code" class="mb-1 block text-sm font-semibold text-slate-700">6-Digit OTP</label>
                            <input
                                type="text"
                                id="otp_code"
                                name="otp_code"
                                value="<?= htmlspecialchars($otpCode) ?>"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                maxlength="6"
                                placeholder="123456"
                                required
                                class="h-12 w-full rounded-lg border border-slate-300 px-3 text-center text-lg font-semibold tracking-[0.35em] shadow-sm outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                            >
                        </div>

                        <button type="submit" class="inline-flex h-12 w-full items-center justify-center rounded-xl bg-gradient-to-r from-brand-600 to-emerald-500 px-4 text-sm font-bold uppercase tracking-wide text-white transition duration-300 hover:scale-[1.01] hover:from-brand-700 hover:to-emerald-600 focus:outline-none focus:ring-4 focus:ring-brand-100">
                            Verify OTP
                        </button>
                    </form>

                    <form method="post" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="resend_otp">
                        <button type="submit" <?= $secondsUntilResend > 0 ? 'disabled' : '' ?> class="inline-flex h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60">
                            <?= $secondsUntilResend > 0 ? 'Resend OTP in ' . $secondsUntilResend . 's' : 'Resend OTP' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" class="mt-5 space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="reset_password">

                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            OTP verified for: <span class="font-semibold"><?= htmlspecialchars($email) ?></span>
                        </div>

                        <div>
                            <label for="new_password" class="mb-1 block text-sm font-semibold text-slate-700">New Password</label>
                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                placeholder="Enter new password"
                                required
                                class="h-12 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                            >
                        </div>

                        <div>
                            <label for="confirm_password" class="mb-1 block text-sm font-semibold text-slate-700">Confirm Password</label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Confirm new password"
                                required
                                class="h-12 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                            >
                        </div>

                        <button type="submit" class="inline-flex h-12 w-full items-center justify-center rounded-xl bg-gradient-to-r from-brand-600 to-emerald-500 px-4 text-sm font-bold uppercase tracking-wide text-white transition duration-300 hover:scale-[1.01] hover:from-brand-700 hover:to-emerald-600 focus:outline-none focus:ring-4 focus:ring-brand-100">
                            Reset Password
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
