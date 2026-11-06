<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ChecklistService.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/HeadTeacherReportService.php';
require_once __DIR__ . '/../services/AttendanceCalculation.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';
require_once __DIR__ . '/../models/JournalModuleRepository.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
new JournalModuleRepository($pdo);
new SystemHolidayService($pdo);
$reportService = new HeadTeacherReportService($pdo);
$checklistService = new ChecklistService($pdo);
$liveHoursSql = AttendanceCalculation::renderedHoursSql('ar');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         LIMIT 1'
    );
    $stmt->execute(['table_name' => $table]);

    return $stmt->fetchColumn() !== false;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name LIMIT 1'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return $stmt->fetchColumn() !== false;
}

function attendanceLogTime(?string $time, string $emptyLabel = 'Pending timeout'): string
{
    if ($time === null || trim($time) === '') {
        return $emptyLabel;
    }

    try {
        return (new DateTimeImmutable($time))->format('g:i A');
    } catch (Throwable $e) {
        return $time;
    }
}

function attendanceDayBadge(string $date, int $hasTimeIn, int $hasTimeOut, ?string $timeIn = null, bool $outsideGeofence = false): string
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $isLate = $timeIn !== null && $timeIn !== '' && (new DateTimeImmutable($timeIn))->format('H:i:s') >= '08:30:00';

    if ($hasTimeIn === 1 && $hasTimeOut === 1) {
        if ($isLate) {
            return '<span class="inline-flex items-center rounded-full bg-yellow-100 px-3 py-1 text-xs font-semibold text-yellow-800 ring-1 ring-inset ring-yellow-200">Completed</span>';
        }
        return '<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">Completed</span>';
    }

    if ($hasTimeIn === 1) {
        if ($date === $today && $hasTimeOut === 0 && $outsideGeofence) {
            return '<span class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">Outside geofence</span>';
        }
        if ($date < $today) {
            return '<span class="inline-flex items-center rounded-full bg-orange-100 px-2 py-1 text-center text-xs font-semibold leading-tight text-orange-700 ring-1 ring-inset ring-orange-200">Pending<br>Timeout</span>';
        }
        if ($isLate) {
            return '<span class="inline-flex items-center rounded-full bg-yellow-100 px-3 py-1 text-xs font-semibold text-yellow-800 ring-1 ring-inset ring-yellow-200">Ongoing</span>';
        }
        return '<span class="inline-flex items-center rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-100">Ongoing</span>';
    }

    if ($date > $today) {
        return '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Upcoming</span>';
    }

    if ($date === $today && date('H:i:s') < '06:00:00') {
        return '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Upcoming</span>';
    }

    return '<span class="inline-flex items-center rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-100">Absent</span>';
}

$semesterStmt = $pdo->query(
    "SELECT sem.id, sem.semester_name, sem.semester_no, sem.start_date, sem.practicum_start_date, sem.practicum_status, sem.end_date, sem.semester_status, sy.year_label
     FROM semesters sem
     JOIN school_years sy ON sy.id = sem.school_year_id
     ORDER BY
       CASE sem.semester_status
         WHEN 'active' THEN 0
         WHEN 'planned' THEN 1
         WHEN 'closed' THEN 2
         ELSE 3
       END,
       sy.start_date DESC,
       sem.semester_no ASC
     LIMIT 1"
);
$currentSemester = $semesterStmt->fetch() ?: null;
$currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;
$hasActiveSemesterForChecklistBuilder = $currentSemester !== null
    && (string) ($currentSemester['semester_status'] ?? '') === 'active';
$practicumStarted = $currentSemester !== null && practicumCanRun((string) ($currentSemester['practicum_start_date'] ?? ''), null, (string) ($currentSemester['practicum_status'] ?? ''));

$sections = [];
$sectionMap = [];
$selectedSectionId = isset($_GET['school_id'])
    ? (int) $_GET['school_id']
    : (isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0);
$viewBy = strtolower(trim((string) ($_GET['view_by'] ?? 'partnered_school')));
if (!in_array($viewBy, ['partnered_school', 'section'], true)) {
    $viewBy = 'partnered_school';
}
$selectedSectionCode = trim((string) ($_GET['section_code'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$hasOriginalSectionColumn = columnExists($pdo, 'user_profiles', 'original_section');
$availableSections = $hasOriginalSectionColumn && $currentSemesterId > 0 ? $reportService->listSections($currentSemesterId) : [];
$availableSectionCodes = array_map(static fn(array $row): string => (string) ($row['section_code'] ?? ''), $availableSections);
if ($viewBy === 'section' && $selectedSectionCode !== '' && !in_array($selectedSectionCode, $availableSectionCodes, true)) {
    $selectedSectionCode = '';
}

if ($currentSemesterId > 0 && $practicumStarted) {
    $sectionStmt = $pdo->prepare(
        "SELECT g.id, g.name AS section_name
         FROM student_school_deployments current_deployment
         JOIN geofence_locations g
           ON g.id = current_deployment.geofence_id
         JOIN student_geofence_assignments sga
           ON sga.student_user_id = current_deployment.student_user_id
          AND sga.semester_id = current_deployment.semester_id
          AND sga.assignment_status = 'active'
         JOIN users u ON u.id = sga.student_user_id
         JOIN user_profiles up ON up.user_id = sga.student_user_id
         JOIN roles r ON r.id = u.role_id
         JOIN semester_students sems
           ON sems.student_user_id = sga.student_user_id
          AND sems.semester_id = :semester_id
          AND sems.enrollment_status = 'active'
         WHERE g.is_active = 1
           AND current_deployment.semester_id = :assignment_semester_id
           AND current_deployment.deployment_status = 'active'
           AND r.role_code = 'student'
           AND u.account_status = 'active'
         GROUP BY g.id, g.name
         ORDER BY g.name ASC"
    );
    $sectionStmt->execute([
        'semester_id' => $currentSemesterId,
        'assignment_semester_id' => $currentSemesterId,
    ]);
    $sections = $sectionStmt->fetchAll();
    foreach ($sections as $section) {
        $sectionMap[(int) $section['id']] = (string) $section['section_name'];
    }
    if ($selectedSectionId > 0 && !isset($sectionMap[$selectedSectionId])) {
        $selectedSectionId = 0;
    }
}

$weekOptions = [];
$weekStart = practicumWeekStart(date('Y-m-d'));
$weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
$selectedWeekIndex = 1;
$selectedWeekLabel = practicumWeekBaseLabel($weekStart, $weekEnd, $selectedWeekIndex);
$selectedWeekBadge = '';
$selectedWeekLabelClass = 'bg-emerald-600 text-white';

if ($currentSemester && $practicumStarted) {
    $weekOptions = practicumBuildWeeksFromStart((string) ($currentSemester['practicum_start_date'] ?? ''));

    if (count($weekOptions) > 0) {
        $semesterAttendanceStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM attendance_records WHERE semester_id = :semester_id"
        );
        $semesterAttendanceStmt->execute(['semester_id' => $currentSemesterId]);
        $semesterHasAttendance = ((int) $semesterAttendanceStmt->fetchColumn()) > 0;

        $requestedWeekStart = isset($_GET['week_start']) ? practicumWeekStart((string) $_GET['week_start']) : null;
        if ($requestedWeekStart === null) {
            $activeWeek = practicumActiveWeek($weekOptions);
            $requestedWeekStart = $activeWeek['start'] ?? null;
            if ((string) $currentSemester['semester_status'] === 'planned' || !$semesterHasAttendance) {
                $requestedWeekStart = $activeWeek['start'] ?? null;
            }
        }

        $weekMatch = practicumSelectWeek($weekOptions, $requestedWeekStart);

        if ($weekMatch !== null) {
            $weekStart = $weekMatch['start'];
            $weekEnd = $weekMatch['end'];
            $selectedWeekIndex = (int) $weekMatch['index'];
            $selectedWeekLabel = practicumWeekDisplayLabel($weekMatch);
            $selectedWeekBadge = practicumWeekStatusBadge($weekMatch);
            $selectedWeekLabelClass = match ((string) ($weekMatch['status'] ?? 'upcoming')) {
                'active' => 'bg-emerald-600 text-white',
                'completed' => 'bg-slate-100 text-slate-600',
                default => 'bg-slate-50 text-slate-500',
            };
        }
    }
}

$pageSize = 10;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $pageSize;

$totalStudents = 0;
$rows = [];
$summary = [
    'journal_submitted' => 0,
    'checklist_done' => 0,
    'avg_score' => 0.0,
];
$activeChecklistCount = 0;
$hasDynamicJournalSubmissions = tableExists($pdo, 'journal_submissions');
$hasStudentJournals = tableExists($pdo, 'student_journals');
$hasStudentDeployments = tableExists($pdo, 'student_school_deployments');
$schoolFilterSql = '';
if ($selectedSectionId > 0) {
    if ($hasStudentDeployments) {
        $schoolFilterSql = 'AND EXISTS (
            SELECT 1
            FROM student_school_deployments filter_deployment
            WHERE filter_deployment.student_user_id = sga.student_user_id
              AND filter_deployment.semester_id = sems.semester_id
              AND filter_deployment.deployment_status = \'active\'
              AND filter_deployment.geofence_id = :deployment_section_id
        )';
    } else {
        $schoolFilterSql = 'AND g.id = :section_id';
    }
}
$sectionFilterSql = $hasOriginalSectionColumn && $viewBy === 'section' && $selectedSectionCode !== ''
    ? 'AND TRIM(COALESCE(up.original_section, \'\')) = :section_code'
    : '';
$evaluationFilterSql = $schoolFilterSql . ' ' . $sectionFilterSql;
$searchFilterSql = $search !== ''
    ? ' AND CONCAT_WS(\' \', up.first_name, up.last_name) LIKE :student_search'
    : '';
$evaluationFilterSql .= $searchFilterSql;

if ($currentSemesterId > 0 && $hasActiveSemesterForChecklistBuilder) {
    $activeChecklistStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM evaluation_checklist_items
         WHERE is_active = 1
           AND (semester_id = :semester_id OR semester_id IS NULL)"
    );
    $activeChecklistStmt->execute(['semester_id' => $currentSemesterId]);
    $activeChecklistCount = (int) $activeChecklistStmt->fetchColumn();
}

