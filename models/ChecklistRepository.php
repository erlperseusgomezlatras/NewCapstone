<?php

declare(strict_types=1);

final class ChecklistRepository
{
    public function __construct(private PDO $pdo)
    {
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

    private function getCurrentActiveSemesterId(): ?int
    {
        if (!$this->tableExists('semesters')) {
            return null;
        }

        $stmt = $this->pdo->query(
            "SELECT id
             FROM semesters
             WHERE semester_status = 'active'
             ORDER BY start_date DESC, id DESC
             LIMIT 1"
        );
        $semesterId = $stmt->fetchColumn();

        return $semesterId !== false ? (int) $semesterId : null;
    }

    public function createChecklistItemsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS evaluation_checklist_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_name VARCHAR(255) NOT NULL,
                points INT NOT NULL DEFAULT 1,
                display_order INT NOT NULL DEFAULT 0,
                semester_id INT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_evaluation_checklist_active (is_active),
                KEY idx_evaluation_checklist_display_order (display_order),
                KEY idx_evaluation_checklist_semester (semester_id),
                CONSTRAINT fk_evaluation_checklist_semester
                    FOREIGN KEY (semester_id) REFERENCES semesters(id)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_evaluation_checklist_created_by
                    FOREIGN KEY (created_by) REFERENCES users(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB"
        );
    }

