<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class SchoolYearService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
    }

    /**
     * Rule 1:
     * For 2026-2027 and term changes (1st/2nd/Summer), set all sections,
     * students, and teachers to inactive. Do not archive.
     */
    public function setTermInactiveState(string $schoolYearLabel, string $termName): void
    {
        $this->assertValidTerm($termName);
        $schoolYear = $this->getSchoolYearByLabel($schoolYearLabel);

        if (!$schoolYear) {
            throw new RuntimeException('School year not found: ' . $schoolYearLabel);
        }

        $schoolYearId = (int) $schoolYear['id'];

        $this->pdo->beginTransaction();
        try {
            // Mark selected term active (optional operational state), others inactive.
            $updateTerm = $this->pdo->prepare(
                'UPDATE school_terms
                 SET status = CASE WHEN term_name = :term_name THEN "active" ELSE "inactive" END
                 WHERE school_year_id = :school_year_id'
            );
            $updateTerm->execute([
                'term_name' => $termName,
                'school_year_id' => $schoolYearId,
            ]);

            // Required rule: all sections inactive for this school year.
            $sectionsStmt = $this->pdo->prepare(
                'UPDATE sections SET status = "inactive" WHERE school_year_id = :school_year_id'
            );
            $sectionsStmt->execute(['school_year_id' => $schoolYearId]);

            // Required rule: all students inactive for this school year.
            $studentStmt = $this->pdo->prepare(
                'UPDATE user_school_year_status ys
                 JOIN users u ON u.id = ys.user_id
                 SET ys.status = "inactive"
                 WHERE ys.school_year_id = :school_year_id AND u.role = "student"'
            );
            $studentStmt->execute(['school_year_id' => $schoolYearId]);

            // Required rule: all teachers inactive for this school year.
            $teacherStmt = $this->pdo->prepare(
                'UPDATE user_school_year_status ys
                 JOIN users u ON u.id = ys.user_id
                 SET ys.status = "inactive"
                 WHERE ys.school_year_id = :school_year_id AND u.role IN ("head_teacher", "coordinator")'
            );
            $teacherStmt->execute(['school_year_id' => $schoolYearId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Rule 2 and Rule 3:
     * - Archive all data from previous active school year
     * - Mark previous users archived/inactive
     * - Create clean new school year with inactive users/sections
     */
    public function startNewSchoolYear(string $newSchoolYearLabel, string $archivedBy): int
    {
        $existing = $this->getSchoolYearByLabel($newSchoolYearLabel);
        if ($existing) {
            throw new RuntimeException('School year already exists: ' . $newSchoolYearLabel);
        }

        $activeSchoolYear = $this->getActiveSchoolYear();
        if (!$activeSchoolYear) {
            throw new RuntimeException('No active school year found to archive.');
        }

        $prevSchoolYearId = (int) $activeSchoolYear['id'];
        $prevSchoolYearLabel = (string) $activeSchoolYear['year_label'];

        $this->pdo->beginTransaction();
        try {
            $this->archiveSchoolYearData($prevSchoolYearId, $prevSchoolYearLabel, $archivedBy);

            // Close previous school year.
            $archiveSchoolYearStmt = $this->pdo->prepare(
                'UPDATE school_years SET status = "archived" WHERE id = :id'
            );
            $archiveSchoolYearStmt->execute(['id' => $prevSchoolYearId]);

            // Archived users must not remain active.
            $archiveUsersStmt = $this->pdo->prepare(
                'UPDATE user_school_year_status
                 SET status = "archived"
                 WHERE school_year_id = :school_year_id'
            );
            $archiveUsersStmt->execute(['school_year_id' => $prevSchoolYearId]);

            // Ensure sections are not active in archived year.
            $archiveSectionsStmt = $this->pdo->prepare(
                'UPDATE sections SET status = "inactive" WHERE school_year_id = :school_year_id'
            );
            $archiveSectionsStmt->execute(['school_year_id' => $prevSchoolYearId]);

            // Create new school year (clean start).
            $newSchoolYearStmt = $this->pdo->prepare(
                'INSERT INTO school_years (year_label, status) VALUES (:year_label, "active")'
            );
            $newSchoolYearStmt->execute(['year_label' => $newSchoolYearLabel]);
            $newSchoolYearId = (int) $this->pdo->lastInsertId();

            // Create terms for new school year.
            $newTermsStmt = $this->pdo->prepare(
                'INSERT INTO school_terms (school_year_id, term_name, status)
                 VALUES (:school_year_id, :term_name, "inactive")'
            );
            foreach (['1st Semester', '2nd Semester', 'Summer'] as $termName) {
                $newTermsStmt->execute([
                    'school_year_id' => $newSchoolYearId,
                    'term_name' => $termName,
                ]);
            }

            // Create clean user-year status rows as inactive for new year.
            $cloneUserStatusStmt = $this->pdo->prepare(
                'INSERT INTO user_school_year_status (user_id, school_year_id, status)
                 SELECT id, :school_year_id, "inactive" FROM users'
            );
            $cloneUserStatusStmt->execute(['school_year_id' => $newSchoolYearId]);

            $this->pdo->commit();
            return $newSchoolYearId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * History remains viewable and immutable in history_school_year_archive.
     */
    private function archiveSchoolYearData(int $schoolYearId, string $schoolYearLabel, string $archivedBy): void
    {
        $sectionsStmt = $this->pdo->prepare(
            'SELECT id, section_name, status, term_id, created_at, updated_at
             FROM sections WHERE school_year_id = :school_year_id'
        );
        $sectionsStmt->execute(['school_year_id' => $schoolYearId]);
        $sections = $sectionsStmt->fetchAll();

        $usersStmt = $this->pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.role, ys.status AS school_year_status
             FROM users u
             JOIN user_school_year_status ys ON ys.user_id = u.id
             WHERE ys.school_year_id = :school_year_id'
        );
        $usersStmt->execute(['school_year_id' => $schoolYearId]);
        $users = $usersStmt->fetchAll();

        $termsStmt = $this->pdo->prepare(
            'SELECT id, term_name, status, created_at, updated_at
             FROM school_terms WHERE school_year_id = :school_year_id'
        );
        $termsStmt->execute(['school_year_id' => $schoolYearId]);
        $terms = $termsStmt->fetchAll();

        $payload = json_encode([
            'school_year' => [
                'id' => $schoolYearId,
                'label' => $schoolYearLabel,
            ],
            'terms' => $terms,
            'sections' => $sections,
            'users' => $users,
        ], JSON_THROW_ON_ERROR);

        $archiveStmt = $this->pdo->prepare(
            'INSERT INTO history_school_year_archive
                (source_school_year_id, source_school_year_label, archived_by, payload)
             VALUES
                (:source_school_year_id, :source_school_year_label, :archived_by, :payload)'
        );
        $archiveStmt->execute([
            'source_school_year_id' => $schoolYearId,
            'source_school_year_label' => $schoolYearLabel,
            'archived_by' => $archivedBy,
            'payload' => $payload,
        ]);
    }

    private function getSchoolYearByLabel(string $label): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, year_label, status FROM school_years WHERE year_label = :label LIMIT 1');
        $stmt->execute(['label' => $label]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getActiveSchoolYear(): ?array
    {
        $stmt = $this->pdo->query('SELECT id, year_label, status FROM school_years WHERE status = "active" ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function assertValidTerm(string $termName): void
    {
        $valid = ['1st Semester', '2nd Semester', 'Summer'];
        if (!in_array($termName, $valid, true)) {
            throw new InvalidArgumentException('Invalid term name: ' . $termName);
        }
    }
}

