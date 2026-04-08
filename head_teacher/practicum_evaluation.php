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
    "CREATE TABLE IF NOT EXISTS evaluation_checklist_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        semester_id INT UNSIGNED NOT NULL,
        label VARCHAR(160) NOT NULL,
        points TINYINT UNSIGNED NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_evaluation_checklist_semester
            FOREIGN KEY (semester_id) REFERENCES semesters(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        INDEX idx_evaluation_checklist_semester (semester_id),
        INDEX idx_evaluation_checklist_active (is_active)
    ) ENGINE=InnoDB"
);

function toMonday(string $date): string
{
    return (new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');
}

function weekLabel(string $monday, string $friday, int $index, bool $active = false): string
{
    $prefix = 'Week ' . $index;
    $range = date('M j', strtotime($monday)) . '-' . date('j, Y', strtotime($friday));
    return $prefix . ' (' . $range . ')' . ($active ? ' (ACTIVE)' : '');
}

$semesterStmt = $pdo->query(
    "SELECT sem.id, sem.semester_name, sem.semester_no, sem.start_date, sem.end_date, sem.semester_status, sy.year_label
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

$sections = [];
$sectionMap = [];
$selectedSectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0;

if ($currentSemesterId > 0) {
    $sectionStmt = $pdo->prepare(
        "SELECT id, section_name
         FROM sections
         WHERE semester_id = :semester_id
         ORDER BY section_name ASC"
    );
    $sectionStmt->execute(['semester_id' => $currentSemesterId]);
    $sections = $sectionStmt->fetchAll();
    foreach ($sections as $section) {
        $sectionMap[(int) $section['id']] = (string) $section['section_name'];
    }
    if ($selectedSectionId > 0 && !isset($sectionMap[$selectedSectionId])) {
        $selectedSectionId = 0;
    }
}

$weekOptions = [];
$weekStart = toMonday(date('Y-m-d'));
$weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
$selectedWeekIndex = 1;
$selectedWeekLabel = weekLabel($weekStart, $weekEnd, $selectedWeekIndex, true);

if ($currentSemester) {
    $semesterStart = toMonday((string) $currentSemester['start_date']);
    $semesterEnd = (new DateTimeImmutable((string) $currentSemester['end_date']))->format('Y-m-d');
    $cursor = new DateTimeImmutable($semesterStart);
    $index = 1;

    while ($cursor->format('Y-m-d') <= $semesterEnd) {
        $monday = $cursor->format('Y-m-d');
        $friday = $cursor->modify('+4 days')->format('Y-m-d');
        $weekOptions[] = [
            'start' => $monday,
            'end' => $friday,
            'index' => $index,
            'label' => weekLabel($monday, $friday, $index, false),
        ];
        $cursor = $cursor->modify('+7 days');
        $index++;
    }

    if (count($weekOptions) > 0) {
        $firstWeekStart = $weekOptions[0]['start'];
        $todayWeekStart = toMonday((new DateTimeImmutable('today'))->format('Y-m-d'));

        $semesterAttendanceStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM attendance_records WHERE semester_id = :semester_id"
        );
        $semesterAttendanceStmt->execute(['semester_id' => $currentSemesterId]);
        $semesterHasAttendance = ((int) $semesterAttendanceStmt->fetchColumn()) > 0;

        $requestedWeekStart = isset($_GET['week_start']) ? toMonday((string) $_GET['week_start']) : null;
        if ($requestedWeekStart === null) {
            if ((string) $currentSemester['semester_status'] === 'planned' || !$semesterHasAttendance) {
                $requestedWeekStart = $firstWeekStart;
            } else {
                $requestedWeekStart = $todayWeekStart;
            }
        }

        $weekMatch = null;
        foreach ($weekOptions as $opt) {
            if ($opt['start'] === $requestedWeekStart) {
                $weekMatch = $opt;
                break;
            }
        }
        if ($weekMatch === null) {
            $weekMatch = $weekOptions[0];
        }

        $weekStart = $weekMatch['start'];
        $weekEnd = $weekMatch['end'];
        $selectedWeekIndex = (int) $weekMatch['index'];
        $selectedWeekLabel = weekLabel($weekStart, $weekEnd, $selectedWeekIndex, true);
    }
}

$pageSize = 10;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $pageSize;

$whereSql = "ss.semester_id = :semester_id AND ss.enrollment_status = 'active'";
$params = [
    'semester_id' => $currentSemesterId,
    'week_start' => $weekStart,
    'week_end' => $weekEnd,
];
if ($selectedSectionId > 0) {
    $whereSql .= " AND ss.section_id = :section_id";
    $params['section_id'] = $selectedSectionId;
}

$totalStudents = 0;
$rows = [];
$summary = [
    'journal_submitted' => 0,
    'checklist_done' => 0,
    'avg_score' => 0.0,
];

if ($currentSemesterId > 0) {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM section_students ss
         WHERE {$whereSql}"
    );
    $countStmt->execute(array_filter($params, static fn($k) => $k !== 'week_start' && $k !== 'week_end', ARRAY_FILTER_USE_KEY));
    $totalStudents = (int) $countStmt->fetchColumn();

    $rowsStmt = $pdo->prepare(
        "SELECT
            ss.student_user_id,
            u.school_id,
            u.email,
            up.first_name,
            up.last_name,
            s.section_name,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN ar.total_hours ELSE 0 END), 0) AS mon_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN ar.total_hours ELSE 0 END), 0) AS tue_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN ar.total_hours ELSE 0 END), 0) AS wed_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN ar.total_hours ELSE 0 END), 0) AS thu_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.total_hours ELSE 0 END), 0) AS fri_hours,
            COALESCE(SUM(ar.total_hours), 0) AS week_total_hours
         FROM section_students ss
         JOIN users u ON u.id = ss.student_user_id
         JOIN user_profiles up ON up.user_id = ss.student_user_id
         JOIN sections s ON s.id = ss.section_id
         LEFT JOIN attendance_records ar
           ON ar.student_user_id = ss.student_user_id
          AND ar.section_id = ss.section_id
          AND ar.semester_id = ss.semester_id
          AND ar.attendance_date BETWEEN :week_start AND :week_end
         WHERE {$whereSql}
         GROUP BY
            ss.student_user_id, u.school_id, u.email, up.first_name, up.last_name, s.section_name
         ORDER BY up.last_name ASC, up.first_name ASC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $rowsStmt->bindValue(':' . $key, $value);
    }
    $rowsStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $rowsStmt->execute();
    $rows = $rowsStmt->fetchAll();

    $summaryStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN t.fri_hours > 0 THEN 1 ELSE 0 END) AS journal_submitted,
            SUM(CASE WHEN t.week_total_hours >= 20 THEN 1 ELSE 0 END) AS checklist_done,
            AVG(LEAST((t.week_total_hours / 40) * 20, 20)) AS avg_score
         FROM (
            SELECT
                ss.student_user_id,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.total_hours ELSE 0 END), 0) AS fri_hours,
                COALESCE(SUM(ar.total_hours), 0) AS week_total_hours
            FROM section_students ss
            LEFT JOIN attendance_records ar
              ON ar.student_user_id = ss.student_user_id
             AND ar.section_id = ss.section_id
             AND ar.semester_id = ss.semester_id
             AND ar.attendance_date BETWEEN :week_start AND :week_end
            WHERE {$whereSql}
            GROUP BY ss.student_user_id
         ) t"
    );
    foreach ($params as $key => $value) {
        $summaryStmt->bindValue(':' . $key, $value);
    }
    $summaryStmt->execute();
    $sumRow = $summaryStmt->fetch();
    if ($sumRow) {
        $summary['journal_submitted'] = (int) ($sumRow['journal_submitted'] ?? 0);
        $summary['checklist_done'] = (int) ($sumRow['checklist_done'] ?? 0);
        $summary['avg_score'] = (float) ($sumRow['avg_score'] ?? 0);
    }
}

