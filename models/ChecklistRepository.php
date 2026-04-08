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

    public function createChecklistTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS checklist_progress (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_id BIGINT UNSIGNED NOT NULL,
                section_id BIGINT UNSIGNED NOT NULL,
                semester_id INT UNSIGNED NOT NULL,
                week DATE NOT NULL,
                orientation TINYINT(1) NOT NULL DEFAULT 0,
                uniform TINYINT(1) NOT NULL DEFAULT 0,
                observation TINYINT(1) NOT NULL DEFAULT 0,
                demo TINYINT(1) NOT NULL DEFAULT 0,
                portfolio TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_checklist_student_week (student_id, semester_id, week),
                KEY idx_checklist_section_week (section_id, semester_id, week),
                CONSTRAINT fk_checklist_progress_student
                    FOREIGN KEY (student_id) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_checklist_progress_section
                    FOREIGN KEY (section_id) REFERENCES sections(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_checklist_progress_semester
                    FOREIGN KEY (semester_id) REFERENCES semesters(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB"
        );
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

    public function ensureWorkflowColumns(): void
    {
        if (!$this->columnExists('checklist_progress', 'checklist_date')) {
            $this->pdo->exec(
                "ALTER TABLE checklist_progress
                 ADD COLUMN checklist_date DATE NULL AFTER week"
            );
        }

        if (!$this->columnExists('checklist_progress', 'closed_at')) {
            $this->pdo->exec(
                "ALTER TABLE checklist_progress
                 ADD COLUMN closed_at DATETIME NULL AFTER checklist_date"
            );
        }
    }

    public function getSectionSemesterId(int $sectionId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT semester_id FROM sections WHERE id = :section_id LIMIT 1');
        $stmt->execute(['section_id' => $sectionId]);
        $semesterId = $stmt->fetchColumn();

        return $semesterId !== false ? (int) $semesterId : null;
    }

    public function initializeWeekChecklist(int $sectionId, int $semesterId, string $week, string $checklistDate): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO checklist_progress
                (student_id, section_id, semester_id, week, checklist_date, closed_at, orientation, uniform, observation, demo, portfolio)
             SELECT
                ss.student_user_id,
                ss.section_id,
                ss.semester_id,
                :week,
                :checklist_date,
                NULL,
                0, 0, 0, 0, 0
             FROM section_students ss
             WHERE ss.section_id = :section_id
               AND ss.semester_id = :semester_id
               AND ss.enrollment_status = 'active'
             ON DUPLICATE KEY UPDATE
                updated_at = updated_at"
        );
        $stmt->execute([
            'week' => $week,
            'checklist_date' => $checklistDate,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->rowCount();
    }

    public function updateChecklistItem(int $studentId, string $week, string $field, int $value): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE checklist_progress
             SET {$field} = :value,
                 updated_at = CURRENT_TIMESTAMP
             WHERE student_id = :student_id
               AND week = :week"
        );
        $stmt->execute([
            'value' => $value,
            'student_id' => $studentId,
            'week' => $week,
        ]);
    }

    public function getChecklistBySection(int $sectionId, int $semesterId, string $week): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                ss.student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                cp.week,
                cp.checklist_date,
                cp.closed_at,
                cp.orientation,
                cp.uniform,
                cp.observation,
                cp.demo,
                cp.portfolio
             FROM section_students ss
             JOIN users u ON u.id = ss.student_user_id
             JOIN user_profiles up ON up.user_id = ss.student_user_id
             LEFT JOIN checklist_progress cp
               ON cp.student_id = ss.student_user_id
              AND cp.section_id = ss.section_id
              AND cp.semester_id = ss.semester_id
              AND cp.week = :week
             WHERE ss.section_id = :section_id
               AND ss.semester_id = :semester_id
               AND ss.enrollment_status = 'active'
             ORDER BY up.last_name ASC, up.first_name ASC"
        );
        $stmt->execute([
            'week' => $week,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->fetchAll();
    }

    public function getChecklistSession(int $sectionId, int $semesterId, string $week): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                MIN(checklist_date) AS checklist_date,
                MAX(closed_at) AS closed_at,
                COUNT(*) AS total_rows
             FROM checklist_progress
             WHERE section_id = :section_id
               AND semester_id = :semester_id
               AND week = :week"
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'week' => $week,
        ]);
        $row = $stmt->fetch();
        if ($row === false || (int) ($row['total_rows'] ?? 0) === 0) {
            return null;
        }

        return $row;
    }

    public function closeChecklistSession(int $sectionId, int $semesterId, string $week): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE checklist_progress
             SET closed_at = COALESCE(closed_at, NOW())
             WHERE section_id = :section_id
               AND semester_id = :semester_id
               AND week = :week"
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'week' => $week,
        ]);
    }

    public function getStudentChecklistRow(int $studentId, string $week): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                student_id,
                week,
                checklist_date,
                closed_at,
                orientation,
                uniform,
                observation,
                demo,
                portfolio
             FROM checklist_progress
             WHERE student_id = :student_id
               AND week = :week
             LIMIT 1"
        );
        $stmt->execute([
            'student_id' => $studentId,
            'week' => $week,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }
}
