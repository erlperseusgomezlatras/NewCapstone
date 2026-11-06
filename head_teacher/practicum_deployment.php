<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditLogService.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$activeSemesterStmt = $pdo->query(
    "SELECT id, semester_no, semester_name
     FROM semesters
     WHERE semester_status = 'active'
     ORDER BY start_date DESC, id DESC
     LIMIT 1"
);
$activeSemester = $activeSemesterStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$activeSemesterId = (int) ($activeSemester['id'] ?? 0);
$activeSemesterNo = (int) ($activeSemester['semester_no'] ?? 0);
$activeSemesterName = strtolower(trim((string) ($activeSemester['semester_name'] ?? '')));
$isSummerSemester = $activeSemesterNo === 3 || str_contains($activeSemesterName, 'summer');
$requiredSchoolType = $isSummerSemester ? null : match ($activeSemesterNo) {
    1 => 'public_school',
    2 => 'private_school',
    default => null,
};
$isDeploymentSemester = $activeSemesterId > 0
    && ($isSummerSemester || in_array($activeSemesterNo, [1, 2], true));

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS partner_schools (
        geofence_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        address VARCHAR(255) NOT NULL DEFAULT '',
        daily_cap_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00,
        time_in_start TIME NOT NULL DEFAULT '06:00:00',
        school_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_partner_schools_geofence
            FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS coordinator_deployments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coordinator_user_id BIGINT UNSIGNED NOT NULL,
        geofence_id BIGINT UNSIGNED NOT NULL,
        semester_id INT UNSIGNED NULL,
        deployment_order TINYINT UNSIGNED NULL,
        deployment_status ENUM('active', 'ended') NOT NULL DEFAULT 'active',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ended_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_coordinator_deployment_order (coordinator_user_id, semester_id, deployment_order),
        KEY idx_coordinator_deployments_geofence (geofence_id),
        KEY idx_coordinator_deployments_status (deployment_status),
        CONSTRAINT fk_coordinator_deployments_user
            FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_coordinator_deployments_geofence
            FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS coordinator_schools (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coordinator_user_id BIGINT UNSIGNED NOT NULL,
        geofence_id BIGINT UNSIGNED NOT NULL,
        semester_id INT UNSIGNED NULL,
        deployment_order TINYINT UNSIGNED NULL,
        assigned_by BIGINT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_coordinator_school_order (coordinator_user_id, semester_id, deployment_order),
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

// Upgrade installations created before ordered deployments were introduced.
try { $pdo->exec("ALTER TABLE coordinator_deployments ADD COLUMN semester_id INT UNSIGNED NULL AFTER geofence_id"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE coordinator_deployments ADD COLUMN deployment_order TINYINT UNSIGNED NULL AFTER semester_id"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE coordinator_schools ADD COLUMN semester_id INT UNSIGNED NULL AFTER geofence_id"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE coordinator_schools ADD COLUMN deployment_order TINYINT UNSIGNED NULL AFTER semester_id"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE coordinator_deployments DROP INDEX uq_coordinator_deployment"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE coordinator_schools DROP INDEX uq_coordinator_school"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE coordinator_deployments ADD UNIQUE KEY uq_coordinator_deployment_order (coordinator_user_id, semester_id, deployment_order)"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE coordinator_schools ADD UNIQUE KEY uq_coordinator_school_order (coordinator_user_id, semester_id, deployment_order)"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE partner_schools ADD COLUMN time_in_start TIME NOT NULL DEFAULT '06:00:00' AFTER daily_cap_hours"); } catch (Throwable) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS student_school_deployments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    coordinator_user_id BIGINT UNSIGNED NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    deployment_order TINYINT UNSIGNED NOT NULL,
    required_seconds INT UNSIGNED NOT NULL DEFAULT 648000,
    rendered_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    deployment_status ENUM('locked','active','completed') NOT NULL DEFAULT 'locked',
    started_at DATETIME NULL, completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_school_order (student_user_id, semester_id, deployment_order),
    UNIQUE KEY uq_student_school_location (student_user_id, semester_id, geofence_id),
    KEY idx_student_school_active (student_user_id, semester_id, deployment_status)
) ENGINE=InnoDB");

function deploymentTypeLabel(string $type): string
{
    return $type === 'private_school' ? 'Private' : 'Public';
}

function coordinatorDeploymentBadge(?string $status): string
{
    return match ($status) {
        'active' => 'bg-emerald-100 text-emerald-700',
        'ended' => 'bg-amber-100 text-amber-700',
        default => 'bg-slate-100 text-slate-700',
    };
}

function coordinatorDeploymentLabel(?string $status): string
{
    return match ($status) {
        'active' => 'Active',
        'ended' => 'Ended',
        default => 'Not Deployed',
    };
}

function schoolTimeInStart(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^(?:0\d|1[0-5]):00(?::00)?$/', $value)) {
        throw new InvalidArgumentException('Select a time-in start between 12:00 AM and 3:00 PM.');
    }

    return substr($value, 0, 5) . ':00';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['deployment_action'] ?? ''));
    $isAjaxToggle = $action === 'toggle_school'
        && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    try {
        if ($action === 'add_school') {
            $name = trim((string) ($_POST['school_name'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $schoolTypeRaw = strtolower(trim((string) ($_POST['school_type'] ?? 'public')));
            $schoolType = $schoolTypeRaw === 'private' ? 'private_school' : 'public_school';
            $latitude = (float) ($_POST['latitude'] ?? 0);
            $longitude = (float) ($_POST['longitude'] ?? 0);
            $radius = (float) ($_POST['radius_meters'] ?? 100);
            $dailyCap = (float) ($_POST['daily_cap_hours'] ?? 8);
            $timeInStart = schoolTimeInStart((string) ($_POST['time_in_start'] ?? '06:00'));

            if ($name === '') {
                throw new RuntimeException('School name is required.');
            }
            if ($address === '') {
                throw new RuntimeException('Address is required.');
            }
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                throw new RuntimeException('Latitude/longitude is invalid.');
            }
            if ($radius < 20 || $radius > 500) {
                throw new RuntimeException('Geofence radius must be between 20m and 500m.');
            }
            if ($dailyCap <= 0 || $dailyCap > 24) {
                throw new RuntimeException('Daily cap must be between 0 and 24 hours.');
            }

            $pdo->beginTransaction();
            $existingSchoolStmt = $pdo->prepare(
                "SELECT geofence_id
                 FROM partner_schools
                 WHERE address = :address
                 LIMIT 1"
            );
            $existingSchoolStmt->execute(['address' => $address]);
            $existingGeofenceId = (int) ($existingSchoolStmt->fetchColumn() ?: 0);

            if ($existingGeofenceId > 0) {
                $updateGeo = $pdo->prepare(
                    "UPDATE geofence_locations
                     SET name = :name,
                         latitude = :latitude,
                         longitude = :longitude,
                         radius_meters = :radius_meters,
                         school_type = :school_type,
                         is_active = 1,
                         updated_at = NOW()
                     WHERE id = :geofence_id"
                );
                $updateGeo->execute([
                    'name' => $name,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'radius_meters' => $radius,
                    'school_type' => $schoolType,
                    'geofence_id' => $existingGeofenceId,
                ]);

                $updatePartner = $pdo->prepare(
                    "UPDATE partner_schools
                     SET address = :address,
                         daily_cap_hours = :daily_cap_hours,
                          time_in_start = :time_in_start,
                         school_status = 'active',
                         updated_at = NOW()
                     WHERE geofence_id = :geofence_id"
                );
                $updatePartner->execute([
                    'address' => $address,
                    'daily_cap_hours' => $dailyCap,
                    'time_in_start' => $timeInStart,
                    'geofence_id' => $existingGeofenceId,
                ]);
                $message = 'School reactivated successfully.';
            } else {
                $insertGeo = $pdo->prepare(
                    "INSERT INTO geofence_locations
                        (name, latitude, longitude, radius_meters, school_type, is_active)
                     VALUES
                        (:name, :latitude, :longitude, :radius_meters, :school_type, 1)"
                );
                $insertGeo->execute([
                    'name' => $name,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'radius_meters' => $radius,
                    'school_type' => $schoolType,
                ]);

                $existingGeofenceId = (int) $pdo->lastInsertId();
                $insertPartner = $pdo->prepare(
                    "INSERT INTO partner_schools
                        (geofence_id, address, daily_cap_hours, time_in_start, school_status)
                     VALUES
                        (:geofence_id, :address, :daily_cap_hours, :time_in_start, 'active')"
                );
                $insertPartner->execute([
                    'geofence_id' => $existingGeofenceId,
                    'address' => $address,
                    'daily_cap_hours' => $dailyCap,
                    'time_in_start' => $timeInStart,
                ]);
                $message = 'School added successfully.';
            }

            $pdo->commit();
            htSetFlash('success', $message);
        } elseif ($action === 'toggle_school') {
            $geofenceId = (int) ($_POST['geofence_id'] ?? 0);
            if ($geofenceId <= 0) {
                throw new RuntimeException('Invalid school selection.');
            }
            if (!$isDeploymentSemester) {
                throw new RuntimeException('Activate a supported semester before changing partnered-school status.');
            }

            $pdo->beginTransaction();

            $currentStmt = $pdo->prepare(
                "SELECT ps.school_status, g.school_type
                 FROM partner_schools ps
                 JOIN geofence_locations g ON g.id = ps.geofence_id
                 WHERE ps.geofence_id = :geofence_id"
            );
            $currentStmt->execute(['geofence_id' => $geofenceId]);
            $currentSchool = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$currentSchool) {
                throw new RuntimeException('School not found.');
            }
            $currentStatus = (string) $currentSchool['school_status'];
            if ($requiredSchoolType !== null && (string) $currentSchool['school_type'] !== $requiredSchoolType) {
                throw new RuntimeException('Only schools matching the active semester type can be activated or deactivated.');
            }

            $nextStatus = $currentStatus === 'active' ? 'inactive' : 'active';
            $nextIsActive = $nextStatus === 'active' ? 1 : 0;

            // Only a real, current deployment protects a school. Historical
            // geofence-assignment rows are not coordinator deployments and
            // must not make an otherwise available school appear assigned.
            if ($nextIsActive === 0) {
                $usageStmt = $pdo->prepare(
                    "SELECT
                        (SELECT COUNT(*) FROM coordinator_deployments
                         WHERE geofence_id = :coordinator_geofence_id
                           AND deployment_status = 'active') AS active_coordinator_deployments,
                        (SELECT COUNT(*) FROM student_school_deployments
                         INNER JOIN semesters student_semester
                           ON student_semester.id = student_school_deployments.semester_id
                         WHERE student_school_deployments.geofence_id = :student_deployment_geofence_id
                           AND student_school_deployments.deployment_status = 'active'
                           AND student_semester.practicum_status = 'ongoing') AS active_student_deployments"
                );
                $usageStmt->execute([
                    'coordinator_geofence_id' => $geofenceId,
                    'student_deployment_geofence_id' => $geofenceId,
                ]);
                $usage = $usageStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if ((int) ($usage['active_coordinator_deployments'] ?? 0) > 0
                    || (int) ($usage['active_student_deployments'] ?? 0) > 0) {
                    throw new RuntimeException(
                        'This school cannot be deactivated while it has a current coordinator or active student deployment. End or reassign the deployment first.'
                    );
                }
            }

            $updatePartner = $pdo->prepare(
                "UPDATE partner_schools
                 SET school_status = :school_status
                 WHERE geofence_id = :geofence_id"
            );
            $updatePartner->execute([
                'school_status' => $nextStatus,
                'geofence_id' => $geofenceId,
            ]);

            $updateGeo = $pdo->prepare(
                "UPDATE geofence_locations
                 SET is_active = :is_active
                 WHERE id = :geofence_id"
            );
            $updateGeo->execute([
                'is_active' => $nextIsActive,
                'geofence_id' => $geofenceId,
            ]);

            $pdo->commit();
            (new AuditLogService($pdo))->log((int) ($user['id'] ?? 0), 'school_status_changed', 'partner_school', $geofenceId, [
                'school_status' => $nextStatus,
            ]);
            if ($isAjaxToggle) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => 'School ' . ($nextStatus === 'active' ? 'activated' : 'deactivated') . '.',
                    'school_status' => $nextStatus,
                    'is_active' => $nextIsActive,
                ]);
                exit;
            }
            htSetFlash('success', 'School status updated.');
        } elseif ($action === 'deploy_coordinator') {
            $coordinatorId = (int) ($_POST['coordinator_id'] ?? 0);
            $geofenceId = (int) ($_POST['geofence_id'] ?? 0);

            if ($coordinatorId <= 0 || $geofenceId <= 0) {
                throw new RuntimeException('Please select a coordinator and one partnered school.');
            }
            if (!$isDeploymentSemester) {
                throw new RuntimeException('Coordinator deployment is available only during the 1st, 2nd, or Summer semester.');
            }

            $coordinatorStmt = $pdo->prepare(
                "SELECT 1
                 FROM users u
                 JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :id
                   AND r.role_code = 'coordinator'
                 LIMIT 1"
            );
            $coordinatorStmt->execute(['id' => $coordinatorId]);
            if ($coordinatorStmt->fetchColumn() === false) {
                throw new RuntimeException('Selected user is not a coordinator.');
            }

            $schoolStmt = $pdo->prepare(
                "SELECT school_type
                 FROM geofence_locations
                 WHERE id = :geofence_id
                   AND is_active = 1"
            );
            $schoolStmt->execute(['geofence_id' => $geofenceId]);
            $schoolType = $schoolStmt->fetchColumn();
            if ($schoolType === false) {
                throw new RuntimeException('The selected partnered school is inactive or missing.');
            }
            if ($requiredSchoolType !== null && (string) $schoolType !== $requiredSchoolType) {
                $semesterLabel = $activeSemesterNo === 1 ? '1st Semester' : '2nd Semester';
                $schoolLabel = $requiredSchoolType === 'public_school' ? 'Public' : 'Private';
                throw new RuntimeException($semesterLabel . ' coordinators must be assigned only to ' . $schoolLabel . ' School placements.');
            }

            $assignedSchoolStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM coordinator_deployments
                 WHERE deployment_status = 'active'
                   AND (semester_id = :semester_id OR semester_id IS NULL)
                   AND coordinator_user_id <> :coordinator_user_id
                   AND geofence_id = :geofence_id"
            );
            $assignedSchoolStmt->execute([
                'semester_id' => $activeSemesterId,
                'coordinator_user_id' => $coordinatorId,
                'geofence_id' => $geofenceId,
            ]);
            if ((int) $assignedSchoolStmt->fetchColumn() > 0) {
                throw new RuntimeException('The selected school is already assigned to another active coordinator.');
            }

            $pdo->beginTransaction();

            // Keep every current student assigned by this coordinator in sync with a
            // coordinator reassignment.  Without this, the coordinator moves to the
            // new school while their students remain assigned to the former geofence.
            $previousSchoolStmt = $pdo->prepare(
                "SELECT geofence_id
                 FROM coordinator_deployments
                 WHERE coordinator_user_id = :coordinator_user_id
                   AND (semester_id = :semester_id OR semester_id IS NULL)
                   AND deployment_order = 1
                   AND deployment_status = 'active'
                 LIMIT 1"
            );
            $previousSchoolStmt->execute([
                'coordinator_user_id' => $coordinatorId,
                'semester_id' => $activeSemesterId,
            ]);
            $previousGeofenceId = (int) ($previousSchoolStmt->fetchColumn() ?: 0);

            if ($previousGeofenceId > 0) {
                // Preserve each student's accumulated hours and deployment ID while
                // changing only their current school location.  Do not restrict this
                // to the previous geofence: that also repairs legacy rows which were
                // left at an older school by the former reassignment behavior.
                $moveStudentDeployments = $pdo->prepare(
                    "UPDATE student_school_deployments
                     SET geofence_id = :new_geofence_id
                     WHERE coordinator_user_id = :coordinator_user_id
                       AND semester_id = :semester_id
                       AND deployment_order = 1
                       AND geofence_id <> :current_geofence_id
                       AND deployment_status IN ('active', 'locked')"
                );
                $moveStudentDeployments->execute([
                    'new_geofence_id' => $geofenceId,
                    'current_geofence_id' => $geofenceId,
                    'coordinator_user_id' => $coordinatorId,
                    'semester_id' => $activeSemesterId,
                ]);

                $moveStudentAssignments = $pdo->prepare(
                    "UPDATE student_geofence_assignments
                     SET geofence_id = :new_geofence_id,
                         assigned_at = NOW()
                     WHERE coordinator_user_id = :coordinator_user_id
                       AND semester_id = :semester_id
                       AND geofence_id <> :current_geofence_id
                       AND assignment_status = 'active'"
                );
                $moveStudentAssignments->execute([
                    'new_geofence_id' => $geofenceId,
                    'current_geofence_id' => $geofenceId,
                    'coordinator_user_id' => $coordinatorId,
                    'semester_id' => $activeSemesterId,
                ]);
            }

            $pdo->prepare(
                "UPDATE coordinator_deployments
                 SET deployment_status = 'ended', ended_at = NOW()
                 WHERE coordinator_user_id = :coordinator_user_id
                   AND deployment_status = 'active'"
            )->execute(['coordinator_user_id' => $coordinatorId]);
            $pdo->prepare("DELETE FROM coordinator_schools WHERE coordinator_user_id = :coordinator_user_id")
                ->execute(['coordinator_user_id' => $coordinatorId]);

            $deployStmt = $pdo->prepare(
                "INSERT INTO coordinator_deployments
                    (coordinator_user_id, geofence_id, semester_id, deployment_order, deployment_status, started_at)
                 VALUES
                    (:coordinator_user_id, :geofence_id, :semester_id, :deployment_order, 'active', NOW())"
                 . " ON DUPLICATE KEY UPDATE
                    geofence_id = VALUES(geofence_id),
                    deployment_status = 'active',
                    started_at = NOW(),
                    ended_at = NULL"
            );

            $mirrorStmt = $pdo->prepare(
                "INSERT INTO coordinator_schools
                    (coordinator_user_id, geofence_id, semester_id, deployment_order, assigned_by, assigned_at)
                 VALUES
                    (:coordinator_user_id, :geofence_id, :semester_id, :deployment_order, :assigned_by, NOW())
                 ON DUPLICATE KEY UPDATE
                    geofence_id = VALUES(geofence_id),
                    assigned_by = VALUES(assigned_by),
                    assigned_at = NOW()"
            );
            $params = [
                'coordinator_user_id' => $coordinatorId,
                'geofence_id' => $geofenceId,
                'semester_id' => $activeSemesterId,
                'deployment_order' => 1,
            ];
            $deployStmt->execute($params);
            $mirrorStmt->execute($params + ['assigned_by' => (int) ($user['id'] ?? 0)]);

            $pdo->commit();
            htSetFlash('success', 'Coordinator deployed successfully.');
        } elseif ($action === 'end_coordinator') {
            $coordinatorId = (int) ($_POST['coordinator_id'] ?? 0);
            if ($coordinatorId <= 0) {
                throw new RuntimeException('Invalid coordinator deployment.');
            }

            $pdo->beginTransaction();

            $endStmt = $pdo->prepare(
                "UPDATE coordinator_deployments
                 SET deployment_status = 'ended',
                     ended_at = NOW()
                 WHERE coordinator_user_id = :coordinator_user_id
                   AND deployment_status = 'active'"
            );
            $endStmt->execute(['coordinator_user_id' => $coordinatorId]);

            $deleteMirror = $pdo->prepare(
                "DELETE FROM coordinator_schools
                 WHERE coordinator_user_id = :coordinator_user_id"
            );
            $deleteMirror->execute(['coordinator_user_id' => $coordinatorId]);

            $pdo->commit();
            htSetFlash('success', 'Coordinator deployment ended successfully.');
        } elseif ($action === 'edit_school') {
            $geofenceId = (int) ($_POST['geofence_id'] ?? 0);
            $name = trim((string) ($_POST['school_name'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $schoolTypeRaw = strtolower(trim((string) ($_POST['school_type'] ?? 'public')));
            $schoolType = $schoolTypeRaw === 'private' ? 'private_school' : 'public_school';
            $latitude = (float) ($_POST['latitude'] ?? 0);
            $longitude = (float) ($_POST['longitude'] ?? 0);
            $radius = (float) ($_POST['radius_meters'] ?? 100);
            $dailyCap = (float) ($_POST['daily_cap_hours'] ?? 8);
            $timeInStart = schoolTimeInStart((string) ($_POST['time_in_start'] ?? '06:00'));

            if ($geofenceId <= 0) {
                throw new RuntimeException('Invalid school.');
            }
            if ($name === '' || $address === '') {
                throw new RuntimeException('School name and address are required.');
            }

            $pdo->beginTransaction();

            $updateGeo = $pdo->prepare(
                "UPDATE geofence_locations
                 SET name = :name,
                     latitude = :latitude,
                     longitude = :longitude,
                     radius_meters = :radius_meters,
                     school_type = :school_type
                 WHERE id = :id"
            );
            $updateGeo->execute([
                'name' => $name,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_meters' => $radius,
                'school_type' => $schoolType,
                'id' => $geofenceId,
            ]);

            $updatePartner = $pdo->prepare(
                "UPDATE partner_schools
                 SET address = :address,
                     daily_cap_hours = :daily_cap_hours,
                     time_in_start = :time_in_start
                 WHERE geofence_id = :geofence_id"
            );
            $updatePartner->execute([
                'address' => $address,
                'daily_cap_hours' => $dailyCap,
                'time_in_start' => $timeInStart,
                'geofence_id' => $geofenceId,
            ]);

            $pdo->commit();
            htSetFlash('success', 'School updated successfully.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($isAjaxToggle) {
            error_log('School status update failed: ' . $e->getMessage());
            header('Content-Type: application/json; charset=utf-8', true, 422);
            $message = str_starts_with($e->getMessage(), 'This school cannot be deactivated')
                ? $e->getMessage()
                : 'Unable to update school status. Please try again.';
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        htSetFlash('error', $e->getMessage());
    }

    $query = [];
    if (isset($_GET['type'])) {
        $query['type'] = (string) $_GET['type'];
    }
    if (isset($_GET['q'])) {
        $query['q'] = (string) $_GET['q'];
    }
    if (isset($_GET['school_page'])) {
        $query['school_page'] = max(1, (int) $_GET['school_page']);
    }
    header('Location: ' . appUrl('head_teacher/practicum_deployment.php') . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

$schoolTypeFilter = trim((string) ($_GET['type'] ?? 'all'));
if (!in_array($schoolTypeFilter, ['all', 'public_school', 'private_school'], true)) {
    $schoolTypeFilter = 'all';
}
if ($requiredSchoolType !== null) {
    $schoolTypeFilter = $requiredSchoolType;
}
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$schoolPage = max(1, (int) ($_GET['school_page'] ?? 1));

$coordinatorsStmt = $pdo->prepare(
    "SELECT
        u.id,
        u.school_id AS coordinator_id,
        u.email,
        u.account_status,
        up.first_name,
        up.last_name,
        MIN(cd.geofence_id) AS geofence_id,
        CASE WHEN SUM(cd.deployment_status = 'active') > 0 THEN 'active' ELSE MAX(cd.deployment_status) END AS deployment_status,
        MIN(cd.started_at) AS started_at,
        MAX(cd.ended_at) AS ended_at,
        MAX(g.name) AS school_name,
        MAX(g.school_type) AS school_type,
        MAX(ps.address) AS school_address
     FROM users u
     JOIN roles r ON r.id = u.role_id
     JOIN user_profiles up ON up.user_id = u.id
     LEFT JOIN coordinator_deployments cd
       ON cd.coordinator_user_id = u.id
      AND cd.semester_id = :semester_id
      AND cd.deployment_order = 1
     LEFT JOIN geofence_locations g
       ON g.id = cd.geofence_id
     LEFT JOIN partner_schools ps
       ON ps.geofence_id = g.id
     WHERE r.role_code = 'coordinator'
       AND u.account_status = 'active'
     GROUP BY u.id, u.school_id, u.email, u.account_status, up.first_name, up.last_name
     ORDER BY up.last_name ASC, up.first_name ASC"
);
$coordinatorsStmt->execute(['semester_id' => $activeSemesterId]);
$coordinators = $coordinatorsStmt->fetchAll();

$schoolsSql = "SELECT
        g.id,
        g.name,
        g.school_type,
        g.latitude,
        g.longitude,
        g.radius_meters,
        g.is_active,
        ps.address,
        ps.daily_cap_hours,
        ps.time_in_start,
        ps.school_status,
        COUNT(cd.coordinator_user_id) AS active_coordinators,
        (SELECT COUNT(*) FROM student_school_deployments ssd
         JOIN semesters student_semester ON student_semester.id = ssd.semester_id
         WHERE ssd.geofence_id = g.id
           AND ssd.deployment_status = 'active'
           AND student_semester.practicum_status = 'ongoing') AS active_student_deployments
    FROM geofence_locations g
    LEFT JOIN partner_schools ps ON ps.geofence_id = g.id
    LEFT JOIN coordinator_deployments cd
      ON cd.geofence_id = g.id
     AND cd.deployment_status = 'active'
     AND (cd.semester_id = :active_semester_id OR cd.semester_id IS NULL)
    WHERE 1 = 1";

$schoolsParams = ['active_semester_id' => $activeSemesterId];
if ($schoolTypeFilter !== 'all') {
    $schoolsSql .= " AND g.school_type = :school_type";
    $schoolsParams['school_type'] = $schoolTypeFilter;
}
if ($searchTerm !== '') {
    $schoolsSql .= " AND (g.name LIKE :search_name OR COALESCE(ps.address, '') LIKE :search_address)";
    $schoolsParams['search_name'] = '%' . $searchTerm . '%';
    $schoolsParams['search_address'] = '%' . $searchTerm . '%';
}

$schoolsSql .= "
    GROUP BY
        g.id, g.name, g.school_type, g.latitude, g.longitude, g.radius_meters, g.is_active,
        ps.address, ps.daily_cap_hours, ps.time_in_start, ps.school_status
    ORDER BY g.name ASC";

$schoolsStmt = $pdo->prepare($schoolsSql);
$schoolsStmt->execute($schoolsParams);
$schools = $schoolsStmt->fetchAll();
$schoolPageSize = 6;
$schoolPageCount = max(1, (int) ceil(count($schools) / $schoolPageSize));
$schoolPage = min($schoolPage, $schoolPageCount);
$visibleSchoolCards = array_slice($schools, ($schoolPage - 1) * $schoolPageSize, $schoolPageSize);
$schoolPageUrl = static function (int $page) use ($schoolTypeFilter, $searchTerm): string {
    return '?' . http_build_query([
        'type' => $schoolTypeFilter,
        'q' => $searchTerm,
        'school_page' => $page,
    ]);
};

$activeSchools = array_values(array_filter(
    $schools,
    static fn(array $school): bool => (int) ($school['is_active'] ?? 0) === 1
        && (string) ($school['school_status'] ?? '') === 'active'
));

// Do not offer a school that is already assigned to an active coordinator.
$availableDeploymentSchools = array_values(array_filter(
    $activeSchools,
    static fn(array $school): bool => $isDeploymentSemester
        && ($requiredSchoolType === null || (string) ($school['school_type'] ?? '') === $requiredSchoolType)
        && (int) ($school['active_coordinators'] ?? 0) === 0
));

$schoolsById = [];
foreach ($schools as $school) {
    $schoolsById[(int) $school['id']] = $school;
}

$editSchoolId = isset($_GET['edit_school_id']) ? (int) $_GET['edit_school_id'] : 0;
$editSchool = $editSchoolId > 0 && isset($schoolsById[$editSchoolId]) ? $schoolsById[$editSchoolId] : null;

$activeDeploymentCount = 0;
$endedDeploymentCount = 0;
foreach ($coordinators as $coordinator) {
    if (($coordinator['deployment_status'] ?? null) === 'active') {
        $activeDeploymentCount++;
    } elseif (($coordinator['deployment_status'] ?? null) === 'ended') {
        $endedDeploymentCount++;
    }
}

renderHeadTeacherPortalStart($user ?? [], 'deployment');
?>
<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
    crossorigin=""
>
<section class="mt-6 space-y-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Practicum Deployment</h2>
        <p class="mt-1 text-sm text-slate-600">Deploy coordinators directly to partnered schools. Sections are no longer used in this module.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-sm font-medium text-slate-500">Coordinators</p>
                <p class="mt-2 text-3xl font-bold text-slate-900"><?= count($coordinators) ?></p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-sm font-medium text-slate-500">Active Deployments</p>
                <p class="mt-2 text-3xl font-bold text-emerald-700"><?= $activeDeploymentCount ?></p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-sm font-medium text-slate-500">Ended Deployments</p>
                <p class="mt-2 text-3xl font-bold text-amber-700"><?= $endedDeploymentCount ?></p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-sm font-medium text-slate-500">Active Schools</p>
                <p class="mt-2 text-3xl font-bold text-slate-900"><?= count($activeSchools) ?></p>
            </article>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Coordinator Deployment</h3>
                <p class="text-sm text-slate-500">Assign one <?= $requiredSchoolType === null ? 'Public or Private' : ($requiredSchoolType === 'private_school' ? 'Private' : 'Public') ?> School for the active semester.</p>
            </div>
            <form method="post" class="grid gap-2 sm:grid-cols-3">
                <input type="hidden" name="deployment_action" value="deploy_coordinator">
                <select name="coordinator_id" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700" required>
                    <option value="">Select Coordinator</option>
                    <?php foreach ($coordinators as $coordinator): ?>
                        <option value="<?= (int) $coordinator['id'] ?>">
                            <?= htmlspecialchars((string) ($coordinator['last_name'] . ', ' . $coordinator['first_name'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="geofence_id" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700" required>
                    <option value=""><?= $requiredSchoolType === null ? 'Select School' : ($requiredSchoolType === 'private_school' ? 'Private School' : 'Public School') ?></option>
                    <?php foreach ($availableDeploymentSchools as $school): ?>
                        <option value="<?= (int) $school['id'] ?>"><?= htmlspecialchars((string) $school['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600">
                    Deploy Coordinator
                </button>
            </form>
        </div>

        <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($coordinators as $coordinator): ?>
                <?php
                $coordinatorId = (int) $coordinator['id'];
                $status = $coordinator['deployment_status'] ?? null;
                $currentSchoolId = (int) ($coordinator['geofence_id'] ?? 0);
                $deploymentSchools = $availableDeploymentSchools;
                if (
                    $currentSchoolId > 0
                    && isset($schoolsById[$currentSchoolId])
                    && ($requiredSchoolType === null || (string) ($schoolsById[$currentSchoolId]['school_type'] ?? '') === $requiredSchoolType)
                ) {
                    $deploymentSchools[] = $schoolsById[$currentSchoolId];
                }
                $deploymentSchools = array_values(array_reduce(
                    $deploymentSchools,
                    static function (array $carry, array $school): array {
                        $carry[(int) $school['id']] = $school;
                        return $carry;
                    },
                    []
                ));
                $availableSchools = array_values(array_filter(
                    $deploymentSchools,
                    static fn(array $school): bool => (int) $school['id'] !== $currentSchoolId
                ));
                $formId = 'change-coordinator-' . $coordinatorId;
                ?>
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) ($coordinator['last_name'] . ', ' . $coordinator['first_name'])) ?></p>
                            <p class="text-xs font-semibold text-slate-700">Coordinator ID: <?= htmlspecialchars((string) ($coordinator['coordinator_id'] ?? 'N/A')) ?></p>
                            <p class="text-xs text-slate-500"><?= htmlspecialchars((string) ($coordinator['email'] ?? '')) ?></p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= coordinatorDeploymentBadge(is_string($status) ? $status : null) ?>">
                            <?= htmlspecialchars(coordinatorDeploymentLabel(is_string($status) ? $status : null)) ?>
                        </span>
                    </div>

                    <div class="mt-4 space-y-2 text-sm text-slate-600">
                        <p><span class="font-semibold text-slate-700">Assigned School:</span> <?= htmlspecialchars((string) ($coordinator['school_name'] ?? 'None')) ?></p>
                        <p><span class="font-semibold text-slate-700">School Type:</span> <?= htmlspecialchars((string) (!empty($coordinator['school_type']) ? deploymentTypeLabel((string) $coordinator['school_type']) : 'Not assigned')) ?></p>
                        <p><span class="font-semibold text-slate-700">Start Date:</span> <?= !empty($coordinator['started_at']) ? htmlspecialchars((string) date('Y-m-d', strtotime((string) $coordinator['started_at']))) : '-' ?></p>
                        <p><span class="font-semibold text-slate-700">End Date:</span> <?= !empty($coordinator['ended_at']) ? htmlspecialchars((string) date('Y-m-d', strtotime((string) $coordinator['ended_at']))) : '-' ?></p>
                    </div>

                    <?php if ($status === 'active'): ?>
                        <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500"><?= $requiredSchoolType === null ? 'Assigned School' : 'Assigned ' . ($requiredSchoolType === 'private_school' ? 'Private' : 'Public') . ' School' ?></p>
                            <p class="text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($coordinator['school_name'] ?? 'None')) ?></p>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <form method="post">
                                <input type="hidden" name="deployment_action" value="end_coordinator">
                                <input type="hidden" name="coordinator_id" value="<?= $coordinatorId ?>">
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">
                                    End Deployment
                                </button>
                            </form>
                            <button
                                type="button"
                                class="toggle-change-form inline-flex w-full items-center justify-center rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 <?= $availableSchools === [] ? 'cursor-not-allowed opacity-60' : '' ?>"
                                data-target-id="<?= htmlspecialchars($formId) ?>"
                                <?= $availableSchools === [] ? 'disabled title="No other active school available"' : '' ?>
                            >
                                Reassign
                            </button>
                        </div>
                        <form id="<?= htmlspecialchars($formId) ?>" method="post" class="mt-2 hidden rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <input type="hidden" name="deployment_action" value="deploy_coordinator">
                            <input type="hidden" name="coordinator_id" value="<?= $coordinatorId ?>">
                            <select name="geofence_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-700" required>
                                <option value=""><?= $requiredSchoolType === null ? 'Select School' : ($requiredSchoolType === 'private_school' ? 'Private School' : 'Public School') ?></option>
                                <?php foreach ($deploymentSchools as $school): ?>
                                    <option value="<?= (int) $school['id'] ?>" <?= (int) $school['id'] === $currentSchoolId ? 'selected' : '' ?>><?= htmlspecialchars((string) $school['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="mt-2 inline-flex w-full items-center justify-center rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-600 <?= $availableSchools === [] ? 'cursor-not-allowed opacity-50' : '' ?>" <?= $availableSchools === [] ? 'disabled' : '' ?>>
                                Save Reassignment
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="mt-4 space-y-2">
                            <input type="hidden" name="deployment_action" value="deploy_coordinator">
                            <input type="hidden" name="coordinator_id" value="<?= $coordinatorId ?>">
                            <select name="geofence_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-700" required>
                                <option value=""><?= $requiredSchoolType === null ? 'Select School' : ($requiredSchoolType === 'private_school' ? 'Private School' : 'Public School') ?></option>
                                <?php foreach ($availableDeploymentSchools as $school): ?>
                                    <option value="<?= (int) $school['id'] ?>"><?= htmlspecialchars((string) $school['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-600 <?= $availableDeploymentSchools === [] ? 'cursor-not-allowed opacity-50' : '' ?>" <?= $availableDeploymentSchools === [] ? 'disabled' : '' ?>>
                                <?= $status === 'ended' ? 'Reactivate Deployment' : 'Deploy Coordinator' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if ($coordinators === []): ?>
                <article class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500 md:col-span-2 xl:col-span-3">
                    No coordinators found.
                </article>
            <?php endif; ?>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <h3 class="text-lg font-semibold text-slate-900">Partnered Schools</h3>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <form method="get" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <?php if ($requiredSchoolType !== null): ?>
                        <span class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
                            <?= $requiredSchoolType === 'public_school' ? 'Public Schools Only' : 'Private Schools Only' ?>
                        </span>
                        <input type="hidden" name="type" value="<?= htmlspecialchars($requiredSchoolType, ENT_QUOTES, 'UTF-8') ?>">
                    <?php elseif ($isSummerSemester): ?>
                        <span class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">Public and Private Schools</span>
                        <input type="hidden" name="type" value="all">
                    <?php else: ?>
                        <select name="type" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                            <option value="all" <?= $schoolTypeFilter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="public_school" <?= $schoolTypeFilter === 'public_school' ? 'selected' : '' ?>>Public</option>
                            <option value="private_school" <?= $schoolTypeFilter === 'private_school' ? 'selected' : '' ?>>Private</option>
                        </select>
                    <?php endif; ?>
                    <input type="text" name="q" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Search schools..." class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                    <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"><span aria-hidden="true">&#128269;</span> Search</button>
                </form>
                <button
                    type="button"
                    id="openAddSchoolModal"
                    class="inline-flex items-center gap-1 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600"
                >
                    <span aria-hidden="true">&#65291;</span> Add School
                </button>
            </div>
        </div>

        <div class="mb-5 grid gap-4 sm:grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($visibleSchoolCards as $school): ?>
                <?php $isActive = (int) ($school['is_active'] ?? 0) === 1 && (string) ($school['school_status'] ?? '') === 'active'; ?>
                <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-900"><?= htmlspecialchars((string) $school['name']) ?></p>
                            <p class="text-xs text-slate-500"><?= htmlspecialchars(deploymentTypeLabel((string) $school['school_type'])) ?> School</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' ?>">
                            <?= $isActive ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                    <p class="mt-3 text-sm text-slate-600"><?= htmlspecialchars((string) ($school['address'] ?? 'No address')) ?></p>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500">
                        <p>Radius: <?= number_format((float) $school['radius_meters'], 0) ?>m</p>
                        <p>Time in: <?= htmlspecialchars((new DateTimeImmutable((string) ($school['time_in_start'] ?? '06:00:00')))->format('g:i A')) ?></p>
                        <p>Active Coordinators: <?= (int) ($school['active_coordinators'] ?? 0) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if ($schools === []): ?>
                <article class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500 md:col-span-2 xl:col-span-3">
                    No schools found for this filter.
                </article>
            <?php endif; ?>
        </div>
        <?php if ($schoolPageCount > 1): ?>
            <nav class="mb-5 flex items-center justify-between border-t border-slate-200 pt-4" aria-label="Partnered school pages">
                <span class="text-sm text-slate-500">Showing <?= (($schoolPage - 1) * $schoolPageSize) + 1 ?>–<?= min($schoolPage * $schoolPageSize, count($schools)) ?> of <?= count($schools) ?> schools</span>
                <div class="flex gap-2">
                    <?php if ($schoolPage > 1): ?>
                        <a href="<?= htmlspecialchars($schoolPageUrl($schoolPage - 1), ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Previous</a>
                    <?php endif; ?>
                    <?php if ($schoolPage < $schoolPageCount): ?>
                        <a href="<?= htmlspecialchars($schoolPageUrl($schoolPage + 1), ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">Next</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

        <div class="overflow-x-auto">
            <table class="min-w-[980px] w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="border-b border-slate-200 px-3 py-3">School Name</th>
                        <th class="border-b border-slate-200 px-3 py-3">Type</th>
                        <th class="border-b border-slate-200 px-3 py-3">Address</th>
                        <th class="border-b border-slate-200 px-3 py-3">Geofence Radius</th>
                        <th class="border-b border-slate-200 px-3 py-3">Daily Cap</th>
                        <th class="border-b border-slate-200 px-3 py-3">Time-In Starts</th>
                        <th class="border-b border-slate-200 px-3 py-3">Status</th>
                        <th class="border-b border-slate-200 px-3 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schools as $school): ?>
                        <?php
                        $schoolId = (int) $school['id'];
                        $isActive = (int) ($school['is_active'] ?? 0) === 1 && (string) ($school['school_status'] ?? '') === 'active';
                        $hasActiveCoordinatorDeployment = (int) ($school['active_coordinators'] ?? 0) > 0;
                        $hasActiveStudentDeployment = (int) ($school['active_student_deployments'] ?? 0) > 0;
                        $hasActiveDeployment = $hasActiveCoordinatorDeployment || $hasActiveStudentDeployment;
                        ?>
                        <tr class="hover:bg-slate-50" data-school-row="<?= $schoolId ?>">
                            <td class="border-b border-slate-100 px-3 py-3">
                                <p class="font-semibold text-slate-900"><?= htmlspecialchars((string) $school['name']) ?></p>
                                <p class="text-xs text-slate-500">Lat: <?= htmlspecialchars((string) $school['latitude']) ?>, Lng: <?= htmlspecialchars((string) $school['longitude']) ?></p>
                            </td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= htmlspecialchars(deploymentTypeLabel((string) $school['school_type'])) ?></td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= htmlspecialchars((string) ($school['address'] ?? '')) ?></td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= number_format((float) $school['radius_meters'], 0) ?>m</td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= number_format((float) ($school['daily_cap_hours'] ?? 8), 1) ?> hrs/day</td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= htmlspecialchars((new DateTimeImmutable((string) ($school['time_in_start'] ?? '06:00:00')))->format('g:i A')) ?></td>
                            <td class="border-b border-slate-100 px-3 py-3">
                                <span data-school-status="<?= $schoolId ?>" class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' ?>">
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="border-b border-slate-100 px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="?<?= htmlspecialchars(http_build_query(['edit_school_id' => $schoolId, 'type' => $schoolTypeFilter, 'q' => $searchTerm, 'school_page' => $schoolPage])) ?>" class="inline-flex items-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                        <span aria-hidden="true">&#9998;</span> Edit
                                    </a>
                                    <?php if (!$isDeploymentSemester): ?>
                                        <button type="button" disabled class="inline-flex cursor-not-allowed items-center gap-1 rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-400" title="Activate a supported semester before changing partnered-school status.">
                                            <span aria-hidden="true">&#128274;</span> Semester Required
                                        </button>
                                    <?php elseif ($isActive && $hasActiveDeployment): ?>
                                        <button type="button" data-school-protected data-protection-reason="<?= $hasActiveCoordinatorDeployment ? 'This school is currently deployed to a coordinator. End or reassign that coordinator deployment first.' : 'This school has an active student deployment. Complete or move that active deployment first.' ?>" class="inline-flex items-center gap-1 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-100" title="End or reassign the current deployment before deactivating this school.">
                                            <span aria-hidden="true">&#128274;</span> <?= $hasActiveCoordinatorDeployment ? 'Assigned' : 'In Use' ?>
                                        </button>
                                    <?php else: ?>
                                        <form method="post" class="js-school-toggle" data-school-id="<?= $schoolId ?>">
                                            <input type="hidden" name="deployment_action" value="toggle_school">
                                            <input type="hidden" name="geofence_id" value="<?= $schoolId ?>">
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                                <span aria-hidden="true"><?= $isActive ? '&#9208;' : '&#9654;' ?></span>
                                                <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($schools === []): ?>
                        <tr>
                            <td colspan="8" class="border-b border-slate-100 px-3 py-8 text-center text-sm text-slate-500">No schools found for this filter.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<div id="addSchoolModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 px-4 py-8">
    <div class="relative max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold tracking-tight text-slate-900">Add Partnered School</h3>
            <button type="button" id="closeAddSchoolModalX" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100 hover:text-slate-700">X</button>
        </div>
        <form method="post" class="space-y-5 p-6" id="addSchoolForm">
            <input type="hidden" name="deployment_action" value="add_school">

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">School Name</label>
                <div class="flex gap-2">
                    <input id="geoSchoolNameInput" type="text" name="school_name" placeholder="e.g. PHINMA Cagayan de Oro College" class="flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                    <button type="button" id="geoSearchBtn" class="inline-flex items-center gap-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100"><span aria-hidden="true">&#128269;</span> Search</button>
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">School Type</label>
                <div class="flex items-center gap-6 text-sm text-slate-700">
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="school_type" value="public" checked>
                        <span>Public</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="school_type" value="private">
                        <span>Private</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Address</label>
                <input id="geoAddressInput" type="text" name="address" placeholder="e.g. Cagayan de Oro City, Misamis Oriental" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                <p class="mt-2 text-xs text-slate-500">Tip: you can drag the map marker for accurate geofencing.</p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label class="text-sm font-semibold text-slate-700">Latitude</label>
                        <button type="button" id="geoUseDefaultBtn" class="text-xs font-semibold text-emerald-700 hover:text-emerald-600">Use CDO Default</button>
                    </div>
                    <input id="geoLatInput" type="number" name="latitude" step="0.0000001" value="8.4795" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">Longitude</label>
                    <input id="geoLngInput" type="number" name="longitude" step="0.0000001" value="124.6473" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Geofence Radius (meters)</label>
                <input id="geoRadiusInput" type="number" name="radius_meters" min="20" max="500" value="80" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                <p class="mt-2 text-xs text-slate-500">Default is 80m. Min 20m, max 500m.</p>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Daily Cap Hours</label>
                <input type="number" name="daily_cap_hours" step="0.1" min="1" max="24" value="8" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Student Time-In Starts</label>
                <select name="time_in_start" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                    <?php for ($hour = 0; $hour <= 15; $hour++): $timeValue = sprintf('%02d:00', $hour); ?>
                        <option value="<?= $timeValue ?>" <?= $hour === 6 ? 'selected' : '' ?>><?= htmlspecialchars((new DateTimeImmutable($timeValue))->format('g:i A')) ?></option>
                    <?php endfor; ?>
                </select>
                <p class="mt-2 text-xs text-slate-500">Choose when students at this school may start time-in (12:00 AM to 3:00 PM).</p>
            </div>

            <div id="geoMap" class="h-64 w-full overflow-hidden rounded-xl border border-slate-300"></div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" id="closeAddSchoolModalBtn" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600"><span aria-hidden="true">&#65291;</span> Add School</button>
            </div>
        </form>
    </div>
</div>
<?php if ($editSchool): ?>
<div id="editSchoolModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4 py-8">
    <div class="relative max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold tracking-tight text-slate-900">Edit Partnered School</h3>
            <a href="<?= htmlspecialchars(appUrl('head_teacher/practicum_deployment.php?' . http_build_query(['type' => $schoolTypeFilter, 'q' => $searchTerm]))) ?>" id="closeEditSchoolModalX" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100 hover:text-slate-700">X</a>
        </div>
        <form method="post" class="space-y-5 p-6" id="editSchoolForm">
            <input type="hidden" name="deployment_action" value="edit_school">
            <input type="hidden" name="geofence_id" value="<?= (int) $editSchool['id'] ?>">

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">School Name</label>
                <input id="editGeoSchoolNameInput" type="text" name="school_name" value="<?= htmlspecialchars((string) $editSchool['name']) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">School Type</label>
                <div class="flex items-center gap-6 text-sm text-slate-700">
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="school_type" value="public" <?= (string) $editSchool['school_type'] === 'public_school' ? 'checked' : '' ?>>
                        <span>Public</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="school_type" value="private" <?= (string) $editSchool['school_type'] === 'private_school' ? 'checked' : '' ?>>
                        <span>Private</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Address</label>
                <div class="flex gap-2">
                    <input id="editGeoAddressInput" type="text" name="address" value="<?= htmlspecialchars((string) ($editSchool['address'] ?? '')) ?>" class="flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                    <button type="button" id="editGeoSearchBtn" class="inline-flex items-center gap-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100"><span aria-hidden="true">&#128269;</span> Search</button>
                </div>
                <p class="mt-2 text-xs text-slate-500">Tip: you can drag the map marker for accurate geofencing.</p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label class="text-sm font-semibold text-slate-700">Latitude</label>
                        <button type="button" id="editGeoUseDefaultBtn" class="text-xs font-semibold text-emerald-700 hover:text-emerald-600">Use CDO Default</button>
                    </div>
                    <input id="editGeoLatInput" type="number" name="latitude" step="0.0000001" value="<?= htmlspecialchars((string) $editSchool['latitude']) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">Longitude</label>
                    <input id="editGeoLngInput" type="number" name="longitude" step="0.0000001" value="<?= htmlspecialchars((string) $editSchool['longitude']) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Geofence Radius (meters)</label>
                <input id="editGeoRadiusInput" type="number" name="radius_meters" min="20" max="500" value="<?= htmlspecialchars((string) $editSchool['radius_meters']) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                <p class="mt-2 text-xs text-slate-500">Default is 80m. Min 20m, max 500m.</p>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Daily Cap Hours</label>
                <input type="number" name="daily_cap_hours" step="0.1" min="1" max="24" value="<?= htmlspecialchars((string) ($editSchool['daily_cap_hours'] ?? '8')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Student Time-In Starts</label>
                <?php $editTimeInStart = substr((string) ($editSchool['time_in_start'] ?? '06:00:00'), 0, 5); ?>
                <select name="time_in_start" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                    <?php for ($hour = 0; $hour <= 15; $hour++): $timeValue = sprintf('%02d:00', $hour); ?>
                        <option value="<?= $timeValue ?>" <?= $editTimeInStart === $timeValue ? 'selected' : '' ?>><?= htmlspecialchars((new DateTimeImmutable($timeValue))->format('g:i A')) ?></option>
                    <?php endfor; ?>
                </select>
                <p class="mt-2 text-xs text-slate-500">Choose when students at this school may start time-in (12:00 AM to 3:00 PM).</p>
            </div>

            <div id="editGeoMap" class="h-64 w-full overflow-hidden rounded-xl border border-slate-300"></div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <a href="<?= htmlspecialchars(appUrl('head_teacher/practicum_deployment.php?' . http_build_query(['type' => $schoolTypeFilter, 'q' => $searchTerm]))) ?>" id="closeEditSchoolModalBtn" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600"><span aria-hidden="true">&#10003;</span> Save School</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('addSchoolModal');
    const openBtn = document.getElementById('openAddSchoolModal');
    const closeBtn = document.getElementById('closeAddSchoolModalBtn');
    const closeX = document.getElementById('closeAddSchoolModalX');
    const addressInput = document.getElementById('geoAddressInput');
    const schoolNameInput = document.getElementById('geoSchoolNameInput');
    const searchBtn = document.getElementById('geoSearchBtn');
    const latInput = document.getElementById('geoLatInput');
    const lngInput = document.getElementById('geoLngInput');
    const radiusInput = document.getElementById('geoRadiusInput');
    const useDefaultBtn = document.getElementById('geoUseDefaultBtn');
    const changeButtons = document.querySelectorAll('.toggle-change-form');

    document.querySelectorAll('.js-school-toggle').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            if (!button || button.disabled) return;
            const originalText = button.innerHTML;
            button.disabled = true;
            button.textContent = 'Updating...';
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
                    body: new FormData(form),
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Unable to update school status.');
                window.PhinmaPortalLiveSession?.notify(500, true);
                const schoolId = form.dataset.schoolId || '';
                const active = Number(result.is_active) === 1;
                const status = document.querySelector('[data-school-status="' + CSS.escape(schoolId) + '"]');
                if (status) {
                    status.textContent = active ? 'Active' : 'Inactive';
                    status.classList.toggle('bg-emerald-100', active);
                    status.classList.toggle('text-emerald-700', active);
                    status.classList.toggle('bg-slate-200', !active);
                    status.classList.toggle('text-slate-700', !active);
                }
                button.innerHTML = '<span aria-hidden="true">' + (active ? '&#9208;' : '&#9654;') + '</span> ' + (active ? 'Deactivate' : 'Activate');
                button.disabled = false;
                if (window.Swal) {
                    Swal.fire({icon: 'success', title: 'School updated', text: result.message, timer: 1300, showConfirmButton: false});
                }
            } catch (error) {
                button.disabled = false;
                button.innerHTML = originalText;
                if (window.Swal) Swal.fire({icon: 'error', title: 'Update failed', text: error.message || 'Please try again.'});
            }
        });
    });

    document.querySelectorAll('[data-school-protected]').forEach(function (button) {
        button.addEventListener('click', function () {
            const message = button.dataset.protectionReason || 'This school has a current deployment. End or reassign it before deactivating the school.';
            if (window.Swal) {
                Swal.fire({icon: 'warning', title: 'School is assigned', text: message, confirmButtonColor: '#047857'});
            }
        });
    });

    const defaultLat = 8.4795;
    const defaultLng = 124.6473;

    let map = null;
    let marker = null;
    let radiusCircle = null;

    function buildAutoSchoolName(addr) {
        const locality = addr?.suburb || addr?.village || addr?.town || addr?.city || addr?.municipality || addr?.county || 'Selected Area';
        return 'School near ' + locality;
    }

    async function reverseGeocodeTo(targetAddressInput, targetSchoolNameInput, lat, lng) {
        if (!targetAddressInput || !targetSchoolNameInput) return;
        try {
            const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&addressdetails=1&lat='
                + encodeURIComponent(String(lat)) + '&lon=' + encodeURIComponent(String(lng));
            const response = await fetch(url, {headers: {'Accept': 'application/json'}});
            const data = await response.json();
            if (data && data.display_name) {
                targetAddressInput.value = data.display_name;
            }
            const autoName = buildAutoSchoolName(data?.address || {});
            if (!targetSchoolNameInput.value.trim() || targetSchoolNameInput.value.trim().toLowerCase().startsWith('school near ')) {
                targetSchoolNameInput.value = autoName;
            }
        } catch (e) {
            // Manual input remains available.
        }
    }

    async function reverseGeocodeAndAutofill(lat, lng) {
        reverseGeocodeTo(addressInput, schoolNameInput, lat, lng);
    }

    function syncRadiusCircle() {
        if (!radiusCircle || !marker) return;
        const radius = Math.max(20, Math.min(500, parseFloat(radiusInput.value) || 80));
        radiusCircle.setRadius(radius);
        radiusCircle.setLatLng(marker.getLatLng());
    }

    function openModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        if (!map && window.L) {
            map = L.map('geoMap').setView([parseFloat(latInput.value) || defaultLat, parseFloat(lngInput.value) || defaultLng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            marker = L.marker([parseFloat(latInput.value) || defaultLat, parseFloat(lngInput.value) || defaultLng], {draggable: true}).addTo(map);
            radiusCircle = L.circle(marker.getLatLng(), {
                radius: Math.max(20, Math.min(500, parseFloat(radiusInput.value) || 80)),
                color: '#059669',
                weight: 2,
                fillColor: '#10b981',
                fillOpacity: 0.14
            }).addTo(map);

            marker.on('dragend', function (e) {
                const pos = e.target.getLatLng();
                latInput.value = pos.lat.toFixed(7);
                lngInput.value = pos.lng.toFixed(7);
                syncRadiusCircle();
                reverseGeocodeAndAutofill(pos.lat, pos.lng);
            });

            map.on('click', function (e) {
                marker.setLatLng(e.latlng);
                latInput.value = e.latlng.lat.toFixed(7);
                lngInput.value = e.latlng.lng.toFixed(7);
                syncRadiusCircle();
                reverseGeocodeAndAutofill(e.latlng.lat, e.latlng.lng);
            });
        }
        if (map) {
            setTimeout(function () { map.invalidateSize(); }, 120);
        }
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function syncMapFromInputs() {
        if (!map || !marker) return;
        const lat = parseFloat(latInput.value);
        const lng = parseFloat(lngInput.value);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        marker.setLatLng([lat, lng]);
        map.setView([lat, lng], 16);
        syncRadiusCircle();
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (closeX) closeX.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    if (latInput) latInput.addEventListener('change', syncMapFromInputs);
    if (lngInput) lngInput.addEventListener('change', syncMapFromInputs);
    if (radiusInput) radiusInput.addEventListener('input', syncRadiusCircle);

    if (useDefaultBtn) {
        useDefaultBtn.addEventListener('click', function () {
            latInput.value = defaultLat.toFixed(7);
            lngInput.value = defaultLng.toFixed(7);
            syncMapFromInputs();
            reverseGeocodeAndAutofill(defaultLat, defaultLng);
        });
    }

    if (searchBtn) {
        searchBtn.addEventListener('click', async function () {
            const q = schoolNameInput.value.trim();
            if (!q) return;
            searchBtn.disabled = true;
            const prevText = searchBtn.textContent;
            searchBtn.textContent = 'Searching...';
            try {
                const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q);
                const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                const data = await response.json();
                if (Array.isArray(data) && data.length > 0) {
                    const lat = parseFloat(data[0].lat);
                    const lng = parseFloat(data[0].lon);
                    latInput.value = lat.toFixed(7);
                    lngInput.value = lng.toFixed(7);
                    syncMapFromInputs();
                    if (data[0].display_name) {
                        addressInput.value = data[0].display_name;
                    }
                    if (!schoolNameInput.value.trim() || schoolNameInput.value.trim().toLowerCase().startsWith('school near ')) {
                        schoolNameInput.value = buildAutoSchoolName(data[0].address || {});
                    }
                } else {
                    alert('School not found. Move the map marker manually.');
                }
            } catch (err) {
                alert('Search failed. Move the map marker manually.');
            } finally {
                searchBtn.disabled = false;
                searchBtn.textContent = prevText;
            }
        });
    }

    if (schoolNameInput) {
        schoolNameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (searchBtn) {
                    searchBtn.click();
                }
            }
        });
    }

    const editModal = document.getElementById('editSchoolModal');
    const editAddressInput = document.getElementById('editGeoAddressInput');
    const editSchoolNameInput = document.getElementById('editGeoSchoolNameInput');
    const editSearchBtn = document.getElementById('editGeoSearchBtn');
    const editLatInput = document.getElementById('editGeoLatInput');
    const editLngInput = document.getElementById('editGeoLngInput');
    const editRadiusInput = document.getElementById('editGeoRadiusInput');
    const editUseDefaultBtn = document.getElementById('editGeoUseDefaultBtn');
    let editMap = null;
    let editMarker = null;
    let editRadiusCircle = null;

    function syncEditRadiusCircle() {
        if (!editRadiusCircle || !editMarker || !editRadiusInput) return;
        const radius = Math.max(20, Math.min(500, parseFloat(editRadiusInput.value) || 80));
        editRadiusCircle.setRadius(radius);
        editRadiusCircle.setLatLng(editMarker.getLatLng());
    }

    function syncEditMapFromInputs() {
        if (!editMap || !editMarker || !editLatInput || !editLngInput) return;
        const lat = parseFloat(editLatInput.value);
        const lng = parseFloat(editLngInput.value);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        editMarker.setLatLng([lat, lng]);
        editMap.setView([lat, lng], 16);
        syncEditRadiusCircle();
    }

    function initEditMap() {
        if (!editModal || !window.L || !editLatInput || !editLngInput || !editRadiusInput) return;
        const startLat = parseFloat(editLatInput.value) || defaultLat;
        const startLng = parseFloat(editLngInput.value) || defaultLng;
        if (!editMap) {
            editMap = L.map('editGeoMap').setView([startLat, startLng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(editMap);

            editMarker = L.marker([startLat, startLng], {draggable: true}).addTo(editMap);
            editRadiusCircle = L.circle(editMarker.getLatLng(), {
                radius: Math.max(20, Math.min(500, parseFloat(editRadiusInput.value) || 80)),
                color: '#059669',
                weight: 2,
                fillColor: '#10b981',
                fillOpacity: 0.14
            }).addTo(editMap);

            editMarker.on('dragend', function (e) {
                const pos = e.target.getLatLng();
                editLatInput.value = pos.lat.toFixed(7);
                editLngInput.value = pos.lng.toFixed(7);
                syncEditRadiusCircle();
                reverseGeocodeTo(editAddressInput, editSchoolNameInput, pos.lat, pos.lng);
            });

            editMap.on('click', function (e) {
                editMarker.setLatLng(e.latlng);
                editLatInput.value = e.latlng.lat.toFixed(7);
                editLngInput.value = e.latlng.lng.toFixed(7);
                syncEditRadiusCircle();
                reverseGeocodeTo(editAddressInput, editSchoolNameInput, e.latlng.lat, e.latlng.lng);
            });
        }
        setTimeout(function () {
            editMap.invalidateSize();
            syncEditMapFromInputs();
        }, 120);
    }

    if (editModal) {
        initEditMap();
    }
    if (editLatInput) editLatInput.addEventListener('change', syncEditMapFromInputs);
    if (editLngInput) editLngInput.addEventListener('change', syncEditMapFromInputs);
    if (editRadiusInput) editRadiusInput.addEventListener('input', syncEditRadiusCircle);
    if (editUseDefaultBtn) {
        editUseDefaultBtn.addEventListener('click', function () {
            editLatInput.value = defaultLat.toFixed(7);
            editLngInput.value = defaultLng.toFixed(7);
            syncEditMapFromInputs();
            reverseGeocodeTo(editAddressInput, editSchoolNameInput, defaultLat, defaultLng);
        });
    }
    if (editSearchBtn) {
        editSearchBtn.addEventListener('click', async function () {
            const q = editAddressInput ? editAddressInput.value.trim() : '';
            if (!q) return;
            editSearchBtn.disabled = true;
            const prevText = editSearchBtn.textContent;
            editSearchBtn.textContent = 'Searching...';
            try {
                const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q);
                const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                const data = await response.json();
                if (Array.isArray(data) && data.length > 0) {
                    const lat = parseFloat(data[0].lat);
                    const lng = parseFloat(data[0].lon);
                    editLatInput.value = lat.toFixed(7);
                    editLngInput.value = lng.toFixed(7);
                    syncEditMapFromInputs();
                    if (data[0].display_name && editAddressInput) {
                        editAddressInput.value = data[0].display_name;
                    }
                    if (editSchoolNameInput && (!editSchoolNameInput.value.trim() || editSchoolNameInput.value.trim().toLowerCase().startsWith('school near '))) {
                        editSchoolNameInput.value = buildAutoSchoolName(data[0].address || {});
                    }
                } else {
                    alert('Address not found. Move the map marker manually.');
                }
            } catch (err) {
                alert('Search failed. Move the map marker manually.');
            } finally {
                editSearchBtn.disabled = false;
                editSearchBtn.textContent = prevText;
            }
        });
    }
    if (editAddressInput) {
        editAddressInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (editSearchBtn) {
                    editSearchBtn.click();
                }
            }
        });
    }

    changeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const targetId = button.getAttribute('data-target-id');
            if (!targetId) return;
            const target = document.getElementById(targetId);
            if (!target) return;
            target.classList.toggle('hidden');
        });
    });
});
</script>
<?php renderHeadTeacherPortalEnd(); ?>
