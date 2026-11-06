<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/StudentCredentialMailer.php';
require_once __DIR__ . '/SupervisorService.php';
require_once __DIR__ . '/PracticumWeekService.php';
require_once __DIR__ . '/AuditLogService.php';
require_once __DIR__ . '/SemesterLifecycleIntegrityService.php';

final class AcademicControlService
{
    private PDO $pdo;
    private StudentCredentialMailer $studentCredentialMailer;

    public function __construct(?PDO $pdo = null, ?StudentCredentialMailer $studentCredentialMailer = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->studentCredentialMailer = $studentCredentialMailer ?? new StudentCredentialMailer();
        $this->ensureSemesterPracticumStartDateColumn();
        $this->ensureSemesterPracticumStatusColumn();
        $this->ensureCoordinatorSchoolTable();
        $this->ensureCoordinatorSemesterLinksTable();
        $this->ensureSemesterStudentsTable();
        $this->ensureStudentGeofenceAssignmentSemesterKey();
    }

    public function startPracticum(int $semesterId, ?string $practicumStartDate = null, ?string $dateChangeConfirmation = null): array
    {
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester.');
        }

        $this->ensureSemesterPracticumStatusColumn();
        $semester = $this->getSemester($semesterId);
        if ($semester === null || ($semester['semester_status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Only an active semester can start practicum.');
        }
        $currentStatus = strtolower((string) ($semester['practicum_status'] ?? ''));
        if (!in_array($currentStatus, ['not_started', 'not_yet_started', 'not_configured', 'establishing'], true)) {
            throw new InvalidArgumentException('Practicum has already been started or scheduled. The practicum date is locked.');
        }
        $date = trim((string) ($practicumStartDate ?? $semester['practicum_start_date'] ?? ''));
        if ($date === '') {
            throw new InvalidArgumentException('Save a practicum start date before starting practicum.');
        }
        $this->assertValidPracticumStartDate($date);
        $storedDate = trim((string) ($semester['practicum_start_date'] ?? ''));
        if ($storedDate !== '' && $storedDate !== $date && $this->semesterHasAttendanceRecords($semesterId) && trim((string) $dateChangeConfirmation) !== 'CONFIRM') {
            throw new InvalidArgumentException('Attendance records already exist. Type CONFIRM before changing the practicum start date.');
        }
        $nextStatus = $date > practicumToday() ? 'scheduled' : 'ongoing';

        $stmt = $this->pdo->prepare(
            "UPDATE semesters
             SET practicum_start_date = :practicum_start_date,
                 practicum_status = :practicum_status
             WHERE id = :semester_id
               AND semester_status = 'active'"
        );
        $stmt->execute(['semester_id' => $semesterId, 'practicum_start_date' => $date, 'practicum_status' => $nextStatus]);

        return [
            'semester_id' => $semesterId,
            'practicum_status' => $nextStatus,
            'effective_week_1_start' => practicumEffectiveStart($date),
        ];
    }

    public function importOfficialStudentMasterlist(string $csvPath, int $activeSemesterId): array
    {
        if ($activeSemesterId <= 0) {
            throw new InvalidArgumentException('Active semester is required.');
        }
        if (!is_file($csvPath) || !is_readable($csvPath)) {
            throw new InvalidArgumentException('Uploaded CSV file is unavailable.');
        }

        $semester = $this->getSemester($activeSemesterId);
        if ($semester === null) {
            throw new RuntimeException('Active semester not found.');
        }
        if ((string) ($semester['semester_status'] ?? '') !== 'active') {
            throw new RuntimeException('Only the active semester can receive imported students.');
        }

        $this->ensureOfficialMasterlistProfileColumns();

        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the uploaded CSV file.');
        }

