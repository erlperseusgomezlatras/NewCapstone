<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AcademicControlService.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$service = new AcademicControlService($pdo);

$errors = [];
$success = [];
$baseAcademicUrl = '/practicum_system/head_teacher/academic_control.php';

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

function buildSectionCode(string $sectionName): string
{
    if (preg_match('/section\s*([a-z0-9]+)/i', $sectionName, $m)) {
        return 'SEC-' . strtoupper($m[1]);
    }

    $sanitized = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $sectionName) ?? '');
    if ($sanitized === '') {
        $sanitized = 'AUTO' . date('His');
    }

    return 'SEC-' . substr($sanitized, 0, 12);
}

$semesterStmt = $pdo->query(
    "SELECT sem.id, sem.semester_name, sem.semester_no, sem.semester_status, sy.year_label
     FROM semesters sem
     JOIN school_years sy ON sy.id = sem.school_year_id
     ORDER BY
        CASE sem.semester_status
            WHEN 'active' THEN 3
            WHEN 'planned' THEN 2
            WHEN 'closed' THEN 1
            ELSE 0
        END DESC,
        sy.start_date DESC,
        sem.semester_no DESC
     LIMIT 1"
);
$currentSemester = $semesterStmt->fetch() ?: null;
$currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;
$selectedSectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($currentSemesterId <= 0 && !in_array($action, ['add_coordinators_bulk'], true)) {
            throw new RuntimeException('No semester found. Create semester first.');
        }

        if ($action === 'create_section') {
            $sectionName = trim((string) ($_POST['section_name'] ?? ''));
            if ($sectionName === '') {
                throw new InvalidArgumentException('Section name is required.');
            }
            $sectionCode = trim((string) ($_POST['section_code'] ?? ''));
            if ($sectionCode === '') {
                $sectionCode = buildSectionCode($sectionName);
            }

            $newSectionId = $service->createSection($currentSemesterId, $sectionCode, $sectionName, 50, 'active');
            $selectedSectionId = $newSectionId;
            htSetFlash('success', 'Section created successfully.');
            header('Location: ' . $baseAcademicUrl . '?section_id=' . $selectedSectionId);
            exit;
        } elseif ($action === 'add_students_bulk') {
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $bulk = (string) ($_POST['bulk_students'] ?? '');
            if ($sectionId <= 0) {
                throw new InvalidArgumentException('Select a valid section.');
            }

            $studentIds = [];
            $lines = preg_split('/\r\n|\r|\n/', $bulk) ?: [];
            foreach ($lines as $line) {
                $parsed = parseBulkLine($line);
                if ($parsed === null) {
                    continue;
                }

                $existing = $service->findUserByEmailOrSchoolId($parsed['email'], $parsed['school_id']);
                if ($existing !== null) {
                    if ((string) $existing['role_code'] !== 'student') {
                        throw new RuntimeException('Existing user ' . $parsed['email'] . ' is not a student.');
                    }
                    $studentIds[] = (int) $existing['id'];
                    continue;
                }

                $studentIds[] = $service->createStudentAccountWithProfile([
                    'school_id' => $parsed['school_id'],
                    'email' => $parsed['email'],
                    'password' => 'Student#123',
                    'first_name' => $parsed['first_name'],
                    'middle_name' => $parsed['middle_name'],
                    'last_name' => $parsed['last_name'],
                ]);
            }

            if ($studentIds === []) {
                throw new InvalidArgumentException('No valid student rows detected.');
            }

            $count = $service->enrollStudentsBulk($sectionId, $studentIds);
            $selectedSectionId = $sectionId;
            htSetFlash('success', "Added {$count} student(s) successfully.");
            header('Location: ' . $baseAcademicUrl . '?section_id=' . $selectedSectionId);
            exit;
        } elseif ($action === 'add_coordinators_bulk') {
            $bulk = (string) ($_POST['bulk_coordinators'] ?? '');
            $lines = preg_split('/\r\n|\r|\n/', $bulk) ?: [];
            $created = 0;
            $existingCount = 0;

            foreach ($lines as $line) {
                $parsed = parseBulkLine($line);
                if ($parsed === null) {
                    continue;
                }

                $existing = $service->findUserByEmailOrSchoolId($parsed['email'], $parsed['school_id']);
                if ($existing !== null) {
                    if ((string) $existing['role_code'] !== 'coordinator') {
                        throw new RuntimeException('Existing user ' . $parsed['email'] . ' is not a coordinator.');
                    }
                    $existingCount++;
                    continue;
                }

                $service->createCoordinatorAccountWithProfile([
                    'school_id' => $parsed['school_id'],
                    'email' => $parsed['email'],
                    'password' => 'Coord#123',
                    'first_name' => $parsed['first_name'],
                    'middle_name' => $parsed['middle_name'],
                    'last_name' => $parsed['last_name'],
                ]);
                $created++;
            }

            if ($created === 0 && $existingCount === 0) {
                throw new InvalidArgumentException('No valid coordinator rows detected.');
            }

            htSetFlash('success', "Coordinator bulk add complete. Created: {$created}, Existing kept: {$existingCount}.");
            header('Location: ' . $baseAcademicUrl . ($selectedSectionId > 0 ? '?section_id=' . $selectedSectionId : ''));
            exit;
        } elseif ($action === 'assign_coordinator') {
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $coordinatorId = (int) ($_POST['coordinator_user_id'] ?? 0);
            $service->assignCoordinator($sectionId, $coordinatorId);
            $selectedSectionId = $sectionId;
            htSetFlash('success', 'Coordinator assigned successfully.');
            header('Location: ' . $baseAcademicUrl . '?section_id=' . $selectedSectionId);
            exit;
        } elseif ($action === 'assign_unassigned_student') {
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $studentId = (int) ($_POST['student_user_id'] ?? 0);
            if ($sectionId <= 0 || $studentId <= 0) {
                throw new InvalidArgumentException('Invalid section or student.');
            }
            $service->enrollStudentsBulk($sectionId, [$studentId]);
            $selectedSectionId = $sectionId;
            htSetFlash('success', 'Student assigned to section successfully.');
            header('Location: ' . $baseAcademicUrl . '?section_id=' . $selectedSectionId);
            exit;
        } elseif ($action === 'move_student') {
            $studentId = (int) ($_POST['student_user_id'] ?? 0);
            $targetSectionId = (int) ($_POST['target_section_id'] ?? 0);
            $fromSectionId = (int) ($_POST['from_section_id'] ?? 0);
            if ($studentId <= 0 || $targetSectionId <= 0 || $fromSectionId <= 0) {
                throw new InvalidArgumentException('Invalid student move request.');
            }

            $sectionExistsStmt = $pdo->prepare('SELECT id FROM sections WHERE id = :id AND semester_id = :semester_id LIMIT 1');
            $sectionExistsStmt->execute([
                'id' => $targetSectionId,
                'semester_id' => $currentSemesterId,
            ]);
            if (!(int) $sectionExistsStmt->fetchColumn()) {
                throw new InvalidArgumentException('Target section does not exist in current semester.');
            }

            $moveStmt = $pdo->prepare(
                'UPDATE section_students
                 SET section_id = :target_section_id
                 WHERE student_user_id = :student_user_id
                   AND section_id = :from_section_id
                   AND semester_id = :semester_id'
            );
            $moveStmt->execute([
                'target_section_id' => $targetSectionId,
                'student_user_id' => $studentId,
                'from_section_id' => $fromSectionId,
                'semester_id' => $currentSemesterId,
            ]);
            if ($moveStmt->rowCount() === 0) {
                throw new RuntimeException('Student move failed. Record not found.');
            }

            htSetFlash('success', 'Student moved successfully.');
            header('Location: ' . $baseAcademicUrl . '?section_id=' . $targetSectionId);
            exit;
        } elseif ($action === 'edit_student') {
            $studentId = (int) ($_POST['student_user_id'] ?? 0);
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $schoolId = trim((string) ($_POST['school_id'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            if ($studentId <= 0 || $sectionId <= 0 || $schoolId === '' || $email === '' || $firstName === '' || $lastName === '') {
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
            header('Location: ' . $baseAcademicUrl . '?section_id=' . $sectionId);
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$sections = [];
if ($currentSemesterId > 0) {
    $sectionsStmt = $pdo->prepare(
        "SELECT
            s.id,
            s.section_code,
            s.section_name,
            s.capacity,
            COUNT(CASE WHEN ss.enrollment_status = 'active' THEN 1 END) AS student_count,
            sc.coordinator_user_id,
            up.first_name AS coordinator_first_name,
            up.last_name AS coordinator_last_name
        FROM sections s
        LEFT JOIN section_students ss
            ON ss.section_id = s.id
           AND ss.semester_id = s.semester_id
        LEFT JOIN section_coordinators sc
            ON sc.section_id = s.id
        LEFT JOIN user_profiles up
            ON up.user_id = sc.coordinator_user_id
        WHERE s.semester_id = :semester_id
        GROUP BY
            s.id, s.section_code, s.section_name, s.capacity,
            sc.coordinator_user_id, up.first_name, up.last_name
        ORDER BY s.section_code ASC"
    );
    $sectionsStmt->execute(['semester_id' => $currentSemesterId]);
    $sections = $sectionsStmt->fetchAll();
}

if ($selectedSectionId <= 0 && count($sections) > 0) {
    $selectedSectionId = (int) $sections[0]['id'];
}

$selectedSection = null;
foreach ($sections as $sec) {
    if ((int) $sec['id'] === $selectedSectionId) {
        $selectedSection = $sec;
        break;
    }
}

$studentsInSection = [];
if ($selectedSectionId > 0 && $currentSemesterId > 0) {
    $studentsStmt = $pdo->prepare(
        "SELECT
            u.id AS user_id,
            u.school_id,
            u.email,
            u.account_status,
            up.first_name,
            up.middle_name,
            up.last_name
        FROM section_students ss
        JOIN users u ON u.id = ss.student_user_id
        JOIN user_profiles up ON up.user_id = u.id
        WHERE ss.section_id = :section_id
          AND ss.semester_id = :semester_id
        ORDER BY up.last_name, up.first_name"
    );
    $studentsStmt->execute([
        'section_id' => $selectedSectionId,
        'semester_id' => $currentSemesterId,
    ]);
    $studentsInSection = $studentsStmt->fetchAll();
}

$unassignedStudents = [];
if ($currentSemesterId > 0) {
    $unassignedStmt = $pdo->prepare(
        "SELECT
            u.id,
            u.school_id,
            u.email,
            up.first_name,
            up.last_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN user_profiles up ON up.user_id = u.id
         WHERE r.role_code = 'student'
           AND u.account_status = 'active'
           AND NOT EXISTS (
                SELECT 1
                FROM section_students ss
                WHERE ss.student_user_id = u.id
                  AND ss.semester_id = :semester_id
           )
         ORDER BY up.last_name, up.first_name"
    );
    $unassignedStmt->execute(['semester_id' => $currentSemesterId]);
    $unassignedStudents = $unassignedStmt->fetchAll();
}

$coordinatorsStmt = $pdo->prepare(
    "SELECT
        u.id,
        u.email,
        u.account_status,
        up.first_name,
        up.last_name,
        sc.section_id,
        sec.section_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN section_coordinators sc ON sc.coordinator_user_id = u.id
    LEFT JOIN sections sec
        ON sec.id = sc.section_id
       AND (:semester_id_filter > 0 AND sec.semester_id = :semester_id_match)
    WHERE r.role_code = 'coordinator'
    ORDER BY up.last_name, up.first_name"
);
$coordinatorsStmt->execute([
    'semester_id_filter' => $currentSemesterId,
    'semester_id_match' => $currentSemesterId,
]);
$coordinators = $coordinatorsStmt->fetchAll();

$kpiStmt = $pdo->prepare(
    "SELECT
        COUNT(DISTINCT ss.student_user_id) AS total_students
    FROM sections s
    LEFT JOIN section_students ss
        ON ss.section_id = s.id
       AND ss.semester_id = s.semester_id
       AND ss.enrollment_status = 'active'
    WHERE s.semester_id = :semester_id"
);
$kpiStmt->execute(['semester_id' => $currentSemesterId]);
$kpi = $kpiStmt->fetch() ?: ['total_students' => 0];

$totalSections = count($sections);
$totalCoordinators = count($coordinators);

renderHeadTeacherPortalStart($user ?? [], 'academic');
?>
<section class="mt-6 space-y-6">
    <?php if ($errors): ?>
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <?php foreach ($success as $item): ?>
                <p><?= htmlspecialchars($item) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Total Students</p>
            <p class="mt-2 text-4xl font-bold text-slate-900"><?= (int) $kpi['total_students'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Total Coordinators</p>
            <p class="mt-2 text-4xl font-bold text-slate-900"><?= $totalCoordinators ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Total Sections</p>
            <p class="mt-2 text-4xl font-bold text-slate-900"><?= $totalSections ?></p>
        </article>
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-4 text-sm text-blue-800">
        <p>
            Current semester:
            <span class="font-semibold"><?= htmlspecialchars((string) ($currentSemester['semester_name'] ?? 'N/A')) ?></span>
            (SY <?= htmlspecialchars((string) ($currentSemester['year_label'] ?? 'N/A')) ?>)
            <?php if (!empty($currentSemester['semester_status'])): ?>
                <span class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold uppercase"><?= htmlspecialchars((string) $currentSemester['semester_status']) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <div class="grid gap-4 xl:grid-cols-12">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-3">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-2xl font-semibold text-slate-900">All Sections</h3>
                <button type="button" data-modal-open="createSectionModal" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">+ New Section</button>
            </div>

            <div class="max-h-[520px] space-y-3 overflow-y-auto pr-1">
                <?php foreach ($sections as $sec): ?>
                    <?php $isActive = (int) $sec['id'] === $selectedSectionId; ?>
                    <a href="?section_id=<?= (int) $sec['id'] ?>" data-section-drop="<?= (int) $sec['id'] ?>" class="block rounded-xl border p-4 transition <?= $isActive ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200 bg-white hover:bg-slate-50' ?>">
                        <div class="flex items-center justify-between">
                            <p class="text-xl font-semibold text-slate-900"><?= htmlspecialchars((string) $sec['section_name']) ?></p>
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600"><?= (int) $sec['student_count'] ?>/<?= (int) $sec['capacity'] ?></span>
                        </div>
                        <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars((string) $sec['section_code']) ?></p>
                        <p class="mt-1 text-sm text-slate-600">
                            <?= $sec['coordinator_user_id'] ? htmlspecialchars((string) ($sec['coordinator_first_name'] . ' ' . $sec['coordinator_last_name'])) : 'No coordinator' ?>
                        </p>
                    </a>
                <?php endforeach; ?>
                <?php if (!$sections): ?>
                    <div class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No sections yet.</div>
                <?php endif; ?>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-9 2xl:col-span-6">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-2xl font-bold text-slate-900 sm:text-3xl"><?= htmlspecialchars((string) ($selectedSection['section_name'] ?? 'No Section Selected')) ?></h3>
                    <p class="mt-1 text-sm text-slate-600">
                        Coordinator:
                        <span class="font-semibold text-slate-800">
                            <?= $selectedSection && $selectedSection['coordinator_user_id'] ? htmlspecialchars((string) ($selectedSection['coordinator_first_name'] . ' ' . $selectedSection['coordinator_last_name'])) : 'Not assigned' ?>
                        </span>
                    </p>
                </div>
                <?php if ($selectedSectionId > 0): ?>
                    <button type="button" data-modal-open="bulkStudentsModal" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600">Add Student</button>
                <?php endif; ?>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table class="min-w-[640px] w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Student ID</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentsInSection as $st): ?>
                            <tr class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-800"><?= htmlspecialchars((string) $st['school_id']) ?></td>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-800"><?= htmlspecialchars((string) ($st['last_name'] . ', ' . $st['first_name'])) ?></p>
                                    <p class="text-xs text-slate-500"><?= htmlspecialchars((string) $st['email']) ?></p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= ((string) $st['account_status']) === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= htmlspecialchars(strtoupper((string) $st['account_status'])) ?>
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
                                            data-student-id="<?= (int) $st['user_id'] ?>"
                                            data-student-school-id="<?= htmlspecialchars((string) $st['school_id']) ?>"
                                            data-student-email="<?= htmlspecialchars((string) $st['email']) ?>"
                                            data-student-first="<?= htmlspecialchars((string) $st['first_name']) ?>"
                                            data-student-middle="<?= htmlspecialchars((string) ($st['middle_name'] ?? '')) ?>"
                                            data-student-last="<?= htmlspecialchars((string) $st['last_name']) ?>"
                                        >◉</button>
                                        <button
                                            type="button"
                                            title="Edit"
                                            aria-label="Edit"
                                            class="rounded-md border border-blue-300 px-2 py-1 text-sm font-semibold text-blue-700 hover:bg-blue-50"
                                            data-modal-open="editStudentModal"
                                            data-student-id="<?= (int) $st['user_id'] ?>"
                                            data-student-school-id="<?= htmlspecialchars((string) $st['school_id']) ?>"
                                            data-student-email="<?= htmlspecialchars((string) $st['email']) ?>"
                                            data-student-first="<?= htmlspecialchars((string) $st['first_name']) ?>"
                                            data-student-middle="<?= htmlspecialchars((string) ($st['middle_name'] ?? '')) ?>"
                                            data-student-last="<?= htmlspecialchars((string) $st['last_name']) ?>"
                                        >✎</button>
                                        <button
                                            type="button"
                                            title="<?= count($sections) > 1 ? 'Move to another section' : 'Create another section first' ?>"
                                            aria-label="Move"
                                            class="rounded-md border border-amber-300 px-2 py-1 text-sm font-semibold text-amber-700 hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            data-modal-open="moveStudentModal"
                                            data-student-id="<?= (int) $st['user_id'] ?>"
                                            data-student-name="<?= htmlspecialchars((string) ($st['last_name'] . ', ' . $st['first_name'])) ?>"
                                            <?= count($sections) > 1 ? '' : 'disabled' ?>
                                        >⇄</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$studentsInSection): ?>
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-500">No students in this section.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-12 2xl:col-span-3">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-2xl font-semibold text-slate-900">Coordinators</h3>
                <button type="button" data-modal-open="bulkCoordinatorsModal" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">+ Add</button>
            </div>

            <input id="coordinatorSearch" type="search" placeholder="Search coordinator..." class="mb-4 w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />

            <div id="coordinatorList" class="space-y-3">
                <?php foreach ($coordinators as $coord): ?>
                    <div class="coordinator-item rounded-xl border border-slate-200 p-4" data-name="<?= htmlspecialchars(strtolower((string) ($coord['first_name'] . ' ' . $coord['last_name'] . ' ' . $coord['email']))) ?>">
                        <div class="flex items-center justify-between">
                            <p class="text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) ($coord['first_name'] . ' ' . $coord['last_name'])) ?></p>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold <?= ((string) $coord['account_status']) === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
                                <?= htmlspecialchars(strtoupper((string) $coord['account_status'])) ?>
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) $coord['email']) ?></p>
                        <p class="mt-2 text-sm text-slate-600"><?= $coord['section_id'] ? 'Assigned: ' . htmlspecialchars((string) $coord['section_name']) : 'Not assigned' ?></p>

                        <?php if ($selectedSectionId > 0): ?>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="action" value="assign_coordinator">
                                <input type="hidden" name="section_id" value="<?= (int) $selectedSectionId ?>">
                                <input type="hidden" name="coordinator_user_id" value="<?= (int) $coord['id'] ?>">
                                <button type="submit" class="rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                                    <?= ((int) $coord['section_id'] === $selectedSectionId) ? 'Assigned' : 'Assign to selected section' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$coordinators): ?>
                    <div class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No coordinators yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-2xl font-semibold text-slate-900">Unassigned Students</h3>
                <p class="mt-1 text-sm text-slate-500">Use search and assign students to the selected section.</p>
            </div>
            <div class="flex w-full gap-2 sm:w-auto">
                <input id="unassignedSearch" type="search" placeholder="Search by student ID, name, or email..." class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200 sm:w-96" />
                <button id="unassignedSearchBtn" type="button" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700">Search</button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="min-w-[860px] w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Student ID</th>
                        <th class="px-4 py-3">Full Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Action</th>
                    </tr>
                </thead>
                <tbody id="unassignedRows">
                    <?php foreach ($unassignedStudents as $us): ?>
                        <?php
                        $fullName = (string) ($us['last_name'] . ', ' . $us['first_name']);
                        $searchValue = strtolower((string) ($us['school_id'] . ' ' . $fullName . ' ' . $us['email']));
                        ?>
                        <tr class="unassigned-row border-t border-slate-100 hover:bg-slate-50" data-search="<?= htmlspecialchars($searchValue) ?>" draggable="true" data-student-drag="<?= (int) $us['id'] ?>">
                            <td class="px-4 py-3 font-semibold text-slate-800"><?= htmlspecialchars((string) $us['school_id']) ?></td>
                            <td class="px-4 py-3 text-slate-800"><?= htmlspecialchars($fullName) ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string) $us['email']) ?></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">UNASSIGNED</span>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($selectedSectionId > 0): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="assign_unassigned_student">
                                        <input type="hidden" name="student_user_id" value="<?= (int) $us['id'] ?>">
                                        <input type="hidden" name="section_id" value="<?= (int) $selectedSectionId ?>">
                                        <button type="submit" class="rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Assign to selected section</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-slate-400">Select a section first</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$unassignedStudents): ?>
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">No unassigned students for this semester.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div id="createSectionModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/45" data-modal-close="createSectionModal"></div>
    <div class="relative w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold text-slate-900">Create New Section</h3>
            <button type="button" data-modal-close="createSectionModal" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">X</button>
        </div>
        <form method="post" class="space-y-4 px-6 py-5">
            <input type="hidden" name="action" value="create_section">
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Section Name</label>
                <input name="section_name" required placeholder="e.g. Section E" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" />
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                <p>Capacity: 50 students</p>
                <p>OJT Practicum only</p>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" data-modal-close="createSectionModal" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600">Create Section</button>
            </div>
        </form>
    </div>
