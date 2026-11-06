<?php

declare(strict_types=1);

final class StudentCredentialMailer
{
    private const DEFAULT_PHINMAED_SMTP_USER = 'jopa.portrias.coc@phinmaed.com';
    private const DEFAULT_SMTP_TIMEOUT_SECONDS = 4;
    private const DEFAULT_SMTP_TIMELIMIT_SECONDS = 4;

    private ?string $cachedTransportFailure = null;

    public function sendStudentCredentials(string $email, string $schoolId, string $plainPassword): array
    {
        return $this->sendCredentials(
            $email,
            'Your Practicum System Account',
            $this->buildHtmlBody(
                'student',
                'Welcome to Practicum System',
                'Your student account has been created by the Head Teacher.',
                $email,
                $schoolId,
                $plainPassword
            ),
            $this->buildTextBody(
                'student',
                'Welcome to Practicum System',
                'Your student account has been created by the Head Teacher.',
                $email,
                $schoolId,
                $plainPassword
            )
        );
    }

    public function sendCoordinatorCredentials(string $email, string $schoolId, string $plainPassword): array
    {
        return $this->sendCredentials(
            $email,
            'Your Coordinator Practicum System Account',
            $this->buildHtmlBody(
                'coordinator',
                'Coordinator Account Created',
                'Your account has been created by the Head Teacher.',
                $email,
                $schoolId,
                $plainPassword
            ),
            $this->buildTextBody(
                'coordinator',
                'Coordinator Account Created',
                'Your account has been created by the Head Teacher.',
                $email,
                $schoolId,
                $plainPassword
            )
        );
    }

    public function sendSupervisorCredentials(string $email, string $name, string $partneredSchool, string $plainPassword): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeSchool = htmlspecialchars($partneredSchool, ENT_QUOTES, 'UTF-8');
        $safePassword = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
        $loginUrl = $this->loginUrl();
        $safeLoginUrl = $loginUrl !== null ? htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') : null;
        $loginInstruction = $safeLoginUrl !== null
            ? 'Open the <a href="' . $safeLoginUrl . '">' . $safeLoginUrl . '</a> login page.'
            : 'Open the Practicum System login page.';