// Setup assignments must not produce "Absent", "Pending", or evaluation
// rows. Weekly monitoring starts only when practicum is ongoing.
if ($currentSemesterId > 0 && $practicumStarted) {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT sga.student_user_id)
         FROM geofence_locations g
         JOIN student_geofence_assignments sga
           ON sga.geofence_id = g.id
          AND sga.assignment_status = 'active'
         JOIN users u ON u.id = sga.student_user_id
         JOIN user_profiles up ON up.user_id = sga.student_user_id
         JOIN roles r ON r.id = u.role_id
         JOIN semester_students sems
           ON sems.student_user_id = sga.student_user_id
          AND sems.semester_id = :semester_id
          AND sems.enrollment_status = 'active'
         WHERE g.is_active = 1
           {$evaluationFilterSql}
           AND sga.semester_id = :assignment_semester_id
           AND r.role_code = 'student'
           AND u.account_status = 'active'"
    );
    $countParams = [
        'semester_id' => $currentSemesterId,
        'assignment_semester_id' => $currentSemesterId,
    ];
    if ($selectedSectionId > 0) {
        if ($hasStudentDeployments) {
            $countParams['deployment_section_id'] = $selectedSectionId;
        } else {
            $countParams['section_id'] = $selectedSectionId;
        }
    }
    if ($sectionFilterSql !== '') {
        $countParams['section_code'] = $selectedSectionCode;
    }
    if ($searchFilterSql !== '') {
        $countParams['student_search'] = '%' . $search . '%';
    }
    $countStmt->execute($countParams);
    $totalStudents = (int) $countStmt->fetchColumn();

    if ($hasDynamicJournalSubmissions) {
        $journalSelect = "js.id AS journal_id,
            js.status AS journal_status,
            COALESCE(js.points_awarded, 0) AS journal_points_awarded,
            COALESCE(js.max_points, 50) AS journal_max_points,";
        $journalJoin = "LEFT JOIN journal_submissions js
           ON js.student_user_id = sga.student_user_id
          AND js.semester_id = sems.semester_id
          AND js.week_start = :journal_week_start";
    } elseif ($hasStudentJournals) {
        $journalSelect = "sj.id AS journal_id,
            sj.status AS journal_status,
            0 AS journal_points_awarded,
            50 AS journal_max_points,";
        $journalJoin = "LEFT JOIN student_journals sj
           ON sj.student_user_id = sga.student_user_id
          AND sj.semester_id = sems.semester_id
          AND sj.week_start = :journal_week_start";
    } else {
        $journalSelect = "NULL AS journal_id,
            NULL AS journal_status,
            0 AS journal_points_awarded,
            50 AS journal_max_points,";
        $journalJoin = '';
    }

    $rowsStmt = $pdo->prepare(
        "SELECT
            sga.student_user_id,
            :row_week_start AS week_start,
            u.school_id,
            u.email,
            up.first_name,
            up.last_name,
            g.name AS assigned_school_name,
            {$journalSelect}
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 0 AND ar.time_in IS NOT NULL THEN 1 ELSE 0 END) AS mon_has_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 0 AND ar.time_out IS NOT NULL THEN 1 ELSE 0 END) AS mon_has_time_out,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 1 AND ar.time_in IS NOT NULL THEN 1 ELSE 0 END) AS tue_has_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 1 AND ar.time_out IS NOT NULL THEN 1 ELSE 0 END) AS tue_has_time_out,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 2 AND ar.time_in IS NOT NULL THEN 1 ELSE 0 END) AS wed_has_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 2 AND ar.time_out IS NOT NULL THEN 1 ELSE 0 END) AS wed_has_time_out,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 3 AND ar.time_in IS NOT NULL THEN 1 ELSE 0 END) AS thu_has_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 3 AND ar.time_out IS NOT NULL THEN 1 ELSE 0 END) AS thu_has_time_out,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 4 AND ar.time_in IS NOT NULL THEN 1 ELSE 0 END) AS fri_has_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 4 AND ar.time_out IS NOT NULL THEN 1 ELSE 0 END) AS fri_has_time_out,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN ar.time_in ELSE NULL END) AS mon_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN ar.time_in ELSE NULL END) AS tue_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN ar.time_in ELSE NULL END) AS wed_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN ar.time_in ELSE NULL END) AS thu_time_in,
            MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.time_in ELSE NULL END) AS fri_time_in,
            COALESCE(SUM({$liveHoursSql}), 0) AS week_total_hours,
            MAX(CASE WHEN ar.attendance_date = :today_date_geo AND ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN COALESCE(ar.geofence_inside, 0) ELSE 1 END) AS today_geofence_inside,
            COALESCE(cw.checked_items, 0) AS checklist_checked_items,
            COALESCE(cw.total_score, 0) AS checklist_total_score,
            csc.confirmed_at AS checklist_confirmed_at
         FROM geofence_locations g
         JOIN student_geofence_assignments sga
           ON sga.geofence_id = g.id
          AND sga.assignment_status = 'active'
         JOIN users u ON u.id = sga.student_user_id
         JOIN roles r ON r.id = u.role_id
         JOIN user_profiles up ON up.user_id = sga.student_user_id
         JOIN semester_students sems
           ON sems.student_user_id = sga.student_user_id
          AND sems.semester_id = :semester_id
          AND sems.enrollment_status = 'active'
         {$journalJoin}
         LEFT JOIN attendance_records ar
           ON ar.student_user_id = sga.student_user_id
          AND ar.semester_id = sems.semester_id
          AND ar.attendance_date BETWEEN :week_start AND :week_end
         LEFT JOIN checklist_student_confirmations csc
           ON csc.student_user_id = sga.student_user_id
          AND csc.geofence_id = g.id
          AND csc.semester_id = sems.semester_id
          AND csc.week_start = :checklist_confirmation_week_start
         LEFT JOIN (
            SELECT
                swc.student_user_id,
                swc.geofence_id,
                swc.semester_id,
                SUM(CASE WHEN swc.is_checked = 1 THEN 1 ELSE 0 END) AS checked_items,
                COALESCE(SUM(CASE WHEN swc.is_checked = 1 THEN swc.awarded_points ELSE 0 END), 0) AS total_score
            FROM student_weekly_checklist swc
            INNER JOIN evaluation_checklist_items eci
                ON eci.id = swc.checklist_item_id
            WHERE swc.week_start = :checklist_week_start
              AND swc.semester_id = :checklist_semester_id
              AND eci.is_active = 1
              AND (eci.semester_id = :checklist_item_semester_id OR eci.semester_id IS NULL)
            GROUP BY swc.student_user_id, swc.geofence_id, swc.semester_id
             ) cw
              ON cw.student_user_id = sga.student_user_id
             AND cw.geofence_id = g.id
             AND cw.semester_id = sems.semester_id
             WHERE g.is_active = 1
               {$evaluationFilterSql}
               AND sga.semester_id = :rows_assignment_semester_id
               AND u.account_status = 'active'
               AND r.role_code = 'student'
             GROUP BY
                sga.student_user_id, u.school_id, u.email, up.first_name, up.last_name, g.name, journal_id, journal_status, journal_points_awarded, journal_max_points, cw.checked_items, cw.total_score, csc.confirmed_at
         ORDER BY up.last_name ASC, up.first_name ASC
         LIMIT :limit OFFSET :offset"
    );
    $rowsStmt->bindValue(':semester_id', $currentSemesterId, PDO::PARAM_INT);
    $rowsStmt->bindValue(':rows_assignment_semester_id', $currentSemesterId, PDO::PARAM_INT);
    $rowsStmt->bindValue(':week_start', $weekStart);
    $rowsStmt->bindValue(':week_end', $weekEnd);
    $rowsStmt->bindValue(':today_date_geo', date('Y-m-d'));
    $rowsStmt->bindValue(':row_week_start', $weekStart);
    if ($hasDynamicJournalSubmissions || $hasStudentJournals) {
        $rowsStmt->bindValue(':journal_week_start', $weekStart);
    }
        $rowsStmt->bindValue(':checklist_week_start', $weekStart);
        $rowsStmt->bindValue(':checklist_semester_id', $currentSemesterId, PDO::PARAM_INT);
        $rowsStmt->bindValue(':checklist_item_semester_id', $currentSemesterId, PDO::PARAM_INT);
        $rowsStmt->bindValue(':checklist_confirmation_week_start', $weekStart);
    if ($selectedSectionId > 0) {
        if ($hasStudentDeployments) {
            $rowsStmt->bindValue(':deployment_section_id', $selectedSectionId, PDO::PARAM_INT);
        } else {
            $rowsStmt->bindValue(':section_id', $selectedSectionId, PDO::PARAM_INT);
        }
    }
    if ($sectionFilterSql !== '') {
        $rowsStmt->bindValue(':section_code', $selectedSectionCode);
    }
    if ($searchFilterSql !== '') {
        $rowsStmt->bindValue(':student_search', '%' . $search . '%');
    }
        $rowsStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $rowsStmt->execute();
    $rows = $rowsStmt->fetchAll();

    if ($hasDynamicJournalSubmissions) {
        $summaryJournalJoin = "LEFT JOIN journal_submissions js
          ON js.student_user_id = sga.student_user_id
         AND js.semester_id = sems.semester_id
         AND js.week_start = :summary_journal_week_start";
        $summaryJournalExpr = 'MAX(CASE WHEN js.id IS NOT NULL THEN 1 ELSE 0 END)';
    } elseif ($hasStudentJournals) {
        $summaryJournalJoin = "LEFT JOIN student_journals sj
          ON sj.student_user_id = sga.student_user_id
         AND sj.semester_id = sems.semester_id
         AND sj.week_start = :summary_journal_week_start";
        $summaryJournalExpr = 'MAX(CASE WHEN sj.id IS NOT NULL THEN 1 ELSE 0 END)';
    } else {
        $summaryJournalJoin = '';
        $summaryJournalExpr = '0';
    }

    $summaryStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN t.has_journal = 1 THEN 1 ELSE 0 END) AS journal_submitted,
            SUM(
                CASE
                    WHEN :active_checklist_count_zero = 0 THEN 0
                    WHEN t.checked_items >= :active_checklist_count_complete THEN 1
                    ELSE 0
                END
            ) AS checklist_done,
            AVG(t.total_score) AS avg_score
         FROM (
            SELECT
                sga.student_user_id,
                {$summaryJournalExpr} AS has_journal,
                COALESCE(cw.checked_items, 0) AS checked_items,
                COALESCE(cw.total_score, 0) AS total_score
                FROM geofence_locations g
                JOIN student_geofence_assignments sga
                  ON sga.geofence_id = g.id
                 AND sga.assignment_status = 'active'
                JOIN users u ON u.id = sga.student_user_id
                JOIN user_profiles up ON up.user_id = sga.student_user_id
                JOIN semester_students sems
                  ON sems.student_user_id = sga.student_user_id
                 AND sems.semester_id = :semester_id
                 AND sems.enrollment_status = 'active'
                {$summaryJournalJoin}
                LEFT JOIN (
                    SELECT
                        swc.student_user_id,
                        swc.geofence_id,
                        swc.semester_id,
                        SUM(CASE WHEN swc.is_checked = 1 THEN 1 ELSE 0 END) AS checked_items,
                        COALESCE(SUM(CASE WHEN swc.is_checked = 1 THEN swc.awarded_points ELSE 0 END), 0) AS total_score
                    FROM student_weekly_checklist swc
                    INNER JOIN evaluation_checklist_items eci
                        ON eci.id = swc.checklist_item_id
                    WHERE swc.week_start = :summary_checklist_week_start
                      AND swc.semester_id = :summary_checklist_semester_id
                      AND eci.is_active = 1
                      AND (eci.semester_id = :summary_checklist_item_semester_id OR eci.semester_id IS NULL)
                    GROUP BY swc.student_user_id, swc.geofence_id, swc.semester_id
                ) cw
                  ON cw.student_user_id = sga.student_user_id
                 AND cw.geofence_id = g.id
                 AND cw.semester_id = sems.semester_id
                WHERE g.is_active = 1
                  {$evaluationFilterSql}
                  AND sga.semester_id = :summary_assignment_semester_id
                  AND u.account_status = 'active'
                GROUP BY sga.student_user_id, cw.checked_items, cw.total_score
         ) t"
    );
    $summaryStmt->bindValue(':semester_id', $currentSemesterId, PDO::PARAM_INT);
    $summaryStmt->bindValue(':summary_assignment_semester_id', $currentSemesterId, PDO::PARAM_INT);
    $summaryStmt->bindValue(':summary_checklist_week_start', $weekStart);
    $summaryStmt->bindValue(':summary_checklist_semester_id', $currentSemesterId, PDO::PARAM_INT);
    $summaryStmt->bindValue(':summary_checklist_item_semester_id', $currentSemesterId, PDO::PARAM_INT);
    if ($hasDynamicJournalSubmissions || $hasStudentJournals) {
        $summaryStmt->bindValue(':summary_journal_week_start', $weekStart);
    }
    if ($selectedSectionId > 0) {
        if ($hasStudentDeployments) {
            $summaryStmt->bindValue(':deployment_section_id', $selectedSectionId, PDO::PARAM_INT);
        } else {
            $summaryStmt->bindValue(':section_id', $selectedSectionId, PDO::PARAM_INT);
        }
    }
    if ($sectionFilterSql !== '') {
        $summaryStmt->bindValue(':section_code', $selectedSectionCode);
    }
    if ($searchFilterSql !== '') {
        $summaryStmt->bindValue(':student_search', '%' . $search . '%');
    }
    $summaryStmt->bindValue(':active_checklist_count_zero', $activeChecklistCount, PDO::PARAM_INT);
    $summaryStmt->bindValue(':active_checklist_count_complete', $activeChecklistCount, PDO::PARAM_INT);
    $summaryStmt->execute();
    $sumRow = $summaryStmt->fetch();
    if ($sumRow) {
        $summary['journal_submitted'] = (int) ($sumRow['journal_submitted'] ?? 0);
        $summary['checklist_done'] = (int) ($sumRow['checklist_done'] ?? 0);
        $summary['avg_score'] = (float) ($sumRow['avg_score'] ?? 0);
    }
}

