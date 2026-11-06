<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/EvaluationFormService.php';
require_once __DIR__ . '/StudentCredentialMailer.php';

final class SupervisorService
{
    private StudentCredentialMailer $credentialMailer;

    public function __construct(private PDO $pdo)
    {
        $this->credentialMailer = new StudentCredentialMailer();
        new EvaluationFormService($pdo);
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        if (!$this->tableExists('supervisor_assignments')) {
            $path = __DIR__ . '/../config/supervisor_module.sql';
            if (!is_readable($path)) throw new RuntimeException('Supervisor module database schema is missing.');
            $this->pdo->exec((string) file_get_contents($path));
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS supervisor_assignment_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id BIGINT UNSIGNED NOT NULL,
            supervisor_id BIGINT UNSIGNED NOT NULL,
            supervisor_name VARCHAR(255) NULL,
            supervisor_email VARCHAR(255) NULL,
            partnered_school VARCHAR(255) NULL,
            position_title VARCHAR(150) NULL,
            semester_id INT UNSIGNED NOT NULL,
            assignment_status ENUM('archived') NOT NULL DEFAULT 'archived',
            assigned_at DATETIME NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_supervisor_assignment_history (student_id, semester_id),
            KEY idx_supervisor_history_supervisor (supervisor_id),
            CONSTRAINT fk_supervisor_history_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_supervisor_history_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_supervisor_history_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        foreach ([
            'supervisor_name' => 'ALTER TABLE supervisor_assignment_history ADD COLUMN supervisor_name VARCHAR(255) NULL AFTER supervisor_id',
            'supervisor_email' => 'ALTER TABLE supervisor_assignment_history ADD COLUMN supervisor_email VARCHAR(255) NULL AFTER supervisor_name',
            'partnered_school' => 'ALTER TABLE supervisor_assignment_history ADD COLUMN partnered_school VARCHAR(255) NULL AFTER supervisor_email',
            'position_title' => 'ALTER TABLE supervisor_assignment_history ADD COLUMN position_title VARCHAR(150) NULL AFTER partnered_school',
        ] as $column => $ddl) {
            if (!$this->columnExists('supervisor_assignment_history', $column)) $this->pdo->exec($ddl);
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS supervisor_semester_access (
            supervisor_id BIGINT UNSIGNED NOT NULL,
            semester_id INT UNSIGNED NOT NULL,
            access_status ENUM('active', 'archived', 'inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (supervisor_id, semester_id),
            CONSTRAINT fk_supervisor_access_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_supervisor_access_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS supervisor_student_selections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supervisor_id BIGINT UNSIGNED NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            semester_id INT UNSIGNED NOT NULL,
            selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_supervisor_selection_student_semester (student_id, semester_id),
            UNIQUE KEY uq_supervisor_selection_supervisor_student_semester (supervisor_id, student_id, semester_id),
            KEY idx_supervisor_selection_supervisor_semester (supervisor_id, semester_id),
            CONSTRAINT fk_supervisor_selection_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_supervisor_selection_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_supervisor_selection_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_submission_certification (
            submission_id BIGINT UNSIGNED PRIMARY KEY,
            supervisor_name VARCHAR(255) NULL,
            supervisor_position VARCHAR(255) NULL,
            signed_date DATE NULL,
            CONSTRAINT fk_submission_certification FOREIGN KEY (submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_submission_ratings (
            submission_id BIGINT UNSIGNED NOT NULL,
            rating_value VARCHAR(32) NOT NULL,
            rating_label VARCHAR(120) NOT NULL DEFAULT '',
            display_order INT NOT NULL,
            PRIMARY KEY (submission_id, display_order),
            CONSTRAINT fk_submission_rating_submission FOREIGN KEY (submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB");
        if ($this->columnExists('evaluation_signatures', 'mime_type') === false && $this->tableExists('evaluation_signatures')) {
            $this->pdo->exec("ALTER TABLE evaluation_signatures ADD COLUMN mime_type VARCHAR(50) NOT NULL DEFAULT 'image/png' AFTER signature_image");
        }
        if ($this->tableExists('evaluation_submissions')) {
            if (!$this->columnExists('evaluation_submissions', 'deployment_id')) {
                $this->pdo->exec('ALTER TABLE evaluation_submissions ADD COLUMN deployment_id BIGINT UNSIGNED NULL AFTER semester_id');
            }
            // Add the replacement first. The legacy two-column index may be
            // serving as the supporting index for fk_submission_form because
            // evaluation_form_id is its leftmost column. Dropping it first
            // makes MySQL reject the migration with error 1553.
            if (!$this->indexExists('evaluation_submissions', 'uq_evaluation_student_form_semester_deployment_supervisor')) {
                $this->pdo->exec('ALTER TABLE evaluation_submissions ADD UNIQUE KEY uq_evaluation_student_form_semester_deployment_supervisor (evaluation_form_id, student_id, semester_id, deployment_id, supervisor_id)');
            }
            foreach (['uq_evaluation_student_form_semester_deployment', 'uq_evaluation_student_form_semester', 'uq_evaluation_student_form'] as $legacyIndex) {
                if ($this->indexExists('evaluation_submissions', $legacyIndex)) {
                    $this->pdo->exec('ALTER TABLE evaluation_submissions DROP INDEX ' . $legacyIndex);
                }
            }
        }

        $activeSemester = $this->activeSemesterId();
        if ($activeSemester > 0) {
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO supervisor_semester_access (supervisor_id, semester_id, access_status)
                SELECT u.id, :semester_id, 'active'
                FROM users u JOIN roles r ON r.id = u.role_id AND r.role_code = 'supervisor'
                WHERE u.account_status = 'active'");
            $stmt->execute(['semester_id' => $activeSemester]);
        }

    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function activeSemesterId(): int
    {
        $stmt = $this->pdo->query("SELECT id FROM semesters WHERE semester_status = 'active' ORDER BY start_date DESC, id DESC LIMIT 1");
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function studentsAvailableForSelection(int $supervisorId): array
    {
        $semesterId = $this->activeSemesterId();
        if ($supervisorId <= 0 || $semesterId <= 0 || !$this->hasCurrentAccess($supervisorId)) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT
                u.id AS student_id,
                u.school_id,
                u.email,
                CONCAT_WS(' ', up.first_name, up.last_name) AS student_name,
                COALESCE(school.name, profile.partnered_school) AS partnered_school,
                up.course,
                COALESCE(NULLIF(up.original_section, ''), sec.section_name) AS section_name,
                CASE WHEN selection.id IS NULL THEN 0 ELSE 1 END AS selected
             FROM (
                SELECT deployment.student_user_id, deployment.geofence_id
                FROM student_school_deployments deployment
                WHERE deployment.semester_id = :deployment_semester_id
                  AND deployment.deployment_status = 'active'
                UNION ALL
                SELECT assignment.student_user_id, assignment.geofence_id
                FROM student_geofence_assignments assignment
                WHERE assignment.semester_id = :assignment_semester_id
                  AND assignment.assignment_status = 'active'
                  AND NOT EXISTS (
                    SELECT 1
                    FROM student_school_deployments active_deployment
                    WHERE active_deployment.student_user_id = assignment.student_user_id
                      AND active_deployment.semester_id = :fallback_semester_id
                      AND active_deployment.deployment_status = 'active'
                  )
             ) placement
             JOIN geofence_locations school
               ON school.id = placement.geofence_id
              AND school.is_active = 1
             JOIN partner_schools partner_school
               ON partner_school.geofence_id = school.id
              AND partner_school.school_status = 'active'
             JOIN supervisor_profiles profile
               ON profile.user_id = :profile_supervisor_id
              AND LOWER(TRIM(profile.partnered_school)) = LOWER(TRIM(school.name))
             JOIN users u
               ON u.id = placement.student_user_id
              AND u.account_status = 'active'
             JOIN roles role
               ON role.id = u.role_id
              AND role.role_code = 'student'
             JOIN semester_students enrollment
               ON enrollment.student_user_id = u.id
              AND enrollment.semester_id = :enrollment_semester_id
              AND enrollment.enrollment_status = 'active'
             JOIN semesters semester
               ON semester.id = enrollment.semester_id
              AND semester.semester_status = 'active'
             LEFT JOIN user_profiles up ON up.user_id = u.id
             LEFT JOIN section_students section_assignment
               ON section_assignment.student_user_id = u.id
              AND section_assignment.semester_id = enrollment.semester_id
              AND section_assignment.enrollment_status = 'active'
             LEFT JOIN sections sec ON sec.id = section_assignment.section_id
             LEFT JOIN supervisor_student_selections selection
               ON selection.student_id = u.id
              AND selection.semester_id = enrollment.semester_id
              AND selection.supervisor_id = :selection_supervisor_id
             WHERE NOT EXISTS (
                SELECT 1
                FROM supervisor_student_selections other_selection
                WHERE other_selection.student_id = u.id
                  AND other_selection.semester_id = enrollment.semester_id
                  AND other_selection.supervisor_id <> :other_supervisor_id
             )
             ORDER BY student_name, u.school_id"
        );
        $stmt->execute([
            'deployment_semester_id' => $semesterId,
            'assignment_semester_id' => $semesterId,
            'fallback_semester_id' => $semesterId,
            'profile_supervisor_id' => $supervisorId,
            'enrollment_semester_id' => $semesterId,
            'selection_supervisor_id' => $supervisorId,
            'other_supervisor_id' => $supervisorId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveStudentSelection(int $supervisorId, array $studentIds): void
    {
        if (count($studentIds) > 6) {
            throw new InvalidArgumentException('You can select up to 6 students.');
        }
        $selectedIds = array_values(array_unique(array_filter(
            array_map('intval', $studentIds),
            static fn(int $studentId): bool => $studentId > 0
        )));
        $semesterId = $this->activeSemesterId();
        if ($supervisorId <= 0 || $semesterId <= 0 || !$this->hasCurrentAccess($supervisorId)) {
            throw new RuntimeException('An active semester and supervisor account are required to select students.');
        }

        $this->pdo->beginTransaction();
        try {
            $supervisorLock = $this->pdo->prepare(
                "SELECT u.id
                 FROM users u
                 JOIN roles r ON r.id = u.role_id AND r.role_code = 'supervisor'
                 WHERE u.id = :supervisor_id AND u.account_status = 'active'
                 FOR UPDATE"
            );
            $supervisorLock->execute(['supervisor_id' => $supervisorId]);
            if ($supervisorLock->fetchColumn() === false || $this->activeSemesterId() !== $semesterId) {
                throw new RuntimeException('The active semester or supervisor access changed. Refresh and try again.');
            }

            $eligibleIds = array_map(
                static fn(array $student): int => (int) $student['student_id'],
                $this->studentsAvailableForSelection($supervisorId)
            );
            if (array_diff($selectedIds, $eligibleIds) !== []) {
                throw new InvalidArgumentException('Choose only active students assigned to your partnered school.');
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM supervisor_student_selections
                 WHERE supervisor_id = :supervisor_id AND semester_id = :semester_id'
            );
            $delete->execute(['supervisor_id' => $supervisorId, 'semester_id' => $semesterId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO supervisor_student_selections (supervisor_id, student_id, semester_id)
                 VALUES (:supervisor_id, :student_id, :semester_id)'
            );
            foreach ($selectedIds as $studentId) {
                $insert->execute([
                    'supervisor_id' => $supervisorId,
                    'student_id' => $studentId,
                    'semester_id' => $semesterId,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function hasCurrentAccess(int $supervisorId): bool
    {
        $semesterId = $this->activeSemesterId();
        if ($supervisorId <= 0 || $semesterId <= 0) return false;
        $stmt = $this->pdo->prepare("SELECT 1 FROM users u
            JOIN roles r ON r.id = u.role_id AND r.role_code = 'supervisor'
            JOIN supervisor_semester_access a ON a.supervisor_id = u.id AND a.semester_id = :semester_id AND a.access_status = 'active'
            WHERE u.id = :id AND u.account_status = 'active' LIMIT 1");
        $stmt->execute(['id' => $supervisorId, 'semester_id' => $semesterId]);
        return $stmt->fetchColumn() !== false;
    }

    public function createSupervisor(string $name, string $email, string $school): array
    {
        $name = trim($name); $email = strtolower(trim($email)); $school = trim($school);
        $semesterId = $this->activeSemesterId();
        if ($name === '' || $school === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Supervisor name, valid email, and partnered school are required.');
        if ($semesterId <= 0) throw new RuntimeException('Activate an academic semester before adding a Supervisor.');
        $schoolCheck = $this->pdo->prepare("SELECT 1 FROM geofence_locations g JOIN partner_schools ps ON ps.geofence_id = g.id WHERE g.name = :school AND g.is_active = 1 AND ps.school_status = 'active' LIMIT 1");
        $schoolCheck->execute(['school' => $school]);
        if ($schoolCheck->fetchColumn() === false) throw new InvalidArgumentException('Please choose an active partnered school or organization.');
        $role = $this->pdo->prepare("SELECT id FROM roles WHERE role_code='supervisor' LIMIT 1"); $role->execute(); $roleId=(int)($role->fetchColumn()?:0);
        if ($roleId<=0) throw new RuntimeException('Supervisor role is unavailable.');
        $parts = preg_split('/\s+/', $name) ?: [$name]; $first=array_shift($parts) ?: $name; $last=$parts ? array_pop($parts) : '';
        $temporary = 'Temp@' . random_int(10000, 99999);
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare("SELECT u.id, r.role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = :email LIMIT 1");
            $existing->execute(['email' => $email]); $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row && (string) $row['role_code'] !== 'supervisor') throw new InvalidArgumentException('That email address is already in use by another account.');
            if ($row) {
                $id = (int) $row['id'];
                $this->pdo->prepare("UPDATE users SET password_hash=:hash, account_status='active', must_change_password=1 WHERE id=:id")->execute(['hash'=>password_hash($temporary,PASSWORD_DEFAULT),'id'=>$id]);
                $this->pdo->prepare("INSERT INTO user_profiles (user_id,first_name,middle_name,last_name,phone,photo_path) VALUES (?,?,?,?,NULL,NULL)
                    ON DUPLICATE KEY UPDATE first_name=VALUES(first_name), middle_name=VALUES(middle_name), last_name=VALUES(last_name)")->execute([$id,$first,'',$last]);
                $this->pdo->prepare("INSERT INTO supervisor_profiles (user_id,partnered_school,position_title) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE partnered_school=VALUES(partnered_school)")->execute([$id,$school]);
                $this->pdo->prepare("INSERT INTO supervisor_semester_access (supervisor_id,semester_id,access_status) VALUES (?,?, 'active') ON DUPLICATE KEY UPDATE access_status='active', updated_at=NOW()")->execute([$id,$semesterId]);
                $this->pdo->commit();
                return array_merge(
                    ['id'=>$id,'email'=>$email,'temporary_password'=>$temporary,'name'=>$name,'reactivated'=>true],
                    $this->credentialMailer->sendSupervisorCredentials($email, $name, $school, $temporary)
                );
            }
            $stmt=$this->pdo->prepare("INSERT INTO users (role_id,school_id,email,password_hash,account_status,must_change_password) VALUES (:role_id,:school_id,:email,:hash,'active',1)");
            $stmt->execute(['role_id'=>$roleId,'school_id'=>'SUP-' . strtoupper(bin2hex(random_bytes(3))),'email'=>$email,'hash'=>password_hash($temporary,PASSWORD_DEFAULT)]);
            $id=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO user_profiles (user_id,first_name,middle_name,last_name,phone,photo_path) VALUES (?,?,?,?,NULL,NULL)')->execute([$id,$first,'',$last]);
            $this->pdo->prepare('INSERT INTO supervisor_profiles (user_id,partnered_school,position_title) VALUES (?, ?, NULL)')->execute([$id,$school]);
            $this->pdo->prepare("INSERT INTO supervisor_semester_access (supervisor_id,semester_id,access_status) VALUES (?,?, 'active')")->execute([$id,$semesterId]);
            $this->pdo->commit();
            return array_merge(
                ['id'=>$id,'email'=>$email,'temporary_password'=>$temporary,'name'=>$name,'reactivated'=>false],
                $this->credentialMailer->sendSupervisorCredentials($email, $name, $school, $temporary)
            );
        } catch (Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    public function listSupervisors(): array
    {
        $stmt = $this->pdo->prepare("SELECT u.id,u.email,u.account_status,CONCAT_WS(' ',up.first_name,up.last_name) AS name,sp.partnered_school,sp.position_title,
            (SELECT COUNT(*) FROM supervisor_student_selections selected
             JOIN semesters active_semester ON active_semester.id = selected.semester_id
             WHERE selected.supervisor_id = u.id AND active_semester.semester_status = 'active') AS student_count
            FROM users u JOIN roles r ON r.id=u.role_id AND r.role_code='supervisor'
            LEFT JOIN user_profiles up ON up.user_id=u.id JOIN supervisor_profiles sp ON sp.user_id=u.id
            ORDER BY name,email");
        $stmt->execute(); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activePartneredSchools(): array
    {
        return $this->pdo->query("SELECT DISTINCT g.name FROM geofence_locations g JOIN partner_schools ps ON ps.geofence_id = g.id WHERE g.is_active = 1 AND ps.school_status = 'active' ORDER BY g.name")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function setSupervisorStatus(int $id, bool $active): void
    {
        $this->pdo->prepare("UPDATE users u JOIN roles r ON r.id=u.role_id SET u.account_status=:status WHERE u.id=:id AND r.role_code='supervisor'")->execute(['status'=>$active?'active':'inactive','id'=>$id]);
        $semesterId = $this->activeSemesterId();
        if ($semesterId > 0) $this->pdo->prepare("UPDATE supervisor_semester_access SET access_status=:status WHERE supervisor_id=:id AND semester_id=:semester_id")->execute(['status'=>$active?'active':'inactive','id'=>$id,'semester_id'=>$semesterId]);
    }

    public function resetPassword(int $id): array
    {
        $s=$this->pdo->prepare("SELECT u.email FROM users u JOIN roles r ON r.id=u.role_id AND r.role_code='supervisor' WHERE u.id=:id LIMIT 1");$s->execute(['id'=>$id]);$email=(string)($s->fetchColumn()?:'');if($email==='')throw new InvalidArgumentException('Supervisor account not found.');$temporary='Temp@'.random_int(10000,99999);$this->pdo->prepare("UPDATE users SET password_hash=:hash,must_change_password=1,account_status='active' WHERE id=:id")->execute(['hash'=>password_hash($temporary,PASSWORD_DEFAULT),'id'=>$id]);$semesterId=$this->activeSemesterId();if($semesterId>0)$this->pdo->prepare("INSERT INTO supervisor_semester_access (supervisor_id,semester_id,access_status) VALUES (?,?, 'active') ON DUPLICATE KEY UPDATE access_status='active',updated_at=NOW()")->execute([$id,$semesterId]);return ['email'=>$email,'temporary_password'=>$temporary];
    }

    public function getStudentAssignment(int $studentId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT selection.supervisor_id,
                    CONCAT_WS(' ', profile.first_name, profile.last_name) AS name,
                    supervisor.email,
                    supervisor_profile.partnered_school,
                    supervisor_profile.position_title
             FROM supervisor_student_selections selection
             JOIN users supervisor ON supervisor.id = selection.supervisor_id AND supervisor.account_status = 'active'
             LEFT JOIN user_profiles profile ON profile.user_id = supervisor.id
             LEFT JOIN supervisor_profiles supervisor_profile ON supervisor_profile.user_id = supervisor.id
             JOIN supervisor_semester_access access
               ON access.supervisor_id = selection.supervisor_id
              AND access.semester_id = selection.semester_id
              AND access.access_status = 'active'
             WHERE selection.student_id = :student_id
               AND selection.semester_id = :semester_id
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId, 'semester_id' => $this->activeSemesterId()]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function assignedStudents(int $supervisorId): array
    {
        return array_values(array_filter(
            $this->studentsAvailableForSelection($supervisorId),
            static fn(array $student): bool => (int) ($student['selected'] ?? 0) === 1
        ));
    }

    public function archiveSemester(int $semesterId): array
    {
        if ($semesterId <= 0) return ['assignments'=>0,'supervisors'=>0];
        $copySelections = $this->pdo->prepare(
            "INSERT INTO supervisor_assignment_history
                (student_id,supervisor_id,supervisor_name,supervisor_email,partnered_school,position_title,semester_id,assignment_status,assigned_at,archived_at)
             SELECT selection.student_id, selection.supervisor_id, CONCAT_WS(' ', profile.first_name, profile.last_name),
                    supervisor.email, supervisor_profile.partnered_school, supervisor_profile.position_title,
                    selection.semester_id, 'archived', selection.selected_at, NOW()
             FROM supervisor_student_selections selection
             JOIN users supervisor ON supervisor.id = selection.supervisor_id
             LEFT JOIN user_profiles profile ON profile.user_id = selection.supervisor_id
             LEFT JOIN supervisor_profiles supervisor_profile ON supervisor_profile.user_id = selection.supervisor_id
             WHERE selection.semester_id = :semester_id
             ON DUPLICATE KEY UPDATE
                supervisor_id = VALUES(supervisor_id),
                supervisor_name = VALUES(supervisor_name),
                supervisor_email = VALUES(supervisor_email),
                partnered_school = VALUES(partnered_school),
                position_title = VALUES(position_title),
                assigned_at = VALUES(assigned_at),
                archived_at = VALUES(archived_at)"
        );
        $copySelections->execute(['semester_id' => $semesterId]);
        $assignments = $copySelections->rowCount();
        $this->pdo->prepare('DELETE FROM supervisor_student_selections WHERE semester_id = :semester_id')
            ->execute(['semester_id' => $semesterId]);
        $access=$this->pdo->prepare("UPDATE supervisor_semester_access SET access_status='archived',updated_at=NOW() WHERE semester_id=:semester_id AND access_status='active'");$access->execute(['semester_id'=>$semesterId]);
        $deactivate=$this->pdo->prepare("UPDATE users u JOIN roles r ON r.id=u.role_id AND r.role_code='supervisor' JOIN supervisor_semester_access a ON a.supervisor_id=u.id AND a.semester_id=:semester_id SET u.account_status='inactive' WHERE a.access_status='archived'");$deactivate->execute(['semester_id'=>$semesterId]);$supervisors=$deactivate->rowCount();
        $this->pdo->exec('DELETE FROM supervisor_assignments'); return ['assignments'=>$assignments,'supervisors'=>$supervisors];
    }

    public function prepareNewSemester(int $newSemesterId): void
    {
        if ($newSemesterId <= 0) return;
        $previousStmt = $this->pdo->query("SELECT id FROM semesters WHERE id <> " . (int) $newSemesterId . " AND semester_status IN ('closed','archived') ORDER BY start_date DESC, id DESC LIMIT 1");
        $previousSemesterId = (int) ($previousStmt->fetchColumn() ?: 0);
        if ($previousSemesterId > 0) $this->archiveSemester($previousSemesterId);
        $this->pdo->prepare("UPDATE supervisor_semester_access SET access_status='archived', updated_at=NOW() WHERE semester_id <> :semester_id AND access_status='active'")->execute(['semester_id' => $newSemesterId]);
        $this->pdo->prepare("UPDATE users u JOIN roles r ON r.id=u.role_id AND r.role_code='supervisor' SET u.account_status='inactive' WHERE NOT EXISTS (SELECT 1 FROM supervisor_semester_access a WHERE a.supervisor_id=u.id AND a.semester_id=:semester_id AND a.access_status='active')")->execute(['semester_id' => $newSemesterId]);
    }

    /** Return the active template that matches the student's current school deployment type. */
    public function activeFormForStudent(int $studentId): ?array
    {
        $semesterId = $this->activeSemesterId();
        if ($studentId <= 0 || $semesterId <= 0) return null;
        $schoolTypeStmt = $this->pdo->prepare("SELECT g.school_type FROM student_school_deployments d JOIN geofence_locations g ON g.id=d.geofence_id WHERE d.student_user_id=:student_id AND d.semester_id=:semester_id AND d.deployment_status='active' ORDER BY d.deployment_order, d.id LIMIT 1");
        $schoolTypeStmt->execute(['student_id'=>$studentId,'semester_id'=>$semesterId]);
        $schoolType = (string) ($schoolTypeStmt->fetchColumn() ?: '');
        if (!in_array($schoolType, ['public_school', 'private_school'], true)) return null;
        $formStmt = $this->pdo->prepare('SELECT id FROM evaluation_forms WHERE is_active = 1 AND target_school_type=:school_type ORDER BY updated_at DESC, id DESC LIMIT 1');
        $formStmt->execute(['school_type'=>$schoolType]);
        $formId = $formStmt->fetchColumn();
        if (!$formId) return null;
        $form = (new EvaluationFormService($this->pdo))->get((int) $formId);
        if (!$form) return null;
        // This is the semester stored on a new submission, not a template lookup filter.
        $form['submission_semester_id'] = $semesterId;
        $form['semester_id'] = $semesterId;
        $form['target_school_type'] = $schoolType;
        return $form;
    }

    public function getSubmission(int $studentId, int $supervisorId, int $formId): ?array
    {
        $semester = $this->activeSemesterId();
        $deploymentId = $this->activeEvaluationDeploymentId($studentId);
        $scopeSql = $deploymentId > 0 ? 'deployment_id = :deployment_id' : 'deployment_id IS NULL';
        $params = ['student_id' => $studentId, 'form_id' => $formId, 'semester_id' => $semester, 'supervisor_id' => $supervisorId];
        if ($deploymentId > 0) $params['deployment_id'] = $deploymentId;
        $s = $this->pdo->prepare("SELECT * FROM evaluation_submissions WHERE student_id=:student_id AND evaluation_form_id=:form_id AND semester_id=:semester_id AND supervisor_id=:supervisor_id AND {$scopeSql} LIMIT 1");
        $s->execute($params);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row && $deploymentId > 0 && $this->isFirstDeployment($studentId, $deploymentId, $semester)) {
            $legacy = $this->pdo->prepare('SELECT * FROM evaluation_submissions WHERE student_id=:student_id AND evaluation_form_id=:form_id AND semester_id=:semester_id AND supervisor_id=:supervisor_id AND deployment_id IS NULL LIMIT 1');
            $legacy->execute(['student_id' => $studentId, 'form_id' => $formId, 'semester_id' => $semester, 'supervisor_id' => $supervisorId]);
            $row = $legacy->fetch(PDO::FETCH_ASSOC) ?: false;
        }
        if (!$row) return null;
        $a=$this->pdo->prepare('SELECT question_id,rating_value FROM evaluation_answers WHERE submission_id=:id');$a->execute(['id'=>$row['id']]);$row['answers']=$a->fetchAll(PDO::FETCH_KEY_PAIR);$c=$this->pdo->prepare('SELECT comment_field_id,comment_text FROM evaluation_submission_comments WHERE submission_id=:id');$c->execute(['id'=>$row['id']]);$row['comments']=$c->fetchAll(PDO::FETCH_KEY_PAIR);$cert=$this->pdo->prepare('SELECT supervisor_name,supervisor_position,signed_date FROM evaluation_submission_certification WHERE submission_id=:id');$cert->execute(['id'=>$row['id']]);$row['certification']=$cert->fetch(PDO::FETCH_ASSOC)?:[];$scale=$this->pdo->prepare('SELECT rating_value,rating_label,display_order FROM evaluation_submission_ratings WHERE submission_id=:id ORDER BY display_order');$scale->execute(['id'=>$row['id']]);$row['rating_scale_snapshot']=$scale->fetchAll(PDO::FETCH_ASSOC);$sig=$this->pdo->prepare('SELECT signature_image,mime_type FROM evaluation_signatures WHERE submission_id=:id');$sig->execute(['id'=>$row['id']]);$signature=$sig->fetch(PDO::FETCH_ASSOC);$row['signature_data']=$signature&&$signature['signature_image']!==null?'data:'.($signature['mime_type']?:'image/png').';base64,'.base64_encode((string)$signature['signature_image']):'';return $row;
    }

    public function submit(int $studentId,int $supervisorId,array $form,array $answers,array $comments,string $overall,string $signature,array $certification=[]): int
    {
        if (!$this->isAssigned($studentId, $supervisorId)) throw new RuntimeException('This student is not assigned to you for the current semester.');
        if($this->getSubmission($studentId,$supervisorId,(int)$form['id']))throw new RuntimeException('Evaluation already submitted for this student. It is now read-only.');
        $deploymentId = $this->activeEvaluationDeploymentId($studentId);
        $this->pdo->beginTransaction(); try{$s=$this->pdo->prepare('INSERT INTO evaluation_submissions (evaluation_form_id,student_id,supervisor_id,semester_id,deployment_id,overall_score,comments) VALUES (?,?,?,?,?,?,?)');$s->execute([(int)$form['id'],$studentId,$supervisorId,(int)$form['semester_id'],$deploymentId>0?$deploymentId:null,$overall!==''?$overall:null,null]);$id=(int)$this->pdo->lastInsertId();$snapshot=$this->pdo->prepare('INSERT INTO evaluation_submission_ratings (submission_id,rating_value,rating_label,display_order) VALUES (?,?,?,?)');foreach($form['ratings'] as $ratingIndex=>$rating)$snapshot->execute([$id,(string)$rating['rating_value'],(string)($rating['rating_label']??''),$ratingIndex]);$q=$this->pdo->prepare('INSERT INTO evaluation_answers (submission_id,question_id,rating_value) VALUES (?,?,?)');foreach($answers as $qid=>$rating)$q->execute([$id,(int)$qid,trim((string)$rating)]);$c=$this->pdo->prepare('INSERT INTO evaluation_submission_comments (submission_id,comment_field_id,comment_text) VALUES (?,?,?)');foreach($comments as $cid=>$text)$c->execute([$id,(int)$cid,trim((string)$text)]);$certStmt=$this->pdo->prepare('INSERT INTO evaluation_submission_certification (submission_id,supervisor_name,supervisor_position,signed_date) VALUES (?,?,?,?)');$certStmt->execute([$id,trim((string)($certification['supervisor_name']??''))?:null,trim((string)($certification['position']??''))?:null,trim((string)($certification['date']??''))?:null]);if($signature!==''){$mime='image/png';$raw=$signature;if(preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.*)$/s',$signature,$matches)){ $mime=$matches[1];$raw=$matches[2]; }$sig=base64_decode($raw,true);if($sig!==false)$this->pdo->prepare('INSERT INTO evaluation_signatures (submission_id,signature_image,mime_type) VALUES (?,?,?)')->execute([$id,$sig,$mime]);}$this->pdo->commit();return $id;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function activeEvaluationDeploymentId(int $studentId): int
    {
        if (!$this->tableExists('student_school_deployments')) return 0;
        $stmt = $this->pdo->prepare("SELECT id FROM student_school_deployments WHERE student_user_id=:student_id AND semester_id=:semester_id AND deployment_status='active' ORDER BY deployment_order, id LIMIT 1");
        $stmt->execute(['student_id' => $studentId, 'semester_id' => $this->activeSemesterId()]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function isFirstDeployment(int $studentId, int $deploymentId, int $semesterId): bool
    {
        $stmt = $this->pdo->prepare('SELECT deployment_order FROM student_school_deployments WHERE id=:id AND student_user_id=:student_id AND semester_id=:semester_id LIMIT 1');
        $stmt->execute(['id' => $deploymentId, 'student_id' => $studentId, 'semester_id' => $semesterId]);
        return (int) ($stmt->fetchColumn() ?: 0) === 1;
    }

    public function isAssigned(int $studentId, int $supervisorId): bool
    {
        foreach ($this->studentsAvailableForSelection($supervisorId) as $student) {
            if ((int) ($student['student_id'] ?? 0) === $studentId && (int) ($student['selected'] ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }
}
