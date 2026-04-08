<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/logout_ui.php';

function headTeacherNavItems(): array
{
    return [
        'attendance' => ['label' => 'Attendance Intelligence', 'path' => '/practicum_system/head_teacher/attendance_intelegence.php'],
        'academic' => ['label' => 'Academic Control', 'path' => '/practicum_system/head_teacher/academic_control.php'],
        'evaluation' => ['label' => 'Practicum Evaluation', 'path' => '/practicum_system/head_teacher/practicum_evaluation.php'],
        'deployment' => ['label' => 'Practicum Deployment', 'path' => '/practicum_system/head_teacher/practicum_deployment.php'],
        'backups' => ['label' => 'Data and Backups', 'path' => '/practicum_system/head_teacher/backups.php'],
        'settings' => ['label' => 'Settings', 'path' => '/practicum_system/head_teacher/settings.php'],
    ];
}

function headTeacherNavIcon(string $key): string
{
    $icons = [
        'attendance' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M4 13h4l2-7 4 12 2-5h4"/></svg>',
        'academic' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M3 8l9-4 9 4-9 4-9-4z"/><path d="M7 10v5c0 1.7 2.2 3 5 3s5-1.3 5-3v-5"/></svg>',
        'evaluation' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'deployment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M12 21s7-4.35 7-11a7 7 0 1 0-14 0c0 6.65 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>',
        'backups' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><ellipse cx="12" cy="5" rx="7" ry="3"/><path d="M5 5v6c0 1.7 3.1 3 7 3s7-1.3 7-3V5"/><path d="M5 11v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.4-1.7 1.7 1.7 0 0 0-1.8.5l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H2.9a2 2 0 1 1 0-4H3a1.7 1.7 0 0 0 1.7-1.4 1.7 1.7 0 0 0-.5-1.8l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V2.9a2 2 0 1 1 4 0V3a1.7 1.7 0 0 0 1.4 1.7 1.7 1.7 0 0 0 1.8-.5l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.26.37.4.82.4 1.3s-.14.93-.4 1.3z"/></svg>',
    ];

    return $icons[$key] ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4"><circle cx="12" cy="12" r="8"/></svg>';
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

function htExpandSchoolYearOptions(array $rows, ?string $currentLabel = null): array
{
    $labelToId = [];
    $startYears = [];
    $nowYear = (int) date('Y');

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

    if ($startYears === []) {
        $startYears[] = $nowYear;
    }

    $minStart = min(min($startYears), $nowYear - 1);
    $maxStart = max(max($startYears), $nowYear + 3);

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
        $target = (string) ($_SERVER['REQUEST_URI'] ?? '/practicum_system/head_teacher/academic_control.php');
        header('Location: ' . $target);
        exit;
    };

    try {
        if ($action === 'create_semester') {
            if (!$isPost) {
                return;
            }
            $yearLabel = trim((string) ($_POST['school_year_label'] ?? ''));
            $semesterName = trim((string) ($_POST['semester_name'] ?? ''));
            if ($yearLabel === '' || $semesterName === '') {
                throw new InvalidArgumentException('School year and semester are required.');
            }

            [$startYear, $endYear] = htParseYearLabel($yearLabel);
            [$semesterNo, $normalizedName, $startDate, $endDate] = htSemesterMeta($semesterName, $startYear, $endYear);

            $pdo->beginTransaction();
            $isNewSchoolYear = false;

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
                $isNewSchoolYear = true;
            }

            $closeActive = $pdo->prepare(
                "UPDATE semesters
                 SET semester_status = 'closed'
                 WHERE school_year_id = :school_year_id
                   AND semester_status = 'active'"
            );
            $closeActive->execute(['school_year_id' => $schoolYearId]);

            if ($isNewSchoolYear) {
                $archiveSchoolYears = $pdo->prepare(
                    "UPDATE school_years
                     SET year_status = 'archived'
                     WHERE id <> :new_school_year_id
                       AND year_status <> 'archived'"
                );
                $archiveSchoolYears->execute(['new_school_year_id' => $schoolYearId]);

                $archiveSemesters = $pdo->prepare(
                    "UPDATE semesters
                     SET semester_status = 'archived'
                     WHERE school_year_id <> :new_school_year_id
                       AND semester_status <> 'archived'"
                );
                $archiveSemesters->execute(['new_school_year_id' => $schoolYearId]);

                $archiveSections = $pdo->prepare(
                    "UPDATE sections s
                     JOIN semesters sem ON sem.id = s.semester_id
                     SET s.section_status = 'archived'
                     WHERE sem.school_year_id <> :new_school_year_id
                       AND s.section_status <> 'archived'"
                );
                $archiveSections->execute(['new_school_year_id' => $schoolYearId]);

                $normalizeUsers = $pdo->prepare(
                    "UPDATE users u
                     JOIN roles r ON r.id = u.role_id
                     SET u.account_status = 'active'
                     WHERE u.account_status <> 'active'
                       AND r.role_code IN ('student', 'coordinator')"
                );
                $normalizeUsers->execute();
            }

            $existingSemStmt = $pdo->prepare(
                'SELECT id FROM semesters WHERE school_year_id = :school_year_id AND semester_no = :semester_no LIMIT 1'
            );
            $existingSemStmt->execute([
                'school_year_id' => $schoolYearId,
                'semester_no' => $semesterNo,
            ]);
            $existingSemesterId = (int) ($existingSemStmt->fetchColumn() ?: 0);

            if ($existingSemesterId > 0) {
                $closeOthers = $pdo->prepare(
                    "UPDATE semesters
                     SET semester_status = CASE WHEN id = :target_id THEN 'planned' ELSE 'closed' END
                     WHERE school_year_id = :school_year_id"
                );
                $closeOthers->execute([
                    'target_id' => $existingSemesterId,
                    'school_year_id' => $schoolYearId,
                ]);

                $clearSectionStudents = $pdo->prepare('DELETE FROM section_students WHERE semester_id = :semester_id');
                $clearSectionStudents->execute(['semester_id' => $existingSemesterId]);

                $clearSectionCoordinators = $pdo->prepare(
                    'DELETE sc
                     FROM section_coordinators sc
                     INNER JOIN sections s ON s.id = sc.section_id
                     WHERE s.semester_id = :semester_id'
                );
                $clearSectionCoordinators->execute(['semester_id' => $existingSemesterId]);

                $setSectionsInactive = $pdo->prepare(
                    "UPDATE sections
                     SET section_status = 'inactive'
                     WHERE semester_id = :semester_id"
                );
                $setSectionsInactive->execute(['semester_id' => $existingSemesterId]);

                $pdo->commit();
                htSetFlash('success', "{$normalizedName} ({$yearLabel}) already exists. It is now prepared and assignments were reset.");
                $redirectToCurrent();
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

            $pdo->commit();
            htSetFlash('success', "Created {$normalizedName} ({$yearLabel}) successfully.");
            $redirectToCurrent();
        }

        if ($action === 'start_semester') {
            $semesterId = (int) ($actionSource['semester_id'] ?? 0);
            if ($semesterId <= 0) {
                throw new InvalidArgumentException('Invalid semester selection.');
            }
            $pdo->beginTransaction();

            $semStmt = $pdo->prepare('SELECT id, school_year_id FROM semesters WHERE id = :id LIMIT 1');
            $semStmt->execute(['id' => $semesterId]);
            $row = $semStmt->fetch();
            if (!$row) {
                throw new RuntimeException('Semester not found.');
            }
            $schoolYearId = (int) $row['school_year_id'];

            if ((int) date('N') !== 1) {
                $nextMondayObj = new DateTimeImmutable('next monday');
                $nextMonday = $nextMondayObj->format('Y-m-d');

                $scheduleTarget = $pdo->prepare(
                    "UPDATE semesters
                     SET semester_status = 'planned',
                         start_date = :start_date
                     WHERE id = :target_id"
                );
                $scheduleTarget->execute([
                    'start_date' => $nextMonday,
                    'target_id' => $semesterId,
                ]);

                $closeOthers = $pdo->prepare(
                    "UPDATE semesters
                     SET semester_status = 'closed'
                     WHERE school_year_id = :school_year_id
                       AND id <> :target_id
                       AND semester_status = 'active'"
                );
                $closeOthers->execute([
                    'school_year_id' => $schoolYearId,
                    'target_id' => $semesterId,
                ]);

                $pdo->commit();
                htSetFlash('success', 'Semester scheduled to start on Monday, ' . $nextMondayObj->format('F j, Y') . '.');
                $redirectToCurrent();
            }

            $deactivate = $pdo->prepare(
                "UPDATE semesters
                 SET semester_status = CASE WHEN id = :target_id THEN 'active' ELSE 'closed' END
                 WHERE school_year_id = :school_year_id"
            );
            $deactivate->execute([
                'target_id' => $semesterId,
                'school_year_id' => $schoolYearId,
            ]);

            $activateSy = $pdo->prepare("UPDATE school_years SET year_status = 'active' WHERE id = :id");
            $activateSy->execute(['id' => $schoolYearId]);

            $sectionsStmt = $pdo->prepare(
                "UPDATE sections
                 SET section_status = CASE WHEN semester_id = :semester_id THEN 'active' ELSE 'inactive' END
                 WHERE semester_id IN (SELECT id FROM semesters WHERE school_year_id = :school_year_id)"
            );
            $sectionsStmt->execute([
                'semester_id' => $semesterId,
                'school_year_id' => $schoolYearId,
            ]);

            $pdo->commit();
            htSetFlash('success', 'Semester started successfully.');
            $redirectToCurrent();
        }

        if ($action === 'end_semester') {
            $semesterId = (int) ($actionSource['semester_id'] ?? 0);
            if ($semesterId <= 0) {
                throw new InvalidArgumentException('Invalid semester selection.');
            }

            $pdo->beginTransaction();

            $semStmt = $pdo->prepare('SELECT id, school_year_id FROM semesters WHERE id = :id LIMIT 1');
            $semStmt->execute(['id' => $semesterId]);
            $row = $semStmt->fetch();
            if (!$row) {
                throw new RuntimeException('Semester not found.');
            }

            $endSem = $pdo->prepare("UPDATE semesters SET semester_status = 'closed' WHERE id = :id");
            $endSem->execute(['id' => $semesterId]);

            $inactiveSections = $pdo->prepare("UPDATE sections SET section_status = 'inactive' WHERE semester_id = :semester_id");
            $inactiveSections->execute(['semester_id' => $semesterId]);

            $pdo->commit();
            htSetFlash('success', 'Semester ended successfully.');
            $redirectToCurrent();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        htSetFlash('error', $e->getMessage());
        $redirectToCurrent();
    }
}

function renderHeadTeacherPortalStart(array $user, string $activeKey): void
{
    $pdo = getPDO();
    htHandlePortalActions($pdo);

    $flash = htGetFlash();
    $navItems = headTeacherNavItems();

    $semesterRows = $pdo->query(
        "SELECT
            sem.id,
            sem.semester_name,
            sem.semester_no,
            sem.semester_status,
            sy.id AS school_year_id,
            sy.year_label,
            sy.year_status
         FROM semesters sem
         JOIN school_years sy ON sy.id = sem.school_year_id
         ORDER BY
            CASE sem.semester_status
                WHEN 'active' THEN 3
                WHEN 'planned' THEN 2
                WHEN 'closed' THEN 1
                ELSE 0
            END DESC,
            sy.start_date DESC,
            sem.semester_no DESC"
    )->fetchAll();

    $currentSemester = $semesterRows[0] ?? null;
    $currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;
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
        $fallbackLabel = (string) ($currentSemester['year_label'] ?? (date('Y') . '-' . ((int) date('Y') + 1)));
        $schoolYears = [['id' => 0, 'year_label' => $fallbackLabel]];
    }
    $selectedYearLabel = (string) ($currentSemester['year_label'] ?? $schoolYears[0]['year_label']);
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
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800 antialiased">
    <div class="min-h-screen lg:flex" id="appRoot">
        <div id="sidebarBackdrop" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

        <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 h-screen w-72 -translate-x-full transform overflow-y-auto bg-emerald-800 text-emerald-50 transition-all duration-300 ease-out lg:translate-x-0 lg:shadow-none lg:w-72">
            <div class="flex h-full flex-col">
                <div class="border-b border-emerald-700 px-5 pb-6 pt-7">
                    <div class="mb-6 flex items-center justify-end lg:hidden">
                        <button id="closeSidebarBtn" type="button" class="rounded-lg p-2 text-emerald-100 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-300">X</button>
                    </div>
                    <div id="sidebarBrandWrap" class="flex flex-col items-center gap-3">
                        <img id="sidebarBrandLogo" src="../assets/images/logo_coc.png" alt="Cagayan de Oro College" class="h-20 w-auto transition-all duration-300" />
                        <div class="text-center sidebar-brand-text">
                            <p class="text-sm font-bold tracking-[0.2em]">CAGAYAN DE ORO COLLEGE</p>
                            <p class="mt-1 text-xs tracking-[0.25em] text-emerald-200">PHINMA EDUCATION</p>
                        </div>
                    </div>
                </div>

                <?php
                $monitoringNav = ['attendance', 'evaluation'];
                $configNav = ['academic', 'deployment'];
                $secondaryNav = ['backups', 'settings'];
                ?>
                <nav class="flex-1 space-y-4 px-3 py-5">
                    <div>
                        <p class="sidebar-text px-3 pb-2 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-200/90">Monitoring</p>
                        <div class="space-y-2">
                            <?php foreach ($monitoringNav as $key): ?>
                                <?php if (!isset($navItems[$key])) { continue; } ?>
                                <?php $item = $navItems[$key]; ?>
                                <a href="<?= htmlspecialchars($item['path']) ?>" class="group flex items-center gap-3 rounded-xl px-4 py-3 text-sm transition <?= $key === $activeKey ? 'bg-emerald-700/70 font-semibold text-white shadow-sm' : 'font-medium text-emerald-100 hover:bg-emerald-700/60' ?>">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg <?= $key === $activeKey ? 'bg-emerald-600' : 'bg-emerald-700' ?> text-xs font-bold"><?= headTeacherNavIcon($key) ?></span>
                                    <span class="sidebar-text"><?= htmlspecialchars($item['label']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <p class="sidebar-text px-3 pb-2 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-200/90">System Configuration</p>
                        <div class="space-y-2">
                            <?php foreach ($configNav as $key): ?>
                                <?php if (!isset($navItems[$key])) { continue; } ?>
                                <?php $item = $navItems[$key]; ?>
                                <a href="<?= htmlspecialchars($item['path']) ?>" class="group flex items-center gap-3 rounded-xl px-4 py-3 text-sm transition <?= $key === $activeKey ? 'bg-emerald-700/70 font-semibold text-white shadow-sm' : 'font-medium text-emerald-100 hover:bg-emerald-700/60' ?>">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg <?= $key === $activeKey ? 'bg-emerald-600' : 'bg-emerald-700' ?> text-xs font-bold"><?= headTeacherNavIcon($key) ?></span>
                                    <span class="sidebar-text"><?= htmlspecialchars($item['label']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </nav>

                <div id="sidebarFooter" class="border-t border-emerald-700 px-4 py-4">
                    <div class="mb-3 space-y-2">
                        <?php foreach ($secondaryNav as $key): ?>
                            <?php if (!isset($navItems[$key])) { continue; } ?>
                            <?php $item = $navItems[$key]; ?>
                            <a href="<?= htmlspecialchars($item['path']) ?>" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition <?= $key === $activeKey ? 'bg-emerald-700/70 font-semibold text-white shadow-sm' : 'font-medium text-emerald-100 hover:bg-emerald-700/60' ?>">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg <?= $key === $activeKey ? 'bg-emerald-600' : 'bg-emerald-700' ?> text-xs font-bold"><?= headTeacherNavIcon($key) ?></span>
                                <span class="sidebar-text"><?= htmlspecialchars($item['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div id="sidebarUserCard" class="flex items-center gap-3 rounded-xl bg-emerald-700/50 px-3 py-3">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-emerald-500 font-semibold text-white">HT</span>
                        <div class="sidebar-text min-w-0">
                            <p class="truncate text-sm font-semibold"><?= htmlspecialchars((string)($user['name'] ?? 'Head Teacher')) ?></p>
                            <p class="truncate text-xs text-emerald-200"><?= htmlspecialchars((string)($user['email'] ?? '')) ?></p>
                        </div>
                    </div>
                    <a id="sidebarLogoutFull" data-logout-trigger href="/practicum_system/public/logout.php" class="mt-3 flex items-center justify-center rounded-xl border border-rose-300 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">
                        Logout
                    </a>
                    <a id="sidebarLogoutMini" data-logout-trigger href="/practicum_system/public/logout.php" class="mt-3 hidden items-center justify-center rounded-xl border border-rose-300 bg-rose-50 px-3 py-2 text-rose-700 transition hover:bg-rose-100" title="Logout" aria-label="Logout">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                    </a>
                </div>
            </div>
        </aside>

        <main id="mainPanel" class="flex-1 transition-[margin] duration-300 lg:ml-72">
            <div class="mx-auto w-full max-w-[1600px] px-4 pb-8 pt-4 sm:px-6 lg:px-8">
                <header class="sticky top-0 z-20 rounded-2xl border border-slate-200 bg-white/95 px-4 py-4 shadow-sm backdrop-blur sm:px-6">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div class="flex items-center gap-3">
                            <button id="sidebarToggleBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500">|||</button>
                            <h1 class="text-lg font-semibold tracking-tight text-slate-900 sm:text-2xl">Head Teacher / Portal</h1>
                        </div>

                        <?php if ($activeKey === 'academic'): ?>
                            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                                <span class="text-sm font-medium text-slate-600">SY <?= htmlspecialchars((string) ($currentSemester['year_label'] ?? 'N/A')) ?></span>
                                <span class="text-sm font-medium text-slate-600"><?= htmlspecialchars((string) ($currentSemester['semester_name'] ?? 'No Semester')) ?></span>
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">
                                    <?= htmlspecialchars(strtoupper((string) ($currentSemester['semester_status'] ?? 'INACTIVE'))) ?>
                                </span>
                                <button id="openSemesterModalBtn" type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400">Create New Semester</button>
                                <?php if (($currentSemester['semester_status'] ?? '') === 'active'): ?>
                                    <a href="/practicum_system/head_teacher/academic_control.php?portal_action=end_semester&amp;semester_id=<?= $currentSemesterId ?>" class="inline-flex items-center justify-center rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-400">End Semester</a>
                                <?php elseif (($currentSemester['semester_status'] ?? '') === 'planned'): ?>
                                    <button type="button" disabled class="inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-amber-100 px-4 py-2.5 text-sm font-semibold text-amber-700 ring-1 ring-amber-300">
                                        Scheduled
                                    </button>
                                <?php else: ?>
                                    <a href="/practicum_system/head_teacher/academic_control.php?portal_action=start_semester&amp;semester_id=<?= $currentSemesterId ?>" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500">Start Semester</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </header>
                <?php if ($flash): ?>
                    <div class="mt-4 rounded-xl border px-4 py-3 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
                    </div>
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
        <div class="relative w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-xl">
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
                        <div id="schoolYearMenu" class="absolute z-20 mt-1 hidden w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
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
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500">Confirm and Create</button>
            </form>
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
                sidebar.classList.remove('lg:w-72');
                sidebar.classList.add('lg:w-20');
                mainPanel?.classList.remove('lg:ml-72');
                mainPanel?.classList.add('lg:ml-20');
                navLinks.forEach((link) => link.classList.add('lg:justify-center'));
                sidebarText.forEach((el) => el.classList.add('lg:hidden'));
                sidebarBrandLogo?.classList.remove('h-20');
                sidebarBrandLogo?.classList.add('h-12');
                sidebarUserCard?.classList.add('lg:justify-center', 'lg:px-2');
                sidebarLogoutFull?.classList.add('lg:hidden');
                sidebarLogoutMini?.classList.remove('hidden');
                sidebarLogoutMini?.classList.add('lg:flex');
            } else {
                sidebar.classList.remove('lg:w-20');
                sidebar.classList.add('lg:w-72');
                mainPanel?.classList.remove('lg:ml-20');
                mainPanel?.classList.add('lg:ml-72');
                navLinks.forEach((link) => link.classList.remove('lg:justify-center'));
                sidebarText.forEach((el) => el.classList.remove('lg:hidden'));
                sidebarBrandLogo?.classList.remove('h-12');
                sidebarBrandLogo?.classList.add('h-20');
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

        function updatePreview() {
            if (!semesterPreview || !semesterSelect || !schoolYearSelect) return;
            semesterPreview.innerHTML = 'You are about to create: <span class="font-semibold">' + semesterSelect.value + ' (' + schoolYearSelect.value + ')</span>';
        }
        function openModal() {
            if (!semesterModal) return;
            semesterModal.classList.remove('hidden');
            semesterModal.classList.add('flex');
            updatePreview();
        }
        function closeModal() {
            if (!semesterModal) return;
            semesterModal.classList.add('hidden');
            semesterModal.classList.remove('flex');
        }
        openSemesterModalBtn?.addEventListener('click', (event) => {
            event.preventDefault();
            openModal();
        });
        closeSemesterModalBtn?.addEventListener('click', closeModal);
        cancelSemesterModalBtn?.addEventListener('click', closeModal);
        modalBackdrop?.addEventListener('click', closeModal);
        schoolYearSelect?.addEventListener('change', updatePreview);
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
                updatePreview();
            });
        });
        document.addEventListener('click', (event) => {
            if (!schoolYearMenu || !schoolYearBtn) return;
            if (schoolYearMenu.contains(event.target) || schoolYearBtn.contains(event.target)) return;
            schoolYearMenu.classList.add('hidden');
        });
    </script>
    <?php renderLogoutUi('/practicum_system/public/logout.php'); ?>
    <?= $extraScript ?>
</body>
</html>
<?php
}