$totalPages = max(1, (int) ceil($totalStudents / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}

$viewStudentId = isset($_GET['view_student_id']) ? (int) $_GET['view_student_id'] : 0;
$detail = null;
if ($viewStudentId > 0 && $currentSemesterId > 0 && $practicumStarted) {
    $detailSchoolFilterSql = $evaluationFilterSql;
        $detailSql = "SELECT
            sga.student_user_id,
            u.school_id,
            u.email,
            up.first_name,
            up.last_name,
            g.name AS section_name,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN {$liveHoursSql} ELSE 0 END), 0) AS mon_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN {$liveHoursSql} ELSE 0 END), 0) AS tue_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN {$liveHoursSql} ELSE 0 END), 0) AS wed_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN {$liveHoursSql} ELSE 0 END), 0) AS thu_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN {$liveHoursSql} ELSE 0 END), 0) AS fri_hours,
            COALESCE(SUM({$liveHoursSql}), 0) AS week_total_hours,
            MAX(CASE WHEN ar.attendance_date = :today_date_geo AND ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN COALESCE(ar.geofence_inside, 0) ELSE 1 END) AS today_geofence_inside,
            COALESCE(cw.total_score, 0) AS checklist_total_score
         FROM geofence_locations g
         JOIN student_geofence_assignments sga
           ON sga.geofence_id = g.id
          AND sga.assignment_status = 'active'
         JOIN users u ON u.id = sga.student_user_id
         JOIN user_profiles up ON up.user_id = sga.student_user_id
         JOIN semester_students sems
           ON sems.student_user_id = sga.student_user_id
          AND sems.semester_id = :semester_id
          AND sems.enrollment_status = 'active'
         LEFT JOIN attendance_records ar
           ON ar.student_user_id = sga.student_user_id
          AND ar.semester_id = sems.semester_id
          AND ar.attendance_date BETWEEN :week_start AND :week_end
         LEFT JOIN (
            SELECT
                swc.student_user_id,
                swc.geofence_id,
                swc.semester_id,
                COALESCE(SUM(CASE WHEN swc.is_checked = 1 THEN swc.awarded_points ELSE 0 END), 0) AS total_score
            FROM student_weekly_checklist swc
            INNER JOIN evaluation_checklist_items eci
                ON eci.id = swc.checklist_item_id
            WHERE swc.week_start = :checklist_week_start
              AND swc.semester_id = :checklist_semester_id
              AND eci.is_active = 1
              AND (eci.semester_id = :checklist_item_semester_id OR eci.semester_id IS NULL)
            GROUP BY swc.student_user_id, swc.geofence_id, swc.semester_id
         ) cw
          ON cw.student_user_id = sga.student_user_id
          AND cw.geofence_id = g.id
          AND cw.semester_id = sems.semester_id
         WHERE g.is_active = 1
           {$detailSchoolFilterSql}
           AND sga.semester_id = :detail_assignment_semester_id
            AND sga.student_user_id = :student_user_id
            AND u.account_status = 'active'
         GROUP BY sga.student_user_id, u.school_id, u.email, up.first_name, up.last_name, g.name, cw.total_score";
    $detailStmt = $pdo->prepare($detailSql);
    $detailStmt->bindValue(':week_start', $weekStart);
    $detailStmt->bindValue(':week_end', $weekEnd);
    $detailStmt->bindValue(':today_date_geo', date('Y-m-d'));
    $detailStmt->bindValue(':checklist_week_start', $weekStart);
    $detailStmt->bindValue(':checklist_semester_id', $currentSemesterId, PDO::PARAM_INT);
    $detailStmt->bindValue(':checklist_item_semester_id', $currentSemesterId, PDO::PARAM_INT);
    $detailStmt->bindValue(':semester_id', $currentSemesterId, PDO::PARAM_INT);
    $detailStmt->bindValue(':detail_assignment_semester_id', $currentSemesterId, PDO::PARAM_INT);
    $detailStmt->bindValue(':student_user_id', $viewStudentId, PDO::PARAM_INT);
    if ($selectedSectionId > 0) {
        if ($hasStudentDeployments) {
            $detailStmt->bindValue(':deployment_section_id', $selectedSectionId, PDO::PARAM_INT);
        } else {
            $detailStmt->bindValue(':section_id', $selectedSectionId, PDO::PARAM_INT);
        }
    }
    if ($sectionFilterSql !== '') {
        $detailStmt->bindValue(':section_code', $selectedSectionCode);
    }
    if ($searchFilterSql !== '') {
        $detailStmt->bindValue(':student_search', '%' . $search . '%');
    }
    $detailStmt->execute();
    $detail = $detailStmt->fetch() ?: null;
}
$detailAttendanceLog = [];
if ($detail !== null) {
    $attendanceLogStmt = $pdo->prepare(
        "SELECT attendance_date, time_in, time_out
         FROM attendance_records
         WHERE student_user_id = :student_user_id
           AND semester_id = :semester_id
           AND attendance_date BETWEEN :week_start AND :week_end
         ORDER BY attendance_date ASC, time_in ASC"
    );
    $attendanceLogStmt->execute([
        'student_user_id' => $viewStudentId,
        'semester_id' => $currentSemesterId,
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
    ]);
    $detailAttendanceLog = $attendanceLogStmt->fetchAll() ?: [];
}