</div>

<div id="bulkStudentsModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/45" data-modal-close="bulkStudentsModal"></div>
    <div class="relative w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold text-slate-900">Add Students (Bulk)</h3>
            <button type="button" data-modal-close="bulkStudentsModal" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">X</button>
        </div>
        <form method="post" class="space-y-4 px-6 py-5">
            <input type="hidden" name="action" value="add_students_bulk">
            <input type="hidden" name="section_id" value="<?= (int) $selectedSectionId ?>">
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <p class="font-semibold">Quick Add Format (One student per line)</p>
                <p class="mt-1 text-xs font-mono">email:firstname:middlename:lastname:schoolID</p>
                <p class="mt-1 text-xs font-mono">or email:firstname:lastname:schoolID</p>
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Student Data</label>
                <textarea name="bulk_students" rows="6" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" placeholder="a.cruz@phinmaed.com:Angelo:M:Cruz:02-23-1001"></textarea>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" data-modal-close="bulkStudentsModal" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600">Add Students</button>
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
        <form method="post" class="space-y-4 px-6 py-5">
            <input type="hidden" name="action" value="add_coordinators_bulk">
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <p class="font-semibold">Quick Add Format (One per line)</p>
                <p class="mt-1 text-xs font-mono">email:firstname:middlename:lastname:schoolID</p>
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Coordinator Data</label>
                <textarea name="bulk_coordinators" rows="6" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" placeholder="m.santos@phinmaed.com:Maria:A:Santos:FAC-001"></textarea>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" data-modal-close="bulkCoordinatorsModal" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600">Add Coordinators</button>
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
            <input type="hidden" name="section_id" value="<?= (int) $selectedSectionId ?>">
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

