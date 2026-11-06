<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$coordinatorUserId = (int) ($user['id'] ?? 0);
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$context = $attendanceService->getCoordinatorSectionContext($coordinatorUserId) ?? [];
$flash = coordinatorGetFlash();
const COORDINATOR_MAX_STUDENTS = 50;

function coordinatorColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
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

function coordinatorIndexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
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

function coordinatorAssignedGeofenceId(PDO $pdo, int $coordinatorUserId): int
{
    if (coordinatorColumnExists($pdo, 'coordinator_deployments', 'coordinator_user_id')) {
        $stmt = $pdo->prepare(
            "SELECT geofence_id
             FROM coordinator_deployments
             WHERE coordinator_user_id = :coordinator_user_id
               AND deployment_status IN ('active', 'running')
             LIMIT 1"
        );
        $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);
        $geofenceId = (int) ($stmt->fetchColumn() ?: 0);
        if ($geofenceId > 0) {
            return $geofenceId;
        }
    }

    if (!coordinatorColumnExists($pdo, 'coordinator_schools', 'coordinator_user_id')) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT geofence_id
         FROM coordinator_schools
         WHERE coordinator_user_id = :coordinator_user_id
         LIMIT 1"
    );
    $stmt->execute(['coordinator_user_id' => $coordinatorUserId]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

function coordinatorEnsureStudentGeofenceAssignmentSchema(PDO $pdo): void
{
    if (!coordinatorColumnExists($pdo, 'student_geofence_assignments', 'coordinator_user_id')) {
        $pdo->exec(
            "ALTER TABLE student_geofence_assignments
             ADD COLUMN coordinator_user_id BIGINT UNSIGNED NULL AFTER geofence_id"
        );
    }

    if (!coordinatorColumnExists($pdo, 'student_geofence_assignments', 'semester_id')) {
        $pdo->exec(
            "ALTER TABLE student_geofence_assignments
             ADD COLUMN semester_id INT UNSIGNED NULL AFTER coordinator_user_id"
        );
    }

    if (!coordinatorIndexExists($pdo, 'student_geofence_assignments', 'uq_student_geofence_semester')) {
        if (coordinatorIndexExists($pdo, 'student_geofence_assignments', 'uq_student_geofence')) {
            $pdo->exec('ALTER TABLE student_geofence_assignments DROP INDEX uq_student_geofence');
        }

        $pdo->exec(
            'ALTER TABLE student_geofence_assignments
             ADD UNIQUE KEY uq_student_geofence_semester (student_user_id, geofence_id, semester_id)'
        );
    }

    $pdo->exec(
        "INSERT INTO student_geofence_assignments
            (student_user_id, geofence_id, coordinator_user_id, semester_id, assigned_at, assignment_status)
         SELECT
            current_sga.student_user_id,
            current_sga.geofence_id,
            current_sga.coordinator_user_id,
            sems.semester_id,
            current_sga.assigned_at,
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
           )"
    );
}

function coordinatorEnsureSemesterStudentsTable(PDO $pdo): void
{
    if (coordinatorColumnExists($pdo, 'semester_students', 'enrollment_status')) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS semester_students (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            semester_id INT UNSIGNED NOT NULL,
            student_user_id BIGINT UNSIGNED NOT NULL,
            enrollment_status ENUM('active', 'inactive', 'archived', 'dropped', 'completed') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_semester_students_student (semester_id, student_user_id),
            KEY idx_semester_students_student (student_user_id),
            KEY idx_semester_students_status (semester_id, enrollment_status)
        ) ENGINE=InnoDB"
    );
}

function coordinatorAssignedStudentCount(PDO $pdo, int $geofenceId, int $semesterId): int
{
    if ($geofenceId <= 0 || $semesterId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT sga.student_user_id)
         FROM student_geofence_assignments sga
         JOIN semester_students sems
           ON sems.student_user_id = sga.student_user_id
          AND sems.semester_id = :semester_id
          AND sems.enrollment_status = 'active'
         JOIN users u ON u.id = sga.student_user_id
         JOIN roles r ON r.id = u.role_id
         WHERE sga.geofence_id = :geofence_id
           AND sga.assignment_status = 'active'
           AND sga.semester_id = :assignment_semester_id
           AND r.role_code = 'student'
           AND u.account_status = 'active'"
    );
    $stmt->execute([
        'geofence_id' => $geofenceId,
        'semester_id' => $semesterId,
        'assignment_semester_id' => $semesterId,
    ]);

    return (int) $stmt->fetchColumn();
}

