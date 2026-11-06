<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AcademicControlService.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$service = null;

$errors = [];
$baseAcademicUrl = appUrl('head_teacher/academic_control.php');

function normalizePart(string $value): string
{
    return trim($value);
}

function parseBulkLine(string $line): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $parts = array_map('normalizePart', preg_split('/\s*[:;,]\s*/', $line) ?: []);
    if (count($parts) !== 4 && count($parts) !== 5) {
        return null;
    }

    if (count($parts) === 4) {
        [$email, $firstName, $lastName, $schoolId] = $parts;
        $middleName = '';
    } else {
        [$email, $firstName, $middleName, $lastName, $schoolId] = $parts;
    }

    if ($email === '' || $firstName === '' || $lastName === '' || $schoolId === '') {
        return null;
    }

    return [
        'email' => strtolower($email),
        'first_name' => $firstName,
        'middle_name' => $middleName === '-' ? '' : $middleName,
        'last_name' => $lastName,
        'school_id' => $schoolId,
    ];
}

function academicColumnExists(PDO $pdo, string $table, string $column): bool
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

function academicTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
         LIMIT 1'
    );
    $stmt->execute(['table_name' => $table]);

    return $stmt->fetchColumn() !== false;
}

function academicCoordinatorAssignmentSourceSql(PDO $pdo): ?string
{
    if (academicTableExists($pdo, 'coordinator_deployments')) {
        return "(SELECT coordinator_user_id, geofence_id
                  FROM coordinator_deployments
                  WHERE deployment_status = 'active')";
    }

    if (academicTableExists($pdo, 'coordinator_schools')) {
        return '(SELECT coordinator_user_id, geofence_id FROM coordinator_schools)';
    }

    return null;
}

$semesterStmt = $pdo->query(
    "SELECT sem.id, sem.semester_name, sem.semester_no, sem.semester_status, sy.year_label
     FROM semesters sem
     JOIN school_years sy ON sy.id = sem.school_year_id
     WHERE sem.semester_status IN ('active', 'planned')
     ORDER BY
        CASE sem.semester_status
            WHEN 'active' THEN 0
            WHEN 'planned' THEN 1
            ELSE 2
        END ASC,
        sem.id DESC
     LIMIT 1"
);
$currentSemester = $semesterStmt->fetch() ?: null;
$currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;
$activeSemesterStmt = $pdo->query("SELECT id FROM semesters WHERE semester_status = 'active' ORDER BY start_date DESC, id DESC LIMIT 1");
$activeSemesterId = (int) ($activeSemesterStmt->fetchColumn() ?: 0);
$hasActiveSemester = $activeSemesterId > 0;

