<?php

declare(strict_types=1);

/**
 * Keeps semester_status as the single source of truth for the active
 * academic cycle. school_years.year_status is a derived summary only.
 */
final class SemesterLifecycleIntegrityService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Repairs stale lifecycle flags without deleting historical records.
     * When legacy data contains more than one active semester, the most
     * recently updated active row is retained and the others are archived.
     *
     * @return array{active_semester_id:?int,archived_semesters:int,updated_school_years:int}
     */
    public function repair(): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $activeRows = $this->pdo->query(
                "SELECT id, school_year_id
                 FROM semesters
                 WHERE semester_status = 'active'
                 ORDER BY updated_at DESC, id DESC
                 FOR UPDATE"
            )->fetchAll(PDO::FETCH_ASSOC);

            $canonicalSemesterId = isset($activeRows[0]) ? (int) $activeRows[0]['id'] : null;
            $canonicalSchoolYearId = isset($activeRows[0]) ? (int) $activeRows[0]['school_year_id'] : 0;
            $archivedSemesters = 0;

            if ($canonicalSemesterId !== null && count($activeRows) > 1) {
                $archiveDuplicates = $this->pdo->prepare(
                    "UPDATE semesters
                     SET semester_status = 'archived',
                         practicum_status = 'ended',
                         completed_at = COALESCE(completed_at, NOW())
                     WHERE semester_status = 'active'
                       AND id <> :semester_id"
                );
                $archiveDuplicates->execute(['semester_id' => $canonicalSemesterId]);
                $archivedSemesters = $archiveDuplicates->rowCount();
            }

            $yearStatusSql = "
                UPDATE school_years sy
                SET year_status = CASE
                    WHEN :active_school_year_id > 0 AND sy.id = :active_school_year_id_duplicate THEN 'active'
                    WHEN EXISTS (
                        SELECT 1
                        FROM semesters planned_sem
                        WHERE planned_sem.school_year_id = sy.id
                          AND planned_sem.semester_status = 'planned'
                    ) THEN 'planned'
                    ELSE 'archived'
                END
                WHERE sy.year_status <> CASE
                    WHEN :active_school_year_id_compare > 0 AND sy.id = :active_school_year_id_compare_duplicate THEN 'active'
                    WHEN EXISTS (
                        SELECT 1
                        FROM semesters planned_sem_compare
                        WHERE planned_sem_compare.school_year_id = sy.id
                          AND planned_sem_compare.semester_status = 'planned'
                    ) THEN 'planned'
                    ELSE 'archived'
                END
            ";
            $syncYears = $this->pdo->prepare($yearStatusSql);
            $syncYears->execute([
                'active_school_year_id' => $canonicalSchoolYearId,
                'active_school_year_id_duplicate' => $canonicalSchoolYearId,
                'active_school_year_id_compare' => $canonicalSchoolYearId,
                'active_school_year_id_compare_duplicate' => $canonicalSchoolYearId,
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return [
                'active_semester_id' => $canonicalSemesterId,
                'archived_semesters' => $archivedSemesters,
                'updated_school_years' => $syncYears->rowCount(),
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
