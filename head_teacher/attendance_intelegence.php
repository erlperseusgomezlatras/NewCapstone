<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/HeadTeacherReportService.php';
require_once __DIR__ . '/../services/AttendanceCalculation.php';
require_once __DIR__ . '/../services/AttendanceStatusService.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$reportService = new HeadTeacherReportService($pdo);
$hasOriginalSectionColumn = (static function (PDO $pdo): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name LIMIT 1');
    $stmt->execute(['table_name' => 'user_profiles', 'column_name' => 'original_section']);
    return $stmt->fetchColumn() !== false;
})($pdo);
$todayDate = date('Y-m-d');
$todayLabel = date('l, F j, Y');
$weekStart = practicumWeekStart(date('Y-m-d'));
$weekEnd = (new DateTimeImmutable('friday this week'))->format('Y-m-d');
$headTeacherHolidayDates = (new SystemHolidayService($pdo))->listBetween($weekStart, $weekEnd);
$liveHoursSql = AttendanceCalculation::renderedHoursSql('ar');
$semesterLiveHoursSql = AttendanceCalculation::renderedHoursSql('semester_ar', $pdo->quote($todayDate));

function headTeacherWeekDates(string $weekStart): array
{
    $start = new DateTimeImmutable($weekStart);
    return [
        'mon' => $start->format('Y-m-d'),
        'tue' => $start->modify('+1 day')->format('Y-m-d'),
        'wed' => $start->modify('+2 days')->format('Y-m-d'),
        'thu' => $start->modify('+3 days')->format('Y-m-d'),
        'fri' => $start->modify('+4 days')->format('Y-m-d'),
    ];
}

function headTeacherDayState(array $row, string $day, string $date, string $todayDate): string
{
    if (isset($GLOBALS['headTeacherHolidayDates'][$date])) {
        return 'holiday';
    }
    $hours = (float) ($row[$day . '_hours'] ?? 0);
    $hasTimeIn = (int) ($row[$day . '_has_time_in'] ?? 0) === 1;
    $hasTimeOut = (int) ($row[$day . '_has_time_out'] ?? 0) === 1;
    $timeIn = (string) ($row[$day . '_time_in'] ?? '');
    $isLate = $timeIn !== '' && (new DateTimeImmutable($timeIn))->format('H:i:s') >= '08:30:00';

    if ($hasTimeIn && !$hasTimeOut) {
        if ($date === $todayDate && (int) ($row['today_geofence_inside'] ?? 1) !== 1) {
            return 'outside_geofence';
        }
        if ($date < $todayDate) {
            return 'pending_timeout';
        }
        return $isLate ? 'ongoing_late' : 'ongoing';
    }

    if (($hasTimeIn && $hasTimeOut) || $hours > 0) {
        return $isLate ? 'completed_late' : 'completed';
    }

    if ($date === $todayDate) {
        return date('H:i:s') >= '06:00:00' ? 'absent' : 'future';
    }

    return $date > $todayDate ? 'future' : 'absent';
}

function headTeacherAttendanceStatusLabel(array $row, string $weekStart, string $todayDate): string
{
    if (isset($GLOBALS['headTeacherHolidayDates'][$todayDate])) {
        return 'HOLIDAY';
    }
    return AttendanceStatusService::todayStatus($row);
    /* Historical weekly aggregate logic below is intentionally unreachable. */
    $completedDays = 0;
    $presentDays = 0;
    $absentDays = 0;
    $futureDays = 0;
    $todayState = null;

    foreach (headTeacherWeekDates($weekStart) as $day => $date) {
        $state = headTeacherDayState($row, $day, $date, $todayDate);
        if ($date === $todayDate) $todayState = $state;
        if ($state === 'completed' || $state === 'completed_late' || $state === 'holiday') {
            $completedDays++;
            $presentDays++;
        } elseif ($state === 'ongoing' || $state === 'ongoing_late' || $state === 'pending_timeout') {
            $presentDays++;
        } elseif ($state === 'future') {
            $futureDays++;
        } else {
            $absentDays++;
        }
    }

    if ($completedDays >= 5) {
        return 'Completed';
    }
    if ($futureDays === 5 || ($todayState === 'future' && date('H:i:s') < '06:00:00')) {
        return 'Upcoming';
    }
    if ($todayState === 'ongoing_late') {
        return 'Ongoing late';
    }
    if ($todayState === 'ongoing') {
        return 'Ongoing';
    }
    if ($absentDays >= 5) {
        return 'Critical';
    }
    if ($presentDays > 0) {
        return 'Critical';
    }

    return 'Absent';
}

function headTeacherAttendanceStatusBadge(array $row, string $weekStart, string $todayDate): string
{
    return match (headTeacherAttendanceStatusLabel($row, $weekStart, $todayDate)) {
        'HOLIDAY' => '<span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">H - Holiday</span>',
        'PRESENT' => '<span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">Present</span>',
        'INCA' => '<span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">INCA</span>',
        'Upcoming' => '<span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Upcoming</span>',
        'Ongoing' => '<span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">Ongoing</span>',
        'Ongoing late' => '<span class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-800">Ongoing late</span>',
        'Completed' => '<span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">Completed</span>',
        'Critical' => '<span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700">Critical</span>',
        default => '<span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700">Absent</span>',
    };
}