        return $this->sendCredentials(
            $email,
            'Your Supervisor Practicum System Account',
            '<h3>Supervisor Account Created</h3>'
            . '<p>Hello ' . $safeName . ', your supervisor account has been created by the Head Teacher.</p>'
            . '<p><strong>Login Details:</strong><br>Email: ' . $safeEmail . '<br>Partnered School: ' . $safeSchool . '<br>Temporary Password: ' . $safePassword . '</p>'
            . '<p>1. ' . $loginInstruction . '<br>2. Sign in using your email and temporary password.<br>3. Change your password immediately.</p>',
            implode(PHP_EOL, [
                'Supervisor Account Created',
                '',
                'Hello ' . $name . ', your supervisor account has been created by the Head Teacher.',
                '',
                'Login Details:',
                'Email: ' . $email,
                'Partnered School: ' . $partneredSchool,
                'Temporary Password: ' . $plainPassword,
                '',
                '1. Open ' . ($loginUrl ?? 'the Practicum System login page') . '.',
                '2. Sign in using your email and temporary password.',
                '3. Change your password immediately.',
            ])
        );
    }

    private function sendCredentials(string $email, string $subject, string $htmlBody, string $textBody): array
    {
        if ($this->cachedTransportFailure !== null) {
            return ['sent' => false, 'error' => $this->cachedTransportFailure];
        }

        $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoloadPath)) {
            $error = 'PHPMailer dependency is not installed.';
            $this->logFailure($email, $error);
            return ['sent' => false, 'error' => $error];
        }

        require_once $autoloadPath;

        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            $error = 'PHPMailer classes are unavailable.';
            $this->logFailure($email, $error);
            return ['sent' => false, 'error' => $error];
        }

        $smtp = $this->smtpConfig();
        $host = $smtp['host'];
        $username = $smtp['username'];
        $password = $this->smtpPassword($host, $smtp['password']);
        $fromEmail = $smtp['from_email'];
        $fromName = $smtp['from_name'];
        $secure = $smtp['secure'];
        $port = $smtp['port'];
        $debugLevel = $smtp['debug_level'];
        $debugOutput = $smtp['debug_output'];
        $timeout = $smtp['timeout'];
        $timeLimit = $smtp['timelimit'];

        if ($host === null || $username === null || $password === null || $fromEmail === null || $fromEmail === '') {
            $error = 'SMTP configuration is incomplete. Set SMTP_PASS for the PHINMAED Office365 account.';
            $this->logFailure($email, $error);
            return ['sent' => false, 'error' => $error];
        }

        try {
            // Reset PHP's request timer before each outbound email attempt so
            // batch imports are not killed mid-send by the global execution limit.
            if (function_exists('set_time_limit')) {
                @set_time_limit(30);
            }

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = $timeout;
            $mail->Timelimit = $timeLimit;
            $mail->SMTPAutoTLS = true;
            $mail->AuthType = 'LOGIN';
            $mail->SMTPKeepAlive = false;

            if ($secure !== '') {
                $mail->SMTPSecure = $secure;
            }

            if ($debugLevel > 0) {
                $mail->SMTPDebug = $debugLevel;
                $mail->Debugoutput = $debugOutput === 'html'
                    ? 'html'
                    : static function (string $message, int $level): void {
                        error_log(sprintf('Student credential SMTP debug [%d]: %s', $level, trim($message)));
                    };
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
            $mail->send();

            return ['sent' => true, 'error' => null];
        } catch (Throwable $exception) {
            $error = $this->normalizeSmtpError($exception->getMessage());
            $this->cachedTransportFailure = $error;
            $this->logFailure($email, $error);
            return ['sent' => false, 'error' => $error];
        }
    }

    private function buildHtmlBody(
        string $roleLabel,
        string $title,
        string $intro,
        string $email,
        string $schoolId,
        string $plainPassword
    ): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeIntro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeSchoolId = htmlspecialchars($schoolId, ENT_QUOTES, 'UTF-8');
        $safePassword = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
        $loginUrl = $this->loginUrl();
        $safeLoginUrl = $loginUrl !== null ? htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') : null;
        $loginInstruction = $safeLoginUrl !== null
            ? 'Open the <a href="' . $safeLoginUrl . '">' . $safeLoginUrl . '</a> login page.'
            : 'Open the Practicum System login page.';

        $safeRoleLabel = htmlspecialchars(ucfirst($roleLabel), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<h3>{$safeTitle}</h3>
<p>{$safeIntro}</p>
<p><strong>Login Details:</strong><br>Email: {$safeEmail}<br>School ID: {$safeSchoolId}<br>Default Password: {$safePassword}</p>
<p>Login Instructions:</p>
<ol>
    <li>{$loginInstruction}</li>
    <li>Sign in using your email address and the default password above.</li>
    <li>Change your password immediately after your first login.</li>
</ol>
<p><em>{$safeRoleLabel} account credentials were generated for first-time access.</em></p>
HTML;
    }

    private function buildTextBody(
        string $roleLabel,
        string $title,
        string $intro,
        string $email,
        string $schoolId,
        string $plainPassword
    ): string
    {
        $loginUrl = $this->loginUrl();

        return implode(PHP_EOL, [
            $title,
            '',
            $intro,
            '',
            'Login Details:',
            'Email: ' . $email,
            'School ID: ' . $schoolId,
            'Default Password: ' . $plainPassword,
            '',
            'Login Instructions:',
            '1. Open ' . ($loginUrl ?? 'the Practicum System login page') . '.',
            '2. Sign in using your email address and the default password above.',
            '3. Change your password immediately after your first login.',
            '',
            ucfirst($roleLabel) . ' account credentials were generated for first-time access.',
        ]);
    }

    private function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        $value = trim((string) $value);
        return $value === '' ? $default : $value;
    }

    private function smtpConfig(): array
    {
        $localConfig = $this->localConfig();
        $username = $this->configValue($localConfig, 'SMTP_USER', self::DEFAULT_PHINMAED_SMTP_USER);
        $fromEmail = $this->env('SMTP_FROM_EMAIL', $username ?? '');
        if (isset($localConfig['SMTP_FROM_EMAIL']) && trim((string) $localConfig['SMTP_FROM_EMAIL']) !== '') {
            $fromEmail = trim((string) $localConfig['SMTP_FROM_EMAIL']);
        }
        $emailForDomain = $fromEmail !== '' ? $fromEmail : ($username ?? '');
        $office365Defaults = $this->shouldUseOffice365Defaults($emailForDomain);

        $secure = strtolower((string) $this->configValue($localConfig, 'SMTP_SECURE', $office365Defaults ? 'tls' : ''));
        $defaultPort = $secure === 'ssl' ? 465 : 587;

        return [
            'host' => $this->configValue($localConfig, 'SMTP_HOST', $office365Defaults ? 'smtp.office365.com' : null),
            'username' => $username,
            'password' => $this->configValue($localConfig, 'SMTP_PASS'),
            'from_email' => $fromEmail,
            'from_name' => $this->configValue($localConfig, 'SMTP_FROM_NAME', 'Practicum System'),
            'secure' => $secure,
            'port' => (int) $this->configValue($localConfig, 'SMTP_PORT', (string) $defaultPort),
            'timeout' => max(2, (int) $this->configValue($localConfig, 'SMTP_TIMEOUT', (string) self::DEFAULT_SMTP_TIMEOUT_SECONDS)),
            'timelimit' => max(2, (int) $this->configValue($localConfig, 'SMTP_TIMELIMIT', (string) self::DEFAULT_SMTP_TIMELIMIT_SECONDS)),
            'debug_level' => max(0, (int) $this->configValue($localConfig, 'SMTP_DEBUG', '0')),
            'debug_output' => strtolower((string) $this->configValue($localConfig, 'SMTP_DEBUG_OUTPUT', 'error_log')),
        ];
    }

    private function localConfig(): array
    {
        $path = dirname(__DIR__) . '/config/smtp.local.php';
        if (!is_file($path)) {
            return [];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }

    /** Gmail displays app passwords in four-character groups. SMTP expects the compact value. */
    private function smtpPassword(?string $host, ?string $password): ?string
    {
        if ($password === null) {
            return null;
        }

        if (strtolower(rtrim(trim((string) $host), '.')) === 'smtp.gmail.com') {
            return preg_replace('/\s+/', '', $password) ?? $password;
        }

        return $password;
    }

    private function configValue(array $localConfig, string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $localConfig)) {
            $value = trim((string) $localConfig[$key]);
            return $value === '' ? $default : $value;
        }

        return $this->env($key, $default);
    }

    private function shouldUseOffice365Defaults(string $email): bool
    {
        $email = strtolower(trim($email));
        return $email !== '' && str_ends_with($email, '@phinmaed.com');
    }

    private function loginUrl(): ?string
    {
        $appUrl = $this->env('APP_URL');
        if ($appUrl === null) {
            return null;
        }

        return rtrim($appUrl, '/') . '/public/login.php';
    }

    private function logFailure(string $email, string $error): void
    {
        error_log(sprintf('Student credential email failed for %s: %s', $email, $error));
    }

    private function normalizeSmtpError(string $error): string
    {
        $trimmed = trim($error);
        if ($trimmed === '') {
            return 'SMTP delivery failed.';
        }

        $lower = strtolower($trimmed);
        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'SMTP connection timed out. Remaining emails in this import were skipped to avoid request timeout.';
        }

        return $trimmed;
    }
}