<div id="moveStudentModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/45" data-modal-close="moveStudentModal"></div>
    <div class="relative w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 class="text-2xl font-semibold text-slate-900">Move Student</h3>
            <button type="button" data-modal-close="moveStudentModal" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">X</button>
        </div>
        <form method="post" class="space-y-4 px-6 py-5">
            <input type="hidden" name="action" value="move_student">
            <input type="hidden" name="student_user_id" id="moveStudentUserId" value="">
            <input type="hidden" name="from_section_id" value="<?= (int) $selectedSectionId ?>">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                <span class="font-semibold">Student:</span> <span id="moveStudentName"></span>
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Move to Section</label>
                <select id="moveTargetSectionId" name="target_section_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" <?= count($sections) > 1 ? '' : 'disabled' ?>>
                    <?php foreach ($sections as $secOption): ?>
                        <?php if ((int) $secOption['id'] === (int) $selectedSectionId) { continue; } ?>
                        <option value="<?= (int) $secOption['id'] ?>"><?= htmlspecialchars((string) $secOption['section_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (count($sections) <= 1): ?>
                    <p class="mt-2 text-xs text-amber-700">Create another section first before moving students.</p>
                <?php endif; ?>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                <button type="button" data-modal-close="moveStudentModal" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-amber-500 disabled:cursor-not-allowed disabled:opacity-60" <?= count($sections) > 1 ? '' : 'disabled' ?>>Move Student</button>
            </div>
        </form>
    </div>
</div>
<?php
$extraScript = <<<HTML
<script>
(() => {
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
        btn.addEventListener('click', () => openModal(btn.getAttribute('data-modal-open')));
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
    const items = document.querySelectorAll('.coordinator-item');
    coordinatorSearch?.addEventListener('input', () => {
        const q = coordinatorSearch.value.toLowerCase().trim();
        items.forEach((item) => {
            const hay = item.getAttribute('data-name') || '';
            item.style.display = hay.includes(q) ? '' : 'none';
        });
    });

    const unassignedSearch = document.getElementById('unassignedSearch');
    const unassignedSearchBtn = document.getElementById('unassignedSearchBtn');
    const unassignedRows = document.querySelectorAll('.unassigned-row');

    function applyUnassignedSearch() {
        const q = (unassignedSearch?.value || '').toLowerCase().trim();
        unassignedRows.forEach((row) => {
            const hay = row.getAttribute('data-search') || '';
            row.style.display = q === '' || hay.includes(q) ? '' : 'none';
        });
    }

    unassignedSearch?.addEventListener('input', applyUnassignedSearch);
    unassignedSearch?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyUnassignedSearch();
        }
    });
    unassignedSearchBtn?.addEventListener('click', applyUnassignedSearch);

    const previewStudentId = document.getElementById('previewStudentId');
    const previewStudentName = document.getElementById('previewStudentName');
    const previewStudentEmail = document.getElementById('previewStudentEmail');
    const editStudentUserId = document.getElementById('editStudentUserId');
    const editSchoolId = document.getElementById('editSchoolId');
    const editEmail = document.getElementById('editEmail');
    const editFirstName = document.getElementById('editFirstName');
    const editMiddleName = document.getElementById('editMiddleName');
    const editLastName = document.getElementById('editLastName');
    const moveStudentUserId = document.getElementById('moveStudentUserId');
    const moveStudentName = document.getElementById('moveStudentName');

    document.querySelectorAll('[data-modal-open="previewStudentModal"], [data-modal-open="editStudentModal"], [data-modal-open="moveStudentModal"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const schoolId = btn.getAttribute('data-student-school-id') || '';
            const email = btn.getAttribute('data-student-email') || '';
            const first = btn.getAttribute('data-student-first') || '';
            const middle = btn.getAttribute('data-student-middle') || '';
            const last = btn.getAttribute('data-student-last') || '';
            const userId = btn.getAttribute('data-student-id') || '';
            const fallbackName = btn.getAttribute('data-student-name') || '';
            const fullName = (first || last) ? [last, ', ', first, middle ? ' ' + middle : ''].join('') : fallbackName;

            if (previewStudentId) previewStudentId.textContent = schoolId;
            if (previewStudentName) previewStudentName.textContent = fullName;
            if (previewStudentEmail) previewStudentEmail.textContent = email;

            if (editStudentUserId) editStudentUserId.value = userId;
            if (editSchoolId) editSchoolId.value = schoolId;
            if (editEmail) editEmail.value = email;
            if (editFirstName) editFirstName.value = first;
            if (editMiddleName) editMiddleName.value = middle;
            if (editLastName) editLastName.value = last;
            if (moveStudentUserId) moveStudentUserId.value = userId;
            if (moveStudentName) moveStudentName.textContent = fullName;
        });
    });

    const studentCards = document.querySelectorAll('[data-student-drag]');
    const sectionDrops = document.querySelectorAll('[data-section-drop]');
    const dragForm = document.createElement('form');
    dragForm.method = 'post';
    dragForm.innerHTML = '<input type="hidden" name="action" value="assign_unassigned_student"><input type="hidden" name="student_user_id" value=""><input type="hidden" name="section_id" value="">';
    document.body.appendChild(dragForm);

    let draggedStudentId = null;
    studentCards.forEach((card) => {
        card.addEventListener('dragstart', () => {
            draggedStudentId = card.getAttribute('data-student-drag');
        });
    });

    sectionDrops.forEach((drop) => {
        drop.addEventListener('dragover', (e) => {
            e.preventDefault();
            drop.classList.add('ring-2', 'ring-emerald-400');
        });
        drop.addEventListener('dragleave', () => {
            drop.classList.remove('ring-2', 'ring-emerald-400');
        });
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            drop.classList.remove('ring-2', 'ring-emerald-400');
            if (!draggedStudentId) return;
            dragForm.querySelector('input[name=\"student_user_id\"]').value = draggedStudentId;
            dragForm.querySelector('input[name=\"section_id\"]').value = drop.getAttribute('data-section-drop');
            dragForm.submit();
        });
    });
})();
</script>
HTML;
renderHeadTeacherPortalEnd($extraScript);