        $summary = [
            'total_rows_processed' => 0,
            'successfully_imported' => 0,
            'reactivated_existing_students' => 0,
            'new_students_created' => 0,
            'duplicates_skipped' => 0,
            'errors' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
            'duplicate_details' => [],
            'error_details' => [],
        ];

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new RuntimeException('CSV file is empty.');
            }

            $header = $this->normalizeOfficialHeaderRow($header);
            $expectedHeader = [
                'SESSION NAME',
                'CAMPUS',
                'STUDENT ID',
                'STUDENT NAME',
                'GENDER',
                'COLLEGE',
                'COURSE',
                'SUBJECT',
                'SECTION',
                'EMAIL',
            ];

            if ($header !== $expectedHeader) {
                throw new RuntimeException('Invalid CSV header. Please use the official student masterlist format.');
            }

            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $this->rowIsEmpty($row)) {
                    continue;
                }

                $summary['total_rows_processed']++;

                try {
                    $mapped = $this->mapOfficialMasterlistRow($row, $activeSemesterId);
                    $existing = $this->findUserByEmailOrSchoolId($mapped['email'], $mapped['school_id']);
                    if ($existing !== null) {
                        if ((string) ($existing['role_code'] ?? '') !== 'student') {
                            throw new RuntimeException('Existing account is not a student: ' . $mapped['school_id'] . ' / ' . $mapped['email']);
                        }

                        $studentUserId = (int) $existing['id'];
                        if ($this->studentHasSemesterEnrollment($activeSemesterId, $studentUserId, 'active')) {
                            $summary['duplicates_skipped']++;
                            $summary['duplicate_details'][] = $mapped['school_id'] . ' / ' . $mapped['email'] . ' (Already enrolled in this semester)';
                            continue;
                        }

                        $this->pdo->beginTransaction();
                        try {
                            $this->reactivateStudentAccountForSemester($studentUserId, $mapped);
                            $this->upsertSemesterStudentEnrollment($activeSemesterId, $studentUserId, 'active');
                            $this->pdo->commit();
                        } catch (Throwable $e) {
                            if ($this->pdo->inTransaction()) {
                                $this->pdo->rollBack();
                            }
                            throw $e;
                        }

                        $summary['successfully_imported']++;
                        $summary['reactivated_existing_students']++;
                        continue;
                    }

                    $creation = $this->createStudentAccountWithProfile($mapped);
                    $this->upsertSemesterStudentEnrollment($activeSemesterId, (int) $creation['user_id'], 'active');
                    $summary['successfully_imported']++;
                    $summary['new_students_created']++;

                    if (!empty($creation['credentials_email_sent'])) {
                        $summary['email_sent']++;
                    } else {
                        $summary['email_failed']++;
                    }
                } catch (Throwable $e) {
                    $summary['errors']++;
                    $summary['error_details'][] = 'Row ' . ($summary['total_rows_processed'] + 1) . ': ' . $e->getMessage();
                }
            }
        } finally {
            fclose($handle);
        }

        return $summary;
    }

    public function createSchoolYear(string $yearLabel, string $startDate, string $endDate, string $yearStatus = 'planned'): int
    {
        $this->assertValidDate($startDate, 'start_date');
        $this->assertValidDate($endDate, 'end_date');
        if ($startDate >= $endDate) {
            throw new InvalidArgumentException('end_date must be greater than start_date');
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $yearLabel)) {
            throw new InvalidArgumentException('Invalid year_label format');
        }
        if (!in_array($yearStatus, ['planned', 'active', 'closed', 'archived'], true)) {
            throw new InvalidArgumentException('Invalid year_status');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO school_years (year_label, start_date, end_date, year_status)
                 VALUES (:year_label, :start_date, :end_date, :year_status)'
            );
            $stmt->execute([
                'year_label' => $yearLabel,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'year_status' => $yearStatus,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->createDefaultSemestersForSchoolYear($id, $yearLabel);
            $this->resetAcademicCycleAssets();
            $this->ensureFutureSchoolYears(3);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function ensureFutureSchoolYears(int $count = 3): void
    {
        if ($count <= 0) {
            return;
        }

        $latestYearLabel = $this->getLatestSchoolYearLabel();
        if ($latestYearLabel === null) {
            return;
        }

        if (!preg_match('/^(\d{4})-(\d{4})$/', $latestYearLabel, $matches)) {
            throw new InvalidArgumentException('Invalid year_label format');
        }

        $startYear = (int) $matches[1];
        $endYear = (int) $matches[2];
        $checkStmt = $this->pdo->prepare('SELECT id FROM school_years WHERE year_label = :year_label LIMIT 1');
        $insertStmt = $this->pdo->prepare(
            'INSERT INTO school_years (year_label, start_date, end_date, year_status)
             VALUES (:year_label, :start_date, :end_date, :year_status)'
        );

        for ($offset = 1; $offset <= $count; $offset++) {
            $newStartYear = $startYear + $offset;
            $newEndYear = $endYear + $offset;
            $newYearLabel = $newStartYear . '-' . $newEndYear;

            $checkStmt->execute(['year_label' => $newYearLabel]);
            if ($checkStmt->fetchColumn() !== false) {
                continue;
            }

            $insertStmt->execute([
                'year_label' => $newYearLabel,
                'start_date' => $newStartYear . '-06-01',
                'end_date' => $newEndYear . '-05-31',
                'year_status' => 'planned',
            ]);
            $this->createDefaultSemestersForSchoolYear((int) $this->pdo->lastInsertId(), $newYearLabel);
        }
    }

    private function getLatestSchoolYearLabel(): ?string
    {
        $stmt = $this->pdo->query(
            "SELECT year_label
             FROM school_years
             ORDER BY CAST(SUBSTRING_INDEX(year_label, '-', 1) AS UNSIGNED) DESC, id DESC
             LIMIT 1"
        );
        $yearLabel = $stmt->fetchColumn();

        return $yearLabel !== false ? (string) $yearLabel : null;
    }

    private function createDefaultSemestersForSchoolYear(int $schoolYearId, string $yearLabel): void
    {
        if ($schoolYearId <= 0) {
            throw new InvalidArgumentException('Invalid school_year_id');
        }
        if (!preg_match('/^(\d{4})-(\d{4})$/', $yearLabel, $matches)) {
            throw new InvalidArgumentException('Invalid year_label format');
        }

        $startYear = (int) $matches[1];
        $endYear = (int) $matches[2];
        $semesters = [
            [
                'semester_no' => 1,
                'semester_name' => '1st Semester',
                'start_date' => $startYear . '-06-01',
                'end_date' => $startYear . '-10-31',
            ],
            [
                'semester_no' => 2,
                'semester_name' => '2nd Semester',
                'start_date' => $startYear . '-11-01',
                'end_date' => $endYear . '-03-31',
            ],
        ];

        $checkStmt = $this->pdo->prepare(
            'SELECT id
             FROM semesters
             WHERE school_year_id = :school_year_id
               AND semester_no = :semester_no
             LIMIT 1'
        );
        $insertStmt = $this->pdo->prepare(
            'INSERT INTO semesters (school_year_id, semester_no, semester_name, start_date, end_date, semester_status)
             VALUES (:school_year_id, :semester_no, :semester_name, :start_date, :end_date, :semester_status)'
        );

        foreach ($semesters as $semester) {
            $checkStmt->execute([
                'school_year_id' => $schoolYearId,
                'semester_no' => (int) $semester['semester_no'],
            ]);
            if ($checkStmt->fetchColumn() !== false) {
                continue;
            }

            $insertStmt->execute([
                'school_year_id' => $schoolYearId,
                'semester_no' => (int) $semester['semester_no'],
                'semester_name' => (string) $semester['semester_name'],
                'start_date' => (string) $semester['start_date'],
                'end_date' => (string) $semester['end_date'],
                'semester_status' => 'planned',
            ]);
        }
    }

    public function createSemester(
        int $schoolYearId,
        int $semesterNo,
        string $semesterName,
        string $startDate,
        string $endDate,
        string $semesterStatus = 'planned'
    ): int {
        if ($schoolYearId <= 0) {
            throw new InvalidArgumentException('Invalid school_year_id');
        }
        if ($semesterNo < 1 || $semesterNo > 3) {
            throw new InvalidArgumentException('semester_no must be 1..3');
        }
        $semesterName = trim($semesterName);
        if ($semesterName === '') {
            throw new InvalidArgumentException('semester_name is required');
        }
        $this->assertValidDate($startDate, 'start_date');
        $this->assertValidDate($endDate, 'end_date');
        if ($startDate >= $endDate) {
            throw new InvalidArgumentException('end_date must be greater than start_date');
        }
        if (!in_array($semesterStatus, ['planned', 'active', 'closed', 'archived'], true)) {
            throw new InvalidArgumentException('Invalid semester_status');
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertRecordExists('school_years', $schoolYearId, 'School year not found');

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
                'semester_status' => $semesterStatus,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function createSection(
        int $semesterId,
        string $sectionCode,
        string $sectionName,
        int $capacity = 50,
        string $sectionStatus = 'active'
    ): int {
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester_id');
        }
        $sectionCode = trim($sectionCode);
        $sectionName = trim($sectionName);
        if ($sectionCode === '' || $sectionName === '') {
            throw new InvalidArgumentException('section_code and section_name are required');
        }
        if ($capacity <= 0) {
            throw new InvalidArgumentException('capacity must be greater than zero');
        }
        if (!in_array($sectionStatus, ['active', 'inactive', 'archived'], true)) {
            throw new InvalidArgumentException('Invalid section_status');
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertRecordExists('semesters', $semesterId, 'Semester not found');

            $stmt = $this->pdo->prepare(
                'INSERT INTO sections (semester_id, section_code, section_name, capacity, section_status)
                 VALUES (:semester_id, :section_code, :section_name, :capacity, :section_status)'
            );
            $stmt->execute([
                'semester_id' => $semesterId,
                'section_code' => $sectionCode,
                'section_name' => $sectionName,
                'capacity' => $capacity,
                'section_status' => $sectionStatus,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function assignCoordinator(int $sectionId, int $coordinatorUserId): void
    {
        if ($sectionId <= 0 || $coordinatorUserId <= 0) {
            throw new InvalidArgumentException('Invalid section_id or coordinator_user_id');
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertRecordExists('sections', $sectionId, 'Section not found');
            $this->assertUserRole($coordinatorUserId, 'coordinator');

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

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function enrollStudentsBulk(int $sectionId, array $studentUserIds): int
    {
        if ($sectionId <= 0) {
            throw new InvalidArgumentException('Invalid section_id');
        }
        if ($studentUserIds === []) {
            throw new InvalidArgumentException('student_user_ids must not be empty');
        }

        $studentUserIds = array_values(array_unique(array_map('intval', $studentUserIds)));
        foreach ($studentUserIds as $studentId) {
            if ($studentId <= 0) {
                throw new InvalidArgumentException('Invalid student_user_id found');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $section = $this->getSection($sectionId);
            if ($section === null) {
                throw new RuntimeException('Section not found');
            }
            $semesterId = (int) $section['semester_id'];
            $capacity = (int) $section['capacity'];

            $currentCount = $this->getActiveSectionStudentCount($sectionId);
            if ($currentCount + count($studentUserIds) > $capacity) {
                throw new RuntimeException('Section capacity exceeded');
            }

            $insertStmt = $this->pdo->prepare(
                'INSERT INTO section_students (section_id, semester_id, student_user_id, enrollment_status)
                 VALUES (:section_id, :semester_id, :student_user_id, :enrollment_status)'
            );

            foreach ($studentUserIds as $studentId) {
                $this->assertUserRole($studentId, 'student');
                if (!$this->studentHasSemesterEnrollment($semesterId, $studentId, 'active')) {
                    throw new RuntimeException('Student must be imported for the active semester before section enrollment.');
                }
                $this->assertStudentNotEnrolledInOtherSectionSameSemester($studentId, $semesterId, $sectionId);

                $insertStmt->execute([
                    'section_id' => $sectionId,
                    'semester_id' => $semesterId,
                    'student_user_id' => $studentId,
                    'enrollment_status' => 'active',
                ]);
            }

            $this->pdo->commit();
            return count($studentUserIds);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function createStudentAccountWithProfile(array $payload): array
    {
        $schoolId = trim((string) ($payload['school_id'] ?? ''));
        if ($schoolId === '') {
            throw new InvalidArgumentException('Missing required field: school_id');
        }

        $this->ensureOfficialMasterlistProfileColumns();

        $studentPayload = $payload;
        $studentPayload['school_id'] = $schoolId;
        $studentPayload['password'] = $schoolId;
        $studentPayload['must_change_password'] = true;

        $userId = $this->createUserAccountWithProfileByRole('student', $studentPayload);
        $email = strtolower(trim((string) ($studentPayload['email'] ?? '')));
        $mailResult = $this->studentCredentialMailer->sendStudentCredentials($email, $schoolId, $schoolId);

        return [
            'user_id' => $userId,
            'credentials_email_sent' => (bool) ($mailResult['sent'] ?? false),
            'credentials_email_error' => $mailResult['error'] ?? null,
        ];
    }

    public function createCoordinatorAccountWithProfile(array $payload): array
    {
        $schoolId = trim((string) ($payload['school_id'] ?? ''));
        if ($schoolId === '') {
            throw new InvalidArgumentException('Missing required field: school_id');
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email');
        }
        if (!preg_match('/@phinmaed\.com$/', $email)) {
            throw new InvalidArgumentException('Coordinator email must use the @phinmaed.com domain.');
        }

        $coordinatorPayload = $payload;
        $coordinatorPayload['school_id'] = $schoolId;
        $coordinatorPayload['email'] = $email;
        $coordinatorPayload['password'] = $schoolId;
        $coordinatorPayload['must_change_password'] = true;

        $existing = $this->findUserByEmailOrSchoolId($email, $schoolId);
        $geofenceId = (int) ($payload['geofence_id'] ?? 0);
        $assignedBy = (int) ($payload['assigned_by'] ?? 0);

        if ($existing !== null) {
            if ((string) ($existing['role_code'] ?? '') !== 'coordinator') {
                throw new RuntimeException('Existing user is not a coordinator.');
            }

            $userId = (int) $existing['id'];
            $semesterId = $this->getActiveSemesterIdForCoordinatorProvisioning();
            $alreadyAddedThisSemester = $semesterId > 0 && $this->coordinatorHasSemesterLink($userId, $semesterId);
            if ((string) ($existing['account_status'] ?? '') !== 'active' || !$alreadyAddedThisSemester) {
                $this->pdo->beginTransaction();
                try {
                    $this->reactivateCoordinatorAccountById($userId, $coordinatorPayload);
                    if ($semesterId > 0) {
                        $this->upsertCoordinatorSemesterLink($userId, $semesterId);
                    }
                    if ($geofenceId > 0 && $assignedBy > 0) {
                        $this->persistCoordinatorSchoolAssignment($userId, $geofenceId, $assignedBy);
                    }
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }

                $mailResult = $this->studentCredentialMailer->sendCoordinatorCredentials($email, $schoolId, $schoolId);
                return [
                    'user_id' => $userId,
                    'status' => 'reactivated',
                    'credentials_email_sent' => (bool) ($mailResult['sent'] ?? false),
                    'credentials_email_error' => $mailResult['error'] ?? null,
                ];
            }

            return [
                'user_id' => $userId,
                'status' => 'existing_active',
                'credentials_email_sent' => false,
                'credentials_email_error' => null,
            ];
        }

        $this->pdo->beginTransaction();
        try {
            $userId = $this->persistUserAccountWithProfileByRole('coordinator', $coordinatorPayload);
            $semesterId = $this->getActiveSemesterIdForCoordinatorProvisioning();
            if ($semesterId > 0) {
                $this->upsertCoordinatorSemesterLink($userId, $semesterId);
            }
            if ($geofenceId > 0 && $assignedBy > 0) {
                $this->persistCoordinatorSchoolAssignment($userId, $geofenceId, $assignedBy);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $mailResult = $this->studentCredentialMailer->sendCoordinatorCredentials($email, $schoolId, $schoolId);

        return [
            'user_id' => $userId,
            'status' => 'created',
            'credentials_email_sent' => (bool) ($mailResult['sent'] ?? false),
            'credentials_email_error' => $mailResult['error'] ?? null,
        ];
    }

    public function startSemester(?int $semesterId = null): array
    {
        try {
            $this->ensureSemesterStudentsTable();
            $this->ensureSemesterPracticumStatusColumn();
            $this->ensureSemesterCompletionLifecycle();
            $this->ensureStudentGeofenceAssignmentSemesterKey();
        } catch (PDOException $e) {
            error_log(sprintf('Semester activation preparation failed: %s', $e->getMessage()));
            throw new RuntimeException('Unable to activate semester. Please contact administrator.', 0, $e);
        }

        $supervisorService = new SupervisorService($this->pdo);
        $this->pdo->beginTransaction();
        try {
            // Repair legacy duplicate active flags before checking whether a
            // genuine active semester already exists.
            (new SemesterLifecycleIntegrityService($this->pdo))->repair();
            $requestedSemester = $semesterId !== null && $semesterId > 0
                ? $this->getSemester($semesterId)
                : null;

            // A completed request may be retried by the browser.  Treat that
            // as a safe no-op instead of attempting to prepare the same
            // semester a second time.
            if ($requestedSemester !== null
                && (string) ($requestedSemester['semester_status'] ?? '') === 'active') {
                $this->pdo->commit();
                return [
                    'semester_id' => (int) $requestedSemester['id'],
                    'school_year_id' => (int) ($requestedSemester['school_year_id'] ?? 0),
                    'semester_name' => (string) ($requestedSemester['semester_name'] ?? ''),
                    'semester_status' => 'active',
                    'practicum_status' => (string) ($requestedSemester['practicum_status'] ?? 'not_started'),
                    'practicum_start_date' => (string) ($requestedSemester['practicum_start_date'] ?? ''),
                    'already_prepared' => true,
                ];
            }

            $semester = $semesterId !== null && $semesterId > 0
                ? $this->getSemester($semesterId)
                : $this->getNextPlannedSemester();

            if ($semester === null) {
                throw new RuntimeException('No planned semester found.');
            }
            if ((string) ($semester['semester_status'] ?? '') !== 'planned') {
                throw new RuntimeException('Only planned semesters can be started.');
            }

            $semesterId = (int) $semester['id'];
            $schoolYearId = (int) ($semester['school_year_id'] ?? 0);

            // This also protects legacy data that was allowed to retain more
            // than one active semester. The requested planned semester
            // becomes the only active one; all earlier active rows are kept
            // as history and cannot remain active in any report.
            $deactivateOtherSemesters = $this->pdo->prepare(
                "UPDATE semesters
                 SET semester_status = 'archived',
                     practicum_status = 'ended',
                     completed_at = COALESCE(completed_at, NOW())
                 WHERE semester_status = 'active'
                   AND id <> :semester_id"
            );
            $deactivateOtherSemesters->execute(['semester_id' => $semesterId]);

            $supervisorService->prepareNewSemester($semesterId);

            $archiveTargetSectionStudents = $this->pdo->prepare(
                "UPDATE section_students
                 SET enrollment_status = 'archived'
                 WHERE semester_id = :semester_id
                   AND enrollment_status <> 'archived'"
            );
            $archiveTargetSectionStudents->execute(['semester_id' => $semesterId]);

            $archiveTargetSemesterStudents = $this->pdo->prepare(
                "UPDATE semester_students
                 SET enrollment_status = 'archived',
                     updated_at = NOW()
                 WHERE semester_id = :semester_id
                   AND enrollment_status <> 'archived'"
            );
            $archiveTargetSemesterStudents->execute(['semester_id' => $semesterId]);

            $activateSemester = $this->pdo->prepare(
                "UPDATE semesters
                 SET semester_status = 'active',
                     practicum_status = 'not_started',
                     practicum_start_date = NULL
                 WHERE id = :semester_id"
            );
            $activateSemester->execute(['semester_id' => $semesterId]);

            // semester_status is authoritative. Synchronize every school
            // year after activation so stale year flags cannot create a
            // second apparent active academic year in reports.
            (new SemesterLifecycleIntegrityService($this->pdo))->repair();

            $activateSections = $this->pdo->prepare(
                "UPDATE sections
                 SET section_status = 'active'
                 WHERE semester_id = :semester_id
                   AND section_status <> 'archived'"
            );
            $activateSections->execute(['semester_id' => $semesterId]);

            $this->pdo->commit();

            return [
                'semester_id' => $semesterId,
                'school_year_id' => $schoolYearId,
                'semester_name' => (string) ($semester['semester_name'] ?? ''),
                'semester_status' => 'active',
                'practicum_status' => 'not_started',
                'practicum_start_date' => '',
                'activated_sections' => $activateSections->rowCount(),
                'archived_section_students' => $archiveTargetSectionStudents->rowCount(),
                'archived_semester_students' => $archiveTargetSemesterStudents->rowCount(),
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($e instanceof PDOException) {
                error_log(sprintf('Semester activation failed: %s', $e->getMessage()));
                throw new RuntimeException('Unable to activate semester. Please contact administrator.', 0, $e);
            }

            throw $e;
        }
    }

    public function assignCoordinatorToSchool(int $coordinatorUserId, int $geofenceId, int $assignedBy): void
    {
        if ($coordinatorUserId <= 0 || $geofenceId <= 0 || $assignedBy <= 0) {
            throw new InvalidArgumentException('Invalid coordinator, school, or assigner.');
        }

        $this->ensureCoordinatorSchoolTable();

        $this->pdo->beginTransaction();
        try {
            $this->assertUserRole($coordinatorUserId, 'coordinator');
            $this->assertUserExists($assignedBy, 'Assigning user not found.');
            $this->assertGeofenceExists($geofenceId, 'Partnered school not found.');

            $stmt = $this->pdo->prepare(
                'INSERT INTO coordinator_schools (coordinator_user_id, geofence_id, assigned_by, assigned_at)
                 VALUES (:coordinator_user_id, :geofence_id, :assigned_by, NOW())
                 ON DUPLICATE KEY UPDATE
                    geofence_id = VALUES(geofence_id),
                    assigned_by = VALUES(assigned_by),
                    assigned_at = VALUES(assigned_at)'
            );
            $stmt->execute([
                'coordinator_user_id' => $coordinatorUserId,
                'geofence_id' => $geofenceId,
                'assigned_by' => $assignedBy,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function endSemester(int $semesterId, ?int $actorUserId = null): array
    {
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester_id');
        }

        $this->ensureSemesterCompletionLifecycle();
        $this->ensureSemesterStudentsTable();
        $supervisorService = new SupervisorService($this->pdo);
        // Schema setup must run before the archive transaction: MySQL DDL
        // implicitly commits and would otherwise break this lifecycle action.
        $auditLog = new AuditLogService($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $semester = $this->getSemester($semesterId);
            if ($semester === null) {
                throw new RuntimeException('Semester not found.');
            }
            if ((string) ($semester['semester_status'] ?? '') !== 'active') {
                throw new RuntimeException('Only an active semester can be ended.');
            }
            if (!in_array((string) ($semester['practicum_status'] ?? ''), ['not_started', 'not_yet_started', 'scheduled', 'ongoing'], true)) {
                throw new RuntimeException('Only an active practicum that has not been completed can be ended.');
            }

            // Do not leave an open time-in session attached to an archived semester.
            // Existing rendered time is preserved; only the session is closed.
            $closeOpenAttendance = $this->pdo->prepare(
                "UPDATE attendance_records
                 SET time_out = NOW(),
                     total_hours = LEAST(8.00, ROUND(COALESCE(geofence_seconds, 0) / 3600, 2)),
                     remarks = 'Auto-closed: semester ended',
                     updated_at = NOW()
                 WHERE semester_id = :semester_id
                   AND time_in IS NOT NULL
                   AND time_out IS NULL"
            );
            $closeOpenAttendance->execute(['semester_id' => $semesterId]);
            $autoClosedAttendance = $closeOpenAttendance->rowCount();

            $supervisorLifecycle = $supervisorService->archiveSemester($semesterId);

            $closeSemester = $this->pdo->prepare(
                "UPDATE semesters
                 SET semester_status = 'completed',
                     practicum_status = 'ended',
                     completed_at = NOW()
                 WHERE id = :semester_id"
            );
            $closeSemester->execute(['semester_id' => $semesterId]);

            $deactivateSections = $this->pdo->prepare(
                "UPDATE sections
                 SET section_status = 'inactive'
                 WHERE semester_id = :semester_id"
            );
            $deactivateSections->execute(['semester_id' => $semesterId]);
            $deactivatedSections = $deactivateSections->rowCount();

            $completeStudents = $this->pdo->prepare(
                "UPDATE section_students
                 SET enrollment_status = 'archived'
                 WHERE semester_id = :semester_id
                   AND enrollment_status <> 'archived'"
            );
            $completeStudents->execute(['semester_id' => $semesterId]);
            $completedStudents = $completeStudents->rowCount();

            $archiveSemesterStudents = $this->pdo->prepare(
                "UPDATE semester_students
                 SET enrollment_status = 'archived',
                     updated_at = NOW()
                 WHERE semester_id = :semester_id
                   AND enrollment_status <> 'archived'"
            );
            $archiveSemesterStudents->execute(['semester_id' => $semesterId]);
            $archivedSemesterStudents = $archiveSemesterStudents->rowCount();

            // Section deployments are no longer used. Deployments are managed
            // through coordinator_deployments and student_geofence_assignments.
            $endedDeployments = 0;

            $resetSummary = $this->resetAcademicCycleAssets();
            $deactivatedCoordinators = (int) ($resetSummary['deactivated_coordinators'] ?? 0);
            $inactiveAssignments = (int) ($resetSummary['inactive_geofence_assignments'] ?? 0);

            // Checklist definitions are global in the current schema, so
            // archiving one semester must not disable them system-wide.
            $disabledChecklistItems = 0;

            $this->insertSemesterArchiveRecordIfSupported($semesterId);
            $auditLog->log($actorUserId, 'semester_ended', 'semester', $semesterId, [
                'auto_closed_attendance_sessions' => $autoClosedAttendance,
                'reason' => 'semester_ended',
            ]);

            // The completed semester must no longer leave its school year
            // flagged active. A remaining planned term stays planned; years
            // containing only historical terms become archived.
            (new SemesterLifecycleIntegrityService($this->pdo))->repair();

            $this->pdo->commit();

            return [
                'semester_id' => $semesterId,
                'current_semester_id' => null,
                'semester_name' => (string) ($semester['semester_name'] ?? ''),
                'semester_status' => 'completed',
                'completed_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
                'deactivated_sections' => $deactivatedSections,
                'completed_students' => $archivedSemesterStudents,
                'archived_section_students' => $completedStudents,
                'ended_deployments' => $endedDeployments,
                'ended_coordinator_deployments' => (int) ($resetSummary['ended_coordinator_deployments'] ?? 0),
                'deactivated_coordinators' => $deactivatedCoordinators,
                'cleared_coordinator_assignments' => (int) ($resetSummary['cleared_coordinator_assignments'] ?? 0),
                'deactivated_partner_schools' => (int) ($resetSummary['deactivated_partner_schools'] ?? 0),
                'deactivated_geofences' => (int) ($resetSummary['deactivated_geofences'] ?? 0),
                'inactive_geofence_assignments' => $inactiveAssignments,
                'archived_supervisor_assignments' => (int) ($supervisorLifecycle['assignments'] ?? 0),
                'deactivated_supervisors' => (int) ($supervisorLifecycle['supervisors'] ?? 0),
                'disabled_checklist_items' => $disabledChecklistItems,
                'auto_closed_attendance_sessions' => $autoClosedAttendance,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function findUserByEmailOrSchoolId(string $email, string $schoolId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.role_id, r.role_code, u.email, u.school_id, u.account_status
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email OR u.school_id = :school_id
             LIMIT 1'
        );
        $stmt->execute([
            'email' => $email,
            'school_id' => $schoolId,
        ]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function ensureSemesterStudentsTable(): void
    {
        if (!$this->tableExists('semester_students')) {
            $this->pdo->exec(
                "CREATE TABLE semester_students (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    semester_id INT UNSIGNED NOT NULL,
                    student_user_id BIGINT UNSIGNED NOT NULL,
                    enrollment_status ENUM('active', 'inactive', 'archived', 'dropped', 'completed') NOT NULL DEFAULT 'active',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_semester_students_student (semester_id, student_user_id),
                    KEY idx_semester_students_student (student_user_id),
                    KEY idx_semester_students_status (semester_id, enrollment_status),
                    CONSTRAINT fk_semester_students_semester
                        FOREIGN KEY (semester_id) REFERENCES semesters(id)
                        ON DELETE RESTRICT ON UPDATE CASCADE,
                    CONSTRAINT fk_semester_students_user
                        FOREIGN KEY (student_user_id) REFERENCES users(id)
                        ON DELETE RESTRICT ON UPDATE CASCADE
                ) ENGINE=InnoDB"
            );
        }

        if ($this->tableExists('section_students') && $this->columnExists('section_students', 'enrollment_status')) {
            $this->pdo->exec(
                "ALTER TABLE section_students
                 MODIFY enrollment_status ENUM('active', 'inactive', 'archived', 'dropped', 'completed') NOT NULL DEFAULT 'active'"
            );
        }

        $this->backfillSemesterStudents();
    }

    private function ensureStudentGeofenceAssignmentSemesterKey(): void
    {
        if (!$this->tableExists('student_geofence_assignments')) {
            return;
        }

        if (!$this->columnExists('student_geofence_assignments', 'coordinator_user_id')) {
            $this->pdo->exec(
                'ALTER TABLE student_geofence_assignments
                 ADD COLUMN coordinator_user_id BIGINT UNSIGNED NULL AFTER geofence_id'
            );
        }

        if (!$this->columnExists('student_geofence_assignments', 'semester_id')) {
            $this->pdo->exec(
                'ALTER TABLE student_geofence_assignments
                 ADD COLUMN semester_id INT UNSIGNED NULL AFTER coordinator_user_id'
            );
        }

        if (!$this->indexExists('student_geofence_assignments', 'uq_student_geofence_semester')) {
            if ($this->indexExists('student_geofence_assignments', 'uq_student_geofence')) {
                $this->pdo->exec('ALTER TABLE student_geofence_assignments DROP INDEX uq_student_geofence');
            }

            $this->pdo->exec(
                'ALTER TABLE student_geofence_assignments
                 ADD UNIQUE KEY uq_student_geofence_semester (student_user_id, geofence_id, semester_id)'
            );
        }

        $this->backfillArchivedGeofenceAssignments();
    }

    private function backfillArchivedGeofenceAssignments(): void
    {
        if (!$this->tableExists('semester_students') || !$this->tableExists('semesters')) {
            return;
        }

        // The source table can contain more than one historical assignment
        // for a student/geofence pair.  Deduplicate the source and ignore an
        // existing unique key so rerunning activation/migrations is safe.
        $this->pdo->exec(
            "INSERT IGNORE INTO student_geofence_assignments
                (student_user_id, geofence_id, coordinator_user_id, semester_id, assigned_at, assignment_status)
             SELECT
                current_sga.student_user_id,
                current_sga.geofence_id,
                MAX(current_sga.coordinator_user_id),
                sems.semester_id,
                MIN(current_sga.assigned_at),
                'inactive'
             FROM student_geofence_assignments current_sga
             JOIN semester_students sems
               ON sems.student_user_id = current_sga.student_user_id
              AND sems.enrollment_status IN ('archived', 'completed', 'inactive')
             JOIN semesters sem
               ON sem.id = sems.semester_id
              AND sem.semester_status IN ('closed', 'archived')
             WHERE current_sga.coordinator_user_id IS NOT NULL
               AND (current_sga.semester_id IS NULL OR current_sga.semester_id <> sems.semester_id)
               AND NOT EXISTS (
                   SELECT 1
                   FROM student_geofence_assignments existing_sga
                   WHERE existing_sga.student_user_id = current_sga.student_user_id
                     AND existing_sga.geofence_id = current_sga.geofence_id
                     AND existing_sga.semester_id = sems.semester_id
               )
             GROUP BY current_sga.student_user_id, current_sga.geofence_id, sems.semester_id"
        );
    }

    private function backfillSemesterStudents(): void
    {
        if (!$this->tableExists('semester_students')) {
            return;
        }

        if ($this->tableExists('section_students')) {
            $this->pdo->exec(
                "INSERT INTO semester_students (semester_id, student_user_id, enrollment_status)
                 SELECT ss.semester_id,
                        ss.student_user_id,
                        CASE
                            WHEN ss.enrollment_status = 'completed' THEN 'archived'
                            WHEN ss.enrollment_status IN ('active', 'inactive', 'archived', 'dropped') THEN ss.enrollment_status
                            ELSE 'inactive'
                        END
                 FROM section_students ss
                 ON DUPLICATE KEY UPDATE
                    enrollment_status = IF(semester_students.enrollment_status = 'active', 'active', VALUES(enrollment_status)),
                    updated_at = NOW()"
            );
        }

        if ($this->columnExists('user_profiles', 'imported_semester_id')) {
            $this->pdo->exec(
                "INSERT INTO semester_students (semester_id, student_user_id, enrollment_status)
                 SELECT up.imported_semester_id, up.user_id, 'active'
                 FROM user_profiles up
                 JOIN users u ON u.id = up.user_id
                 JOIN roles r ON r.id = u.role_id
                 JOIN semesters sem ON sem.id = up.imported_semester_id
                WHERE up.imported_semester_id IS NOT NULL
                  AND r.role_code = 'student'
                  AND sem.semester_status = 'active'
                 ON DUPLICATE KEY UPDATE
                    enrollment_status = IF(semester_students.enrollment_status = 'archived', 'archived', VALUES(enrollment_status)),
                    updated_at = NOW()"
            );
        }
    }

    private function studentHasSemesterEnrollment(int $semesterId, int $studentUserId, string $status = 'active'): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM semester_students
             WHERE semester_id = :semester_id
               AND student_user_id = :student_user_id
               AND enrollment_status = :enrollment_status
             LIMIT 1'
        );
        $stmt->execute([
            'semester_id' => $semesterId,
            'student_user_id' => $studentUserId,
            'enrollment_status' => $status,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function upsertSemesterStudentEnrollment(int $semesterId, int $studentUserId, string $status = 'active'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO semester_students (semester_id, student_user_id, enrollment_status)
             VALUES (:semester_id, :student_user_id, :enrollment_status)
             ON DUPLICATE KEY UPDATE
                enrollment_status = VALUES(enrollment_status),
                updated_at = NOW()'
        );
        $stmt->execute([
            'semester_id' => $semesterId,
            'student_user_id' => $studentUserId,
            'enrollment_status' => $status,
        ]);
    }

    private function reactivateStudentAccountForSemester(int $userId, array $payload): void
    {
        $schoolId = trim((string) ($payload['school_id'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($schoolId === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid student reactivation data.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET school_id = :school_id,
                 email = :email,
                 password_hash = :password_hash,
                 account_status = \'active\',
                 must_change_password = 1,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $userId,
            'school_id' => $schoolId,
            'email' => $email,
            'password_hash' => password_hash($schoolId, PASSWORD_DEFAULT),
        ]);

        $this->upsertUserProfile($userId, $payload);
    }

    private function createUserAccountWithProfileByRole(string $roleCode, array $payload): int
    {
        $this->pdo->beginTransaction();
        try {
            $userId = $this->persistUserAccountWithProfileByRole($roleCode, $payload);
            $this->pdo->commit();
            return $userId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function persistUserAccountWithProfileByRole(string $roleCode, array $payload): int
    {
        $required = ['school_id', 'email', 'password', 'first_name', 'last_name'];
        foreach ($required as $field) {
            if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                throw new InvalidArgumentException('Missing required field: ' . $field);
            }
        }
        $email = filter_var((string) $payload['email'], FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            throw new InvalidArgumentException('Invalid email');
        }

        $roleId = $this->getRoleIdByCode($roleCode);
        if ($roleId === null) {
            throw new RuntimeException('Role not found: ' . $roleCode);
        }

        $params = [
            'role_id' => $roleId,
            'school_id' => trim((string) $payload['school_id']),
            'email' => $email,
            'password_hash' => password_hash((string) $payload['password'], PASSWORD_BCRYPT),
            'account_status' => 'active',
        ];
        $supportsMustChangePassword = $this->columnExists('users', 'must_change_password');

        if ($supportsMustChangePassword) {
            $params['must_change_password'] = !empty($payload['must_change_password']) ? 1 : 0;
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (role_id, school_id, email, password_hash, account_status, must_change_password)
                 VALUES (:role_id, :school_id, :email, :password_hash, :account_status, :must_change_password)'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (role_id, school_id, email, password_hash, account_status)
                 VALUES (:role_id, :school_id, :email, :password_hash, :account_status)'
            );
        }

        $stmt->execute($params);
        $userId = (int) $this->pdo->lastInsertId();

        $this->insertUserProfile($userId, $payload);

        return $userId;
    }

    private function reactivateCoordinatorAccountById(int $userId, array $payload): void
    {
        $schoolId = trim((string) ($payload['school_id'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($schoolId === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid coordinator reactivation data.');
        }

        $params = [
            'id' => $userId,
            'school_id' => $schoolId,
            'email' => $email,
            'password_hash' => password_hash($schoolId, PASSWORD_DEFAULT),
        ];

        $supportsMustChangePassword = $this->columnExists('users', 'must_change_password');
        if ($supportsMustChangePassword) {
            $stmt = $this->pdo->prepare(
                'UPDATE users
                 SET school_id = :school_id,
                     email = :email,
                     password_hash = :password_hash,
                     account_status = \'active\',
                     must_change_password = 1,
                     updated_at = NOW()
                 WHERE id = :id'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE users
                 SET school_id = :school_id,
                     email = :email,
                     password_hash = :password_hash,
                     account_status = \'active\',
                     updated_at = NOW()
                 WHERE id = :id'
            );
        }

        $stmt->execute($params);
    }

    private function persistCoordinatorSchoolAssignment(int $coordinatorUserId, int $geofenceId, int $assignedBy): void
    {
        $this->assertUserRole($coordinatorUserId, 'coordinator');
        $this->assertUserExists($assignedBy, 'Assigning user not found.');
        $this->assertGeofenceExists($geofenceId, 'Partnered school not found.');

        $stmt = $this->pdo->prepare(
            'INSERT INTO coordinator_schools (coordinator_user_id, geofence_id, assigned_by, assigned_at)
             VALUES (:coordinator_user_id, :geofence_id, :assigned_by, NOW())
             ON DUPLICATE KEY UPDATE
                geofence_id = VALUES(geofence_id),
                assigned_by = VALUES(assigned_by),
                assigned_at = VALUES(assigned_at)'
        );
        $stmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'geofence_id' => $geofenceId,
            'assigned_by' => $assignedBy,
        ]);
    }

    private function ensureCoordinatorSemesterLinksTable(): void
    {
        if ($this->tableExists('coordinator_semester_links')) {
            return;
        }
        $this->pdo->exec(
            "CREATE TABLE coordinator_semester_links (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                coordinator_user_id BIGINT UNSIGNED NOT NULL,
                semester_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_coordinator_semester_link (coordinator_user_id, semester_id),
                KEY idx_coordinator_semester_semester (semester_id)
            ) ENGINE=InnoDB"
        );
    }

    private function getActiveSemesterIdForCoordinatorProvisioning(): int
    {
        return (int) ($this->pdo->query("SELECT id FROM semesters WHERE semester_status = 'active' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
    }

    private function coordinatorHasSemesterLink(int $userId, int $semesterId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM coordinator_semester_links WHERE coordinator_user_id = :user_id AND semester_id = :semester_id LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'semester_id' => $semesterId]);
        return $stmt->fetchColumn() !== false;
    }

    private function upsertCoordinatorSemesterLink(int $userId, int $semesterId): void
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO coordinator_semester_links (coordinator_user_id, semester_id) VALUES (:user_id, :semester_id)');
        $stmt->execute(['user_id' => $userId, 'semester_id' => $semesterId]);
    }

    private function resetCoordinatorAccountsForAcademicCycle(): array
    {
        $deactivatedCoordinators = 0;
        if ($this->tableExists('users') && $this->tableExists('roles')) {
            $deactivate = $this->pdo->prepare(
                "UPDATE users u
                 JOIN roles r ON r.id = u.role_id
                 SET u.account_status = 'inactive'
                 WHERE r.role_code = 'coordinator'
                   AND u.account_status <> 'inactive'"
            );
            $deactivate->execute();
            $deactivatedCoordinators = $deactivate->rowCount();
        }

        return [
            'deactivated_coordinators' => $deactivatedCoordinators,
            'cleared_coordinator_assignments' => 0,
            'ended_coordinator_deployments' => 0,
        ];
    }

    public function resetAcademicCycleAssets(): array
    {
        $summary = $this->resetCoordinatorAccountsForAcademicCycle();

        $clearedCoordinatorAssignments = 0;
        if ($this->tableExists('coordinator_schools')) {
            $clearAssignments = $this->pdo->prepare('DELETE FROM coordinator_schools');
            $clearAssignments->execute();
            $clearedCoordinatorAssignments = $clearAssignments->rowCount();
        }

        $endedCoordinatorDeployments = 0;
        if ($this->tableExists('coordinator_deployments')) {
            $endDeployments = $this->pdo->prepare(
                "UPDATE coordinator_deployments
                 SET deployment_status = 'ended',
                     ended_at = NOW()
                 WHERE deployment_status = 'active'"
            );
            $endDeployments->execute();
            $endedCoordinatorDeployments = $endDeployments->rowCount();
        }

        $deactivatedPartnerSchools = 0;
        if ($this->tableExists('partner_schools')) {
            $deactivateSchools = $this->pdo->prepare(
                "UPDATE partner_schools
                 SET school_status = 'inactive',
                     updated_at = NOW()"
            );
            $deactivateSchools->execute();
            $deactivatedPartnerSchools = $deactivateSchools->rowCount();
        }

        $deactivatedGeofences = 0;
        if ($this->tableExists('geofence_locations')) {
            $deactivateGeofences = $this->pdo->prepare(
                "UPDATE geofence_locations
                 SET is_active = 0,
                     updated_at = NOW()"
            );
            $deactivateGeofences->execute();
            $deactivatedGeofences = $deactivateGeofences->rowCount();
        }

        $endedSectionDeployments = 0;
        if ($this->tableExists('section_deployments')) {
            $endSectionDeployments = $this->pdo->prepare(
                "UPDATE section_deployments
                 SET deployment_status = 'ended',
                     ended_at = NOW(),
                     updated_at = NOW()
                 WHERE deployment_status = 'running'"
            );
            $endSectionDeployments->execute();
            $endedSectionDeployments = $endSectionDeployments->rowCount();
        }

        $inactiveGeofenceAssignments = 0;
        if ($this->tableExists('student_geofence_assignments')) {
            $deactivateAssignments = $this->pdo->prepare(
                "UPDATE student_geofence_assignments
                 SET assignment_status = 'inactive'
                 WHERE assignment_status <> 'inactive'"
            );
            $deactivateAssignments->execute();
            $inactiveGeofenceAssignments = $deactivateAssignments->rowCount();
        }

        $summary['cleared_coordinator_assignments'] = $clearedCoordinatorAssignments;
        $summary['deactivated_partner_schools'] = $deactivatedPartnerSchools;
        $summary['deactivated_geofences'] = $deactivatedGeofences;
        $summary['ended_coordinator_deployments'] = $endedCoordinatorDeployments;
        $summary['ended_section_deployments'] = $endedSectionDeployments;
        $summary['inactive_geofence_assignments'] = $inactiveGeofenceAssignments;

        return $summary;
    }

    private function getRoleIdByCode(string $roleCode): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE role_code = :role_code LIMIT 1');
        $stmt->execute(['role_code' => $roleCode]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name
             LIMIT 1'
        );
        $stmt->execute([
            'table_name' => $table,
            'index_name' => $index,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function getSemester(int $semesterId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, school_year_id, semester_no, semester_name, semester_status, start_date, practicum_start_date, practicum_status
             FROM semesters
             WHERE id = :semester_id
             LIMIT 1'
        );
        $stmt->execute(['semester_id' => $semesterId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function semesterHasAttendanceRecords(int $semesterId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM attendance_records WHERE semester_id = :semester_id LIMIT 1');
        $stmt->execute(['semester_id' => $semesterId]);
        return $stmt->fetchColumn() !== false;
    }

    private function getActiveSemester(): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT id, school_year_id, semester_no, semester_name, semester_status, start_date, practicum_start_date, practicum_status
             FROM semesters
             WHERE semester_status = 'active'
             ORDER BY start_date ASC, semester_no ASC
             LIMIT 1"
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function getNextPlannedSemester(): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT id, school_year_id, semester_no, semester_name, semester_status, start_date, practicum_start_date, practicum_status
             FROM semesters
             WHERE semester_status = 'planned'
             ORDER BY start_date ASC, semester_no ASC
             LIMIT 1"
        );
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function assertRecordExists(string $table, int $id, string $message): void
    {
        $allowed = ['school_years', 'semesters', 'sections'];
        if (!in_array($table, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported table check');
        }
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException($message);
        }
    }

    private function assertUserExists(int $userId, string $message): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException($message);
        }
    }

    private function assertGeofenceExists(int $geofenceId, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.id
             FROM geofence_locations g
             JOIN partner_schools ps ON ps.geofence_id = g.id
             WHERE g.id = :geofence_id
             LIMIT 1'
        );
        $stmt->execute(['geofence_id' => $geofenceId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException($message);
        }
    }

    private function assertUserRole(int $userId, string $roleCode): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id
               AND r.role_code = :role_code
               AND u.account_status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'role_code' => $roleCode,
        ]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("User {$userId} is not an active {$roleCode}");
        }
    }

    private function getSection(int $sectionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, semester_id, capacity
             FROM sections
             WHERE id = :section_id
             LIMIT 1'
        );
        $stmt->execute(['section_id' => $sectionId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function getActiveSectionStudentCount(int $sectionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM section_students
             WHERE section_id = :section_id
               AND enrollment_status = \'active\''
        );
        $stmt->execute(['section_id' => $sectionId]);
        return (int) $stmt->fetchColumn();
    }

    private function assertStudentNotEnrolledInOtherSectionSameSemester(int $studentUserId, int $semesterId, int $sectionId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT section_id
             FROM section_students
             WHERE student_user_id = :student_user_id
               AND semester_id = :semester_id
               AND enrollment_status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
        ]);
        $existingSectionId = $stmt->fetchColumn();
        if ($existingSectionId !== false && (int) $existingSectionId !== $sectionId) {
            throw new RuntimeException("Student {$studentUserId} is already enrolled in another section for this semester");
        }
    }

    private function tableExists(string $table): bool
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

    private function insertSemesterArchiveRecordIfSupported(int $semesterId): void
    {
        $table = 'history_school_year_archive';
        if (!$this->tableExists($table)) {
            return;
        }

        if ($this->columnExists($table, 'semester_id') && $this->columnExists($table, 'archived_at')) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO history_school_year_archive (semester_id, archived_at)
                 VALUES (:semester_id, NOW())"
            );
            $stmt->execute(['semester_id' => $semesterId]);
        }
    }

    private function assertValidDate(string $date, string $field): void
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid {$field}");
        }
    }

    private function assertValidPracticumStartDate(string $date): void
    {
        $this->assertValidDate($date, 'practicum_start_date');
        $candidate = new DateTimeImmutable($date, new DateTimeZone('Asia/Manila'));
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));

        if ($candidate < $today) {
            throw new InvalidArgumentException('Practicum start date must be today or a future date.');
        }
        if ($candidate->format('w') === '0') {
            throw new InvalidArgumentException('Practicum start date cannot be a Sunday. Choose Monday through Saturday.');
        }
    }

    private function insertUserProfile(int $userId, array $payload): void
    {
        $profileData = [
            'user_id' => $userId,
            'first_name' => trim((string) $payload['first_name']),
            'middle_name' => isset($payload['middle_name']) ? $this->nullableTrim($payload['middle_name']) : null,
            'last_name' => trim((string) $payload['last_name']),
            'phone' => isset($payload['phone']) ? $this->nullableTrim($payload['phone']) : null,
            'photo_path' => isset($payload['photo_path']) ? $this->nullableTrim($payload['photo_path']) : null,
        ];

        $optionalColumns = [
            'gender',
            'campus',
            'college',
            'course',
            'subject',
            'original_section',
            'semester_name',
            'imported_semester_id',
        ];

        foreach ($optionalColumns as $column) {
            if ($this->columnExists('user_profiles', $column)) {
                $profileData[$column] = array_key_exists($column, $payload)
                    ? $this->nullableTrim($payload[$column])
                    : null;
            }
        }

        $columns = array_keys($profileData);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $sql = sprintf(
            'INSERT INTO user_profiles (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($profileData);
    }

    private function upsertUserProfile(int $userId, array $payload): void
    {
        $profileData = [
            'user_id' => $userId,
            'first_name' => trim((string) $payload['first_name']),
            'middle_name' => isset($payload['middle_name']) ? $this->nullableTrim($payload['middle_name']) : null,
            'last_name' => trim((string) $payload['last_name']),
            'phone' => isset($payload['phone']) ? $this->nullableTrim($payload['phone']) : null,
            'photo_path' => isset($payload['photo_path']) ? $this->nullableTrim($payload['photo_path']) : null,
        ];

        $optionalColumns = [
            'gender',
            'campus',
            'college',
            'course',
            'subject',
            'original_section',
            'semester_name',
            'imported_semester_id',
        ];

        foreach ($optionalColumns as $column) {
            if ($this->columnExists('user_profiles', $column)) {
                $profileData[$column] = array_key_exists($column, $payload)
                    ? $this->nullableTrim($payload[$column])
                    : null;
            }
        }

        $columns = array_keys($profileData);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        $updates = [];
        foreach ($columns as $column) {
            if ($column === 'user_id') {
                continue;
            }
            $updates[] = $column . ' = VALUES(' . $column . ')';
        }

        $sql = sprintf(
            'INSERT INTO user_profiles (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', $updates)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($profileData);
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function ensureOfficialMasterlistProfileColumns(): void
    {
        $requiredColumns = [
            'gender' => 'ALTER TABLE user_profiles ADD COLUMN gender VARCHAR(32) NULL AFTER last_name',
            'campus' => 'ALTER TABLE user_profiles ADD COLUMN campus VARCHAR(150) NULL AFTER gender',
            'college' => 'ALTER TABLE user_profiles ADD COLUMN college VARCHAR(200) NULL AFTER campus',
            'course' => 'ALTER TABLE user_profiles ADD COLUMN course VARCHAR(200) NULL AFTER college',
            'subject' => 'ALTER TABLE user_profiles ADD COLUMN subject VARCHAR(200) NULL AFTER course',
            'original_section' => 'ALTER TABLE user_profiles ADD COLUMN original_section VARCHAR(120) NULL AFTER subject',
            'semester_name' => 'ALTER TABLE user_profiles ADD COLUMN semester_name VARCHAR(120) NULL AFTER original_section',
            'imported_semester_id' => 'ALTER TABLE user_profiles ADD COLUMN imported_semester_id INT UNSIGNED NULL AFTER semester_name',
        ];

        foreach ($requiredColumns as $column => $sql) {
            if (!$this->columnExists('user_profiles', $column)) {
                $this->pdo->exec($sql);
            }
        }
    }

    private function ensureSemesterPracticumStartDateColumn(): void
    {
        if (!$this->tableExists('semesters')) {
            return;
        }

        if (!$this->columnExists('semesters', 'practicum_start_date')) {
            $this->pdo->exec('ALTER TABLE semesters ADD COLUMN practicum_start_date DATE NULL AFTER start_date');
        }
    }

    /**
     * Adds the explicit completed lifecycle state without discarding legacy
     * archived/closed rows. Those older rows remain readable as history.
     */
    private function ensureSemesterCompletionLifecycle(): void
    {
        if (!$this->tableExists('semesters')) {
            return;
        }

        if (!$this->columnExists('semesters', 'completed_at')) {
            $this->pdo->exec('ALTER TABLE semesters ADD COLUMN completed_at DATETIME NULL AFTER practicum_status');
        }

        $typeStmt = $this->pdo->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name
             LIMIT 1'
        );
        $typeStmt->execute(['table_name' => 'semesters', 'column_name' => 'semester_status']);
        if (strtolower((string) ($typeStmt->fetchColumn() ?: '')) === 'enum') {
            $this->pdo->exec(
                "ALTER TABLE semesters
                 MODIFY semester_status ENUM('planned', 'active', 'completed', 'closed', 'archived') NOT NULL DEFAULT 'planned'"
            );
        }
    }

    private function ensureSemesterPracticumStatusColumn(): void
    {
        if (!$this->tableExists('semesters')) {
            return;
        }

        if (!$this->columnExists('semesters', 'practicum_status')) {
            $this->pdo->exec("ALTER TABLE semesters ADD COLUMN practicum_status VARCHAR(24) NOT NULL DEFAULT 'not_configured' AFTER practicum_start_date");
            $this->pdo->exec(
                "UPDATE semesters
                 SET practicum_status = CASE
                    WHEN semester_status = 'active' AND practicum_start_date IS NOT NULL THEN 'not_started'
                    WHEN semester_status = 'active' THEN 'not_started'
                    WHEN semester_status IN ('closed', 'archived') THEN 'ended'
                    ELSE 'not_started'
                 END"
            );
        }

        // Older installations used an ENUM that cannot store the current
        // lifecycle values (not_started, scheduled, ended). Convert it once
        // before normalizing rows so archived semesters cannot crash pages.
        $typeStmt = $this->pdo->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name
             LIMIT 1'
        );
        $typeStmt->execute(['table_name' => 'semesters', 'column_name' => 'practicum_status']);
        if (strtolower((string) ($typeStmt->fetchColumn() ?: '')) === 'enum') {
            $this->pdo->exec("ALTER TABLE semesters MODIFY practicum_status VARCHAR(24) NOT NULL DEFAULT 'not_started'");
        }

        // Upgrade the brief transitional label used by the prior release.
        $this->pdo->exec("UPDATE semesters SET practicum_status = 'not_started' WHERE practicum_status IN ('establishing', 'not_yet_started', 'not_configured')");
        $this->pdo->exec("UPDATE semesters SET practicum_status = 'ended' WHERE practicum_status = 'completed'");
    }

    private function ensureCoordinatorSchoolTable(): void
    {
        if ($this->tableExists('coordinator_schools')) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE coordinator_schools (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                coordinator_user_id BIGINT UNSIGNED NOT NULL,
                geofence_id BIGINT UNSIGNED NOT NULL,
                assigned_by BIGINT UNSIGNED NOT NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_coordinator_school (coordinator_user_id),
                KEY idx_coordinator_schools_geofence (geofence_id),
                CONSTRAINT fk_coordinator_schools_user
                    FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_coordinator_schools_geofence
                    FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_coordinator_schools_assigned_by
                    FOREIGN KEY (assigned_by) REFERENCES users(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB"
        );
    }

    private function normalizeOfficialHeaderRow(array $header): array
    {
        $normalized = [];
        foreach ($header as $index => $value) {
            $cell = trim((string) $value);
            if ($index === 0) {
                $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;
            }
            $normalized[] = $cell;
        }

        return $normalized;
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function mapOfficialMasterlistRow(array $row, int $activeSemesterId): array
    {
        $columns = array_map(static fn($value): string => trim((string) $value), $row);
        if (count($columns) < 10) {
            throw new InvalidArgumentException('Expected at least 10 columns.');
        }

        $sessionName = $columns[0];
        $campus = $columns[1];
        $schoolId = $columns[2];
        $email = $columns[count($columns) - 1];
        $section = $columns[count($columns) - 2];
        $subject = $columns[count($columns) - 3];
        $course = $columns[count($columns) - 4];
        $college = $columns[count($columns) - 5];
        $gender = $columns[count($columns) - 6];
        $nameParts = array_slice($columns, 3, count($columns) - 9);
        $fullName = trim(implode(', ', $nameParts));

        if (
            $sessionName === '' || $campus === '' || $schoolId === '' || $fullName === '' ||
            $gender === '' || $college === '' || $course === '' || $subject === '' ||
            $section === '' || $email === ''
        ) {
            throw new InvalidArgumentException('One or more required fields are blank.');
        }

        $nameParts = $this->parseOfficialStudentName($fullName);

        return [
            'school_id' => $schoolId,
            'email' => strtolower($email),
            'first_name' => $nameParts['first_name'],
            'middle_name' => $nameParts['middle_name'],
            'last_name' => $nameParts['last_name'],
            'gender' => $gender,
            'campus' => $campus,
            'college' => $college,
            'course' => $course,
            'subject' => $subject,
            'original_section' => $section,
            'semester_name' => $sessionName,
            'imported_semester_id' => (string) $activeSemesterId,
        ];
    }

    private function parseOfficialStudentName(string $fullName): array
    {
        $nameParts = explode(',', $fullName);

        $lastName = trim($nameParts[0] ?? '');
        $givenNames = trim($nameParts[1] ?? '');

        if ($lastName === '' || $givenNames === '') {
            throw new InvalidArgumentException('Student name must follow LASTNAME, FIRST MIDDLE format.');
        }

        $givenParts = preg_split('/\s+/', $givenNames) ?: [];
        $firstName = array_shift($givenParts) ?? '';
        $middleName = trim(implode(' ', $givenParts));

        if ($firstName === '') {
            throw new InvalidArgumentException('Student first name is missing.');
        }

        return [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
        ];
    }
}
