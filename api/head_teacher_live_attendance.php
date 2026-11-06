<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/AttendanceCalculation.php';
require_once __DIR__ . '/../services/AttendanceStatusService.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getPDO();
    practicumAutoActivateScheduled($pdo);
    $columnStmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name LIMIT 1');
    $columnStmt->execute(['table_name' => 'user_profiles', 'column_name' => 'original_section']);
    $hasOriginalSectionColumn = $columnStmt->fetchColumn() !== false;
    $todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $weekStart = isset($_GET['week_start'])
        ? (new DateTimeImmutable((string) $_GET['week_start']))->modify('monday this week')->format('Y-m-d')
        : (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
    $holidayDates = (new SystemHolidayService($pdo))->listBetween($weekStart, $weekEnd);
    $isTodayHoliday = isset($holidayDates[$todayDate]);
    $liveHoursSql = AttendanceCalculation::renderedHoursSql('ar');
    $semesterLiveHoursSql = AttendanceCalculation::renderedHoursSql('semester_ar', $pdo->quote($todayDate));

    $semesterStmt = $pdo->query(
        "SELECT sem.id, sem.semester_name, sem.practicum_start_date, sem.practicum_status, sy.year_label
         FROM semesters sem
         JOIN school_years sy ON sy.id = sem.school_year_id
         ORDER BY (sem.semester_status = 'active') DESC, sy.start_date DESC, sem.semester_no ASC
         LIMIT 1"
    );
    $currentSemester = $semesterStmt->fetch() ?: null;
    $currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;

    $payload = [
        'semester' => $currentSemester,
        'kpi' => [
            'active_now' => 0,
            'logged_today' => 0,
            'completed_8h' => 0,
            'sections_monitored' => 0,
        ],
        'sections' => [],
        'selected_section_id' => 0,
        'weekly_rows' => [],
        'holidays' => $holidayDates,
    ];

    if ($currentSemesterId > 0) {
        if (!practicumCanRun((string) ($currentSemester['practicum_start_date'] ?? ''), null, (string) ($currentSemester['practicum_status'] ?? ''))) {
            echo json_encode($payload);
            exit;
        }

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
        $payload['kpi'] = $kpiStmt->fetch() ?: $payload['kpi'];
        if ($isTodayHoliday) {
            $payload['kpi']['active_now'] = 0;
            $payload['kpi']['logged_today'] = 0;
            $payload['kpi']['completed_8h'] = 0;
        }

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
        $payload['sections'] = $sectionsStmt->fetchAll();

        $selectedSchoolId = isset($_GET['school_id'])
            ? (int) $_GET['school_id']
            : (isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0);
        $viewBy = strtolower(trim((string) ($_GET['view_by'] ?? 'partnered_school')));
        $selectedSectionCode = trim((string) ($_GET['section_code'] ?? ''));
        $selectedStudentSearch = trim((string) ($_GET['student_search'] ?? ''));
        if ($viewBy !== 'section') {
            $selectedSectionCode = '';
        }
        $payload['selected_section_id'] = $selectedSchoolId;

        $weeklySchoolFilterSql = $selectedSchoolId > 0 ? 'AND g.id = :school_id' : '';
        $weeklySectionFilterSql = $hasOriginalSectionColumn && $selectedSectionCode !== ''
            ? "AND TRIM(COALESCE(up.original_section, '')) = :section_code"
            : '';
        $weeklyStudentFilterSql = $selectedStudentSearch !== ''
            ? "AND (CONCAT_WS(' ', up.first_name, up.last_name) LIKE :student_search_name OR CONCAT_WS(' ', up.last_name, up.first_name) LIKE :student_search_name_reversed OR u.school_id LIKE :student_search_school_id OR CAST(u.id AS CHAR) LIKE :student_search_id)"
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
                    MAX(CASE WHEN ar.attendance_date = :today_date AND ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN 1 ELSE 0 END) AS is_live,
                    MAX(CASE WHEN ar.attendance_date = :today_date_time_in THEN ar.time_in ELSE NULL END) AS today_time_in,
                    MAX(CASE WHEN ar.attendance_date = :today_date_time_out THEN ar.time_out ELSE NULL END) AS today_time_out,
                    MAX(CASE WHEN ar.attendance_date = :today_date_override THEN COALESCE(ar.is_override, 0) ELSE 0 END) AS today_is_override,
                    MAX(CASE WHEN ar.attendance_date = :today_date_geo AND ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN COALESCE(ar.geofence_inside, 0) ELSE 1 END) AS today_geofence_inside,
                    COALESCE(MAX(CASE WHEN ar.attendance_date = :today_date_hours THEN {$liveHoursSql} ELSE 0 END), 0) AS today_hours
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
            'today_date' => $todayDate,
            'today_date_time_in' => $todayDate,
            'today_date_time_out' => $todayDate,
            'today_date_override' => $todayDate,
            'today_date_geo' => $todayDate,
            'today_date_hours' => $todayDate,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
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
        $payload['weekly_rows'] = $weeklyStmt->fetchAll();
        foreach ($payload['weekly_rows'] as &$weeklyRow) {
            $weeklyRow['attendance_status'] = AttendanceStatusService::todayStatus($weeklyRow);
        }
        unset($weeklyRow);
    }

    echo json_encode($payload);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
