<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/AttendanceCalculation.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';
require_once __DIR__ . '/../services/SemesterLifecycleIntegrityService.php';

final class HeadTeacherReportRepository
{
    public const JOURNALS_REQUIRED = 16;
    public const TARGET_HOURS = 180.0;

    public function __construct(private PDO $pdo)
    {
        new SystemHolidayService($pdo);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
             LIMIT 1'
        );
        $stmt->execute(['table_name' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table_name
               AND column_name = :column_name LIMIT 1'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);

        return $stmt->fetchColumn() !== false;
    }

    public function getDefaultSemesterContext(): ?array
    {
        if (!$this->tableExists('semesters') || !$this->tableExists('school_years')) {
            return null;
        }

        $this->repairLifecycleFlags();

        $stmt = $this->pdo->query(
            "SELECT
                sem.id AS semester_id,
                sem.school_year_id,
                sem.semester_no,
                sem.semester_name,
                sem.semester_status,
                sem.start_date AS semester_start_date,
                sem.practicum_start_date,
                sem.practicum_status,
                sem.end_date AS semester_end_date,
                sy.year_label,
                CASE
                    WHEN EXISTS (
                        SELECT 1 FROM semesters active_sem
                        WHERE active_sem.school_year_id = sy.id
                          AND active_sem.semester_status = 'active'
                    ) THEN 'active'
                    WHEN EXISTS (
                        SELECT 1 FROM semesters planned_sem
                        WHERE planned_sem.school_year_id = sy.id
                          AND planned_sem.semester_status = 'planned'
                    ) THEN 'planned'
                    ELSE 'archived'
                END AS year_status
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
                sem.semester_no ASC,
                sem.id DESC
             LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function getSemesterBySelection(int $schoolYearId, int $semesterNo): ?array
    {
        if ($schoolYearId <= 0 || $semesterNo <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                sem.id AS semester_id,
                sem.school_year_id,
                sem.semester_no,
                sem.semester_name,
                sem.semester_status,
                sem.start_date AS semester_start_date,
                sem.practicum_start_date,
                sem.practicum_status,
                sem.end_date AS semester_end_date,
                sy.year_label,
                CASE
                    WHEN EXISTS (
                        SELECT 1 FROM semesters active_sem
                        WHERE active_sem.school_year_id = sy.id
                          AND active_sem.semester_status = 'active'
                    ) THEN 'active'
                    WHEN EXISTS (
                        SELECT 1 FROM semesters planned_sem
                        WHERE planned_sem.school_year_id = sy.id
                          AND planned_sem.semester_status = 'planned'
                    ) THEN 'planned'
                    ELSE 'archived'
                END AS year_status
             FROM semesters sem
             JOIN school_years sy ON sy.id = sem.school_year_id
             WHERE sem.school_year_id = :school_year_id
               AND sem.semester_no = :semester_no
             LIMIT 1"
        );
        $stmt->execute([
            'school_year_id' => $schoolYearId,
            'semester_no' => $semesterNo,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function listSchoolYears(): array
    {
        if (!$this->tableExists('school_years') || !$this->tableExists('semesters')) {
            return [];
        }

        $this->repairLifecycleFlags();

        $stmt = $this->pdo->query(
            "SELECT
                sy.id,
                sy.year_label,
                sy.start_date,
                CASE
                    WHEN EXISTS (
                        SELECT 1 FROM semesters active_sem
                        WHERE active_sem.school_year_id = sy.id
                          AND active_sem.semester_status = 'active'
                    ) THEN 'active'
                    WHEN EXISTS (
                        SELECT 1 FROM semesters planned_sem
                        WHERE planned_sem.school_year_id = sy.id
                          AND planned_sem.semester_status = 'planned'
                    ) THEN 'planned'
                    ELSE 'archived'
                END AS year_status
             FROM school_years sy
             WHERE EXISTS (SELECT 1 FROM semesters sem WHERE sem.school_year_id = sy.id)
             ORDER BY start_date DESC, id DESC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function repairLifecycleFlags(): void
    {
        try {
            (new SemesterLifecycleIntegrityService($this->pdo))->repair();
        } catch (Throwable $e) {
            // Reporting must remain available even if an old database cannot
            // be repaired automatically. The derived query above still avoids
            // treating stale school_years flags as the active source of truth.
            error_log('Semester lifecycle integrity repair failed: ' . $e->getMessage());
        }
    }

    public function listPartneredSchools(): array
    {
        if (!$this->tableExists('geofence_locations') || !$this->tableExists('partner_schools')) {
            return [];
        }

        $stmt = $this->pdo->query(
            "SELECT g.id, g.name, g.school_type, ps.address
             FROM geofence_locations g
             JOIN partner_schools ps ON ps.geofence_id = g.id
             ORDER BY g.name ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listSections(int $semesterId): array
    {
        if ($semesterId <= 0 || !$this->tableExists('semester_students') || !$this->tableExists('user_profiles')) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT TRIM(COALESCE(up.original_section, '')) AS section_code
             FROM semester_students sems
             JOIN user_profiles up ON up.user_id = sems.student_user_id
             WHERE sems.semester_id = :semester_id
               AND TRIM(COALESCE(up.original_section, '')) <> ''
             ORDER BY section_code ASC"
        );
        $stmt->execute(['semester_id' => $semesterId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchReportRows(
        int $semesterId,
        string $viewBy,
        int $schoolId,
        string $sectionCode,
        string $search
    ): array {
        if ($semesterId <= 0) {
            return [];
        }

        $viewBy = strtolower(trim($viewBy));
        if (!in_array($viewBy, ['partnered_school', 'section'], true)) {
            $viewBy = 'partnered_school';
        }

        $attendanceSql = $this->attendanceAggregateSql();
        $journalSql = $this->journalAggregateSql();
        $hasJournalFormMaximum = $this->tableExists('journal_forms')
            && $this->columnExists('journal_forms', 'max_points');
        $journalFormMaximumJoin = $hasJournalFormMaximum
            ? "LEFT JOIN (
                   SELECT semester_id,
                          CAST(SUBSTRING_INDEX(GROUP_CONCAT(max_points ORDER BY is_active DESC, id DESC), ',', 1) AS UNSIGNED) AS max_points
                   FROM journal_forms GROUP BY semester_id
               ) jfm ON jfm.semester_id = sem.id"
            : '';
        $journalDefaultMaximum = $hasJournalFormMaximum ? 'COALESCE(jfm.max_points, 50)' : '50';
        $checklistSql = $this->checklistAverageSql();
        $evaluationSql = $this->evaluationAverageSql();
        $assignedSchoolSourceSql = "
            SELECT sga.student_user_id, sga.semester_id, sga.geofence_id
            FROM student_geofence_assignments sga
            WHERE sga.assignment_status = 'active'
        ";
        if ($this->tableExists('student_school_deployments')) {
            $assignedSchoolSourceSql .= "
                UNION
                SELECT d.student_user_id, d.semester_id, d.geofence_id
                FROM student_school_deployments d
                WHERE d.deployment_status IN ('planned', 'active', 'running', 'locked', 'completed')
            ";
        }
        $where = "WHERE sem.id = :semester_id";
        $params = ['semester_id' => $semesterId];

        if ($viewBy === 'partnered_school' && $schoolId > 0) {
            $where .= " AND (EXISTS (
                SELECT 1
                FROM student_geofence_assignments filter_sga
                WHERE filter_sga.student_user_id = u.id
                  AND filter_sga.semester_id = sem.id
                  AND filter_sga.assignment_status = 'active'
                  AND filter_sga.geofence_id = :school_id
            )";
            if ($this->tableExists('student_school_deployments')) {
                $where .= " OR EXISTS (
                    SELECT 1
                    FROM student_school_deployments filter_d
                    WHERE filter_d.student_user_id = u.id
                      AND filter_d.semester_id = sem.id
                      AND filter_d.geofence_id = :school_id_deployment
                )";
            }
            $where .= ')';
            $params['school_id'] = $schoolId;
            if ($this->tableExists('student_school_deployments')) {
                $params['school_id_deployment'] = $schoolId;
            }
        }

        if ($viewBy === 'section' && trim($sectionCode) !== '') {
            $where .= ' AND TRIM(COALESCE(up.original_section, "")) = :section_code';
            $params['section_code'] = trim($sectionCode);
        }

        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND (
                u.school_id LIKE :search_school_id
                OR CONCAT_WS(" ", up.first_name, up.middle_name, up.last_name) LIKE :search_name
                OR CONCAT_WS(" ", up.last_name, up.first_name, up.middle_name) LIKE :search_name_rev
            )';
            $params['search_school_id'] = '%' . $search . '%';
            $params['search_name'] = '%' . $search . '%';
            $params['search_name_rev'] = '%' . $search . '%';
        }

        $journalColumns = [];
        for ($week = 1; $week <= self::JOURNALS_REQUIRED; $week++) {
            $journalColumns[] = sprintf('COALESCE(js.j%s, 0) AS j%s', $week, $week);
            $journalColumns[] = sprintf('COALESCE(js.jp%s, 0) AS jp%s', $week, $week);
            $journalColumns[] = sprintf('COALESCE(js.jm%s, %s) AS jm%s', $week, $journalDefaultMaximum, $week);
        }

        $groupColumns = [
            'sem.id',
            'sem.school_year_id',
            'sem.semester_no',
            'sem.semester_name',
            'sem.semester_status',
            'sem.start_date',
            'sem.practicum_start_date',
            'sem.end_date',
            'sy.year_label',
            'u.id',
            'u.school_id',
            'u.email',
            'up.first_name',
            'up.middle_name',
            'up.last_name',
            'up.original_section',
            'g.id',
            'g.name',
            'g.school_type',
            'ps.address',
            'att.present_days',
            'att.rendered_hours',
            'js.journals_done',
            'checklist.checklist_average',
            'eval.evaluation_average',
        ];
        if ($hasJournalFormMaximum) {
            $groupColumns[] = 'jfm.max_points';
        }
        for ($week = 1; $week <= self::JOURNALS_REQUIRED; $week++) {
            $groupColumns[] = 'js.j' . $week;
            $groupColumns[] = 'js.jp' . $week;
            $groupColumns[] = 'js.jm' . $week;
        }

        $sql = "SELECT
                    sem.id AS semester_id,
                    sem.school_year_id,
                    sem.semester_no,
                    sem.semester_name,
                    sem.semester_status,
                    sem.start_date AS semester_start_date,
                    sem.practicum_start_date,
                    sem.end_date AS semester_end_date,
                    sy.year_label,
                    u.id AS student_user_id,
                    u.school_id AS student_number,
                    u.email,
                    up.first_name,
                    up.middle_name,
                    up.last_name,
                    TRIM(COALESCE(up.original_section, '')) AS section_code,
                    g.id AS partnered_school_id,
                    g.name AS partnered_school_name,
                    g.school_type,
                    ps.address AS partnered_school_address,
                    (
                        SELECT GROUP_CONCAT(DISTINCT assigned_g.name ORDER BY assigned_g.name SEPARATOR '||')
                        FROM ({$assignedSchoolSourceSql}) assigned_schools
                        JOIN geofence_locations assigned_g ON assigned_g.id = assigned_schools.geofence_id
                        WHERE assigned_schools.student_user_id = u.id
                          AND assigned_schools.semester_id = sem.id
                          AND assigned_g.is_active = 1
                    ) AS assigned_school_names,
                    COALESCE(att.present_days, 0) + (
                        SELECT COUNT(*)
                        FROM system_holidays holiday
                        WHERE holiday.holiday_date BETWEEN
                            COALESCE(sem.practicum_start_date, sem.start_date)
                            AND LEAST(CURDATE(), sem.end_date)
                    ) AS present_days,
                    COALESCE(att.rendered_hours, 0) AS rendered_hours,
                    COALESCE(js.journals_done, 0) AS journals_done,
                    checklist.checklist_average,
                    eval.evaluation_average,
                    " . implode(",\n                    ", $journalColumns) . ",
                    CASE
                        WHEN COALESCE(js.journals_done, 0) > 0
                        THEN ROUND((COALESCE(js.journals_done, 0) / " . self::JOURNALS_REQUIRED . ") * 100, 2)
                        ELSE 0
                    END AS compliance_percent
                FROM semesters sem
                JOIN school_years sy ON sy.id = sem.school_year_id
                JOIN semester_students sems ON sems.semester_id = sem.id
                JOIN users u ON u.id = sems.student_user_id
                JOIN user_profiles up ON up.user_id = u.id
                LEFT JOIN student_geofence_assignments sga
                    ON sga.student_user_id = u.id
                   AND sga.semester_id = sem.id
                   AND sga.assignment_status = 'active'
                LEFT JOIN geofence_locations g ON g.id = sga.geofence_id
                LEFT JOIN partner_schools ps
                    ON ps.geofence_id = g.id
                LEFT JOIN (
                    {$attendanceSql}
                ) att ON att.semester_id = sem.id AND att.student_user_id = u.id
                LEFT JOIN (
                    {$journalSql}
                ) js ON js.semester_id = sem.id AND js.student_user_id = u.id
                {$journalFormMaximumJoin}
                LEFT JOIN (
                    {$checklistSql}
                ) checklist ON checklist.semester_id = sem.id AND checklist.student_user_id = u.id
                LEFT JOIN (
                    {$evaluationSql}
                ) eval ON eval.semester_id = sem.id AND eval.student_id = u.id
                {$where}
                GROUP BY " . implode(', ', $groupColumns) . "
                ORDER BY
                    COALESCE(g.name, '') ASC,
                    TRIM(COALESCE(up.original_section, '')) ASC,
                    up.last_name ASC,
                    up.first_name ASC";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function attendanceAggregateSql(): string
    {
        $liveHoursSql = $this->liveDisplayHoursSql('ar');

        return "SELECT
                    semester_id,
                    student_user_id,
                    COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM system_holidays h WHERE h.holiday_date = ar.attendance_date
                    ) THEN ar.attendance_date END) AS present_days,
                    COALESCE(SUM({$liveHoursSql}), 0) AS rendered_hours
                FROM attendance_records ar
                GROUP BY semester_id, student_user_id";
    }

    private function liveDisplayHoursSql(string $alias): string
    {
        return AttendanceCalculation::renderedHoursSql($alias);
    }

    private function journalAggregateSql(): string
    {
        if (!$this->tableExists('journal_submissions')) {
            $emptyColumns = ['semester_id', 'student_user_id', 'journals_done'];
            for ($week = 1; $week <= self::JOURNALS_REQUIRED; $week++) {
                $emptyColumns[] = 'j' . $week;
                $emptyColumns[] = 'jp' . $week;
                $emptyColumns[] = 'jm' . $week;
            }

            return 'SELECT ' . implode(', ', array_map(static fn(string $column): string => 'NULL AS ' . $column, $emptyColumns)) . ' WHERE 1 = 0';
        }

        $pivotColumns = [];
        $pointsExpression = $this->columnExists('journal_submissions', 'points_awarded')
            ? 'COALESCE(points_awarded, 0)' : '0';
        $maximumExpression = $this->columnExists('journal_submissions', 'max_points')
            ? 'COALESCE(max_points, 50)' : '50';
        for ($week = 1; $week <= self::JOURNALS_REQUIRED; $week++) {
            $pivotColumns[] = sprintf(
                "MAX(CASE WHEN week_no = %d AND status = 'approved' THEN 1 ELSE 0 END) AS j%d",
                $week,
                $week
            );
            $pivotColumns[] = sprintf(
                'MAX(CASE WHEN week_no = %d THEN %s END) AS jp%d',
                $week,
                $pointsExpression,
                $week
            );
            $pivotColumns[] = sprintf(
                'MAX(CASE WHEN week_no = %d THEN %s END) AS jm%d',
                $week,
                $maximumExpression,
                $week
            );
        }

        return "SELECT
                    semester_id,
                    student_user_id,
                    COUNT(DISTINCT CASE WHEN status = 'approved' THEN week_no END) AS journals_done,
                    " . implode(",\n                    ", $pivotColumns) . "
                FROM journal_submissions
                WHERE COALESCE(is_reopened, 0) = 0
                GROUP BY semester_id, student_user_id";
    }

    private function evaluationAverageSql(): string
    {
        if (!$this->tableExists('evaluation_submissions') || !$this->tableExists('evaluation_answers')) {
            return 'SELECT NULL AS semester_id, NULL AS student_id, NULL AS evaluation_average WHERE 1 = 0';
        }

        return "SELECT
                    es.semester_id,
                    es.student_id,
                    AVG(CAST(NULLIF(ea.rating_value, '') AS DECIMAL(10, 2))) AS evaluation_average
                FROM evaluation_submissions es
                JOIN evaluation_answers ea ON ea.submission_id = es.id
                GROUP BY es.semester_id, es.student_id";
    }

    private function checklistAverageSql(): string
    {
        if (!$this->tableExists('student_weekly_checklist') || !$this->tableExists('evaluation_checklist_items')) {
            return 'SELECT NULL AS semester_id, NULL AS student_user_id, NULL AS checklist_average WHERE 1 = 0';
        }

        return "SELECT
                    weekly.semester_id,
                    weekly.student_user_id,
                    AVG(weekly.weekly_score) AS checklist_average
                FROM (
                    SELECT
                        swc.semester_id,
                        swc.student_user_id,
                        swc.week_start,
                        COALESCE(SUM(CASE WHEN swc.is_checked = 1 THEN swc.awarded_points ELSE 0 END), 0) AS weekly_score
                    FROM student_weekly_checklist swc
                    JOIN evaluation_checklist_items eci ON eci.id = swc.checklist_item_id
                    GROUP BY swc.semester_id, swc.student_user_id, swc.week_start
                ) weekly
                GROUP BY weekly.semester_id, weekly.student_user_id";
    }
}