$totalPages = max(1, (int) ceil($totalStudents / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}

$viewStudentId = isset($_GET['view_student_id']) ? (int) $_GET['view_student_id'] : 0;
$detail = null;
if ($viewStudentId > 0 && $currentSemesterId > 0) {
    $detailSql = "SELECT
            ss.student_user_id,
            u.school_id,
            u.email,
            up.first_name,
            up.last_name,
            s.section_name,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN ar.total_hours ELSE 0 END), 0) AS mon_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN ar.total_hours ELSE 0 END), 0) AS tue_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN ar.total_hours ELSE 0 END), 0) AS wed_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN ar.total_hours ELSE 0 END), 0) AS thu_hours,
            COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.total_hours ELSE 0 END), 0) AS fri_hours,
            COALESCE(SUM(ar.total_hours), 0) AS week_total_hours
         FROM section_students ss
         JOIN users u ON u.id = ss.student_user_id
         JOIN user_profiles up ON up.user_id = ss.student_user_id
         JOIN sections s ON s.id = ss.section_id
         LEFT JOIN attendance_records ar
           ON ar.student_user_id = ss.student_user_id
          AND ar.section_id = ss.section_id
          AND ar.semester_id = ss.semester_id
          AND ar.attendance_date BETWEEN :week_start AND :week_end
         WHERE ss.semester_id = :semester_id
           AND ss.student_user_id = :student_user_id
         GROUP BY ss.student_user_id, u.school_id, u.email, up.first_name, up.last_name, s.section_name";
    if ($selectedSectionId > 0) {
        $detailSql .= " AND ss.section_id = :section_id";
    }
    $detailStmt = $pdo->prepare($detailSql);
    $detailStmt->bindValue(':week_start', $weekStart);
    $detailStmt->bindValue(':week_end', $weekEnd);
    $detailStmt->bindValue(':semester_id', $currentSemesterId, PDO::PARAM_INT);
    $detailStmt->bindValue(':student_user_id', $viewStudentId, PDO::PARAM_INT);
    if ($selectedSectionId > 0) {
        $detailStmt->bindValue(':section_id', $selectedSectionId, PDO::PARAM_INT);
    }
    $detailStmt->execute();
    $detail = $detailStmt->fetch() ?: null;
}