function headTeacherDayBadge(array $row, string $day, string $date, string $todayDate): string
{
    $state = headTeacherDayState($row, $day, $date, $todayDate);
    $hours = (float) ($row[$day . '_hours'] ?? 0);
    return match ($state) {
        'holiday' => '<span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800" title="Holiday; no practicum hours credited">H - Holiday</span>',
        'completed' => '<span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">✓ ' . practicumFormatHours($hours) . '</span>',
        'ongoing' => '<span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">' . practicumFormatHours($hours) . '</span>',
        'ongoing_late' => '<span class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-800">' . practicumFormatHours($hours) . '</span>',
        'outside_geofence' => '<span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700">' . practicumFormatHours($hours) . '</span>',
        'pending_timeout' => '<span class="inline-flex items-center rounded-full bg-orange-100 px-2 py-1 text-center text-xs font-semibold leading-tight text-orange-700">Pending<br>Timeout</span>',
        'completed_late' => '<span class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-800">âœ“ ' . practicumFormatHours($hours) . '</span>',
        'future' => '<span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Upcoming</span>',
        default => '<span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700">✖</span>',
    };
}

$semesterStmt = $pdo->query(
    "SELECT sem.id, sem.semester_name, sem.semester_status, sem.start_date, sem.practicum_start_date, sem.practicum_status, sem.end_date, sy.year_label
     FROM semesters sem
     JOIN school_years sy ON sy.id = sem.school_year_id
     ORDER BY (sem.semester_status = 'active') DESC, sy.start_date DESC, sem.semester_no ASC
     LIMIT 1"
);
$currentSemester = $semesterStmt->fetch() ?: null;
$currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;
$practicumStarted = $currentSemester !== null && practicumCanRun((string) ($currentSemester['practicum_start_date'] ?? ''), null, (string) ($currentSemester['practicum_status'] ?? ''));

$schools = [];
$sectionStatuses = [];
$selectedSchoolId = isset($_GET['school_id']) ? (int) $_GET['school_id'] : 0;
$viewBy = strtolower(trim((string) ($_GET['view_by'] ?? 'partnered_school')));
if (!in_array($viewBy, ['partnered_school', 'section'], true)) {
    $viewBy = 'partnered_school';
}
$selectedSectionCode = trim((string) ($_GET['section_code'] ?? ''));
$selectedStudentSearch = trim((string) ($_GET['student_search'] ?? ''));
$selectedStatus = trim((string) ($_GET['status'] ?? ''));
if (!in_array($selectedStatus, ['PRESENT', 'INCA', 'ABSENT', 'HOLIDAY'], true)) {
    $selectedStatus = '';
}
$selectedWeekNo = isset($_GET['week_no']) ? max(1, (int) $_GET['week_no']) : 0;
$weekOptions = [];
$weekStart = practicumWeekStart(date('Y-m-d'));
$weekEnd = (new DateTimeImmutable('friday this week'))->format('Y-m-d');
$selectedWeek = null;
$selectedWeekLabel = 'Week 1';
$selectedWeekBadge = '';

if ($currentSemesterId > 0 && $practicumStarted) {
    $weekOptions = practicumBuildWeeksFromStart((string) ($currentSemester['practicum_start_date'] ?? ''));
    if ($selectedWeekNo > 0) {
        foreach ($weekOptions as $opt) {
            if ($opt['index'] === $selectedWeekNo) {
                $selectedWeek = $opt;
                break;
            }
        }
    }
    $selectedWeek ??= practicumActiveWeek($weekOptions) ?? ($weekOptions[0] ?? null);

    if ($selectedWeek !== null) {
        $weekStart = $selectedWeek['start'];
        $weekEnd = $selectedWeek['end'];
        $selectedWeekNo = (int) $selectedWeek['index'];
        $selectedWeekLabel = practicumWeekDisplayLabel($selectedWeek);
        $selectedWeekBadge = practicumWeekStatusBadge($selectedWeek);
    }
}

$selectedSectionId = $selectedSchoolId;
$sections = $hasOriginalSectionColumn && $currentSemesterId > 0 ? $reportService->listSections($currentSemesterId) : [];
if ($viewBy === 'section' && $selectedSectionCode !== '') {
    $sectionCodes = array_map(static fn(array $section): string => (string) ($section['section_code'] ?? ''), $sections);
    if (!in_array($selectedSectionCode, $sectionCodes, true)) {
        $selectedSectionCode = '';
    }
}

$kpi = [
    'active_now' => 0,
    'logged_today' => 0,
    'completed_8h' => 0,
    'sections_monitored' => 0,
];
$sectionStatuses = [];
$weeklyRows = [];

