<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AcademicControlService.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/HeadTeacherAdminVerification.php';
require_once __DIR__ . '/../services/SemesterLifecycleIntegrityService.php';
require_once __DIR__ . '/../middleware/logout_ui.php';

function headTeacherNavItems(): array
{
    return [
        'attendance' => ['label' => 'Attendance Intelligence', 'path' => appUrl('head_teacher/attendance_intelegence.php')],
        'holidays' => ['label' => 'School Holidays', 'path' => appUrl('head_teacher/holidays.php')],
        'reports' => ['label' => 'Reports', 'path' => appUrl('head_teacher/student_practicum_reports.php')],
        'academic' => ['label' => 'Academic Control', 'path' => appUrl('head_teacher/academic_control.php')],
        'concerns' => ['label' => 'Student Concerns', 'path' => appUrl('head_teacher/concerns.php')],
        'deployment' => ['label' => 'Practicum Deployment', 'path' => appUrl('head_teacher/practicum_deployment.php')],
        'journal_management' => ['label' => 'Journal Management', 'path' => appUrl('head_teacher/journal_management.php')],
        'evaluation_management' => ['label' => 'Evaluation Management', 'path' => appUrl('head_teacher/evaluation_management.php')],
        'evaluation' => ['label' => 'Practicum Evaluation', 'path' => appUrl('head_teacher/practicum_evaluation.php')],
        'backups' => ['label' => 'Data and Backups', 'path' => appUrl('head_teacher/backups.php')],
        'settings' => ['label' => 'Settings', 'path' => appUrl('head_teacher/settings.php')],
    ];
}

function headTeacherNavIcon(string $key): string
{
    $icons = [
        'attendance' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M4 13h4l2-7 4 12 2-5h4"/></svg>',
        'holidays' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M12 13v5M9.5 15.5h5"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M5 19h14"/><path d="M8 19V9"/><path d="M12 19V5"/><path d="M16 19v-7"/></svg>',
        'academic' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M3 8l9-4 9 4-9 4-9-4z"/><path d="M7 10v5c0 1.7 2.2 3 5 3s5-1.3 5-3v-5"/></svg>',
        'concerns' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M4 5h16v11H8l-4 4V5z"/><path d="M8 9h8M8 12h5"/></svg>',
        'deployment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M12 21s7-4.35 7-11a7 7 0 1 0-14 0c0 6.65 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>',
        'journal_management' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M14 3v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>',
        'evaluation_management' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
        'evaluation' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'backups' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><ellipse cx="12" cy="5" rx="7" ry="3"/><path d="M5 5v6c0 1.7 3.1 3 7 3s7-1.3 7-3V5"/><path d="M5 11v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.4-1.7 1.7 1.7 0 0 0-1.8.5l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H2.9a2 2 0 1 1 0-4H3a1.7 1.7 0 0 0 1.7-1.4 1.7 1.7 0 0 0-.5-1.8l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V2.9a2 2 0 1 1 4 0V3a1.7 1.7 0 0 0 1.4 1.7 1.7 1.7 0 0 0 1.8-.5l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.26.37.4.82.4 1.3s-.14.93-.4 1.3z"/></svg>',
    ];

    return $icons[$key] ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4"><circle cx="12" cy="12" r="8"/></svg>';
}

function headTeacherInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if ($parts === []) {
        return 'HT';
    }

    $first = strtoupper(substr((string) $parts[0], 0, 1));
    $last = strtoupper(substr((string) $parts[count($parts) - 1], 0, 1));

    return trim($first . $last) !== '' ? $first . $last : 'HT';
}

function htSetFlash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['ht_flash'] = ['type' => $type, 'message' => $message];
}

function htGetFlash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['ht_flash'])) {
        return null;
    }
    $flash = $_SESSION['ht_flash'];
    unset($_SESSION['ht_flash']);
    return is_array($flash) ? $flash : null;
}

function htSetSweetAlert(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['ht_sweet_alert'] = ['type' => $type, 'message' => $message];
}

function htGetSweetAlert(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $alert = $_SESSION['ht_sweet_alert'] ?? null;
    unset($_SESSION['ht_sweet_alert']);
    return is_array($alert) ? $alert : null;
}

function htParseYearLabel(string $yearLabel): array
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', $yearLabel, $m)) {
        throw new InvalidArgumentException('Invalid school year label.');
    }
    $startYear = (int) $m[1];
    $endYear = (int) $m[2];
    if ($endYear !== $startYear + 1) {
        throw new InvalidArgumentException('School year must be consecutive (e.g., 2026-2027).');
    }
    return [$startYear, $endYear];
}

function htSemesterMeta(string $semesterName, int $startYear, int $endYear): array
{
    $name = strtolower(trim($semesterName));
    if ($name === '1st semester') {
        return [1, '1st Semester', "{$startYear}-06-01", "{$startYear}-10-31"];
    }
    if ($name === '2nd semester') {
        return [2, '2nd Semester', "{$startYear}-11-01", "{$endYear}-03-31"];
    }
    if ($name === 'summer') {
        return [3, 'Summer', "{$endYear}-04-01", "{$endYear}-05-31"];
    }
    throw new InvalidArgumentException('Invalid semester name.');
}

function htCurrentSchoolYearLabel(?DateTimeImmutable $now = null): string
{
    $now = $now ?? new DateTimeImmutable('now');
    $year = (int) $now->format('Y');
    $month = (int) $now->format('n');

    if ($month >= 6) {
        return $year . '-' . ($year + 1);
    }

    return ($year - 1) . '-' . $year;
}