$schoolsStmt = $pdo->query(
    "SELECT g.id, g.name
     FROM geofence_locations g
     JOIN partner_schools ps ON ps.geofence_id = g.id
     WHERE g.is_active = 1
       AND ps.school_status = 'active'
     ORDER BY g.name ASC"
);
$schools = $schoolsStmt->fetchAll() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if (in_array($action, ['import_students_csv', 'add_coordinators_bulk', 'add_coordinator'], true) && !$hasActiveSemester) {
            throw new InvalidArgumentException('Please activate a semester before importing students or adding coordinators.');
        }
        $service = new AcademicControlService($pdo);

        if ($action === 'import_students_csv') {
            if (!isset($_FILES['student_masterlist']) || !is_array($_FILES['student_masterlist'])) {
                throw new InvalidArgumentException('Please upload the official student masterlist CSV file.');
            }

            $file = $_FILES['student_masterlist'];
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('CSV upload failed. Please try again.');
            }

            $tmpPath = (string) ($file['tmp_name'] ?? '');
            $originalName = strtolower((string) ($file['name'] ?? ''));
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                throw new RuntimeException('Uploaded CSV file could not be verified.');
            }
            if (!str_ends_with($originalName, '.csv')) {
                throw new InvalidArgumentException('Only CSV files are accepted for official masterlist import.');
            }

            $summary = $service->importOfficialStudentMasterlist($tmpPath, $activeSemesterId);
            $newStudentCount = (int) ($summary['new_students_created'] ?? 0)
                + (int) ($summary['reactivated_existing_students'] ?? 0);
            $hasImportedStudents = $newStudentCount > 0;
            $importLines = $hasImportedStudents
                ? [
                    'Import Complete.',
                    'Total Rows Processed: ' . (int) $summary['total_rows_processed'],
                ]
                : [];
            $optionalImportTotals = [
                'Existing Reactivated' => (int) ($summary['reactivated_existing_students'] ?? 0),
                'New Students Created' => (int) ($summary['new_students_created'] ?? 0),
                'Already Enrolled Skipped' => (int) $summary['duplicates_skipped'],
                'Errors' => (int) $summary['errors'],
            ];
            foreach ($optionalImportTotals as $label => $value) {
                if ($value > 0) {
                    $importLines[] = $label . ': ' . $value;
                }
            }
            if (!empty($summary['error_details'])) {
                $importLines[] = 'First error: ' . (string) $summary['error_details'][0];
            }
            if ($hasImportedStudents) {
                $importLines[] = 'Credential emails sent: ' . (int) $summary['email_sent'];
                if ((int) $summary['email_failed'] > 0) {
                    $importLines[] = 'Accounts created, email delivery pending: ' . (int) $summary['email_failed'];
                }
            }

            if (!$hasImportedStudents && $importLines === []) {
                $importLines[] = 'No new students were imported.';
            }
            htSetSweetAlert($hasImportedStudents ? 'success' : 'warning', implode("\n", $importLines));
            header('Location: ' . $baseAcademicUrl);
            exit;
        }

        if ($action === 'add_coordinator') {
            $coordinatorId = trim((string) ($_POST['coordinator_id'] ?? ''));
            $result = $service->createCoordinatorAccountWithProfile([
                'school_id' => $coordinatorId,
                'email' => trim((string) ($_POST['coordinator_email'] ?? '')),
                'first_name' => trim((string) ($_POST['coordinator_first_name'] ?? '')),
                'middle_name' => trim((string) ($_POST['coordinator_middle_name'] ?? '')),
                'last_name' => trim((string) ($_POST['coordinator_last_name'] ?? '')),
            ]);
            $status = (string) ($result['status'] ?? 'created');
            htSetSweetAlert($status === 'existing_active' ? 'warning' : 'success', implode("\n", array_filter([
                $status === 'existing_active' ? 'Coordinator already exists in this semester.' : 'Coordinator Created',
                'Coordinator ID: ' . $coordinatorId,
                $status === 'existing_active' ? '' : 'Temporary Password: ' . $coordinatorId,
                $status === 'existing_active' ? '' : 'Password Change Required: Yes',
                $status === 'existing_active' ? '' : (!empty($result['credentials_email_sent'])
                    ? 'Credential email sent.'
                    : 'Account created, but credential email delivery is pending.'),
            ])));
            header('Location: ' . $baseAcademicUrl);
            exit;
        }

        if ($action === 'add_coordinators_bulk') {
            $bulk = (string) ($_POST['bulk_coordinators'] ?? '');

            $lines = preg_split('/\r\n|\r|\n/', $bulk) ?: [];
            $created = 0;
            $reactivatedCount = 0;
            $existingCount = 0;
            $emailSentCount = 0;
            $emailFailedCount = 0;

            foreach ($lines as $line) {
                $parsed = parseBulkLine($line);
                if ($parsed === null) {
                    continue;
                }

                if (!preg_match('/^[^@\s]+@phinmaed\.com$/', $parsed['email'])) {
                    throw new InvalidArgumentException('Invalid coordinator email: ' . $parsed['email'] . '. Use an email ending in @phinmaed.com.');
                }

                $existing = $service->findUserByEmailOrSchoolId($parsed['email'], $parsed['school_id']);
                if ($existing !== null) {
                    if ((string) $existing['role_code'] !== 'coordinator') {
                        throw new RuntimeException('Existing user ' . $parsed['email'] . ' is not a coordinator.');
                    }
                }

                $result = $service->createCoordinatorAccountWithProfile([
                    'school_id' => $parsed['school_id'],
                    'email' => $parsed['email'],
                    'first_name' => $parsed['first_name'],
                    'middle_name' => $parsed['middle_name'],
                    'last_name' => $parsed['last_name'],
                ]);
                $status = (string) ($result['status'] ?? 'created');
                if ($status === 'reactivated') {
                    $reactivatedCount++;
                } elseif ($status === 'existing_active') {
                    $existingCount++;
                } else {
                    $created++;
                }

                if (!empty($result['credentials_email_sent'])) {
                    $emailSentCount++;
                } elseif (in_array($status, ['created', 'reactivated'], true)) {
                    $emailFailedCount++;
                }
            }

            if ($created === 0 && $reactivatedCount === 0 && $existingCount === 0) {
                throw new InvalidArgumentException('No valid coordinator rows detected.');
            }

            if ($reactivatedCount > 0 && $created === 0 && $existingCount === 0 && $emailFailedCount === 0) {
                $message = $reactivatedCount === 1
                    ? 'Coordinator account reactivated successfully.'
                    : "Coordinator bulk add complete. Reactivated: {$reactivatedCount}.";
            } elseif ($created > 0 && $reactivatedCount === 0 && $existingCount === 0 && $emailFailedCount === 0) {
                $message = $created === 1
                    ? 'Coordinator account created successfully.'
                    : "Coordinator bulk add complete. Created: {$created}.";
            } elseif ($created > 0 || $reactivatedCount > 0) {
                $message = implode("\n", [
                    'Coordinator bulk add complete.',
                    "Created: {$created}",
                    "Reactivated: {$reactivatedCount}",
                    "Existing kept: {$existingCount}",
                    "Credential emails sent: {$emailSentCount}",
                    "Accounts created, email delivery pending: {$emailFailedCount}",
                ]);
            } else {
                $message = implode("\n", [
                    'No new coordinator accounts were added.',
                    "Existing coordinator accounts kept: {$existingCount}",
                ]);
            }

            $alertType = $created === 0 && $reactivatedCount === 0 ? 'warning' : 'success';
            htSetSweetAlert($alertType, $message);
            header('Location: ' . $baseAcademicUrl);
            exit;
        }

        if ($action === 'edit_student') {
            $studentId = (int) ($_POST['student_user_id'] ?? 0);
            $schoolId = trim((string) ($_POST['school_id'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            if ($studentId <= 0 || $schoolId === '' || $email === '' || $firstName === '' || $lastName === '') {
                throw new InvalidArgumentException('Incomplete student edit data.');
            }

            $pdo->beginTransaction();

            $updUser = $pdo->prepare(
                'UPDATE users
                 SET school_id = :school_id, email = :email
                 WHERE id = :id'
            );
            $updUser->execute([
                'school_id' => $schoolId,
                'email' => $email,
                'id' => $studentId,
            ]);

            $updProfile = $pdo->prepare(
                'UPDATE user_profiles
                 SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name
                 WHERE user_id = :user_id'
            );
            $updProfile->execute([
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'user_id' => $studentId,
            ]);

            $pdo->commit();

            htSetFlash('success', 'Student updated successfully.');
            header('Location: ' . $baseAcademicUrl);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

$studentPage = max(1, (int) ($_GET['student_page'] ?? 1));
$studentPageSize = 10;
$studentSql = "SELECT
        u.id AS user_id,
        u.school_id,
        u.email,
        u.account_status,
        up.first_name,
        up.middle_name,
        up.last_name,
        up.course,
        up.campus,
        COALESCE(NULLIF(up.original_section, ''), sec.section_name) AS section_name,
        g.name AS assigned_school_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    JOIN semester_students sems
      ON sems.student_user_id = u.id
     AND sems.semester_id = :semester_id
     AND sems.enrollment_status = 'active'
    JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN section_students ss
      ON ss.student_user_id = u.id
     AND ss.semester_id = sems.semester_id
     AND ss.enrollment_status = 'active'
    LEFT JOIN sections sec ON sec.id = ss.section_id
    LEFT JOIN student_geofence_assignments sga
      ON sga.student_user_id = u.id
     AND sga.assignment_status = 'active'
     AND sga.semester_id = sems.semester_id
    LEFT JOIN geofence_locations g ON g.id = sga.geofence_id
    WHERE r.role_code = 'student'";
$studentParams = ['semester_id' => $currentSemesterId];
$studentCountStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$studentSql}) counted_students");
$studentCountStmt->execute($studentParams);
$studentTotal = (int) $studentCountStmt->fetchColumn();
$studentTotalPages = max(1, (int) ceil($studentTotal / $studentPageSize));
if ($studentPage > $studentTotalPages) {
    $studentPage = $studentTotalPages;
}
$studentOffset = ($studentPage - 1) * $studentPageSize;
$studentSql .= '
    ORDER BY up.last_name ASC, up.first_name ASC
    LIMIT :student_limit OFFSET :student_offset';
$studentsStmt = $pdo->prepare($studentSql);
$studentsStmt->bindValue(':semester_id', $currentSemesterId, PDO::PARAM_INT);
$studentsStmt->bindValue(':student_limit', $studentPageSize, PDO::PARAM_INT);
$studentsStmt->bindValue(':student_offset', $studentOffset, PDO::PARAM_INT);
$studentsStmt->execute();
$students = $studentsStmt->fetchAll() ?: [];

$assignmentSql = academicCoordinatorAssignmentSourceSql($pdo);
$coordinatorsSql = "SELECT
        u.id AS user_id,
        u.school_id AS coordinator_id,
        u.email,
        u.account_status,
        up.first_name,
        up.last_name,
        ca.geofence_id,
        g.name AS school_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    JOIN user_profiles up ON up.user_id = u.id";
if ($assignmentSql !== null) {
    $coordinatorsSql .= "
    LEFT JOIN {$assignmentSql} ca
      ON ca.coordinator_user_id = u.id
    LEFT JOIN geofence_locations g ON g.id = ca.geofence_id";
} else {
    $coordinatorsSql .= '
    LEFT JOIN geofence_locations g ON 1 = 0';
}
$coordinatorsSql .= "
    WHERE r.role_code = 'coordinator'
      AND u.account_status = 'active'
    ORDER BY up.last_name ASC, up.first_name ASC";
$coordinatorsStmt = $pdo->prepare($coordinatorsSql);
$coordinatorsStmt->execute();
$coordinators = $coordinatorsStmt->fetchAll() ?: [];
$uniqueCoordinatorIds = array_unique(array_filter(array_map(
    static fn (array $coordinator): int => (int) ($coordinator['user_id'] ?? 0),
    $coordinators
)));

$kpi = [
    'total_students' => $studentTotal,
    // A coordinator can be assigned to more than one school. Count accounts,
    // not assignment rows.
    'total_coordinators' => count($uniqueCoordinatorIds),
    'total_partnered_schools' => count($schools),
];

$sweetAlert = htGetSweetAlert();
renderHeadTeacherPortalStart($user ?? [], 'academic');
?>
<section class="mt-6 space-y-6">
    <?php if ($errors !== []): ?>
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Academic Control</h1>
            <p class="mt-2 text-sm text-slate-600">Manage the active semester, partnered schools, coordinators, and the global student roster in one school-based workspace.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="button" data-modal-open="bulkStudentsModal" data-requires-active="true" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 <?= $hasActiveSemester ? '' : 'opacity-55' ?>">Import CSV</button>
            <button type="button" data-modal-open="bulkCoordinatorsModal" data-requires-active="true" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:bg-slate-50 <?= $hasActiveSemester ? '' : 'opacity-55' ?>">Add Coordinators</button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Total Students</p>
            <p class="mt-2 text-4xl font-bold text-slate-900"><?= (int) $kpi['total_students'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Total Coordinators</p>
            <p class="mt-2 text-4xl font-bold text-slate-900"><?= (int) $kpi['total_coordinators'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Partnered Schools</p>
            <p class="mt-2 text-4xl font-bold text-slate-900"><?= (int) $kpi['total_partnered_schools'] ?></p>
        </article>
    </div>

    <div class="grid gap-4 xl:grid-cols-12">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-4">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-2xl font-semibold text-slate-900">Partnered Schools</h3>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= count($schools) ?> schools</span>
            </div>

            <div class="space-y-3">
                <?php foreach ($schools as $school): ?>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) $school['name']) ?></p>
                        <p class="mt-1 text-sm text-slate-500">Coordinator deployment and student geofence assignment are managed per school.</p>
                    </div>
                <?php endforeach; ?>
                <?php if ($schools === []): ?>
                    <div class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No partnered schools available.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-8">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-2xl font-semibold text-slate-900">Coordinators</h3>
                    <p class="mt-1 text-sm text-slate-500">Coordinators are assigned directly to partnered schools.</p>
                </div>
            </div>

            <input id="coordinatorSearch" type="search" placeholder="Search coordinator..." class="mb-4 w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />

            <div id="coordinatorList" class="grid gap-3 md:grid-cols-2">
                <?php foreach ($coordinators as $coord): ?>
                    <div class="coordinator-item rounded-xl border border-slate-200 p-4" data-name="<?= htmlspecialchars(strtolower((string) ($coord['coordinator_id'] . ' ' . $coord['first_name'] . ' ' . $coord['last_name'] . ' ' . $coord['email'] . ' ' . ($coord['school_name'] ?? '')))) ?>">
                        <div class="flex items-center justify-between">
                            <p class="text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) ($coord['first_name'] . ' ' . $coord['last_name'])) ?></p>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold <?= ((string) $coord['account_status']) === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
                                <?= htmlspecialchars(strtoupper((string) $coord['account_status'])) ?>
                            </span>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-700">Coordinator ID: <?= htmlspecialchars((string) ($coord['coordinator_id'] ?? 'N/A')) ?></p>
                        <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) $coord['email']) ?></p>
                        <p class="mt-2 text-sm text-slate-600"><?= !empty($coord['geofence_id']) ? 'Assigned School: ' . htmlspecialchars((string) ($coord['school_name'] ?? '')) : 'No school assigned' ?></p>
                    </div>
                <?php endforeach; ?>
                <?php if ($coordinators === []): ?>
                    <div class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500 md:col-span-2">No coordinators yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-2xl font-semibold text-slate-900">Students</h3>
                <p class="mt-1 text-sm text-slate-500">Student records are managed globally for the semester and linked to partnered schools through geofence assignments.</p>
            </div>
            <div class="flex w-full gap-2 sm:w-auto">
                <input id="studentSearch" type="search" placeholder="Search by student ID, name, email, or school..." class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200 sm:w-96" />
                <button id="studentSearchBtn" type="button" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700">Search</button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="min-w-[1100px] w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Student ID</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Section</th>
                        <th class="px-4 py-3">Assigned School</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody id="studentRows">
                    <?php foreach ($students as $student): ?>
                        <?php
                        $fullName = trim((string) (($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . (!empty($student['middle_name']) ? ' ' . $student['middle_name'] : '')));
                        $assignedSchool = (string) ($student['assigned_school_name'] ?? 'Not assigned');
                        $sectionName = (string) ($student['section_name'] ?? 'Not assigned');
                        $searchValue = strtolower((string) (($student['school_id'] ?? '') . ' ' . $fullName . ' ' . ($student['email'] ?? '') . ' ' . $sectionName . ' ' . $assignedSchool));
                        ?>
                        <tr class="student-row border-t border-slate-100 hover:bg-slate-50" data-search="<?= htmlspecialchars($searchValue) ?>">
                            <td class="px-4 py-3 font-semibold text-slate-800"><?= htmlspecialchars((string) ($student['school_id'] ?? 'N/A')) ?></td>
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-800"><?= htmlspecialchars($fullName) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars((string) ($student['course'] ?? '')) ?><?= !empty($student['campus']) ? ' | ' . htmlspecialchars((string) $student['campus']) : '' ?></p>
                            </td>
                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string) ($student['email'] ?? '')) ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($sectionName) ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($assignedSchool) ?></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= ((string) ($student['account_status'] ?? '')) === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
                                    <?= htmlspecialchars(strtoupper((string) ($student['account_status'] ?? 'unknown'))) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        title="Preview"
                                        aria-label="Preview"
                                        class="rounded-md border border-slate-300 px-2 py-1 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                        data-modal-open="previewStudentModal"
                                        data-student-id="<?= (int) ($student['user_id'] ?? 0) ?>"
                                        data-student-school-id="<?= htmlspecialchars((string) ($student['school_id'] ?? '')) ?>"
                                        data-student-email="<?= htmlspecialchars((string) ($student['email'] ?? '')) ?>"
                                        data-student-first="<?= htmlspecialchars((string) ($student['first_name'] ?? '')) ?>"
                                        data-student-middle="<?= htmlspecialchars((string) ($student['middle_name'] ?? '')) ?>"
                                        data-student-last="<?= htmlspecialchars((string) ($student['last_name'] ?? '')) ?>"
                                    >View</button>
                                    <button
                                        type="button"
                                        title="Edit"
                                        aria-label="Edit"
                                        class="rounded-md border border-blue-300 px-2 py-1 text-sm font-semibold text-blue-700 hover:bg-blue-50"
                                        data-modal-open="editStudentModal"
                                        data-student-id="<?= (int) ($student['user_id'] ?? 0) ?>"
                                        data-student-school-id="<?= htmlspecialchars((string) ($student['school_id'] ?? '')) ?>"
                                        data-student-email="<?= htmlspecialchars((string) ($student['email'] ?? '')) ?>"
                                        data-student-first="<?= htmlspecialchars((string) ($student['first_name'] ?? '')) ?>"
                                        data-student-middle="<?= htmlspecialchars((string) ($student['middle_name'] ?? '')) ?>"
                                        data-student-last="<?= htmlspecialchars((string) ($student['last_name'] ?? '')) ?>"
                                    >Edit</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($students === []): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500">No students found for the current semester.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="flex flex-col gap-3 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500">
                Showing <?= $studentTotal === 0 ? 0 : ($studentOffset + 1) ?> to <?= min($studentOffset + $studentPageSize, $studentTotal) ?> of <?= $studentTotal ?> student(s).
            </p>
            <div class="flex gap-2">
                <?php
                $previousStudentQuery = array_merge($_GET, ['student_page' => max(1, $studentPage - 1)]);
                $nextStudentQuery = array_merge($_GET, ['student_page' => min($studentTotalPages, $studentPage + 1)]);
                ?>
                <a href="?<?= htmlspecialchars(http_build_query($previousStudentQuery), ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 <?= $studentPage <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>">Previous</a>
                <a href="?<?= htmlspecialchars(http_build_query($nextStudentQuery), ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 <?= $studentPage >= $studentTotalPages ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>">Next</a>
            </div>
        </div>
    </div>
