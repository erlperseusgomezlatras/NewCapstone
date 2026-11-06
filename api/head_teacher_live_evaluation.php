<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/AttendanceCalculation.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';

header('Content-Type: application/json; charset=utf-8');

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

try {
    $pdo = getPDO();
    new SystemHolidayService($pdo);
    $liveHoursSql = AttendanceCalculation::renderedHoursSql('ar');

    $semesterStmt = $pdo->query(
        "SELECT sem.id, sem.practicum_start_date, sem.practicum_status
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
    $semester = $semesterStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $semesterId = $semester !== null ? (int) ($semester['id'] ?? 0) : 0;

    $weekStart = isset($_GET['week_start']) ? (new DateTimeImmutable((string) $_GET['week_start']))->modify('monday this week')->format('Y-m-d') : (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
    $selectedSectionId = isset($_GET['school_id'])
        ? (int) $_GET['school_id']
        : (isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0);
    $viewBy = strtolower(trim((string) ($_GET['view_by'] ?? 'partnered_school')));
    $selectedSectionCode = $viewBy === 'section' ? trim((string) ($_GET['section_code'] ?? '')) : '';
    $search = trim((string) ($_GET['search'] ?? ''));
    $pageSize = max(1, (int) ($_GET['page_size'] ?? 10));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $pageSize;

    $payload = [
        'summary' => [
            'total_students' => 0,
            'journal_submitted' => 0,
            'checklist_done' => 0,
            'avg_score' => 0.0,
        ],
        'rows' => [],
    ];
    $activeChecklistCount = 0;
    $hasDynamicJournalSubmissions = tableExists($pdo, 'journal_submissions');
    $hasStudentJournals = tableExists($pdo, 'student_journals');
    $hasChecklistConfirmations = tableExists($pdo, 'checklist_student_confirmations');
    $confirmationSelect = $hasChecklistConfirmations ? 'csc.confirmed_at AS checklist_confirmed_at,' : 'NULL AS checklist_confirmed_at,';
    $confirmationJoin = $hasChecklistConfirmations ? "LEFT JOIN checklist_student_confirmations csc
               ON csc.student_user_id = sga.student_user_id
              AND csc.geofence_id = g.id
              AND csc.semester_id = sems.semester_id
              AND csc.week_start = :checklist_confirmation_week_start" : '';
    $hasOriginalSectionColumn = columnExists($pdo, 'user_profiles', 'original_section');
    $schoolFilterSql = $selectedSectionId > 0 ? 'AND g.id = :section_id' : '';
    $sectionFilterSql = $hasOriginalSectionColumn && $selectedSectionCode !== ''
        ? "AND TRIM(COALESCE(up.original_section, '')) = :section_code"
        : '';
    $evaluationFilterSql = $schoolFilterSql . ' ' . $sectionFilterSql;
    $searchFilterSql = $search !== ''
        ? " AND CONCAT_WS(' ', up.first_name, up.last_name) LIKE :student_search"
        : '';
    $evaluationFilterSql .= $searchFilterSql;

    if ($semesterId > 0) {
        if (!practicumCanRun((string) ($semester['practicum_start_date'] ?? ''), null, (string) ($semester['practicum_status'] ?? ''))) {
            echo json_encode($payload);
            exit;
        }

        $activeChecklistStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM evaluation_checklist_items
             WHERE is_active = 1
               AND (semester_id = :semester_id OR semester_id IS NULL)"
        );
        $activeChecklistStmt->execute(['semester_id' => $semesterId]);
        $activeChecklistCount = (int) $activeChecklistStmt->fetchColumn();

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
               AND sga.semester_id = :count_assignment_semester_id
               AND r.role_code = 'student'
               AND u.account_status = 'active'"
        );
        $countParams = [
            'semester_id' => $semesterId,
            'count_assignment_semester_id' => $semesterId,
        ];
        if ($selectedSectionId > 0) {
            $countParams['section_id'] = $selectedSectionId;
        }
        if ($sectionFilterSql !== '') {
            $countParams['section_code'] = $selectedSectionCode;
        }
        if ($searchFilterSql !== '') {
            $countParams['student_search'] = '%' . $search . '%';
        }
        $countStmt->execute($countParams);
        $payload['summary']['total_students'] = (int) $countStmt->fetchColumn();

        if ($hasDynamicJournalSubmissions) {
            $journalSelect = "js.id AS journal_id,
                js.status AS journal_status,";
            $journalJoin = "LEFT JOIN journal_submissions js
               ON js.student_user_id = sga.student_user_id
              AND js.semester_id = sems.semester_id
              AND js.week_start = :journal_week_start
              AND COALESCE(js.is_reopened, 0) = 0";
        } elseif ($hasStudentJournals) {
            $journalSelect = "sj.id AS journal_id,
                sj.status AS journal_status,";
            $journalJoin = "LEFT JOIN student_journals sj
               ON sj.student_user_id = sga.student_user_id
              AND sj.semester_id = sems.semester_id
              AND sj.week_start = :journal_week_start";
        } else {
            $journalSelect = "NULL AS journal_id,
                NULL AS journal_status,";
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
                {$confirmationSelect}
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
                COALESCE(cw.total_score, 0) AS checklist_total_score
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
             {$confirmationJoin}
             LEFT JOIN attendance_records ar
               ON ar.student_user_id = sga.student_user_id
              AND ar.semester_id = sems.semester_id
              AND ar.attendance_date BETWEEN :week_start AND :week_end
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
                sga.student_user_id, u.school_id, u.email, up.first_name, up.last_name, g.name, journal_id, journal_status, cw.checked_items, cw.total_score, checklist_confirmed_at
             ORDER BY up.last_name ASC, up.first_name ASC
             LIMIT :limit OFFSET :offset"
        );
        $rowsStmt->bindValue(':semester_id', $semesterId, PDO::PARAM_INT);
        $rowsStmt->bindValue(':rows_assignment_semester_id', $semesterId, PDO::PARAM_INT);
        $rowsStmt->bindValue(':week_start', $weekStart);
        $rowsStmt->bindValue(':week_end', $weekEnd);
        $rowsStmt->bindValue(':today_date_geo', date('Y-m-d'));
        $rowsStmt->bindValue(':row_week_start', $weekStart);
        if ($hasDynamicJournalSubmissions || $hasStudentJournals) {
            $rowsStmt->bindValue(':journal_week_start', $weekStart);
        }
        $rowsStmt->bindValue(':checklist_week_start', $weekStart);
        $rowsStmt->bindValue(':checklist_semester_id', $semesterId, PDO::PARAM_INT);
        $rowsStmt->bindValue(':checklist_item_semester_id', $semesterId, PDO::PARAM_INT);
        if ($hasChecklistConfirmations) {
            $rowsStmt->bindValue(':checklist_confirmation_week_start', $weekStart);
        }
            if ($selectedSectionId > 0) {
                $rowsStmt->bindValue(':section_id', $selectedSectionId, PDO::PARAM_INT);
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
        $payload['rows'] = $rowsStmt->fetchAll();

        if ($hasDynamicJournalSubmissions) {
            $summaryJournalJoin = "LEFT JOIN journal_submissions js
             ON js.student_user_id = sga.student_user_id
             AND js.semester_id = sems.semester_id
             AND js.week_start = :summary_journal_week_start
             AND COALESCE(js.is_reopened, 0) = 0";
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
    $summaryConfirmationJoin = $hasChecklistConfirmations ? "LEFT JOIN checklist_student_confirmations csc
                  ON csc.student_user_id = sga.student_user_id
                 AND csc.geofence_id = g.id
                 AND csc.semester_id = sems.semester_id
                 AND csc.week_start = :summary_checklist_confirmation_week_start" : '';
    $summaryConfirmationExpr = $hasChecklistConfirmations
        ? 'MAX(CASE WHEN csc.confirmed_at IS NOT NULL THEN 1 ELSE 0 END)'
        : '0';

        $summaryStmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN t.has_journal = 1 THEN 1 ELSE 0 END) AS journal_submitted,
                SUM(
                    CASE
                        WHEN :active_checklist_count_zero = 0 THEN 0
                        WHEN t.checklist_confirmed = 1 OR t.checked_items >= :active_checklist_count_complete THEN 1
                        ELSE 0
                    END
                ) AS checklist_done,
                AVG(t.total_score) AS avg_score
             FROM (
                SELECT
                    sga.student_user_id,
                {$summaryJournalExpr} AS has_journal,
                COALESCE(cw.checked_items, 0) AS checked_items,
                COALESCE(cw.total_score, 0) AS total_score,
                {$summaryConfirmationExpr} AS checklist_confirmed
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
                {$summaryConfirmationJoin}
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
                GROUP BY sga.student_user_id, cw.checked_items, cw.total_score, checklist_confirmed
         ) t"
        );
        $summaryStmt->bindValue(':semester_id', $semesterId, PDO::PARAM_INT);
        $summaryStmt->bindValue(':summary_assignment_semester_id', $semesterId, PDO::PARAM_INT);
        $summaryStmt->bindValue(':summary_checklist_week_start', $weekStart);
        $summaryStmt->bindValue(':summary_checklist_semester_id', $semesterId, PDO::PARAM_INT);
    $summaryStmt->bindValue(':summary_checklist_item_semester_id', $semesterId, PDO::PARAM_INT);
    if ($hasChecklistConfirmations) {
        $summaryStmt->bindValue(':summary_checklist_confirmation_week_start', $weekStart);
    }
        if ($hasDynamicJournalSubmissions || $hasStudentJournals) {
            $summaryStmt->bindValue(':summary_journal_week_start', $weekStart);
        }
        if ($selectedSectionId > 0) {
            $summaryStmt->bindValue(':section_id', $selectedSectionId, PDO::PARAM_INT);
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
        $summaryRow = $summaryStmt->fetch() ?: [];
        $payload['summary']['journal_submitted'] = (int) ($summaryRow['journal_submitted'] ?? 0);
        $payload['summary']['checklist_done'] = (int) ($summaryRow['checklist_done'] ?? 0);
        $payload['summary']['avg_score'] = (float) ($summaryRow['avg_score'] ?? 0);
    }

    echo json_encode($payload);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