$activeSemesterId = (int) ($context['semester_id'] ?? 0);
$isActiveSemester = ((string) ($context['semester_status'] ?? '')) === 'active';
coordinatorEnsureStudentGeofenceAssignmentSchema($pdo);
coordinatorEnsureSemesterStudentsTable($pdo);
$assignedGeofenceId = (int) ($context['geofence_id'] ?? 0);
if ($assignedGeofenceId <= 0) {
    $assignedGeofenceId = coordinatorAssignedGeofenceId($pdo, $coordinatorUserId);
}
$coordinatorDeploymentStmt = $pdo->prepare(
    "SELECT geofence_id, deployment_order
     FROM coordinator_deployments
     WHERE coordinator_user_id = :coordinator_user_id
       AND deployment_status IN ('active', 'running')
     ORDER BY COALESCE(deployment_order, 99), id"
);
$coordinatorDeploymentStmt->execute(['coordinator_user_id' => $coordinatorUserId]);
$coordinatorDeployments = $coordinatorDeploymentStmt->fetchAll();
if ($coordinatorDeployments === []) {
    $coordinatorDeploymentStmt = $pdo->prepare("SELECT geofence_id, COALESCE(deployment_order, 1) AS deployment_order FROM coordinator_schools WHERE coordinator_user_id=:coordinator_user_id ORDER BY COALESCE(deployment_order, 99), id");
    $coordinatorDeploymentStmt->execute(['coordinator_user_id' => $coordinatorUserId]);
    $coordinatorDeployments = $coordinatorDeploymentStmt->fetchAll();
}
if ($coordinatorDeployments === [] && $assignedGeofenceId > 0) {
    $coordinatorDeployments = [['geofence_id' => $assignedGeofenceId, 'deployment_order' => 1]];
}
$assignedStudentCount = coordinatorAssignedStudentCount($pdo, $assignedGeofenceId, $activeSemesterId);
$remainingStudentSlots = max(0, COORDINATOR_MAX_STUDENTS - $assignedStudentCount);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'assign_selected_students') {
            if ($assignedGeofenceId <= 0) {
                throw new RuntimeException('No partnered school is assigned to your coordinator account yet. Please contact the Head Teacher.');
            }
            if ($activeSemesterId <= 0) {
                throw new RuntimeException('No active semester is available for assignment.');
            }

            $currentAssignedCount = coordinatorAssignedStudentCount($pdo, $assignedGeofenceId, $activeSemesterId);
            if ($currentAssignedCount >= COORDINATOR_MAX_STUDENTS) {
                throw new RuntimeException('You have already reached the maximum of 50 students.');
            }

            $studentIds = array_values(array_unique(array_map('intval', $_POST['student_ids'] ?? [])));
            $studentIds = array_values(array_filter($studentIds, static fn(int $id): bool => $id > 0));
            if ($studentIds === []) {
                throw new InvalidArgumentException('Select at least one student to assign.');
            }

            $availableCheckStmt = $pdo->prepare(
                "SELECT
                    u.id,
                    EXISTS(
                        SELECT 1
                        FROM student_geofence_assignments sga
                        WHERE sga.student_user_id = u.id
                          AND sga.assignment_status = 'active'
                          AND sga.semester_id = :has_assignment_semester_id
                    ) AS has_active_assignment,
                    EXISTS(
                        SELECT 1
                        FROM student_geofence_assignments sga
                        WHERE sga.student_user_id = u.id
                          AND sga.assignment_status = 'active'
                          AND sga.semester_id = :mismatch_semester_id
                          AND sga.geofence_id <> :coordinator_geofence_id
                    ) AS has_mismatch
                 FROM users u
                 JOIN roles r ON r.id = u.role_id
                 JOIN semester_students sems
                   ON sems.student_user_id = u.id
                  AND sems.semester_id = :semester_id
                  AND sems.enrollment_status = 'active'
                 WHERE u.id = :student_user_id
                   AND r.role_code = 'student'
                   AND u.account_status = 'active'
                 LIMIT 1"
            );

            $assignStmt = $pdo->prepare(
                "INSERT INTO student_geofence_assignments
                    (student_user_id, geofence_id, coordinator_user_id, semester_id, assigned_at, assignment_status)
                 VALUES
                    (:student_user_id, :geofence_id, :coordinator_user_id, :semester_id, NOW(), 'active')
                 ON DUPLICATE KEY UPDATE
                    coordinator_user_id = VALUES(coordinator_user_id),
                    semester_id = VALUES(semester_id),
                    assigned_at = VALUES(assigned_at),
                    assignment_status = 'active'"
            );

            $assignedCount = 0;
            $pdo->beginTransaction();
            foreach ($studentIds as $studentId) {
                if (($currentAssignedCount + $assignedCount) >= COORDINATOR_MAX_STUDENTS) {
                    throw new RuntimeException('Adding these students would exceed the maximum of 50 students.');
                }

                $availableCheckStmt->execute([
                    'coordinator_geofence_id' => $assignedGeofenceId,
                    'has_assignment_semester_id' => $activeSemesterId,
                    'mismatch_semester_id' => $activeSemesterId,
                    'student_user_id' => $studentId,
                    'semester_id' => $activeSemesterId,
                ]);
                $studentRow = $availableCheckStmt->fetch();
                $availableCheckStmt->closeCursor();

                if (!$studentRow) {
                    continue;
                }

                if ((int) ($studentRow['has_active_assignment'] ?? 0) === 1) {
                    continue;
                }

                if ((int) ($studentRow['has_mismatch'] ?? 0) === 1) {
                    throw new RuntimeException('You can only assign students within your assigned school.');
                }

                $assignStmt->execute([
                    'student_user_id' => $studentId,
                    'geofence_id' => $assignedGeofenceId,
                    'coordinator_user_id' => $coordinatorUserId,
                    'semester_id' => $activeSemesterId,
                ]);

                $studentDeploymentStmt = $pdo->prepare(
                    "INSERT INTO student_school_deployments
                        (student_user_id, coordinator_user_id, geofence_id, semester_id, deployment_order, deployment_status, started_at)
                     VALUES (:student_user_id, :coordinator_user_id, :geofence_id, :semester_id, :deployment_order,
                             CASE WHEN :deployment_order_status = 1 THEN 'active' ELSE 'locked' END,
                             CASE WHEN :deployment_order_started = 1 THEN NOW() ELSE NULL END)
                     ON DUPLICATE KEY UPDATE coordinator_user_id=VALUES(coordinator_user_id), deployment_status=IF(rendered_seconds >= required_seconds, 'completed', VALUES(deployment_status))"
                );
                foreach ($coordinatorDeployments as $deployment) {
                    $order = (int) ($deployment['deployment_order'] ?? 0);
                    $geo = (int) ($deployment['geofence_id'] ?? 0);
                    if ($order <= 0 || $geo <= 0) { continue; }
                    $studentDeploymentStmt->execute([
                        'student_user_id' => $studentId,
                        'coordinator_user_id' => $coordinatorUserId,
                        'geofence_id' => $geo,
                        'semester_id' => $activeSemesterId,
                        'deployment_order' => $order,
                        'deployment_order_status' => $order,
                        'deployment_order_started' => $order === 1 ? 1 : 0,
                    ]);
                }

                $assignedCount++;
            }
            $pdo->commit();

            if ($assignedCount === 0) {
                throw new RuntimeException('Selected students are no longer available for assignment.');
            }

            coordinatorSetFlash('success', $assignedCount . ' student(s) assigned to your school successfully.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        coordinatorSetFlash('error', $e->getMessage());
    }

    header('Location: ' . appUrl('coordinator/available_students.php'));
    exit;
}