</section>

<div id="bulkStudentsModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/45" data-modal-close="bulkStudentsModal"></div>
    <div class="relative w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold text-slate-900">Import Students</h3>
            <button type="button" data-modal-close="bulkStudentsModal" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">X</button>
        </div>
        <form method="post" enctype="multipart/form-data" class="space-y-4 px-6 py-5">
            <input type="hidden" name="action" value="import_students_csv">
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <p class="font-semibold">Official PHINMA Masterlist Format</p>
                <p class="mt-1 text-xs font-mono">SESSION NAME,CAMPUS,STUDENT ID,STUDENT NAME,GENDER,COLLEGE,COURSE,SUBJECT,SECTION,EMAIL</p>
                <p class="mt-2 text-xs">The import remains semester-based. Any source-classification values from the CSV are stored only as reference data and no longer drive Academic Control grouping.</p>
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Student Masterlist CSV</label>
                <input name="student_masterlist" type="file" accept=".csv,text/csv" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" data-modal-close="bulkStudentsModal" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600">Import CSV</button>
            </div>
        </form>
    </div>
</div>

<div id="bulkCoordinatorsModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/45" data-modal-close="bulkCoordinatorsModal"></div>
    <div class="relative w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold text-slate-900">Add New Coordinator</h3>
            <button type="button" data-modal-close="bulkCoordinatorsModal" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">X</button>
        </div>
        <form id="coordinatorForm" method="post" class="space-y-4 px-6 py-5">
            <input type="hidden" name="action" value="add_coordinator">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="mb-1 block text-sm font-semibold text-slate-700">Coordinator ID</label><input name="coordinator_id" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm" /></div>
                <div><label class="mb-1 block text-sm font-semibold text-slate-700">Email Address</label><input name="coordinator_email" type="email" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm" /></div>
                <div><label class="mb-1 block text-sm font-semibold text-slate-700">First Name</label><input name="coordinator_first_name" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm" /></div>
                <div><label class="mb-1 block text-sm font-semibold text-slate-700">Middle Name</label><input name="coordinator_middle_name" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm" /></div>
                <div class="sm:col-span-2"><label class="mb-1 block text-sm font-semibold text-slate-700">Last Name</label><input name="coordinator_last_name" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm" /></div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" data-modal-close="bulkCoordinatorsModal" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600">Create Coordinator</button>
            </div>
        </form>
    </div>
