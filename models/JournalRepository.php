<?php

declare(strict_types=1);

final class JournalRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getSectionJournals(int $sectionId, int $semesterId, string $weekStart): array
    {
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
        $stmt->execute([
            'week_start' => $weekStart,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->fetchAll();
    }

    public function getJournalDetail(int $studentId, int $semesterId, string $weekStart): ?array
    {
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
               AND sj.week_start = :week_start
             LIMIT 1"
        );
        $stmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function updateJournalStatus(int $studentId, int $semesterId, string $weekStart, string $status, ?string $feedback): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE student_journals
             SET status = :status,
                 coordinator_feedback = :coordinator_feedback,
                 updated_at = CURRENT_TIMESTAMP
             WHERE student_user_id = :student_id
               AND semester_id = :semester_id
               AND week_start = :week_start"
        );
        $stmt->execute([
            'status' => $status,
            'coordinator_feedback' => $feedback,
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);
    }
}