$uiError = null;
$checklistItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (in_array($action, ['add_checklist_item', 'update_checklist_item', 'delete_checklist_item'], true) && !$hasActiveSemesterForChecklistBuilder) {
            throw new RuntimeException('Checklist Builder is available only while a semester is active.');
        }

        if ($action === 'add_checklist_item') {
            $checklistService->createChecklistItem(
                (string) ($_POST['item_name'] ?? ''),
                (int) ($_POST['points'] ?? 0),
                (int) ($user['id'] ?? 0),
                $currentSemesterId
            );
        } elseif ($action === 'update_checklist_item') {
            $checklistService->updateChecklistDefinition(
                (int) ($_POST['item_id'] ?? 0),
                (string) ($_POST['item_name'] ?? ''),
                (int) ($_POST['points'] ?? 0),
                (int) ($_POST['display_order'] ?? 0),
                isset($_POST['is_active']),
                $currentSemesterId
            );
        } elseif ($action === 'delete_checklist_item') {
            $checklistService->deleteChecklistItem((int) ($_POST['item_id'] ?? 0), $currentSemesterId);
        }

        if ($action !== '') {
            header(
                'Location: ' . appUrl('head_teacher/practicum_evaluation.php?') . http_build_query([
                    'week_start' => $weekStart,
                    'school_id' => $selectedSectionId,
                    'page' => $page,
                ])
            );
            exit;
        }
    } catch (Throwable $e) {
        $uiError = $e->getMessage();
    }
}

