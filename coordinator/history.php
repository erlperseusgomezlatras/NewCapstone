<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/AttendanceCalculation.php';
require_once __DIR__ . '/../models/JournalModuleRepository.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$pdo = getPDO();
new JournalModuleRepository($pdo);
$attendanceService = new AttendanceService($pdo);
$coordinatorUserId = (int) ($user['id'] ?? 0);
$context = $attendanceService->getCoordinatorSectionContext($coordinatorUserId) ?? [];

function historyTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         LIMIT 1'
    );
    $stmt->execute(['table_name' => $table]);

    return $stmt->fetchColumn() !== false;
}

function historyIntParam(string $key): int
{
    return isset($_GET[$key]) && filter_var($_GET[$key], FILTER_VALIDATE_INT) !== false
        ? max(0, (int) $_GET[$key])
        : 0;
}

function historySearchParam(): string
{
    return trim((string) ($_GET['search'] ?? ''));
}

function historyFullName(array $row): string
{
    $name = trim((string) (($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')));
    return $name !== ',' && $name !== '' ? $name : 'Unknown Student';
}

function historyWeekdaysBetween(string $start, string $end): int
{
    $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    if ($startDate === false || $endDate === false || $endDate < $startDate) {
        return 0;
    }

    $count = 0;
    for ($cursor = $startDate; $cursor <= $endDate; $cursor = $cursor->modify('+1 day')) {
        if ((int) $cursor->format('N') <= 5) {
            $count++;
        }
    }

    return $count;
}

function historyFinalStatus(float $hours, int $journalsDone, int $journalsRequired, int $checklistDone, int $checklistTotal): string
{
    if ($hours <= 0 && $journalsDone === 0 && $checklistTotal === 0) {
        return 'Archived';
    }
    if ($hours >= 180 && $journalsDone >= $journalsRequired && ($checklistTotal === 0 || $checklistDone >= $checklistTotal)) {
        return 'Completed';
    }
    if ($hours < 144 || $journalsDone < max(1, (int) floor($journalsRequired * 0.75))) {
        return 'Critical';
    }

    return 'Incomplete';
}

function historyStatusBadge(string $status): string
{
    return match ($status) {
        'Completed' => coordinatorTopBadge($status, 'green'),
        'Critical' => coordinatorTopBadge($status, 'red'),
        'Incomplete' => coordinatorTopBadge($status, 'orange'),
        default => coordinatorTopBadge($status, 'slate'),
    };
}

function historyBaseQuery(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || $value === 0 || $value === '0') {
            unset($query[$key]);
        }
    }

    return '?' . http_build_query($query);
}

$selectedSchoolYearId = historyIntParam('school_year_id');
$selectedSemesterId = historyIntParam('semester_id');
$selectedSchoolId = historyIntParam('partnered_school_id');
$search = historySearchParam();
$detailStudentId = historyIntParam('student_id');
$detailSemesterId = historyIntParam('detail_semester_id');
$detailSchoolId = historyIntParam('detail_school_id');
$page = max(1, historyIntParam('page') ?: 1);
$pageSize = 10;
$offset = ($page - 1) * $pageSize;
$hasDynamicJournals = historyTableExists($pdo, 'journal_submissions');
$hasLegacyJournals = historyTableExists($pdo, 'student_journals');

$historyFromSql = "FROM student_geofence_assignments sga
    JOIN semesters sem ON sem.id = sga.semester_id
    JOIN school_years sy ON sy.id = sem.school_year_id
    LEFT JOIN semester_students sems
      ON sems.student_user_id = sga.student_user_id
     AND sems.semester_id = sem.id
    JOIN users u ON u.id = sga.student_user_id
    JOIN user_profiles up ON up.user_id = u.id
    JOIN geofence_locations g ON g.id = sga.geofence_id";
$historyWhereSql = "WHERE sga.coordinator_user_id = :coordinator_user_id
      AND sga.semester_id IS NOT NULL
      AND sem.semester_status IN ('closed', 'archived')";

$filterParams = ['coordinator_user_id' => $coordinatorUserId];
$where = '';
if ($selectedSchoolYearId > 0) {
    $where .= ' AND sy.id = :school_year_id';
    $filterParams['school_year_id'] = $selectedSchoolYearId;
}
if ($selectedSemesterId > 0) {
    $where .= ' AND sem.id = :semester_id';
    $filterParams['semester_id'] = $selectedSemesterId;
}
if ($selectedSchoolId > 0) {
    $where .= ' AND g.id = :partnered_school_id';
    $filterParams['partnered_school_id'] = $selectedSchoolId;
}
if ($search !== '') {
    $where .= " AND (u.school_id LIKE :search OR CONCAT_WS(' ', up.first_name, up.last_name) LIKE :search_name OR CONCAT_WS(' ', up.last_name, up.first_name) LIKE :search_name_rev)";
    $filterParams['search'] = '%' . $search . '%';
    $filterParams['search_name'] = '%' . $search . '%';
    $filterParams['search_name_rev'] = '%' . $search . '%';
}

$schoolYearsStmt = $pdo->prepare(
    "SELECT DISTINCT sy.id, sy.year_label
     {$historyFromSql}
     {$historyWhereSql}
     ORDER BY sy.start_date DESC, sy.year_label DESC"
);
$schoolYearsStmt->execute(['coordinator_user_id' => $coordinatorUserId]);
$schoolYears = $schoolYearsStmt->fetchAll(PDO::FETCH_ASSOC);

$semestersStmt = $pdo->prepare(
    "SELECT DISTINCT sem.id, sem.semester_name, sem.semester_no, sy.year_label
     {$historyFromSql}
     {$historyWhereSql}
       " . ($selectedSchoolYearId > 0 ? ' AND sy.id = :school_year_id' : '') . "
     ORDER BY sy.year_label DESC, sem.semester_no ASC, sem.start_date DESC"
);
$semesterParams = ['coordinator_user_id' => $coordinatorUserId];
if ($selectedSchoolYearId > 0) {
    $semesterParams['school_year_id'] = $selectedSchoolYearId;
}
$semestersStmt->execute($semesterParams);
$semesters = $semestersStmt->fetchAll(PDO::FETCH_ASSOC);

$schoolsSql = "SELECT DISTINCT g.id, g.name
     {$historyFromSql}
     {$historyWhereSql}";
$schoolsParams = ['coordinator_user_id' => $coordinatorUserId];
if ($selectedSchoolYearId > 0) {
    $schoolsSql .= ' AND sy.id = :school_year_id';
    $schoolsParams['school_year_id'] = $selectedSchoolYearId;
}
if ($selectedSemesterId > 0) {
    $schoolsSql .= ' AND sem.id = :semester_id';
    $schoolsParams['semester_id'] = $selectedSemesterId;
}
$schoolsSql .= ' ORDER BY g.name ASC';
$schoolsStmt = $pdo->prepare($schoolsSql);
$schoolsStmt->execute($schoolsParams);
$schools = $schoolsStmt->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(sga.student_user_id, ':', sem.id, ':', g.id)) {$historyFromSql} {$historyWhereSql}{$where}");
$countStmt->execute($filterParams);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));

