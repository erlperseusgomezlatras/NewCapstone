<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/AttendanceCalculation.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';

final class CoordinatorReportRepository
{
    private const JOURNALS_REQUIRED = 16;
    private const TARGET_HOURS = 180.0;

    private SystemHolidayService $holidayService;

    public function __construct(private PDO $pdo)
    {
        $this->holidayService = new SystemHolidayService($pdo);
    }

    public function tableExists(string $table): bool
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

    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);

        return $stmt->fetchColumn() !== false;
    }

    public function baseFromSql(): string
    {
        return "FROM student_geofence_assignments sga
            JOIN semesters sem ON sem.id = sga.semester_id
            JOIN school_years sy ON sy.id = sem.school_year_id
            LEFT JOIN semester_students sems
              ON sems.student_user_id = sga.student_user_id
             AND sems.semester_id = sem.id
            JOIN users u ON u.id = sga.student_user_id
            JOIN user_profiles up ON up.user_id = u.id
            JOIN geofence_locations g ON g.id = sga.geofence_id";
    }

    public function journalAggregateSql(): string
    {
        $hasDynamic = $this->tableExists('journal_submissions');
        $hasLegacy = $this->tableExists('student_journals');

        if (!$hasDynamic && !$hasLegacy) {
            return 'SELECT NULL AS semester_id, NULL AS student_user_id, 0 AS journals_done, 0 AS journals_pending, 0 AS journals_approved, 0 AS journals_declined WHERE 1=0';
        }

        $sources = [];
        if ($hasDynamic) {
            $sources[] = "SELECT semester_id, student_user_id, week_start, status
                          FROM journal_submissions
                          WHERE status IN ('pending', 'reviewed', 'approved', 'declined', 'resubmitted')
                            AND COALESCE(is_reopened, 0) = 0";
        }
        if ($hasLegacy) {
            $sources[] = "SELECT semester_id, student_user_id, week_start, status
                          FROM student_journals
                          WHERE status IN ('pending', 'reviewed', 'approved', 'declined', 'resubmitted')";
        }

        return "SELECT semester_id,
                       student_user_id,
                       COUNT(DISTINCT week_start) AS journals_done,
                       SUM(CASE WHEN status IN ('pending', 'reviewed', 'resubmitted') THEN 1 ELSE 0 END) AS journals_pending,
                       SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS journals_approved,
                       SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) AS journals_declined
                FROM (" . implode(' UNION ALL ', $sources) . ") journal_union
                GROUP BY semester_id, student_user_id";
    }

    public function completionStatusSql(): string
    {
        $journalsRequired = self::JOURNALS_REQUIRED;
        $targetHours = self::TARGET_HOURS;
        $criticalHours = $targetHours * 0.8;
        $criticalJournals = (int) floor($journalsRequired * 0.75);

        return "CASE
            WHEN COALESCE(att.days_present, 0) = 0 AND COALESCE(att.rendered_hours, 0) <= 0 THEN 'Absent'
            WHEN COALESCE(att.rendered_hours, 0) >= {$targetHours}
                 AND COALESCE(jr.journals_done, 0) >= {$journalsRequired}
                 AND (COALESCE(ch.checklist_total, 0) = 0 OR COALESCE(ch.checklist_done, 0) >= COALESCE(ch.checklist_total, 0))
            THEN 'Completed'
            WHEN COALESCE(att.rendered_hours, 0) < {$criticalHours}
                 OR COALESCE(jr.journals_done, 0) < {$criticalJournals}
                 OR (COALESCE(ch.checklist_total, 0) > 0 AND (COALESCE(ch.checklist_done, 0) / COALESCE(ch.checklist_total, 1)) < 0.75)
            THEN 'Critical'
            ELSE 'Ongoing'
        END";
    }

    private function liveDisplayHoursSql(string $alias = 'att', ?string $currentDateSql = null): string
    {
        return AttendanceCalculation::renderedHoursSql($alias, $currentDateSql);
    }

    private function currentWeekWindow(): array
    {
        $today = practicumToday();
        $weekStart = practicumWeekStart($today);
        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
        $currentEnd = $today < $weekEnd ? $today : $weekEnd;
        $targetDays = 0;
        for ($cursor = new DateTimeImmutable($weekStart); $cursor <= new DateTimeImmutable($currentEnd); $cursor = $cursor->modify('+1 day')) {
            if ((int) $cursor->format('N') <= 5) {
                $targetDays++;
            }
        }

        return [
            'today' => $today,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'target_days' => $targetDays,
        ];
    }

    public function aggregateJoinSql(): string
    {
        $liveHoursSql = $this->liveDisplayHoursSql('ar');
        $evaluationSql = $this->evaluationAverageSql();

        return "LEFT JOIN (
            SELECT semester_id,
                   student_user_id,
                   COUNT(DISTINCT CASE WHEN time_in IS NOT NULL AND NOT EXISTS (
                       SELECT 1 FROM system_holidays h WHERE h.holiday_date = ar.attendance_date
                   ) THEN attendance_date END) AS days_present,
                   COALESCE(SUM({$liveHoursSql}), 0) AS rendered_hours,
                   SUM(
                       CASE
                           WHEN time_in IS NOT NULL
                        AND NOT EXISTS (SELECT 1 FROM system_holidays h WHERE h.holiday_date = ar.attendance_date)
                            AND TIME(time_in) >= '08:30:00'
                           THEN 1
                           ELSE 0
                       END
                   ) AS late_count,
                   COUNT(*) AS total_attendance_records
            FROM attendance_records ar
            GROUP BY semester_id, student_user_id
        ) att ON att.semester_id = sem.id AND att.student_user_id = sga.student_user_id
        LEFT JOIN (
            {$this->journalAggregateSql()}
        ) jr ON jr.semester_id = sem.id AND jr.student_user_id = sga.student_user_id
        LEFT JOIN (
            SELECT semester_id,
                   student_user_id,
                   SUM(CASE WHEN is_checked = 1 THEN 1 ELSE 0 END) AS checklist_done,
                   COUNT(*) AS checklist_total,
                   COALESCE(SUM(awarded_points), 0) AS checklist_score
            FROM student_weekly_checklist
            GROUP BY semester_id, student_user_id
        ) ch ON ch.semester_id = sem.id AND ch.student_user_id = sga.student_user_id
        LEFT JOIN (
            SELECT weekly.semester_id,
                   weekly.student_user_id,
                   AVG(weekly.weekly_score) AS checklist_average
            FROM (
                SELECT semester_id,
                       student_user_id,
                       week_start,
                       COALESCE(SUM(CASE WHEN is_checked = 1 THEN awarded_points ELSE 0 END), 0) AS weekly_score
                FROM student_weekly_checklist
                GROUP BY semester_id, student_user_id, week_start
            ) weekly
            GROUP BY weekly.semester_id, weekly.student_user_id
        ) ca ON ca.semester_id = sem.id AND ca.student_user_id = sga.student_user_id
        LEFT JOIN (
            {$evaluationSql}
        ) ev ON ev.semester_id = sem.id AND ev.student_id = sga.student_user_id";
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

    public function listSemesters(int $coordinatorUserId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT
                sem.id,
                sem.semester_name,
                sem.semester_no,
                sem.semester_status,
                sem.practicum_start_date,
                sem.practicum_status,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                sy.year_label,
                sy.start_date
             {$this->baseFromSql()}
             WHERE sga.coordinator_user_id = :coordinator_user_id
               AND sga.semester_id IS NOT NULL
             ORDER BY sy.start_date DESC, sem.semester_no ASC"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listPartneredSchools(int $coordinatorUserId, int $semesterId = 0): array
    {
        $sql = "SELECT DISTINCT g.id, g.name
                {$this->baseFromSql()}
                WHERE sga.coordinator_user_id = :coordinator_user_id
                  AND sga.semester_id IS NOT NULL";
        $params = ['coordinator_user_id' => $coordinatorUserId];
        if ($semesterId > 0) {
            $sql .= ' AND sem.id = :semester_id';
            $params['semester_id'] = $semesterId;
        }
        $sql .= ' ORDER BY g.name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchJournalWeekFlags(int $studentUserId, int $semesterId): array
    {
        $flags = array_fill(1, self::JOURNALS_REQUIRED, 0);
        if ($this->tableExists('journal_submissions')) {
            $stmt = $this->pdo->prepare("SELECT week_no FROM journal_submissions
                WHERE student_user_id=:student_user_id AND semester_id=:semester_id
                  AND status='approved' AND COALESCE(is_reopened, 0)=0
                  AND week_no BETWEEN 1 AND :required_weeks");
            $stmt->execute(['student_user_id'=>$studentUserId, 'semester_id'=>$semesterId, 'required_weeks'=>self::JOURNALS_REQUIRED]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $week = (int) ($row['week_no'] ?? 0);
                if ($week > 0) { $flags[$week] = 1; }
            }
        }
        if ($this->tableExists('student_journals')) {
            $stmt = $this->pdo->prepare("SELECT status FROM student_journals
                WHERE student_user_id=:student_user_id AND semester_id=:semester_id
                ORDER BY week_start LIMIT " . self::JOURNALS_REQUIRED);
            $stmt->execute(['student_user_id'=>$studentUserId, 'semester_id'=>$semesterId]);
            $week = 1;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ((string) ($row['status'] ?? '') === 'approved') {
                    $flags[$week] = 1;
                }
                $week++;
            }
        }
        return $flags;
    }

    public function fetchJournalWeekScores(int $studentUserId, int $semesterId): array
    {
        $defaultMaximum = 50;
        if ($this->tableExists('journal_forms') && $this->columnExists('journal_forms', 'max_points')) {
            $formStmt = $this->pdo->prepare(
                'SELECT max_points FROM journal_forms
                 WHERE semester_id = :semester_id
                 ORDER BY is_active DESC, id DESC LIMIT 1'
            );
            $formStmt->execute(['semester_id' => $semesterId]);
            $defaultMaximum = max(1, (int) ($formStmt->fetchColumn() ?: 50));
        }

        $scores = [];
        for ($week = 1; $week <= self::JOURNALS_REQUIRED; $week++) {
            $scores[$week] = ['points_awarded' => 0, 'max_points' => $defaultMaximum];
        }

        $hasDynamicSubmission = false;
        if ($this->tableExists('journal_submissions')) {
            $pointsExpression = $this->columnExists('journal_submissions', 'points_awarded')
                ? 'COALESCE(points_awarded, 0)' : '0';
            $maximumExpression = $this->columnExists('journal_submissions', 'max_points')
                ? 'COALESCE(max_points, ' . $defaultMaximum . ')' : (string) $defaultMaximum;
            $stmt = $this->pdo->prepare(
                "SELECT week_no,
                        MAX({$pointsExpression}) AS points_awarded,
                        MAX({$maximumExpression}) AS max_points
                 FROM journal_submissions
                 WHERE student_user_id = :student_user_id
                   AND semester_id = :semester_id
                   AND week_no BETWEEN 1 AND :required_weeks
                 GROUP BY week_no"
            );
            $stmt->execute([
                'student_user_id' => $studentUserId,
                'semester_id' => $semesterId,
                'required_weeks' => self::JOURNALS_REQUIRED,
            ]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $week = (int) ($row['week_no'] ?? 0);
                if ($week >= 1 && $week <= self::JOURNALS_REQUIRED) {
                    $hasDynamicSubmission = true;
                    $scores[$week] = [
                        'points_awarded' => max(0, (int) ($row['points_awarded'] ?? 0)),
                        'max_points' => max(1, (int) ($row['max_points'] ?? $defaultMaximum)),
                    ];
                }
            }
        }
        if (!$hasDynamicSubmission && $this->tableExists('student_journals')) {
            $stmt = $this->pdo->prepare(
                'SELECT week_start FROM student_journals
                 WHERE student_user_id = :student_user_id AND semester_id = :semester_id
                 ORDER BY week_start ASC LIMIT ' . self::JOURNALS_REQUIRED
            );
            $stmt->execute(['student_user_id' => $studentUserId, 'semester_id' => $semesterId]);
            $week = 1;
            while ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                $scores[$week++] = ['points_awarded' => 0, 'max_points' => $defaultMaximum];
            }
        }

        return $scores;
    }

    public function fetchStudentDeploymentSchools(int $studentUserId, int $coordinatorUserId, int $semesterId): array
    {
        $schools = [];
        if ($this->tableExists('student_school_deployments')) {
            $stmt = $this->pdo->prepare(
                "SELECT d.deployment_order, g.name AS school_name, g.school_type
                 FROM student_school_deployments d
                 JOIN geofence_locations g ON g.id = d.geofence_id
                 WHERE d.student_user_id = :student_user_id
                   AND d.coordinator_user_id = :coordinator_user_id
                   AND d.semester_id = :semester_id
                 ORDER BY d.deployment_order ASC, d.id ASC"
            );
            $stmt->execute([
                'student_user_id' => $studentUserId,
                'coordinator_user_id' => $coordinatorUserId,
                'semester_id' => $semesterId,
            ]);
            $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (count($schools) >= 2 || !$this->tableExists('coordinator_deployments')) {
            if (count($schools) >= 2) {
                return $schools;
            }
        }

        $fallbackStmt = $this->pdo->prepare(
            "SELECT cd.geofence_id, cd.deployment_order, g.name AS school_name, g.school_type
             FROM coordinator_deployments cd
             JOIN geofence_locations g ON g.id = cd.geofence_id
             WHERE cd.coordinator_user_id = :coordinator_user_id
               AND (cd.semester_id = :semester_id OR cd.semester_id IS NULL)
               AND cd.deployment_status = 'active'
             ORDER BY cd.deployment_order ASC, cd.id ASC"
        );
        $fallbackStmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'semester_id' => $semesterId,
        ]);
        foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) as $fallbackSchool) {
            $alreadyListed = false;
            foreach ($schools as $school) {
                if ((int) ($school['geofence_id'] ?? 0) === (int) ($fallbackSchool['geofence_id'] ?? 0)
                    || (int) ($school['deployment_order'] ?? 0) === (int) ($fallbackSchool['deployment_order'] ?? 0)) {
                    $alreadyListed = true;
                    break;
                }
            }
            if (!$alreadyListed) {
                $schools[] = $fallbackSchool;
            }
        }

        if (count($schools) < 2 && $this->tableExists('student_geofence_assignments')) {
            $assignmentStmt = $this->pdo->prepare(
                "SELECT  sga.geofence_id AS geofence_id,
                         sga.id AS deployment_order,
                         g.name AS school_name,
                         g.school_type
                 FROM student_geofence_assignments sga
                 JOIN geofence_locations g ON g.id = sga.geofence_id
                 WHERE sga.student_user_id = :student_user_id
                   AND sga.coordinator_user_id = :coordinator_user_id
                   AND sga.semester_id = :semester_id
                   AND sga.assignment_status = 'active'
                 ORDER BY deployment_order ASC, sga.assigned_at ASC"
            );
            $assignmentStmt->execute([
                'student_user_id' => $studentUserId,
                'coordinator_user_id' => $coordinatorUserId,
                'semester_id' => $semesterId,
            ]);
            foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $assignedSchool) {
                $alreadyListed = false;
                foreach ($schools as $school) {
                    if ((int) ($school['geofence_id'] ?? 0) === (int) ($assignedSchool['geofence_id'] ?? 0)) {
                        $alreadyListed = true;
                        break;
                    }
                }
                if (!$alreadyListed) {
                    $schools[] = $assignedSchool;
                }
            }
        }
        usort($schools, static fn(array $a, array $b): int => ((int) ($a['deployment_order'] ?? 99)) <=> ((int) ($b['deployment_order'] ?? 99)));

        return $schools;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function fetchReportRows(
        int $coordinatorUserId,
        int $semesterId,
        int $schoolId,
        string $search,
        string $statusFilter,
        string $sortBy,
        int $limit,
        int $offset,
        string $sectionCode = ''
    ): array {
        $currentWeek = $this->currentWeekWindow();
        $where = "WHERE sga.coordinator_user_id = :coordinator_user_id
                    AND sga.semester_id IS NOT NULL";
        $params = ['coordinator_user_id' => $coordinatorUserId];

        if ($semesterId > 0) {
            $where .= ' AND sem.id = :semester_id';
            $params['semester_id'] = $semesterId;
        }
        if ($schoolId > 0) {
            $where .= ' AND g.id = :school_id';
            $params['school_id'] = $schoolId;
        }
        if (trim($sectionCode) !== '') {
            $where .= " AND TRIM(COALESCE(up.original_section, '')) = :section_code";
            $params['section_code'] = trim($sectionCode);
        }
        if ($search !== '') {
            $where .= " AND (u.school_id LIKE :search OR CONCAT_WS(' ', up.first_name, up.last_name) LIKE :search_name OR CONCAT_WS(' ', up.last_name, up.first_name) LIKE :search_name_rev)";
            $params['search'] = '%' . $search . '%';
            $params['search_name'] = '%' . $search . '%';
            $params['search_name_rev'] = '%' . $search . '%';
        }

        $params['current_week_start'] = $currentWeek['week_start'];
        $params['current_week_end'] = $currentWeek['week_end'];

        $statusExpr = $this->completionStatusSql();
        $statusHaving = '';
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $statusHaving = " HAVING completion_status = :status_filter";
            $params['status_filter'] = ucfirst(strtolower($statusFilter));
        }

        $orderBy = match ($sortBy) {
            'hours' => 'rendered_hours DESC, up.last_name ASC, up.first_name ASC',
            'days_present' => 'days_present DESC, up.last_name ASC, up.first_name ASC',
            'checklist' => 'checklist_percent DESC, up.last_name ASC, up.first_name ASC',
            'journals' => 'journals_done DESC, up.last_name ASC, up.first_name ASC',
            default => 'up.last_name ASC, up.first_name ASC',
        };

        $countSql = "SELECT COUNT(*) FROM (
            SELECT sga.student_user_id, sem.id AS semester_id, g.id AS geofence_id,
                   {$statusExpr} AS completion_status
            {$this->baseFromSql()}
            {$this->aggregateJoinSql()}
            LEFT JOIN (
                SELECT semester_id,
                       student_user_id,
                       COUNT(DISTINCT CASE WHEN cw_ar.time_in IS NOT NULL AND NOT EXISTS (
                           SELECT 1 FROM system_holidays h WHERE h.holiday_date = cw_ar.attendance_date
                       ) THEN cw_ar.attendance_date END) AS current_week_present_days
                FROM attendance_records cw_ar
                WHERE cw_ar.attendance_date BETWEEN :current_week_start AND :current_week_end
                GROUP BY cw_ar.semester_id, cw_ar.student_user_id
            ) cw ON cw.semester_id = sem.id AND cw.student_user_id = sga.student_user_id
            {$where}
            GROUP BY sga.student_user_id, sem.id, g.id, att.days_present, att.rendered_hours,
                     jr.journals_done, ch.checklist_done, ch.checklist_total
            {$statusHaving}
        ) report_rows";

        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $sectionSql = $this->columnExists('user_profiles', 'original_section')
            ? "TRIM(COALESCE(up.original_section, ''))"
            : "''";
        $rowsSql = "SELECT
                sga.student_user_id,
                sga.coordinator_user_id,
                sem.id AS semester_id,
                sem.semester_name,
                sem.semester_no,
                sem.semester_status,
                sem.practicum_start_date,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                sy.year_label,
                g.id AS geofence_id,
                g.name AS school_name,
                u.school_id,
                u.email,
                up.first_name,
                up.last_name,
                {$sectionSql} AS section_code,
                COALESCE(att.days_present, 0) AS days_present,
                COALESCE(att.rendered_hours, 0) AS rendered_hours,
                COALESCE(att.late_count, 0) AS late_count,
                COALESCE(att.total_attendance_records, 0) AS total_attendance_records,
                COALESCE(jr.journals_done, 0) AS journals_done,
                COALESCE(jr.journals_pending, 0) AS journals_pending,
                COALESCE(jr.journals_approved, 0) AS journals_approved,
                COALESCE(jr.journals_declined, 0) AS journals_declined,
                COALESCE(ch.checklist_done, 0) AS checklist_done,
                COALESCE(ch.checklist_total, 0) AS checklist_total,
                COALESCE(ch.checklist_score, 0) AS checklist_score,
                ca.checklist_average,
                ev.evaluation_average,
                MAX(COALESCE(cw.current_week_present_days, 0)) AS current_week_present_days,
                CASE
                    WHEN COALESCE(ch.checklist_total, 0) > 0
                    THEN ROUND((COALESCE(ch.checklist_done, 0) / COALESCE(ch.checklist_total, 1)) * 100, 0)
                    ELSE 0
                END AS checklist_percent,
                {$statusExpr} AS completion_status
            {$this->baseFromSql()}
            {$this->aggregateJoinSql()}
            LEFT JOIN (
                SELECT semester_id,
                       student_user_id,
                       COUNT(DISTINCT CASE WHEN cw_ar.time_in IS NOT NULL AND NOT EXISTS (
                           SELECT 1 FROM system_holidays h WHERE h.holiday_date = cw_ar.attendance_date
                       ) THEN cw_ar.attendance_date END) AS current_week_present_days
                FROM attendance_records cw_ar
                WHERE cw_ar.attendance_date BETWEEN :current_week_start AND :current_week_end
                GROUP BY cw_ar.semester_id, cw_ar.student_user_id
            ) cw ON cw.semester_id = sem.id AND cw.student_user_id = sga.student_user_id
            {$where}
            GROUP BY sga.student_user_id, sem.id, g.id, sem.semester_name, sem.semester_no, sem.semester_status,
                     sem.practicum_start_date, sem.start_date, sem.end_date, sy.year_label, g.name,
                     u.school_id, u.email, up.first_name, up.last_name,
                     att.days_present, att.rendered_hours, att.late_count, att.total_attendance_records,
                     jr.journals_done, jr.journals_pending, jr.journals_approved, jr.journals_declined,
                     ch.checklist_done, ch.checklist_total, ch.checklist_score,
                     ca.checklist_average,
                     ev.evaluation_average
            {$statusHaving}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset";

        $rowsStmt = $this->pdo->prepare($rowsSql);
        foreach ($params as $key => $value) {
            $rowsStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $rowsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $rowsStmt->execute();

        return [
            'rows' => $rowsStmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    public function verifyStudentAccess(int $coordinatorUserId, int $studentUserId, int $semesterId, int $geofenceId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                sga.student_user_id,
                sem.id AS semester_id,
                sem.semester_name,
                sem.semester_no,
                sem.semester_status,
                sem.practicum_start_date,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                sy.year_label,
                g.id AS geofence_id,
                g.name AS school_name,
                u.school_id,
                u.email,
                up.first_name,
                up.last_name,
                up.original_section,
                cup.first_name AS coordinator_first_name,
                cup.last_name AS coordinator_last_name
             {$this->baseFromSql()}
             LEFT JOIN users cu ON cu.id = sga.coordinator_user_id
             LEFT JOIN user_profiles cup ON cup.user_id = cu.id
             WHERE sga.coordinator_user_id = :coordinator_user_id
               AND sga.student_user_id = :student_user_id
               AND sem.id = :semester_id
               AND g.id = :geofence_id
             LIMIT 1"
        );
        $stmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
            'geofence_id' => $geofenceId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function fetchStudentAggregates(int $studentUserId, int $semesterId): array
    {
        $statusExpr = $this->completionStatusSql();
        $liveHoursSql = $this->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(att.days_present, 0) AS days_present,
                COALESCE(att.rendered_hours, 0) AS rendered_hours,
                COALESCE(att.late_count, 0) AS late_count,
                COALESCE(att.total_attendance_records, 0) AS total_attendance_records,
                COALESCE(jr.journals_done, 0) AS journals_done,
                COALESCE(jr.journals_pending, 0) AS journals_pending,
                COALESCE(jr.journals_approved, 0) AS journals_approved,
                COALESCE(jr.journals_declined, 0) AS journals_declined,
                COALESCE(ch.checklist_done, 0) AS checklist_done,
                COALESCE(ch.checklist_total, 0) AS checklist_total,
                COALESCE(ch.checklist_score, 0) AS checklist_score,
                ev.evaluation_average,
                CASE
                    WHEN COALESCE(ch.checklist_total, 0) > 0
                    THEN ROUND((COALESCE(ch.checklist_done, 0) / COALESCE(ch.checklist_total, 1)) * 100, 0)
                    ELSE 0
                END AS checklist_percent,
                {$statusExpr} AS completion_status
             FROM (SELECT :student_user_id AS student_user_id, :semester_id AS semester_id) base
             LEFT JOIN (
                SELECT semester_id, student_user_id,
                       COUNT(DISTINCT CASE WHEN time_in IS NOT NULL THEN attendance_date END) AS days_present,
                       COALESCE(SUM({$liveHoursSql}), 0) AS rendered_hours,
                       SUM(CASE WHEN time_in IS NOT NULL AND TIME(time_in) >= '08:30:00' THEN 1 ELSE 0 END) AS late_count,
                       COUNT(*) AS total_attendance_records
                FROM attendance_records ar
                WHERE student_user_id = :student_user_id_att AND semester_id = :semester_id_att
                GROUP BY semester_id, student_user_id
             ) att ON att.student_user_id = base.student_user_id AND att.semester_id = base.semester_id
             LEFT JOIN (
                {$this->journalAggregateSql()}
             ) jr ON jr.student_user_id = base.student_user_id AND jr.semester_id = base.semester_id
             LEFT JOIN (
                SELECT semester_id, student_user_id,
                       SUM(CASE WHEN is_checked = 1 THEN 1 ELSE 0 END) AS checklist_done,
                       COUNT(*) AS checklist_total,
                       COALESCE(SUM(awarded_points), 0) AS checklist_score
                FROM student_weekly_checklist
                WHERE student_user_id = :student_user_id_ch AND semester_id = :semester_id_ch
                GROUP BY semester_id, student_user_id
             ) ch ON ch.student_user_id = base.student_user_id AND ch.semester_id = base.semester_id
             LEFT JOIN (
                {$this->evaluationAverageSql()}
             ) ev ON ev.student_id = base.student_user_id AND ev.semester_id = base.semester_id"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
            'student_user_id_att' => $studentUserId,
            'semester_id_att' => $semesterId,
            'student_user_id_ch' => $studentUserId,
            'semester_id_ch' => $semesterId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : [];
    }

    public function fetchWeeklyAttendance(int $studentUserId, int $semesterId, string $practicumStartDate, string $semesterStartDate, string $semesterEndDate, int $geofenceId = 0): array
    {
        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT attendance_date, time_in, time_out, total_hours
             FROM attendance_records
             WHERE student_user_id = :student_user_id
               AND semester_id = :semester_id
             ORDER BY attendance_date ASC"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
        ]);
        $attendanceByDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['time_in'])) {
                $attendanceByDate[(string) $row['attendance_date']] = $row;
            }
        }

        $effectiveStart = practicumEffectiveStart($practicumStartDate) ?: $semesterStartDate;
        $weeks = practicumBuildWeeks($effectiveStart, $semesterEndDate, $semesterEndDate);
        $holidayDates = $effectiveStart !== '' && $semesterEndDate >= $effectiveStart
            ? $this->holidayService->listBetween($effectiveStart, $semesterEndDate, $geofenceId > 0 ? $geofenceId : null)
            : [];
        $weekly = [];
        foreach ($weeks as $week) {
            $presentDays = 0;
            $absentDays = 0;
            $hours = 0.0;
            $weekStart = (string) $week['start'];
            $weekEnd = (string) $week['end'];

            if ($weekStart > $today) {
                $weekly[] = [
                    'label' => (string) ($week['label'] ?? ('Week ' . ($week['index'] ?? ''))),
                    'start' => $weekStart,
                    'end' => $weekEnd,
                    'present_days' => 0,
                    'absent_days' => 0,
                    'rendered_hours' => 0.0,
                    'status' => 'upcoming',
                ];
                continue;
            }

            for ($day = new DateTimeImmutable($weekStart); $day <= new DateTimeImmutable($weekEnd); $day = $day->modify('+1 day')) {
                $dateKey = $day->format('Y-m-d');
                if (isset($holidayDates[$dateKey])) {
                    if ($dateKey <= $today) {
                        $presentDays++;
                    }
                    continue;
                }
                if (isset($attendanceByDate[$dateKey])) {
                    $presentDays++;
                    $attendanceRow = $attendanceByDate[$dateKey];
                    if (!empty($attendanceRow['time_in']) && empty($attendanceRow['time_out']) && $dateKey === $today) {
                        $timeIn = new DateTimeImmutable((string) $attendanceRow['time_in'], $tz);
                        $hours += min(8.0, max(0.0, ($now->getTimestamp() - $timeIn->getTimestamp()) / 3600));
                    } else {
                        $hours += (float) ($attendanceRow['total_hours'] ?? 0);
                    }
                } elseif ($dateKey < $today) {
                    $absentDays++;
                }
            }
            $weekly[] = [
                'label' => (string) ($week['label'] ?? ('Week ' . ($week['index'] ?? ''))),
                'start' => $weekStart,
                'end' => $weekEnd,
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'rendered_hours' => $hours,
                'status' => practicumWeekStatus($weekStart, $weekEnd, $today),
            ];
        }

        return $weekly;
    }

    public function fetchJournalRows(int $studentUserId, int $semesterId): array
    {
        $hasDynamic = $this->tableExists('journal_submissions');
        $hasLegacy = $this->tableExists('student_journals');
        if (!$hasDynamic && !$hasLegacy) {
            return [];
        }

        $sources = [];
        if ($hasDynamic) {
            $sources[] = "SELECT id, week_no, week_start, submitted_at, status, coordinator_feedback,
                                 COALESCE(points_awarded, 0) AS points_awarded, COALESCE(max_points, 50) AS max_points,
                                 'dynamic' AS source
                          FROM journal_submissions
                          WHERE student_user_id = :student_user_id AND semester_id = :semester_id";
        }
        if ($hasLegacy) {
            $sources[] = "SELECT id, NULL AS week_no, week_start, submitted_at, status, coordinator_feedback,
                                 0 AS points_awarded, 50 AS max_points, 'legacy' AS source
                          FROM student_journals
                          WHERE student_user_id = :student_user_id_legacy AND semester_id = :semester_id_legacy";
        }

        $sql = implode(' UNION ALL ', $sources) . ' ORDER BY week_start ASC, submitted_at ASC';
        $stmt = $this->pdo->prepare($sql);
        $params = [
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
        ];
        if ($hasLegacy) {
            $params['student_user_id_legacy'] = $studentUserId;
            $params['semester_id_legacy'] = $semesterId;
        }
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchWeeklyChecklistScores(int $studentUserId, int $semesterId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                swc.week_start,
                COALESCE(SUM(swc.awarded_points), 0) AS total_score,
                COALESCE(SUM(eci.points), 0) AS max_score,
                CASE
                    WHEN COALESCE(SUM(eci.points), 0) > 0
                    THEN ROUND((COALESCE(SUM(swc.awarded_points), 0) / COALESCE(SUM(eci.points), 1)) * 100, 0)
                    ELSE 0
                END AS score_percent,
                GROUP_CONCAT(
                    CONCAT(eci.item_name, ': ', CASE WHEN swc.is_checked = 1 THEN 'Checked' ELSE 'Not Checked' END)
                    ORDER BY eci.display_order ASC, eci.id ASC
                    SEPARATOR '\n'
                ) AS remarks
             FROM student_weekly_checklist swc
             JOIN evaluation_checklist_items eci ON eci.id = swc.checklist_item_id
             WHERE swc.student_user_id = :student_user_id
               AND swc.semester_id = :semester_id
             GROUP BY swc.week_start
             ORDER BY swc.week_start ASC"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