$uiError = null;
$checklistItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_checklist_item') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $points = (int) ($_POST['points'] ?? 0);
        if ($currentSemesterId <= 0) {
            $uiError = 'No active/planned semester found for checklist builder.';
        } elseif ($label === '' || $points <= 0) {
            $uiError = 'Checklist item label and points are required.';
        } else {
            $nextSortStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(sort_order), 0) + 1
                 FROM evaluation_checklist_items
                 WHERE semester_id = :semester_id AND is_active = 1"
            );
            $nextSortStmt->execute(['semester_id' => $currentSemesterId]);
            $nextSort = (int) $nextSortStmt->fetchColumn();

            $insertChecklistStmt = $pdo->prepare(
                "INSERT INTO evaluation_checklist_items (semester_id, label, points, sort_order, is_active)
                 VALUES (:semester_id, :label, :points, :sort_order, 1)"
            );
            $insertChecklistStmt->execute([
                'semester_id' => $currentSemesterId,
                'label' => $label,
                'points' => min($points, 20),
                'sort_order' => max(1, $nextSort),
            ]);

            header(
                'Location: practicum_evaluation.php?' . http_build_query([
                    'week_start' => $weekStart,
                    'section_id' => $selectedSectionId,
                    'page' => $page,
                ])
            );
            exit;
        }
    } elseif ($action === 'remove_checklist_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $deleteChecklistStmt = $pdo->prepare(
                "UPDATE evaluation_checklist_items
                 SET is_active = 0
                 WHERE id = :id AND semester_id = :semester_id"
            );
            $deleteChecklistStmt->execute([
                'id' => $itemId,
                'semester_id' => $currentSemesterId,
            ]);
        }
        header(
            'Location: practicum_evaluation.php?' . http_build_query([
                'week_start' => $weekStart,
                'section_id' => $selectedSectionId,
                'page' => $page,
            ])
        );
        exit;
    }
}

if ($currentSemesterId > 0) {
    $checklistLoadStmt = $pdo->prepare(
        "SELECT id, label, points, sort_order
         FROM evaluation_checklist_items
         WHERE semester_id = :semester_id
           AND is_active = 1
         ORDER BY sort_order ASC, id ASC"
    );
    $checklistLoadStmt->execute(['semester_id' => $currentSemesterId]);
    $checklistItems = $checklistLoadStmt->fetchAll();
}

$checklistTotalPoints = 0;
foreach ($checklistItems as $item) {
    $checklistTotalPoints += (int) ($item['points'] ?? 0);
}

function hoursCell(float $hours): string
{
    if ($hours <= 0) {
        return '<span class="text-slate-300">Upcoming</span>';
    }
    return number_format($hours, 1) . 'h';
}