$checklistItems = $hasActiveSemesterForChecklistBuilder
    ? $checklistService->getChecklistBuilderItems($currentSemesterId)
    : [];

$checklistTotalPoints = 0;
foreach ($checklistItems as $item) {
    if ((int) ($item['is_active'] ?? 0) === 1) {
        $checklistTotalPoints += (int) ($item['points'] ?? 0);
    }
}

renderHeadTeacherPortalStart($user ?? [], 'evaluation');
?>
<section class="mt-6 space-y-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Practicum Evaluation</h2>
                <p class="mt-1 text-sm text-slate-600">Weekly evaluation table, journal submissions, and checklist scoring.</p>
            </div>
            <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                <form method="get" class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                    <select id="evaluationViewBy" name="view_by" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                        <option value="partnered_school" <?= $viewBy === 'partnered_school' ? 'selected' : '' ?>>Partnered School</option>
                        <option value="section" <?= $viewBy === 'section' ? 'selected' : '' ?>>Section</option>
                    </select>
                    <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search student name" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 placeholder:text-slate-400 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                    <select name="week_start" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                        <?php foreach ($weekOptions as $opt): ?>
                            <option value="<?= htmlspecialchars((string) $opt['start']) ?>" <?= $opt['start'] === $weekStart ? 'selected' : '' ?>>
                                <?= htmlspecialchars(practicumWeekFilterLabel($opt)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="evaluationSchool" name="school_id" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100 <?= $viewBy === 'section' ? 'hidden' : '' ?>">
                        <option value="0" <?= $selectedSectionId === 0 ? 'selected' : '' ?>>All Schools</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= (int) $section['id'] ?>" <?= (int) $section['id'] === $selectedSectionId ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $section['section_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="evaluationSection" name="section_code" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100 <?= $viewBy === 'section' ? '' : 'hidden' ?>">
                        <option value="">All Sections</option>
                        <?php foreach ($availableSections as $section): ?>
                            <?php $sectionCode = (string) ($section['section_code'] ?? ''); ?>
                            <option value="<?= htmlspecialchars($sectionCode) ?>" <?= $sectionCode === $selectedSectionCode ? 'selected' : '' ?>><?= htmlspecialchars($sectionCode) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($uiError !== null): ?>
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= htmlspecialchars($uiError) ?></div>
    <?php endif; ?>

    <?php if (!$practicumStarted): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 shadow-sm">
            <p class="font-bold">Practicum tracking has not started.</p>
            <p class="mt-1">No attendance, journal, checklist, or evaluation status is being recorded. These dashboards will begin when the Head Teacher starts practicum.</p>
        </div>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Students</p>
            <p id="evalTotalStudents" class="mt-2 text-4xl font-bold text-slate-900"><?= $totalStudents ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Journal Submitted</p>
            <p id="evalJournalSubmitted" class="mt-2 text-3xl font-bold text-slate-900"><?= $summary['journal_submitted'] ?></p>
            <p class="text-sm text-slate-500">Friday submissions this week</p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-orange-600">Checklist Done</p>
            <p id="evalChecklistDone" class="mt-2 text-3xl font-bold text-slate-900"><?= $summary['checklist_done'] ?></p>
            <p class="text-sm text-slate-500">Students with all active checklist items checked</p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Avg Score</p>
            <p class="mt-2 text-3xl font-bold text-slate-900"><span id="evalAvgScore"><?= number_format($summary['avg_score'], 1) ?></span><span class="text-base font-medium text-slate-500"> pts</span></p>
            <p class="text-sm text-slate-500">Average checklist points awarded this week</p>
        </article>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2 text-xl font-semibold text-slate-900">
                <span><?= $selectedSectionId > 0 ? htmlspecialchars($sectionMap[$selectedSectionId] ?? 'Assigned School') : 'All Students' ?></span>
                <span>&mdash; Weekly Evaluation</span>
                <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= $totalStudents ?> Students</span>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <span><?= htmlspecialchars($selectedWeekLabel) ?>, <?= htmlspecialchars($weekStart) ?> to <?= htmlspecialchars($weekEnd) ?></span>
                <?= $selectedWeekBadge ?>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-[1160px] w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Student Name</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Assigned School</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">M</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">T</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">W</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">TH</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">F</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Journal (Fri)</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Checklist</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Score</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody id="evaluationRows">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $studentUserId = (int) $row['student_user_id'];
                        $monDate = $weekStart;
                        $tueDate = (new DateTimeImmutable($weekStart))->modify('+1 day')->format('Y-m-d');
                        $wedDate = (new DateTimeImmutable($weekStart))->modify('+2 day')->format('Y-m-d');
                        $thuDate = (new DateTimeImmutable($weekStart))->modify('+3 day')->format('Y-m-d');
                        $friDate = (new DateTimeImmutable($weekStart))->modify('+4 day')->format('Y-m-d');
                        $checkedItems = (int) ($row['checklist_checked_items'] ?? 0);
                        $score = (int) ($row['checklist_total_score'] ?? 0);
                        $hasJournal = !empty($row['journal_id']);
                        $journalStatus = (string) ($row['journal_status'] ?? '');
                        $journal = !$hasJournal ? 'Pending' : match ($journalStatus) {
                            'resubmitted' => 'Resubmitted',
                            'declined', 'revise' => 'Declined',
                            'approved' => 'Approved',
                            'reviewed' => 'Reviewed',
                            default => 'Submitted',
                        };
                        $journalTone = match ($journal) {
                            'Resubmitted' => 'text-blue-700',
                            'Declined' => 'text-rose-700',
                            'Approved', 'Submitted' => 'text-emerald-700',
                            default => 'text-amber-700',
                        };
                        if (!empty($row['checklist_confirmed_at'])) {
                            $checklist = 'Confirmed';
                            $checklistTone = 'text-emerald-700';
                        } elseif ($checkedItems === 0) {
                            $checklist = 'Not Yet';
                            $checklistTone = 'text-slate-500';
                        } elseif ($activeChecklistCount > 0 && $checkedItems >= $activeChecklistCount) {
                            $checklist = 'Completed';
                            $checklistTone = 'text-emerald-700';
                        } else {
                            $checklist = 'In Progress';
                            $checklistTone = 'text-amber-700';
                        }
                        $baseQuery = [
                            'week_start' => $weekStart,
                            'school_id' => $selectedSectionId,
                            'view_by' => $viewBy,
                            'section_code' => $selectedSectionCode,
                            'search' => $search,
                            'page' => $page,
                            'view_student_id' => (int) $row['student_user_id'],
                        ];
                        ?>
                        <tr class="transition hover:bg-slate-50">
                            <td class="border-b border-slate-100 px-4 py-3">
                                <p class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars((string) $row['email']) ?></p>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) $row['assigned_school_name']) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3" data-day-hours="<?= $studentUserId ?>:mon"><?= attendanceDayBadge($monDate, (int) ($row['mon_has_time_in'] ?? 0), (int) ($row['mon_has_time_out'] ?? 0), $row['mon_time_in'] ?? null, $monDate === date('Y-m-d') && (int) ($row['today_geofence_inside'] ?? 1) !== 1) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3" data-day-hours="<?= $studentUserId ?>:tue"><?= attendanceDayBadge($tueDate, (int) ($row['tue_has_time_in'] ?? 0), (int) ($row['tue_has_time_out'] ?? 0), $row['tue_time_in'] ?? null, $tueDate === date('Y-m-d') && (int) ($row['today_geofence_inside'] ?? 1) !== 1) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3" data-day-hours="<?= $studentUserId ?>:wed"><?= attendanceDayBadge($wedDate, (int) ($row['wed_has_time_in'] ?? 0), (int) ($row['wed_has_time_out'] ?? 0), $row['wed_time_in'] ?? null, $wedDate === date('Y-m-d') && (int) ($row['today_geofence_inside'] ?? 1) !== 1) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3" data-day-hours="<?= $studentUserId ?>:thu"><?= attendanceDayBadge($thuDate, (int) ($row['thu_has_time_in'] ?? 0), (int) ($row['thu_has_time_out'] ?? 0), $row['thu_time_in'] ?? null, $thuDate === date('Y-m-d') && (int) ($row['today_geofence_inside'] ?? 1) !== 1) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3" data-day-hours="<?= $studentUserId ?>:fri"><?= attendanceDayBadge($friDate, (int) ($row['fri_has_time_in'] ?? 0), (int) ($row['fri_has_time_out'] ?? 0), $row['fri_time_in'] ?? null, $friDate === date('Y-m-d') && (int) ($row['today_geofence_inside'] ?? 1) !== 1) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3" data-journal="<?= $studentUserId ?>">
                                <span class="text-xs italic <?= $journalTone ?>"><?= $journal ?></span>
                                <div class="mt-1 text-xs font-semibold text-slate-700"><?= (int) ($row['journal_points_awarded'] ?? 0) ?> / <?= max(1, (int) ($row['journal_max_points'] ?? 50)) ?> points</div>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3" data-checklist="<?= $studentUserId ?>">
                                <span class="text-xs italic <?= $checklistTone ?>"><?= $checklist ?></span>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3" data-score="<?= $studentUserId ?>"><?= $score ?> pts</td>
                            <td class="border-b border-slate-100 px-4 py-3">
                                <a href="?<?= htmlspecialchars(http_build_query($baseQuery)) ?>" class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">View Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="11" class="border-b border-slate-100 px-4 py-8 text-center text-sm text-slate-500">
                                <?= $practicumStarted ? 'No student records for this filter.' : 'Waiting for practicum start date.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500">
                Showing <?= $totalStudents === 0 ? 0 : ($offset + 1) ?> to <?= min($offset + $pageSize, $totalStudents) ?> of <?= $totalStudents ?> students
            </p>
            <div class="flex gap-2">
                <?php
                $prevQuery = ['week_start' => $weekStart, 'school_id' => $selectedSectionId, 'view_by' => $viewBy, 'section_code' => $selectedSectionCode, 'search' => $search, 'page' => max(1, $page - 1)];
                $nextQuery = ['week_start' => $weekStart, 'school_id' => $selectedSectionId, 'view_by' => $viewBy, 'section_code' => $selectedSectionCode, 'search' => $search, 'page' => min($totalPages, $page + 1)];
                ?>
                <a href="?<?= htmlspecialchars(http_build_query($prevQuery)) ?>" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 <?= $page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-slate-100' ?>">Previous</a>
                <a href="?<?= htmlspecialchars(http_build_query($nextQuery)) ?>" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : 'hover:bg-slate-100' ?>">Next</a>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-3">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-3xl font-semibold tracking-tight text-slate-900">Checklist Builder</h3>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">Total: <?= $checklistTotalPoints ?> pts</span>
            </div>
            <div class="space-y-3">
                <?php foreach ($checklistItems as $item): ?>
                    <form method="post" class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-12 md:items-center">
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <div class="md:col-span-4">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Checklist Item</label>
                            <input type="text" name="item_name" value="<?= htmlspecialchars((string) $item['item_name']) ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Points</label>
                            <input type="number" min="1" name="points" value="<?= (int) $item['points'] ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Order</label>
                            <input type="number" min="0" name="display_order" value="<?= (int) $item['display_order'] ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
                            <label class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                                <input type="checkbox" name="is_active" value="1" <?= (int) ($item['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span><?= (int) ($item['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?></span>
                            </label>
                        </div>
                        <div class="flex gap-2 md:col-span-2 md:justify-end">
                            <button type="submit" name="action" value="update_checklist_item" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100">Save</button>
                            <button type="submit" name="action" value="delete_checklist_item" onclick="return confirm('Delete this checklist item?');" class="rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Delete</button>
                        </div>
                    </form>
                <?php endforeach; ?>
                <?php if (!$checklistItems): ?>
                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                        <?= $hasActiveSemesterForChecklistBuilder ? 'No checklist items yet. Add your first active scoring criterion below.' : 'No active semester. Checklist items from ended semesters are archived with their semester history.' ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($hasActiveSemesterForChecklistBuilder): ?>
                <form method="post" class="mt-4 grid gap-3 sm:grid-cols-5">
                    <input type="hidden" name="action" value="add_checklist_item">
                    <input type="text" name="item_name" placeholder="Checklist item" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 sm:col-span-3" required>
                    <input type="number" min="1" name="points" placeholder="Pts" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 sm:col-span-1" required>
                    <button type="submit" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100 sm:col-span-1">+ Add Item</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm lg:col-span-2">
            <h3 class="text-3xl font-semibold tracking-tight text-slate-900">Evaluation Policy</h3>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-blue-900">
                <li>Checklist evaluation happens <span class="font-semibold">once per week</span> per section.</li>
                <li>Only the Head Teacher can create, reorder, activate, deactivate, edit, and delete checklist criteria.</li>
                <li>Coordinators can only check or uncheck active checklist items for students.</li>
                <li>New active items appear automatically in the Coordinator Weekly Checklist.</li>
                <li>Journals are considered submitted only when students submit the dynamic weekly journal form.</li>
                <li>Student totals update from the sum of awarded points on checked checklist items.</li>
            </ul>
        </div>
    </div>

    <?php if ($detail): ?>
        <?php
        $modalBase = ['week_start' => $weekStart, 'school_id' => $selectedSectionId, 'view_by' => $viewBy, 'section_code' => $selectedSectionCode, 'search' => $search, 'page' => $page];
        $closeUrl = '?' . http_build_query($modalBase);
        $detailTotal = (float) $detail['week_total_hours'];
        $detailScore = (float) ($detail['checklist_total_score'] ?? 0);
        ?>
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/55 p-4">
            <div class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex items-start justify-between border-b border-slate-200 px-6 py-4">
                    <div>
                        <h3 class="text-xl font-semibold text-slate-900">Student Evaluation Details</h3>
                        <p class="text-sm text-slate-500"><?= htmlspecialchars((string) ($detail['last_name'] . ', ' . $detail['first_name'])) ?> • <?= htmlspecialchars((string) $detail['school_id']) ?></p>
                    </div>
                    <a href="<?= htmlspecialchars($closeUrl) ?>" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Close</a>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Assigned School</p>
                            <p class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) $detail['section_name']) ?></p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Week</p>
                            <p class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars($weekStart) ?> to <?= htmlspecialchars($weekEnd) ?></p>
                        </div>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-5">
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Mon</p><p class="font-semibold text-slate-900"><?= practicumFormatHours((float) $detail['mon_hours']) ?>h</p></div>
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Tue</p><p class="font-semibold text-slate-900"><?= practicumFormatHours((float) $detail['tue_hours']) ?>h</p></div>
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Wed</p><p class="font-semibold text-slate-900"><?= practicumFormatHours((float) $detail['wed_hours']) ?>h</p></div>
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Thu</p><p class="font-semibold text-slate-900"><?= practicumFormatHours((float) $detail['thu_hours']) ?>h</p></div>
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Fri</p><p class="font-semibold text-slate-900"><?= practicumFormatHours((float) $detail['fri_hours']) ?>h</p></div>
                    </div>
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <p class="text-sm text-emerald-900">Weekly Total: <span class="font-semibold"><?= practicumFormatHours($detailTotal) ?>h</span> &bull; Checklist Score: <span class="font-semibold"><?= number_format($detailScore, 1) ?>/20</span></p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-4 py-3">
                            <h4 class="font-semibold text-slate-900">Attendance Log</h4>
                            <p class="mt-1 text-xs text-slate-500">Time-in and time-out records for the selected week.</p>
                        </div>
                        <div class="max-h-56 overflow-y-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-semibold">Attendance Date</th>
                                        <th class="px-4 py-2.5 font-semibold">Time In</th>
                                        <th class="px-4 py-2.5 font-semibold">Time Out</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($detailAttendanceLog as $attendanceLog): ?>
                                        <tr>
                                            <td class="px-4 py-2.5 text-slate-700"><?= htmlspecialchars((string) ($attendanceLog['attendance_date'] ?? '')) ?></td>
                                            <td class="px-4 py-2.5 text-slate-700"><?= htmlspecialchars(attendanceLogTime($attendanceLog['time_in'] ?? null, 'Not recorded')) ?></td>
                                            <td class="px-4 py-2.5 text-slate-700"><?= htmlspecialchars(attendanceLogTime($attendanceLog['time_out'] ?? null)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if ($detailAttendanceLog === []): ?>
                                        <tr><td colspan="3" class="px-4 py-4 text-center text-sm text-slate-500">No attendance records for the selected week.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php
$evaluationApiUrl = json_encode(appUrl('api/head_teacher_live_evaluation.php') . '?' . http_build_query([
    'week_start' => $weekStart,
    'school_id' => $selectedSectionId,
    'view_by' => $viewBy,
    'section_code' => $selectedSectionCode,
    'search' => $search,
    'page' => $page,
    'page_size' => $pageSize,
]));
$evaluationLiveScript = $practicumStarted ? str_replace(
    ['__API_URL__', '__ACTIVE_CHECKLIST_COUNT__'],
    [$evaluationApiUrl, json_encode($activeChecklistCount)],
    <<<'HTML'
<script>
(() => {
  const viewBy = document.getElementById("evaluationViewBy");
  const school = document.getElementById("evaluationSchool");
  const section = document.getElementById("evaluationSection");
  function syncEvaluationView() {
    const bySection = viewBy && viewBy.value === "section";
    school?.classList.toggle("hidden", bySection);
    section?.classList.toggle("hidden", !bySection);
  }
  viewBy?.addEventListener("change", () => {
    if (viewBy.value === "section" && school) school.value = "0";
    if (viewBy.value === "partnered_school" && section) section.value = "";
    syncEvaluationView();
  });
  syncEvaluationView();

  const apiUrl = __API_URL__;
  const activeChecklistCount = Number(__ACTIVE_CHECKLIST_COUNT__ || 0);
  const totalStudentsEl = document.getElementById("evalTotalStudents");
  const journalEl = document.getElementById("evalJournalSubmitted");
  const checklistEl = document.getElementById("evalChecklistDone");
  const avgScoreEl = document.getElementById("evalAvgScore");
  const rowsEl = document.getElementById("evaluationRows");
  if (!rowsEl) return;

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      "\"": "&quot;",
      "'": "&#039;"
    }[char]));
  }

  function formatHours(hours) {
    const totalMinutes = Math.max(0, Math.floor((Number(hours || 0) * 60) + 0.00001));
    const wholeHours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${wholeHours}.${String(minutes).padStart(2, "0")}`;
  }

  function renderHoursCell(hours) {
    const amount = Number(hours || 0);
    if (amount <= 0) {
      return '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Upcoming</span>';
    }
    return formatHours(amount) + " / 8.00 hours";
  }

  function renderAttendanceCell(dateValue, hasTimeIn, hasTimeOut, timeIn, outsideGeofence = false) {
    const today = new Date();
    const todayValue = [
      today.getFullYear(),
      String(today.getMonth() + 1).padStart(2, "0"),
      String(today.getDate()).padStart(2, "0")
    ].join("-");
    const isLate = String(timeIn || "").length >= 19 && String(timeIn).slice(11, 19) >= "08:30:00";
    if (Number(hasTimeIn || 0) === 1 && Number(hasTimeOut || 0) === 1) {
      if (isLate) return '<span class="inline-flex items-center rounded-full bg-yellow-100 px-3 py-1 text-xs font-semibold text-yellow-800 ring-1 ring-inset ring-yellow-200">Completed</span>';
      return '<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">Completed</span>';
    }
    if (Number(hasTimeIn || 0) === 1) {
      if (String(dateValue || "") === todayValue && Number(hasTimeOut || 0) === 0 && outsideGeofence) return '<span class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">Outside geofence</span>';
      if (String(dateValue || "") < todayValue) return '<span class="inline-flex items-center rounded-full bg-orange-100 px-2 py-1 text-center text-xs font-semibold leading-tight text-orange-700 ring-1 ring-inset ring-orange-200">Pending<br>Timeout</span>';
      if (isLate) return '<span class="inline-flex items-center rounded-full bg-yellow-100 px-3 py-1 text-xs font-semibold text-yellow-800 ring-1 ring-inset ring-yellow-200">Ongoing</span>';
      return '<span class="inline-flex items-center rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-100">Ongoing</span>';
    }
    if (String(dateValue || "") > todayValue) {
      return '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Upcoming</span>';
    }
    if (String(dateValue || "") === todayValue) {
      const currentTime = `${String(today.getHours()).padStart(2, "0")}:${String(today.getMinutes()).padStart(2, "0")}:${String(today.getSeconds()).padStart(2, "0")}`;
      if (currentTime < "06:00:00") return '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Upcoming</span>';
    }

    return '<span class="inline-flex items-center rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-100">Absent</span>';
  }

  function renderLabel(label, tone) {
    return `<span class="text-xs italic ${tone}">${label}</span>`;
  }

  function renderRow(row) {
    const parseLocalDate = (value) => {
      const parts = String(value || "").split("-");
      if (parts.length !== 3) {
        return new Date(NaN);
      }
      return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    };
    const monday = parseLocalDate(row.week_start);
    const tuesday = new Date(monday); tuesday.setDate(monday.getDate() + 1);
    const wednesday = new Date(monday); wednesday.setDate(monday.getDate() + 2);
    const thursday = new Date(monday); thursday.setDate(monday.getDate() + 3);
    const friday = new Date(monday); friday.setDate(monday.getDate() + 4);
    const asDate = (date) => {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, "0");
      const day = String(date.getDate()).padStart(2, "0");
      return `${year}-${month}-${day}`;
    };
    const checkedItems = Number(row.checklist_checked_items || 0);
    const score = Number(row.checklist_total_score || 0);
    const journalStatus = String(row.journal_status || "");
    const journalLabel = Number(row.journal_id || 0) <= 0
      ? "Pending"
      : journalStatus === "resubmitted"
        ? "Resubmitted"
        : ["declined", "revise"].includes(journalStatus)
          ? "Declined"
          : journalStatus === "approved"
            ? "Approved"
            : journalStatus === "reviewed"
              ? "Reviewed"
              : "Submitted";
    const journalTone = {
      Resubmitted: "text-blue-700",
      Declined: "text-rose-700",
      Approved: "text-emerald-700",
      Submitted: "text-emerald-700",
    }[journalLabel] || "text-amber-700";
    const journal = `${renderLabel(journalLabel, journalTone)}<div class="mt-1 text-xs font-semibold text-slate-700">${Number(row.journal_points_awarded || 0)} / ${Math.max(1, Number(row.journal_max_points || 50))} points</div>`;
    let checklist = renderLabel("Not Yet", "text-slate-500");
    if (row.checklist_confirmed_at) {
      checklist = renderLabel("Confirmed", "text-emerald-700");
    } else if (checkedItems > 0 && activeChecklistCount > 0) {
      checklist = checkedItems >= activeChecklistCount
        ? renderLabel("Completed", "text-emerald-700")
        : renderLabel("In Progress", "text-amber-700");
    }
    const query = new URLSearchParams(window.location.search);
    query.set("view_student_id", Number(row.student_user_id || 0));
    const href = "?" + query.toString();
    return `<tr class="transition hover:bg-slate-50">
      <td class="border-b border-slate-100 px-4 py-3">
        <p class="font-semibold text-slate-900">${escapeHtml(`${row.last_name || ""}, ${row.first_name || ""}`)}</p>
        <p class="text-xs text-slate-500">${escapeHtml(row.email || "")}</p>
      </td>
      <td class="border-b border-slate-100 px-4 py-3 text-slate-700">${escapeHtml(row.assigned_school_name || "")}</td>
      <td class="border-b border-slate-100 px-4 py-3">${renderAttendanceCell(asDate(monday), row.mon_has_time_in, row.mon_has_time_out, row.mon_time_in, asDate(monday) === asDate(new Date()) && Number(row.today_geofence_inside ?? 1) !== 1)}</td>
      <td class="border-b border-slate-100 px-4 py-3">${renderAttendanceCell(asDate(tuesday), row.tue_has_time_in, row.tue_has_time_out, row.tue_time_in, asDate(tuesday) === asDate(new Date()) && Number(row.today_geofence_inside ?? 1) !== 1)}</td>
      <td class="border-b border-slate-100 px-4 py-3">${renderAttendanceCell(asDate(wednesday), row.wed_has_time_in, row.wed_has_time_out, row.wed_time_in, asDate(wednesday) === asDate(new Date()) && Number(row.today_geofence_inside ?? 1) !== 1)}</td>
      <td class="border-b border-slate-100 px-4 py-3">${renderAttendanceCell(asDate(thursday), row.thu_has_time_in, row.thu_has_time_out, row.thu_time_in, asDate(thursday) === asDate(new Date()) && Number(row.today_geofence_inside ?? 1) !== 1)}</td>
      <td class="border-b border-slate-100 px-4 py-3">${renderAttendanceCell(asDate(friday), row.fri_has_time_in, row.fri_has_time_out, row.fri_time_in, asDate(friday) === asDate(new Date()) && Number(row.today_geofence_inside ?? 1) !== 1)}</td>
      <td class="border-b border-slate-100 px-4 py-3">${journal}</td>
      <td class="border-b border-slate-100 px-4 py-3">${checklist}</td>
      <td class="border-b border-slate-100 px-4 py-3">${score} pts</td>
      <td class="border-b border-slate-100 px-4 py-3">
        <a href="${href}" class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">View Details</a>
      </td>
    </tr>`;
  }

  async function refresh() {
    try {
      const response = await fetch(apiUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const data = await response.json();
      if (data.error) return;
      const summary = data.summary || {};
      if (totalStudentsEl) totalStudentsEl.textContent = Number(summary.total_students || 0);
      if (journalEl) journalEl.textContent = Number(summary.journal_submitted || 0);
      if (checklistEl) checklistEl.textContent = Number(summary.checklist_done || 0);
      if (avgScoreEl) avgScoreEl.textContent = Number(summary.avg_score || 0).toFixed(1);
      const rows = Array.isArray(data.rows) ? data.rows : [];
      if (rows.length === 0) {
        rowsEl.innerHTML = '<tr><td colspan="11" class="border-b border-slate-100 px-4 py-8 text-center text-sm text-slate-500">No student records for this filter.</td></tr>';
        return;
      }
      rowsEl.innerHTML = rows.map(renderRow).join("");
    } catch (_error) {
    }
  }

  refresh();
  window.setInterval(refresh, 30000);
})();
</script>
HTML
) : '';
renderHeadTeacherPortalEnd($evaluationLiveScript);
?>
