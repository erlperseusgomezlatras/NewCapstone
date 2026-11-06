<?php

declare(strict_types=1);

final class JournalRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
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

    private function ensureSchema(): void
    {
        if (!$this->tableExists('student_journals')) {
            return;
        }

        if (!$this->columnExists('student_journals', 'coordinator_feedback')) {
            $this->pdo->exec('ALTER TABLE student_journals ADD COLUMN coordinator_feedback TEXT NULL');
        }

        if (!$this->columnExists('student_journals', 'reviewed_by')) {
            $this->pdo->exec('ALTER TABLE student_journals ADD COLUMN reviewed_by BIGINT UNSIGNED NULL');
        }

        if (!$this->columnExists('student_journals', 'reviewed_at')) {
            $this->pdo->exec('ALTER TABLE student_journals ADD COLUMN reviewed_at DATETIME NULL');
        }

        $statusColumnStmt = $this->pdo->prepare(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $statusColumnStmt->execute([
            'table_name' => 'student_journals',
            'column_name' => 'status',
        ]);
        $columnType = (string) ($statusColumnStmt->fetchColumn() ?: '');

        $expectedType = "enum('pending','reviewed','approved','declined','resubmitted')";
        if (strtolower($columnType) !== $expectedType) {
            $this->pdo->exec(
                "ALTER TABLE student_journals
                 MODIFY COLUMN status ENUM('pending_review','pending','reviewed','approved','revise','declined','resubmitted')
                 NOT NULL DEFAULT 'pending'"
            );
            $this->pdo->exec("UPDATE student_journals SET status = 'pending' WHERE status = 'pending_review'");
            $this->pdo->exec("UPDATE student_journals SET status = 'declined' WHERE status = 'revise'");
            $this->pdo->exec(
                "ALTER TABLE student_journals
                 MODIFY COLUMN status ENUM('pending','reviewed','approved','declined','resubmitted')
                 NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function getSectionJournals(int $sectionId, int $semesterId, string $weekStart): array
    {
        if (!$this->tableExists('student_journals')) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                ss.student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                sj.id AS journal_id,
                sj.week_start,
                sj.status,
                sj.grateful_for,
                sj.proud_of,
                sj.words_to_inspire,
                sj.affirmations,
                sj.look_forward_to,
                sj.feeling,
                sj.coordinator_feedback,
                sj.submitted_at,
                sj.updated_at
             FROM section_students ss
             JOIN users u ON u.id = ss.student_user_id
             JOIN user_profiles up ON up.user_id = ss.student_user_id
             LEFT JOIN student_journals sj
               ON sj.student_user_id = ss.student_user_id
              AND sj.semester_id = ss.semester_id
              AND sj.week_start = :week_start
             WHERE ss.section_id = :section_id
               AND ss.semester_id = :semester_id
               AND ss.enrollment_status = 'active'
             ORDER BY up.last_name ASC, up.first_name ASC"
        );
        $params = [
            'week_start' => $weekStart,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ];
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getCoordinatorSchoolJournals(int $coordinatorUserId, int $semesterId, string $weekStart): array
    {
        $hasStudentGeofenceAssignments = $this->tableExists('student_geofence_assignments');
        if (!$hasStudentGeofenceAssignments) {
            return [];
        }

        $assignmentSql = null;
        $hasCoordinatorDeployments = $this->tableExists('coordinator_deployments');
        $hasCoordinatorSchools = $this->tableExists('coordinator_schools');
        if ($hasStudentGeofenceAssignments && $hasCoordinatorDeployments && $hasCoordinatorSchools) {
            $assignmentSql = "(SELECT
                                cd.coordinator_user_id,
                                cd.geofence_id
                               FROM coordinator_deployments cd
                               WHERE cd.deployment_status IN ('active', 'running')
                               UNION ALL
                               SELECT
                                cs.coordinator_user_id,
                                cs.geofence_id
                               FROM coordinator_schools cs
                               WHERE NOT EXISTS (
                                SELECT 1
                                FROM coordinator_deployments cd
                                WHERE cd.coordinator_user_id = cs.coordinator_user_id
                                  AND cd.deployment_status IN ('active', 'running')
                               ))";
        } elseif ($hasStudentGeofenceAssignments && $hasCoordinatorDeployments) {
            $assignmentSql = "(SELECT coordinator_user_id, geofence_id
                               FROM coordinator_deployments
                               WHERE deployment_status IN ('active', 'running'))";
        } elseif ($hasStudentGeofenceAssignments && $hasCoordinatorSchools) {
            $assignmentSql = '(SELECT coordinator_user_id, geofence_id FROM coordinator_schools)';
        }

        $studentSourceParts = [];
        $params = [];

        if ($assignmentSql !== null) {
            $studentSourceParts[] = "SELECT DISTINCT sga.student_user_id
                                     FROM {$assignmentSql} ca
                                     JOIN student_geofence_assignments sga
                                       ON sga.geofence_id = ca.geofence_id
                                      AND sga.assignment_status = 'active'
                                      JOIN semester_students sems
                                        ON sems.student_user_id = sga.student_user_id
                                       AND sems.semester_id = :deployment_semester_id
                                       AND sems.enrollment_status = 'active'
                                      JOIN semesters sem
                                        ON sem.id = sems.semester_id
                                       AND sem.semester_status = 'active'
                                      WHERE ca.coordinator_user_id = :deployment_coordinator_user_id
                                        AND sga.semester_id = :deployment_assignment_semester_id";
            $params['deployment_coordinator_user_id'] = $coordinatorUserId;
            $params['deployment_semester_id'] = $semesterId;
            $params['deployment_assignment_semester_id'] = $semesterId;
        }

        if ($studentSourceParts === []) {
            return [];
        }

        $hasStudentJournals = $this->tableExists('student_journals');
        $studentSourceSql = implode("\nUNION\n", $studentSourceParts);
        $journalSelect = $hasStudentJournals
            ? "sj.id AS journal_id,
                sj.week_start,
                sj.status,
                sj.grateful_for,
                sj.proud_of,
                sj.words_to_inspire,
                sj.affirmations,
                sj.look_forward_to,
                sj.feeling,
                sj.coordinator_feedback,
                sj.submitted_at,
                sj.updated_at"
            : "NULL AS journal_id,
                :select_week_start AS week_start,
                NULL AS status,
                NULL AS grateful_for,
                NULL AS proud_of,
                NULL AS words_to_inspire,
                NULL AS affirmations,
                NULL AS look_forward_to,
                NULL AS feeling,
                NULL AS coordinator_feedback,
                NULL AS submitted_at,
                NULL AS updated_at";

        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');

        $journalJoin = $hasStudentJournals
            ? "LEFT JOIN student_journals sj
               ON sj.student_user_id = u.id
              AND sj.semester_id = :journal_semester_id
              AND (
                    sj.week_start = :journal_week_start
                    OR DATE(sj.submitted_at) BETWEEN :journal_week_start_for_submit AND :journal_week_end_for_submit
                  )"
            : '';

        if ($hasStudentJournals) {
            $params['journal_semester_id'] = $semesterId;
            $params['journal_week_start'] = $weekStart;
            $params['journal_week_start_for_submit'] = $weekStart;
            $params['journal_week_end_for_submit'] = $weekEnd;
        } else {
            $params['select_week_start'] = $weekStart;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                u.id AS student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                {$journalSelect}
             FROM ({$studentSourceSql}) assigned_students
             JOIN users u ON u.id = assigned_students.student_user_id
             JOIN user_profiles up ON up.user_id = assigned_students.student_user_id
             {$journalJoin}
             ORDER BY up.last_name ASC, up.first_name ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getJournalDetail(int $studentId, int $semesterId, string $weekStart): ?array
    {
        if (!$this->tableExists('student_journals')) {
            return null;
        }

        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            "SELECT
                sj.*,
                u.school_id,
                up.first_name,
                up.last_name
             FROM student_journals sj
             JOIN users u ON u.id = sj.student_user_id
             JOIN user_profiles up ON up.user_id = sj.student_user_id
             WHERE sj.student_user_id = :student_id
               AND sj.semester_id = :semester_id
               AND (
                    sj.week_start = :week_start
                    OR DATE(sj.submitted_at) BETWEEN :week_start_for_submit AND :week_end_for_submit
               )
             ORDER BY (sj.week_start = :week_start_order) DESC, sj.submitted_at DESC
             LIMIT 1"
        );
        $params = [
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'week_start_for_submit' => $weekStart,
            'week_end_for_submit' => $weekEnd,
            'week_start_order' => $weekStart,
        ];
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function updateJournalStatus(int $studentId, int $semesterId, string $weekStart, string $status, ?string $feedback, int $reviewedBy = 0): void
    {
        if (!$this->tableExists('student_journals')) {
            return;
        }

        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');

        $setFragments = [
            'status = :status',
            'coordinator_feedback = :coordinator_feedback',
            'updated_at = CURRENT_TIMESTAMP',
        ];
        $params = [
            'status' => $status,
            'coordinator_feedback' => $feedback,
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'week_start_for_submit' => $weekStart,
            'week_end_for_submit' => $weekEnd,
        ];

        if ($this->columnExists('student_journals', 'reviewed_by')) {
            $setFragments[] = 'reviewed_by = :reviewed_by';
            $params['reviewed_by'] = $reviewedBy > 0 ? $reviewedBy : null;
        }
        if ($this->columnExists('student_journals', 'reviewed_at')) {
            $setFragments[] = 'reviewed_at = CURRENT_TIMESTAMP';
        }

        $sql = sprintf(
            "UPDATE student_journals
             SET %s
             WHERE student_user_id = :student_id
               AND semester_id = :semester_id
               AND (
                    week_start = :week_start
                    OR DATE(submitted_at) BETWEEN :week_start_for_submit AND :week_end_for_submit
               )",
            implode(",\n                 ", $setFragments)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function resubmitJournal(int $journalId, int $studentId, string $gratefulFor, string $proudOf, string $wordsToInspire, string $affirmations, string $lookForwardTo, ?string $feeling): void
    {
        if (!$this->tableExists('student_journals')) {
            return;
        }

        $setFragments = [
            'grateful_for = :grateful_for',
            'proud_of = :proud_of',
            'words_to_inspire = :words_to_inspire',
            'affirmations = :affirmations',
            'look_forward_to = :look_forward_to',
            'feeling = :feeling',
            "status = 'resubmitted'",
            'updated_at = CURRENT_TIMESTAMP',
        ];
        $params = [
            'journal_id' => $journalId,
            'student_id' => $studentId,
            'grateful_for' => $gratefulFor,
            'proud_of' => $proudOf,
            'words_to_inspire' => $wordsToInspire,
            'affirmations' => $affirmations,
            'look_forward_to' => $lookForwardTo,
            'feeling' => $feeling,
        ];

        if ($this->columnExists('student_journals', 'reviewed_by')) {
            $setFragments[] = 'reviewed_by = NULL';
        }

        if ($this->columnExists('student_journals', 'reviewed_at')) {
            $setFragments[] = 'reviewed_at = NULL';
        }

        $sql = sprintf(
            "UPDATE student_journals
             SET %s
             WHERE id = :journal_id
               AND student_user_id = :student_id
               AND status = 'declined'",
            implode(",\n                 ", $setFragments)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
