<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PracticumWeekService.php';
require_once __DIR__ . '/../models/AttendanceRepository.php';
require_once __DIR__ . '/AttendanceStatusService.php';
require_once __DIR__ . '/SystemHolidayService.php';

final class AttendanceService
{
    public const DAILY_CAP_HOURS = 8.0;
    public const PUBLIC_REQUIRED_HOURS = 180.0;
    public const PRIVATE_REQUIRED_HOURS = 180.0;
    public const TOTAL_REQUIRED_HOURS = 180.0;

    private PDO $pdo;
    private AttendanceRepository $repository;
    private SystemHolidayService $holidayService;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        practicumAutoActivateScheduled($this->pdo);
        $this->repository = new AttendanceRepository($this->pdo);
        $this->repository->ensureEmergencyOverrideSchema();
        $this->holidayService = new SystemHolidayService($this->pdo);
        // Request-time safety net. A cron task also invokes this process, but
        // no stale open session can survive until the next scheduled run.
        $this->finalizeExpiredOpenAttendanceRecords();
    }

    /**
     * Finalize missed time-outs at the end of the attendance date. A student
     * who timed in but forgot to time out is an INCA case requiring review.
     * It receives no credit until a coordinator approves it.
     */
    private function finalizeExpiredOpenAttendanceRecords(): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE attendance_records
             SET time_out = TIMESTAMP(attendance_date, '23:59:59'),
                 total_hours = 0.00,
                 attendance_status = 'INCA',
                 remarks = 'Auto-closed: Missing timeout',
                 updated_at = NOW()
             WHERE attendance_date < CURDATE()
               AND time_in IS NOT NULL
               AND time_out IS NULL
               AND COALESCE(is_override, 0) = 0"
        );
        $stmt->execute();
        $recovered = $stmt->rowCount();
        if ($recovered > 0) {
            error_log("Recovered open attendance session(s): {$recovered}");
        }

        $this->pdo->exec(
            "INSERT INTO attendance_sessions (attendance_id, event_type, event_time, source)
             SELECT ar.id, 'time_out', ar.time_out, 'system'
             FROM attendance_records ar
             WHERE ar.attendance_date < CURDATE()
               AND ar.time_out IS NOT NULL
               AND ar.remarks = 'Auto-closed: Missing timeout'
               AND NOT EXISTS (
                   SELECT 1
                   FROM attendance_sessions existing
                   WHERE existing.attendance_id = ar.id
                     AND existing.event_type = 'time_out'
               )"
        );
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
        if ($this->holidayService->isHoliday($today)) {
            throw new RuntimeException('Today is a system-wide school holiday. Attendance cannot be recorded and no hours will be credited.');
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->findTodayAttendanceForUpdate($studentUserId, $today);
            if ($existing !== null) {
                throw new RuntimeException('Duplicate attendance: already timed in today');
            }

            $enrollment = $this->getActiveEnrollment($studentUserId);
            if ($enrollment === null) {
                throw new RuntimeException('Student is not enrolled in an active semester');
            }
            if (!$this->isDateInActiveSemesterWeek($today, $enrollment)) {
                throw new RuntimeException('Time-in denied: attendance is allowed only during the active semester week');
            }

            $this->repository->syncStudentDeploymentProgress($studentUserId, (int) $enrollment['semester_id']);
            $deployments = $this->repository->getStudentDeployments($studentUserId, (int) $enrollment['semester_id']);
            $geofence = $this->resolveDeploymentGeofence($deployments, $latitude, $longitude);
            if ($geofence === null) {
                throw new RuntimeException('No active geofence assignment found');
            }
            if (($geofence['deployment_status'] ?? '') === 'locked') {
                throw new RuntimeException('You must complete 180 hours at your first assigned school before starting your practicum at the next school.');
            }
            if (($geofence['deployment_status'] ?? '') === 'completed') {
                throw new RuntimeException('You have completed the required 180 hours for this school. Please proceed to your next assigned school.');
            }
            $timeInStart = substr((string) ($geofence['time_in_start'] ?? '06:00:00'), 0, 5);
            if (!preg_match('/^(?:0\d|1[0-5]):00$/', $timeInStart)) {
                $timeInStart = '06:00';
            }
            if ($now->format('H:i') < $timeInStart) {
                throw new RuntimeException('Time in for this school is available from ' . (new DateTimeImmutable($timeInStart))->format('g:i A') . '.');
            }
            if ($this->holidayService->isHoliday($today, (int) $geofence['geofence_id'])) {
                throw new RuntimeException('Today is a school holiday or closure at your assigned school. Attendance cannot be recorded and no hours will be credited.');
            }
            if ((int) $geofence['inside_radius'] !== 1) {
                throw new RuntimeException('You are outside your assigned practicum location. Please enter the allowed area before starting your attendance.');
            }

            $ojtCode = ((string) $geofence['school_type']) === 'public_school' ? 'public_school' : 'private_school';
            $ojtTypeId = $this->getOjtTypeIdByCode($ojtCode);
            if ($ojtTypeId === null) {
                throw new RuntimeException('OJT type not configured');
            }

            $attendanceId = $this->insertAttendanceRecord(
                $studentUserId,
                isset($enrollment['section_id']) && $enrollment['section_id'] !== null && $enrollment['section_id'] !== ''
                    ? (int) $enrollment['section_id']
                    : null,
                (int) $enrollment['semester_id'],
                $ojtTypeId,
                $today,
                $now->format('Y-m-d H:i:s'),
                isset($geofence['deployment_id']) ? (int) $geofence['deployment_id'] : null
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

    public function recordTimeOut(int $studentUserId, ?float $latitude = null, ?float $longitude = null): array
    {
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student_user_id');
        }

        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');
        if ($this->holidayService->isHoliday($today)) {
            throw new RuntimeException('Today is a system-wide school holiday. Time-out is unavailable and no practicum hours are credited.');
        }

        $this->pdo->beginTransaction();
        try {
            $attendance = $this->findTodayAttendanceForUpdate($studentUserId, $today);
            if ($attendance === null) {
                throw new RuntimeException('No time-in record found for today');
            }
            if (!empty($attendance['deployment_id'])) {
                $schoolStmt = $this->pdo->prepare(
                    'SELECT geofence_id
                     FROM student_school_deployments
                     WHERE id = :deployment_id
                     LIMIT 1'
                );
                $schoolStmt->execute(['deployment_id' => (int) $attendance['deployment_id']]);
                $geofenceId = (int) ($schoolStmt->fetchColumn() ?: 0);
                if ($geofenceId > 0 && $this->holidayService->isHoliday($today, $geofenceId)) {
                    throw new RuntimeException('Today is a school holiday or closure at your assigned school. Time-out is unavailable and no hours will be credited.');
                }
            }
            if (!empty($attendance['time_out'])) {
                throw new RuntimeException('Time-out already recorded for today');
            }

            if ($latitude !== null && $longitude !== null) {
                $this->recordStudentGeofenceHeartbeat($studentUserId, $today, $latitude, $longitude);
                $attendance = $this->findTodayAttendanceForUpdate($studentUserId, $today) ?? $attendance;
            }

            $enrollment = $this->getActiveEnrollment($studentUserId);
            if ($enrollment === null) {
                throw new RuntimeException('Student is not enrolled in an active semester');
            }
            if (!$this->isDateInActiveSemesterWeek($today, $enrollment)) {
                throw new RuntimeException('Time-out denied: attendance is allowed only during the active practicum week');
            }

            $timeIn = new DateTimeImmutable((string) $attendance['time_in']);
            if ($now < $timeIn) {
                throw new RuntimeException('Time-out cannot be earlier than time-in');
            }

            // Include the seconds since the latest heartbeat so time between
            // the last browser update and time-out is persisted.
            $elapsedSeconds = (int) ($attendance['geofence_seconds'] ?? 0);
            if ((int) ($attendance['geofence_inside'] ?? 0) === 1 && !empty($attendance['geofence_last_seen_at'])) {
                $lastSeenAt = new DateTimeImmutable((string) $attendance['geofence_last_seen_at']);
                $elapsedSeconds += max(0, $now->getTimestamp() - $lastSeenAt->getTimestamp());
            }
            $elapsedSeconds = min(28800, $elapsedSeconds);
            $totalHours = min(self::DAILY_CAP_HOURS, round($elapsedSeconds / 3600, 2));
            if ($totalHours < 0) {
                throw new RuntimeException('Computed total_hours is invalid');
            }

            $this->updateTimeOut((int) $attendance['id'], $now->format('Y-m-d H:i:s'), $totalHours);
            $this->repository->syncStudentDeploymentProgress($studentUserId, (int) $enrollment['semester_id']);
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

        $renderedHoursSql = $this->repository->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM({$renderedHoursSql}), 0) AS weekly_total_hours
             FROM attendance_records ar
             WHERE ar.student_user_id = :student_user_id
               AND ar.attendance_date BETWEEN :week_start AND :week_end"
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

        $renderedHoursSql = $this->repository->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM({$renderedHoursSql}), 0) AS overall_total_hours
             FROM attendance_records ar
             WHERE ar.student_user_id = :student_user_id"
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

        $renderedHoursSql = $this->repository->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT
                ss.section_id,
                s.section_code,
                s.section_name,
                ss.student_user_id,
                up.last_name,
                up.first_name,
                COALESCE(SUM({$renderedHoursSql}), 0) AS total_hours
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
             ORDER BY up.last_name, up.first_name"
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

        $context['total_students'] = $this->repository->getCoordinatorAssignedStudentCount($coordinatorUserId);

        return $context;
    }

    /**
     * Resolve an automatically closed missing-timeout record. The coordinator
     * must still manage this student in the current active semester.
     */
    public function reviewIncompleteAttendance(
        int $coordinatorUserId,
        int $attendanceId,
        bool $approve,
        ?float $verifiedHours = null
    ): array {
        if ($coordinatorUserId <= 0 || $attendanceId <= 0) {
            throw new InvalidArgumentException('Invalid attendance review request.');
        }

        $this->pdo->beginTransaction();
        try {
            $recordStmt = $this->pdo->prepare(
                'SELECT id, student_user_id, semester_id, attendance_status
                 FROM attendance_records WHERE id = :id FOR UPDATE'
            );
            $recordStmt->execute(['id' => $attendanceId]);
            $record = $recordStmt->fetch();
            if ($record === false || (string) $record['attendance_status'] !== 'INCA') {
                throw new RuntimeException('This attendance record is not awaiting INCA review.');
            }
            if ($this->getCoordinatorManagedStudentContext($coordinatorUserId, (int) $record['student_user_id']) === null) {
                throw new RuntimeException('You are not assigned to review this student.');
            }

            $hours = $approve ? min(self::DAILY_CAP_HOURS, max(0.0, $verifiedHours ?? self::DAILY_CAP_HOURS)) : 0.0;
            $status = $approve ? 'PRESENT' : 'INCA_REJECTED';
            $remark = $approve ? 'Approved Missing Timeout' : 'INCA Rejected';
            $update = $this->pdo->prepare(
                "UPDATE attendance_records
                 SET total_hours = :total_hours, attendance_status = :attendance_status,
                     remarks = :remarks, reviewed_by = :reviewed_by, reviewed_at = NOW(), updated_at = NOW()
                 WHERE id = :id"
            );
            $update->execute([
                'total_hours' => $hours,
                'attendance_status' => $status,
                'remarks' => $remark,
                'reviewed_by' => $coordinatorUserId,
                'id' => $attendanceId,
            ]);
            $this->repository->syncStudentDeploymentProgress((int) $record['student_user_id'], (int) $record['semester_id']);
            $this->pdo->commit();

            return ['attendance_id' => $attendanceId, 'status' => $status, 'credited_hours' => $hours];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getCoordinatorAssignedSchools(int $coordinatorUserId): array
    {
        if ($coordinatorUserId <= 0) {
            throw new InvalidArgumentException('Invalid coordinator_user_id');
        }

        return $this->repository->getCoordinatorAssignedSchools($coordinatorUserId);
    }

    public function syncStudentSchoolProgress(int $studentUserId, int $semesterId): void
    {
        $stmt = $this->pdo->prepare('SELECT practicum_start_date, practicum_status FROM semesters WHERE id = :semester_id LIMIT 1');
        $stmt->execute(['semester_id' => $semesterId]);
        $semester = $stmt->fetch();
        if ($semester === false || !practicumCanRun(
            (string) ($semester['practicum_start_date'] ?? ''),
            null,
            (string) ($semester['practicum_status'] ?? '')
        )) {
            return;
        }
        $this->repository->syncStudentDeploymentProgress($studentUserId, $semesterId);
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
                'holidays' => [],
            ];
        }

        $semesterId = (int) ($context['semester_id'] ?? 0);
        if (!practicumCanRun((string) ($context['practicum_start_date'] ?? ''), $todayDate, (string) ($context['practicum_status'] ?? ''))) {
            return [
                'context' => $context,
                'summary' => [
                    'active_now' => 0,
                    'logged_today' => 0,
                    'completed_8h' => 0,
                    'total_students' => (int) ($context['total_students'] ?? 0),
                ],
                'live_section' => null,
                'recent_logged_in' => [],
                'weekly_rows' => [],
                'holidays' => $this->holidayService->listBetween(
                    $weekStart,
                    $weekEnd,
                    (int) ($context['geofence_id'] ?? 0) > 0 ? (int) $context['geofence_id'] : null
                ),
            ];
        }

        $summary = $this->repository->getCoordinatorDailyKpi($coordinatorUserId, $todayDate);
        $summary['total_students'] = (int) ($context['total_students'] ?? 0);

        return [
            'context' => $context,
            'summary' => $summary,
            'live_section' => null,
            'recent_logged_in' => $this->repository->getRecentLoggedIn($coordinatorUserId, $todayDate),
            'weekly_rows' => $semesterId > 0
                ? $this->repository->getWeeklyAttendanceRows($coordinatorUserId, $semesterId, $weekStart, $weekEnd, $todayDate)
                : [],
            'holidays' => $this->holidayService->listBetween(
                $weekStart,
                $weekEnd,
                (int) ($context['geofence_id'] ?? 0) > 0 ? (int) $context['geofence_id'] : null
            ),
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

    public function markEmergencyAttendanceOverride(int $coordinatorUserId, int $studentUserId, string $attendanceDate, string $reason): array
    {
        if ($coordinatorUserId <= 0) {
            throw new InvalidArgumentException('Invalid coordinator_user_id');
        }
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student_user_id');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Reason must be 255 characters or fewer.');
        }
        if ($reason === '') {
            $reason = 'Emergency attendance override';
        }

        $this->assertValidDate($attendanceDate, 'attendance_date');
        $date = new DateTimeImmutable($attendanceDate);
        if ((int) $date->format('N') > 5) {
            throw new RuntimeException('Emergency attendance override is allowed only Monday-Friday.');
        }

        $this->pdo->beginTransaction();
        try {
            // Emergency attendance must follow the same ordered deployment
            // as normal Time In; the legacy assignment join is only a fallback.
            $studentContext = $this->repository->getCoordinatorActiveStudentDeployment($coordinatorUserId, $studentUserId);
            if ($studentContext === null) {
                $studentContext = $this->repository->getCoordinatorManagedStudentContext($coordinatorUserId, $studentUserId);
            }
            if ($studentContext === null) {
                throw new RuntimeException('You can only override attendance for students under your assigned school.');
            }
            $semesterId = (int) ($studentContext['semester_id'] ?? 0);
            if ($semesterId <= 0) {
                throw new RuntimeException('Active semester could not be resolved for this student.');
            }
            $practicumStart = practicumEffectiveStart((string) ($studentContext['practicum_start_date'] ?? ''));
            if ($practicumStart === null) {
                throw new RuntimeException('Practicum/OJT Start Date is not configured for this semester.');
            }
            $practicumEnd = (new DateTimeImmutable($practicumStart, new DateTimeZone('Asia/Manila')))
                ->modify('+15 weeks +4 days')
                ->format('Y-m-d');
            if ($attendanceDate < $practicumStart || $attendanceDate > $practicumEnd) {
                throw new RuntimeException('Emergency attendance override date must be within the 16-week practicum period.');
            }
            if ($attendanceDate > practicumToday()) {
                throw new RuntimeException('Emergency attendance override cannot be recorded for a future date.');
            }
            $orderedDeployments = $this->repository->getStudentDeployments($studentUserId, $semesterId);
            if ($orderedDeployments !== []) {
                $activeDeploymentId = null;
                foreach ($orderedDeployments as $orderedDeployment) {
                    if ((string) ($orderedDeployment['deployment_status'] ?? '') === 'active') {
                        $activeDeploymentId = (int) $orderedDeployment['id'];
                        $studentContext['school_type'] = $orderedDeployment['school_type'];
                        $studentContext['geofence_id'] = $orderedDeployment['geofence_id'];
                        break;
                    }
                }
                if ($activeDeploymentId === null) {
                    throw new RuntimeException('This student has no active school deployment for the current semester.');
                }
            }
            if (!practicumCanRun((string) ($studentContext['practicum_start_date'] ?? ''), $attendanceDate, (string) ($studentContext['practicum_status'] ?? ''))) {
                throw new RuntimeException('Practicum has not started yet. Attendance overrides are disabled until the Practicum/OJT Start Date.');
            }

            $schoolType = (string) ($studentContext['school_type'] ?? '');
            $activeDeploymentId = $activeDeploymentId ?? (isset($studentContext['deployment_id']) ? (int) $studentContext['deployment_id'] : null);
            $geofenceId = (int) ($studentContext['geofence_id'] ?? 0);
            if ($geofenceId > 0 && $this->holidayService->isHoliday($attendanceDate, $geofenceId)) {
                throw new RuntimeException('This is a school holiday or closure at the assigned school. Emergency attendance cannot be added.');
            }
            $ojtCode = $schoolType === 'public_school' ? 'public_school' : 'private_school';
            $ojtTypeId = $this->repository->getOjtTypeIdByCode($ojtCode);
            if ($ojtTypeId === null) {
                throw new RuntimeException('OJT type not configured.');
            }

            $existingAttendance = $this->findTodayAttendanceForUpdate($studentUserId, $attendanceDate);
            $now = new DateTimeImmutable('now');
            $remarks = 'Emergency override: ' . $reason;

            if ($existingAttendance !== null) {
                if ((float) ($existingAttendance['total_hours'] ?? 0) > 0 || !empty($existingAttendance['time_out'])) {
                    throw new RuntimeException('Attendance is already credited for the selected date. Choose an absent weekday instead.');
                }
                // An emergency approval may correct an existing uncredited
                // absence record. A credited attendance record is protected
                // above, so this update cannot replace already-earned hours.
                $timeIn = $attendanceDate . ' 08:00:00';
                $timeOut = $attendanceDate . ' 16:00:00';
                $totalHours = self::DAILY_CAP_HOURS;

                $attendanceId = (int) $existingAttendance['id'];
                $this->updateEmergencyOverrideAttendance(
                    $attendanceId,
                    $timeIn,
                    $timeOut,
                    $totalHours,
                    $remarks,
                    $coordinatorUserId,
                    $reason,
                    $activeDeploymentId
                );
                $this->insertAttendanceSession($attendanceId, 'time_out', $timeOut);
            } else {
                $timeIn = $attendanceDate . ' 08:00:00';
                $timeOut = $attendanceDate . ' 16:00:00';
                $totalHours = self::DAILY_CAP_HOURS;

                $deploymentRows = $this->repository->getStudentDeployments($studentUserId, $semesterId);
                $activeDeploymentId = null;
                foreach ($deploymentRows as $deploymentRow) {
                    if ((string) ($deploymentRow['deployment_status'] ?? '') === 'active') {
                        $activeDeploymentId = (int) $deploymentRow['id'];
                        break;
                    }
                }

                $attendanceId = $this->repository->insertAttendance(
                    $studentUserId,
                    $semesterId,
                    $ojtTypeId,
                    $attendanceDate,
                    $timeIn,
                    $timeOut,
                    $totalHours,
                    $remarks,
                    1,
                    $coordinatorUserId,
                    $reason,
                    $activeDeploymentId
                );

                $this->insertAttendanceSession($attendanceId, 'time_in', $timeIn);
                $this->insertAttendanceSession($attendanceId, 'time_out', $timeOut);
            }

            $this->repository->insertAttendanceOverrideLog($attendanceId, $coordinatorUserId, $reason);
            $this->repository->syncStudentDeploymentProgress($studentUserId, $semesterId);

            $this->pdo->commit();

            return [
                'attendance_id' => $attendanceId,
                'student_user_id' => $studentUserId,
                'attendance_date' => $attendanceDate,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'total_hours' => $totalHours,
                'is_override' => 1,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getCoordinatorManagedStudentContext(int $coordinatorUserId, int $studentUserId): ?array
    {
        if ($coordinatorUserId <= 0 || $studentUserId <= 0) {
            return null;
        }

        return $this->repository->getCoordinatorManagedStudentContext($coordinatorUserId, $studentUserId);
    }

    public function getStudentLiveSnapshot(
        int $studentUserId,
        string $attendanceDate,
        ?float $latitude = null,
        ?float $longitude = null,
        bool $requireGeofence = false
    ): array
    {
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student_user_id');
        }
        $this->assertValidDate($attendanceDate, 'attendance_date');

        if ($latitude !== null && $longitude !== null) {
            $this->recordStudentGeofenceHeartbeat($studentUserId, $attendanceDate, $latitude, $longitude);
        }

        $isInsideGeofence = !$requireGeofence || $this->isStudentInsideAssignedGeofence($studentUserId, $latitude, $longitude);
        $enrollment = $this->getActiveEnrollment($studentUserId);
        $semesterId = (int) ($enrollment['semester_id'] ?? 0);
        // Attendance totals and today's record must belong to the active
        // enrollment. Repeat interns start a new semester at zero; previous
        // semester data remains available only through the History views.
        $today = $semesterId > 0
            ? $this->repository->getStudentTodayLiveAttendance($studentUserId, $attendanceDate, $semesterId)
            : null;
        $deploymentProgress = [];
        $activeGeofenceId = 0;
        if ($enrollment !== null) {
            if ($semesterId > 0) {
                // Keep deployment hours/status current even when this request
                // does not include a fresh location heartbeat.
                $this->repository->syncStudentDeploymentProgress($studentUserId, $semesterId);
                foreach ($this->repository->getStudentDeployments($studentUserId, $semesterId) as $deployment) {
                    if ((string) ($deployment['deployment_status'] ?? '') === 'active') {
                        $activeGeofenceId = (int) ($deployment['geofence_id'] ?? 0);
                    }
                    $deploymentProgress[] = [
                        'id' => (int) ($deployment['id'] ?? 0),
                        'required_seconds' => (int) ($deployment['required_seconds'] ?? 0),
                        'rendered_seconds' => (int) ($deployment['rendered_seconds'] ?? 0),
                        'deployment_status' => (string) ($deployment['deployment_status'] ?? 'locked'),
                    ];
                }
            }
        }
        $totals = $semesterId > 0
            ? $this->repository->getStudentLiveTotals($studentUserId, $semesterId, $attendanceDate)
            : ['credited_hours'=>0, 'public_credited_hours'=>0, 'private_credited_hours'=>0, 'live_open_hours'=>0, 'public_live_open_hours'=>0, 'private_live_open_hours'=>0];
        $publicHours = (float) ($totals['public_credited_hours'] ?? 0) + ($isInsideGeofence ? (float) ($totals['public_live_open_hours'] ?? 0) : 0.0);
        $privateHours = (float) ($totals['private_credited_hours'] ?? 0) + ($isInsideGeofence ? (float) ($totals['private_live_open_hours'] ?? 0) : 0.0);
        $overallHours = (float) ($totals['credited_hours'] ?? 0) + ($isInsideGeofence ? (float) ($totals['live_open_hours'] ?? 0) : 0.0);
        $elapsedSeconds = $isInsideGeofence ? (int) ($today['elapsed_seconds'] ?? 0) : 0;
        $statusLabel = $this->resolveAttendanceStatusLabel($today, $attendanceDate);
        $isHoliday = $this->holidayService->isHoliday(
            $attendanceDate,
            $activeGeofenceId > 0 ? $activeGeofenceId : null
        );
        if ($isHoliday) {
            $statusLabel = 'H - Holiday';
        }

        return [
            'active' => !$isHoliday && $today !== null && !empty($today['time_in']) && empty($today['time_out']),
            'attendance_id' => $today !== null ? (int) $today['id'] : null,
            'attendance_date' => $attendanceDate,
            'time_in' => $today['time_in'] ?? null,
            'time_out' => $today['time_out'] ?? null,
            'is_override' => $today !== null ? (int) ($today['is_override'] ?? 0) : 0,
            'override_reason' => $today['override_reason'] ?? null,
            'active_ojt_code' => $today['ojt_code'] ?? null,
            'today_hours' => !$isHoliday && $today !== null && $isInsideGeofence ? (float) $today['display_hours'] : (!$isHoliday ? (float) ($today['total_hours'] ?? 0) : 0.0),
            'credited_today_hours' => !$isHoliday && $today !== null ? (float) $today['total_hours'] : 0.0,
            'credited_overall_hours' => (float) ($totals['credited_hours'] ?? 0),
            'credited_public_hours' => (float) ($totals['public_credited_hours'] ?? 0),
            'credited_private_hours' => (float) ($totals['private_credited_hours'] ?? 0),
            'elapsed_seconds' => $elapsedSeconds,
            'can_time_out' => $isInsideGeofence && $elapsedSeconds >= 28800,
            'remaining_seconds_to_timeout' => max(0, 28800 - $elapsedSeconds),
            'public_hours' => $publicHours,
            'private_hours' => $privateHours,
            'overall_hours' => $overallHours,
            'public_required_hours' => self::PUBLIC_REQUIRED_HOURS,
            'private_required_hours' => self::PRIVATE_REQUIRED_HOURS,
            'overall_required_hours' => self::TOTAL_REQUIRED_HOURS,
            'daily_required_hours' => self::DAILY_CAP_HOURS,
            'status_label' => $statusLabel,
            'is_holiday' => $isHoliday,
            'geofence_inside' => $isInsideGeofence,
            'deployment_progress' => $deploymentProgress,
        ];
    }

    public function recordStudentGeofenceHeartbeat(int $studentUserId, string $attendanceDate, float $latitude, float $longitude): bool
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Invalid latitude/longitude');
        }
        if ($attendanceDate !== (new DateTimeImmutable('today'))->format('Y-m-d')) {
            // A browser heartbeat may never revive or extend a prior day.
            return false;
        }

        $geofence = $this->repository->getStudentAssignedGeofence($studentUserId);
        if ($geofence === null) {
            return false;
        }

        $distance = $this->distanceMeters($latitude, $longitude, (float) $geofence['latitude'], (float) $geofence['longitude']);
        $inside = $distance <= (float) $geofence['radius_meters'];
        $this->repository->updateStudentGeofenceHeartbeat($studentUserId, $attendanceDate, $latitude, $longitude, $inside);
        $semesterId = $this->repository->getAttendanceSemesterId($studentUserId, $attendanceDate);
        if ($semesterId !== null) {
            $this->repository->syncStudentDeploymentProgress($studentUserId, $semesterId);
        }
        $attendanceId = $this->repository->getOpenAttendanceId($studentUserId, $attendanceDate);
        if ($attendanceId !== null) {
            $this->insertGeofenceLog(
                $attendanceId,
                (int) $geofence['id'],
                $latitude,
                $longitude,
                $distance,
                $inside ? 1 : 0
            );
        }
        return $inside;
    }

    private function isStudentInsideAssignedGeofence(int $studentUserId, ?float $latitude, ?float $longitude): bool
    {
        if ($latitude === null || $longitude === null || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return false;
        }

        $geofence = $this->repository->getStudentAssignedGeofence($studentUserId);
        if ($geofence === null) {
            return false;
        }

        return $this->distanceMeters($latitude, $longitude, (float) $geofence['latitude'], (float) $geofence['longitude']) <= (float) $geofence['radius_meters'];
    }

    private function distanceMeters(float $latitude, float $longitude, float $targetLatitude, float $targetLongitude): float
    {
        $lat1 = deg2rad($latitude);
        $lat2 = deg2rad($targetLatitude);
        $deltaLat = deg2rad($targetLatitude - $latitude);
        $deltaLng = deg2rad($targetLongitude - $longitude);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;
        return 6371000.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private function getActiveEnrollment(int $studentUserId): ?array
    {
        $renderedHoursSql = $this->repository->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            'SELECT
                ss.section_id,
                sem.id AS semester_id,
                sem.start_date AS semester_start_date,
                sem.practicum_start_date,
                sem.practicum_status,
                sem.end_date AS semester_end_date
             FROM users u
             JOIN semester_students sems
               ON sems.student_user_id = u.id
              AND sems.enrollment_status = \'active\'
             LEFT JOIN section_students ss
               ON ss.student_user_id = u.id
              AND ss.semester_id = sems.semester_id
              AND ss.enrollment_status = \'active\'
             JOIN semesters sem ON sem.id = sems.semester_id
             WHERE u.id = :student_user_id
               AND sem.semester_status = \'active\'
             ORDER BY sem.start_date DESC, sem.id DESC
             LIMIT 1'
        );
        $stmt->execute(['student_user_id' => $studentUserId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function isDateInActiveSemesterWeek(string $date, array $enrollment): bool
    {
        if (!practicumCanRun(
            (string) ($enrollment['practicum_start_date'] ?? ''),
            $date,
            (string) ($enrollment['practicum_status'] ?? '')
        )) {
            return false;
        }

        $practicumStart = practicumEffectiveStart((string) ($enrollment['practicum_start_date'] ?? ''));
        if ($practicumStart === null) {
            return false;
        }
        $practicumEnd = (new DateTimeImmutable($practicumStart, new DateTimeZone('Asia/Manila')))
            ->modify('+15 weeks +4 days')
            ->format('Y-m-d');
        if ($date < $practicumStart || $date > $practicumEnd) {
            return false;
        }

        $start = new DateTimeImmutable($practicumStart, new DateTimeZone('Asia/Manila'));
        for ($index = 0; $index < 16; $index++) {
            $weekStart = $start->modify('+' . ($index * 7) . ' days')->format('Y-m-d');
            $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
            if ($date >= $weekStart && $date <= $weekEnd) {
                return true;
            }
        }

        return false;
    }

    private function findTodayAttendanceForUpdate(int $studentUserId, string $date): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, deployment_id, time_in, time_out, total_hours, geofence_seconds
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

    private function resolveDeploymentGeofence(array $deployments, float $latitude, float $longitude): ?array
    {
        $nearest = null;
        foreach ($deployments as $deployment) {
            $distance = $this->distanceMeters($latitude, $longitude, (float) $deployment['latitude'], (float) $deployment['longitude']);
            $candidateInside = $distance <= (float) $deployment['radius_meters'];
            $nearestInside = $nearest !== null && (int) ($nearest['inside_radius'] ?? 0) === 1;
            if ($nearest === null || ($candidateInside && !$nearestInside) || ($candidateInside === $nearestInside && $distance < (float) $nearest['distance_from_center'])) {
                $nearest = $deployment + [
                    'deployment_id' => (int) $deployment['id'],
                    'distance_from_center' => $distance,
                    'inside_radius' => $candidateInside ? 1 : 0,
                ];
            }
        }
        return $nearest;
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
        ?int $sectionId,
        int $semesterId,
        int $ojtTypeId,
        string $attendanceDate,
        string $timeIn,
        ?int $deploymentId = null
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO attendance_records
                (student_user_id, section_id, semester_id, deployment_id, ojt_type_id, attendance_date, time_in, time_out, total_hours, attendance_status, geofence_last_seen_at, geofence_inside, remarks)
             VALUES
                (:student_user_id, :section_id, :semester_id, :deployment_id, :ojt_type_id, :attendance_date, :time_in, NULL, 0.00, 'LIVE', NOW(), 1, NULL)"
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'section_id' => $sectionId,
            'semester_id' => $semesterId,
            'deployment_id' => $deploymentId,
            'ojt_type_id' => $ojtTypeId,
            'attendance_date' => $attendanceDate,
            'time_in' => $timeIn,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function updateTimeOut(int $attendanceId, string $timeOut, float $totalHours): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE attendance_records
             SET time_out = :time_out,
                 total_hours = LEAST(8.00, GREATEST(0, :total_hours)),
                 attendance_status = 'PRESENT',
                 updated_at = NOW()
             WHERE id = :attendance_id"
        );
        $stmt->execute([
            'attendance_id' => $attendanceId,
            'time_out' => $timeOut,
            'total_hours' => $totalHours,
        ]);
    }

    private function updateEmergencyOverrideAttendance(
        int $attendanceId,
        string $timeIn,
        string $timeOut,
        float $totalHours,
        string $remarks,
        int $overrideBy,
        string $overrideReason,
        ?int $deploymentId = null
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE attendance_records
             SET time_in = :time_in,
                 time_out = :time_out,
                 total_hours = LEAST(8.00, GREATEST(0, :total_hours)),
                 attendance_status = 'PRESENT',
                 geofence_seconds = :override_seconds,
                 geofence_inside = 1,
                 geofence_last_seen_at = NULL,
                 remarks = :remarks,
                 deployment_id = COALESCE(deployment_id, :deployment_id),
                 is_override = 1,
                 override_by = :override_by,
                 override_reason = :override_reason,
                 updated_at = NOW()
             WHERE id = :attendance_id"
        );
        $stmt->execute([
            'attendance_id' => $attendanceId,
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            'total_hours' => $totalHours,
            'override_seconds' => (int) round($totalHours * 3600),
            'remarks' => $remarks,
            'deployment_id' => $deploymentId,
            'override_by' => $overrideBy,
            'override_reason' => $overrideReason,
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

        $renderedHoursSql = $this->repository->liveDisplayHoursSql('ar');
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM({$renderedHoursSql}), 0)
             FROM attendance_records ar
             JOIN ojt_types ot ON ot.id = ar.ojt_type_id
             WHERE ar.student_user_id = :student_user_id
               AND ot.ojt_code = :ojt_code"
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

    private function resolveAttendanceStatusLabel(?array $attendance, ?string $attendanceDate = null): string
    {
        if ($attendance === null) return AttendanceStatusService::todayStatus([]);
        if ((int) ($attendance['is_override'] ?? 0) === 1) {
            return 'Emergency Override';
        }
        return AttendanceStatusService::todayStatus($attendance);
    }
}
