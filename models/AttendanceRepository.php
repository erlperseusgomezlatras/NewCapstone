<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AttendanceCalculation.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';

final class AttendanceRepository
{
    public function __construct(private PDO $pdo)
    {
        new SystemHolidayService($pdo);
        $this->ensurePartnerSchoolTimeInStartColumn();
    }

    private function ensurePartnerSchoolTimeInStartColumn(): void
    {
        if ($this->tableExists('partner_schools') && !$this->columnExists('partner_schools', 'time_in_start')) {
            $this->pdo->exec("ALTER TABLE partner_schools ADD COLUMN time_in_start TIME NOT NULL DEFAULT '06:00:00' AFTER daily_cap_hours");
        }
    }

    public function ensureEmergencyOverrideSchema(): void
    {
        $this->ensureSemesterPracticumStatusSchema();
        $this->ensureOrderedDeploymentSchema();
        $this->removeSectionEnrollmentConstraint();

        if (!$this->columnExists('attendance_records', 'geofence_seconds')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN geofence_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_hours"
            );
        }
        if (!$this->columnExists('attendance_records', 'geofence_last_seen_at')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN geofence_last_seen_at DATETIME NULL AFTER geofence_seconds"
            );
        }
        if (!$this->columnExists('attendance_records', 'geofence_inside')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN geofence_inside TINYINT(1) NOT NULL DEFAULT 0 AFTER geofence_last_seen_at"
            );
        }

        // Persist the final attendance decision separately from timestamps.
        // This lets an automatically closed missing time-out remain INCA rather
        // than being mistaken for a normally completed PRESENT record.
        if (!$this->columnExists('attendance_records', 'attendance_status')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN attendance_status VARCHAR(24) NOT NULL DEFAULT 'PRESENT' AFTER total_hours"
            );
        }
        if (!$this->columnExists('attendance_records', 'reviewed_by')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER attendance_status"
            );
        }
        if (!$this->columnExists('attendance_records', 'reviewed_at')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by"
            );
        }

        // Repair legacy values before any dashboard totals are calculated.
        // A single attendance date can never credit more than eight hours.
        $this->pdo->exec(
            "UPDATE attendance_records
             SET total_hours = LEAST(8.00, GREATEST(COALESCE(total_hours, 0), 0))
             WHERE total_hours < 0 OR total_hours > 8.00"
        );
        // A row without a time-in is an absence, never attendance credit.
        $this->pdo->exec(
            "UPDATE attendance_records
             SET total_hours = 0.00, attendance_status = 'ABSENT'
             WHERE time_in IS NULL AND COALESCE(total_hours, 0) <> 0"
        );
        // Convert the earlier automatic-timeout implementation into the
        // reviewable INCA state. It was previously being counted as PRESENT.
        $this->pdo->exec(
            "UPDATE attendance_records
             SET total_hours = 0.00, attendance_status = 'INCA',
                 remarks = 'Auto-closed: Missing timeout'
             WHERE remarks = 'Automatic time out at end of day'"
        );
        $this->pdo->exec(
            "UPDATE attendance_records
             SET attendance_status = CASE
                    WHEN remarks LIKE 'Auto-closed: Missing timeout%' THEN 'INCA'
                    WHEN time_in IS NULL THEN 'ABSENT'
                    WHEN time_out IS NULL AND attendance_date < CURDATE() THEN 'INCA'
                    WHEN time_out IS NULL THEN 'LIVE'
                    WHEN attendance_status = 'INCA_REJECTED' THEN 'INCA_REJECTED'
                    ELSE 'PRESENT'
                 END"
        );

        // Preserve any live hours that were already rendered before the
        // persistent geofence counter was introduced.
        $this->pdo->exec(
            "UPDATE attendance_records
             SET geofence_seconds = LEAST(28800, ROUND(GREATEST(total_hours, 0) * 3600))
             WHERE time_out IS NULL
               AND COALESCE(geofence_seconds, 0) = 0
               AND total_hours > 0"
        );

        if (!$this->columnExists('attendance_records', 'is_override')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN is_override TINYINT(1) NOT NULL DEFAULT 0 AFTER remarks"
            );
        }

        if (!$this->columnExists('attendance_records', 'override_by')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN override_by BIGINT UNSIGNED NULL AFTER is_override"
            );
        }

        if (!$this->columnExists('attendance_records', 'override_reason')) {
            $this->pdo->exec(
                "ALTER TABLE attendance_records
                 ADD COLUMN override_reason VARCHAR(255) NULL AFTER override_by"
            );
        }

        if ($this->tableExists('attendance_override_logs')) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE attendance_override_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                attendance_id BIGINT UNSIGNED NOT NULL,
                coordinator_id BIGINT UNSIGNED NOT NULL,
                reason TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_attendance_override_logs_attendance
                    FOREIGN KEY (attendance_id) REFERENCES attendance_records(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_attendance_override_logs_coordinator
                    FOREIGN KEY (coordinator_id) REFERENCES users(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE,
                INDEX idx_attendance_override_logs_attendance (attendance_id),
                INDEX idx_attendance_override_logs_coordinator (coordinator_id)
            ) ENGINE=InnoDB"
        );
    }

    private function ensureSemesterPracticumStatusSchema(): void
    {
        if (!$this->tableExists('semesters') || $this->columnExists('semesters', 'practicum_status')) {
            return;
        }

        $this->pdo->exec("ALTER TABLE semesters ADD COLUMN practicum_status VARCHAR(24) NOT NULL DEFAULT 'not_configured' AFTER practicum_start_date");
        $this->pdo->exec(
            "UPDATE semesters
             SET practicum_status = CASE
                WHEN semester_status = 'active' AND practicum_start_date IS NOT NULL THEN 'ongoing'
                WHEN semester_status = 'active' THEN 'not_yet_started'
                WHEN semester_status IN ('closed', 'archived') THEN 'completed'
                ELSE 'not_configured'
             END"
        );
        $this->pdo->exec("UPDATE semesters SET practicum_status = 'not_yet_started' WHERE practicum_status = 'establishing'");
    }

    /** Keep the new ordered deployment model additive so old attendance rows remain readable. */
    private function ensureOrderedDeploymentSchema(): void
    {
        if (!$this->tableExists('student_school_deployments')) {
            $this->pdo->exec("CREATE TABLE student_school_deployments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_user_id BIGINT UNSIGNED NOT NULL,
                coordinator_user_id BIGINT UNSIGNED NULL,
                geofence_id BIGINT UNSIGNED NOT NULL,
                semester_id INT UNSIGNED NOT NULL,
                deployment_order TINYINT UNSIGNED NOT NULL,
                required_seconds INT UNSIGNED NOT NULL DEFAULT 648000,
                rendered_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                deployment_status ENUM('locked','active','completed') NOT NULL DEFAULT 'locked',
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_student_school_order (student_user_id, semester_id, deployment_order),
                UNIQUE KEY uq_student_school_location (student_user_id, semester_id, geofence_id),
                KEY idx_student_school_active (student_user_id, semester_id, deployment_status)
            ) ENGINE=InnoDB");
        }
        if (!$this->columnExists('attendance_records', 'deployment_id')) {
            $this->pdo->exec('ALTER TABLE attendance_records ADD COLUMN deployment_id BIGINT UNSIGNED NULL AFTER semester_id');
        }
        // Existing installations may have only the legacy single-school row.
        // Copy it into the additive model so old attendance remains usable.
        $this->pdo->exec("INSERT INTO student_school_deployments
            (student_user_id, coordinator_user_id, geofence_id, semester_id, deployment_order, deployment_status, started_at)
            SELECT sga.student_user_id, sga.coordinator_user_id, sga.geofence_id, sga.semester_id, 1, 'active', sga.assigned_at
            FROM student_geofence_assignments sga
            WHERE sga.assignment_status = 'active' AND sga.semester_id IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM student_school_deployments d
                WHERE d.student_user_id=sga.student_user_id AND d.semester_id=sga.semester_id
                  AND d.geofence_id=sga.geofence_id
              )");
        $this->pdo->exec("UPDATE student_school_deployments d
            JOIN (SELECT student_user_id, semester_id, geofence_id, MIN(id) AS assignment_id
                  FROM student_geofence_assignments WHERE assignment_status = 'active'
                  GROUP BY student_user_id, semester_id, geofence_id) a
              ON a.student_user_id=d.student_user_id AND a.semester_id=d.semester_id AND a.geofence_id=d.geofence_id
            SET d.rendered_seconds = LEAST(d.required_seconds, d.rendered_seconds)
            WHERE d.rendered_seconds > d.required_seconds");
    }

    private function removeSectionEnrollmentConstraint(): void
    {
        if (!$this->tableExists('attendance_records')) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'attendance_records'
               AND CONSTRAINT_NAME = 'fk_attendance_enrollment'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'
             LIMIT 1"
        );
        $stmt->execute();
        if ($stmt->fetchColumn() !== false) {
            $this->pdo->exec('ALTER TABLE attendance_records DROP FOREIGN KEY fk_attendance_enrollment');
        }

        if ($this->columnExists('attendance_records', 'section_id')) {
            $this->pdo->exec('ALTER TABLE attendance_records MODIFY section_id BIGINT UNSIGNED NULL');
        }
        if ($this->columnExists('attendance_records', 'semester_id')) {
            $this->pdo->exec('ALTER TABLE attendance_records MODIFY semester_id INT UNSIGNED NULL');
        }
    }

    private function coordinatorAssignmentSourceSql(): ?string
    {
        $hasCoordinatorDeployments = $this->tableExists('coordinator_deployments');
        $hasCoordinatorSchools = $this->tableExists('coordinator_schools');

        if ($hasCoordinatorDeployments && $hasCoordinatorSchools) {
            return "(SELECT
                        cd.coordinator_user_id,
                        cd.geofence_id,
                        cd.started_at AS assigned_at
                     FROM coordinator_deployments cd
                     WHERE cd.deployment_status IN ('active', 'running')
                     UNION ALL
                     SELECT
                        cs.coordinator_user_id,
                        cs.geofence_id,
                        cs.assigned_at
                     FROM coordinator_schools cs
                     WHERE NOT EXISTS (
                        SELECT 1
                        FROM coordinator_deployments cd
                        WHERE cd.coordinator_user_id = cs.coordinator_user_id
                          AND cd.deployment_status IN ('active', 'running')
                     ))";
        }

        if ($hasCoordinatorDeployments) {
            return "(SELECT coordinator_user_id, geofence_id, started_at AS assigned_at
                      FROM coordinator_deployments
                      WHERE deployment_status IN ('active', 'running'))";
        }

        if ($hasCoordinatorSchools) {
            return '(SELECT coordinator_user_id, geofence_id, assigned_at FROM coordinator_schools)';
        }

        return null;
    }

    public function liveDisplayHoursSql(string $alias = 'ar', ?string $currentDateSql = null): string
    {
        return AttendanceCalculation::renderedHoursSql($alias, $currentDateSql);
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
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function getCurrentAcademicContext(): ?array
    {
        if (!$this->tableExists('semesters') || !$this->tableExists('school_years')) {
            return null;
        }

        $stmt = $this->pdo->query(
            "SELECT
                sem.id AS semester_id,
                sem.semester_name,
                sem.semester_no,
                sem.start_date AS semester_start_date,
                sem.practicum_start_date,
                sem.practicum_status,
                sem.end_date AS semester_end_date,
                sem.semester_status,
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
                END,
                sy.start_date DESC,
                sem.start_date DESC,
                sem.semester_no DESC
             LIMIT 1"
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getCoordinatorSectionContext(int $coordinatorUserId): ?array
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null) {
            $current = $this->getCurrentAcademicContext();
            if ($current === null) {
                return null;
            }

            return [
                'geofence_id' => null,
                'section_id' => null,
                'section_code' => null,
                'section_name' => null,
                'capacity' => null,
                'section_status' => null,
                'semester_id' => $current['semester_id'],
                'semester_name' => $current['semester_name'],
                'semester_no' => $current['semester_no'],
                'semester_start_date' => $current['semester_start_date'],
                'practicum_start_date' => $current['practicum_start_date'] ?? null,
                'practicum_status' => $current['practicum_status'] ?? 'not_configured',
                'semester_end_date' => $current['semester_end_date'],
                'semester_status' => $current['semester_status'],
                'school_year_id' => $current['school_year_id'],
                'year_label' => $current['year_label'],
                'year_status' => $current['year_status'],
                'school_name' => null,
                'school_address' => null,
                'deployment_type' => null,
            ];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                ca.geofence_id,
                NULL AS section_id,
                NULL AS section_code,
                NULL AS section_name,
                NULL AS capacity,
                NULL AS section_status,
                current_sem.semester_id,
                current_sem.semester_name,
                current_sem.semester_no,
                current_sem.semester_start_date,
                current_sem.practicum_start_date,
                current_sem.practicum_status,
                current_sem.semester_end_date,
                current_sem.semester_status,
                current_sem.school_year_id,
                current_sem.year_label,
                current_sem.year_status,
                g.name AS school_name,
                ps.address AS school_address,
                g.school_type AS deployment_type
             FROM {$assignmentSql} ca
             JOIN geofence_locations g ON g.id = ca.geofence_id
             LEFT JOIN partner_schools ps ON ps.geofence_id = g.id
             LEFT JOIN (
                SELECT
                    sem_ctx.id AS semester_id,
                    sem_ctx.semester_name,
                    sem_ctx.semester_no,
                    sem_ctx.start_date AS semester_start_date,
                    sem_ctx.practicum_start_date,
                    sem_ctx.practicum_status,
                    sem_ctx.end_date AS semester_end_date,
                    sem_ctx.semester_status,
                    sy_ctx.id AS school_year_id,
                    sy_ctx.year_label,
                    sy_ctx.year_status
                FROM semesters sem_ctx
                JOIN school_years sy_ctx ON sy_ctx.id = sem_ctx.school_year_id
                ORDER BY
                    CASE sem_ctx.semester_status
                        WHEN 'active' THEN 0
                        WHEN 'planned' THEN 1
                        WHEN 'closed' THEN 2
                        ELSE 3
                    END,
                    sy_ctx.start_date DESC,
                    sem_ctx.start_date DESC,
                    sem_ctx.semester_no DESC
                LIMIT 1
             ) current_sem ON 1 = 1
             WHERE ca.coordinator_user_id = :coordinator_user_id
             ORDER BY
                CASE current_sem.semester_status
                    WHEN 'active' THEN 0
                    WHEN 'planned' THEN 1
                    ELSE 2
                END,
                ca.assigned_at DESC
             LIMIT 1"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);
        $row = $stmt->fetch();

        if ($row !== false) {
            return $row;
        }

        $current = $this->getCurrentAcademicContext();
        if ($current === null) {
            return null;
        }

        return [
            'geofence_id' => null,
            'section_id' => null,
            'section_code' => null,
            'section_name' => null,
            'capacity' => null,
            'section_status' => null,
            'semester_id' => $current['semester_id'],
            'semester_name' => $current['semester_name'],
            'semester_no' => $current['semester_no'],
            'semester_start_date' => $current['semester_start_date'],
            'practicum_start_date' => $current['practicum_start_date'] ?? null,
            'practicum_status' => $current['practicum_status'] ?? 'not_configured',
            'semester_end_date' => $current['semester_end_date'],
            'semester_status' => $current['semester_status'],
            'school_year_id' => $current['school_year_id'],
            'year_label' => $current['year_label'],
            'year_status' => $current['year_status'],
            'school_name' => null,
            'school_address' => null,
            'deployment_type' => null,
        ];
    }

    public function getCoordinatorAssignedSchools(int $coordinatorUserId): array
    {
        if ($coordinatorUserId <= 0) {
            return [];
        }

        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT
                ca.geofence_id,
                g.name AS school_name,
                ps.address AS school_address,
                g.school_type AS deployment_type,
                ca.assigned_at
             FROM {$assignmentSql} ca
             JOIN geofence_locations g ON g.id = ca.geofence_id
             LEFT JOIN partner_schools ps ON ps.geofence_id = g.id
             WHERE ca.coordinator_user_id = :coordinator_user_id
               AND g.is_active = 1
             ORDER BY ca.assigned_at DESC, g.name"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);

        return $stmt->fetchAll() ?: [];
    }

    public function getCoordinatorAssignedStudentCount(int $coordinatorUserId): int
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null || !$this->tableExists('student_geofence_assignments')) {
            return 0;
        }

        $activeDeploymentJoin = '';

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT sga.student_user_id)
             FROM {$assignmentSql} ca
             {$activeDeploymentJoin}
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = ca.geofence_id
              AND sga.assignment_status = 'active'
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = sga.semester_id
              AND sems.enrollment_status = 'active'
             JOIN semesters sem
               ON sem.id = sems.semester_id
              AND sem.semester_status = 'active'
             JOIN users u ON u.id = sga.student_user_id
             JOIN roles r ON r.id = u.role_id
             WHERE ca.coordinator_user_id = :coordinator_user_id
               AND r.role_code = 'student'
               AND u.account_status = 'active'"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);

        return (int) $stmt->fetchColumn();
    }

    public function getCoordinatorDailyKpi(int $coordinatorUserId, string $attendanceDate): array
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null || !$this->tableExists('student_geofence_assignments')) {
            return [
                'active_now' => 0,
                'logged_today' => 0,
                'completed_8h' => 0,
            ];
        }

        $liveHoursSql = $this->liveDisplayHoursSql('ar');
        $activeDeploymentJoin = '';
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN ar.student_user_id END) AS active_now,
                COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL THEN ar.student_user_id END) AS logged_today,
                COUNT(DISTINCT CASE WHEN {$liveHoursSql} >= 8 THEN ar.student_user_id END) AS completed_8h
             FROM {$assignmentSql} ca
             {$activeDeploymentJoin}
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = ca.geofence_id
              AND sga.assignment_status = 'active'
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = sga.semester_id
              AND sems.enrollment_status = 'active'
             JOIN semesters sem
               ON sem.id = sems.semester_id
              AND sem.semester_status = 'active'
             JOIN users u ON u.id = sga.student_user_id
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN attendance_records ar
               ON ar.student_user_id = sga.student_user_id
              AND ar.attendance_date = :attendance_date
             WHERE ca.coordinator_user_id = :coordinator_user_id
               AND r.role_code = 'student'
               AND u.account_status = 'active'"
        );
        $stmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'attendance_date' => $attendanceDate,
        ]);

        return $stmt->fetch() ?: [
            'active_now' => 0,
            'logged_today' => 0,
            'completed_8h' => 0,
        ];
    }

    public function getLiveSectionStatus(int $sectionId, int $semesterId, string $attendanceDate): ?array
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        $assignmentJoin = $assignmentSql !== null
            ? "LEFT JOIN section_coordinators sc
               ON sc.section_id = s.id
             LEFT JOIN {$assignmentSql} ca
               ON ca.coordinator_user_id = sc.coordinator_user_id
             LEFT JOIN geofence_locations g ON g.id = ca.geofence_id
             LEFT JOIN partner_schools ps ON ps.geofence_id = g.id"
            : "LEFT JOIN section_coordinators sc
               ON sc.section_id = s.id
             LEFT JOIN geofence_locations g ON 1 = 0
             LEFT JOIN partner_schools ps ON 1 = 0";

        $stmt = $this->pdo->prepare(
            "SELECT
                s.id AS section_id,
                s.section_code,
                s.section_name,
                s.capacity,
                g.name AS school_name,
                ps.address AS school_address,
                g.school_type AS deployment_type,
                COUNT(DISTINCT CASE WHEN ss.enrollment_status = 'active' THEN ss.student_user_id END) AS total_students,
                COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN ar.student_user_id END) AS active_now
             FROM sections s
             LEFT JOIN section_students ss
               ON ss.section_id = s.id
              AND ss.semester_id = s.semester_id
             LEFT JOIN attendance_records ar
               ON ar.student_user_id = ss.student_user_id
              AND ar.attendance_date = :attendance_date
             {$assignmentJoin}
             WHERE s.id = :section_id
               AND s.semester_id = :semester_id
             GROUP BY
                s.id, s.section_code, s.section_name, s.capacity,
                g.name, ps.address, g.school_type"
        );
        $stmt->execute([
            'attendance_date' => $attendanceDate,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getRecentLoggedIn(int $coordinatorUserId, string $attendanceDate, int $limit = 6): array
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null || !$this->tableExists('student_geofence_assignments')) {
            return [];
        }

        $activeDeploymentJoin = '';

        $stmt = $this->pdo->prepare(
            "SELECT
                ar.student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                ar.time_in
             FROM {$assignmentSql} ca
             {$activeDeploymentJoin}
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = ca.geofence_id
              AND sga.assignment_status = 'active'
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = sga.semester_id
              AND sems.enrollment_status = 'active'
             JOIN semesters sem
               ON sem.id = sems.semester_id
              AND sem.semester_status = 'active'
              JOIN user_profiles up
                ON up.user_id = sga.student_user_id
              JOIN attendance_records ar
                ON ar.student_user_id = sga.student_user_id
               AND ar.attendance_date = :attendance_date
              JOIN users u ON u.id = sga.student_user_id
              JOIN roles r ON r.id = u.role_id
              WHERE ca.coordinator_user_id = :coordinator_user_id
                AND r.role_code = 'student'
                AND u.account_status = 'active'
               AND ar.time_in IS NOT NULL
             ORDER BY ar.time_in DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'attendance_date' => $attendanceDate,
        ]);

        return $stmt->fetchAll();
    }

    public function getWeeklyAttendanceRows(int $coordinatorUserId, int $semesterId, string $weekStart, string $weekEnd, string $currentAttendanceDate): array
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null || !$this->tableExists('student_geofence_assignments')) {
            return [];
        }

        $hasDynamicJournals = $this->tableExists('journal_submissions');
        $hasStudentJournals = !$hasDynamicJournals && $this->tableExists('student_journals');
        $journalPointsExpression = $hasDynamicJournals && $this->columnExists('journal_submissions', 'points_awarded')
            ? 'COALESCE(js.points_awarded, 0)' : '0';
        $journalMaximumExpression = $hasDynamicJournals && $this->columnExists('journal_submissions', 'max_points')
            ? 'COALESCE(js.max_points, 50)' : '50';
        $hasStudentConcerns = $this->tableExists('student_concerns');
        $hasOriginalSection = $this->columnExists('user_profiles', 'original_section');
        $csvSectionSql = $hasOriginalSection
            ? "NULLIF(TRIM(up.original_section), '')"
            : "NULL";
        $currentDateSql = $this->pdo->quote($currentAttendanceDate);
        $liveHoursSql = $this->liveDisplayHoursSql('ar', $currentDateSql);
        $semesterLiveHoursSql = $this->liveDisplayHoursSql('semester_ar', $currentDateSql);
        $activeDeploymentJoin = '';
        $sql = "SELECT
                u.id AS student_user_id,
                u.school_id,
                u.email,
                up.first_name,
                up.last_name,
                COALESCE({$csvSectionSql}, sec.section_code) AS section_code,
                COALESCE(sec.section_name, {$csvSectionSql}) AS section_name,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN {$liveHoursSql} ELSE 0 END), 0) AS mon_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN {$liveHoursSql} ELSE 0 END), 0) AS tue_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN {$liveHoursSql} ELSE 0 END), 0) AS wed_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN {$liveHoursSql} ELSE 0 END), 0) AS thu_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN {$liveHoursSql} ELSE 0 END), 0) AS fri_hours,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN ar.time_in ELSE NULL END) AS mon_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN ar.time_in ELSE NULL END) AS tue_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN ar.time_in ELSE NULL END) AS wed_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN ar.time_in ELSE NULL END) AS thu_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.time_in ELSE NULL END) AS fri_time_in,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN ar.time_out ELSE NULL END) AS mon_time_out,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN ar.time_out ELSE NULL END) AS tue_time_out,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN ar.time_out ELSE NULL END) AS wed_time_out,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN ar.time_out ELSE NULL END) AS thu_time_out,
                MAX(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.time_out ELSE NULL END) AS fri_time_out,
                COALESCE(SUM({$liveHoursSql}), 0) AS total_hours,
                COALESCE(semester_totals.public_hours, 0) AS public_hours,
                COALESCE(semester_totals.private_hours, 0) AS private_hours,
                MAX(CASE
                    WHEN ar.attendance_date = {$currentDateSql} AND ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN 1
                    ELSE 0
                END) AS is_active_today,
                MAX(CASE WHEN ar.attendance_date = {$currentDateSql} THEN ar.time_in ELSE NULL END) AS today_time_in,
                MAX(CASE WHEN ar.attendance_date = {$currentDateSql} THEN ar.time_out ELSE NULL END) AS today_time_out,
                MAX(CASE WHEN ar.attendance_date = {$currentDateSql} AND ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN COALESCE(ar.geofence_inside, 0) ELSE 1 END) AS today_geofence_inside,
                MAX(CASE WHEN ar.attendance_date = {$currentDateSql} THEN COALESCE(ar.is_override, 0) ELSE 0 END) AS today_is_override,
                MAX(CASE WHEN ar.attendance_date = {$currentDateSql} THEN {$liveHoursSql} ELSE 0 END) AS today_hours,
                MAX(CASE WHEN ar.attendance_date < {$currentDateSql} AND ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN 1 ELSE 0 END) AS pending_timeout,
                MAX(CASE WHEN COALESCE(ar.is_override, 0) = 1 THEN 1 ELSE 0 END) AS has_override_week,";

        if ($hasDynamicJournals) {
            $sql .= "
                CASE WHEN js.id IS NULL THEN 'missing' ELSE js.status END AS journal_status,
                js.id AS journal_id,
                {$journalPointsExpression} AS journal_points_awarded,
                {$journalMaximumExpression} AS journal_max_points";
        } elseif ($hasStudentJournals) {
            $sql .= "
                CASE
                    WHEN sj.id IS NULL THEN 'missing'
                    ELSE sj.status
                END AS journal_status,
                sj.id AS journal_id,
                0 AS journal_points_awarded,
                50 AS journal_max_points";
        } else {
            $sql .= "
                'missing' AS journal_status,
                NULL AS journal_id,
                0 AS journal_points_awarded,
                50 AS journal_max_points";
        }

        if ($hasStudentConcerns) {
            $sql .= ",
                COALESCE(concern_counts.pending_concern_count, 0) AS pending_concern_count,
                COALESCE(concern_counts.total_concern_count, 0) AS total_concern_count";
        } else {
            $sql .= ",
                0 AS pending_concern_count,
                0 AS total_concern_count";
        }

        $sql .= "
             FROM {$assignmentSql} ca
             {$activeDeploymentJoin}
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = ca.geofence_id
              AND sga.assignment_status = 'active'
             JOIN user_profiles up ON up.user_id = sga.student_user_id
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = :semester_id
              AND sems.enrollment_status = 'active'
             JOIN semesters sem
               ON sem.id = sems.semester_id
              AND sem.semester_status = 'active'
             JOIN users u ON u.id = sga.student_user_id
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN section_students ss
               ON ss.student_user_id = sga.student_user_id
              AND ss.semester_id = sga.semester_id
              AND ss.enrollment_status = 'active'
             LEFT JOIN sections sec ON sec.id = ss.section_id
             LEFT JOIN attendance_records ar
               ON ar.student_user_id = sga.student_user_id
              AND ar.semester_id = :attendance_semester_id
              AND ar.attendance_date BETWEEN :week_start AND :week_end";

        $sql .= "
             LEFT JOIN ojt_types ot ON ot.id = ar.ojt_type_id";

        $sql .= "
             LEFT JOIN (
                SELECT
                    semester_ar.student_user_id,
                    COALESCE(SUM(CASE WHEN semester_ot.ojt_code = 'public_school' THEN {$semesterLiveHoursSql} ELSE 0 END), 0) AS public_hours,
                    COALESCE(SUM(CASE WHEN semester_ot.ojt_code = 'private_school' THEN {$semesterLiveHoursSql} ELSE 0 END), 0) AS private_hours
                FROM attendance_records semester_ar
                LEFT JOIN ojt_types semester_ot ON semester_ot.id = semester_ar.ojt_type_id
                WHERE semester_ar.semester_id = :semester_total_id
                GROUP BY semester_ar.student_user_id
             ) semester_totals
               ON semester_totals.student_user_id = sga.student_user_id";

        if ($hasDynamicJournals) {
            $sql .= "
             LEFT JOIN journal_submissions js
               ON js.student_user_id = sga.student_user_id
              AND js.semester_id = :journal_semester_id
              AND js.week_start = :journal_week_start";
        } elseif ($hasStudentJournals) {
            $sql .= "
             LEFT JOIN student_journals sj
               ON sj.student_user_id = sga.student_user_id
              AND sj.semester_id = :journal_semester_id
              AND sj.week_start = :journal_week_start";
        }

        if ($hasStudentConcerns) {
            $sql .= "
             LEFT JOIN (
                SELECT
                    student_user_id,
                    SUM(CASE WHEN concern_status = 'pending' THEN 1 ELSE 0 END) AS pending_concern_count,
                    COUNT(*) AS total_concern_count
                FROM student_concerns
                WHERE semester_id = :concern_semester_id
                GROUP BY student_user_id
             ) concern_counts
               ON concern_counts.student_user_id = sga.student_user_id";
        }

        $sql .= "
             WHERE ca.coordinator_user_id = :coordinator_user_id
               AND sga.semester_id = :assignment_semester_id
               AND r.role_code = 'student'
               AND u.account_status = 'active'
             GROUP BY
                u.id,
                u.school_id,
                up.first_name,
                up.last_name,
                COALESCE({$csvSectionSql}, sec.section_code),
                COALESCE(sec.section_name, {$csvSectionSql}),
                semester_totals.public_hours,
                semester_totals.private_hours";

        if ($hasDynamicJournals) {
            $sql .= ", js.id, js.status, js.points_awarded, js.max_points";
        } elseif ($hasStudentJournals) {
            $sql .= ", sj.id, sj.status";
        }

        $sql .= "
             ORDER BY up.last_name ASC, up.first_name ASC";

        $stmt = $this->pdo->prepare($sql);
        $params = [
            'semester_id' => $semesterId,
            'assignment_semester_id' => $semesterId,
            'attendance_semester_id' => $semesterId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'semester_total_id' => $semesterId,
            'coordinator_user_id' => $coordinatorUserId,
        ];
        if ($hasDynamicJournals || $hasStudentJournals) {
            $params['journal_semester_id'] = $semesterId;
            $params['journal_week_start'] = $weekStart;
        }
        if ($hasStudentConcerns) {
            $params['concern_semester_id'] = $semesterId;
        }
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getCoordinatorSectionHistory(int $coordinatorUserId): array
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null) {
            return [];
        }

        $renderedHoursSql = $this->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT
                ca.geofence_id,
                g.name AS school_name,
                NULL AS section_id,
                NULL AS section_code,
                NULL AS section_name,
                sem.id AS semester_id,
                sem.semester_name,
                sem.semester_no,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                sem.semester_status,
                sy.year_label,
                COUNT(DISTINCT sga.student_user_id) AS total_students,
                COALESCE(AVG(LEAST((totals.total_hours / 40) * 100, 100)), 0) AS completion_rate
             FROM {$assignmentSql} ca
             JOIN geofence_locations g ON g.id = ca.geofence_id
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = ca.geofence_id
              AND sga.assignment_status = 'active'
             JOIN user_profiles up ON up.user_id = sga.student_user_id
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = sga.semester_id
             JOIN semesters sem ON sem.id = sems.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             LEFT JOIN (
                SELECT
                    semester_id,
                    student_user_id,
                    SUM({$renderedHoursSql}) AS total_hours
                FROM attendance_records ar
                GROUP BY semester_id, student_user_id
              ) totals
               ON totals.semester_id = sem.id
              AND totals.student_user_id = sga.student_user_id
             WHERE ca.coordinator_user_id = :coordinator_user_id
             GROUP BY
                ca.geofence_id, g.name,
                sem.id, sem.semester_name, sem.semester_no,
                sem.start_date, sem.end_date, sem.semester_status,
                sy.year_label
             ORDER BY sy.start_date DESC, sem.semester_no DESC, g.name ASC"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);

        return $stmt->fetchAll();
    }

    public function getCoordinatorHandledStudentsHistory(int $coordinatorUserId): array
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null) {
            return [];
        }

        $renderedHoursSql = $this->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT
                ca.geofence_id,
                g.name AS school_name,
                NULL AS section_id,
                NULL AS section_code,
                NULL AS section_name,
                sem.id AS semester_id,
                sem.semester_name,
                sem.semester_status,
                sy.year_label,
                sga.student_user_id,
                sga.assignment_status AS enrollment_status,
                u.school_id,
                u.email,
                up.first_name,
                up.last_name,
                COALESCE(att.days_present, 0) AS days_present,
                COALESCE(att.total_hours, 0) AS total_hours
             FROM {$assignmentSql} ca
             JOIN geofence_locations g ON g.id = ca.geofence_id
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = ca.geofence_id
              AND sga.assignment_status = 'active'
             JOIN user_profiles up ON up.user_id = sga.student_user_id
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = sga.semester_id
             JOIN semesters sem ON sem.id = sems.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             JOIN users u ON u.id = sga.student_user_id
             LEFT JOIN (
                SELECT
                    semester_id,
                    student_user_id,
                    COUNT(DISTINCT attendance_date) AS days_present,
                    SUM({$renderedHoursSql}) AS total_hours
                FROM attendance_records ar
                GROUP BY semester_id, student_user_id
              ) att
               ON att.semester_id = sem.id
              AND att.student_user_id = sga.student_user_id
             WHERE ca.coordinator_user_id = :coordinator_user_id
             ORDER BY
                sy.start_date DESC,
                sem.semester_no DESC,
                g.name ASC,
                up.last_name ASC,
                up.first_name ASC"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);

        return $stmt->fetchAll();
    }

    public function getOjtTypeIdByCode(string $ojtCode): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ojt_types WHERE ojt_code = :ojt_code AND is_active = 1 LIMIT 1');
        $stmt->execute(['ojt_code' => $ojtCode]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function attendanceExists(int $studentUserId, string $attendanceDate): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM attendance_records
             WHERE student_user_id = :student_user_id
               AND attendance_date = :attendance_date
             LIMIT 1'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'attendance_date' => $attendanceDate,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function getCoordinatorManagedStudentContext(int $coordinatorUserId, int $studentUserId): ?array
    {
        $assignmentSql = $this->coordinatorAssignmentSourceSql();
        if ($assignmentSql === null || !$this->tableExists('student_geofence_assignments')) {
            return null;
        }

        $activeDeploymentJoin = '';

        $stmt = $this->pdo->prepare(
            "SELECT
                sga.student_user_id,
                sga.geofence_id,
                ss.section_id,
                COALESCE(ss.semester_id, sem.id) AS semester_id,
                sem.practicum_start_date,
                sem.practicum_status,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                g.school_type
             FROM {$assignmentSql} ca
             {$activeDeploymentJoin}
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = ca.geofence_id
              AND sga.assignment_status = 'active'
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = sga.semester_id
              AND sems.enrollment_status = 'active'
             JOIN semesters sem
               ON sem.id = sems.semester_id
              AND sem.semester_status = 'active'
             LEFT JOIN section_students ss
               ON ss.student_user_id = sga.student_user_id
              AND ss.semester_id = sem.id
              AND ss.enrollment_status = 'active'
             JOIN geofence_locations g
               ON g.id = sga.geofence_id
             JOIN users u
               ON u.id = sga.student_user_id
             JOIN roles r
               ON r.id = u.role_id
             WHERE ca.coordinator_user_id = :coordinator_user_id
               AND sga.student_user_id = :student_user_id
               AND r.role_code = 'student'
               AND u.account_status = 'active'
             LIMIT 1"
        );
        $stmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'student_user_id' => $studentUserId,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getCoordinatorActiveStudentDeployment(int $coordinatorUserId, int $studentUserId): ?array
    {
        if (!$this->tableExists('student_school_deployments')) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT d.id AS deployment_id, d.geofence_id, d.semester_id,
                    d.deployment_order, d.required_seconds, d.rendered_seconds,
                    d.deployment_status, sem.practicum_start_date,
                    sem.practicum_status,
                    sem.start_date AS semester_start_date,
                    sem.end_date AS semester_end_date,
                    g.school_type
             FROM student_school_deployments d
             JOIN semesters sem ON sem.id=d.semester_id AND sem.semester_status='active'
             JOIN geofence_locations g ON g.id=d.geofence_id
             WHERE d.coordinator_user_id=:coordinator_user_id
               AND d.student_user_id=:student_user_id
               AND d.deployment_status='active'
             ORDER BY d.deployment_order
             LIMIT 1"
        );
        $stmt->execute(['coordinator_user_id'=>$coordinatorUserId, 'student_user_id'=>$studentUserId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function insertAttendance(
        int $studentUserId,
        int $semesterId,
        int $ojtTypeId,
        string $attendanceDate,
        string $timeIn,
        ?string $timeOut,
        float $totalHours,
        ?string $remarks,
        int $isOverride = 0,
        ?int $overrideBy = null,
        ?string $overrideReason = null,
        ?int $deploymentId = null
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO attendance_records
                (student_user_id, semester_id, deployment_id, ojt_type_id, attendance_date, time_in, time_out, total_hours, attendance_status, geofence_seconds, geofence_last_seen_at, geofence_inside, remarks, is_override, override_by, override_reason)
             VALUES
                (:student_user_id, :semester_id, :deployment_id, :ojt_type_id, :attendance_date, :time_in, :time_out, LEAST(8.00, GREATEST(0, :total_hours)), 'PRESENT', 0, NULL, 0, :remarks, :is_override, :override_by, :override_reason)"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
            'deployment_id' => $deploymentId,
            'ojt_type_id' => $ojtTypeId,
            'attendance_date' => $attendanceDate,
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            'total_hours' => $totalHours,
            'remarks' => $remarks,
            'is_override' => $isOverride,
            'override_by' => $overrideBy,
            'override_reason' => $overrideReason,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function insertAttendanceOverrideLog(int $attendanceId, int $coordinatorUserId, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_override_logs (attendance_id, coordinator_id, reason)
             VALUES (:attendance_id, :coordinator_id, :reason)'
        );
        $stmt->execute([
            'attendance_id' => $attendanceId,
            'coordinator_id' => $coordinatorUserId,
            'reason' => $reason,
        ]);
    }

    public function getWeeklyHours(int $studentUserId, string $weekStart, string $weekEnd): float
    {
        $renderedHoursSql = $this->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM({$renderedHoursSql}), 0)
             FROM attendance_records ar
             WHERE ar.student_user_id = :student_user_id
               AND ar.attendance_date BETWEEN :week_start AND :week_end
               AND NOT EXISTS (
                   SELECT 1 FROM system_holidays h
                   WHERE h.holiday_date = ar.attendance_date
               )"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
        ]);

        return (float) $stmt->fetchColumn();
    }

    public function getTotalHoursByOjtType(int $studentUserId, string $ojtCode): float
    {
        $renderedHoursSql = $this->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM({$renderedHoursSql}), 0)
             FROM attendance_records ar
             JOIN ojt_types ot ON ot.id = ar.ojt_type_id
             WHERE ar.student_user_id = :student_user_id
               AND ot.ojt_code = :ojt_code"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'ojt_code' => $ojtCode,
        ]);

        return (float) $stmt->fetchColumn();
    }

    public function getOverallHours(int $studentUserId): float
    {
        $renderedHoursSql = $this->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM({$renderedHoursSql}), 0)
             FROM attendance_records ar
             WHERE ar.student_user_id = :student_user_id"
        );
        $stmt->execute(['student_user_id' => $studentUserId]);

        return (float) $stmt->fetchColumn();
    }

    public function getStudentTodayLiveAttendance(int $studentUserId, string $attendanceDate, ?int $semesterId = null): ?array
    {
        $liveHoursSql = $this->liveDisplayHoursSql('ar');
        $semesterClause = $semesterId !== null ? ' AND ar.semester_id = :semester_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT
                ar.id,
                ar.attendance_date,
                ar.time_in,
                ar.time_out,
                ar.attendance_status,
                ar.remarks,
                ar.is_override,
                ar.override_reason,
                ot.ojt_code,
                ar.total_hours,
                {$liveHoursSql} AS display_hours,
                LEAST(
                    GREATEST(
                        COALESCE(ar.geofence_seconds, 0)
                        + CASE
                            WHEN ar.geofence_inside = 1 AND ar.geofence_last_seen_at IS NOT NULL
                            THEN GREATEST(TIMESTAMPDIFF(SECOND, ar.geofence_last_seen_at, NOW()), 0)
                            ELSE 0
                          END,
                        0
                    ),
                    28800
                ) AS elapsed_seconds
             FROM attendance_records ar
             LEFT JOIN ojt_types ot ON ot.id = ar.ojt_type_id
             WHERE ar.student_user_id = :student_user_id
               AND ar.attendance_date = :attendance_date
               {$semesterClause}
             LIMIT 1"
        );
        $params = [
            'student_user_id' => $studentUserId,
            'attendance_date' => $attendanceDate,
        ];
        if ($semesterId !== null) {
            $params['semester_id'] = $semesterId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function updateStudentGeofenceHeartbeat(int $studentUserId, string $attendanceDate, float $latitude, float $longitude, bool $inside): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE attendance_records
             SET geofence_seconds = LEAST(
                     28800,
                     geofence_seconds + CASE
                         WHEN geofence_inside = 1 AND geofence_last_seen_at IS NOT NULL
                         THEN GREATEST(TIMESTAMPDIFF(SECOND, geofence_last_seen_at, NOW()), 0)
                         ELSE 0
                     END
                 ),
                 geofence_last_seen_at = NOW(),
                 geofence_inside = :inside,
                 attendance_status = 'LIVE'
             WHERE student_user_id = :student_user_id
               AND attendance_date = :attendance_date
               AND time_out IS NULL"
        );
        $stmt->execute([
            'inside' => $inside ? 1 : 0,
            'student_user_id' => $studentUserId,
            'attendance_date' => $attendanceDate,
        ]);
    }

    public function getAttendanceSemesterId(int $studentUserId, string $attendanceDate): ?int
    {
        $stmt = $this->pdo->prepare('SELECT semester_id FROM attendance_records WHERE student_user_id=:student_user_id AND attendance_date=:attendance_date LIMIT 1');
        $stmt->execute(['student_user_id' => $studentUserId, 'attendance_date' => $attendanceDate]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    public function getOpenAttendanceId(int $studentUserId, string $attendanceDate): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM attendance_records
             WHERE student_user_id = :student_user_id
               AND attendance_date = :attendance_date
               AND time_out IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'attendance_date' => $attendanceDate,
        ]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function getStudentAssignedGeofence(int $studentUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT g.id, g.latitude, g.longitude, g.radius_meters
             FROM student_school_deployments d
             JOIN geofence_locations g ON g.id = d.geofence_id
             WHERE d.student_user_id = :student_user_id
               AND d.deployment_status = 'active'
               AND g.is_active = 1
             ORDER BY d.deployment_order
             LIMIT 1"
        );
        $stmt->execute(['student_user_id' => $studentUserId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getStudentDeployments(int $studentUserId, int $semesterId): array
    {
        if (!$this->tableExists('student_school_deployments')) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.student_user_id, d.coordinator_user_id, d.geofence_id,
                    d.semester_id, d.deployment_order, d.required_seconds,
                    d.rendered_seconds, d.deployment_status, d.started_at, d.completed_at,
                    g.name AS school_name, g.school_type, g.latitude, g.longitude, g.radius_meters,
                    COALESCE(ps.time_in_start, '06:00:00') AS time_in_start
             FROM student_school_deployments d
             JOIN geofence_locations g ON g.id = d.geofence_id
              LEFT JOIN partner_schools ps ON ps.geofence_id = g.id
             WHERE d.student_user_id = :student_user_id
               AND d.semester_id = :semester_id
             ORDER BY d.deployment_order"
        );
        $stmt->execute(['student_user_id' => $studentUserId, 'semester_id' => $semesterId]);
        return $stmt->fetchAll() ?: [];
    }

    public function syncStudentDeploymentProgress(int $studentUserId, int $semesterId): void
    {
        if (!$this->tableExists('student_school_deployments')) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE student_school_deployments d
             LEFT JOIN (
                SELECT deployment_id,
                       SUM(CASE WHEN COALESCE(is_override, 0) = 1
                                THEN ROUND(GREATEST(COALESCE(total_hours, 0), 0) * 3600)
                                WHEN attendance_status IN ('INCA', 'INCA_REJECTED') THEN 0
                                WHEN time_out IS NULL AND attendance_date = CURDATE()
                                THEN LEAST(28800, COALESCE(geofence_seconds, 0)
                                     + CASE
                                           WHEN geofence_inside = 1 AND geofence_last_seen_at IS NOT NULL
                                           THEN LEAST(GREATEST(TIMESTAMPDIFF(SECOND, geofence_last_seen_at, NOW()), 0), 28800)
                                           ELSE 0
                                       END)
                                ELSE CASE WHEN COALESCE(geofence_seconds, 0) > 0
                                          THEN LEAST(28800, geofence_seconds)
                                          ELSE ROUND(LEAST(8.00, GREATEST(COALESCE(total_hours, 0), 0)) * 3600)
                                     END
                           END) AS rendered
                FROM attendance_records
                WHERE student_user_id = :student_user_id AND semester_id = :semester_id
                  AND NOT EXISTS (
                      SELECT 1 FROM system_holidays h
                      WHERE h.holiday_date = attendance_records.attendance_date
                  )
                  AND deployment_id IS NOT NULL
                GROUP BY deployment_id
             ) a ON a.deployment_id = d.id
             SET d.rendered_seconds = LEAST(d.required_seconds, COALESCE(a.rendered, 0)),
                 d.deployment_status = CASE
                    WHEN LEAST(d.required_seconds, COALESCE(a.rendered, 0)) >= d.required_seconds THEN 'completed'
                    WHEN d.deployment_order = 1 THEN 'active'
                    ELSE 'locked'
                 END,
                 d.completed_at = CASE
                    WHEN LEAST(d.required_seconds, COALESCE(a.rendered, 0)) >= d.required_seconds THEN COALESCE(d.completed_at, NOW())
                    ELSE NULL END,
                 d.started_at = CASE
                    WHEN d.deployment_order = 1 OR LEAST(d.required_seconds, COALESCE(a.rendered, 0)) > 0
                    THEN COALESCE(d.started_at, NOW()) ELSE d.started_at END
             WHERE d.student_user_id = :student_user_id_2 AND d.semester_id = :semester_id_2"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId, 'semester_id' => $semesterId,
            'student_user_id_2' => $studentUserId, 'semester_id_2' => $semesterId,
        ]);
        // A later deployment unlocks only after every prior row is complete.
        $this->pdo->prepare(
            "UPDATE student_school_deployments d
             SET d.deployment_status = CASE WHEN d.rendered_seconds >= d.required_seconds THEN 'completed' ELSE 'locked' END
             WHERE d.student_user_id = :student_user_id AND d.semester_id = :semester_id
               AND d.deployment_order > 1
               AND EXISTS (SELECT 1 FROM student_school_deployments p
                           WHERE p.student_user_id=d.student_user_id AND p.semester_id=d.semester_id
                             AND p.deployment_order=d.deployment_order-1
                             AND p.deployment_status='completed')"
        )->execute(['student_user_id' => $studentUserId, 'semester_id' => $semesterId]);
        $this->pdo->prepare(
            "UPDATE student_school_deployments d SET d.deployment_status='active'
             WHERE d.student_user_id=:student_user_id AND d.semester_id=:semester_id
               AND d.deployment_status='locked'
               AND d.deployment_order=(SELECT MIN(x.deployment_order) FROM student_school_deployments x
                   WHERE x.student_user_id=d.student_user_id AND x.semester_id=d.semester_id AND x.deployment_status='locked'
                     AND NOT EXISTS (SELECT 1 FROM student_school_deployments p WHERE p.student_user_id=x.student_user_id AND p.semester_id=x.semester_id AND p.deployment_order=x.deployment_order-1 AND p.deployment_status<>'completed'))"
        )->execute(['student_user_id' => $studentUserId, 'semester_id' => $semesterId]);
    }

    public function getStudentLiveTotals(int $studentUserId, int $semesterId, string $attendanceDate): array
    {
        $liveHoursSql = $this->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN global_holiday.holiday_date IS NULL AND school_holiday.holiday_date IS NULL THEN ar.total_hours ELSE 0 END), 0) AS credited_hours,
                COALESCE(SUM(CASE WHEN global_holiday.holiday_date IS NULL AND school_holiday.holiday_date IS NULL AND ot.ojt_code = 'public_school' THEN ar.total_hours ELSE 0 END), 0) AS public_credited_hours,
                COALESCE(SUM(CASE WHEN global_holiday.holiday_date IS NULL AND school_holiday.holiday_date IS NULL AND ot.ojt_code = 'private_school' THEN ar.total_hours ELSE 0 END), 0) AS private_credited_hours,
                COALESCE(SUM(CASE
                    WHEN global_holiday.holiday_date IS NULL AND school_holiday.holiday_date IS NULL AND ar.attendance_date = :attendance_date_live AND ar.time_out IS NULL THEN {$liveHoursSql}
                    ELSE 0
                END), 0) AS live_open_hours,
                COALESCE(SUM(CASE
                    WHEN global_holiday.holiday_date IS NULL AND school_holiday.holiday_date IS NULL AND ar.attendance_date = :attendance_date_public AND ar.time_out IS NULL AND ot.ojt_code = 'public_school' THEN {$liveHoursSql}
                    ELSE 0
                END), 0) AS public_live_open_hours,
                COALESCE(SUM(CASE
                    WHEN global_holiday.holiday_date IS NULL AND school_holiday.holiday_date IS NULL AND ar.attendance_date = :attendance_date_private AND ar.time_out IS NULL AND ot.ojt_code = 'private_school' THEN {$liveHoursSql}
                    ELSE 0
                END), 0) AS private_live_open_hours
             FROM attendance_records ar
             LEFT JOIN ojt_types ot ON ot.id = ar.ojt_type_id
              LEFT JOIN system_holidays global_holiday ON global_holiday.holiday_date = ar.attendance_date
              LEFT JOIN student_school_deployments attendance_deployment ON attendance_deployment.id = ar.deployment_id
              LEFT JOIN school_holidays school_holiday
                ON school_holiday.geofence_id = attendance_deployment.geofence_id
               AND school_holiday.holiday_date = ar.attendance_date
             WHERE ar.student_user_id = :student_user_id
               AND ar.semester_id = :semester_id"
        );
        $stmt->execute([
            'attendance_date_live' => $attendanceDate,
            'attendance_date_public' => $attendanceDate,
            'attendance_date_private' => $attendanceDate,
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : [
            'credited_hours' => 0,
            'public_credited_hours' => 0,
            'private_credited_hours' => 0,
            'live_open_hours' => 0,
            'public_live_open_hours' => 0,
            'private_live_open_hours' => 0,
        ];
    }
}