$journalAggregateSql = 'SELECT NULL AS semester_id, NULL AS student_user_id, 0 AS journals_done WHERE 1=0';
if ($hasDynamicJournals || $hasLegacyJournals) {
    $journalSources = [];
    if ($hasDynamicJournals) {
        $journalSources[] = "SELECT semester_id, student_user_id, week_start
                             FROM journal_submissions
                             WHERE status IN ('pending', 'reviewed', 'approved', 'resubmitted')";
    }
    if ($hasLegacyJournals) {
        $journalSources[] = "SELECT semester_id, student_user_id, week_start
                             FROM student_journals
                             WHERE status IN ('pending', 'reviewed', 'approved', 'resubmitted')";
    }
    $journalAggregateSql = "SELECT semester_id, student_user_id, COUNT(DISTINCT week_start) AS journals_done
                            FROM (" . implode(' UNION ALL ', $journalSources) . ") journal_union
                            GROUP BY semester_id, student_user_id";
}

$renderedHoursSql = AttendanceCalculation::renderedHoursSql('ar');
$rowsSql = "SELECT
        sga.student_user_id,
        sem.id AS semester_id,
        sem.semester_name,
        sem.semester_no,
        sem.practicum_start_date,
        sem.start_date AS semester_start_date,
        sem.end_date AS semester_end_date,
        sy.id AS school_year_id,
        sy.year_label,
        g.id AS geofence_id,
        g.name AS school_name,
        u.school_id,
        u.email,
        up.first_name,
        up.last_name,
        COALESCE(att.days_present, 0) AS days_present,
        COALESCE(att.rendered_hours, 0) AS rendered_hours,
        COALESCE(jr.journals_done, 0) AS journals_done,
        COALESCE(ch.checklist_done, 0) AS checklist_done,
        COALESCE(ch.checklist_total, 0) AS checklist_total,
        COALESCE(ch.checklist_score, 0) AS checklist_score
    {$historyFromSql}
    LEFT JOIN (
        SELECT semester_id, student_user_id,
               COUNT(DISTINCT attendance_date) AS days_present,
               COALESCE(SUM({$renderedHoursSql}), 0) AS rendered_hours
        FROM attendance_records ar
        GROUP BY semester_id, student_user_id
    ) att ON att.semester_id = sem.id AND att.student_user_id = sga.student_user_id
    LEFT JOIN (
        {$journalAggregateSql}
    ) jr ON jr.semester_id = sem.id AND jr.student_user_id = sga.student_user_id
    LEFT JOIN (
        SELECT semester_id, student_user_id,
               SUM(CASE WHEN is_checked = 1 THEN 1 ELSE 0 END) AS checklist_done,
               COUNT(*) AS checklist_total,
               COALESCE(SUM(awarded_points), 0) AS checklist_score
        FROM student_weekly_checklist
        GROUP BY semester_id, student_user_id
    ) ch ON ch.semester_id = sem.id AND ch.student_user_id = sga.student_user_id
    {$historyWhereSql}{$where}
    ORDER BY sy.start_date DESC, sem.semester_no DESC, g.name ASC, up.last_name ASC, up.first_name ASC
    LIMIT :limit OFFSET :offset";