if ($currentSemesterId > 0 && $practicumStarted) {
    $kpiStmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN ar.student_user_id END) AS active_now,
            COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL THEN ar.student_user_id END) AS logged_today,
            COUNT(DISTINCT CASE WHEN {$liveHoursSql} >= 8 THEN ar.student_user_id END) AS completed_8h,
                (
                    SELECT COUNT(DISTINCT g.id)
                    FROM geofence_locations g
                    JOIN student_geofence_assignments sga
                      ON sga.geofence_id = g.id
                     AND sga.assignment_status = 'active'
                    JOIN users u ON u.id = sga.student_user_id
                    JOIN roles r ON r.id = u.role_id
                    JOIN semester_students sems
                      ON sems.student_user_id = sga.student_user_id
                     AND sems.semester_id = :semester_for_sections
                     AND sems.enrollment_status = 'active'
                    WHERE sga.semester_id = :sections_assignment_semester_id
                      AND r.role_code = 'student'
                  AND u.account_status = 'active'
            ) AS sections_monitored
         FROM attendance_records ar
         WHERE ar.semester_id = :semester_id
           AND ar.attendance_date = :today_date"
    );
    $kpiStmt->execute([
        'semester_for_sections' => $currentSemesterId,
        'sections_assignment_semester_id' => $currentSemesterId,
        'semester_id' => $currentSemesterId,
        'today_date' => $todayDate,
    ]);
    $kpi = $kpiStmt->fetch() ?: $kpi;

    $sectionsStmt = $pdo->prepare(
        "SELECT
            g.id,
            g.name AS section_name,
            COUNT(DISTINCT CASE WHEN r.role_code = 'student' AND u.account_status = 'active' THEN sga.student_user_id END) AS total_students,
            COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN ar.student_user_id END) AS active_now
         FROM student_school_deployments current_deployment
         JOIN geofence_locations g
            ON g.id = current_deployment.geofence_id
         LEFT JOIN student_geofence_assignments sga
            ON sga.student_user_id = current_deployment.student_user_id
           AND sga.semester_id = current_deployment.semester_id
           AND sga.assignment_status = 'active'
         LEFT JOIN semester_students sems
            ON sems.student_user_id = sga.student_user_id
           AND sems.semester_id = :semester_id
           AND sems.enrollment_status = 'active'
         LEFT JOIN users u
            ON u.id = sga.student_user_id
         LEFT JOIN roles r
            ON r.id = u.role_id
         LEFT JOIN attendance_records ar
            ON ar.student_user_id = sga.student_user_id
           AND ar.semester_id = sems.semester_id
           AND ar.attendance_date = :today_date
         WHERE g.is_active = 1
           AND current_deployment.semester_id = :assignment_semester_id
           AND current_deployment.deployment_status = 'active'
           AND r.role_code = 'student'
           AND u.account_status = 'active'
         GROUP BY g.id, g.name
         ORDER BY g.name ASC"
    );
    $sectionsStmt->execute([
        'today_date' => $todayDate,
        'semester_id' => $currentSemesterId,
        'assignment_semester_id' => $currentSemesterId,
    ]);
    $sectionStatuses = $sectionsStmt->fetchAll();

    if ($selectedSchoolId > 0) {
        $schoolExists = false;
        foreach ($sectionStatuses as $schoolRow) {
            if ((int) $schoolRow['id'] === $selectedSchoolId) {
                $schoolExists = true;
                break;
            }
        }
        if (!$schoolExists) {
            $selectedSchoolId = 0;
        }
    }

    $weeklySchoolFilterSql = $selectedSchoolId > 0 ? ' AND g.id = :school_id' : '';
    $weeklySectionFilterSql = $hasOriginalSectionColumn && $viewBy === 'section' && $selectedSectionCode !== ''
        ? ' AND TRIM(COALESCE(up.original_section, \'\')) = :section_code'
        : '';
    $weeklyStudentFilterSql = $selectedStudentSearch !== ''
        ? ' AND (CONCAT_WS(\' \', up.first_name, up.last_name) LIKE :student_search_name OR CONCAT_WS(\' \', up.last_name, up.first_name) LIKE :student_search_name_reversed OR u.school_id LIKE :student_search_school_id OR CAST(u.id AS CHAR) LIKE :student_search_id)'
        : '';
    $weeklyStmt = $pdo->prepare(
            "SELECT
                sga.student_user_id,
                u.school_id,
                up.last_name,
                up.first_name,
                g.name AS assigned_school_name,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN {$liveHoursSql} ELSE 0 END), 0) AS mon_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN {$liveHoursSql} ELSE 0 END), 0) AS tue_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN {$liveHoursSql} ELSE 0 END), 0) AS wed_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN {$liveHoursSql} ELSE 0 END), 0) AS thu_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN {$liveHoursSql} ELSE 0 END), 0) AS fri_hours,
                COALESCE(SUM({$liveHoursSql}), 0) AS total_hours,
                COALESCE(semester_totals.public_hours, 0) AS public_hours,
                COALESCE(semester_totals.private_hours, 0) AS private_hours,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN ar.time_in ELSE NULL END) AS mon_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN ar.time_in ELSE NULL END) AS tue_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN ar.time_in ELSE NULL END) AS wed_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN ar.time_in ELSE NULL END) AS thu_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.time_in ELSE NULL END) AS fri_time_in,
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
                MAX(CASE WHEN ar.attendance_date = :today_date_status_time_in THEN ar.time_in ELSE NULL END) AS today_time_in,
                MAX(CASE WHEN ar.attendance_date = :today_date_status_time_out THEN ar.time_out ELSE NULL END) AS today_time_out,
                MAX(CASE WHEN ar.attendance_date = :today_date_status_override THEN COALESCE(ar.is_override, 0) ELSE 0 END) AS today_is_override,
                MAX(CASE WHEN ar.attendance_date = :today_date_status_geo AND ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN COALESCE(ar.geofence_inside, 0) ELSE 1 END) AS today_geofence_inside,
                COALESCE(MAX(CASE WHEN ar.attendance_date = :today_date_status_hours THEN {$liveHoursSql} ELSE 0 END), 0) AS today_hours
             FROM student_school_deployments current_deployment
             JOIN geofence_locations g
               ON g.id = current_deployment.geofence_id
             JOIN student_geofence_assignments sga
               ON sga.student_user_id = current_deployment.student_user_id
              AND sga.semester_id = current_deployment.semester_id
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
             LEFT JOIN ojt_types ot ON ot.id = ar.ojt_type_id
             LEFT JOIN (
                SELECT semester_ar.student_user_id,
                       COALESCE(SUM(CASE WHEN semester_ot.ojt_code = 'public_school' THEN {$semesterLiveHoursSql} ELSE 0 END), 0) AS public_hours,
                       COALESCE(SUM(CASE WHEN semester_ot.ojt_code = 'private_school' THEN {$semesterLiveHoursSql} ELSE 0 END), 0) AS private_hours
                FROM attendance_records semester_ar
                LEFT JOIN ojt_types semester_ot ON semester_ot.id = semester_ar.ojt_type_id
                WHERE semester_ar.semester_id = :semester_total_id
                GROUP BY semester_ar.student_user_id
             ) semester_totals ON semester_totals.student_user_id = sga.student_user_id
             WHERE g.is_active = 1
               AND current_deployment.semester_id = :weekly_assignment_semester_id
               AND current_deployment.deployment_status = 'active'
               AND u.account_status = 'active'
               {$weeklySchoolFilterSql}
               {$weeklySectionFilterSql}
               {$weeklyStudentFilterSql}
             GROUP BY sga.student_user_id, u.school_id, up.last_name, up.first_name, g.name, semester_totals.public_hours, semester_totals.private_hours
             ORDER BY up.last_name, up.first_name"
    );
    $weeklyParams = [
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'today_date_status_time_in' => $todayDate,
        'today_date_status_time_out' => $todayDate,
        'today_date_status_override' => $todayDate,
        'today_date_status_geo' => $todayDate,
        'today_date_status_hours' => $todayDate,
        'semester_id' => $currentSemesterId,
            'weekly_assignment_semester_id' => $currentSemesterId,
            'semester_total_id' => $currentSemesterId,
    ];
    if ($selectedSchoolId > 0) {
        $weeklyParams['school_id'] = $selectedSchoolId;
    }
    if ($weeklySectionFilterSql !== '') {
        $weeklyParams['section_code'] = $selectedSectionCode;
    }
    if ($weeklyStudentFilterSql !== '') {
        $studentSearchLike = '%' . $selectedStudentSearch . '%';
        $weeklyParams['student_search_name'] = $studentSearchLike;
        $weeklyParams['student_search_name_reversed'] = $studentSearchLike;
        $weeklyParams['student_search_school_id'] = $studentSearchLike;
        $weeklyParams['student_search_id'] = $studentSearchLike;
    }
    $weeklyStmt->execute($weeklyParams);
        $weeklyRows = $weeklyStmt->fetchAll();
        if ($selectedStatus !== '') {
            $weeklyRows = array_values(array_filter(
                $weeklyRows,
                static fn(array $row): bool => headTeacherAttendanceStatusLabel($row, $weekStart, $todayDate) === $selectedStatus
            ));
        }
}

