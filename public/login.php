<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/role_check.php';

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

// Secure session cookie settings (works on localhost and production HTTPS)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
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
    redirectByRole((string) $_SESSION['role']);
}

$error = '';
$email = '';
$genericAuthError = 'Invalid email or password.';
$maxAttempts = 5;
$lockoutSeconds = 900; // 15 minutes

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return $stmt->fetchColumn() !== false;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
         LIMIT 1'
    );
    $stmt->execute([
        'table_name' => $table,
    ]);

    return $stmt->fetchColumn() !== false;
}

function shouldRepairArchivedAccount(array $user, bool $usesModernSchema, bool $hasUserYearStatusTable): bool
{
    if (!$usesModernSchema || $hasUserYearStatusTable) {
        return false;
    }

    return isset($user['role'], $user['status'])
        && in_array((string) $user['role'], ['coordinator', 'student'], true)
        && (string) $user['status'] === 'archived';
}

function loginThrottleKey(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    return hash('sha256', $ip . '|' . $ua);
}

function initLoginThrottle(string $key): void
{
    if (!isset($_SESSION['login_throttle']) || !is_array($_SESSION['login_throttle'])) {
        $_SESSION['login_throttle'] = [];
    }

    if (!isset($_SESSION['login_throttle'][$key]) || !is_array($_SESSION['login_throttle'][$key])) {
        $_SESSION['login_throttle'][$key] = [
            'attempts' => 0,
            'blocked_until' => 0,
        ];
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function safeTrimPost(string $key): string
{
    $value = (string)($_POST[$key] ?? '');
    return trim(filter_var($value, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
}

function loginMathChallenge(): array
{
    $challenge = $_SESSION['login_math'] ?? null;
    if (
        !is_array($challenge)
        || !isset($challenge['a'], $challenge['b'], $challenge['answer'])
        || !is_int($challenge['a'])
        || !is_int($challenge['b'])
        || !is_int($challenge['answer'])
    ) {
        $a = random_int(10, 30);
        $b = random_int(1, 9);
        $challenge = [
            'a' => $a,
            'b' => $b,
            'answer' => $a + $b,
        ];
        $_SESSION['login_math'] = $challenge;
    }

    return $challenge;
}

function regenerateLoginMathChallenge(): array
{
    $a = random_int(10, 30);
    $b = random_int(1, 9);
    $challenge = [
        'a' => $a,
        'b' => $b,
        'answer' => $a + $b,
    ];
    $_SESSION['login_math'] = $challenge;
    return $challenge;
}

$throttleKey = loginThrottleKey();
initLoginThrottle($throttleKey);
$throttleState = &$_SESSION['login_throttle'][$throttleKey];
$flashError = (string)($_SESSION['login_flash_error'] ?? '');
$flashEmail = (string)($_SESSION['login_flash_email'] ?? '');
$flashSuccess = (string)($_SESSION['login_flash_success'] ?? '');
unset($_SESSION['login_flash_error'], $_SESSION['login_flash_email'], $_SESSION['login_flash_success']);

if ($flashError !== '') {
    $error = $flashError;
}
if ($flashEmail !== '') {
    $email = $flashEmail;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = safeTrimPost('email');
    $password = (string) ($_POST['password'] ?? '');
    $mathAnswer = safeTrimPost('math_answer');
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $nowTs = time();
    $challenge = loginMathChallenge();

    if (isset($_POST['refresh_challenge'])) {
        regenerateLoginMathChallenge();
        $_SESSION['login_flash_email'] = $email;
        header('Location: login.php');
        exit;
    } elseif (($throttleState['blocked_until'] ?? 0) > $nowTs) {
        $error = 'Too many login attempts. Please try again later.';
    } elseif ($submittedToken === '' || !hash_equals((string)csrfToken(), $submittedToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif ($mathAnswer === '' || !ctype_digit($mathAnswer) || (int)$mathAnswer !== (int)$challenge['answer']) {
        $error = 'Please solve the verification challenge correctly.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = $genericAuthError;
    } else {
        try {
            $pdo = getPDO();
            $usesModernSchema = columnExists($pdo, 'users', 'role_id')
                && columnExists($pdo, 'users', 'account_status');
            $hasUserYearStatusTable = tableExists($pdo, 'user_school_year_status');

            if ($usesModernSchema) {
                $stmt = $pdo->prepare(
                    'SELECT
                        u.id,
                        COALESCE(NULLIF(TRIM(CONCAT_WS(" ", up.first_name, up.middle_name, up.last_name)), ""), u.email) AS full_name,
                        u.email,
                        u.password_hash,
                        r.role_code AS role,
                        u.account_status AS status
                     FROM users u
                     JOIN roles r ON r.id = u.role_id
                     LEFT JOIN user_profiles up ON up.user_id = u.id
                     WHERE u.email = :email
                     LIMIT 1'
                );
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id, full_name, email, password_hash, role, status
                     FROM users
                     WHERE email = :email
                     LIMIT 1'
                );
            }

            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (
                $user
                && password_verify($password, (string) $user['password_hash'])
                && shouldRepairArchivedAccount($user, $usesModernSchema, $hasUserYearStatusTable)
            ) {
                $repairStmt = $pdo->prepare(
                    'UPDATE users
                     SET account_status = :account_status
                     WHERE id = :id'
                );
                $repairStmt->execute([
                    'account_status' => 'active',
                    'id' => (int) $user['id'],
                ]);
                $user['status'] = 'active';
            }

            if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $throttleState['attempts'] = 0;
                $throttleState['blocked_until'] = 0;

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['name'] = (string) $user['full_name'];
                $_SESSION['email'] = (string) $user['email'];
                $_SESSION['role'] = (string) $user['role'];

                if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                    $rehashStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                    $rehashStmt->execute([
                        'hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => (int)$user['id'],
                    ]);
                }

                redirectByRole((string) $user['role']);
            }

            $throttleState['attempts'] = (int)($throttleState['attempts'] ?? 0) + 1;
            if ($throttleState['attempts'] >= $maxAttempts) {
                $throttleState['blocked_until'] = $nowTs + $lockoutSeconds;
            }
            $error = $genericAuthError;
        } catch (PDOException) {
            $error = 'Login is temporarily unavailable. Please try again later.';
        }
    }

    regenerateLoginMathChallenge();
    $_SESSION['login_flash_error'] = $error;
    $_SESSION['login_flash_email'] = $email;
    header('Location: login.php');
    exit;
}

$csrfToken = csrfToken();
$mathChallenge = loginMathChallenge();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Login | PHINMA Practicum</title>
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
    <style>
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes softPulse {
            0%, 100% { transform: scale(1); opacity: 0.8; }
            50% { transform: scale(1.04); opacity: 1; }
        }
        .anim-fade-up { animation: fadeUp .55s ease-out both; }
        .anim-fade-in { animation: fadeIn .65s ease-out both; }
        .anim-pulse-soft { animation: softPulse 4.5s ease-in-out infinite; }
        .anim-delay-1 { animation-delay: .08s; }
        .anim-delay-2 { animation-delay: .16s; }
        .anim-delay-3 { animation-delay: .24s; }
    </style>
    <main class="min-h-screen lg:grid lg:grid-cols-2">
        <section class="relative hidden overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-emerald-400 px-6 py-14 sm:px-10 lg:block lg:px-16">
            <div class="absolute -left-12 -top-12 h-44 w-44 rounded-full bg-white/10 blur-xl"></div>
            <div class="absolute -bottom-16 -right-16 h-56 w-56 rounded-full bg-white/10 blur-xl"></div>
            <div class="relative mx-auto flex h-full max-w-xl flex-col items-center justify-center text-center text-white">
                <img src="../assets/images/logo_college.png" alt="Cagayan De Oro College Logo" class="anim-fade-in anim-pulse-soft mb-6 w-40 drop-shadow-xl sm:w-52">
                <h1 class="anim-fade-up text-3xl font-extrabold leading-tight sm:text-4xl lg:text-5xl">Cagayan De Oro College</h1>
                <p class="anim-fade-up anim-delay-1 mt-4 max-w-2xl text-sm text-emerald-50 sm:text-base">
                    Practicum Management System with Attendance Monitoring for Education Practicum Students.
                </p>
                <p class="anim-fade-up anim-delay-2 mt-5 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-100">PHINMA Cagayan de Oro College</p>
            </div>
        </section>

        <section class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-8">
            <div class="anim-fade-up w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8">
                <a href="index.php" class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 transition hover:translate-x-0.5 hover:text-brand-900">
                    <span aria-hidden="true">&larr;</span>
                    <span>Back to Homepage</span>
                </a>

                <img src="../assets/images/logo.png" alt="PHINMA Mark" class="anim-fade-in anim-delay-1 mx-auto mt-5 w-20">
                <h2 class="anim-fade-up anim-delay-2 mt-4 text-center text-4xl font-extrabold leading-none tracking-tight">System Login</h2>

                <?php if ($error !== ''): ?>
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($flashSuccess !== ''): ?>
                    <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700"><?= htmlspecialchars($flashSuccess) ?></div>
                <?php endif; ?>

                <form id="loginForm" method="post" novalidate class="mt-5 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div>
                        <label for="email" class="mb-1 block text-sm font-semibold text-slate-700">Email Address</label>
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

                    <div>
                        <label for="password" class="mb-1 block text-sm font-semibold text-slate-700">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            required
                            class="h-12 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                        >
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Verification</label>
                        <div class="grid grid-cols-[44px_14px_44px_14px_minmax(0,1fr)_44px] items-center gap-2 sm:grid-cols-[48px_16px_48px_16px_minmax(0,1fr)_48px] sm:gap-3">
                            <input type="text" value="<?= (int)$mathChallenge['a'] ?>" readonly aria-label="First number" class="h-11 w-12 rounded-lg border border-slate-300 bg-slate-50 text-center text-base font-semibold text-slate-700">
                            <span class="text-base font-bold text-slate-600">+</span>
                            <input type="text" value="<?= (int)$mathChallenge['b'] ?>" readonly aria-label="Second number" class="h-11 w-12 rounded-lg border border-slate-300 bg-slate-50 text-center text-base font-semibold text-slate-700">
                            <span class="text-base font-bold text-slate-600">=</span>
                            <input
                                type="text"
                                name="math_answer"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                placeholder="?"
                                aria-label="Verification answer"
                                required
                                class="h-11 min-w-0 flex-1 rounded-lg border border-slate-300 px-2 text-center text-base font-semibold shadow-sm outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                            >
                            <button type="button" id="refreshChallengeBtn" title="Refresh challenge" aria-label="Refresh challenge" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-lg font-bold text-brand-700 transition hover:border-brand-500 hover:bg-brand-50">&#8635;</button>
                        </div>
                    </div>

                    <button id="loginSubmitBtn" type="submit" class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-brand-600 to-emerald-500 px-4 text-sm font-bold uppercase tracking-wide text-white transition duration-300 hover:scale-[1.01] hover:from-brand-700 hover:to-emerald-600 focus:outline-none focus:ring-4 focus:ring-brand-100">
                        <svg id="loginSpinner" class="hidden h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span id="loginBtnText">Proceed</span>
                    </button>
                </form>

                <p class="mt-4 text-center text-sm">
                    <a href="forgot_password.php" class="font-medium text-brand-700 transition hover:text-brand-900 hover:underline">Forgot Password</a>
                </p>
            </div>
        </section>
    </main>

    <script>
        (function () {
            const form = document.getElementById('loginForm');
            const submitButton = document.getElementById('loginSubmitBtn');
            const refreshButton = document.getElementById('refreshChallengeBtn');
            const spinner = document.getElementById('loginSpinner');
            const text = document.getElementById('loginBtnText');
            if (!form || !submitButton || !spinner || !text) return;

            form.addEventListener('submit', function (event) {
                const trigger = event.submitter || document.activeElement;
                if (trigger && trigger.name === 'refresh_challenge') {
                    return;
                }
                submitButton.disabled = true;
                submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                spinner.classList.remove('hidden');
                text.textContent = 'Signing in...';
            });

            refreshButton?.addEventListener('click', function () {
                const existingField = form.querySelector('input[name="refresh_challenge"]');
                if (existingField) {
                    existingField.remove();
                }

                const refreshField = document.createElement('input');
                refreshField.type = 'hidden';
                refreshField.name = 'refresh_challenge';
                refreshField.value = '1';
                form.appendChild(refreshField);
                form.submit();
            });
        })();
    </script>
</body>
</html>