$rowsStmt = $pdo->prepare($rowsSql);
foreach ($filterParams as $key => $value) {
    $rowsStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$rowsStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$rowsStmt->execute();
$historyRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$detail = null;
$weeklyAttendance = [];
$journalRows = [];
$checklistRows = [];
if ($detailStudentId > 0 && $detailSemesterId > 0 && $detailSchoolId > 0) {
    $detailStmt = $pdo->prepare(
        "SELECT
            sga.student_user_id,
            sem.id AS semester_id,
            sem.semester_name,
            sem.semester_no,
            sem.practicum_start_date,
            sem.start_date AS semester_start_date,
            sem.end_date AS semester_end_date,
            sy.year_label,
            g.id AS geofence_id,
            g.name AS school_name,
            u.school_id,
            u.email,
            up.first_name,
            up.last_name,
            cup.first_name AS coordinator_first_name,
            cup.last_name AS coordinator_last_name
         {$historyFromSql}
         LEFT JOIN users cu ON cu.id = sga.coordinator_user_id
         LEFT JOIN user_profiles cup ON cup.user_id = cu.id
         {$historyWhereSql}
         AND sga.student_user_id = :detail_student_id
         AND sem.id = :detail_semester_id
         AND g.id = :detail_school_id
         LIMIT 1"
    );
    $detailStmt->execute([
        'coordinator_user_id' => $coordinatorUserId,
        'detail_student_id' => $detailStudentId,
        'detail_semester_id' => $detailSemesterId,
        'detail_school_id' => $detailSchoolId,
    ]);
    $detail = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($detail !== null) {
        $attendanceStmt = $pdo->prepare(
            "SELECT attendance_date, time_in, time_out, total_hours, remarks, COALESCE(is_override, 0) AS is_override
             FROM attendance_records
             WHERE student_user_id = :student_user_id
               AND semester_id = :semester_id
             ORDER BY attendance_date ASC"
        );
        $attendanceStmt->execute([
            'student_user_id' => $detailStudentId,
            'semester_id' => $detailSemesterId,
        ]);
        $attendanceByDate = [];
        foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $attendance) {
            $attendanceByDate[(string) $attendance['attendance_date']] = $attendance;
        }

        $weeks = practicumBuildWeeks((string) practicumEffectiveStart((string) ($detail['practicum_start_date'] ?? '')) ?: (string) $detail['semester_start_date'], (string) $detail['semester_end_date'], (string) $detail['semester_end_date']);
        foreach ($weeks as $week) {
            $presentDays = 0;
            $hours = 0.0;
            $weekStart = (string) $week['start'];
            $weekEnd = (string) $week['end'];
            for ($day = new DateTimeImmutable($weekStart); $day <= new DateTimeImmutable($weekEnd); $day = $day->modify('+1 day')) {
                $dateKey = $day->format('Y-m-d');
                if (isset($attendanceByDate[$dateKey]) && !empty($attendanceByDate[$dateKey]['time_in'])) {
                    $presentDays++;
                    $hours += (float) ($attendanceByDate[$dateKey]['total_hours'] ?? 0);
                }
            }
            $weeklyAttendance[] = [
                'label' => (string) $week['label'],
                'start' => $weekStart,
                'end' => $weekEnd,
                'present_days' => $presentDays,
                'hours' => $hours,
            ];
        }

        if ($hasDynamicJournals || $hasLegacyJournals) {
            $detailJournalSources = [];
            $detailJournalParams = [];
            if ($hasDynamicJournals) {
                $detailJournalSources[] = "SELECT week_start, submitted_at, status, coordinator_feedback,
                                                   COALESCE(points_awarded, 0) AS points_awarded,
                                                   COALESCE(max_points, 50) AS max_points, 'dynamic' AS source
                                           FROM journal_submissions
                                           WHERE student_user_id = ?
                                             AND semester_id = ?";
                $detailJournalParams[] = $detailStudentId;
                $detailJournalParams[] = $detailSemesterId;
            }
            if ($hasLegacyJournals) {
                $detailJournalSources[] = "SELECT week_start, submitted_at, status, coordinator_feedback,
                                                   0 AS points_awarded, 50 AS max_points, 'legacy' AS source
                                           FROM student_journals
                                           WHERE student_user_id = ?
                                             AND semester_id = ?";
                $detailJournalParams[] = $detailStudentId;
                $detailJournalParams[] = $detailSemesterId;
            }
            $journalStmt = $pdo->prepare(
                implode(' UNION ALL ', $detailJournalSources) . ' ORDER BY week_start ASC, submitted_at ASC'
            );
            $journalStmt->execute($detailJournalParams);
            $journalRows = $journalStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $checklistStmt = $pdo->prepare(
            "SELECT
                swc.week_start,
                GROUP_CONCAT(CONCAT(eci.item_name, ': ', CASE WHEN swc.is_checked = 1 THEN 'Checked' ELSE 'Not Checked' END) ORDER BY eci.display_order ASC, eci.id ASC SEPARATOR '\n') AS checklist_items,
                COALESCE(SUM(swc.awarded_points), 0) AS total_score
             FROM student_weekly_checklist swc
             JOIN evaluation_checklist_items eci ON eci.id = swc.checklist_item_id
             WHERE swc.student_user_id = :student_user_id
               AND swc.semester_id = :semester_id
             GROUP BY swc.week_start
             ORDER BY swc.week_start ASC"
        );
        $checklistStmt->execute([
            'student_user_id' => $detailStudentId,
            'semester_id' => $detailSemesterId,
        ]);
        $checklistRows = $checklistStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<?php renderCoordinatorPortalStart(
    $user,
    'history',
    'History',
    $context,
    '<a href="' . htmlspecialchars(appUrl('coordinator/dashboard.php'), ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Student List</a>'
); ?>

<section data-reveal class="mb-6">
    <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">History</h1>
    <p class="mt-3 text-base text-slate-500 sm:text-lg">Review archived practicum records from completed semesters without affecting the active semester.</p>
</section>

<form method="get" data-reveal class="mb-6 rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
    <div class="grid gap-3 lg:grid-cols-4">
        <select name="school_year_id" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900">
            <option value="0">All School Years</option>
            <?php foreach ($schoolYears as $schoolYear): ?>
                <option value="<?= (int) $schoolYear['id'] ?>" <?= (int) $schoolYear['id'] === $selectedSchoolYearId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $schoolYear['year_label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="semester_id" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900">
            <option value="0">All Semesters</option>
            <?php foreach ($semesters as $semester): ?>
                <option value="<?= (int) $semester['id'] ?>" <?= (int) $semester['id'] === $selectedSemesterId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($semester['year_label'] . ' - ' . $semester['semester_name'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="partnered_school_id" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900">
            <option value="0">All Partnered Schools</option>
            <?php foreach ($schools as $school): ?>
                <option value="<?= (int) $school['id'] ?>" <?= (int) $school['id'] === $selectedSchoolId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $school['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search Student Name / School ID" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900">
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="submit" class="rounded-2xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-800">Apply Filters</button>
        <a href="<?= htmlspecialchars(appUrl('coordinator/history.php')) ?>" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">Reset</a>
    </div>
</form>

<?php if ($detail !== null): ?>
    <?php
    $detailName = historyFullName($detail);
    $targetHours = 180.0;
    $detailRows = array_filter($historyRows, static fn(array $row): bool =>
        (int) $row['student_user_id'] === $detailStudentId
        && (int) $row['semester_id'] === $detailSemesterId
        && (int) $row['geofence_id'] === $detailSchoolId
    );
    $detailSummary = $detailRows !== [] ? array_values($detailRows)[0] : null;
    $renderedHours = (float) ($detailSummary['rendered_hours'] ?? 0);
    $daysPresent = (int) ($detailSummary['days_present'] ?? 0);
    $semesterStart = (string) (practicumEffectiveStart((string) ($detail['practicum_start_date'] ?? '')) ?: $detail['semester_start_date']);
    $expectedDays = historyWeekdaysBetween($semesterStart, (string) $detail['semester_end_date']);
    $daysAbsent = max(0, $expectedDays - $daysPresent);
    $completionPercent = $targetHours > 0 ? min(100, ($renderedHours / $targetHours) * 100) : 0;
    ?>
    <section data-reveal class="mb-6 rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-slate-950"><?= htmlspecialchars($detailName) ?></h2>
                <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars((string) $detail['school_id']) ?> | <?= htmlspecialchars((string) $detail['school_name']) ?></p>
            </div>
            <a href="<?= htmlspecialchars(historyBaseQuery(['student_id' => 0, 'detail_semester_id' => 0, 'detail_school_id' => 0])) ?>" class="rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">Close Details</a>
        </div>
        <div class="mt-5 grid gap-3 md:grid-cols-3">
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Coordinator</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars(trim((string) (($detail['coordinator_last_name'] ?? '') . ', ' . ($detail['coordinator_first_name'] ?? ''))) ?: 'Coordinator') ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">School Year</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) $detail['year_label']) ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Semester</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) $detail['semester_name']) ?></div></div>
        </div>
    </section>

    <section class="mb-6 grid gap-4 md:grid-cols-5">
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Days Present</p><p class="mt-2 text-2xl font-bold"><?= $daysPresent ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Days Absent</p><p class="mt-2 text-2xl font-bold"><?= $daysAbsent ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Rendered Hours</p><p class="mt-2 text-2xl font-bold"><?= coordinatorFormatHours($renderedHours) ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Target Hours</p><p class="mt-2 text-2xl font-bold"><?= coordinatorFormatHours($targetHours) ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Completion</p><p class="mt-2 text-2xl font-bold"><?= number_format($completionPercent, 0) ?>%</p></div>
    </section>

    <section class="mb-6 grid gap-6 xl:grid-cols-2">
        <article class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
            <h3 class="text-xl font-bold text-slate-950">Weekly Attendance</h3>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Week</th><th class="px-4 py-3">Range</th><th class="px-4 py-3">Present</th><th class="px-4 py-3">Hours</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($weeklyAttendance as $week): ?>
                            <tr><td class="px-4 py-3 font-semibold"><?= htmlspecialchars((string) $week['label']) ?></td><td class="px-4 py-3"><?= htmlspecialchars((string) ($week['start'] . ' to ' . $week['end'])) ?></td><td class="px-4 py-3"><?= (int) $week['present_days'] ?></td><td class="px-4 py-3"><?= coordinatorFormatHours((float) $week['hours']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
            <h3 class="text-xl font-bold text-slate-950">Journal History</h3>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Week</th><th class="px-4 py-3">Submitted</th><th class="px-4 py-3">Score</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Feedback</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($journalRows as $journal): ?>
                            <tr><td class="px-4 py-3"><?= htmlspecialchars((string) $journal['week_start']) ?></td><td class="px-4 py-3"><?= !empty($journal['submitted_at']) ? htmlspecialchars((new DateTimeImmutable((string) $journal['submitted_at']))->format('M j, Y')) : '--' ?></td><td class="px-4 py-3 font-semibold"><?= (int) ($journal['points_awarded'] ?? 0) ?> / <?= max(1, (int) ($journal['max_points'] ?? 50)) ?></td><td class="px-4 py-3"><?= coordinatorTopBadge(ucwords((string) $journal['status']), 'blue') ?></td><td class="px-4 py-3 text-slate-600"><?= nl2br(htmlspecialchars((string) ($journal['coordinator_feedback'] ?? ''))) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if ($journalRows === []): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No journal history found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section class="mb-6 rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
        <h3 class="text-xl font-bold text-slate-950">Checklist History</h3>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Week</th><th class="px-4 py-3">Checklist Items</th><th class="px-4 py-3">Total Score</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($checklistRows as $checklist): ?>
                        <tr><td class="px-4 py-3 font-semibold"><?= htmlspecialchars((string) $checklist['week_start']) ?></td><td class="px-4 py-3 whitespace-pre-line text-slate-600"><?= htmlspecialchars((string) $checklist['checklist_items']) ?></td><td class="px-4 py-3 font-semibold"><?= (int) $checklist['total_score'] ?> pts</td></tr>
                    <?php endforeach; ?>
                    <?php if ($checklistRows === []): ?><tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">No checklist history found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php elseif ($detailStudentId > 0): ?>
    <section class="mb-6 rounded-[28px] border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">
        Archived student history could not be found for this coordinator.
    </section>
<?php endif; ?>

<section data-reveal class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
    <div class="flex flex-col gap-2 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-slate-950">Archived Student Records</h2>
            <p class="mt-1 text-sm text-slate-500">Showing <?= $totalRows === 0 ? 0 : ($offset + 1) ?> to <?= min($offset + $pageSize, $totalRows) ?> of <?= $totalRows ?> archived records.</p>
        </div>
    </div>
    <div class="scroll-slim overflow-x-auto">
        <table class="min-w-full text-left">
            <thead class="bg-slate-50/80">
                <tr class="text-xs uppercase tracking-[0.16em] text-slate-500">
                    <th class="px-5 py-4 font-semibold">Student Name</th>
                    <th class="px-4 py-4 font-semibold">School ID</th>
                    <th class="px-4 py-4 font-semibold">Partnered School</th>
                    <th class="px-4 py-4 font-semibold">School Year</th>
                    <th class="px-4 py-4 font-semibold">Semester</th>
                    <th class="px-4 py-4 font-semibold">Days Present</th>
                    <th class="px-4 py-4 font-semibold">Rendered Hours</th>
                    <th class="px-4 py-4 font-semibold">Journal Completion</th>
                    <th class="px-4 py-4 font-semibold">Checklist Score</th>
                    <th class="px-4 py-4 font-semibold">Final Status</th>
                    <th class="px-4 py-4 font-semibold">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($historyRows as $row): ?>
                    <?php
                    $journalsRequired = 16;
                    $status = historyFinalStatus(
                        (float) ($row['rendered_hours'] ?? 0),
                        (int) ($row['journals_done'] ?? 0),
                        $journalsRequired,
                        (int) ($row['checklist_done'] ?? 0),
                        (int) ($row['checklist_total'] ?? 0)
                    );
                    ?>
                    <tr class="transition hover:bg-slate-50/80">
                        <td class="px-5 py-4 font-semibold text-slate-900"><?= htmlspecialchars(historyFullName($row)) ?></td>
                        <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($row['school_id'] ?? 'N/A')) ?></td>
                        <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($row['school_name'] ?? 'N/A')) ?></td>
                        <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($row['year_label'] ?? 'N/A')) ?></td>
                        <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($row['semester_name'] ?? 'N/A')) ?></td>
                        <td class="px-4 py-4 font-semibold text-slate-900"><?= (int) ($row['days_present'] ?? 0) ?></td>
                        <td class="px-4 py-4 font-semibold text-slate-900"><?= coordinatorFormatHours((float) ($row['rendered_hours'] ?? 0), true) ?></td>
                        <td class="px-4 py-4 font-semibold text-slate-900"><?= (int) ($row['journals_done'] ?? 0) ?>/<?= $journalsRequired ?></td>
                        <td class="px-4 py-4 font-semibold text-slate-900"><?= (int) ($row['checklist_score'] ?? 0) ?> pts</td>
                        <td class="px-4 py-4"><?= historyStatusBadge($status) ?></td>
                        <td class="px-4 py-4">
                            <a href="<?= htmlspecialchars(historyBaseQuery([
                                'student_id' => (int) $row['student_user_id'],
                                'detail_semester_id' => (int) $row['semester_id'],
                                'detail_school_id' => (int) $row['geofence_id'],
                            ])) ?>" class="inline-flex rounded-2xl bg-brand-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-800">View History</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($historyRows === []): ?>
                    <tr>
                        <td colspan="11" class="px-5 py-12 text-center text-sm text-slate-500">No archived student records found for the selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-500">Page <?= $page ?> of <?= $totalPages ?></p>
        <div class="flex gap-2">
            <a href="<?= htmlspecialchars(historyBaseQuery(['page' => max(1, $page - 1)])) ?>" class="rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 <?= $page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>">Previous</a>
            <a href="<?= htmlspecialchars(historyBaseQuery(['page' => min($totalPages, $page + 1)])) ?>" class="rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>">Next</a>
        </div>
    </div>
</section>

<?php renderCoordinatorPortalEnd(); ?>