    public function ensureChecklistItemsSchema(): void
    {
        if (!$this->tableExists('evaluation_checklist_items')) {
            $this->createChecklistItemsTable();
            return;
        }

        if ($this->columnExists('evaluation_checklist_items', 'label') && !$this->columnExists('evaluation_checklist_items', 'item_name')) {
            $this->pdo->exec(
                "ALTER TABLE evaluation_checklist_items
                 CHANGE COLUMN label item_name VARCHAR(255) NOT NULL"
            );
        }

        if ($this->columnExists('evaluation_checklist_items', 'sort_order') && !$this->columnExists('evaluation_checklist_items', 'display_order')) {
            $this->pdo->exec(
                "ALTER TABLE evaluation_checklist_items
                 CHANGE COLUMN sort_order display_order INT NOT NULL DEFAULT 0"
            );
        }

        if (!$this->columnExists('evaluation_checklist_items', 'display_order')) {
            $this->pdo->exec(
                "ALTER TABLE evaluation_checklist_items
                 ADD COLUMN display_order INT NOT NULL DEFAULT 0 AFTER points"
            );
        }

        if (!$this->columnExists('evaluation_checklist_items', 'semester_id')) {
            $this->pdo->exec(
                "ALTER TABLE evaluation_checklist_items
                 ADD COLUMN semester_id INT UNSIGNED NULL AFTER display_order"
            );
        }

        if (!$this->columnExists('evaluation_checklist_items', 'created_by')) {
            $fallbackUserId = (int) ($this->pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
            $this->pdo->exec(
                "ALTER TABLE evaluation_checklist_items
                 ADD COLUMN created_by BIGINT UNSIGNED NOT NULL DEFAULT {$fallbackUserId} AFTER is_active"
            );
        }

        $activeSemesterId = $this->getCurrentActiveSemesterId();
        if ($activeSemesterId !== null) {
            $stmt = $this->pdo->prepare(
                "UPDATE evaluation_checklist_items
                 SET semester_id = :semester_id
                 WHERE semester_id IS NULL"
            );
            $stmt->execute(['semester_id' => $activeSemesterId]);
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE evaluation_checklist_items
                 ADD INDEX idx_evaluation_checklist_semester (semester_id)"
            );
        } catch (Throwable) {
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE evaluation_checklist_items
                 ADD CONSTRAINT fk_evaluation_checklist_semester
                 FOREIGN KEY (semester_id) REFERENCES semesters(id)
                 ON DELETE SET NULL ON UPDATE CASCADE"
            );
        } catch (Throwable) {
        }
    }

    public function createChecklistSessionsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS checklist_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                section_id BIGINT UNSIGNED NULL,
                geofence_id BIGINT UNSIGNED NULL,
                semester_id INT UNSIGNED NOT NULL,
                week_start DATE NOT NULL,
                checklist_date DATE NULL,
                closed_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_checklist_session (section_id, semester_id, week_start),
                UNIQUE KEY uq_checklist_session_geofence (geofence_id, semester_id, week_start),
                KEY idx_checklist_session_week (semester_id, week_start),
                KEY idx_checklist_session_geofence (geofence_id, semester_id, week_start),
                CONSTRAINT fk_checklist_sessions_section
                    FOREIGN KEY (section_id) REFERENCES sections(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_checklist_sessions_geofence
                    FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_checklist_sessions_semester
                    FOREIGN KEY (semester_id) REFERENCES semesters(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB"
        );
    }

    public function ensureChecklistSessionsSchema(): void
    {
        if (!$this->tableExists('checklist_sessions')) {
            $this->createChecklistSessionsTable();
            return;
        }

        if (!$this->columnExists('checklist_sessions', 'geofence_id')) {
            $this->pdo->exec(
                "ALTER TABLE checklist_sessions
                 ADD COLUMN geofence_id BIGINT UNSIGNED NULL AFTER section_id"
            );
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE checklist_sessions
                 MODIFY COLUMN section_id BIGINT UNSIGNED NULL"
            );
        } catch (Throwable) {
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE checklist_sessions
                 ADD UNIQUE KEY uq_checklist_session_geofence (geofence_id, semester_id, week_start)"
            );
        } catch (Throwable) {
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE checklist_sessions
                 ADD INDEX idx_checklist_session_geofence (geofence_id, semester_id, week_start)"
            );
        } catch (Throwable) {
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE checklist_sessions
                 ADD CONSTRAINT fk_checklist_sessions_geofence
                 FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
                 ON DELETE CASCADE ON UPDATE CASCADE"
            );
        } catch (Throwable) {
        }
    }

    public function createStudentWeeklyChecklistTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS student_weekly_checklist (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_user_id BIGINT UNSIGNED NOT NULL,
                section_id BIGINT UNSIGNED NULL,
                geofence_id BIGINT UNSIGNED NULL,
                semester_id INT UNSIGNED NOT NULL,
                week_start DATE NOT NULL,
                checklist_item_id BIGINT UNSIGNED NOT NULL,
                is_checked TINYINT(1) NOT NULL DEFAULT 0,
                awarded_points INT NOT NULL DEFAULT 0,
                checked_by BIGINT UNSIGNED NOT NULL,
                checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_student_week_item (student_user_id, section_id, semester_id, week_start, checklist_item_id),
                UNIQUE KEY uq_student_week_item_geofence (student_user_id, geofence_id, semester_id, week_start, checklist_item_id),
                KEY idx_student_weekly_checklist_section_week (section_id, semester_id, week_start),
                KEY idx_student_weekly_checklist_geofence_week (geofence_id, semester_id, week_start),
                KEY idx_student_weekly_checklist_item (checklist_item_id),
                CONSTRAINT fk_student_weekly_checklist_student
                    FOREIGN KEY (student_user_id) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_student_weekly_checklist_section
                    FOREIGN KEY (section_id) REFERENCES sections(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_student_weekly_checklist_geofence
                    FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_student_weekly_checklist_semester
                    FOREIGN KEY (semester_id) REFERENCES semesters(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_student_weekly_checklist_item
                    FOREIGN KEY (checklist_item_id) REFERENCES evaluation_checklist_items(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_student_weekly_checklist_checked_by
                    FOREIGN KEY (checked_by) REFERENCES users(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB"
        );
    }

    public function ensureStudentWeeklyChecklistSchema(): void
    {
        if (!$this->tableExists('student_weekly_checklist')) {
            $this->createStudentWeeklyChecklistTable();
            return;
        }

        if (!$this->columnExists('student_weekly_checklist', 'geofence_id')) {
            $this->pdo->exec(
                "ALTER TABLE student_weekly_checklist
                 ADD COLUMN geofence_id BIGINT UNSIGNED NULL AFTER section_id"
            );
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE student_weekly_checklist
                 MODIFY COLUMN section_id BIGINT UNSIGNED NULL"
            );
        } catch (Throwable) {
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE student_weekly_checklist
                 ADD INDEX idx_student_weekly_checklist_student_user (student_user_id)"
            );
        } catch (Throwable) {
        }

        $legacyUniqueExists = (bool) $this->pdo->query(
            "SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'student_weekly_checklist'
               AND index_name = 'uq_student_week_item'
               AND seq_in_index = 1
               AND column_name = 'student_user_id'
             LIMIT 1"
        )->fetchColumn();

        if ($legacyUniqueExists) {
            $indexColumnsStmt = $this->pdo->query(
                "SELECT column_name
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = 'student_weekly_checklist'
                   AND index_name = 'uq_student_week_item'
                 ORDER BY seq_in_index ASC"
            );
            $indexColumns = array_map(
                static fn(array $row): string => (string) ($row['column_name'] ?? ''),
                $indexColumnsStmt->fetchAll()
            );

            $expected = ['student_user_id', 'section_id', 'semester_id', 'week_start', 'checklist_item_id'];
            if ($indexColumns !== $expected) {
                $this->pdo->exec(
                    "ALTER TABLE student_weekly_checklist
                     DROP INDEX uq_student_week_item"
                );
                $this->pdo->exec(
                    "ALTER TABLE student_weekly_checklist
                     ADD UNIQUE KEY uq_student_week_item (student_user_id, section_id, semester_id, week_start, checklist_item_id)"
                );
            }
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE student_weekly_checklist
                 ADD UNIQUE KEY uq_student_week_item_geofence (student_user_id, geofence_id, semester_id, week_start, checklist_item_id)"
            );
        } catch (Throwable) {
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE student_weekly_checklist
                 ADD INDEX idx_student_weekly_checklist_geofence_week (geofence_id, semester_id, week_start)"
            );
        } catch (Throwable) {
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE student_weekly_checklist
                 ADD CONSTRAINT fk_student_weekly_checklist_geofence
                 FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
                 ON DELETE CASCADE ON UPDATE CASCADE"
            );
        } catch (Throwable) {
        }
    }

    public function ensureChecklistStudentConfirmationsSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS checklist_student_confirmations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_user_id BIGINT UNSIGNED NOT NULL,
                geofence_id BIGINT UNSIGNED NOT NULL,
                semester_id INT UNSIGNED NOT NULL,
                week_start DATE NOT NULL,
                confirmed_by BIGINT UNSIGNED NOT NULL,
                confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_checklist_student_confirmation (student_user_id, geofence_id, semester_id, week_start),
                KEY idx_checklist_confirmation_week (geofence_id, semester_id, week_start)
            ) ENGINE=InnoDB"
        );
    }

    public function confirmChecklistStudent(int $studentUserId, int $geofenceId, int $semesterId, string $weekStart, int $confirmedBy): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO checklist_student_confirmations
                (student_user_id, geofence_id, semester_id, week_start, confirmed_by, confirmed_at)
             VALUES
                (:student_user_id, :geofence_id, :semester_id, :week_start, :confirmed_by, NOW())
             ON DUPLICATE KEY UPDATE
                confirmed_by = VALUES(confirmed_by),
                confirmed_at = NOW()"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'geofence_id' => $geofenceId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'confirmed_by' => $confirmedBy,
        ]);
    }

    public function isChecklistStudentConfirmed(int $studentUserId, int $geofenceId, int $semesterId, string $weekStart): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM checklist_student_confirmations
             WHERE student_user_id = :student_user_id
               AND geofence_id = :geofence_id
               AND semester_id = :semester_id
               AND week_start = :week_start
             LIMIT 1"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'geofence_id' => $geofenceId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function getSectionSemesterId(int $sectionId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT semester_id FROM sections WHERE id = :section_id LIMIT 1');
        $stmt->execute(['section_id' => $sectionId]);
        $semesterId = $stmt->fetchColumn();

        return $semesterId !== false ? (int) $semesterId : null;
    }

    public function getActiveChecklistItems(?int $semesterId = null): array
    {
        if ($semesterId !== null && $semesterId > 0 && $this->columnExists('evaluation_checklist_items', 'semester_id')) {
            $stmt = $this->pdo->prepare(
                "SELECT id, item_name, points, display_order, semester_id, is_active
                 FROM evaluation_checklist_items
                 WHERE is_active = 1
                   AND semester_id = :semester_id
                 ORDER BY display_order ASC, id ASC"
            );
            $stmt->execute(['semester_id' => $semesterId]);

            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->query(
            "SELECT id, item_name, points, display_order, semester_id, is_active
             FROM evaluation_checklist_items
             WHERE is_active = 1
             ORDER BY display_order ASC, id ASC"
        );

        return $stmt->fetchAll();
    }

    public function getAllChecklistItems(?int $semesterId = null): array
    {
        if ($semesterId !== null && $semesterId > 0 && $this->columnExists('evaluation_checklist_items', 'semester_id')) {
            $stmt = $this->pdo->prepare(
                "SELECT id, item_name, points, display_order, semester_id, is_active, created_by, created_at, updated_at
                 FROM evaluation_checklist_items
                 WHERE semester_id = :semester_id
                 ORDER BY is_active DESC, display_order ASC, id ASC"
            );
            $stmt->execute(['semester_id' => $semesterId]);

            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->query(
            "SELECT id, item_name, points, display_order, semester_id, is_active, created_by, created_at, updated_at
             FROM evaluation_checklist_items
             ORDER BY is_active DESC, display_order ASC, id ASC"
        );

        return $stmt->fetchAll();
    }

    public function getChecklistItem(int $itemId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, item_name, points, display_order, semester_id, is_active
             FROM evaluation_checklist_items
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getNextChecklistDisplayOrder(?int $semesterId = null): int
    {
        if ($semesterId !== null && $semesterId > 0 && $this->columnExists('evaluation_checklist_items', 'semester_id')) {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(MAX(display_order), 0) + 1
                 FROM evaluation_checklist_items
                 WHERE semester_id = :semester_id"
            );
            $stmt->execute(['semester_id' => $semesterId]);

            return (int) ($stmt->fetchColumn() ?: 1);
        }

        return (int) ($this->pdo->query(
            "SELECT COALESCE(MAX(display_order), 0) + 1
             FROM evaluation_checklist_items"
        )->fetchColumn() ?: 1);
    }

    public function createChecklistItem(string $itemName, int $points, int $displayOrder, int $createdBy, ?int $semesterId = null): void
    {
        $semesterId = $semesterId !== null && $semesterId > 0 ? $semesterId : $this->getCurrentActiveSemesterId();
        $stmt = $this->pdo->prepare(
            "INSERT INTO evaluation_checklist_items (item_name, points, display_order, semester_id, is_active, created_by)
             VALUES (:item_name, :points, :display_order, :semester_id, 1, :created_by)"
        );
        $stmt->execute([
            'item_name' => $itemName,
            'points' => $points,
            'display_order' => $displayOrder,
            'semester_id' => $semesterId,
            'created_by' => $createdBy,
        ]);
    }

    public function updateChecklistItemDefinition(int $itemId, string $itemName, int $points, int $displayOrder, int $isActive, ?int $semesterId = null): void
    {
        $scopeSql = $semesterId !== null && $semesterId > 0 && $this->columnExists('evaluation_checklist_items', 'semester_id')
            ? ' AND semester_id = :semester_id'
            : '';
        $stmt = $this->pdo->prepare(
            "UPDATE evaluation_checklist_items
             SET item_name = :item_name,
                 points = :points,
                 display_order = :display_order,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id{$scopeSql}"
        );
        $params = [
            'id' => $itemId,
            'item_name' => $itemName,
            'points' => $points,
            'display_order' => $displayOrder,
            'is_active' => $isActive,
        ];
        if ($scopeSql !== '') {
            $params['semester_id'] = $semesterId;
        }
        $stmt->execute($params);
    }

    public function deleteChecklistItem(int $itemId, ?int $semesterId = null): void
    {
        $scopeSql = $semesterId !== null && $semesterId > 0 && $this->columnExists('evaluation_checklist_items', 'semester_id')
            ? ' AND semester_id = :semester_id'
            : '';
        $stmt = $this->pdo->prepare('DELETE FROM evaluation_checklist_items WHERE id = :id' . $scopeSql);
        $params = ['id' => $itemId];
        if ($scopeSql !== '') {
            $params['semester_id'] = $semesterId;
        }
        $stmt->execute($params);
    }

    public function getChecklistSession(int $sectionId, int $semesterId, string $weekStart): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT section_id, semester_id, week_start, checklist_date, closed_at
             FROM checklist_sessions
             WHERE section_id = :section_id
               AND semester_id = :semester_id
               AND week_start = :week_start
             LIMIT 1"
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getChecklistSessionByGeofence(int $geofenceId, int $semesterId, string $weekStart): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT geofence_id, semester_id, week_start, checklist_date, closed_at
             FROM checklist_sessions
             WHERE geofence_id = :geofence_id
               AND semester_id = :semester_id
               AND week_start = :week_start
             LIMIT 1"
        );
        $stmt->execute([
            'geofence_id' => $geofenceId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function createChecklistSession(int $sectionId, int $semesterId, string $weekStart, string $checklistDate): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO checklist_sessions (section_id, semester_id, week_start, checklist_date, closed_at)
             VALUES (:section_id, :semester_id, :week_start, :checklist_date, NULL)
             ON DUPLICATE KEY UPDATE
                checklist_date = VALUES(checklist_date),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'checklist_date' => $checklistDate,
        ]);
    }

    public function closeChecklistSession(int $sectionId, int $semesterId, string $weekStart): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE checklist_sessions
             SET closed_at = COALESCE(closed_at, NOW())
             WHERE section_id = :section_id
               AND semester_id = :semester_id
               AND week_start = :week_start"
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);
    }

    public function createChecklistSessionByGeofence(int $geofenceId, int $semesterId, string $weekStart, string $checklistDate): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO checklist_sessions (section_id, geofence_id, semester_id, week_start, checklist_date, closed_at)
             VALUES (NULL, :geofence_id, :semester_id, :week_start, :checklist_date, NULL)
             ON DUPLICATE KEY UPDATE
                checklist_date = VALUES(checklist_date),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'geofence_id' => $geofenceId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'checklist_date' => $checklistDate,
        ]);
    }

    public function closeChecklistSessionByGeofence(int $geofenceId, int $semesterId, string $weekStart): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE checklist_sessions
             SET closed_at = COALESCE(closed_at, NOW())
             WHERE geofence_id = :geofence_id
               AND semester_id = :semester_id
               AND week_start = :week_start"
        );
        $stmt->execute([
            'geofence_id' => $geofenceId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);
    }

    public function initializeWeekChecklistRows(int $sectionId, int $semesterId, string $weekStart, int $checkedBy): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO student_weekly_checklist
                (student_user_id, section_id, semester_id, week_start, checklist_item_id, is_checked, awarded_points, checked_by)
             SELECT
                ss.student_user_id,
                ss.section_id,
                ss.semester_id,
                :week_start,
                ci.id,
                0,
                0,
                :checked_by
             FROM section_students ss
             JOIN evaluation_checklist_items ci
               ON ci.is_active = 1
             WHERE ss.section_id = :section_id
               AND ss.semester_id = :semester_id
               AND ss.enrollment_status = 'active'
             ON DUPLICATE KEY UPDATE
                checked_by = VALUES(checked_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'week_start' => $weekStart,
            'checked_by' => $checkedBy,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->rowCount();
    }

    public function syncNewActiveItemsIntoWeek(int $sectionId, int $semesterId, string $weekStart, int $checkedBy): int
    {
        return $this->initializeWeekChecklistRows($sectionId, $semesterId, $weekStart, $checkedBy);
    }

    public function initializeWeekChecklistRowsByGeofence(int $geofenceId, int $semesterId, string $weekStart, int $checkedBy): int
    {
        $items = $this->getActiveChecklistItems($semesterId);
        if ($items === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO student_weekly_checklist
                (student_user_id, section_id, geofence_id, semester_id, week_start, checklist_item_id, is_checked, awarded_points, checked_by)
             SELECT
                sga.student_user_id,
                NULL,
                sga.geofence_id,
                :insert_semester_id,
                :insert_week_start,
                ci.id,
                0,
                0,
                :checked_by
              FROM student_geofence_assignments sga
              JOIN semester_students sems
                ON sems.student_user_id = sga.student_user_id
               AND sems.semester_id = :filter_semester_id
               AND sems.enrollment_status = 'active'
              JOIN semesters sem
                ON sem.id = sems.semester_id
               AND sem.semester_status = 'active'
              JOIN users u ON u.id = sga.student_user_id
              JOIN roles r ON r.id = u.role_id
             JOIN evaluation_checklist_items ci
               ON ci.is_active = 1
              AND (ci.semester_id = :item_semester_id OR ci.semester_id IS NULL)
              WHERE sga.geofence_id = :geofence_id
                AND sga.assignment_status = 'active'
                AND sga.semester_id = :assignment_semester_id
                AND r.role_code = 'student'
                AND u.account_status = 'active'
             ON DUPLICATE KEY UPDATE
                checked_by = VALUES(checked_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'insert_semester_id' => $semesterId,
            'insert_week_start' => $weekStart,
            'filter_semester_id' => $semesterId,
            'assignment_semester_id' => $semesterId,
            'checked_by' => $checkedBy,
            'item_semester_id' => $semesterId,
            'geofence_id' => $geofenceId,
        ]);

        return $stmt->rowCount();
    }

    public function syncNewActiveItemsIntoWeekByGeofence(int $geofenceId, int $semesterId, string $weekStart, int $checkedBy): int
    {
        return $this->initializeWeekChecklistRowsByGeofence($geofenceId, $semesterId, $weekStart, $checkedBy);
    }

    public function upsertStudentChecklistItem(
        int $studentUserId,
        int $sectionId,
        int $semesterId,
        string $weekStart,
        int $checklistItemId,
        int $isChecked,
        int $awardedPoints,
        int $checkedBy
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO student_weekly_checklist
                (student_user_id, section_id, semester_id, week_start, checklist_item_id, is_checked, awarded_points, checked_by, checked_at)
             VALUES
                (:student_user_id, :section_id, :semester_id, :week_start, :checklist_item_id, :is_checked, :awarded_points, :checked_by, NOW())
             ON DUPLICATE KEY UPDATE
                is_checked = VALUES(is_checked),
                awarded_points = VALUES(awarded_points),
                checked_by = VALUES(checked_by),
                checked_at = NOW(),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'checklist_item_id' => $checklistItemId,
            'is_checked' => $isChecked,
            'awarded_points' => $awardedPoints,
            'checked_by' => $checkedBy,
        ]);
    }

    public function getStudentChecklistItemRow(int $studentUserId, int $sectionId, int $semesterId, string $weekStart, int $checklistItemId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT swc.student_user_id, swc.section_id, swc.semester_id, swc.week_start, swc.checklist_item_id, swc.is_checked, swc.awarded_points,
                    cs.checklist_date, cs.closed_at
             FROM student_weekly_checklist swc
             LEFT JOIN checklist_sessions cs
               ON cs.section_id = swc.section_id
              AND cs.semester_id = swc.semester_id
              AND cs.week_start = swc.week_start
             WHERE swc.student_user_id = :student_user_id
               AND swc.section_id = :section_id
               AND swc.semester_id = :semester_id
               AND swc.week_start = :week_start
               AND swc.checklist_item_id = :checklist_item_id
             LIMIT 1"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'checklist_item_id' => $checklistItemId,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function upsertStudentChecklistItemByGeofence(
        int $studentUserId,
        int $geofenceId,
        int $semesterId,
        string $weekStart,
        int $checklistItemId,
        int $isChecked,
        int $awardedPoints,
        int $checkedBy
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO student_weekly_checklist
                (student_user_id, section_id, geofence_id, semester_id, week_start, checklist_item_id, is_checked, awarded_points, checked_by, checked_at)
             VALUES
                (:student_user_id, NULL, :geofence_id, :semester_id, :week_start, :checklist_item_id, :is_checked, :awarded_points, :checked_by, NOW())
             ON DUPLICATE KEY UPDATE
                is_checked = VALUES(is_checked),
                awarded_points = VALUES(awarded_points),
                checked_by = VALUES(checked_by),
                checked_at = NOW(),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'geofence_id' => $geofenceId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'checklist_item_id' => $checklistItemId,
            'is_checked' => $isChecked,
            'awarded_points' => $awardedPoints,
            'checked_by' => $checkedBy,
        ]);
    }

    public function getStudentChecklistItemRowByGeofence(int $studentUserId, int $geofenceId, int $semesterId, string $weekStart, int $checklistItemId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT swc.student_user_id, swc.geofence_id, swc.semester_id, swc.week_start, swc.checklist_item_id, swc.is_checked, swc.awarded_points,
                    cs.checklist_date, cs.closed_at
             FROM student_weekly_checklist swc
             LEFT JOIN checklist_sessions cs
               ON cs.geofence_id = swc.geofence_id
              AND cs.semester_id = swc.semester_id
              AND cs.week_start = swc.week_start
             WHERE swc.student_user_id = :student_user_id
               AND swc.geofence_id = :geofence_id
               AND swc.semester_id = :semester_id
               AND swc.week_start = :week_start
               AND swc.checklist_item_id = :checklist_item_id
             LIMIT 1"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'geofence_id' => $geofenceId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
            'checklist_item_id' => $checklistItemId,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getChecklistBySection(int $sectionId, int $semesterId, string $weekStart): array
    {
        $items = $this->getActiveChecklistItems();
        $rowsStmt = $this->pdo->prepare(
            "SELECT
                ss.student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                cs.checklist_date,
                cs.closed_at,
                COALESCE(SUM(swc.awarded_points), 0) AS total_points
             FROM section_students ss
             JOIN users u ON u.id = ss.student_user_id
             JOIN user_profiles up ON up.user_id = ss.student_user_id
             LEFT JOIN checklist_sessions cs
               ON cs.section_id = ss.section_id
              AND cs.semester_id = ss.semester_id
              AND cs.week_start = :week_start
             LEFT JOIN student_weekly_checklist swc
               ON swc.student_user_id = ss.student_user_id
              AND swc.section_id = ss.section_id
              AND swc.semester_id = ss.semester_id
              AND swc.week_start = :week_start_join
             WHERE ss.section_id = :section_id
               AND ss.semester_id = :semester_id
               AND ss.enrollment_status = 'active'
             GROUP BY ss.student_user_id, u.school_id, up.first_name, up.last_name, cs.checklist_date, cs.closed_at
             ORDER BY up.last_name ASC, up.first_name ASC"
        );
        $rowsStmt->execute([
            'week_start' => $weekStart,
            'week_start_join' => $weekStart,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);
        $rows = $rowsStmt->fetchAll();

        $itemStateStmt = $this->pdo->prepare(
            "SELECT swc.student_user_id, swc.checklist_item_id, swc.is_checked, swc.awarded_points
             FROM student_weekly_checklist swc
             WHERE swc.section_id = :section_id
               AND swc.semester_id = :semester_id
               AND swc.week_start = :week_start"
        );
        $itemStateStmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);

        $itemStates = [];
        foreach ($itemStateStmt->fetchAll() as $state) {
            $studentUserId = (int) ($state['student_user_id'] ?? 0);
            $checklistItemId = (int) ($state['checklist_item_id'] ?? 0);
            if ($studentUserId <= 0 || $checklistItemId <= 0) {
                continue;
            }
            $itemStates[$studentUserId][$checklistItemId] = [
                'is_checked' => (int) ($state['is_checked'] ?? 0),
                'awarded_points' => (int) ($state['awarded_points'] ?? 0),
            ];
        }

        foreach ($rows as &$row) {
            $studentUserId = (int) ($row['student_user_id'] ?? 0);
            $row['items'] = [];
            $checkedCount = 0;

            foreach ($items as $item) {
                $itemId = (int) ($item['id'] ?? 0);
                $state = $itemStates[$studentUserId][$itemId] ?? ['is_checked' => 0, 'awarded_points' => 0];
                $row['items'][] = [
                    'id' => $itemId,
                    'item_name' => (string) ($item['item_name'] ?? ''),
                    'points' => (int) ($item['points'] ?? 0),
                    'display_order' => (int) ($item['display_order'] ?? 0),
                    'is_checked' => (int) ($state['is_checked'] ?? 0),
                    'awarded_points' => (int) ($state['awarded_points'] ?? 0),
                ];
                if ((int) ($state['is_checked'] ?? 0) === 1) {
                    $checkedCount++;
                }
            }

            $row['checked_count'] = $checkedCount;
        }
        unset($row);

        return $rows;
    }

    public function getChecklistByGeofence(int $geofenceId, int $semesterId, string $weekStart): array
    {
        $items = $this->getActiveChecklistItems($semesterId);
        $rowsStmt = $this->pdo->prepare(
            "SELECT
                sga.student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                cs.checklist_date,
                cs.closed_at,
                csc.confirmed_at,
                COALESCE(SUM(swc.awarded_points), 0) AS total_points
              FROM student_geofence_assignments sga
              JOIN semester_students sems
                ON sems.student_user_id = sga.student_user_id
               AND sems.semester_id = :student_semester_id
               AND sems.enrollment_status = 'active'
              JOIN semesters sem
                ON sem.id = sems.semester_id
               AND sem.semester_status = 'active'
              JOIN user_profiles up
                ON up.user_id = sga.student_user_id
              JOIN users u ON u.id = sga.student_user_id
              JOIN roles r ON r.id = u.role_id
              LEFT JOIN checklist_sessions cs
                ON cs.geofence_id = sga.geofence_id
              AND cs.semester_id = :session_semester_id
              AND cs.week_start = :session_week_start
             LEFT JOIN checklist_student_confirmations csc
               ON csc.student_user_id = sga.student_user_id
              AND csc.geofence_id = sga.geofence_id
              AND csc.semester_id = :confirmation_semester_id
              AND csc.week_start = :confirmation_week_start
             LEFT JOIN student_weekly_checklist swc
               ON swc.student_user_id = sga.student_user_id
              AND swc.geofence_id = sga.geofence_id
              AND swc.semester_id = :semester_id_join
              AND swc.week_start = :week_start_join
              WHERE sga.geofence_id = :geofence_id
                AND sga.assignment_status = 'active'
                AND sga.semester_id = :assignment_semester_id
                AND r.role_code = 'student'
                AND u.account_status = 'active'
             GROUP BY sga.student_user_id, u.school_id, up.first_name, up.last_name, cs.checklist_date, cs.closed_at, csc.confirmed_at
             ORDER BY up.last_name ASC, up.first_name ASC"
        );
        $rowsStmt->execute([
            'student_semester_id' => $semesterId,
            'assignment_semester_id' => $semesterId,
            'session_semester_id' => $semesterId,
            'session_week_start' => $weekStart,
            'confirmation_semester_id' => $semesterId,
            'confirmation_week_start' => $weekStart,
            'semester_id_join' => $semesterId,
            'week_start_join' => $weekStart,
            'geofence_id' => $geofenceId,
        ]);
        $rows = $rowsStmt->fetchAll();

        $itemStateStmt = $this->pdo->prepare(
            "SELECT swc.student_user_id, swc.checklist_item_id, swc.is_checked, swc.awarded_points
             FROM student_weekly_checklist swc
             WHERE swc.geofence_id = :geofence_id
               AND swc.semester_id = :semester_id
               AND swc.week_start = :week_start"
        );
        $itemStateStmt->execute([
            'geofence_id' => $geofenceId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);

        $itemStates = [];
        foreach ($itemStateStmt->fetchAll() as $state) {
            $studentUserId = (int) ($state['student_user_id'] ?? 0);
            $checklistItemId = (int) ($state['checklist_item_id'] ?? 0);
            if ($studentUserId <= 0 || $checklistItemId <= 0) {
                continue;
            }
            $itemStates[$studentUserId][$checklistItemId] = [
                'is_checked' => (int) ($state['is_checked'] ?? 0),
                'awarded_points' => (int) ($state['awarded_points'] ?? 0),
            ];
        }

        foreach ($rows as &$row) {
            $studentUserId = (int) ($row['student_user_id'] ?? 0);
            $row['items'] = [];
            $checkedCount = 0;

            foreach ($items as $item) {
                $itemId = (int) ($item['id'] ?? 0);
                $state = $itemStates[$studentUserId][$itemId] ?? ['is_checked' => 0, 'awarded_points' => 0];
                $row['items'][] = [
                    'id' => $itemId,
                    'item_name' => (string) ($item['item_name'] ?? ''),
                    'points' => (int) ($item['points'] ?? 0),
                    'display_order' => (int) ($item['display_order'] ?? 0),
                    'is_checked' => (int) ($state['is_checked'] ?? 0),
                    'awarded_points' => (int) ($state['awarded_points'] ?? 0),
                ];
                if ((int) ($state['is_checked'] ?? 0) === 1) {
                    $checkedCount++;
                }
            }

            $row['checked_count'] = $checkedCount;
        }
        unset($row);

        return $rows;
    }
}
