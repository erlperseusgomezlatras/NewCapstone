<?php

declare(strict_types=1);

final class AcademicRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createSchoolYear(string $label, string $startDate, string $endDate, string $status = 'planned'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO school_years (year_label, start_date, end_date, year_status)
             VALUES (:year_label, :start_date, :end_date, :year_status)'
        );
        $stmt->execute([
            'year_label' => $label,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'year_status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createSemester(
        int $schoolYearId,
        int $semesterNo,
        string $semesterName,
        string $startDate,
        string $endDate,
        string $status = 'planned'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO semesters (school_year_id, semester_no, semester_name, start_date, end_date, semester_status)
             VALUES (:school_year_id, :semester_no, :semester_name, :start_date, :end_date, :semester_status)'
        );
        $stmt->execute([
            'school_year_id' => $schoolYearId,
            'semester_no' => $semesterNo,
            'semester_name' => $semesterName,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'semester_status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createSection(
        int $semesterId,
        string $sectionCode,
        string $sectionName,
        int $capacity,
        string $status = 'active'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (semester_id, section_code, section_name, capacity, section_status)
             VALUES (:semester_id, :section_code, :section_name, :capacity, :section_status)'
        );
        $stmt->execute([
            'semester_id' => $semesterId,
            'section_code' => $sectionCode,
            'section_name' => $sectionName,
            'capacity' => $capacity,
            'section_status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function getSemesterIdBySectionId(int $sectionId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT semester_id FROM sections WHERE id = :section_id LIMIT 1');
        $stmt->execute(['section_id' => $sectionId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    public function assignCoordinator(int $sectionId, int $coordinatorUserId): void
    {
        $this->pdo->prepare('DELETE FROM section_coordinators WHERE section_id = :section_id')
            ->execute(['section_id' => $sectionId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO section_coordinators (section_id, coordinator_user_id)
             VALUES (:section_id, :coordinator_user_id)'
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'coordinator_user_id' => $coordinatorUserId,
        ]);
    }

    public function enrollStudent(int $sectionId, int $semesterId, int $studentUserId, string $status = 'active'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO section_students (section_id, semester_id, student_user_id, enrollment_status)
             VALUES (:section_id, :semester_id, :student_user_id, :enrollment_status)'
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'student_user_id' => $studentUserId,
            'enrollment_status' => $status,
        ]);
    }
}