</div>

<div id="previewStudentModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/45" data-modal-close="previewStudentModal"></div>
    <div class="relative w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold text-slate-900">Student Preview</h3>
            <button type="button" data-modal-close="previewStudentModal" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">X</button>
        </div>
        <div class="space-y-3 px-6 py-5 text-sm">
            <div><span class="font-semibold text-slate-700">Student ID:</span> <span id="previewStudentId" class="text-slate-900"></span></div>
            <div><span class="font-semibold text-slate-700">Full Name:</span> <span id="previewStudentName" class="text-slate-900"></span></div>
            <div><span class="font-semibold text-slate-700">Email:</span> <span id="previewStudentEmail" class="text-slate-900"></span></div>
        </div>
        <div class="border-t border-slate-200 px-6 py-4 text-right">
            <button type="button" data-modal-close="previewStudentModal" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Close</button>
        </div>
    </div>
</div>

<div id="editStudentModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/45" data-modal-close="editStudentModal"></div>
    <div class="relative w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold text-slate-900">Edit Student</h3>
            <button type="button" data-modal-close="editStudentModal" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">X</button>
        </div>
        <form method="post" class="space-y-4 px-6 py-5">
            <input type="hidden" name="action" value="edit_student">
            <input type="hidden" name="student_user_id" id="editStudentUserId" value="">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">Student ID</label>
                    <input id="editSchoolId" name="school_id" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">Email</label>
                    <input id="editEmail" name="email" type="email" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">First Name</label>
                    <input id="editFirstName" name="first_name" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">Middle Name</label>
                    <input id="editMiddleName" name="middle_name" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-semibold text-slate-700">Last Name</label>
                    <input id="editLastName" name="last_name" required class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" data-modal-close="editStudentModal" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php
