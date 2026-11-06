<?php

declare(strict_types=1);

final class ConcernRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensureConcernSchema(): void
    {
        if (!$this->tableExists('student_concerns')) {
            $this->pdo->exec(
            "CREATE TABLE student_concerns (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_user_id BIGINT UNSIGNED NOT NULL,
                coordinator_user_id BIGINT UNSIGNED NULL,
                semester_id INT UNSIGNED NULL,
                category VARCHAR(64) NOT NULL DEFAULT 'General',
                subject VARCHAR(150) NOT NULL,
                message TEXT NOT NULL,
                concern_status ENUM('pending', 'resolved', 'declined') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_concern_student
                    FOREIGN KEY (student_user_id) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_concern_coordinator
                    FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                INDEX idx_student_concerns_student (student_user_id),
                INDEX idx_student_concerns_semester (student_user_id, semester_id),
                INDEX idx_student_concerns_status (concern_status),
                INDEX idx_student_concerns_created (created_at)
            ) ENGINE=InnoDB"
            );
        }
        if (!$this->columnExists('student_concerns', 'semester_id')) {
            $this->pdo->exec('ALTER TABLE student_concerns ADD COLUMN semester_id INT UNSIGNED NULL AFTER coordinator_user_id, ADD INDEX idx_student_concerns_semester (student_user_id, semester_id)');
            // Preserve legacy records in the semester that contained their
            // submission date. They remain history, never new-semester data.
            $this->pdo->exec("UPDATE student_concerns sc
                SET semester_id=(SELECT ss.semester_id FROM semester_students ss JOIN semesters sem ON sem.id=ss.semester_id
                    WHERE ss.student_user_id=sc.student_user_id AND DATE(sc.created_at) BETWEEN sem.start_date AND sem.end_date
                    ORDER BY sem.start_date DESC LIMIT 1)
                WHERE sc.semester_id IS NULL");
        }
        if (!$this->columnExists('student_concerns', 'category')) {
            $this->pdo->exec("ALTER TABLE student_concerns ADD COLUMN category VARCHAR(64) NOT NULL DEFAULT 'General' AFTER semester_id");
        }

        // Replace the old informational "reviewed" state with the two real
        // outcomes. Existing reviewed concerns remain open for follow-up.
        $this->pdo->exec("UPDATE student_concerns SET concern_status = 'pending' WHERE concern_status = 'reviewed'");
        $statusType = $this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_concerns'
               AND COLUMN_NAME = 'concern_status' LIMIT 1"
        )->fetchColumn();
        if (is_string($statusType) && str_contains($statusType, "'reviewed'")) {
            $this->pdo->exec("ALTER TABLE student_concerns MODIFY concern_status ENUM('pending', 'resolved', 'declined') NOT NULL DEFAULT 'pending'");
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS concern_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                concern_id BIGINT UNSIGNED NOT NULL,
                sender_user_id BIGINT UNSIGNED NOT NULL,
                sender_role ENUM('student', 'coordinator', 'head_teacher') NOT NULL,
                message TEXT NOT NULL,
                attachment_path VARCHAR(255) NULL,
                attachment_name VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_concern_messages_concern FOREIGN KEY (concern_id) REFERENCES student_concerns(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_concern_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
                INDEX idx_concern_messages_concern (concern_id, created_at),
                INDEX idx_concern_messages_sender (sender_user_id)
            ) ENGINE=InnoDB"
        );
        $this->pdo->exec(
            "INSERT INTO concern_messages (concern_id, sender_user_id, sender_role, message, created_at)
             SELECT sc.id, sc.student_user_id, 'student', sc.message, sc.created_at
             FROM student_concerns sc
             WHERE NOT EXISTS (
                 SELECT 1 FROM concern_messages cm WHERE cm.concern_id = sc.id
             )"
        );
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

    public function createConcern(int $studentUserId, int $semesterId, string $category, string $subject, string $message, ?array $attachment = null): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
            'INSERT INTO student_concerns
                (student_user_id, semester_id, category, subject, message)
             VALUES
                (:student_user_id, :semester_id, :category, :subject, :message)'
            );
            $stmt->execute([
                'student_user_id' => $studentUserId,
                'semester_id' => $semesterId,
                'category' => $category,
                'subject' => $subject,
                'message' => $message,
            ]);
            $concernId = (int) $this->pdo->lastInsertId();
            $this->addMessage($concernId, $studentUserId, 'student', $message, $attachment);
            $this->pdo->commit();
            return $concernId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getStudentConcerns(int $studentUserId, int $semesterId): array
    {
        if (!$this->tableExists('student_concerns')) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                sc.*,
                CONCAT(
                    COALESCE(coord_up.last_name, ''),
                    CASE
                        WHEN coord_up.last_name IS NOT NULL AND coord_up.first_name IS NOT NULL THEN ', '
                        ELSE ''
                    END,
                    COALESCE(coord_up.first_name, '')
                ) AS coordinator_name
             FROM student_concerns sc
             LEFT JOIN user_profiles coord_up
               ON coord_up.user_id = sc.coordinator_user_id
             WHERE sc.student_user_id = :student_user_id AND sc.semester_id = :semester_id
             ORDER BY sc.created_at DESC, sc.id DESC"
        );
        $stmt->execute(['student_user_id' => $studentUserId, 'semester_id' => $semesterId]);

        return $this->attachMessages($stmt->fetchAll());
    }

    public function getStudentConcernSummary(int $studentUserId, int $semesterId): array
    {
        if (!$this->tableExists('student_concerns')) {
            return [
                'total' => 0,
                'pending' => 0,
                'declined' => 0,
                'resolved' => 0,
            ];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN concern_status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN concern_status = 'declined' THEN 1 ELSE 0 END) AS declined,
                SUM(CASE WHEN concern_status = 'resolved' THEN 1 ELSE 0 END) AS resolved
             FROM student_concerns
             WHERE student_user_id = :student_user_id AND semester_id = :semester_id"
        );
        $stmt->execute(['student_user_id' => $studentUserId, 'semester_id' => $semesterId]);
        $row = $stmt->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'declined' => (int) ($row['declined'] ?? 0),
            'resolved' => (int) ($row['resolved'] ?? 0),
        ];
    }

    public function getCoordinatorManagedStudentProfile(int $coordinatorUserId, int $studentUserId): ?array
    {
        $managedStudentsSql = $this->coordinatorManagedStudentsSql();
        if ($managedStudentsSql === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                u.id AS student_user_id,
                u.school_id,
                u.email,
                up.first_name,
                up.last_name,
                CONCAT(up.last_name, ', ', up.first_name) AS student_name
             FROM ({$managedStudentsSql}) managed_students
             JOIN users u ON u.id = managed_students.student_user_id
             JOIN user_profiles up ON up.user_id = managed_students.student_user_id
             WHERE managed_students.coordinator_user_id = :coordinator_user_id
               AND managed_students.student_user_id = :student_user_id
             LIMIT 1"
        );
        $stmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'student_user_id' => $studentUserId,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getCoordinatorStudentConcerns(int $coordinatorUserId, int $studentUserId, int $semesterId): array
    {
        if (!$this->tableExists('student_concerns')) {
            return [];
        }

        $managedStudentsSql = $this->coordinatorManagedStudentsSql();
        if ($managedStudentsSql === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                sc.*,
                CONCAT(up.last_name, ', ', up.first_name) AS student_name,
                u.school_id,
                CONCAT(
                    COALESCE(coord_up.last_name, ''),
                    CASE
                        WHEN coord_up.last_name IS NOT NULL AND coord_up.first_name IS NOT NULL THEN ', '
                        ELSE ''
                    END,
                    COALESCE(coord_up.first_name, '')
                ) AS coordinator_name
             FROM student_concerns sc
             JOIN ({$managedStudentsSql}) managed_students
               ON managed_students.student_user_id = sc.student_user_id
              AND managed_students.coordinator_user_id = :coordinator_user_id
             JOIN users u ON u.id = sc.student_user_id
             JOIN user_profiles up ON up.user_id = sc.student_user_id
             LEFT JOIN user_profiles coord_up
               ON coord_up.user_id = sc.coordinator_user_id
             WHERE sc.student_user_id = :student_user_id AND sc.semester_id = :semester_id
             ORDER BY sc.created_at DESC, sc.id DESC"
        );
        $stmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
        ]);

        return $this->attachMessages($stmt->fetchAll());
    }

    public function getHeadTeacherConcerns(int $semesterId): array
    {
        if (!$this->tableExists('student_concerns')) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT sc.*, u.school_id,
                    CONCAT(up.last_name, ', ', up.first_name) AS student_name,
                    CONCAT(COALESCE(coord_up.last_name, ''), CASE WHEN coord_up.last_name IS NOT NULL AND coord_up.first_name IS NOT NULL THEN ', ' ELSE '' END, COALESCE(coord_up.first_name, '')) AS coordinator_name
             FROM student_concerns sc
             JOIN users u ON u.id = sc.student_user_id
             JOIN user_profiles up ON up.user_id = sc.student_user_id
             LEFT JOIN user_profiles coord_up ON coord_up.user_id = sc.coordinator_user_id
             WHERE sc.semester_id = :semester_id
             ORDER BY CASE sc.concern_status WHEN 'pending' THEN 0 ELSE 1 END, sc.updated_at DESC, sc.id DESC"
        );
        $stmt->execute(['semester_id' => $semesterId]);
        return $this->attachMessages($stmt->fetchAll());
    }

    public function addMessage(int $concernId, int $senderUserId, string $senderRole, string $message, ?array $attachment = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO concern_messages (concern_id, sender_user_id, sender_role, message, attachment_path, attachment_name)
             VALUES (:concern_id, :sender_user_id, :sender_role, :message, :attachment_path, :attachment_name)'
        );
        $stmt->execute([
            'concern_id' => $concernId,
            'sender_user_id' => $senderUserId,
            'sender_role' => $senderRole,
            'message' => $message,
            'attachment_path' => $attachment['path'] ?? null,
            'attachment_name' => $attachment['name'] ?? null,
        ]);

        $this->pdo->prepare('UPDATE student_concerns SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $concernId]);
    }

    public function concernBelongsToStudent(int $concernId, int $studentUserId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM student_concerns WHERE id = :concern_id AND student_user_id = :student_user_id LIMIT 1');
        $stmt->execute(['concern_id' => $concernId, 'student_user_id' => $studentUserId]);
        return $stmt->fetchColumn() !== false;
    }

    private function attachMessages(array $concerns): array
    {
        if ($concerns === [] || !$this->tableExists('concern_messages')) {
            return $concerns;
        }

        $ids = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $concerns)));
        if ($ids === []) {
            return $concerns;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT cm.*, CONCAT(COALESCE(up.last_name, ''), CASE WHEN up.last_name IS NOT NULL AND up.first_name IS NOT NULL THEN ', ' ELSE '' END, COALESCE(up.first_name, '')) AS sender_name
             FROM concern_messages cm LEFT JOIN user_profiles up ON up.user_id = cm.sender_user_id
             WHERE cm.concern_id IN ({$placeholders}) ORDER BY cm.created_at ASC, cm.id ASC"
        );
        $stmt->execute($ids);
        $messages = [];
        foreach ($stmt->fetchAll() as $message) {
            $messages[(int) $message['concern_id']][] = $message;
        }
        foreach ($concerns as &$concern) {
            $concern['messages'] = $messages[(int) $concern['id']] ?? [];
        }
        unset($concern);
        return $concerns;
    }

    public function updateConcernStatus(int $coordinatorUserId, int $studentUserId, int $semesterId, int $concernId, string $status): void
    {
        $managedStudentsSql = $this->coordinatorManagedStudentsSql();
        if ($managedStudentsSql === null || !$this->tableExists('student_concerns')) {
            throw new RuntimeException('Concern record is not available.');
        }

        $lookup = $this->pdo->prepare(
            "SELECT
                sc.id,
                sc.concern_status
             FROM student_concerns sc
             JOIN ({$managedStudentsSql}) managed_students
               ON managed_students.student_user_id = sc.student_user_id
              AND managed_students.coordinator_user_id = :coordinator_user_id
             WHERE sc.id = :concern_id
               AND sc.student_user_id = :student_user_id
               AND sc.semester_id = :semester_id
             LIMIT 1"
        );
        $lookup->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'concern_id' => $concernId,
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
        ]);

        $existing = $lookup->fetch();
        if ($existing === false) {
            throw new RuntimeException('Concern not found for this student.');
        }

        $currentStatus = (string) ($existing['concern_status'] ?? 'pending');
        if ($currentStatus !== 'pending') {
            if ($currentStatus === $status) {
                return;
            }

            throw new RuntimeException('Closed concerns cannot be changed.');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE student_concerns
             SET concern_status = :concern_status,
                 coordinator_user_id = :coordinator_user_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :concern_id
               AND student_user_id = :student_user_id"
        );
        $stmt->execute([
            'concern_status' => $status,
            'coordinator_user_id' => $coordinatorUserId,
            'concern_id' => $concernId,
            'student_user_id' => $studentUserId,
        ]);
    }

    private function coordinatorManagedStudentsSql(): ?string
    {
        $parts = [];

        if ($this->tableExists('student_geofence_assignments')) {
            $assignmentSql = $this->coordinatorAssignmentSourceSql();
            if ($assignmentSql !== null) {
                $activeDeploymentJoin = '';

                $parts[] = "SELECT DISTINCT
                                ca.coordinator_user_id,
                                sga.student_user_id
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
                            WHERE r.role_code = 'student'
                              AND u.account_status = 'active'";
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode("\nUNION\n", $parts);
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

    private function columnExists(string $table, string $column): bool
    {
        $stmt=$this->pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table_name AND column_name=:column_name LIMIT 1');
        $stmt->execute(['table_name'=>$table, 'column_name'=>$column]);
        return $stmt->fetchColumn() !== false;
    }
}