renderHeadTeacherPortalStart($user ?? [], 'evaluation');
?>
<section class="mt-6 space-y-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Practicum Evaluation</h2>
                <p class="mt-1 text-sm text-slate-600">Weekly evaluation table, journal submissions, and checklist scoring.</p>
            </div>
            <form method="get" class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                <select name="week_start" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                    <?php foreach ($weekOptions as $opt): ?>
                        <option value="<?= htmlspecialchars((string) $opt['start']) ?>" <?= $opt['start'] === $weekStart ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $opt['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="section_id" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-100">
                    <option value="0" <?= $selectedSectionId === 0 ? 'selected' : '' ?>>All Sections</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= (int) $section['id'] === $selectedSectionId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $section['section_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
            </form>
        </div>
    </div>

    <?php if ($uiError !== null): ?>
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= htmlspecialchars($uiError) ?></div>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Students</p>
            <p class="mt-2 text-4xl font-bold text-slate-900"><?= $totalStudents ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Journal Submitted</p>
            <p class="mt-2 text-3xl font-bold text-slate-900"><?= $summary['journal_submitted'] ?></p>
            <p class="text-sm text-slate-500">Friday submissions this week</p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-orange-600">Checklist Done</p>
            <p class="mt-2 text-3xl font-bold text-slate-900"><?= $summary['checklist_done'] ?></p>
            <p class="text-sm text-slate-500">Students with >= 20 weekly hours</p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Avg Score</p>
            <p class="mt-2 text-3xl font-bold text-slate-900"><?= number_format($summary['avg_score'], 1) ?><span class="text-base font-medium text-slate-500">/20</span></p>
            <p class="text-sm text-slate-500">Derived from weekly attendance</p>
        </article>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2 text-xl font-semibold text-slate-900">
                <span><?= $selectedSectionId > 0 ? htmlspecialchars($sectionMap[$selectedSectionId] ?? 'Section') : 'All Sections' ?></span>
                <span>&mdash; Weekly Evaluation</span>
                <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= $totalStudents ?> Students</span>
            </div>
            <span class="inline-flex items-center rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white"><?= htmlspecialchars($selectedWeekLabel) ?></span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-[1160px] w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Student Name</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Section</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">M</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">T</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">W</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">TH</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">F</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Journal (Fri)</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Checklist</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Score</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $mon = (float) $row['mon_hours'];
                        $tue = (float) $row['tue_hours'];
                        $wed = (float) $row['wed_hours'];
                        $thu = (float) $row['thu_hours'];
                        $fri = (float) $row['fri_hours'];
                        $total = (float) $row['week_total_hours'];
                        $score = min(($total / 40) * 20, 20);
                        $journal = $fri > 0 ? 'Submitted' : 'Pending';
                        $checklist = $total >= 20 ? 'Done' : 'Not Yet';
                        $baseQuery = [
                            'week_start' => $weekStart,
                            'section_id' => $selectedSectionId,
                            'page' => $page,
                            'view_student_id' => (int) $row['student_user_id'],
                        ];
                        ?>
                        <tr class="transition hover:bg-slate-50">
                            <td class="border-b border-slate-100 px-4 py-3">
                                <p class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars((string) $row['email']) ?></p>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) $row['section_name']) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3"><?= hoursCell($mon) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3"><?= hoursCell($tue) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3"><?= hoursCell($wed) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3"><?= hoursCell($thu) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3"><?= hoursCell($fri) ?></td>
                            <td class="border-b border-slate-100 px-4 py-3">
                                <span class="text-xs italic <?= $journal === 'Submitted' ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $journal ?></span>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3">
                                <span class="text-xs italic <?= $checklist === 'Done' ? 'text-emerald-700' : 'text-slate-500' ?>"><?= $checklist ?></span>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3"><?= number_format($score, 1) ?>/20</td>
                            <td class="border-b border-slate-100 px-4 py-3">
                                <a href="?<?= htmlspecialchars(http_build_query($baseQuery)) ?>" class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">View Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="11" class="border-b border-slate-100 px-4 py-8 text-center text-sm text-slate-500">No student records for this filter.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500">
                Showing <?= $totalStudents === 0 ? 0 : ($offset + 1) ?> to <?= min($offset + $pageSize, $totalStudents) ?> of <?= $totalStudents ?> students
            </p>
            <div class="flex gap-2">
                <?php
                $prevQuery = ['week_start' => $weekStart, 'section_id' => $selectedSectionId, 'page' => max(1, $page - 1)];
                $nextQuery = ['week_start' => $weekStart, 'section_id' => $selectedSectionId, 'page' => min($totalPages, $page + 1)];
                ?>
                <a href="?<?= htmlspecialchars(http_build_query($prevQuery)) ?>" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 <?= $page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-slate-100' ?>">Previous</a>
                <a href="?<?= htmlspecialchars(http_build_query($nextQuery)) ?>" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : 'hover:bg-slate-100' ?>">Next</a>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-3">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-3xl font-semibold tracking-tight text-slate-900">Checklist Builder</h3>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">Total: <?= $checklistTotalPoints ?> pts</span>
            </div>
            <div class="space-y-3">
                <?php foreach ($checklistItems as $item): ?>
                    <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="font-medium text-slate-800"><?= htmlspecialchars((string) $item['label']) ?></p>
                        <div class="flex items-center gap-3">
                            <span class="rounded-full border border-slate-300 bg-white px-3 py-1 text-sm font-semibold text-slate-700"><?= (int) $item['points'] ?> pts</span>
                            <form method="post">
                                <input type="hidden" name="action" value="remove_checklist_item">
                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$checklistItems): ?>
                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">No checklist items in database for this semester yet. Add one below.</div>
                <?php endif; ?>
            </div>
            <form method="post" class="mt-4 grid gap-3 sm:grid-cols-5">
                <input type="hidden" name="action" value="add_checklist_item">
                <input type="text" name="label" placeholder="Criteria item" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 sm:col-span-3" required>
                <input type="number" min="1" max="20" name="points" placeholder="Pts" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 sm:col-span-1" required>
                <button type="submit" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100 sm:col-span-1">+ Add Item</button>
            </form>
        </div>
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm lg:col-span-2">
            <h3 class="text-3xl font-semibold tracking-tight text-slate-900">Evaluation Policy</h3>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-blue-900">
                <li>Checklist evaluation happens <span class="font-semibold">once per week</span> per section.</li>
                <li>The day varies (Mon-Fri) depending on the coordinator&apos;s schedule.</li>
                <li>Journals are considered submitted when Friday attendance exists.</li>
                <li>Checklist changes apply to future evaluations only.</li>
                <li>Max score per student: <span class="font-semibold">20 pts</span>.</li>
            </ul>
        </div>
    </div>

    <?php if ($detail): ?>
        <?php
        $modalBase = ['week_start' => $weekStart, 'section_id' => $selectedSectionId, 'page' => $page];
        $closeUrl = '?' . http_build_query($modalBase);
        $detailTotal = (float) $detail['week_total_hours'];
        $detailScore = min(($detailTotal / 40) * 20, 20);
        ?>
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/55 p-4">
            <div class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex items-start justify-between border-b border-slate-200 px-6 py-4">
                    <div>
                        <h3 class="text-xl font-semibold text-slate-900">Student Evaluation Details</h3>
                        <p class="text-sm text-slate-500"><?= htmlspecialchars((string) ($detail['last_name'] . ', ' . $detail['first_name'])) ?> • <?= htmlspecialchars((string) $detail['school_id']) ?></p>
                    </div>
                    <a href="<?= htmlspecialchars($closeUrl) ?>" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Close</a>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Section</p>
                            <p class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) $detail['section_name']) ?></p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Week</p>
                            <p class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars($weekStart) ?> to <?= htmlspecialchars($weekEnd) ?></p>
                        </div>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-5">
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Mon</p><p class="font-semibold text-slate-900"><?= number_format((float) $detail['mon_hours'], 1) ?>h</p></div>
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Tue</p><p class="font-semibold text-slate-900"><?= number_format((float) $detail['tue_hours'], 1) ?>h</p></div>
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Wed</p><p class="font-semibold text-slate-900"><?= number_format((float) $detail['wed_hours'], 1) ?>h</p></div>
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Thu</p><p class="font-semibold text-slate-900"><?= number_format((float) $detail['thu_hours'], 1) ?>h</p></div>
                        <div class="rounded-lg border border-slate-200 p-3 text-center"><p class="text-xs text-slate-500">Fri</p><p class="font-semibold text-slate-900"><?= number_format((float) $detail['fri_hours'], 1) ?>h</p></div>
                    </div>
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <p class="text-sm text-emerald-900">Weekly Total: <span class="font-semibold"><?= number_format($detailTotal, 1) ?>h</span> • Derived Score: <span class="font-semibold"><?= number_format($detailScore, 1) ?>/20</span></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php renderHeadTeacherPortalEnd(); ?>
