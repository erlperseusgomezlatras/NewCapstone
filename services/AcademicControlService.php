<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class AcademicControlService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
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
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
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

    public function createStudentAccountWithProfile(array $payload): int
    {
        return $this->createUserAccountWithProfileByRole('student', $payload);
    }

    public function createCoordinatorAccountWithProfile(array $payload): int
    {
        return $this->createUserAccountWithProfileByRole('coordinator', $payload);
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

    private function createUserAccountWithProfileByRole(string $roleCode, array $payload): int
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

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (role_id, school_id, email, password_hash, account_status)
                 VALUES (:role_id, :school_id, :email, :password_hash, :account_status)'
            );
            $stmt->execute([
                'role_id' => $roleId,
                'school_id' => trim((string) $payload['school_id']),
                'email' => $email,
                'password_hash' => password_hash((string) $payload['password'], PASSWORD_BCRYPT),
                'account_status' => 'active',
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $profileStmt = $this->pdo->prepare(
                'INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, phone, photo_path)
                 VALUES (:user_id, :first_name, :middle_name, :last_name, :phone, :photo_path)'
            );
            $profileStmt->execute([
                'user_id' => $userId,
                'first_name' => trim((string) $payload['first_name']),
                'middle_name' => isset($payload['middle_name']) ? trim((string) $payload['middle_name']) : null,
                'last_name' => trim((string) $payload['last_name']),
                'phone' => isset($payload['phone']) ? trim((string) $payload['phone']) : null,
                'photo_path' => isset($payload['photo_path']) ? trim((string) $payload['photo_path']) : null,
            ]);

            $this->pdo->commit();
            return $userId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function getRoleIdByCode(string $roleCode): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE role_code = :role_code LIMIT 1');
        $stmt->execute(['role_code' => $roleCode]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
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

    private function assertValidDate(string $date, string $field): void
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid {$field}");
        }
    }
}
