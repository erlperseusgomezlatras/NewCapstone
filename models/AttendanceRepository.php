<?php

declare(strict_types=1);

final class AttendanceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getCoordinatorSectionContext(int $coordinatorUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                s.id AS section_id,
                s.section_code,
                s.section_name,
                s.capacity,
                s.section_status,
                sem.id AS semester_id,
                sem.semester_name,
                sem.semester_no,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                sem.semester_status,
                sy.id AS school_year_id,
                sy.year_label,
                sy.year_status,
                g.name AS school_name,
                ps.address AS school_address,
                sd.deployment_type
             FROM section_coordinators sc
             JOIN sections s ON s.id = sc.section_id
             JOIN semesters sem ON sem.id = s.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             LEFT JOIN section_deployments sd
               ON sd.section_id = s.id
              AND sd.deployment_status = 'running'
             LEFT JOIN geofence_locations g ON g.id = sd.geofence_id
             LEFT JOIN partner_schools ps ON ps.geofence_id = g.id
             WHERE sc.coordinator_user_id = :coordinator_user_id
               AND s.section_status = 'active'
               AND sem.semester_status = 'active'
               AND sy.year_status = 'active'
             ORDER BY
                CASE sem.semester_status
                    WHEN 'active' THEN 0
                    WHEN 'planned' THEN 1
                    ELSE 2
                END,
                sy.start_date DESC,
                sem.semester_no ASC,
                s.section_name ASC
             LIMIT 1"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getSectionEnrollmentCount(int $sectionId, int $semesterId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM section_students
             WHERE section_id = :section_id
               AND semester_id = :semester_id
               AND enrollment_status = 'active'"
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function getDailySectionKpi(int $sectionId, int $semesterId, string $attendanceDate): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN ar.student_user_id END) AS active_now,
                COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL THEN ar.student_user_id END) AS logged_today,
                COUNT(DISTINCT CASE WHEN ar.total_hours >= 8 THEN ar.student_user_id END) AS completed_8h
             FROM attendance_records ar
             WHERE ar.section_id = :section_id
               AND ar.semester_id = :semester_id
               AND ar.attendance_date = :attendance_date"
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
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
        $stmt = $this->pdo->prepare(
            "SELECT
                s.id AS section_id,
                s.section_code,
                s.section_name,
                s.capacity,
                g.name AS school_name,
                ps.address AS school_address,
                sd.deployment_type,
                COUNT(DISTINCT CASE WHEN ss.enrollment_status = 'active' THEN ss.student_user_id END) AS total_students,
                COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN ar.student_user_id END) AS active_now
             FROM sections s
             LEFT JOIN section_students ss
               ON ss.section_id = s.id
              AND ss.semester_id = s.semester_id
             LEFT JOIN attendance_records ar
               ON ar.section_id = s.id
              AND ar.semester_id = s.semester_id
              AND ar.attendance_date = :attendance_date
             LEFT JOIN section_deployments sd
               ON sd.section_id = s.id
              AND sd.deployment_status = 'running'
             LEFT JOIN geofence_locations g ON g.id = sd.geofence_id
             LEFT JOIN partner_schools ps ON ps.geofence_id = g.id
             WHERE s.id = :section_id
               AND s.semester_id = :semester_id
             GROUP BY
                s.id, s.section_code, s.section_name, s.capacity,
                g.name, ps.address, sd.deployment_type"
        );
        $stmt->execute([
            'attendance_date' => $attendanceDate,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function getRecentLoggedIn(int $sectionId, int $semesterId, string $attendanceDate, int $limit = 6): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                ar.student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                ar.time_in
             FROM attendance_records ar
             JOIN users u ON u.id = ar.student_user_id
             JOIN user_profiles up ON up.user_id = ar.student_user_id
             WHERE ar.section_id = :section_id
               AND ar.semester_id = :semester_id
               AND ar.attendance_date = :attendance_date
               AND ar.time_in IS NOT NULL
             ORDER BY ar.time_in DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'attendance_date' => $attendanceDate,
        ]);

        return $stmt->fetchAll();
    }

    public function getWeeklyAttendanceRows(int $sectionId, int $semesterId, string $weekStart, string $weekEnd): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                ss.student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN ar.total_hours ELSE 0 END), 0) AS mon_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN ar.total_hours ELSE 0 END), 0) AS tue_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN ar.total_hours ELSE 0 END), 0) AS wed_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN ar.total_hours ELSE 0 END), 0) AS thu_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.total_hours ELSE 0 END), 0) AS fri_hours,
                COALESCE(SUM(ar.total_hours), 0) AS total_hours,
                CASE
                    WHEN sj.id IS NULL THEN 'missing'
                    ELSE sj.status
                END AS journal_status,
                sj.id AS journal_id
             FROM section_students ss
             JOIN users u ON u.id = ss.student_user_id
             JOIN user_profiles up ON up.user_id = ss.student_user_id
             LEFT JOIN attendance_records ar
               ON ar.student_user_id = ss.student_user_id
              AND ar.section_id = ss.section_id
              AND ar.semester_id = ss.semester_id
              AND ar.attendance_date BETWEEN :week_start AND :week_end
             LEFT JOIN student_journals sj
               ON sj.student_user_id = ss.student_user_id
              AND sj.semester_id = ss.semester_id
              AND sj.week_start = :journal_week_start
             WHERE ss.section_id = :section_id
               AND ss.semester_id = :semester_id
               AND ss.enrollment_status = 'active'
             GROUP BY
                ss.student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                sj.id,
                sj.status
             ORDER BY up.last_name ASC, up.first_name ASC"
        );
        $stmt->execute([
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'journal_week_start' => $weekStart,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->fetchAll();
    }

    public function getCoordinatorSectionHistory(int $coordinatorUserId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                s.id AS section_id,
                s.section_code,
                s.section_name,
                sem.id AS semester_id,
                sem.semester_name,
                sem.semester_no,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                sem.semester_status,
                sy.year_label,
                COUNT(DISTINCT CASE WHEN ss.enrollment_status = 'active' THEN ss.student_user_id END) AS total_students,
                COALESCE(AVG(LEAST((totals.total_hours / 40) * 100, 100)), 0) AS completion_rate
             FROM section_coordinators sc
             JOIN sections s ON s.id = sc.section_id
             JOIN semesters sem ON sem.id = s.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             LEFT JOIN section_students ss
               ON ss.section_id = s.id
              AND ss.semester_id = s.semester_id
             LEFT JOIN (
                SELECT
                    section_id,
                    semester_id,
                    student_user_id,
                    SUM(total_hours) AS total_hours
                FROM attendance_records
                GROUP BY section_id, semester_id, student_user_id
             ) totals
               ON totals.section_id = ss.section_id
              AND totals.semester_id = ss.semester_id
              AND totals.student_user_id = ss.student_user_id
             WHERE sc.coordinator_user_id = :coordinator_user_id
             GROUP BY
                s.id, s.section_code, s.section_name,
                sem.id, sem.semester_name, sem.semester_no,
                sem.start_date, sem.end_date, sem.semester_status,
                sy.year_label
             ORDER BY sy.start_date DESC, sem.semester_no DESC, s.section_name ASC"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);

        return $stmt->fetchAll();
    }

    public function getCoordinatorHandledStudentsHistory(int $coordinatorUserId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                s.id AS section_id,
                s.section_code,
                s.section_name,
                sem.id AS semester_id,
                sem.semester_name,
                sem.semester_status,
                sy.year_label,
                ss.student_user_id,
                ss.enrollment_status,
                u.school_id,
                u.email,
                up.first_name,
                up.last_name,
                COALESCE(att.days_present, 0) AS days_present,
                COALESCE(att.total_hours, 0) AS total_hours
             FROM section_coordinators sc
             JOIN sections s ON s.id = sc.section_id
             JOIN semesters sem ON sem.id = s.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             JOIN section_students ss
               ON ss.section_id = s.id
              AND ss.semester_id = s.semester_id
             JOIN users u ON u.id = ss.student_user_id
             JOIN user_profiles up ON up.user_id = ss.student_user_id
             LEFT JOIN (
                SELECT
                    section_id,
                    semester_id,
                    student_user_id,
                    COUNT(DISTINCT attendance_date) AS days_present,
                    SUM(total_hours) AS total_hours
                FROM attendance_records
                GROUP BY section_id, semester_id, student_user_id
             ) att
               ON att.section_id = ss.section_id
              AND att.semester_id = ss.semester_id
              AND att.student_user_id = ss.student_user_id
             WHERE sc.coordinator_user_id = :coordinator_user_id
             ORDER BY
                sy.start_date DESC,
                sem.semester_no DESC,
                s.section_name ASC,
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

    public function insertAttendance(
        int $studentUserId,
        int $sectionId,
        int $semesterId,
        int $ojtTypeId,
        string $attendanceDate,
        string $timeIn,
        ?string $timeOut,
        float $totalHours,
        ?string $remarks
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_records
                (student_user_id, section_id, semester_id, ojt_type_id, attendance_date, time_in, time_out, total_hours, remarks)
             VALUES
                (:student_user_id, :section_id, :semester_id, :ojt_type_id, :attendance_date, :time_in, :time_out, :total_hours, :remarks)'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'ojt_type_id' => $ojtTypeId,
            'attendance_date' => $attendanceDate,
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            'total_hours' => $totalHours,
            'remarks' => $remarks,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function getWeeklyHours(int $studentUserId, string $weekStart, string $weekEnd): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(total_hours), 0)
             FROM attendance_records
             WHERE student_user_id = :student_user_id
               AND attendance_date BETWEEN :week_start AND :week_end'
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
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(ar.total_hours), 0)
             FROM attendance_records ar
             JOIN ojt_types ot ON ot.id = ar.ojt_type_id
             WHERE ar.student_user_id = :student_user_id
               AND ot.ojt_code = :ojt_code'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'ojt_code' => $ojtCode,
        ]);

        return (float) $stmt->fetchColumn();
    }

    public function getOverallHours(int $studentUserId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(total_hours), 0)
             FROM attendance_records
             WHERE student_user_id = :student_user_id'
        );
        $stmt->execute(['student_user_id' => $studentUserId]);

        return (float) $stmt->fetchColumn();
    }
}