function htExpandSchoolYearOptions(array $rows, ?string $currentLabel = null): array
{
    $labelToId = [];
    $startYears = [];
    $currentSchoolYearLabel = htCurrentSchoolYearLabel();
    [$currentStartYear] = htParseYearLabel($currentSchoolYearLabel);

    foreach ($rows as $row) {
        $label = trim((string) ($row['year_label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $labelToId[$label] = $id;
        if (preg_match('/^(\d{4})-(\d{4})$/', $label, $m)) {
            $startYears[] = (int) $m[1];
        }
    }

    if ($currentLabel !== null && preg_match('/^(\d{4})-(\d{4})$/', $currentLabel, $m)) {
        $startYears[] = (int) $m[1];
    }

    $startYears[] = $currentStartYear;
    $minStart = min(min($startYears), $currentStartYear - 2);
    $maxStart = max(max($startYears), $currentStartYear + 20);

    for ($start = $minStart; $start <= $maxStart; $start++) {
        $label = $start . '-' . ($start + 1);
        if (!isset($labelToId[$label])) {
            $labelToId[$label] = 0;
        }
    }

    $labels = array_keys($labelToId);
    usort($labels, static function (string $a, string $b): int {
        return strcmp($b, $a);
    });

    $out = [];
    foreach ($labels as $label) {
        $out[] = [
            'id' => (int) ($labelToId[$label] ?? 0),
            'year_label' => $label,
        ];
    }

    return $out;
}

function htEnsureFutureSchoolYears(PDO $pdo, int $count = 3): void
{
    if ($count <= 0) {
        return;
    }

    $latestYearStmt = $pdo->query(
        "SELECT year_label
         FROM school_years
         ORDER BY start_date DESC, id DESC
         LIMIT 1"
    );
    $latestYearLabel = $latestYearStmt->fetchColumn();
    if ($latestYearLabel === false) {
        return;
    }

    [$startYear, $endYear] = htParseYearLabel((string) $latestYearLabel);
    $checkStmt = $pdo->prepare('SELECT id FROM school_years WHERE year_label = :year_label LIMIT 1');
    $insertStmt = $pdo->prepare(
        'INSERT INTO school_years (year_label, start_date, end_date, year_status)
         VALUES (:year_label, :start_date, :end_date, :year_status)'
    );

    for ($offset = 1; $offset <= $count; $offset++) {
        $newStartYear = $startYear + $offset;
        $newEndYear = $endYear + $offset;
        $newYearLabel = $newStartYear . '-' . $newEndYear;

        $checkStmt->execute(['year_label' => $newYearLabel]);
        if ($checkStmt->fetchColumn() !== false) {
            continue;
        }

        $insertStmt->execute([
            'year_label' => $newYearLabel,
            'start_date' => $newStartYear . '-06-01',
            'end_date' => $newEndYear . '-05-31',
            'year_status' => 'planned',
        ]);
        htCreateDefaultSemesters($pdo, (int) $pdo->lastInsertId(), $newStartYear, $newEndYear);
    }
}

function htCreateDefaultSemesters(PDO $pdo, int $schoolYearId, int $startYear, int $endYear): void
{
    if ($schoolYearId <= 0) {
        throw new InvalidArgumentException('Invalid school year.');
    }

    $semesters = [
        [
            'semester_no' => 1,
            'semester_name' => '1st Semester',
            'start_date' => $startYear . '-06-01',
            'end_date' => $startYear . '-10-31',
        ],
        [
            'semester_no' => 2,
            'semester_name' => '2nd Semester',
            'start_date' => $startYear . '-11-01',
            'end_date' => $endYear . '-03-31',
        ],
    ];

    $checkStmt = $pdo->prepare(
        'SELECT id
         FROM semesters
         WHERE school_year_id = :school_year_id
           AND semester_no = :semester_no
         LIMIT 1'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO semesters (school_year_id, semester_no, semester_name, start_date, end_date, semester_status)
         VALUES (:school_year_id, :semester_no, :semester_name, :start_date, :end_date, :semester_status)'
    );

    foreach ($semesters as $semester) {
        $checkStmt->execute([
            'school_year_id' => $schoolYearId,
            'semester_no' => (int) $semester['semester_no'],
        ]);
        if ($checkStmt->fetchColumn() !== false) {
            continue;
        }

        $insertStmt->execute([
            'school_year_id' => $schoolYearId,
            'semester_no' => (int) $semester['semester_no'],
            'semester_name' => (string) $semester['semester_name'],
            'start_date' => (string) $semester['start_date'],
            'end_date' => (string) $semester['end_date'],
            'semester_status' => 'planned',
        ]);
    }
}

function htSetPortalContext(array $context): void
{
    $GLOBALS['ht_portal_context'] = $context;
}

function htGetPortalContext(): array
{
    $ctx = $GLOBALS['ht_portal_context'] ?? [];
    return is_array($ctx) ? $ctx : [];
}

function htHandlePortalActions(PDO $pdo): void
{
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $isGet = $_SERVER['REQUEST_METHOD'] === 'GET';

    if (!$isPost && !$isGet) {
        return;
    }

    $actionSource = $isPost ? $_POST : $_GET;
    $action = trim((string) ($actionSource['portal_action'] ?? ''));
    if ($action === '') {
        return;
    }

    $redirectToCurrent = static function (): void {
        $target = (string) ($_SERVER['REQUEST_URI'] ?? appUrl('head_teacher/academic_control.php'));
        header('Location: ' . $target);
        exit;
    };

    try {
        if ($action === 'create_semester') {
            if (!$isPost) {
                return;
            }
            requireHeadTeacherAdministrativeVerification($pdo, (string) ($_POST['admin_password'] ?? ''));
            $yearLabel = trim((string) ($_POST['school_year_label'] ?? ''));
            $semesterName = trim((string) ($_POST['semester_name'] ?? ''));
            if ($yearLabel === '' || $semesterName === '') {
                throw new InvalidArgumentException('School year and semester are required.');
            }

            [$startYear, $endYear] = htParseYearLabel($yearLabel);
            [$semesterNo, $normalizedName, $startDate, $endDate] = htSemesterMeta($semesterName, $startYear, $endYear);

            $activeSemesterStmt = $pdo->query("SELECT id FROM semesters WHERE semester_status = 'active' LIMIT 1");
            if ((int) ($activeSemesterStmt->fetchColumn() ?: 0) > 0) {
                throw new InvalidArgumentException('Cannot Create Semester. Archive the active semester first.');
            }

            $pdo->beginTransaction();
            $resetSummary = [
                'deactivated_partner_schools' => 0,
            ];

            $syStmt = $pdo->prepare('SELECT id FROM school_years WHERE year_label = :year_label LIMIT 1');
            $syStmt->execute(['year_label' => $yearLabel]);
            $schoolYearId = (int) ($syStmt->fetchColumn() ?: 0);

            if ($schoolYearId <= 0) {
                $insertSy = $pdo->prepare(
                    'INSERT INTO school_years (year_label, start_date, end_date, year_status)
                     VALUES (:year_label, :start_date, :end_date, :year_status)'
                );
                $insertSy->execute([
                    'year_label' => $yearLabel,
                    'start_date' => "{$startYear}-06-01",
                    'end_date' => "{$endYear}-05-31",
                    'year_status' => 'planned',
                ]);
                $schoolYearId = (int) $pdo->lastInsertId();
            }

            $existingSemStmt = $pdo->prepare(
                'SELECT id, semester_status FROM semesters WHERE school_year_id = :school_year_id AND semester_no = :semester_no LIMIT 1'
            );
            error_log(sprintf(
                'Semester duplicate check: year_label=%s; school_year_id=%d; semester_no=%d; semester_name=%s',
                $yearLabel,
                $schoolYearId,
                $semesterNo,
                $normalizedName
            ));
            $existingSemStmt->execute([
                'school_year_id' => $schoolYearId,
                'semester_no' => $semesterNo,
            ]);
            $existingSemester = $existingSemStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $existingSemesterId = (int) ($existingSemester['id'] ?? 0);

            if ($existingSemesterId > 0) {
                if ((string) ($existingSemester['semester_status'] ?? '') !== 'planned') {
                    throw new InvalidArgumentException(sprintf(
                        'Cannot Create Semester. %s (%s) is already in use.',
                        $normalizedName,
                        $yearLabel
                    ));
                }
                $pdo->commit();
                htSetFlash('success', sprintf('%s (%s) is ready to activate.', $normalizedName, $yearLabel));
                header('Location: ' . appUrl('head_teacher/academic_control.php?semester_id=' . $existingSemesterId));
                exit;
            }

            $insertSem = $pdo->prepare(
                'INSERT INTO semesters (school_year_id, semester_no, semester_name, start_date, end_date, semester_status)
                 VALUES (:school_year_id, :semester_no, :semester_name, :start_date, :end_date, :semester_status)'
            );
            $insertSem->execute([
                'school_year_id' => $schoolYearId,
                'semester_no' => $semesterNo,
                'semester_name' => $normalizedName,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'semester_status' => 'planned',
            ]);

            $createdSemesterId = (int) $pdo->lastInsertId();
            $pdo->commit();
            htSetFlash('success', sprintf(
                'Created %s (%s) successfully. Schools deactivated: %d. Import a fresh CSV for this semester before coordinators add students.',
                $normalizedName,
                $yearLabel,
                (int) ($resetSummary['deactivated_partner_schools'] ?? 0)
            ));
            header('Location: ' . appUrl('head_teacher/academic_control.php?semester_id=' . $createdSemesterId));
            exit;
        }

        if ($action === 'start_semester') {
            requireHeadTeacherAdministrativeVerification($pdo, (string) ($actionSource['admin_password'] ?? ''));
            $semesterId = (int) ($actionSource['semester_id'] ?? 0);
            if ($semesterId <= 0) {
                throw new InvalidArgumentException('Invalid semester selection.');
            }
            $service = new AcademicControlService($pdo);
            $result = $service->startSemester($semesterId);
            htSetFlash('success', !empty($result['already_prepared'])
                ? 'Semester has already been prepared and activated.'
                : 'Semester is now active. Practicum is not yet started; attendance and week calculations remain disabled.');
            $redirectToCurrent();
        }

        if ($action === 'end_semester') {
            requireHeadTeacherAdministrativeVerification($pdo, (string) ($actionSource['admin_password'] ?? ''));
            $semesterId = (int) ($actionSource['semester_id'] ?? 0);
            if ($semesterId <= 0) {
                throw new InvalidArgumentException('Invalid semester selection.');
            }

            $service = new AcademicControlService($pdo);
            $result = $service->endSemester($semesterId, (int) ($user['id'] ?? 0));
            htSetFlash('success', trim(sprintf(
                'Semester ended successfully. Students completed: %d, coordinator deployments ended: %d, coordinators deactivated: %d, schools deactivated: %d.',
                (int) ($result['completed_students'] ?? 0),
                (int) ($result['ended_coordinator_deployments'] ?? 0),
                (int) ($result['deactivated_coordinators'] ?? 0),
                (int) ($result['deactivated_partner_schools'] ?? 0)
            )));
            $redirectToCurrent();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            error_log(sprintf('Head Teacher portal action "%s" failed: %s', $action, $e->getMessage()));
            htSetFlash('error', 'Unable to complete the requested action. Please contact administrator.');
        } else {
            htSetFlash('error', $e->getMessage());
        }
        $redirectToCurrent();
    }
}

function renderHeadTeacherPortalStart(array $user, string $activeKey): void
{
    $pdo = getPDO();
    $profileNameStmt = $pdo->prepare(
        "SELECT TRIM(CONCAT_WS(' ', first_name, last_name))
         FROM user_profiles
         WHERE user_id = :user_id
         LIMIT 1"
    );
    $profileNameStmt->execute(['user_id' => (int) ($user['id'] ?? 0)]);
    $profileName = trim((string) ($profileNameStmt->fetchColumn() ?: ''));
    if ($profileName !== '') {
        $user['name'] = $profileName;
        $_SESSION['name'] = $profileName;
    }
    $profileInitials = headTeacherInitials((string) ($user['name'] ?? 'Head Teacher'));
    htHandlePortalActions($pdo);

    // Keep the portal and Reports aligned with semester_status. This repairs
    // stale school_years.year_status values left by earlier lifecycle code.
    try {
        (new SemesterLifecycleIntegrityService($pdo))->repair();
    } catch (Throwable $e) {
        error_log('Head Teacher semester lifecycle integrity repair failed: ' . $e->getMessage());
    }

    // A date only becomes a practicum date after the Head Teacher explicitly
    // starts or schedules practicum. Older activation code populated today's
    // date even while the lifecycle was still "not started", which made an
    // unconfigured practicum look overdue.
    try {
        $pdo->exec(
            "UPDATE semesters
             SET practicum_start_date = NULL
             WHERE semester_status = 'active'
               AND practicum_status IN ('not_started', 'not_yet_started', 'not_configured', 'establishing')
               AND practicum_start_date IS NOT NULL"
        );
    } catch (Throwable $e) {
        error_log('Head Teacher practicum-date integrity repair failed: ' . $e->getMessage());
    }

    $flash = htGetFlash();
    $navItems = headTeacherNavItems();

    $semesterRows = $pdo->query(
        "SELECT
            sem.id,
            sem.semester_name,
            sem.semester_no,
            sem.semester_status,
            sem.practicum_start_date,
            sem.practicum_status,
            sy.id AS school_year_id,
            sy.year_label,
            sy.year_status
         FROM semesters sem
         JOIN school_years sy ON sy.id = sem.school_year_id
         ORDER BY
            CASE sem.semester_status
                WHEN 'active' THEN 0
                WHEN 'planned' THEN 1
                WHEN 'closed' THEN 2
                ELSE 3
            END ASC,
            sem.id DESC"
    )->fetchAll();

    // A current semester can only be active or planned. Archived/closed rows
    // are historical records and must never be relabelled as a new plan.
    $currentSemester = null;
    $requestedSemesterId = max(0, (int) ($_GET['semester_id'] ?? 0));
    $previousSemester = null;
    foreach ($semesterRows as $semesterRow) {
        $status = (string) ($semesterRow['semester_status'] ?? '');
        if ($requestedSemesterId > 0 && (int) ($semesterRow['id'] ?? 0) === $requestedSemesterId
            && in_array($status, ['active', 'planned'], true)) {
            $currentSemester = $semesterRow;
        }
        if ($currentSemester === null && in_array($status, ['active', 'planned'], true)) {
            $currentSemester = $semesterRow;
        }
        if (in_array($status, ['completed', 'archived', 'closed'], true)
            && ($previousSemester === null || (int) $semesterRow['id'] > (int) $previousSemester['id'])) {
            $previousSemester = $semesterRow;
        }
    }
    $hasActiveSemester = (bool) array_filter($semesterRows, static fn(array $semester): bool => (string) ($semester['semester_status'] ?? '') === 'active');
    // A production-reset installation starts from the earliest configured
    // school year and first term, not the highest future school-year record.
    // A requested semester or a real active semester always takes precedence.
    if (!$hasActiveSemester && $requestedSemesterId === 0) {
        foreach ($semesterRows as $semesterRow) {
            if ((string) ($semesterRow['year_label'] ?? '') === '2024-2025'
                && (int) ($semesterRow['semester_no'] ?? 0) === 1
                && (string) ($semesterRow['semester_status'] ?? '') === 'planned') {
                $currentSemester = $semesterRow;
                break;
            }
        }
    }
    $currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;
    $currentPracticumStart = $currentSemester !== null ? (string) ($currentSemester['practicum_start_date'] ?? '') : '';
    $currentPracticumStatus = practicumStatus(
        $currentPracticumStart !== '' ? $currentPracticumStart : null,
        null,
        16,
        (string) ($currentSemester['practicum_status'] ?? '')
    );
    $currentPracticumEffectiveStart = practicumEffectiveStart($currentPracticumStart);
    $practicumDateLocked = $currentPracticumStatus !== 'not_started';
    $adminUnlocked = headTeacherAdminSessionIsValid((int) ($user['id'] ?? 0));
    $adminVerificationExpiresAt = $adminUnlocked ? headTeacherAdminSessionExpiresAt() : 0;
    $schoolYears = $pdo->query('SELECT id, year_label FROM school_years ORDER BY start_date DESC')->fetchAll();
    if (!is_array($schoolYears) || count($schoolYears) === 0) {
        $fallbackYears = $pdo->query(
            "SELECT DISTINCT sy.id, sy.year_label
             FROM semesters sem
             JOIN school_years sy ON sy.id = sem.school_year_id
             ORDER BY sy.year_label DESC"
        )->fetchAll();
        $schoolYears = is_array($fallbackYears) ? $fallbackYears : [];
    }
    $schoolYears = array_values(array_filter($schoolYears, static function ($row): bool {
        return isset($row['year_label']) && trim((string) $row['year_label']) !== '';
    }));
    if (count($schoolYears) === 0) {
        $fallbackLabel = htCurrentSchoolYearLabel();
        $schoolYears = [['id' => 0, 'year_label' => $fallbackLabel]];
    }
    $selectedYearLabel = (string) ($currentSemester['year_label'] ?? htCurrentSchoolYearLabel());
    $schoolYears = htExpandSchoolYearOptions($schoolYears, $selectedYearLabel);
    htSetPortalContext([
        'school_years' => $schoolYears,
        'selected_year_label' => $selectedYearLabel,
    ]);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Head Teacher Portal</title>
    <link rel="icon" type="image/png" href="/assets/images/logo_college.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @media (max-width: 1023px) {
            /* The collapsed desktop control must never survive a resize into
               the mobile drawer; mobile uses the full labelled action only. */
            #sidebarLogoutMini { display: none !important; }
        }
        @media (min-width: 1024px) {
            .head-teacher-sidebar.is-desktop-collapsed #sidebarLogoutFull { display: none !important; }
            .head-teacher-sidebar.is-desktop-collapsed #sidebarLogoutMini { display: flex !important; justify-content: center; }
            .head-teacher-sidebar.is-desktop-collapsed #sidebarLogoutMini .sign-out-button { width: 40px; height: 40px; border-radius: 10px; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased">
    <div class="min-h-screen lg:flex" id="appRoot">
        <div id="sidebarBackdrop" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

        <aside id="sidebar" class="head-teacher-sidebar fixed inset-y-0 left-0 z-40 h-screen w-72 -translate-x-full transform overflow-hidden bg-gradient-to-b from-emerald-700 via-emerald-800 to-emerald-950 text-white shadow-2xl transition-all duration-300 ease-out lg:translate-x-0 lg:w-72">
            <div class="flex h-full flex-col">
                <div class="border-b border-white/10 px-6 py-7">
                    <div class="mb-4 flex items-center justify-end lg:hidden">
                        <button id="closeSidebarBtn" type="button" class="rounded-lg p-2 text-emerald-100 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-300">X</button>
                    </div>
                    <div id="sidebarBrandWrap" class="flex items-center gap-4">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/10">
                            <img id="sidebarBrandLogo" src="../assets/images/logo_coc.png" alt="Cagayan de Oro College" class="h-14 w-auto p-2 transition-all duration-300" />
                        </div>
                        <div class="min-w-0 sidebar-brand-text">
                            <p class="truncate text-xs font-extrabold uppercase tracking-[0.18em] text-white/95">Head Teacher Portal</p>
                            <p class="mt-1 text-[11px] uppercase tracking-[0.18em] text-emerald-100/80">PHINMA Education</p>
                        </div>
                    </div>
                </div>

                <?php
                $monitoringNav = ['attendance', 'holidays', 'reports'];
                $configNav = ['academic', 'deployment', 'journal_management', 'evaluation_management', 'evaluation'];
                $secondaryNav = ['backups', 'settings'];
                $activeSemesterRequiredNav = ['attendance', 'holidays', 'deployment', 'journal_management', 'evaluation_management', 'evaluation'];
                ?>
                <nav class="flex-1 space-y-5 overflow-y-auto px-4 py-6">
                    <div>
                        <p class="sidebar-text px-4 pb-2 text-[11px] font-bold uppercase tracking-[0.18em] text-emerald-100/70">Monitoring</p>
                        <div class="space-y-2">
                            <?php foreach ($monitoringNav as $key): ?>
                                <?php if (!isset($navItems[$key])) { continue; } ?>
                                <?php $item = $navItems[$key]; ?>
                                <?php if (in_array($key, $activeSemesterRequiredNav, true) && !$hasActiveSemester): ?>
                                <button type="button" data-requires-active-semester data-active-semester-message="<?= $key === 'holidays' ? 'Please activate a semester before managing School Holidays.' : 'Please activate a semester before opening Attendance Intelligence.' ?>" class="group flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-sm font-semibold text-emerald-50/50">
                                <?php else: ?>
                                <a href="<?= htmlspecialchars($item['path']) ?>" class="group flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold transition <?= $key === $activeKey ? 'bg-white/15 text-white shadow-lg ring-1 ring-white/10' : 'text-emerald-50/90 hover:bg-white/10 hover:text-white' ?>">
                                <?php endif; ?>
                                    <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center"><?= headTeacherNavIcon($key) ?></span>
                                    <span class="sidebar-text"><?= htmlspecialchars($item['label']) ?></span>
                                <?= (in_array($key, $activeSemesterRequiredNav, true) && !$hasActiveSemester) ? '</button>' : '</a>' ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <p class="sidebar-text px-4 pb-2 text-[11px] font-bold uppercase tracking-[0.18em] text-emerald-100/70">System Configuration</p>
                        <div class="space-y-2">
                            <?php foreach ($configNav as $key): ?>
                                <?php if (!isset($navItems[$key])) { continue; } ?>
                                <?php $item = $navItems[$key]; ?>
                                <?php if (in_array($key, $activeSemesterRequiredNav, true) && !$hasActiveSemester): ?>
                                <button type="button" data-requires-active-semester data-active-semester-message="Please activate a semester before opening this module." class="group flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-sm font-semibold text-emerald-50/50">
                                <?php else: ?>
                                <a href="<?= htmlspecialchars($item['path']) ?>" class="group flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold transition <?= $key === $activeKey ? 'bg-white/15 text-white shadow-lg ring-1 ring-white/10' : 'text-emerald-50/90 hover:bg-white/10 hover:text-white' ?>">
                                <?php endif; ?>
                                    <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center"><?= headTeacherNavIcon($key) ?></span>
                                    <span class="sidebar-text"><?= htmlspecialchars($item['label']) ?></span>
                                <?= (in_array($key, $activeSemesterRequiredNav, true) && !$hasActiveSemester) ? '</button>' : '</a>' ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </nav>

                <div id="sidebarFooter" class="border-t border-white/10 px-4 py-4">
                    <div class="mb-4 space-y-2">
                        <?php foreach ($secondaryNav as $key): ?>
                            <?php if (!isset($navItems[$key])) { continue; } ?>
                            <?php $item = $navItems[$key]; ?>
                            <a href="<?= htmlspecialchars($item['path']) ?>" class="group flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold transition <?= $key === $activeKey ? 'bg-white/15 text-white shadow-lg ring-1 ring-white/10' : 'text-emerald-50/90 hover:bg-white/10 hover:text-white' ?>">
                                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center"><?= headTeacherNavIcon($key) ?></span>
                                <span class="sidebar-text"><?= htmlspecialchars($item['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div id="sidebarUserCard" class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 shadow-sm">
                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/15 bg-white/10 text-sm font-bold text-white"><?= htmlspecialchars($profileInitials) ?></span>
                        <div class="sidebar-text min-w-0">
                            <p class="truncate text-sm font-semibold text-white"><?= htmlspecialchars((string)($user['name'] ?? 'Head Teacher')) ?></p>
                            <p class="mt-0.5 truncate text-xs text-emerald-100/80">Head Teacher</p>
                        </div>
                    </div>
                    <div class="mt-3">
                        <?php renderSignOutButton('sidebarLogoutFull', true, 'sign-out-button--compact'); ?>
                        <div id="sidebarLogoutMini" class="hidden"><?php renderSignOutButton('', false, 'sign-out-button--compact'); ?></div>
                    </div>
                </div>
            </div>
        </aside>

        <main id="mainPanel" class="flex-1 transition-[margin] duration-300 lg:ml-72">
            <div class="mx-auto w-full max-w-[1600px] px-4 pb-8 pt-4 sm:px-6 lg:px-8">
                <header class="sticky top-0 z-20 rounded-2xl border border-slate-200 bg-white/95 px-4 py-4 shadow-sm backdrop-blur sm:px-6">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div class="flex items-center gap-3">
                            <button id="sidebarToggleBtn" type="button" aria-label="Collapse or expand sidebar" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="M4 6h16M4 12h16M4 18h16"/><path d="M8 4v16"/></svg></button>
                            <h1 class="text-lg font-semibold tracking-tight text-slate-900 sm:text-2xl">Head Teacher / Portal</h1>
                        </div>

                    </div>
                </header>
                <?php if ($flash): ?>
                    <div id="headTeacherFlash" class="hidden" data-type="<?= htmlspecialchars((string) ($flash['type'] ?? 'error'), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <script>
                        (() => {
                            const flash = document.getElementById('headTeacherFlash');
                            if (!flash || typeof Swal === 'undefined') return;
                            Swal.fire({
                                icon: flash.dataset.type === 'success' ? 'success' : 'error',
                                title: flash.dataset.type === 'success' ? 'Success' : 'Unable to save',
                                text: flash.textContent.trim(),
                                confirmButtonColor: '#047857'
                            });
                        })();
                    </script>
                <?php endif; ?>
                <?php if ($activeKey === 'academic' && $currentSemesterId > 0 && ($currentSemester['semester_status'] ?? '') === 'active'): ?>
                    <section class="mt-4 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_18px_45px_-30px_rgba(15,23,42,0.22)]">
                        <div class="flex flex-col gap-4 border-b border-slate-200 bg-white px-5 py-5 sm:px-7 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex items-start gap-4">
                                <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600 shadow-sm">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v2"/></svg>
                                </span>
                                <div>
                                    <h2 class="text-2xl font-bold tracking-tight text-slate-950">Administration Actions</h2>
                                    <p class="mt-1 text-sm text-slate-600">Administrative controls are locked to protect academic records.</p>
                                </div>
                            </div>
                            <div id="administrationState" class="flex flex-wrap items-center gap-2 <?= $adminUnlocked ? 'text-emerald-700' : 'text-slate-700' ?>">
                                <span id="administrationStatusBadge" class="inline-flex items-center gap-2 rounded-full <?= $adminUnlocked ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' ?> px-4 py-2 text-sm font-bold">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                                    <span id="administrationLockLabel"><?= $adminUnlocked ? 'Unlocked' : 'Locked' ?></span>
                                </span>
                                <span id="administrationCountdown" data-expires-at="<?= (int) $adminVerificationExpiresAt ?>" class="<?= $adminUnlocked ? '' : 'hidden' ?> rounded-full bg-emerald-700 px-3 py-2 text-xs font-bold text-white shadow-sm"></span>
                            </div>
                        </div>
                        <div class="grid gap-0 lg:grid-cols-12">
                            <div class="relative overflow-hidden bg-white px-5 py-7 sm:px-7 lg:col-span-7">
                                <div id="administrationLockedPanel" class="relative flex flex-col gap-5 sm:flex-row sm:items-center <?= $adminUnlocked ? 'hidden' : '' ?>">
                                    <div class="flex h-36 w-36 shrink-0 items-center justify-center rounded-[2rem] border border-slate-200 bg-slate-50 text-slate-600 shadow-inner">
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-16 w-16"><path d="M12 3 4 7v5c0 5 3.4 8.7 8 9 4.6-.3 8-4 8-9V7l-8-4Z"/><rect x="8" y="11" width="8" height="6" rx="1"/><path d="M10 11V9a2 2 0 0 1 4 0v2"/></svg>
                                    </div>
                                    <div class="max-w-xl">
                                        <h3 class="text-2xl font-bold tracking-tight text-slate-950">Administration Locked</h3>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">This section contains sensitive settings for the current semester. To make changes, verify your Head Teacher password.</p>
                                        <button id="unlockAdministrationBtn" type="button" class="mt-5 inline-flex items-center gap-3 rounded-xl bg-slate-800 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-slate-900/15 transition hover:bg-slate-900 <?= $adminUnlocked ? 'hidden' : '' ?>">
                                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.75-2.9"/><path d="m16 5 2 2-2 2"/></svg>
                                            Unlock Administration
                                            <span aria-hidden="true" class="text-lg leading-none">›</span>
                                        </button>
                                    </div>
                                </div>
                                <div id="administrationUnlockedPanel" class="relative rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5 <?= $adminUnlocked ? '' : 'hidden' ?>">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <h3 class="text-2xl font-bold tracking-tight text-slate-950">Administration Unlocked</h3>
                                            <p class="mt-1 text-sm text-slate-600">Changes are available for this verified administration session.</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <?php if ($currentPracticumStatus === 'not_started'): ?>
                                                <button id="btnStartPracticum" data-admin-action type="button" data-semester-id="<?= (int) $currentSemesterId ?>" <?= $adminUnlocked ? '' : 'disabled' ?> class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-45">Start Practicum</button>
                                            <?php endif; ?>
                                            <button id="btnEndSemester" data-admin-action type="button" data-semester-id="<?= (int) $currentSemesterId ?>" data-semester-name="<?= htmlspecialchars((string) ($currentSemester['semester_name'] ?? 'Current Semester'), ENT_QUOTES, 'UTF-8') ?>" <?= $adminUnlocked ? '' : 'disabled' ?> class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-45">End Semester</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <aside class="border-t border-slate-200 bg-slate-50/80 px-5 py-6 sm:px-7 lg:col-span-5 lg:border-l lg:border-t-0">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Current Semester</p>
                                <div class="mt-3 flex items-start gap-3">
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-200 text-slate-600"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="m3 10 9-5 9 5-9 5-9-5Z"/><path d="M7 12v4c3 2 7 2 10 0v-4"/></svg></span>
                                    <div><p class="text-lg font-bold text-slate-950">SY <?= htmlspecialchars((string) ($currentSemester['year_label'] ?? 'N/A')) ?></p><p class="text-sm font-medium text-slate-600"><?= htmlspecialchars((string) ($currentSemester['semester_name'] ?? 'No Semester')) ?></p></div>
                                </div>
                                <div class="mt-4 space-y-2 text-sm">
                                    <p class="font-semibold text-emerald-700"><span class="mr-2 inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></span>Semester Status: <?= htmlspecialchars(ucfirst((string) ($currentSemester['semester_status'] ?? 'inactive'))) ?></p>
                                    <p class="font-semibold <?= in_array($currentPracticumStatus, ['not_started', 'scheduled'], true) ? 'text-slate-600' : 'text-sky-700' ?>"><span class="mr-2 inline-block h-2.5 w-2.5 rounded-full <?= in_array($currentPracticumStatus, ['not_started', 'scheduled'], true) ? 'bg-slate-400' : 'bg-sky-500' ?>"></span>Practicum Status: <?= htmlspecialchars(practicumStatusLabel($currentPracticumStatus)) ?></p>
                                </div>
                                <div class="mt-5 border-t border-slate-200 pt-4">
                                    <label for="practicumStartDate" class="text-sm font-bold text-slate-700">Practicum/OJT Start Date</label>
                                    <input id="practicumStartDate" data-lifecycle-locked="<?= $practicumDateLocked ? 'true' : 'false' ?>" type="date" value="<?= htmlspecialchars($currentPracticumStart) ?>" min="<?= htmlspecialchars(practicumToday()) ?>" <?= ($adminUnlocked && !$practicumDateLocked) ? '' : 'disabled' ?> class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 disabled:bg-slate-100 disabled:text-slate-500">
                                    <?php if ($practicumDateLocked): ?><p class="mt-2 text-xs font-semibold text-slate-500">Locked after practicum confirmation</p><?php endif; ?>
                                    <?php if ($currentPracticumStatus === 'not_started'): ?>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">Not scheduled yet. Choose a future date and select <span class="font-semibold text-slate-700">Start Practicum</span> only when setup is complete.</p>
                                        <p class="mt-2 text-xs text-slate-500">Effective Week 1 Start: <span class="font-semibold text-slate-700">Not set</span></p>
                                    <?php else: ?>
                                        <p class="mt-3 text-xs text-slate-500">Effective Week 1 Start: <span class="font-semibold text-slate-700"><?= htmlspecialchars($currentPracticumEffectiveStart !== null ? (new DateTimeImmutable($currentPracticumEffectiveStart))->format('M j, Y') : 'Not set') ?></span></p>
                                    <?php endif; ?>
                                </div>
                            </aside>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($activeKey === 'academic' && $currentSemesterId > 0 && ($currentSemester['semester_status'] ?? '') === 'planned'): ?>
                    <section class="mt-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_18px_42px_-32px_rgba(15,23,42,0.42)] sm:p-7">
                        <div class="flex items-start gap-4">
                            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600 shadow-sm">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.1 2.1-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.55V20.3h-3v-.11A1.7 1.7 0 0 0 10.7 18.6a1.7 1.7 0 0 0-1.88.34l-.06.06-2.1-2.1.06-.06A1.7 1.7 0 0 0 7.06 15a1.7 1.7 0 0 0-1.55-1.03H5.4v-3h.11A1.7 1.7 0 0 0 7.06 9.94 1.7 1.7 0 0 0 6.72 8.06l-.06-.06 2.1-2.1.06.06a1.7 1.7 0 0 0 1.88.34 1.7 1.7 0 0 0 1.03-1.55V4.64h3v.11A1.7 1.7 0 0 0 15.76 6.3a1.7 1.7 0 0 0 1.88-.34l.06-.06L19.8 8l-.06.06a1.7 1.7 0 0 0-.34 1.88 1.7 1.7 0 0 0 1.55 1.03h.11v3h-.11A1.7 1.7 0 0 0 19.4 15Z"/></svg>
                            </span>
                            <div>
                                <h2 class="text-2xl font-bold tracking-tight text-slate-950">Administration Actions</h2>
                                <p class="mt-1 text-sm text-slate-500">Manage semester settings and activate the practicum period.</p>
                            </div>
                        </div>

                        <div class="mt-6 grid overflow-hidden rounded-2xl border border-slate-200 lg:grid-cols-12">
                            <div class="px-5 py-6 sm:px-7 lg:col-span-7">
                                <p class="text-sm font-bold text-slate-600">Current Semester</p>
                                <p class="mt-3 text-3xl font-bold tracking-tight text-slate-950">SY <?= htmlspecialchars((string) ($currentSemester['year_label'] ?? 'N/A')) ?></p>
                                <p class="mt-1 text-xl font-medium text-slate-500"><?= htmlspecialchars((string) ($currentSemester['semester_name'] ?? 'No Semester')) ?></p>
                                <div class="mt-7 grid gap-5 border-t border-slate-100 pt-5 sm:grid-cols-2">
                                    <div class="sm:border-r sm:border-slate-100 sm:pr-5">
                                        <p class="text-sm font-medium text-slate-500">Semester Status</p>
                                        <span class="mt-2 inline-flex items-center gap-2 rounded-xl bg-slate-100 px-3 py-2 text-sm font-bold uppercase text-slate-700"><span class="h-2.5 w-2.5 rounded-full bg-slate-500"></span>Planned</span>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-500">Practicum Status</p>
                                        <span class="mt-2 inline-flex items-center gap-2 rounded-xl bg-slate-100 px-3 py-2 text-sm font-bold text-slate-600"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>Not Yet Started</span>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t border-slate-200 bg-slate-50/45 px-5 py-6 sm:px-7 lg:col-span-5 lg:border-l lg:border-t-0">
                                <p class="text-sm font-bold uppercase tracking-[0.12em] text-slate-500">Actions</p>
                                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                    <button id="openSemesterModalBtn" data-admin-required="true" type="button" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-bold text-slate-800 shadow-sm transition hover:border-slate-400 hover:bg-slate-50">
                                        <span class="text-xl leading-none">+</span> Create New Semester
                                    </button>
                                    <button id="btnStartSemester" type="button" data-semester-id="<?= (int) $currentSemesterId ?>" data-semester-name="<?= htmlspecialchars((string) ($currentSemester['semester_name'] ?? 'Planned Semester'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-800">
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="m8 5 11 7-11 7V5Z"/></svg> Activate Semester
                                    </button>
                                </div>
                                <div class="mt-6 border-t border-slate-200 pt-5">
                                    <div class="flex gap-3 text-sm leading-6 text-slate-500">
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400"><path fill-rule="evenodd" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 14h-2v-6h2v6Zm0-8h-2V6h2v2Z" clip-rule="evenodd"/></svg>
                                        <p>Activate the semester before configuring the practicum start date.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($activeKey === 'academic' && $currentSemesterId === 0): ?>
                    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-sm font-bold uppercase tracking-[0.16em] text-slate-600">Administration Actions</h2>
                        <div class="mt-3">
                            <button id="openSemesterModalBtn" data-admin-required="true" type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700">Create New Semester</button>
                        </div>
                        <?php if ($previousSemester !== null): ?>
                            <div class="mt-4 border-t border-slate-200 pt-4">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Previous Semester</p>
                                <p class="mt-1 text-sm font-semibold text-slate-900">SY <?= htmlspecialchars((string) ($previousSemester['year_label'] ?? 'N/A')) ?></p>
                                <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($previousSemester['semester_name'] ?? 'No Semester')) ?></p>
                                <p class="mt-2 text-xs font-semibold text-slate-700">Semester Status: Completed</p>
                                <p class="mt-3 text-xs text-slate-500">This semester is preserved in history. Create a new semester to continue administration.</p>
                            </div>
                        <?php else: ?>
                            <p class="mt-4 text-sm text-slate-500">No current semester exists. Create a new semester to begin setup.</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
<?php
}

function renderHeadTeacherPortalEnd(string $extraScript = ''): void
{
    $portalContext = htGetPortalContext();
    $schoolYears = $portalContext['school_years'] ?? [];
    $selectedYearLabel = (string) ($portalContext['selected_year_label'] ?? '');
    $openSemesterModalOnLoad = false;

    ?>
            </div>
        </main>
    </div>

    <div id="semesterModal" class="fixed inset-0 z-50 <?= $openSemesterModalOnLoad ? 'flex' : 'hidden' ?> items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/45" id="modalBackdrop"></div>
        <div class="relative max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <h3 class="text-xl font-semibold text-slate-900">Create New Semester</h3>
                <button id="closeSemesterModalBtn" type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700">X</button>
            </div>

            <div class="space-y-5 px-6 py-5">
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">School Year <span class="text-rose-500">*</span></label>
                    <input type="hidden" id="schoolYear" name="school_year_label" form="createSemesterForm" value="<?= htmlspecialchars($selectedYearLabel) ?>">
                    <div class="relative">
                        <button id="schoolYearBtn" type="button" class="flex w-full items-center justify-between rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                            <span id="schoolYearBtnText"><?= htmlspecialchars($selectedYearLabel) ?></span>
                            <span class="text-slate-500">v</span>
                        </button>
                        <div id="schoolYearMenu" class="absolute z-20 mt-1 hidden max-h-64 w-full overflow-y-auto overscroll-contain rounded-xl border border-slate-200 bg-white shadow-lg">
                            <?php foreach ($schoolYears as $sy): ?>
                                <?php $label = trim((string) $sy['year_label']); ?>
                                <?php if ($label === '') { continue; } ?>
                                <button type="button" class="school-year-option block w-full px-4 py-2 text-left text-sm text-slate-800 hover:bg-slate-100" data-value="<?= htmlspecialchars($label) ?>">
                                    <?= htmlspecialchars($label) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="semester" class="mb-2 block text-sm font-semibold text-slate-700">Semester <span class="text-rose-500">*</span></label>
                    <select id="semester" name="semester_name" form="createSemesterForm" style="color-scheme: light; background-color: #ffffff; color: #0f172a;" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                        <option style="background-color:#ffffff;color:#0f172a;" value="1st Semester">1st Semester</option>
                        <option style="background-color:#ffffff;color:#0f172a;" value="2nd Semester" selected>2nd Semester</option>
                        <option style="background-color:#ffffff;color:#0f172a;" value="Summer">Summer</option>
                    </select>
                    <ul id="semesterAvailability" class="mt-3 space-y-1.5 text-xs text-slate-600" aria-live="polite"></ul>
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                    <p class="font-semibold">Creating new semester within the same school year</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Current semester remains in history.</li>
                        <li>Section and coordinator assignments will be reset.</li>
                        <li>Students will be unassigned for the new semester.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-semibold">Confirmation Preview</p>
                    <p id="semesterPreview" class="mt-1">You are about to create: <span class="font-semibold">2nd Semester (SY 2026-2027)</span></p>
                </div>
            </div>

            <form id="createSemesterForm" method="post" class="flex flex-col-reverse gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
                <input type="hidden" name="portal_action" value="create_semester">
                <button id="cancelSemesterModalBtn" type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                <button id="confirmCreateSemesterBtn" type="submit" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">Confirm and Create</button>
            </form>
        </div>
    </div>

    <div id="endSemesterModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/55" id="endSemesterBackdrop"></div>
        <div class="relative w-full max-w-lg rounded-3xl border border-amber-200 bg-white shadow-2xl">
            <div class="border-b border-amber-100 bg-gradient-to-r from-amber-50 via-orange-50 to-white px-6 py-5">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-amber-700">Semester Transition</p>
                <h3 class="mt-2 text-2xl font-black tracking-tight text-slate-900">End This Semester?</h3>
                <p class="mt-2 text-sm text-slate-600">This will archive the active semester and reset active operational assignments without deleting historical records.</p>
            </div>
            <div class="space-y-4 px-6 py-6">
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
                    <p class="font-semibold">The system will:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Complete the semester and preserve its history</li>
                        <li>Mark enrolled students as completed</li>
                        <li>End active deployments and geofence assignments</li>
                        <li>Keep attendance and historical records for reports</li>
                    </ul>
                </div>
                <p id="endSemesterSummary" class="text-sm font-medium text-slate-700">You are about to end the current semester.</p>
                <p id="endSemesterError" class="hidden rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"></p>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button id="cancelEndSemesterBtn" type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                    <button id="confirmEndSemesterBtn" type="button" class="inline-flex items-center justify-center rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-300">
                        <span id="confirmEndSemesterText">Complete Semester</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="startSemesterModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/55" id="startSemesterBackdrop"></div>
        <div class="relative w-full max-w-lg overflow-hidden rounded-3xl border border-emerald-200 bg-white shadow-2xl">
            <div class="border-b border-emerald-100 bg-gradient-to-r from-emerald-50 via-lime-50 to-white px-6 py-5">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-emerald-700">Semester Activation</p>
                <h3 class="mt-2 text-2xl font-black tracking-tight text-slate-900">Start Semester</h3>
                <p class="mt-2 text-sm text-slate-600">Are you sure you want to start this semester?</p>
            </div>
            <div class="space-y-4 px-6 py-6">
                <p id="startSemesterSummary" class="text-sm font-medium text-slate-700">The next planned semester will become active.</p>
                <div>
                    <label for="startSemesterPassword" class="mb-2 block text-sm font-semibold text-slate-700">Head Teacher Password</label>
                    <div class="relative">
                        <input id="startSemesterPassword" type="password" autocomplete="current-password" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 pr-11 text-sm">
                        <button type="button" data-password-toggle data-password-target="startSemesterPassword" aria-label="Show password" aria-pressed="false" class="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center rounded-r-xl text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></button>
                    </div>
                </div>
                <p id="startSemesterError" class="hidden rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"></p>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button id="cancelStartSemesterBtn" type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                    <button id="confirmStartSemester" type="button" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <span id="confirmStartSemesterText">Yes, Start</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="startPracticumModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="startPracticumTitle" aria-describedby="startPracticumMessage">
        <div class="absolute inset-0 bg-slate-900/55" id="startPracticumBackdrop"></div>
        <div class="relative w-full max-w-lg overflow-hidden rounded-3xl border border-emerald-200 bg-white shadow-2xl">
            <div class="border-b border-emerald-100 bg-gradient-to-r from-emerald-50 via-lime-50 to-white px-6 py-5">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-emerald-700">Practicum Activation</p>
                <h3 id="startPracticumTitle" class="mt-2 text-2xl font-black tracking-tight text-slate-900">Start Practicum</h3>
                <p id="startPracticumMessage" class="mt-2 text-sm text-slate-600">Attendance tracking will become active immediately.</p>
            </div>
            <div class="space-y-4 px-6 py-6">
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-900">
                    <p class="font-semibold">Starting practicum enables:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Attendance tracking</li>
                        <li>Deployment monitoring</li>
                        <li>Practicum week calculations</li>
                    </ul>
                </div>
                <p id="startPracticumError" class="hidden rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert"></p>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button id="cancelStartPracticumBtn" type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                    <button id="confirmStartPracticumBtn" type="button" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:opacity-70">
                        <span id="confirmStartPracticumText">Start Practicum</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="adminVerificationModal" class="fixed inset-0 z-[70] hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="adminVerificationTitle">
        <div class="absolute inset-0 bg-slate-900/55"></div>
        <div class="relative w-full max-w-lg overflow-hidden rounded-3xl border border-emerald-200 bg-white shadow-2xl">
            <div class="border-b border-emerald-100 bg-emerald-50 px-6 py-5">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-emerald-700">Administrative Verification</p>
                <h3 id="adminVerificationTitle" class="mt-2 text-2xl font-black tracking-tight text-slate-900">Verify Identity</h3>
                <p id="adminVerificationText" class="mt-2 text-sm text-slate-600">Confirm your Head Teacher password to continue.</p>
            </div>
            <div class="space-y-4 px-6 py-6">
                <div>
                    <label for="adminVerificationPassword" class="mb-2 block text-sm font-semibold text-slate-700">Head Teacher Password</label>
                    <div class="relative">
                        <input id="adminVerificationPassword" type="password" autocomplete="current-password" class="w-full rounded-xl border-2 border-slate-800 bg-white px-4 py-3 pr-12 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100" placeholder="Enter your password">
                        <button type="button" data-password-toggle data-password-target="adminVerificationPassword" aria-label="Show password" aria-pressed="false" class="absolute inset-y-0 right-0 inline-flex w-12 items-center justify-center rounded-r-xl text-slate-500 transition hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></button>
                    </div>
                </div>
                <p id="adminVerificationError" class="hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700"></p>
                <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-end">
                    <button id="cancelAdminVerificationBtn" type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                    <button id="confirmAdminVerificationBtn" type="button" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        const closeSidebarBtn = document.getElementById('closeSidebarBtn');
        const mainPanel = document.getElementById('mainPanel');
        const sidebarText = document.querySelectorAll('.sidebar-text, .sidebar-brand-text');
        const navLinks = document.querySelectorAll('aside nav a');
        const sidebarBrandLogo = document.getElementById('sidebarBrandLogo');
        const sidebarUserCard = document.getElementById('sidebarUserCard');
        const sidebarLogoutFull = document.getElementById('sidebarLogoutFull');
        const sidebarLogoutMini = document.getElementById('sidebarLogoutMini');
        let desktopCollapsed = false;

        function setDesktopCollapsed(collapsed) {
            desktopCollapsed = collapsed;
            if (collapsed) {
                sidebar.classList.add('is-desktop-collapsed');
                sidebar.classList.remove('lg:w-72');
                sidebar.classList.add('lg:w-20');
                mainPanel?.classList.remove('lg:ml-72');
                mainPanel?.classList.add('lg:ml-20');
                navLinks.forEach((link) => link.classList.add('lg:justify-center'));
                sidebarText.forEach((el) => el.classList.add('lg:hidden'));
                sidebarBrandLogo?.classList.remove('h-14');
                sidebarBrandLogo?.classList.add('h-10');
                sidebarUserCard?.classList.add('lg:justify-center', 'lg:px-2');
                sidebarLogoutFull?.classList.add('lg:hidden');
                sidebarLogoutMini?.classList.remove('hidden');
                sidebarLogoutMini?.classList.add('lg:flex');
            } else {
                sidebar.classList.remove('is-desktop-collapsed');
                sidebar.classList.remove('lg:w-20');
                sidebar.classList.add('lg:w-72');
                mainPanel?.classList.remove('lg:ml-20');
                mainPanel?.classList.add('lg:ml-72');
                navLinks.forEach((link) => link.classList.remove('lg:justify-center'));
                sidebarText.forEach((el) => el.classList.remove('lg:hidden'));
                sidebarBrandLogo?.classList.remove('h-10');
                sidebarBrandLogo?.classList.add('h-14');
                sidebarUserCard?.classList.remove('lg:justify-center', 'lg:px-2');
                sidebarLogoutMini?.classList.add('hidden');
                sidebarLogoutMini?.classList.remove('lg:flex');
                sidebarLogoutFull?.classList.remove('lg:hidden');
            }
        }

        function openMobileSidebar() {
            sidebar.classList.remove('-translate-x-full');
            sidebarBackdrop.classList.remove('hidden');
        }

        function closeMobileSidebar() {
            sidebar.classList.add('-translate-x-full');
            sidebarBackdrop.classList.add('hidden');
        }

        sidebarToggleBtn?.addEventListener('click', () => {
            if (window.innerWidth < 1024) {
                openMobileSidebar();
                return;
            }
            setDesktopCollapsed(!desktopCollapsed);
        });

        closeSidebarBtn?.addEventListener('click', closeMobileSidebar);
        sidebarBackdrop?.addEventListener('click', closeMobileSidebar);
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 1024) {
                sidebarBackdrop?.classList.add('hidden');
                sidebar.classList.remove('-translate-x-full');
            } else {
                sidebar.classList.add('-translate-x-full');
            }
        });
        setDesktopCollapsed(false);
        document.querySelectorAll('[data-requires-active-semester]').forEach((button) => {
            button.addEventListener('click', () => {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({icon: 'warning', title: 'No Active Semester', text: button.dataset.activeSemesterMessage || 'Please activate a semester before opening Attendance Intelligence.', confirmButtonColor: '#047857'});
                }
            });
        });

        const semesterModal = document.getElementById('semesterModal');
        const openSemesterModalBtn = document.getElementById('openSemesterModalBtn');
        const closeSemesterModalBtn = document.getElementById('closeSemesterModalBtn');
        const cancelSemesterModalBtn = document.getElementById('cancelSemesterModalBtn');
        const modalBackdrop = document.getElementById('modalBackdrop');
        const schoolYearSelect = document.getElementById('schoolYear');
        const schoolYearBtn = document.getElementById('schoolYearBtn');
        const schoolYearBtnText = document.getElementById('schoolYearBtnText');
        const schoolYearMenu = document.getElementById('schoolYearMenu');
        const schoolYearOptions = document.querySelectorAll('.school-year-option');
        const semesterSelect = document.getElementById('semester');
        const semesterPreview = document.getElementById('semesterPreview');
        const semesterAvailability = document.getElementById('semesterAvailability');
        const confirmCreateSemesterBtn = document.getElementById('confirmCreateSemesterBtn');
        const btnStartSemester = document.getElementById('btnStartSemester');
        const btnStartPracticum = document.getElementById('btnStartPracticum');
        const btnEndSemester = document.getElementById('btnEndSemester');
        const practicumDateInput = document.getElementById('practicumStartDate');
        const unlockAdministrationBtn = document.getElementById('unlockAdministrationBtn');
        const administrationLockLabel = document.getElementById('administrationLockLabel');
        const administrationCountdown = document.getElementById('administrationCountdown');
        const administrationState = document.getElementById('administrationState');
        const administrationStatusBadge = document.getElementById('administrationStatusBadge');
        const administrationLockedPanel = document.getElementById('administrationLockedPanel');
        const administrationUnlockedPanel = document.getElementById('administrationUnlockedPanel');
        const startSemesterModal = document.getElementById('startSemesterModal');
        const startSemesterBackdrop = document.getElementById('startSemesterBackdrop');
        const startPracticumModal = document.getElementById('startPracticumModal');
        const startPracticumBackdrop = document.getElementById('startPracticumBackdrop');
        const startPracticumMessage = document.getElementById('startPracticumMessage');
        const startPracticumError = document.getElementById('startPracticumError');
        const cancelStartPracticumBtn = document.getElementById('cancelStartPracticumBtn');
        const confirmStartPracticumBtn = document.getElementById('confirmStartPracticumBtn');
        const confirmStartPracticumText = document.getElementById('confirmStartPracticumText');
        const cancelStartSemesterBtn = document.getElementById('cancelStartSemesterBtn');
        const confirmStartSemesterBtn = document.getElementById('confirmStartSemester');
        const confirmStartSemesterText = document.getElementById('confirmStartSemesterText');
        const startSemesterSummary = document.getElementById('startSemesterSummary');
        const startSemesterError = document.getElementById('startSemesterError');
        const endSemesterModal = document.getElementById('endSemesterModal');
        const endSemesterBackdrop = document.getElementById('endSemesterBackdrop');
        const cancelEndSemesterBtn = document.getElementById('cancelEndSemesterBtn');
        const confirmEndSemesterBtn = document.getElementById('confirmEndSemesterBtn');
        const confirmEndSemesterText = document.getElementById('confirmEndSemesterText');
        const endSemesterSummary = document.getElementById('endSemesterSummary');
        const endSemesterError = document.getElementById('endSemesterError');
        const startSemesterPassword = document.getElementById('startSemesterPassword');
        const adminVerificationModal = document.getElementById('adminVerificationModal');
        const adminVerificationTitle = document.getElementById('adminVerificationTitle');
        const adminVerificationText = document.getElementById('adminVerificationText');
        const adminVerificationPassword = document.getElementById('adminVerificationPassword');
        const adminVerificationError = document.getElementById('adminVerificationError');
        const confirmAdminVerificationBtn = document.getElementById('confirmAdminVerificationBtn');
        const cancelAdminVerificationBtn = document.getElementById('cancelAdminVerificationBtn');
        const startSemesterApiUrl = <?= json_encode(appUrl('api/start_semester.php')) ?>;
        const endSemesterApiUrl = <?= json_encode(appUrl('api/end_semester.php')) ?>;
        const startPracticumApiUrl = <?= json_encode(appUrl('api/start_practicum.php')) ?>;
        const adminVerifyApiUrl = <?= json_encode(appUrl('api/head_teacher_admin_verify.php')) ?>;
        const semesterAvailabilityApiUrl = <?= json_encode(appUrl('api/semester_availability.php')) ?>;
        const portalLiveStateApiUrl = <?= json_encode(appUrl('api/portal_live_state.php')) ?>;
        let startSemesterId = 0;
        let endSemesterId = 0;
        let adminVerificationAction = null;

        // Keep every open Head Teacher page synchronized when a semester or
        // practicum state changes. BroadcastChannel updates same-browser tabs
        // immediately; polling covers changes made from another device.
        (function initialisePortalLiveSession() {
            const channelName = 'phinma-head-teacher-portal-state';
            const channel = 'BroadcastChannel' in window ? new BroadcastChannel(channelName) : null;
            let knownVersion = '';
            let updateNotice = null;

            function pageHasUnsavedInteraction() {
                const active = document.activeElement;
                return active instanceof HTMLInputElement
                    || active instanceof HTMLTextAreaElement
                    || active instanceof HTMLSelectElement
                    || active?.isContentEditable === true
                    || Boolean(document.querySelector('.fixed.inset-0.z-50:not(.hidden)'));
            }

            function showDeferredUpdateNotice() {
                if (updateNotice) return;
                updateNotice = document.createElement('button');
                updateNotice.type = 'button';
                updateNotice.className = 'fixed bottom-5 right-5 z-[70] rounded-xl bg-slate-900 px-4 py-3 text-left text-sm font-semibold text-white shadow-xl transition hover:bg-slate-800';
                updateNotice.textContent = 'Portal updated in another session. Refresh now';
                updateNotice.addEventListener('click', () => window.location.reload());
                document.body.appendChild(updateNotice);
            }

            function applyExternalUpdate() {
                if (pageHasUnsavedInteraction()) {
                    showDeferredUpdateNotice();
                    return;
                }
                window.location.reload();
            }

            function receiveExternalUpdate(message) {
                const delay = Math.max(250, Number(message?.delay || 700));
                window.setTimeout(() => {
                    if (message?.force === true) {
                        applyExternalUpdate();
                        return;
                    }
                    checkPortalState();
                }, delay);
            }

            async function checkPortalState() {
                if (document.visibilityState !== 'visible') return;
                try {
                    const response = await fetch(portalLiveStateApiUrl, {
                        cache: 'no-store', credentials: 'same-origin', headers: {Accept: 'application/json'}
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.success || !payload.state_version) return;
                    if (knownVersion && knownVersion !== payload.state_version) {
                        applyExternalUpdate();
                        return;
                    }
                    knownVersion = payload.state_version;
                } catch (_) {
                    // A transient check failure must not interrupt portal work.
                }
            }

            window.PhinmaPortalLiveSession = {
                notify(delay = 700, force = false) {
                    const message = {type: 'portal-state-changed', delay, force};
                    channel?.postMessage(message);
                    try { localStorage.setItem(channelName, JSON.stringify(message)); } catch (_) {}
                },
            };
            channel?.addEventListener('message', (event) => {
                if (event.data?.type === 'portal-state-changed') receiveExternalUpdate(event.data);
            });
            window.addEventListener('storage', (event) => {
                if (event.key !== channelName) return;
                try { receiveExternalUpdate(JSON.parse(event.newValue || '{}')); } catch (_) { checkPortalState(); }
            });
            // All Head Teacher POST forms (CSV import, coordinator creation,
            // student editing, etc.) notify other portal tabs. A listener on
            // the document runs after form-level validation, so cancelled
            // submissions do not trigger an update check.
            document.addEventListener('submit', (event) => {
                if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return;
                if ((event.target.method || 'get').toLowerCase() === 'post') {
                    // Native forms navigate only after this event; allow their
                    // server transaction time to finish before other tabs load.
                    window.PhinmaPortalLiveSession?.notify(3000, true);
                }
            });
            document.addEventListener('visibilitychange', checkPortalState);
            checkPortalState();
            window.setInterval(checkPortalState, 15000);
        })();

        document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const input = document.getElementById(toggle.dataset.passwordTarget || '');
                if (!input) return;
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            });
        });

        function requestAdministrativeVerification(title, detail, action) {
            if (!adminVerificationModal) return;
            adminVerificationAction = action;
            // The verification step uses one consistent security presentation;
            // the callback continues to determine the underlying action.
            adminVerificationTitle.textContent = 'Verify Identity';
            adminVerificationText.textContent = 'Confirm your Head Teacher password to continue.';
            adminVerificationPassword.value = '';
            adminVerificationError.classList.add('hidden');
            adminVerificationModal.classList.remove('hidden');
            adminVerificationModal.classList.add('flex');
            setTimeout(() => adminVerificationPassword.focus(), 0);
        }
        function closeAdministrativeVerification() {
            adminVerificationModal?.classList.add('hidden');
            adminVerificationModal?.classList.remove('flex');
            adminVerificationAction = null;
        }
        cancelAdminVerificationBtn?.addEventListener('click', closeAdministrativeVerification);
        confirmAdminVerificationBtn?.addEventListener('click', async () => {
            try {
                const response = await fetch(adminVerifyApiUrl, { method: 'POST', headers: {'Content-Type':'application/json','Accept':'application/json'}, body: JSON.stringify({password: adminVerificationPassword.value}) });
                const payload = await response.json();
                if (!response.ok || !payload.success) throw new Error(payload.message || 'Verification failed.');
                const action = adminVerificationAction;
                closeAdministrativeVerification();
                if (typeof action === 'function') action();
            } catch (error) {
                adminVerificationError.textContent = error instanceof Error ? error.message : 'Verification failed.';
                adminVerificationError.classList.remove('hidden');
            }
        });

        let administrationExpiresAt = Number(administrationCountdown?.dataset.expiresAt || '0');
        function setAdministrationLocked(locked) {
            practicumDateInput && (practicumDateInput.disabled = locked || practicumDateInput.dataset.lifecycleLocked === 'true');
            document.querySelectorAll('[data-admin-action]').forEach((element) => {
                element.disabled = locked;
            });
            administrationLockLabel && (administrationLockLabel.textContent = locked ? 'Locked' : 'Unlocked');
            administrationState?.classList.toggle('text-slate-700', locked);
            administrationState?.classList.toggle('text-emerald-700', !locked);
            administrationStatusBadge?.classList.toggle('bg-slate-100', locked);
            administrationStatusBadge?.classList.toggle('text-slate-700', locked);
            administrationStatusBadge?.classList.toggle('bg-emerald-100', !locked);
            administrationStatusBadge?.classList.toggle('text-emerald-800', !locked);
            administrationCountdown?.classList.toggle('hidden', locked);
            unlockAdministrationBtn?.classList.toggle('hidden', !locked);
            administrationLockedPanel?.classList.toggle('hidden', !locked);
            administrationUnlockedPanel?.classList.toggle('hidden', locked);
        }
        function tickAdministrationCountdown() {
            const seconds = Math.max(0, administrationExpiresAt - Math.floor(Date.now() / 1000));
            if (seconds <= 0) { setAdministrationLocked(true); return; }
            const minutes = String(Math.floor(seconds / 60)).padStart(2, '0');
            const remainder = String(seconds % 60).padStart(2, '0');
            if (administrationCountdown) administrationCountdown.textContent = `Time Remaining: ${minutes}:${remainder}`;
        }
        if (administrationExpiresAt > 0) { setAdministrationLocked(false); tickAdministrationCountdown(); setInterval(tickAdministrationCountdown, 1000); }
        unlockAdministrationBtn?.addEventListener('click', () => requestAdministrativeVerification('Administrative Verification', 'Enter your Head Teacher password to unlock administration for 10 minutes.', () => {
            administrationExpiresAt = Math.floor(Date.now() / 1000) + 600;
            setAdministrationLocked(false);
            tickAdministrationCountdown();
            setInterval(tickAdministrationCountdown, 1000);
        }));

        function updatePreview() {
            if (!semesterPreview || !semesterSelect || !schoolYearSelect) return;
            semesterPreview.innerHTML = 'You are about to create: <span class="font-semibold">' + semesterSelect.value + ' (' + schoolYearSelect.value + ')</span>';
        }
        function setCreateSemesterAvailability(semesters) {
            if (!semesterSelect) return;
            const byName = new Map((semesters || []).map((semester) => [semester.name, semester]));
            Array.from(semesterSelect.options).forEach((option) => {
                const item = byName.get(option.value);
                const available = item ? item.available === true : false;
                option.disabled = !available;
                option.textContent = available ? option.value : `${option.value} (Already Created)`;
            });
            const selected = semesterSelect.options[semesterSelect.selectedIndex];
            if (!selected || selected.disabled) {
                const firstAvailable = Array.from(semesterSelect.options).find((option) => !option.disabled);
                if (firstAvailable) semesterSelect.value = firstAvailable.value;
            }
            const available = Array.from(semesterSelect.options).some((option) => !option.disabled);
            if (confirmCreateSemesterBtn) confirmCreateSemesterBtn.disabled = !available;
            if (semesterAvailability) {
                semesterAvailability.innerHTML = (semesters || []).map((semester) => {
                    const isAvailable = semester.available === true;
                    const colour = isAvailable ? 'bg-emerald-500' : 'bg-rose-500';
                    const label = isAvailable ? 'Available' : 'Already Created';
                    return `<li class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full ${colour}"></span><span>${semester.name} — ${label}</span></li>`;
                }).join('');
            }
            updatePreview();
        }
        async function loadSemesterAvailability() {
            if (!schoolYearSelect?.value) return;
            if (confirmCreateSemesterBtn) confirmCreateSemesterBtn.disabled = true;
            if (semesterAvailability) semesterAvailability.innerHTML = '<li class="text-slate-500">Checking semester availability…</li>';
            try {
                const response = await fetch(`${semesterAvailabilityApiUrl}?school_year_label=${encodeURIComponent(schoolYearSelect.value)}`, { headers: { 'Accept': 'application/json' } });
                const payload = await response.json();
                if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to check semester availability.');
                setCreateSemesterAvailability(payload.data?.semesters || []);
            } catch (error) {
                if (semesterAvailability) semesterAvailability.innerHTML = '<li class="text-rose-700">Unable to check availability. Please try again.</li>';
            }
        }
        function openModal() {
            if (!semesterModal) return;
            semesterModal.classList.remove('hidden');
            semesterModal.classList.add('flex');
            updatePreview();
            loadSemesterAvailability();
        }
        function closeModal() {
            if (!semesterModal) return;
            semesterModal.classList.add('hidden');
            semesterModal.classList.remove('flex');
        }
        openSemesterModalBtn?.addEventListener('click', (event) => {
            event.preventDefault();
            if (openSemesterModalBtn.dataset.adminRequired === 'true') {
                requestAdministrativeVerification('Administrative Verification', 'Enter your Head Teacher password to create a new semester.', openModal);
                return;
            }
            openModal();
        });
        closeSemesterModalBtn?.addEventListener('click', closeModal);
        cancelSemesterModalBtn?.addEventListener('click', closeModal);
        modalBackdrop?.addEventListener('click', closeModal);
        schoolYearSelect?.addEventListener('change', loadSemesterAvailability);
        semesterSelect?.addEventListener('change', updatePreview);

        schoolYearBtn?.addEventListener('click', () => {
            schoolYearMenu?.classList.toggle('hidden');
        });
        schoolYearOptions.forEach((option) => {
            option.addEventListener('click', () => {
                const value = option.getAttribute('data-value') || '';
                if (schoolYearSelect) schoolYearSelect.value = value;
                if (schoolYearBtnText) schoolYearBtnText.textContent = value;
                schoolYearMenu?.classList.add('hidden');
                loadSemesterAvailability();
            });
        });
        document.addEventListener('click', (event) => {
            if (!schoolYearMenu || !schoolYearBtn) return;
            if (schoolYearMenu.contains(event.target) || schoolYearBtn.contains(event.target)) return;
            schoolYearMenu.classList.add('hidden');
        });

        function openStartSemesterModal() {
            if (!startSemesterModal) return;
            startSemesterModal.classList.remove('hidden');
            startSemesterModal.classList.add('flex');
        }

        function closeStartSemesterModal() {
            if (!startSemesterModal) return;
            startSemesterModal.classList.add('hidden');
            startSemesterModal.classList.remove('flex');
            if (startSemesterError) {
                startSemesterError.textContent = '';
                startSemesterError.classList.add('hidden');
            }
            if (confirmStartSemesterBtn) {
                confirmStartSemesterBtn.disabled = false;
                confirmStartSemesterBtn.classList.remove('cursor-not-allowed', 'opacity-70');
            }
            if (confirmStartSemesterText) {
                confirmStartSemesterText.textContent = 'Yes, Start';
            }
        }

        function openEndSemesterModal() {
            if (!endSemesterModal) return;
            if (confirmEndSemesterBtn) {
                confirmEndSemesterBtn.disabled = false;
                confirmEndSemesterBtn.classList.remove('cursor-not-allowed', 'opacity-50');
            }
            endSemesterModal.classList.remove('hidden');
            endSemesterModal.classList.add('flex');
        }

        function closeEndSemesterModal() {
            if (!endSemesterModal) return;
            endSemesterModal.classList.add('hidden');
            endSemesterModal.classList.remove('flex');
            if (endSemesterError) {
                endSemesterError.textContent = '';
                endSemesterError.classList.add('hidden');
            }
            if (confirmEndSemesterBtn) {
                confirmEndSemesterBtn.disabled = true;
                confirmEndSemesterBtn.classList.add('cursor-not-allowed', 'opacity-50');
            }
            if (confirmEndSemesterText) {
                confirmEndSemesterText.textContent = 'Complete Semester';
            }
        }

        btnStartSemester?.addEventListener('click', () => {
            startSemesterId = Number(btnStartSemester.getAttribute('data-semester-id') || '0');
            const semesterName = btnStartSemester.getAttribute('data-semester-name') || 'planned semester';
            if (startSemesterSummary) {
                startSemesterSummary.textContent = 'Are you sure you want to start ' + semesterName + '? Only one semester can be active at a time.';
            }
            openStartSemesterModal();
        });

        btnEndSemester?.addEventListener('click', () => {
            endSemesterId = Number(btnEndSemester.getAttribute('data-semester-id') || '0');
            const semesterName = btnEndSemester.getAttribute('data-semester-name') || 'current semester';
            if (endSemesterSummary) {
                endSemesterSummary.textContent = 'Are you sure you want to end ' + semesterName + '? This action completes the semester and preserves history for reports.';
            }
            requestAdministrativeVerification('Administrative Verification', 'Enter your Head Teacher password to end this semester.', openEndSemesterModal);
        });

        cancelStartSemesterBtn?.addEventListener('click', closeStartSemesterModal);
        startSemesterBackdrop?.addEventListener('click', closeStartSemesterModal);
        cancelEndSemesterBtn?.addEventListener('click', closeEndSemesterModal);
        endSemesterBackdrop?.addEventListener('click', closeEndSemesterModal);

        confirmStartSemesterBtn?.addEventListener('click', async () => {
            confirmStartSemesterBtn.disabled = true;
            confirmStartSemesterBtn.classList.add('cursor-not-allowed', 'opacity-70');
            if (confirmStartSemesterText) {
                confirmStartSemesterText.textContent = 'Starting...';
            }
            if (startSemesterError) {
                startSemesterError.textContent = '';
                startSemesterError.classList.add('hidden');
            }

            try {
                const response = await fetch(startSemesterApiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ semester_id: startSemesterId, admin_password: startSemesterPassword?.value || '' })
                });

                const payload = await response.json();
                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || 'Failed to start semester.');
                }

                window.PhinmaPortalLiveSession?.notify();
                window.location.reload();
            } catch (error) {
                if (startSemesterError) {
                    startSemesterError.textContent = error instanceof Error ? error.message : 'Failed to start semester.';
                    startSemesterError.classList.remove('hidden');
                }
                confirmStartSemesterBtn.disabled = false;
                confirmStartSemesterBtn.classList.remove('cursor-not-allowed', 'opacity-70');
                if (confirmStartSemesterText) {
                    confirmStartSemesterText.textContent = 'Yes, Start';
                }
            }
        });

        confirmEndSemesterBtn?.addEventListener('click', async () => {
            if (endSemesterId <= 0) {
                if (endSemesterError) {
                    endSemesterError.textContent = 'Missing semester selection.';
                    endSemesterError.classList.remove('hidden');
                }
                return;
            }

            confirmEndSemesterBtn.disabled = true;
            confirmEndSemesterBtn.classList.add('cursor-not-allowed', 'opacity-70');
            if (confirmEndSemesterText) {
                confirmEndSemesterText.textContent = 'Completing...';
            }
            if (endSemesterError) {
                endSemesterError.textContent = '';
                endSemesterError.classList.add('hidden');
            }

            try {
                const response = await fetch(endSemesterApiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ semester_id: endSemesterId })
                });

                const payload = await response.json();
                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || 'Failed to end semester.');
                }

                window.PhinmaPortalLiveSession?.notify();
                window.location.reload();
            } catch (error) {
                if (endSemesterError) {
                    endSemesterError.textContent = error instanceof Error ? error.message : 'Failed to end semester.';
                    endSemesterError.classList.remove('hidden');
                }
                confirmEndSemesterBtn.disabled = false;
                confirmEndSemesterBtn.classList.remove('cursor-not-allowed', 'opacity-70');
                if (confirmEndSemesterText) {
                    confirmEndSemesterText.textContent = 'Complete Semester';
                }
            }
        });

        function closeStartPracticumModal() {
            startPracticumModal?.classList.add('hidden');
            startPracticumModal?.classList.remove('flex');
            if (startPracticumError) {
                startPracticumError.textContent = '';
                startPracticumError.classList.add('hidden');
            }
        }
        function practicumDateLabel(date) {
            return new Intl.DateTimeFormat('en-US', {
                timeZone: 'Asia/Manila', month: 'long', day: 'numeric', year: 'numeric'
            }).format(new Date(`${date}T12:00:00+08:00`));
        }
        function openStartPracticumModal() {
            const selectedDate = practicumDateInput?.value || '';
            const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
            if (startPracticumMessage) {
                startPracticumMessage.textContent = selectedDate > today
                    ? `Attendance tracking will automatically open on ${practicumDateLabel(selectedDate)}.`
                    : 'Attendance tracking will become active immediately.';
            }
            if (startPracticumError) {
                startPracticumError.textContent = selectedDate === '' ? 'Select a practicum start date before starting practicum.' : '';
                startPracticumError.classList.toggle('hidden', selectedDate !== '');
            }
            if (confirmStartPracticumBtn) confirmStartPracticumBtn.disabled = selectedDate === '';
            startPracticumModal?.classList.remove('hidden');
            startPracticumModal?.classList.add('flex');
        }
        btnStartPracticum?.addEventListener('click', openStartPracticumModal);
        cancelStartPracticumBtn?.addEventListener('click', closeStartPracticumModal);
        startPracticumBackdrop?.addEventListener('click', closeStartPracticumModal);
        confirmStartPracticumBtn?.addEventListener('click', async () => {
            const selectedDate = practicumDateInput?.value || '';
            if (!selectedDate || !btnStartPracticum) return;
            confirmStartPracticumBtn.disabled = true;
            if (confirmStartPracticumText) confirmStartPracticumText.textContent = 'Starting...';
            if (startPracticumError) {
                startPracticumError.textContent = '';
                startPracticumError.classList.add('hidden');
            }
            try {
                const response = await fetch(startPracticumApiUrl, {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', 'Accept':'application/json'},
                    body: JSON.stringify({
                        semester_id: Number(btnStartPracticum.dataset.semesterId || '0'),
                        practicum_start_date: selectedDate
                    })
                });
                const payload = await response.json();
                if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to start practicum.');
                window.PhinmaPortalLiveSession?.notify();
                window.location.reload();
            } catch (error) {
                if (startPracticumError) {
                    startPracticumError.textContent = error instanceof Error ? error.message : 'Unable to start practicum.';
                    startPracticumError.classList.remove('hidden');
                }
                confirmStartPracticumBtn.disabled = false;
                if (confirmStartPracticumText) confirmStartPracticumText.textContent = 'Start Practicum';
            }
        });
    </script>
    <?php renderLogoutUi(appUrl('public/logout.php')); ?>
    <?= $extraScript ?>
</body>
</html>
<?php
}
