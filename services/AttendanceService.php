<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AttendanceRepository.php';

final class AttendanceService
{
    private PDO $pdo;
    private AttendanceRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->repository = new AttendanceRepository($this->pdo);
    }

    public function recordTimeIn(int $studentUserId, float $latitude, float $longitude): array
    {
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student_user_id');
        }
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Invalid latitude/longitude');
        }

        $now = new DateTimeImmutable('now');
        if ((int) $now->format('N') > 5) {
            throw new RuntimeException('Attendance allowed only Monday-Friday');
        }
        $today = $now->format('Y-m-d');

        $this->pdo->beginTransaction();
        try {
            $existing = $this->findTodayAttendanceForUpdate($studentUserId, $today);
            if ($existing !== null) {
                throw new RuntimeException('Duplicate attendance: already timed in today');
            }

            $enrollment = $this->getActiveEnrollment($studentUserId);
            if ($enrollment === null) {
                throw new RuntimeException('Student is not enrolled in an active section');
            }

            $geofence = $this->getNearestAssignedGeofence($studentUserId, $latitude, $longitude);
            if ($geofence === null) {
                throw new RuntimeException('No active geofence assignment found');
            }
            if ((int) $geofence['inside_radius']                                                                                                             !== 1) {
                throw new RuntimeException('Time-in denied: outside assigned geofence radius');
            }

            $ojtCode = ((string) $geofence['school_type']) === 'public_school' ? 'public_school' : 'private_school';
            $ojtTypeId = $this->getOjtTypeIdByCode($ojtCode);
            if ($ojtTypeId === null) {
                throw new RuntimeException('OJT type not configured');
            }

            $attendanceId = $this->insertAttendanceRecord(
                $studentUserId,
                (int) $enrollment['section_id'],
                (int) $enrollment['semester_id'],
                $ojtTypeId,
                $today,
                $now->format('Y-m-d H:i:s')
            );

            $this->insertAttendanceSession($attendanceId, 'time_in', $now->format('Y-m-d H:i:s'));
            $this->insertGeofenceLog(
                $attendanceId,
                (int) $geofence['geofence_id'],
                $latitude,
                $longitude,
                (float) $geofence['distance_from_center'],
                1
            );

            $this->pdo->commit();
            return [
                'attendance_id' => $attendanceId,
                'student_user_id' => $studentUserId,
                'attendance_date' => $today,
                'time_in' => $now->format('Y-m-d H:i:s'),
                'section_id' => (int) $enrollment['section_id'],
                'semester_id' => (int) $enrollment['semester_id'],
                'ojt_type_code' => $ojtCode,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function recordTimeOut(int $studentUserId): array
    {
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student_user_id');
        }

        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');

        $this->pdo->beginTransaction();
        try {
            $attendance = $this->findTodayAttendanceForUpdate($studentUserId, $today);
            if ($attendance === null) {
                throw new RuntimeException('No time-in record found for today');
            }
            if (!empty($attendance['time_out'])) {
                throw new RuntimeException('Time-out already recorded for today');
            }

            $timeIn = new DateTimeImmutable((string) $attendance['time_in']);
            if ($now < $timeIn) {
                throw new RuntimeException('Time-out cannot be earlier than time-in');
            }

            $totalHours = round(($now->getTimestamp() - $timeIn->getTimestamp()) / 3600, 2);
            if ($totalHours < 0) {
                throw new RuntimeException('Computed total_hours is invalid');
            }

            $this->updateTimeOut((int) $attendance['id'], $now->format('Y-m-d H:i:s'), $totalHours);
            $this->insertAttendanceSession((int) $attendance['id'], 'time_out', $now->format('Y-m-d H:i:s'));

            $this->pdo->commit();
            return [
                'attendance_id' => (int) $attendance['id'],
                'student_user_id' => $studentUserId,
                'time_in' => (string) $attendance['time_in'],
                'time_out' => $now->format('Y-m-d H:i:s'),
                'total_hours' => $totalHours,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function weeklyHours(int $studentUserId, string $weekStart, string $weekEnd): array
    {
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student_id');
        }
        $this->assertValidDate($weekStart, 'week_start');
        $this->assertValidDate($weekEnd, 'week_end');

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(total_hours), 0) AS weekly_total_hours
             FROM attendance_records
             WHERE student_user_id = :student_user_id
               AND attendance_date BETWEEN :week_start AND :week_end'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
        ]);

        return [
            'student_id' => $studentUserId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'weekly_total_hours' => (float) $stmt->fetchColumn(),
        ];
    }

    public function totalPublicHours(int $studentUserId): array
    {
        return [
            'student_id' => $studentUserId,
            'total_public_hours' => $this->getTotalHoursByOjtCode($studentUserId, 'public_school'),
        ];
    }

    public function totalPrivateHours(int $studentUserId): array
    {
        return [
            'student_id' => $studentUserId,
            'total_private_hours' => $this->getTotalHoursByOjtCode($studentUserId, 'private_school'),
        ];
    }

    public function overallHours(int $studentUserId): array
    {
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student_id');
        }

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(total_hours), 0) AS overall_total_hours
             FROM attendance_records
             WHERE student_user_id = :student_user_id'
        );
        $stmt->execute(['student_user_id' => $studentUserId]);

        return [
            'student_id' => $studentUserId,
            'overall_total_hours' => (float) $stmt->fetchColumn(),
        ];
    }

    public function sectionSummary(int $sectionId, int $semesterId): array
    {
        if ($sectionId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('Invalid section_id or semester_id');
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                ss.section_id,
                s.section_code,
                s.section_name,
                ss.student_user_id,
                up.last_name,
                up.first_name,
                COALESCE(SUM(ar.total_hours), 0) AS total_hours
             FROM section_students ss
             JOIN sections s ON s.id = ss.section_id
             JOIN user_profiles up ON up.user_id = ss.student_user_id
             LEFT JOIN attendance_records ar
                ON ar.section_id = ss.section_id
               AND ar.semester_id = ss.semester_id
               AND ar.student_user_id = ss.student_user_id
             WHERE ss.section_id = :section_id
               AND ss.semester_id = :semester_id
             GROUP BY
                ss.section_id,
                s.section_code,
                s.section_name,
                ss.student_user_id,
                up.last_name,
                up.first_name
             ORDER BY up.last_name, up.first_name'
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
        ]);

        return [
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'rows' => $stmt->fetchAll(),
        ];
    }

    public function getCoordinatorSectionContext(int $coordinatorUserId): ?array
    {
        if ($coordinatorUserId <= 0) {
            throw new InvalidArgumentException('Invalid coordinator_user_id');
        }

        $context = $this->repository->getCoordinatorSectionContext($coordinatorUserId);
        if ($context === null) {
            return null;
        }

        $context['total_students'] = $this->repository->getSectionEnrollmentCount(
            (int) $context['section_id'],
            (int) $context['semester_id']
        );

        return $context;
    }

    public function getCoordinatorDashboardData(int $coordinatorUserId, string $todayDate, string $weekStart, string $weekEnd): array
    {
        $this->assertValidDate($todayDate, 'today_date');
        $this->assertValidDate($weekStart, 'week_start');
        $this->assertValidDate($weekEnd, 'week_end');

        $context = $this->getCoordinatorSectionContext($coordinatorUserId);
        if ($context === null) {
            return [
                'context' => null,
                'summary' => [
                    'active_now' => 0,
                    'logged_today' => 0,
                    'completed_8h' => 0,
                    'total_students' => 0,
                ],
                'live_section' => null,
                'recent_logged_in' => [],
                'weekly_rows' => [],
            ];
        }

        $sectionId = (int) $context['section_id'];
        $semesterId = (int) $context['semester_id'];
        $summary = $this->repository->getDailySectionKpi($sectionId, $semesterId, $todayDate);
        $summary['total_students'] = (int) ($context['total_students'] ?? 0);

        return [
            'context' => $context,
            'summary' => $summary,
            'live_section' => $this->repository->getLiveSectionStatus($sectionId, $semesterId, $todayDate),
            'recent_logged_in' => $this->repository->getRecentLoggedIn($sectionId, $semesterId, $todayDate),
            'weekly_rows' => $this->repository->getWeeklyAttendanceRows($sectionId, $semesterId, $weekStart, $weekEnd),
        ];
    }

    public function getCoordinatorSectionHistory(int $coordinatorUserId): array
    {
        if ($coordinatorUserId <= 0) {
            throw new InvalidArgumentException('Invalid coordinator_user_id');
        }

        return $this->repository->getCoordinatorSectionHistory($coordinatorUserId);
    }

    public function getCoordinatorHandledStudentsHistory(int $coordinatorUserId): array
    {
        if ($coordinatorUserId <= 0) {
            throw new InvalidArgumentException('Invalid coordinator_user_id');
        }

        return $this->repository->getCoordinatorHandledStudentsHistory($coordinatorUserId);
    }

    private function getActiveEnrollment(int $studentUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                ss.section_id,
                ss.semester_id
             FROM section_students ss
             JOIN sections s ON s.id = ss.section_id
             JOIN semesters sem ON sem.id = ss.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             WHERE ss.student_user_id = :student_user_id
               AND ss.enrollment_status = \'active\'
               AND s.section_status = \'active\'
               AND sem.semester_status = \'active\'
               AND sy.year_status = \'active\'
             ORDER BY sy.start_date DESC, sem.start_date DESC
             LIMIT 1'
        );
        $stmt->execute(['student_user_id' => $studentUserId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function findTodayAttendanceForUpdate(int $studentUserId, string $date): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, time_in, time_out
             FROM attendance_records
             WHERE student_user_id = :student_user_id
               AND attendance_date = :attendance_date
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'attendance_date' => $date,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function getNearestAssignedGeofence(int $studentUserId, float $latitude, float $longitude): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                g.id AS geofence_id,
                g.school_type,
                g.radius_meters,
                6371000 * 2 * ASIN(
                    SQRT(
                        POWER(SIN(RADIANS((:lat - g.latitude) / 2)), 2)
                        + COS(RADIANS(g.latitude))
                        * COS(RADIANS(:lat))
                        * POWER(SIN(RADIANS((:lng - g.longitude) / 2)), 2)
                    )
                ) AS distance_from_center,
                CASE
                    WHEN (
                        6371000 * 2 * ASIN(
                            SQRT(
                                POWER(SIN(RADIANS((:lat2 - g.latitude) / 2)), 2)
                                + COS(RADIANS(g.latitude))
                                * COS(RADIANS(:lat2))
                                * POWER(SIN(RADIANS((:lng2 - g.longitude) / 2)), 2)
                            )
                        )
                    ) <= g.radius_meters THEN 1 ELSE 0
                END AS inside_radius
             FROM geofence_locations g
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = g.id
              AND sga.student_user_id = :student_user_id
              AND sga.assignment_status = \'active\'
             WHERE g.is_active = 1
             ORDER BY inside_radius DESC, distance_from_center ASC
             LIMIT 1'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'lat' => $latitude,
            'lng' => $longitude,
            'lat2' => $latitude,
            'lng2' => $longitude,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function getOjtTypeIdByCode(string $ojtCode): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM ojt_types
             WHERE ojt_code = :ojt_code
               AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['ojt_code' => $ojtCode]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function insertAttendanceRecord(
        int $studentUserId,
        int $sectionId,
        int $semesterId,
        int $ojtTypeId,
        string $attendanceDate,
        string $timeIn
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_records
                (student_user_id, section_id, semester_id, ojt_type_id, attendance_date, time_in, time_out, total_hours, remarks)
             VALUES
                (:student_user_id, :section_id, :semester_id, :ojt_type_id, :attendance_date, :time_in, NULL, 0.00, NULL)'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'ojt_type_id' => $ojtTypeId,
            'attendance_date' => $attendanceDate,
            'time_in' => $timeIn,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function updateTimeOut(int $attendanceId, string $timeOut, float $totalHours): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE attendance_records
             SET time_out = :time_out,
                 total_hours = :total_hours,
                 updated_at = NOW()
             WHERE id = :attendance_id'
        );
        $stmt->execute([
            'attendance_id' => $attendanceId,
            'time_out' => $timeOut,
            'total_hours' => $totalHours,
        ]);
    }

    private function insertAttendanceSession(int $attendanceId, string $eventType, string $eventTime): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_sessions (attendance_id, event_type, event_time, source)
             VALUES (:attendance_id, :event_type, :event_time, :source)'
        );
        $stmt->execute([
            'attendance_id' => $attendanceId,
            'event_type' => $eventType,
            'event_time' => $eventTime,
            'source' => 'system',
        ]);
    }

    private function insertGeofenceLog(
        int $attendanceId,
        int $geofenceId,
        float $latitude,
        float $longitude,
        float $distanceFromCenter,
        int $insideRadius
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_geofence_logs
                (attendance_id, geofence_id, latitude, longitude, distance_from_center, inside_radius)
             VALUES
                (:attendance_id, :geofence_id, :latitude, :longitude, :distance_from_center, :inside_radius)'
        );
        $stmt->execute([
            'attendance_id' => $attendanceId,
            'geofence_id' => $geofenceId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'distance_from_center' => $distanceFromCenter,
            'inside_radius' => $insideRadius,
        ]);
    }

    private function getTotalHoursByOjtCode(int $studentUserId, string $ojtCode): float
    {
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student_id');
        }

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

    private function assertValidDate(string $date, string $field): void
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid {$field}");
        }
    }
}