renderHeadTeacherPortalStart($user ?? [], 'attendance');
if ($currentSemester === null || (string) ($currentSemester['semester_status'] ?? '') !== 'active') {
    ?>
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
        <h2 class="text-xl font-semibold text-slate-900">No active semester available.</h2>
        <p class="mt-2 text-sm text-slate-600">Attendance Intelligence will be available after a semester is activated.</p>
    </section>
    <?php
    renderHeadTeacherPortalEnd();
    exit;
}
?>
<section class="mt-6 space-y-6">
    <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Live Practicum Monitoring</h2>
            <p class="mt-1 text-sm text-slate-600">Real-time attendance tracking across all geofenced locations.</p>
            <span class="mt-3 inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">LIVE - <?= (int) $kpi['active_now'] ?> online</span>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Today</p>
                <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($todayLabel) ?></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current Time</p>
                <p id="live-time" class="mt-1 text-sm font-semibold text-slate-900">00:00:00</p>
            </div>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Active Students Now</p>
            <p id="kpiActiveNow" class="mt-2 text-3xl font-bold text-slate-900"><?= (int) $kpi['active_now'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Logged In Today</p>
            <p id="kpiLoggedToday" class="mt-2 text-3xl font-bold text-slate-900"><?= (int) $kpi['logged_today'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Completed 8 Hours</p>
            <p id="kpiCompleted8" class="mt-2 text-3xl font-bold text-slate-900"><?= (int) $kpi['completed_8h'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Assigned Schools Monitored</p>
            <p id="kpiSectionsMonitored" class="mt-2 text-3xl font-bold text-slate-900"><?= (int) $kpi['sections_monitored'] ?></p>
        </article>
    </div>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <form method="get" class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end">
            <div id="viewByWrap" class="flex-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">View By</label>
                <select id="viewBy" name="view_by" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                    <option value="partnered_school" <?= $viewBy === 'partnered_school' ? 'selected' : '' ?>>Partnered School</option>
                    <option value="section" <?= $viewBy === 'section' ? 'selected' : '' ?>>Section</option>
                </select>
            </div>
            <div id="partneredSchoolWrap" class="flex-1 <?= $viewBy === 'section' ? 'hidden' : '' ?>">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Partnered School</label>
                <select name="school_id" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                    <option value="0" <?= $selectedSchoolId === 0 ? 'selected' : '' ?>>All Schools</option>
                    <?php foreach ($sectionStatuses as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= (int) $section['id'] === $selectedSchoolId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $section['section_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="sectionWrap" class="flex-1 <?= $viewBy === 'section' ? '' : 'hidden' ?>">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Section</label>
                <select name="section_code" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $section): ?>
                        <?php $sectionCode = (string) ($section['section_code'] ?? ''); ?>
                        <option value="<?= htmlspecialchars($sectionCode) ?>" <?= $sectionCode === $selectedSectionCode ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sectionCode) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="studentSearch">Search Student</label>
                <input id="studentSearch" type="search" name="student_search" value="<?= htmlspecialchars($selectedStudentSearch) ?>" placeholder="Name or ID" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 placeholder:text-slate-400 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
            </div>
            <div class="flex-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
                <select name="status" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                    <option value="">All Statuses</option>
                    <?php foreach (['PRESENT', 'INCA', 'ABSENT', 'HOLIDAY'] as $statusOption): ?>
                        <option value="<?= htmlspecialchars($statusOption) ?>" <?= $selectedStatus === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars($statusOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Week</label>
                <select name="week_no" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                    <?php foreach ($weekOptions as $week): ?>
                        <option value="<?= (int) $week['index'] ?>" <?= (int) $week['index'] === $selectedWeekNo ? 'selected' : '' ?>>
                            <?= htmlspecialchars(practicumWeekFilterLabel($week)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 lg:w-auto">Apply Filters</button>
            </div>
        </form>

        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            <p class="font-semibold">Attendance Policy Reminder</p>
            <p class="mt-1">Daily attendance is credited up to <span class="font-semibold">8 hours/day</span>. Weekly maximum is <span class="font-semibold">40 hours</span> (Monday-Friday only).</p>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-5">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Live Student Location Monitoring</h3>
                <p class="mt-1 text-sm text-slate-500">Monitor currently tracked practicum students. Updates every 20 seconds.</p>
            </div>
            <div class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end">
                <div>
                    <label for="locationSchoolFilter" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">School</label>
                    <select id="locationSchoolFilter" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-medium text-slate-800 outline-none transition focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        <option value="0">All Schools</option>
                    </select>
                </div>
                <div>
                    <label for="locationDisplayFilter" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">Display</label>
                    <select id="locationDisplayFilter" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-medium text-slate-800 outline-none transition focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        <option value="all">All Students</option>
                        <option value="timed_in">Timed In Students</option>
                        <option value="violations">Violations Only</option>
                    </select>
                </div>
                <label class="flex min-h-[42px] items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 xl:mb-0"><input id="locationViolationsOnly" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500"> Show Violations Only</label>
            </div>
        </div>
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-emerald-50 p-3"><p class="text-xs font-semibold text-emerald-700">STUDENTS TIMED IN NOW</p><p id="mapTimedIn" class="mt-1 text-2xl font-bold text-emerald-950">0</p></div>
            <div class="rounded-xl bg-rose-50 p-3"><p class="text-xs font-semibold text-rose-700">OUTSIDE GEOFENCE</p><p id="mapOutside" class="mt-1 text-2xl font-bold text-rose-950">0</p></div>
            <div class="rounded-xl bg-sky-50 p-3"><p class="text-xs font-semibold text-sky-700">ACTIVE SCHOOLS</p><p id="mapSchools" class="mt-1 text-2xl font-bold text-sky-950">0</p></div>
            <div class="rounded-xl bg-slate-100 p-3"><p class="text-xs font-semibold text-slate-600">GPS DEVICES REPORTING</p><p id="mapGps" class="mt-1 text-2xl font-bold text-slate-950">0</p></div>
        </div>
        <div class="relative">
            <div id="liveLocationMap" class="h-[420px] overflow-hidden rounded-xl border border-slate-200 bg-slate-100" aria-label="Live student location monitoring map"></div>
            <div id="liveLocationEmpty" class="pointer-events-none absolute inset-0 hidden items-center justify-center p-6">
                <div class="max-w-sm rounded-xl border border-slate-200 bg-white/95 px-5 py-4 text-center text-sm font-medium text-slate-600 shadow-lg backdrop-blur">No students currently match the selected filters.</div>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="text-lg font-semibold text-slate-900">Weekly Attendance Analytics</h3>
            <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <span><?= htmlspecialchars($selectedWeekLabel) ?>, <?= htmlspecialchars($weekStart) ?> to <?= htmlspecialchars($weekEnd) ?></span>
                <?= $selectedWeekBadge ?>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[760px] w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                <tr class="text-xs uppercase tracking-wide text-slate-500">
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Student</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Mon</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Tue</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Wed</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Thu</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Fri</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Total</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Status</th>
                </tr>
                </thead>
                <tbody id="headTeacherWeeklyRows">
                <?php foreach ($weeklyRows as $row): ?>
                    <?php
                    $publicHours = (float) ($row['public_hours'] ?? 0);
                    $privateHours = (float) ($row['private_hours'] ?? 0);
                    $weekDates = headTeacherWeekDates($weekStart);
                    ?>
                    <tr class="transition hover:bg-slate-50">
                        <td class="border-b border-slate-100 px-4 py-3 font-medium">
                            <?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) $row['school_id']) ?></div>
                        </td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= headTeacherDayBadge($row, 'mon', $weekDates['mon'], $todayDate) ?></td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= headTeacherDayBadge($row, 'tue', $weekDates['tue'], $todayDate) ?></td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= headTeacherDayBadge($row, 'wed', $weekDates['wed'], $todayDate) ?></td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= headTeacherDayBadge($row, 'thu', $weekDates['thu'], $todayDate) ?></td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= headTeacherDayBadge($row, 'fri', $weekDates['fri'], $todayDate) ?></td>
                        <td class="border-b border-slate-100 px-4 py-3 font-semibold"><div>Pub: <?= practicumFormatHours($publicHours) ?></div><div>Priv: <?= practicumFormatHours($privateHours) ?></div></td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= headTeacherAttendanceStatusBadge($row, $weekStart, $todayDate) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$weeklyRows): ?>
                    <tr>
                        <td colspan="8" class="border-b border-slate-100 px-4 py-6 text-center text-sm text-slate-500">
                            <?= $practicumStarted ? 'No weekly attendance data yet.' : 'No attendance data yet. Practicum has not started.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php
$headTeacherApiUrl = json_encode(appUrl('api/head_teacher_live_attendance.php') . '?' . http_build_query([
    'school_id' => $selectedSchoolId,
    'view_by' => $viewBy,
    'section_code' => $selectedSectionCode,
    'student_search' => $selectedStudentSearch,
    'week_start' => $weekStart,
    'week_no' => $selectedWeekNo,
    'status' => $selectedStatus,
]));
$headTeacherHolidayDatesJson = json_encode(array_keys($headTeacherHolidayDates), JSON_THROW_ON_ERROR);
$headTeacherLocationApiUrl = json_encode(appUrl('api/head_teacher_live_locations.php'));
$selectedStatusJson = json_encode($selectedStatus);
$extraScript = <<<'HTML'
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
const viewBySelect = document.getElementById("viewBy");
const partneredSchoolWrap = document.getElementById("partneredSchoolWrap");
const sectionWrap = document.getElementById("sectionWrap");
const schoolSelect = document.querySelector('select[name="school_id"]');
const sectionSelect = document.querySelector('select[name="section_code"]');
function syncAttendanceViewBy() {
  const bySection = viewBySelect && viewBySelect.value === "section";
  partneredSchoolWrap?.classList.toggle("hidden", bySection);
  sectionWrap?.classList.toggle("hidden", !bySection);
}
viewBySelect?.addEventListener("change", () => {
  if (viewBySelect.value === "section" && schoolSelect) schoolSelect.value = "0";
  if (viewBySelect.value === "partnered_school" && sectionSelect) sectionSelect.value = "";
  syncAttendanceViewBy();
});
syncAttendanceViewBy();

(() => {
  const endpoint = __LOCATION_API_URL__;
  const mapNode = document.getElementById('liveLocationMap');
  const violationsInput = document.getElementById('locationViolationsOnly');
  const displayFilter = document.getElementById('locationDisplayFilter');
  if (!mapNode || !window.L) return;
  const schoolFilter = document.getElementById('locationSchoolFilter');
  const campusCenter = [8.4795, 124.6473];
  const map = L.map(mapNode, {scrollWheelZoom: false}).setView(campusCenter, 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'}).addTo(map);
  const layers = L.layerGroup().addTo(map);
  const counters = {timed_in: document.getElementById('mapTimedIn'), outside: document.getElementById('mapOutside'), active_schools: document.getElementById('mapSchools'), gps_reporting: document.getElementById('mapGps')};
  const empty = document.getElementById('liveLocationEmpty');
  const knownSchools = new Map();
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const markerColor = {inside: '#059669', near_boundary: '#d97706', outside: '#dc2626', gps_stale: '#64748b'};
  function updateSchoolOptions(schools) {
    (schools || []).forEach((school) => knownSchools.set(String(school.id), String(school.name || 'School')));
    if (!schoolFilter) return;
    const selected = schoolFilter.value || '0';
    schoolFilter.innerHTML = '<option value="0">All Schools</option>' + Array.from(knownSchools.entries())
      .sort((a, b) => a[1].localeCompare(b[1]))
      .map(([id, name]) => `<option value="${escapeHtml(id)}">${escapeHtml(name)}</option>`).join('');
    schoolFilter.value = knownSchools.has(selected) || selected === '0' ? selected : '0';
  }
  function isViolation(student) {
    return ['outside', 'gps_stale'].includes(String(student.status || ''));
  }
  async function refreshLocations(zoomToSchool = false) {
    try {
      const url = new URL(endpoint, window.location.origin);
      if (schoolFilter?.value && schoolFilter.value !== '0') url.searchParams.set('school_id', schoolFilter.value);
      const violationsOnly = Boolean(violationsInput?.checked) || displayFilter?.value === 'violations';
      if (violationsOnly) url.searchParams.set('violations_only', '1');
      const response = await fetch(url, {cache: 'no-store', headers: {Accept: 'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.success) return;
      layers.clearLayers();
      updateSchoolOptions(data.schools || []);
      const points = [];
      (data.schools || []).forEach(school => {
        const center = [Number(school.latitude), Number(school.longitude)];
        points.push(center);
        L.circle(center, {radius: Number(school.radius_meters), color: '#059669', weight: 2, fillColor: '#10b981', fillOpacity: .10}).bindTooltip(escapeHtml(school.name), {sticky: true}).addTo(layers);
      });
      let displayedStudents = data.students || [];
      if (violationsOnly) displayedStudents = displayedStudents.filter(isViolation);
      if (displayFilter?.value === 'timed_in') displayedStudents = displayedStudents.filter(student => Boolean(student.time_in));
      displayedStudents.forEach(student => {
        const lat = Number(student.latitude ?? student.school_latitude), lng = Number(student.longitude ?? student.school_longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        points.push([lat, lng]);
        const color = markerColor[student.status] || markerColor.gps_stale;
        const icon = L.divIcon({className: '', html: `<span style="display:block;width:15px;height:15px;border-radius:50%;background:${color};border:3px solid white;box-shadow:0 1px 5px rgba(15,23,42,.45)"></span>`, iconSize:[21,21], iconAnchor:[10,10]});
        const popup = `<div class="text-sm"><strong>${escapeHtml(student.last_name)}, ${escapeHtml(student.first_name)}</strong><br>ID: ${escapeHtml(student.school_id)}<br>School: ${escapeHtml(student.assigned_school)}<br>Time in: ${escapeHtml(student.time_in)}<br>Hours worked: ${Number(student.hours_worked || 0).toFixed(2)}<br>GPS accuracy: Not reported<br>Status: ${escapeHtml(String(student.status).replace('_', ' '))}</div>`;
        L.marker([lat, lng], {icon}).bindPopup(popup).addTo(layers);
      });
      const reported = displayedStudents.filter(student => String(student.status || '') !== 'gps_stale');
      const activeSchools = new Set(displayedStudents.map(student => String(student.assigned_school_id || '')));
      Object.entries({timed_in: displayedStudents.length, outside: displayedStudents.filter(student => String(student.status || '') === 'outside').length, active_schools: activeSchools.size, gps_reporting: reported.length}).forEach(([key, value]) => {if (counters[key]) counters[key].textContent = String(value);});
      if (empty) {
        empty.classList.toggle('hidden', displayedStudents.length > 0);
        empty.classList.toggle('flex', displayedStudents.length === 0);
      }
      if (points.length) map.fitBounds(points, {padding:[24,24], maxZoom: 16});
      else if (zoomToSchool || displayedStudents.length === 0) map.setView(campusCenter, 13);
    } catch (_) {}
  }
  schoolFilter?.addEventListener('change', () => refreshLocations(true));
  displayFilter?.addEventListener('change', () => refreshLocations(true));
  violationsInput?.addEventListener('change', () => refreshLocations(true));
  refreshLocations(true);
  setInterval(() => refreshLocations(false), 20000);
})();

const clockElement=document.getElementById("live-time");
function updateClock(){const now=new Date();clockElement.textContent=now.toLocaleTimeString("en-US",{hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:true});}
updateClock();
setInterval(updateClock,1000);

(() => {
  const apiUrl = __HEAD_TEACHER_API_URL__;
  const kpiActiveNow = document.getElementById("kpiActiveNow");
  const kpiLoggedToday = document.getElementById("kpiLoggedToday");
  const kpiCompleted8 = document.getElementById("kpiCompleted8");
  const kpiSectionsMonitored = document.getElementById("kpiSectionsMonitored");
  const rowsEl = document.getElementById("headTeacherWeeklyRows");
  if (!rowsEl) return;

  const weekStart = "__WEEK_START__";
  const todayDate = "__TODAY_DATE__";
  let holidayDates = new Set(__HOLIDAYS__ || []);

  function weekDates() {
    const [year, month, day] = weekStart.split("-").map(Number);
    const monday = new Date(year, month - 1, day);
    const names = ["mon", "tue", "wed", "thu", "fri"];
    return names.map((name, index) => {
      const date = new Date(monday);
      date.setDate(monday.getDate() + index);
      return { name, value: [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, "0"),
        String(date.getDate()).padStart(2, "0")
      ].join("-") };
    });
  }

  const days = weekDates();

  function currentTimeValue() {
    const now = new Date();
    return `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}:${String(now.getSeconds()).padStart(2, "0")}`;
  }

  function badge(label, tone) {
    const tones = {
      green: "bg-emerald-100 text-emerald-700",
      amber: "bg-amber-100 text-amber-700",
      yellow: "bg-yellow-100 text-yellow-800",
      orange: "bg-orange-100 text-orange-700",
      rose: "bg-rose-100 text-rose-700",
      slate: "bg-slate-100 text-slate-600"
    };
    return `<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${tones[tone] || tones.slate}">${label}</span>`;
  }

  function dayState(row, day, dateValue) {
    if (holidayDates.has(dateValue)) return "holiday";
    const hours = Number(row[`${day}_hours`] || 0);
    const hasTimeIn = Number(row[`${day}_has_time_in`] || 0) === 1;
    const hasTimeOut = Number(row[`${day}_has_time_out`] || 0) === 1;
    const timeIn = String(row[`${day}_time_in`] || "");
    const isLate = timeIn.length >= 19 && timeIn.slice(11, 19) >= "08:30:00";
    if (hasTimeIn && !hasTimeOut) {
      if (dateValue === todayDate && Number(row.today_geofence_inside ?? 1) !== 1) return "outside_geofence";
      if (dateValue < todayDate) return "pending_timeout";
      return isLate ? "ongoing_late" : "ongoing";
    }
    if ((hasTimeIn && hasTimeOut) || hours > 0) return isLate ? "completed_late" : "completed";
    if (dateValue === todayDate) return currentTimeValue() >= "06:00:00" ? "absent" : "future";
    return dateValue > todayDate ? "future" : "absent";
  }

  function formatHours(hours) {
    const totalMinutes = Math.max(0, Math.floor((Number(hours || 0) * 60) + 0.00001));
    const wholeHours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${wholeHours}.${String(minutes).padStart(2, "0")}`;
  }

  function dayBadge(row, day, dateValue) {
    const state = dayState(row, day, dateValue);
    const hours = Number(row[`${day}_hours`] || 0);
    if (state === "holiday") return badge("H - Holiday", "slate");
    if (state === "completed") return badge(`✓ ${formatHours(hours)}`, "green");
    if (state === "completed_late") return badge("✓ " + formatHours(hours), "yellow");
    if (state === "ongoing") return badge(formatHours(hours), "amber");
    if (state === "ongoing_late") return badge(formatHours(hours), "yellow");
    if (state === "outside_geofence") return badge(formatHours(hours), "rose");
    if (state === "pending_timeout") return badge("Pending<br>Timeout", "orange");
    if (state === "future") return badge("Upcoming", "slate");
    return badge("✖", "rose");
  }

  function statusLabel(row) {
    if (holidayDates.has(todayDate)) return "HOLIDAY";
    // API status is generated by AttendanceStatusService. Never let weekly
    // aggregate heuristics override a current attendance session.
    if (row.attendance_status) return String(row.attendance_status);
    if (Number(row.is_live || 0) === 1 || (row.today_time_in && !row.today_time_out)) return "INCA";
    if (row.today_time_in && row.today_time_out) return "PRESENT";
    let completedDays = 0;
    let presentDays = 0;
    let absentDays = 0;
    let futureDays = 0;
    let todayState = null;

    days.forEach(({ name, value }) => {
      const state = dayState(row, name, value);
      if (value === todayDate) todayState = state;
      if (state === "completed" || state === "completed_late") {
        completedDays += 1;
        presentDays += 1;
      } else if (state === "ongoing" || state === "ongoing_late" || state === "pending_timeout") {
        presentDays += 1;
      } else if (state === "future") {
        futureDays += 1;
      } else {
        absentDays += 1;
      }
    });

    if (completedDays >= 5) return "Completed";
    if (futureDays === 5 || (todayState === "future" && currentTimeValue() < "06:00:00")) {
      return "Upcoming";
    }
    if (todayState === "ongoing_late") return "Ongoing late";
    if (todayState === "ongoing") return "Ongoing";
    if (absentDays >= 5 || presentDays > 0) return "Critical";
    return "Absent";
  }

  function statusBadge(row) {
    const label = statusLabel(row);
    if (label === "HOLIDAY") return badge("H - Holiday", "slate");
    if (label === "INCA") return badge(label, "amber");
    if (label === "PRESENT") return badge(label, "green");
    if (label === "Completed") return badge(label, "green");
    if (label === "Critical") return badge(label, "rose");
    if (label === "Absent") return badge(label, "rose");
    if (label === "Ongoing late") return badge(label, "yellow");
    if (label === "Ongoing") return badge(label, "amber");
    return badge(label, "slate");
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      "\"": "&quot;",
      "'": "&#039;"
    }[char]));
  }

  function renderRows(rows) {
    rows = rows.filter((row) => !__STATUS__ || statusLabel(row) === __STATUS__);
    if (!Array.isArray(rows) || rows.length === 0) {
      rowsEl.innerHTML = "<tr><td colspan=\"8\" class=\"border-b border-slate-100 px-4 py-6 text-center text-sm text-slate-500\">No weekly attendance data yet.</td></tr>";
      return;
    }

    rowsEl.innerHTML = rows.map((row) => {
      const publicHours = Number(row.public_hours || 0);
      const privateHours = Number(row.private_hours || 0);
      const livePill = Number(row.is_live || 0) === 1
        ? "<span class=\"ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700\">LIVE</span>"
        : "";
      return `<tr class="transition hover:bg-slate-50">
          <td class="border-b border-slate-100 px-4 py-3 font-medium">
            ${escapeHtml(row.last_name || "")}, ${escapeHtml(row.first_name || "")}${livePill}
            <div class="text-xs text-slate-500">${escapeHtml(row.school_id || "")}</div>
          </td>
          <td class="border-b border-slate-100 px-4 py-3">${dayBadge(row, "mon", days[0].value)}</td>
          <td class="border-b border-slate-100 px-4 py-3">${dayBadge(row, "tue", days[1].value)}</td>
          <td class="border-b border-slate-100 px-4 py-3">${dayBadge(row, "wed", days[2].value)}</td>
          <td class="border-b border-slate-100 px-4 py-3">${dayBadge(row, "thu", days[3].value)}</td>
          <td class="border-b border-slate-100 px-4 py-3">${dayBadge(row, "fri", days[4].value)}</td>
          <td class="border-b border-slate-100 px-4 py-3 font-semibold"><div>Pub: ${formatHours(publicHours)}</div><div>Priv: ${formatHours(privateHours)}</div></td>
          <td class="border-b border-slate-100 px-4 py-3">${statusBadge(row)}</td>
        </tr>`;
    }).join("");
  }

  async function refresh() {
    try {
      const res = await fetch(apiUrl, { headers: { Accept: "application/json" } });
      if (!res.ok) return;
      const data = await res.json();
      if (data.error) return;
      holidayDates = new Set(Object.keys(data.holidays || {}));
      const kpi = data.kpi || {};
      if (kpiActiveNow) kpiActiveNow.textContent = Number(kpi.active_now || 0);
      if (kpiLoggedToday) kpiLoggedToday.textContent = Number(kpi.logged_today || 0);
      if (kpiCompleted8) kpiCompleted8.textContent = Number(kpi.completed_8h || 0);
      if (kpiSectionsMonitored) kpiSectionsMonitored.textContent = Number(kpi.sections_monitored || 0);
      renderRows(data.weekly_rows || []);
    } catch (_err) {
    }
  }

  refresh();
  setInterval(refresh, 5000);
})();
</script>
HTML;
$extraScript = $practicumStarted ? str_replace(
    ['__HEAD_TEACHER_API_URL__', '__LOCATION_API_URL__', '__WEEK_START__', '__TODAY_DATE__', '__STATUS__', '__HOLIDAYS__'],
    [$headTeacherApiUrl, $headTeacherLocationApiUrl, $weekStart, $todayDate, $selectedStatusJson, $headTeacherHolidayDatesJson],
    $extraScript
) : '';
renderHeadTeacherPortalEnd($extraScript);