$availableStudents = [];
if ($assignedGeofenceId > 0 && $isActiveSemester && $activeSemesterId > 0) {
        $sql = "SELECT
            u.id,
            u.school_id,
            u.email,
            up.first_name,
            up.middle_name,
            up.last_name,
            up.course,
            up.original_section,
            g.name AS assigned_school_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        JOIN semester_students sems
          ON sems.student_user_id = u.id
         AND sems.semester_id = :semester_id
         AND sems.enrollment_status = 'active'
        JOIN user_profiles up ON up.user_id = u.id
        LEFT JOIN student_geofence_assignments sga
          ON sga.student_user_id = u.id
         AND sga.assignment_status = 'active'
         AND sga.semester_id = :assigned_semester_id
        LEFT JOIN geofence_locations g
          ON g.id = sga.geofence_id
        WHERE r.role_code = 'student'
          AND u.account_status = 'active'
          AND NOT EXISTS (
                SELECT 1
                FROM student_geofence_assignments assigned
                WHERE assigned.student_user_id = u.id
                  AND assigned.assignment_status = 'active'
                  AND assigned.semester_id = :exists_semester_id
          )
        ORDER BY up.last_name ASC, up.first_name ASC";

$stmt = $pdo->prepare($sql);
    $stmt->execute([
        'semester_id' => $activeSemesterId,
        'assigned_semester_id' => $activeSemesterId,
        'exists_semester_id' => $activeSemesterId,
    ]);
    $availableStudents = $stmt->fetchAll();
}
$availableSections = [];
foreach ($availableStudents as $availableStudent) {
    $sectionValue = trim((string) ($availableStudent['original_section'] ?? ''));
    if ($sectionValue !== '') {
        $availableSections[$sectionValue] = $sectionValue;
    }
}