if ($sweetAlert):
    $sweetAlertHtml = '<div style="text-align:left;line-height:1.7">'
        . implode('<br>', array_map(
            static fn(string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'),
            preg_split('/\r\n|\r|\n/', (string) ($sweetAlert['message'] ?? '')) ?: []
        ))
        . '</div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    $sweetAlertType = (string) ($sweetAlert['type'] ?? 'info');
    $sweetAlertMessage = (string) ($sweetAlert['message'] ?? '');
    $sweetAlertTitle = str_contains(strtolower($sweetAlertMessage), 'coordinator')
        ? ($sweetAlertType === 'warning' ? 'No New Coordinator Added' : 'Coordinator Account')
        : ($sweetAlertType === 'warning' ? 'No New Students Imported' : 'CSV Import Result');
    echo '<script>Swal.fire({icon:' . json_encode($sweetAlertType) . ',title:' . json_encode($sweetAlertTitle) . ',html:' . json_encode($sweetAlertHtml) . ',confirmButtonColor:"#047857"});</script>';
endif;
$extraScript = '<script>window.headTeacherHasActiveSemester = ' . json_encode($hasActiveSemester) . ';</script>' . <<<'HTML'
<script>
(() => {
    const hasActiveSemester = window.headTeacherHasActiveSemester === true;
    const openers = document.querySelectorAll('[data-modal-open]');
    const closers = document.querySelectorAll('[data-modal-close]');

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    openers.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (btn.dataset.requiresActive === 'true' && !hasActiveSemester) {
                Swal.fire({icon:'warning', title:'No Active Semester', text:'Please activate a semester before importing students or adding coordinators.', confirmButtonColor:'#047857'});
                return;
            }
            openModal(btn.getAttribute('data-modal-open'));
        });
    });

    closers.forEach((btn) => {
        btn.addEventListener('click', () => closeModal(btn.getAttribute('data-modal-close')));
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.fixed.inset-0.z-50').forEach((modal) => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        });
    });

    const coordinatorSearch = document.getElementById('coordinatorSearch');
    const coordinatorItems = document.querySelectorAll('.coordinator-item');
    coordinatorSearch?.addEventListener('input', () => {
        const q = coordinatorSearch.value.toLowerCase().trim();
        coordinatorItems.forEach((item) => {
            const hay = item.getAttribute('data-name') || '';
            item.style.display = hay.includes(q) ? '' : 'none';
        });
    });

    const bulkCoordinatorsForm = document.getElementById('bulkCoordinatorsForm');
    const bulkCoordinatorsInput = document.getElementById('bulkCoordinatorsInput');
    const bulkCoordinatorsError = document.getElementById('bulkCoordinatorsError');
    bulkCoordinatorsForm?.addEventListener('submit', (event) => {
        const invalidEmail = (bulkCoordinatorsInput?.value || '')
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean)
            .map((line) => line.split(/\s*[:;,]\s*/)[0]?.trim().toLowerCase() || '')
            .find((email) => !/^[^@\s]+@phinmaed\.com$/.test(email));

        if (!invalidEmail) {
            bulkCoordinatorsError?.classList.add('hidden');
            return;
        }

        event.preventDefault();
        if (bulkCoordinatorsError) {
            bulkCoordinatorsError.textContent = `Invalid coordinator email: ${invalidEmail}. Use an email ending in @phinmaed.com.`;
            bulkCoordinatorsError.classList.remove('hidden');
        }
        bulkCoordinatorsInput?.focus();
    });

    const studentSearch = document.getElementById('studentSearch');
    const studentSearchBtn = document.getElementById('studentSearchBtn');
    const studentRows = document.querySelectorAll('.student-row');

    function applyStudentSearch() {
        const q = (studentSearch?.value || '').toLowerCase().trim();
        studentRows.forEach((row) => {
            const hay = row.getAttribute('data-search') || '';
            row.style.display = q === '' || hay.includes(q) ? '' : 'none';
        });
    }

    studentSearch?.addEventListener('input', applyStudentSearch);
    studentSearch?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyStudentSearch();
        }
    });
    studentSearchBtn?.addEventListener('click', applyStudentSearch);

    const previewStudentId = document.getElementById('previewStudentId');
    const previewStudentName = document.getElementById('previewStudentName');
    const previewStudentEmail = document.getElementById('previewStudentEmail');
    const editStudentUserId = document.getElementById('editStudentUserId');
    const editSchoolId = document.getElementById('editSchoolId');
    const editEmail = document.getElementById('editEmail');
    const editFirstName = document.getElementById('editFirstName');
    const editMiddleName = document.getElementById('editMiddleName');
    const editLastName = document.getElementById('editLastName');

    document.querySelectorAll('[data-modal-open="previewStudentModal"], [data-modal-open="editStudentModal"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const schoolId = btn.getAttribute('data-student-school-id') || '';
            const email = btn.getAttribute('data-student-email') || '';
            const first = btn.getAttribute('data-student-first') || '';
            const middle = btn.getAttribute('data-student-middle') || '';
            const last = btn.getAttribute('data-student-last') || '';
            const userId = btn.getAttribute('data-student-id') || '';
            const fullName = [last, ', ', first, middle ? ' ' + middle : ''].join('').trim();

            if (previewStudentId) previewStudentId.textContent = schoolId;
            if (previewStudentName) previewStudentName.textContent = fullName;
            if (previewStudentEmail) previewStudentEmail.textContent = email;

            if (editStudentUserId) editStudentUserId.value = userId;
            if (editSchoolId) editSchoolId.value = schoolId;
            if (editEmail) editEmail.value = email;
            if (editFirstName) editFirstName.value = first;
            if (editMiddleName) editMiddleName.value = middle;
            if (editLastName) editLastName.value = last;
        });
    });
})();
</script>
HTML;
renderHeadTeacherPortalEnd($extraScript);
?>
