<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS partner_schools (
        geofence_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        address VARCHAR(255) NOT NULL DEFAULT '',
        daily_cap_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00,
        school_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_partner_schools_geofence
            FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS section_deployments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        section_id BIGINT UNSIGNED NOT NULL,
        geofence_id BIGINT UNSIGNED NOT NULL,
        deployment_type ENUM('public_school', 'private_school') NOT NULL,
        deployment_status ENUM('running', 'ended') NOT NULL DEFAULT 'running',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ended_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_section_deployments_section
            FOREIGN KEY (section_id) REFERENCES sections(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_section_deployments_geofence
            FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
            ON DELETE RESTRICT ON UPDATE CASCADE,
        INDEX idx_section_deployments_section (section_id),
        INDEX idx_section_deployments_geofence (geofence_id),
        INDEX idx_section_deployments_type_status (deployment_type, deployment_status)
    ) ENGINE=InnoDB"
);

function deploymentTypeLabel(string $type): string
{
    return $type === 'private_school' ? 'Private' : 'Public';
}

function deploymentStatusBadge(string $status): string
{
    if ($status === 'running') {
        return 'bg-emerald-100 text-emerald-700';
    }
    return 'bg-slate-100 text-slate-700';
}

function normalizeSchoolName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', (string) $name);
    return trim((string) $name);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['deployment_action'] ?? ''));

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

            $geofenceId = (int) $pdo->lastInsertId();
            $insertPartner = $pdo->prepare(
                "INSERT INTO partner_schools
                    (geofence_id, address, daily_cap_hours, school_status)
                 VALUES
                    (:geofence_id, :address, :daily_cap_hours, 'active')"
            );
            $insertPartner->execute([
                'geofence_id' => $geofenceId,
                'address' => $address,
                'daily_cap_hours' => $dailyCap,
            ]);

            $pdo->commit();
            htSetFlash('success', 'School added successfully.');
        } elseif ($action === 'toggle_school') {
            $geofenceId = (int) ($_POST['geofence_id'] ?? 0);
            if ($geofenceId <= 0) {
                throw new RuntimeException('Invalid school selection.');
            }

            $pdo->beginTransaction();
            $currentStmt = $pdo->prepare("SELECT school_status FROM partner_schools WHERE geofence_id = :geofence_id");
            $currentStmt->execute(['geofence_id' => $geofenceId]);
            $currentStatus = (string) $currentStmt->fetchColumn();
            if ($currentStatus === '') {
                throw new RuntimeException('School not found.');
            }
            $nextStatus = $currentStatus === 'active' ? 'inactive' : 'active';
            $nextIsActive = $nextStatus === 'active' ? 1 : 0;

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
            htSetFlash('success', 'School status updated.');
        } elseif ($action === 'assign_partner') {
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $geofenceId = (int) ($_POST['geofence_id'] ?? 0);
            $deploymentType = (string) ($_POST['deployment_type'] ?? '');

            if ($sectionId <= 0 || !in_array($deploymentType, ['public_school', 'private_school'], true)) {
                throw new RuntimeException('Invalid deployment request.');
            }
            if ($geofenceId <= 0) {
                throw new RuntimeException('Please select a school before assigning.');
            }

            $typeCheck = $pdo->prepare(
                "SELECT school_type, is_active FROM geofence_locations WHERE id = :id LIMIT 1"
            );
            $typeCheck->execute(['id' => $geofenceId]);
            $schoolRow = $typeCheck->fetch();
            if (!$schoolRow) {
                throw new RuntimeException('Selected school does not exist.');
            }
            if ((string) $schoolRow['school_type'] !== $deploymentType) {
                throw new RuntimeException('Selected school type does not match this deployment slot.');
            }
            if ((int) $schoolRow['is_active'] !== 1) {
                throw new RuntimeException('Selected school is inactive.');
            }

            $pdo->beginTransaction();

            $endRunning = $pdo->prepare(
                "UPDATE section_deployments
                 SET deployment_status = 'ended',
                     ended_at = NOW()
                 WHERE section_id = :section_id
                   AND deployment_type = :deployment_type
                   AND deployment_status = 'running'"
            );
            $endRunning->execute([
                'section_id' => $sectionId,
                'deployment_type' => $deploymentType,
            ]);

            $insertDeployment = $pdo->prepare(
                "INSERT INTO section_deployments
                    (section_id, geofence_id, deployment_type, deployment_status, started_at)
                 VALUES
                    (:section_id, :geofence_id, :deployment_type, 'running', NOW())"
            );
            $insertDeployment->execute([
                'section_id' => $sectionId,
                'geofence_id' => $geofenceId,
                'deployment_type' => $deploymentType,
            ]);

            $syncAssignments = $pdo->prepare(
                "INSERT INTO student_geofence_assignments (student_user_id, geofence_id, assignment_status, assigned_at)
                 SELECT ss.student_user_id, :geofence_id, 'active', NOW()
                 FROM section_students ss
                 WHERE ss.section_id = :section_id
                   AND ss.enrollment_status = 'active'
                 ON DUPLICATE KEY UPDATE
                    assignment_status = VALUES(assignment_status),
                    assigned_at = VALUES(assigned_at)"
            );
            $syncAssignments->execute([
                'section_id' => $sectionId,
                'geofence_id' => $geofenceId,
            ]);

            $pdo->commit();
            htSetFlash('success', 'Partner assigned successfully.');
        } elseif ($action === 'end_partner') {
            $deploymentId = (int) ($_POST['deployment_id'] ?? 0);
            if ($deploymentId <= 0) {
                throw new RuntimeException('Invalid deployment.');
            }

            $endStmt = $pdo->prepare(
                "UPDATE section_deployments
                 SET deployment_status = 'ended',
                     ended_at = NOW()
                 WHERE id = :id
                   AND deployment_status = 'running'"
            );
            $endStmt->execute(['id' => $deploymentId]);

            htSetFlash('success', 'Deployment ended successfully.');
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

            if ($geofenceId <= 0) {
                throw new RuntimeException('Invalid school.');
            }
            if ($name === '' || $address === '') {
                throw new RuntimeException('School name and address are required.');
            }

            $runningTypeConflictStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM section_deployments
                 WHERE geofence_id = :geofence_id
                   AND deployment_status = 'running'
                   AND deployment_type <> :deployment_type"
            );
            $runningTypeConflictStmt->execute([
                'geofence_id' => $geofenceId,
                'deployment_type' => $schoolType,
            ]);
            if ((int) $runningTypeConflictStmt->fetchColumn() > 0) {
                throw new RuntimeException('Cannot change school type while a running deployment exists.');
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
                     daily_cap_hours = :daily_cap_hours
                 WHERE geofence_id = :geofence_id"
            );
            $updatePartner->execute([
                'address' => $address,
                'daily_cap_hours' => $dailyCap,
                'geofence_id' => $geofenceId,
            ]);

            $pdo->commit();
            htSetFlash('success', 'School updated successfully.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
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
    if (isset($_GET['section_id'])) {
        $query['section_id'] = (int) $_GET['section_id'];
    }
    header('Location: practicum_deployment.php' . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

$semesterStmt = $pdo->query(
    "SELECT sem.id, sem.semester_name, sem.semester_status, sy.year_label
     FROM semesters sem
     JOIN school_years sy ON sy.id = sem.school_year_id
     ORDER BY
       CASE sem.semester_status
         WHEN 'active' THEN 0
         WHEN 'planned' THEN 1
         WHEN 'closed' THEN 2
         ELSE 3
       END,
       sy.start_date DESC,
       sem.semester_no ASC
     LIMIT 1"
);
$currentSemester = $semesterStmt->fetch() ?: null;
$currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;

$selectedSectionId = isset($_GET['section_id']) ? max(0, (int) $_GET['section_id']) : 0;
$schoolTypeFilter = trim((string) ($_GET['type'] ?? 'all'));
if (!in_array($schoolTypeFilter, ['all', 'public_school', 'private_school'], true)) {
    $schoolTypeFilter = 'all';
}
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$sections = [];
$sectionStudentsCount = [];
$attendanceTotals = [];
$runningDeployments = [];
$endedTotals = [];

if ($currentSemesterId > 0) {
    $sectionsStmt = $pdo->prepare(
        "SELECT s.id, s.section_name, s.section_code, s.section_status,
                COUNT(ss.id) AS student_count
         FROM sections s
         LEFT JOIN section_students ss
            ON ss.section_id = s.id
           AND ss.semester_id = s.semester_id
           AND ss.enrollment_status = 'active'
         WHERE s.semester_id = :semester_id
         GROUP BY s.id, s.section_name, s.section_code, s.section_status
         ORDER BY s.section_name ASC"
    );
    $sectionsStmt->execute(['semester_id' => $currentSemesterId]);
    $sections = $sectionsStmt->fetchAll();

    foreach ($sections as $section) {
        $sectionStudentsCount[(int) $section['id']] = (int) $section['student_count'];
    }

    $attendanceTotalsStmt = $pdo->prepare(
        "SELECT ar.section_id, ot.ojt_code, COALESCE(SUM(ar.total_hours), 0) AS total_hours
         FROM attendance_records ar
         JOIN ojt_types ot ON ot.id = ar.ojt_type_id
         WHERE ar.semester_id = :semester_id
         GROUP BY ar.section_id, ot.ojt_code"
    );
    $attendanceTotalsStmt->execute(['semester_id' => $currentSemesterId]);
    foreach ($attendanceTotalsStmt->fetchAll() as $row) {
        $sectionId = (int) $row['section_id'];
        $type = (string) $row['ojt_code'];
        $attendanceTotals[$sectionId][$type] = (float) $row['total_hours'];
    }

    $runningStmt = $pdo->prepare(
        "SELECT sd.id, sd.section_id, sd.deployment_type, sd.deployment_status, sd.started_at, sd.ended_at,
                g.id AS geofence_id, g.name, g.school_type, g.radius_meters,
                ps.address, ps.daily_cap_hours, ps.school_status
         FROM section_deployments sd
         JOIN geofence_locations g ON g.id = sd.geofence_id
         LEFT JOIN partner_schools ps ON ps.geofence_id = g.id
         JOIN sections s ON s.id = sd.section_id
         WHERE s.semester_id = :semester_id
           AND sd.deployment_status = 'running'
         ORDER BY sd.started_at DESC"
    );
    $runningStmt->execute(['semester_id' => $currentSemesterId]);
    foreach ($runningStmt->fetchAll() as $row) {
        $runningDeployments[(int) $row['section_id']][(string) $row['deployment_type']] = $row;
    }

    $endedTotalsStmt = $pdo->prepare(
        "SELECT sd.section_id, sd.deployment_type, COUNT(*) AS ended_count
         FROM section_deployments sd
         JOIN sections s ON s.id = sd.section_id
         WHERE s.semester_id = :semester_id
           AND sd.deployment_status = 'ended'
         GROUP BY sd.section_id, sd.deployment_type"
    );
    $endedTotalsStmt->execute(['semester_id' => $currentSemesterId]);
    foreach ($endedTotalsStmt->fetchAll() as $row) {
        $endedTotals[(int) $row['section_id']][(string) $row['deployment_type']] = (int) $row['ended_count'];
    }
}

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
        ps.school_status,
        COALESCE(COUNT(DISTINCT sda.section_id), 0) AS linked_sections
    FROM geofence_locations g
    LEFT JOIN partner_schools ps ON ps.geofence_id = g.id
    LEFT JOIN section_deployments sda
      ON sda.geofence_id = g.id
     AND sda.deployment_status = 'running'
    WHERE 1 = 1";

$schoolsParams = [];
if ($schoolTypeFilter !== 'all') {
    $schoolsSql .= " AND g.school_type = :school_type";
    $schoolsParams['school_type'] = $schoolTypeFilter;
}
if ($searchTerm !== '') {
    $schoolsSql .= " AND (g.name LIKE :search OR COALESCE(ps.address, '') LIKE :search)";
    $schoolsParams['search'] = '%' . $searchTerm . '%';
}

$schoolsSql .= "
    GROUP BY
        g.id, g.name, g.school_type, g.latitude, g.longitude, g.radius_meters, g.is_active,
        ps.address, ps.daily_cap_hours, ps.school_status
    ORDER BY g.name ASC";

$schoolsStmt = $pdo->prepare($schoolsSql);
$schoolsStmt->execute($schoolsParams);
$schools = $schoolsStmt->fetchAll();

$schoolsByType = [
    'public_school' => [],
    'private_school' => [],
];
$assignableSchoolsByType = [
    'public_school' => [],
    'private_school' => [],
];
foreach ($schools as $school) {
    $type = (string) $school['school_type'];
    if (isset($schoolsByType[$type])) {
        $schoolsByType[$type][] = $school;
        if ((int) ($school['is_active'] ?? 0) === 1) {
            $assignableSchoolsByType[$type][] = $school;
        }
    }
}

$editSchoolId = isset($_GET['edit_school_id']) ? (int) $_GET['edit_school_id'] : 0;
$editSchool = null;
if ($editSchoolId > 0) {
    foreach ($schools as $school) {
        if ((int) $school['id'] === $editSchoolId) {
            $editSchool = $school;
            break;
        }
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
        <p class="mt-1 text-sm text-slate-600">Manage partnered schools, geofencing, and section deployment assignments.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 xl:grid-cols-4">
        <?php foreach ($sections as $section): ?>
            <?php
            $sectionId = (int) $section['id'];
            $publicRunning = $runningDeployments[$sectionId]['public_school'] ?? null;
            $privateRunning = $runningDeployments[$sectionId]['private_school'] ?? null;
            $publicHours = (float) ($attendanceTotals[$sectionId]['public_school'] ?? 0);
            $privateHours = (float) ($attendanceTotals[$sectionId]['private_school'] ?? 0);
            ?>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <p class="text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) $section['section_name']) ?></p>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars((string) $section['section_code']) ?></p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                        <?= (int) ($sectionStudentsCount[$sectionId] ?? 0) ?> Students
                    </span>
                </div>

                <div class="space-y-3">
                    <?php foreach (['public_school' => $publicRunning, 'private_school' => $privateRunning] as $type => $activeRow): ?>
                        <?php
                        $slotLabel = deploymentTypeLabel($type);
                        $slotSchools = $assignableSchoolsByType[$type] ?? [];
                        $availableSwitchSchools = [];
                        if ($activeRow) {
                            $activeGeofenceId = (int) ($activeRow['geofence_id'] ?? 0);
                            $activeName = normalizeSchoolName((string) ($activeRow['name'] ?? ''));
                            foreach ($slotSchools as $candidateSchool) {
                                $candidateId = (int) ($candidateSchool['id'] ?? 0);
                                $candidateName = normalizeSchoolName((string) ($candidateSchool['name'] ?? ''));
                                if ($candidateId <= 0) {
                                    continue;
                                }
                                if ($candidateId === $activeGeofenceId) {
                                    continue;
                                }
                                if ($activeName !== '' && $candidateName === $activeName) {
                                    continue;
                                }
                                $availableSwitchSchools[] = $candidateSchool;
                            }
                        }
                        $endedCount = (int) ($endedTotals[$sectionId][$type] ?? 0);
                        ?>
                        <div class="rounded-lg border border-slate-200 p-3">
                            <div class="mb-2 flex items-center justify-between">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= htmlspecialchars($slotLabel) ?> Partner</p>
                                <p class="text-xs text-slate-500">Ended: <?= $endedCount ?></p>
                            </div>
                            <?php if ($activeRow): ?>
                                <?php $changeFormId = 'change-form-' . $sectionId . '-' . ($type === 'public_school' ? 'public' : 'private'); ?>
                                <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) $activeRow['name']) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars((string) ($activeRow['address'] ?? '')) ?></p>
                                <div class="mt-2 flex items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= deploymentStatusBadge((string) $activeRow['deployment_status']) ?>">Running</span>
                                    <span class="text-xs text-slate-500">Since <?= htmlspecialchars((string) date('M j, Y', strtotime((string) $activeRow['started_at']))) ?></span>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <form method="post">
                                        <input type="hidden" name="deployment_action" value="end_partner">
                                        <input type="hidden" name="deployment_id" value="<?= (int) $activeRow['id'] ?>">
                                        <button type="submit" class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                            <span aria-hidden="true">&#9209;</span> End
                                        </button>
                                    </form>
                                    <button
                                        type="button"
                                        class="toggle-change-form inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 <?= count($availableSwitchSchools) === 0 ? 'cursor-not-allowed opacity-60' : '' ?>"
                                        data-target-id="<?= htmlspecialchars($changeFormId) ?>"
                                        <?= count($availableSwitchSchools) === 0 ? 'disabled title="No other active school available for this type"' : '' ?>
                                    >
                                        <span aria-hidden="true">&#9998;</span> Change
                                    </button>
                                </div>
                                <form id="<?= htmlspecialchars($changeFormId) ?>" method="post" class="mt-2 hidden rounded-lg border border-slate-200 bg-slate-50 p-2">
                                        <input type="hidden" name="deployment_action" value="assign_partner">
                                        <input type="hidden" name="section_id" value="<?= $sectionId ?>">
                                        <input type="hidden" name="deployment_type" value="<?= htmlspecialchars($type) ?>">
                                        <select name="geofence_id" class="mb-2 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs text-slate-700" <?= count($availableSwitchSchools) === 0 ? 'disabled' : 'required' ?>>
                                            <option value=""><?= count($availableSwitchSchools) === 0 ? 'No other school available' : 'Select school...' ?></option>
                                            <?php foreach ($availableSwitchSchools as $school): ?>
                                                <option value="<?= (int) $school['id'] ?>"><?= htmlspecialchars((string) $school['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-600 <?= count($availableSwitchSchools) === 0 ? 'cursor-not-allowed opacity-50' : '' ?>" <?= count($availableSwitchSchools) === 0 ? 'disabled' : '' ?>>
                                            <span aria-hidden="true">&#10003;</span> Assign New Partner
                                        </button>
                                    </form>
                            <?php else: ?>
                                <p class="mb-2 text-xs text-slate-500">No active deployment.</p>
                                <form method="post">
                                    <input type="hidden" name="deployment_action" value="assign_partner">
                                    <input type="hidden" name="section_id" value="<?= $sectionId ?>">
                                    <input type="hidden" name="deployment_type" value="<?= htmlspecialchars($type) ?>">
                                    <select name="geofence_id" class="mb-2 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs text-slate-700" required>
                                        <option value="">No school selected</option>
                                        <?php foreach ($slotSchools as $school): ?>
                                            <option value="<?= (int) $school['id'] ?>"><?= htmlspecialchars((string) $school['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-600 <?= count($slotSchools) === 0 ? 'cursor-not-allowed opacity-50' : '' ?>" <?= count($slotSchools) === 0 ? 'disabled' : '' ?>>
                                        <span aria-hidden="true">&#65291;</span> Assign <?= htmlspecialchars($slotLabel) ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <p class="mt-2 text-xs text-slate-600">Rendered: <span class="font-semibold"><?= number_format($type === 'public_school' ? $publicHours : $privateHours, 1) ?> hrs</span></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$sections): ?>
            <article class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500 md:col-span-2 xl:col-span-4">
                No sections available for the current semester.
            </article>
        <?php endif; ?>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <h3 class="text-lg font-semibold text-slate-900">School Partners</h3>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <form method="get" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <?php if ($selectedSectionId > 0): ?>
                        <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                    <?php endif; ?>
                    <select name="type" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                        <option value="all" <?= $schoolTypeFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="public_school" <?= $schoolTypeFilter === 'public_school' ? 'selected' : '' ?>>Public</option>
                        <option value="private_school" <?= $schoolTypeFilter === 'private_school' ? 'selected' : '' ?>>Private</option>
                    </select>
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

        <?php if ($editSchool): ?>
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <h4 class="mb-3 text-sm font-semibold uppercase tracking-wide text-amber-700">Edit School</h4>
                <form method="post" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <input type="hidden" name="deployment_action" value="edit_school">
                    <input type="hidden" name="geofence_id" value="<?= (int) $editSchool['id'] ?>">
                    <input type="text" name="school_name" value="<?= htmlspecialchars((string) $editSchool['name']) ?>" class="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700" required>
                    <input type="text" name="address" value="<?= htmlspecialchars((string) ($editSchool['address'] ?? '')) ?>" class="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700" required>
                    <select name="school_type" class="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700">
                        <option value="public" <?= (string) $editSchool['school_type'] === 'public_school' ? 'selected' : '' ?>>Public</option>
                        <option value="private" <?= (string) $editSchool['school_type'] === 'private_school' ? 'selected' : '' ?>>Private</option>
                    </select>
                    <input type="number" name="latitude" value="<?= htmlspecialchars((string) $editSchool['latitude']) ?>" step="0.0000001" class="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700" required>
                    <input type="number" name="longitude" value="<?= htmlspecialchars((string) $editSchool['longitude']) ?>" step="0.0000001" class="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700" required>
                    <input type="number" name="radius_meters" value="<?= htmlspecialchars((string) $editSchool['radius_meters']) ?>" min="20" max="500" class="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700" required>
                    <input type="number" name="daily_cap_hours" value="<?= htmlspecialchars((string) ($editSchool['daily_cap_hours'] ?? '8')) ?>" step="0.1" min="1" max="24" class="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-slate-700" required>
                    <div class="flex gap-2">
                        <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600"><span aria-hidden="true">&#10003;</span> Save</button>
                        <a href="practicum_deployment.php" class="rounded-xl border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-100">Cancel</a>
                    </div>
                </form>
            </div>
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
                        <th class="border-b border-slate-200 px-3 py-3">Status</th>
                        <th class="border-b border-slate-200 px-3 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schools as $school): ?>
                        <?php
                        $schoolId = (int) $school['id'];
                        $type = (string) $school['school_type'];
                        $isActive = (int) ($school['is_active'] ?? 0) === 1;
                        $statusLabel = $isActive ? 'Active' : 'Inactive';
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="border-b border-slate-100 px-3 py-3">
                                <p class="font-semibold text-slate-900"><?= htmlspecialchars((string) $school['name']) ?></p>
                                <p class="text-xs text-slate-500">Lat: <?= htmlspecialchars((string) $school['latitude']) ?>, Lng: <?= htmlspecialchars((string) $school['longitude']) ?></p>
                            </td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= htmlspecialchars(deploymentTypeLabel($type)) ?></td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= htmlspecialchars((string) ($school['address'] ?? '')) ?></td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= number_format((float) $school['radius_meters'], 0) ?>m</td>
                            <td class="border-b border-slate-100 px-3 py-3"><?= number_format((float) ($school['daily_cap_hours'] ?? 8), 1) ?> hrs/day</td>
                            <td class="border-b border-slate-100 px-3 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' ?>">
                                    <?= htmlspecialchars($statusLabel) ?>
                                </span>
                            </td>
                            <td class="border-b border-slate-100 px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="?<?= htmlspecialchars(http_build_query(['edit_school_id' => $schoolId, 'type' => $schoolTypeFilter, 'q' => $searchTerm])) ?>" class="inline-flex items-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                        <span aria-hidden="true">&#9998;</span> Edit
                                    </a>
                                    <form method="post">
                                        <input type="hidden" name="deployment_action" value="toggle_school">
                                        <input type="hidden" name="geofence_id" value="<?= $schoolId ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                            <span aria-hidden="true"><?= $isActive ? "&#9208;" : "&#9654;" ?></span>
                                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$schools): ?>
                        <tr>
                            <td colspan="7" class="border-b border-slate-100 px-3 py-8 text-center text-sm text-slate-500">No schools found for this filter.</td>
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
                <input id="geoSchoolNameInput" type="text" name="school_name" placeholder="e.g. PHINMA Cagayan de Oro College" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
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
                <div class="flex gap-2">
                    <input id="geoAddressInput" type="text" name="address" placeholder="e.g. Cagayan de Oro City, Misamis Oriental" class="flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800" required>
                    <button type="button" id="geoSearchBtn" class="inline-flex items-center gap-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100"><span aria-hidden="true">&#128269;</span> Search</button>
                </div>
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

            <div id="geoMap" class="h-64 w-full overflow-hidden rounded-xl border border-slate-300"></div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" id="closeAddSchoolModalBtn" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600"><span aria-hidden="true">&#65291;</span> Add School</button>
            </div>
        </form>
    </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('addSchoolModal');
    const openBtn = document.getElementById('openAddSchoolModal');
    const closeBtn = document.getElementById('closeAddSchoolModalBtn');
    const closeX = document.getElementById('closeAddSchoolModalX');
    const addSchoolForm = document.getElementById('addSchoolForm');
    const addressInput = document.getElementById('geoAddressInput');
    const schoolNameInput = document.getElementById('geoSchoolNameInput');
    const searchBtn = document.getElementById('geoSearchBtn');
    const latInput = document.getElementById('geoLatInput');
    const lngInput = document.getElementById('geoLngInput');
    const radiusInput = document.getElementById('geoRadiusInput');
    const useDefaultBtn = document.getElementById('geoUseDefaultBtn');
    const changeButtons = document.querySelectorAll('.toggle-change-form');

    const defaultLat = 8.4795;
    const defaultLng = 124.6473;

    let map = null;
    let marker = null;
    let radiusCircle = null;

    function buildAutoSchoolName(addr) {
        const locality = addr?.suburb || addr?.village || addr?.town || addr?.city || addr?.municipality || addr?.county || 'Selected Area';
        return 'School near ' + locality;
    }

    async function reverseGeocodeAndAutofill(lat, lng) {
        try {
            const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&addressdetails=1&lat='
                + encodeURIComponent(String(lat)) + '&lon=' + encodeURIComponent(String(lng));
            const response = await fetch(url, {headers: {'Accept': 'application/json'}});
            const data = await response.json();
            if (data && data.display_name) {
                addressInput.value = data.display_name;
            }
            const autoName = buildAutoSchoolName(data?.address || {});
            if (!schoolNameInput.value.trim() || schoolNameInput.value.trim().toLowerCase().startsWith('school near ')) {
                schoolNameInput.value = autoName;
            }
        } catch (e) {
            // no-op fallback: manual input still works
        }
    }

    function syncRadiusCircle() {
        if (!radiusCircle || !marker) return;
        const radius = Math.max(20, Math.min(500, parseFloat(radiusInput.value) || 80));
        radiusCircle.setRadius(radius);
        const pos = marker.getLatLng();
        radiusCircle.setLatLng(pos);
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

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    closeX.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    latInput.addEventListener('change', syncMapFromInputs);
    lngInput.addEventListener('change', syncMapFromInputs);
    radiusInput.addEventListener('input', syncRadiusCircle);

    useDefaultBtn.addEventListener('click', function () {
        latInput.value = defaultLat.toFixed(7);
        lngInput.value = defaultLng.toFixed(7);
        syncMapFromInputs();
        reverseGeocodeAndAutofill(defaultLat, defaultLng);
    });

    searchBtn.addEventListener('click', async function () {
        const q = addressInput.value.trim();
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
                alert('Address not found. Move the map marker manually.');
            }
        } catch (err) {
            alert('Search failed. Move the map marker manually.');
        } finally {
            searchBtn.disabled = false;
            searchBtn.textContent = prevText;
        }
    });

    // UX: pressing Enter in address field should search location, not submit Add School form.
    addressInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchBtn.click();
        }
    });

    // UX: prevent Enter-key implicit submit in modal inputs.
    if (addSchoolForm) {
        addSchoolForm.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') {
                return;
            }
            const target = e.target;
            if (!target || target.tagName === 'TEXTAREA') {
                return;
            }
            if (target === addressInput) {
                e.preventDefault();
                searchBtn.click();
                return;
            }
            e.preventDefault();
        });
    }

    if (openBtn) {
        setTimeout(function () {
            reverseGeocodeAndAutofill(parseFloat(latInput.value) || defaultLat, parseFloat(lngInput.value) || defaultLng);
        }, 60);
    }

    changeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const targetId = button.getAttribute('data-target-id');
            if (!targetId) {
                return;
            }
            const form = document.getElementById(targetId);
            if (!form) {
                return;
            }
            form.classList.toggle('hidden');
        });
    });
});
</script>
<?php renderHeadTeacherPortalEnd(); ?>