$dashboardLink = sprintf(
    '<a href="%s" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Student List</a>',
    htmlspecialchars(appUrl('coordinator/dashboard.php'), ENT_QUOTES, 'UTF-8')
);

renderCoordinatorPortalStart(
    $user,
    'available_students',
    'Add Students',
    $context,
    $dashboardLink,
    true
);
?>

<?php if ($flash): ?>
    <section data-reveal class="mb-6 rounded-[28px] border px-5 py-4 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </section>
<?php endif; ?>

<?php if ($assignedGeofenceId <= 0): ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <h1 class="text-3xl font-bold text-slate-900">Add Students</h1>
        <p class="mt-3 text-slate-500">No partnered school is assigned to your coordinator account yet.</p>
        <p class="mt-1 text-slate-500">Please contact the Head Teacher.</p>
    </section>
<?php elseif (!$isActiveSemester || $activeSemesterId <= 0): ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <h1 class="text-3xl font-bold text-slate-900">Add Students</h1>
        <p class="mt-3 text-slate-500">No active semester is available for assignment.</p>
    </section>
<?php else: ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Add Students</h1>
            <p class="mt-3 max-w-3xl text-base text-slate-500 sm:text-lg">
                View unassigned students imported for the active semester, then assign them to your school.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm text-slate-500">
            <span><?= coordinatorTopBadge((string) count($availableStudents) . ' Available', 'blue') ?></span>
            <span><?= coordinatorTopBadge($assignedStudentCount . '/' . COORDINATOR_MAX_STUDENTS . ' Added', $assignedStudentCount >= COORDINATOR_MAX_STUDENTS ? 'red' : 'slate') ?></span>
            <span><?= coordinatorTopBadge((string) ($context['school_name'] ?? 'No School'), 'green') ?></span>
        </div>
    </section>

    <section class="mb-6 grid gap-4 md:grid-cols-3">
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <p class="text-sm font-semibold text-slate-500">Assigned School</p>
            <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars((string) ($context['school_name'] ?? 'N/A')) ?></h2>
            <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars((string) ($context['school_address'] ?? '')) ?></p>
        </article>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <p class="text-sm font-semibold text-slate-500">School Type</p>
            <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars((string) (($context['deployment_type'] ?? '') === 'private_school' ? 'Private School' : 'Public School')) ?></h2>
            <p class="mt-2 text-sm text-slate-500">Assignment is restricted by geofence location.</p>
        </article>
        <article data-reveal class="rounded-[28px] border border-emerald-200 bg-emerald-50 p-6 shadow-panel">
            <p class="text-sm font-semibold text-emerald-800">Student Capacity</p>
            <p class="mt-3 text-3xl font-bold tracking-tight text-emerald-950"><?= $assignedStudentCount ?>/<?= COORDINATOR_MAX_STUDENTS ?></p>
            <p class="mt-2 text-sm text-emerald-900"><?= $remainingStudentSlots ?> slot(s) remaining. Selected students can only be assigned within your assigned school.</p>
        </article>
    </section>

    <section data-reveal class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
        <form method="post">
            <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h3 class="text-2xl font-bold tracking-tight text-slate-950">Unassigned Student Pool</h3>
                    <p class="mt-2 text-base text-slate-500">Search by name, school ID, course, section, or email.</p>
                    <p class="mt-1 text-sm font-semibold <?= $remainingStudentSlots > 0 ? 'text-emerald-700' : 'text-rose-700' ?>"><?= $assignedStudentCount ?>/<?= COORDINATOR_MAX_STUDENTS ?> added</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <input id="availableStudentSearch" type="search" placeholder="Search students..." class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                    <select id="availableStudentSection" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                        <option value="">All Sections</option>
                        <?php foreach ($availableSections as $sectionValue): ?>
                            <option value="<?= htmlspecialchars($sectionValue) ?>"><?= htmlspecialchars($sectionValue) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="rounded-2xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-400" <?= $remainingStudentSlots <= 0 ? 'disabled' : '' ?>>Assign Selected Students</button>
                </div>
            </div>

            <div class="flex items-center justify-between gap-4 px-5 py-4 text-sm text-slate-500">
                <label class="inline-flex items-center gap-3 font-medium text-slate-700">
                    <input id="selectAllStudents" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    Select All Visible
                </label>
                <div><span id="selectedStudentCount">0</span> selected</div>
            </div>

            <input type="hidden" name="action" value="assign_selected_students">

            <div class="scroll-slim overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead class="bg-slate-50/80">
                        <tr class="text-xs uppercase tracking-[0.22em] text-slate-500">
                            <th class="px-5 py-4 font-semibold">Select</th>
                            <th class="px-4 py-4 font-semibold">School ID</th>
                            <th class="px-4 py-4 font-semibold">Student Name</th>
                            <th class="px-4 py-4 font-semibold">Course</th>
                            <th class="px-4 py-4 font-semibold">Section</th>
                            <th class="px-4 py-4 font-semibold">Email</th>
                        </tr>
                    </thead>
                    <tbody id="availableStudentRows" class="divide-y divide-slate-100">
                        <?php foreach ($availableStudents as $student): ?>
                            <?php
                            $middleName = trim((string) ($student['middle_name'] ?? ''));
                            $fullName = trim((string) (($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ($middleName !== '' ? ' ' . $middleName : '')));
                            $searchValue = strtolower(trim((string) (
                                ($student['school_id'] ?? '') . ' ' .
                                $fullName . ' ' .
                                ($student['course'] ?? '') . ' ' .
                                ($student['original_section'] ?? '') . ' ' .
                                ($student['email'] ?? '')
                            )));
                            ?>
                            <tr class="available-student-row transition hover:bg-slate-50/80" data-search="<?= htmlspecialchars($searchValue) ?>" data-section="<?= htmlspecialchars((string) ($student['original_section'] ?? '')) ?>">
                                <td class="px-5 py-4">
                                    <input type="checkbox" name="student_ids[]" value="<?= (int) $student['id'] ?>" class="student-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                </td>
                                <td class="px-4 py-4 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($student['school_id'] ?? '')) ?></td>
                                <td class="px-4 py-4 text-sm text-slate-700"><?= htmlspecialchars($fullName) ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($student['course'] ?? '')) ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($student['original_section'] ?? 'Unassigned')) ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($student['email'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($availableStudents === []): ?>
                            <tr id="availableStudentsEmptyState">
                                <td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">No available students for the active semester. Import the new semester CSV first.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php
$pageScript = <<<'HTML'
<script>
(() => {
  const searchInput = document.getElementById('availableStudentSearch');
  const sectionSelect = document.getElementById('availableStudentSection');
  const selectAll = document.getElementById('selectAllStudents');
  const selectedCount = document.getElementById('selectedStudentCount');
  const rows = Array.from(document.querySelectorAll('.available-student-row'));
  const checkboxes = Array.from(document.querySelectorAll('.student-checkbox'));

  function updateSelectedCount() {
    const count = checkboxes.filter((checkbox) => checkbox.checked).length;
    if (selectedCount) {
      selectedCount.textContent = String(count);
    }
  }

  function visibleCheckboxes() {
    return rows
      .filter((row) => row.style.display !== 'none')
      .map((row) => row.querySelector('.student-checkbox'))
      .filter((checkbox) => checkbox instanceof HTMLInputElement);
  }

  function applySearch() {
    const query = (searchInput?.value || '').toLowerCase().trim();
    const selectedSection = (sectionSelect?.value || '').trim();
    rows.forEach((row) => {
      const haystack = row.getAttribute('data-search') || '';
      const rowSection = (row.getAttribute('data-section') || '').trim();
      const matchesSearch = query === '' || haystack.includes(query);
      const matchesSection = selectedSection === '' || rowSection === selectedSection;
      row.style.display = matchesSearch && matchesSection ? '' : 'none';
    });

    const visible = visibleCheckboxes();
    if (selectAll) {
      selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
    }
  }

  searchInput?.addEventListener('input', applySearch);
  sectionSelect?.addEventListener('change', applySearch);
  selectAll?.addEventListener('change', () => {
    visibleCheckboxes().forEach((checkbox) => {
      checkbox.checked = !!selectAll.checked;
    });
    updateSelectedCount();
  });

  checkboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      const visible = visibleCheckboxes();
      if (selectAll) {
        selectAll.checked = visible.length > 0 && visible.every((item) => item.checked);
      }
      updateSelectedCount();
    });
  });

  applySearch();
  updateSelectedCount();
})();
</script>
HTML;
renderCoordinatorPortalEnd($pageScript);
