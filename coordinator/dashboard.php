<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/ConcernService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$coordinatorUserId = (int) ($user['id'] ?? 0);
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$concernService = new ConcernService($pdo);
$context = $attendanceService->getCoordinatorSectionContext($coordinatorUserId) ?? [];
$assignedSchools = $attendanceService->getCoordinatorAssignedSchools($coordinatorUserId);
if ($assignedSchools === [] && $context !== [] && !empty($context['school_name'])) {
    $assignedSchools[] = [
        'school_name' => $context['school_name'],
        'school_address' => $context['school_address'] ?? '',
        'deployment_type' => $context['deployment_type'] ?? '',
    ];
}
$flash = coordinatorGetFlash();
$assignedSchoolType = (string) ($context['deployment_type'] ?? '');
$assignedSchoolTypeLabel = $assignedSchoolType === 'public_school'
    ? 'Public'
    : ($assignedSchoolType === 'private_school' ? 'Private' : 'Not assigned');
$hasAssignedSchool = $context !== [] && (int) ($context['geofence_id'] ?? 0) > 0;
$hasActiveSemester = ((string) ($context['semester_status'] ?? '')) === 'active'
    && ((string) ($context['year_status'] ?? '')) === 'active';
$incompleteAttendance = [];
if ($hasActiveSemester && !empty($context['semester_id'])) {
    try {
        $incaStmt = $pdo->prepare(
            "SELECT ar.id, ar.attendance_date, ar.time_in, up.first_name, up.last_name, u.school_id
             FROM attendance_records ar
             JOIN student_school_deployments d
               ON d.student_user_id = ar.student_user_id
              AND d.semester_id = ar.semester_id
              AND d.coordinator_user_id = :coordinator_user_id
             JOIN users u ON u.id = ar.student_user_id
             JOIN user_profiles up ON up.user_id = u.id
             WHERE ar.semester_id = :semester_id
               AND ar.attendance_status = 'INCA'
             ORDER BY ar.attendance_date ASC, up.last_name ASC"
        );
        $incaStmt->execute(['coordinator_user_id' => $coordinatorUserId, 'semester_id' => (int) $context['semester_id']]);
        $incompleteAttendance = $incaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Unable to load INCA review queue: ' . $e->getMessage());
    }
}

$selectedWeek = coordinatorSelectedWeek($context, isset($_GET['week_start']) ? (string) $_GET['week_start'] : null);
$weekOptions = coordinatorWeekOptions($context);
$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$overrideWeekOptions = array_values(array_filter(
    $weekOptions,
    static fn (array $week): bool => (string) ($week['start'] ?? '') <= $todayDate
));
$overrideSelectedWeekStart = (string) ($selectedWeek['start'] ?? '');
if (!in_array($overrideSelectedWeekStart, array_column($overrideWeekOptions, 'start'), true)) {
    $overrideSelectedWeekStart = (string) ($overrideWeekOptions[count($overrideWeekOptions) - 1]['start'] ?? '');
}
$dashboard = $hasAssignedSchool && $hasActiveSemester && $selectedWeek !== null
    ? $attendanceService->getCoordinatorDashboardData($coordinatorUserId, $todayDate, $selectedWeek['start'], $selectedWeek['end'])
    : [
        'context' => $context !== [] ? $context : null,
        'summary' => ['active_now' => 0, 'logged_today' => 0, 'completed_8h' => 0, 'total_students' => 0],
        'live_section' => null,
        'recent_logged_in' => [],
        'weekly_rows' => [],
    ];
$studentSearch = trim((string) ($_GET['student_search'] ?? ''));
$selectedStudentSection = trim((string) ($_GET['section_code'] ?? ''));
$selectedStatus = trim((string) ($_GET['status'] ?? ''));
if (!in_array($selectedStatus, ['Critical', 'Absent', 'Completed'], true)) {
    $selectedStatus = '';
}
$studentSections = [];
foreach (($dashboard['weekly_rows'] ?? []) as $studentRow) {
    $sectionCode = trim((string) ($studentRow['section_code'] ?? ''));
    $sectionName = trim((string) ($studentRow['section_name'] ?? ''));
    if ($sectionCode !== '' || $sectionName !== '') {
        $studentSections[$sectionCode !== '' ? $sectionCode : $sectionName] = $sectionName !== '' ? $sectionName : $sectionCode;
    }
}
$filteredWeeklyRows = array_values(array_filter(
    $dashboard['weekly_rows'] ?? [],
    static function (array $row) use ($studentSearch, $selectedStudentSection, $selectedStatus, $selectedWeek, $todayDate): bool {
        $name = strtolower(trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
        $query = strtolower($studentSearch);
        $sectionCode = trim((string) ($row['section_code'] ?? ''));
        $sectionName = trim((string) ($row['section_name'] ?? ''));
        $section = $sectionCode !== '' ? $sectionCode : $sectionName;
        return ($query === '' || str_contains($name, $query))
            && ($selectedStudentSection === '' || $section === $selectedStudentSection)
            && ($selectedStatus === '' || coordinatorStudentStatusLabel($row, (string) $selectedWeek['start'], $todayDate) === $selectedStatus);
    }
));

function coordinatorStudentWeekDates(string $weekStart): array
{
    $start = new DateTimeImmutable($weekStart);
    return [
        'mon' => $start->format('Y-m-d'),
        'tue' => $start->modify('+1 day')->format('Y-m-d'),
        'wed' => $start->modify('+2 days')->format('Y-m-d'),
        'thu' => $start->modify('+3 days')->format('Y-m-d'),
        'fri' => $start->modify('+4 days')->format('Y-m-d'),
    ];
}

function coordinatorStudentDayState(array $row, string $day, string $date, string $todayDate): string
{
    $hours = (float) ($row[$day . '_hours'] ?? 0);
    $timeIn = (string) ($row[$day . '_time_in'] ?? '');
    $timeOut = (string) ($row[$day . '_time_out'] ?? '');
    $isLate = $timeIn !== '' && (new DateTimeImmutable($timeIn))->format('H:i:s') >= '08:30:00';

    if ($timeIn !== '' && $timeOut === '') {
        if ($date < $todayDate) return 'pending_timeout';
        return $isLate ? 'ongoing_late' : 'ongoing';
    }
    if ($hours >= 8 || ($timeIn !== '' && $timeOut !== '')) {
        return $isLate ? 'completed_late' : 'completed';
    }
    if ($date === $todayDate) {
        return date('H:i:s') < '06:00:00' ? 'future' : 'absent';
    }
    return $date > $todayDate ? 'future' : 'absent';
}

function coordinatorStudentStatusLabel(array $row, string $weekStart, string $todayDate): string
{
    $completedDays = 0;
    $presentDays = 0;
    $absentDays = 0;
    $futureDays = 0;
    $todayState = null;

    foreach (coordinatorStudentWeekDates($weekStart) as $day => $date) {
        $state = coordinatorStudentDayState($row, $day, $date, $todayDate);
        if ($date === $todayDate) $todayState = $state;
        if (in_array($state, ['completed', 'completed_late'], true)) {
            $completedDays++;
            $presentDays++;
        } elseif (in_array($state, ['ongoing', 'ongoing_late', 'pending_timeout'], true)) {
            $presentDays++;
        } elseif ($state === 'future') {
            $futureDays++;
        } else {
            $absentDays++;
        }
    }

    if ($completedDays >= 5) return 'Completed';
    if ($futureDays >= 5 || ($todayState === 'future' && date('H:i:s') < '06:00:00')) return 'Upcoming';
    if ($todayState === 'ongoing_late') return 'Ongoing late';
    if ($todayState === 'ongoing') return 'Ongoing';
    if ($absentDays >= 5 || $presentDays > 0) return 'Critical';
    return 'Absent';
}

function coordinatorStudentStatusBadge(array $row, string $weekStart, string $todayDate): string
{
    return match (coordinatorStudentStatusLabel($row, $weekStart, $todayDate)) {
        'Ongoing late' => coordinatorTopBadge('Ongoing late', 'yellow'),
        'Ongoing' => coordinatorTopBadge('Ongoing', 'blue'),
        'Upcoming' => coordinatorTopBadge('Upcoming', 'slate'),
        'Completed' => coordinatorTopBadge('Completed', 'green'),
        'Critical' => coordinatorTopBadge('Critical', 'red'),
        default => coordinatorTopBadge('Absent', 'red'),
    };
}

function coordinatorConcernButton(int $studentUserId, int $pendingCount = 0, ?string $weekStart = null): string
{
    if ($pendingCount <= 0) {
        return '';
    }
    $label = 'View Concern (' . $pendingCount . ')';

    $query = ['student_id' => $studentUserId];
    if ($weekStart !== null && $weekStart !== '') {
        $query['week_start'] = $weekStart;
    }

    return '<a href="' . htmlspecialchars(appUrl('coordinator/concerns.php?' . http_build_query($query)), ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl border border-rose-300 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-400 hover:bg-rose-100">' . htmlspecialchars($label) . '</a>';
}

function coordinatorJournalStatusBadge(?string $status): string
{
    return match ((string) $status) {
        'pending' => coordinatorTopBadge('Pending', 'blue'),
        'reviewed' => coordinatorTopBadge('Reviewed', 'orange'),
        'approved' => coordinatorTopBadge('Approved', 'green'),
        'declined' => coordinatorTopBadge('Declined', 'red'),
        'resubmitted' => coordinatorTopBadge('Resubmitted', 'blue'),
        default => coordinatorTopBadge('Missing', 'red'),
    };
}
?>
<?php renderCoordinatorPortalStart(
    $user,
    'dashboard',
    'Coordinator Student List',
    $context,
    '',
    true
); ?>

<?php if ($flash): ?>
    <section data-reveal class="mb-6 rounded-[28px] border px-5 py-4 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </section>
<?php endif; ?>

<?php if (!$hasAssignedSchool || !$hasActiveSemester || $selectedWeek === null): ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <h1 class="text-3xl font-bold text-slate-900">Student List</h1>
        <?php if (!$hasAssignedSchool): ?>
            <p class="mt-3 text-slate-500">No partnered school is assigned to your coordinator account yet.</p>
            <p class="mt-1 text-slate-500">Please contact the Head Teacher.</p>
        <?php elseif ($hasActiveSemester && $selectedWeek === null): ?>
            <p class="mt-3 text-slate-500">Practicum has not started yet.</p>
            <p class="mt-1 text-slate-500">Start Date: <?= htmlspecialchars(!empty($context['practicum_start_date']) ? date('F j, Y', strtotime((string) $context['practicum_start_date'])) : 'Not set') ?></p>
        <?php else: ?>
            <p class="mt-3 text-slate-500">No active semester is available yet.</p>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section data-reveal class="mb-5 flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
        <div class="flex items-center gap-4"><div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-800"><svg viewBox="0 0 24 24" fill="currentColor" class="h-9 w-9"><path d="M12 2 1 8l11 6 9-4.91V17h2V8L12 2Zm-7 9.18V16l7 4 7-4v-4.82l-7 3.82-7-3.82Z"/></svg></div><div><h1 class="text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">Student List</h1><p class="mt-1 text-sm text-slate-500 sm:text-base">Monitor students under your assigned school for the selected week.</p></div></div>
        <form method="get" class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
            <label for="week_start" class="col-span-2 self-center text-sm font-semibold text-slate-700 sm:col-span-1">Week:</label>
            <select id="week_start" name="week_start" class="min-w-32 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <?php foreach ($weekOptions as $option): ?>
                    <option value="<?= htmlspecialchars((string) $option['start']) ?>" <?= $option['start'] === $selectedWeek['start'] ? 'selected' : '' ?>><?= htmlspecialchars(practicumWeekFilterLabel($option)) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="student_search" value="<?= htmlspecialchars($studentSearch) ?>" placeholder="Search student name or ID..." class="col-span-2 min-w-56 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100 sm:col-span-1">
            <select name="section_code" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100"><option value="">All Sections</option><?php foreach ($studentSections as $sectionCode => $sectionName): ?><option value="<?= htmlspecialchars($sectionCode) ?>" <?= $sectionCode === $selectedStudentSection ? 'selected' : '' ?>><?= htmlspecialchars($sectionName) ?></option><?php endforeach; ?></select>
            <select name="status" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none focus:border-brand-500 focus:ring-4 focus:ring-emerald-100"><option value="">All Statuses</option><?php foreach (['Critical', 'Absent', 'Completed'] as $statusOption): ?><option value="<?= htmlspecialchars($statusOption) ?>" <?= $selectedStatus === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars($statusOption) ?></option><?php endforeach; ?></select>
            <button type="submit" class="rounded-2xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-800">View</button>
        </form>
    </section>

    <section class="mb-6 grid gap-4 xl:grid-cols-12">
        <?php $assignedSchool = $assignedSchools[0] ?? []; $schoolType = (string) ($assignedSchool['deployment_type'] ?? ''); $schoolTypeLabel = $schoolType === 'public_school' ? 'Public' : ($schoolType === 'private_school' ? 'Private' : 'Not assigned'); ?>
        <article data-reveal class="relative overflow-hidden rounded-[24px] border border-slate-200 bg-gradient-to-br from-white via-white to-emerald-50 p-6 shadow-panel xl:col-span-5"><div class="relative z-10 max-w-[78%]"><p class="text-xs font-bold uppercase tracking-widest text-slate-500">Your Assigned School</p><h2 class="mt-2 text-2xl font-extrabold leading-tight text-slate-950"><?= htmlspecialchars((string) ($assignedSchool['school_name'] ?? 'No assigned school')) ?></h2><p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars((string) ($assignedSchool['school_address'] ?? '')) ?></p><span class="mt-4 inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">School Type: <?= htmlspecialchars($schoolTypeLabel) ?></span></div><div class="pointer-events-none absolute -bottom-8 -right-5 text-emerald-800/10"><svg viewBox="0 0 24 24" fill="currentColor" class="h-48 w-48"><path d="M12 3 1 9l11 6 9-4.91V17h2V9L12 3Zm-7 9v5l7 4 7-4v-5l-7 3.82L5 12Z"/></svg></div></article>
        <div class="grid gap-4 sm:grid-cols-2 xl:col-span-7 xl:grid-cols-4">
            <article data-reveal class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-sm font-semibold text-slate-500">Students</p><p id="summaryTotalStudents" class="mt-2 text-3xl font-extrabold text-slate-950"><?= count($filteredWeeklyRows) ?></p><p class="mt-2 text-xs font-bold text-emerald-700">Live now: <span id="summaryActiveNow"><?= (int) ($dashboard['summary']['active_now'] ?? 0) ?></span></p></article>
            <article data-reveal class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-sm font-semibold text-slate-500">Semester</p><p class="mt-2 text-xl font-extrabold text-slate-950"><?= htmlspecialchars((string) ($context['semester_name'] ?? 'No active semester')) ?></p><p class="mt-2 text-xs text-slate-500"><?= htmlspecialchars((string) ($context['year_label'] ?? '')) ?></p></article>
            <article data-reveal class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-sm font-semibold text-slate-500">Logged In Today</p><p id="summaryLoggedToday" class="mt-2 text-3xl font-extrabold text-slate-950"><?= (int) ($dashboard['summary']['logged_today'] ?? 0) ?></p><p class="mt-2 text-xs text-slate-500">Active time-in sessions</p></article>
            <article data-reveal class="rounded-[22px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-sm font-semibold text-slate-500">Completed 8 Hours</p><p id="summaryCompleted8" class="mt-2 text-3xl font-extrabold text-slate-950"><?= (int) ($dashboard['summary']['completed_8h'] ?? 0) ?></p><p class="mt-2 text-xs text-slate-500">Today</p></article>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-teal-50 px-5 py-3 xl:col-span-12"><p class="font-bold text-emerald-950">🟢 Live Refresh</p><p class="text-sm text-emerald-900">Student hours refresh every 5 seconds while time-in sessions are active.</p></div>
    </section>
    <?php if ($incompleteAttendance !== []): ?>
    <section data-reveal class="mb-6 overflow-hidden rounded-[28px] border border-amber-200 bg-amber-50 shadow-panel">
        <div class="border-b border-amber-200 px-5 py-4">
            <h3 class="text-lg font-bold text-amber-950">Incomplete Attendance Review</h3>
            <p class="mt-1 text-sm text-amber-800">These students timed in but did not time out. Approve credits at most 8.00 hours; reject gives no credit.</p>
        </div>
        <div class="divide-y divide-amber-100">
            <?php foreach ($incompleteAttendance as $record): ?>
            <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="font-semibold text-slate-950"><?= htmlspecialchars(trim((string) $record['last_name'] . ', ' . (string) $record['first_name'])) ?></p>
                    <p class="text-sm text-slate-600"><?= htmlspecialchars((string) $record['school_id']) ?> · <?= htmlspecialchars((new DateTimeImmutable((string) $record['attendance_date']))->format('M j, Y')) ?> · timed in <?= htmlspecialchars((new DateTimeImmutable((string) $record['time_in']))->format('g:i A')) ?></p>
                </div>
                <form method="post" action="<?= htmlspecialchars(appUrl('coordinator/review_incomplete_attendance.php')) ?>" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="attendance_id" value="<?= (int) $record['id'] ?>">
                    <input type="hidden" name="week_start" value="<?= htmlspecialchars((string) ($selectedWeek['start'] ?? '')) ?>">
                    <input class="w-24 rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm" type="number" min="0" max="8" step="0.25" name="verified_hours" value="8">
                    <button class="rounded-xl bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800" type="submit" name="action" value="approve_inca">Approve INCA</button>
                    <button class="rounded-xl border border-rose-300 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50" type="submit" name="action" value="reject_inca">Reject</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section data-reveal class="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-panel">
        <div class="flex items-center gap-2 border-b border-slate-200 px-5">
            <div class="border-b-[3px] border-emerald-700 px-4 py-4 text-sm font-bold text-slate-900">Current Students</div>
            <a href="<?= htmlspecialchars(appUrl('coordinator/weekly_attendance.php?' . http_build_query(['week_start' => (string) $selectedWeek['start'], 'student_search' => $studentSearch, 'section_code' => $selectedStudentSection])), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-4 text-sm font-semibold text-slate-600 hover:text-emerald-800">Weekly Attendance</a>
        </div>
        <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-xl font-bold tracking-tight text-slate-950">Current Students</h3>
                <p class="mt-1 text-sm text-slate-500">Each row is one student under your assigned school for the selected week.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" onclick="window.location.reload()" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" aria-label="Refresh student list">↻ Refresh</button>
            </div>
        </div>
        <div class="scroll-slim overflow-x-auto">
            <table class="min-w-full text-left">
                <thead class="bg-slate-50">
                    <tr class="text-[11px] uppercase tracking-wide text-slate-600">
                        <th class="px-5 py-4 font-semibold">Student</th>
                        <th class="px-4 py-4 font-semibold">School ID</th>
                        <th class="px-4 py-4 font-semibold">Week Hours</th>
                        <th class="px-4 py-4 font-semibold">Journal Score / Status</th>
                        <th class="px-4 py-4 font-semibold">Status</th>
                        <th class="px-4 py-4 font-semibold">Concerns</th>
                        <th class="px-4 py-4 font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody id="coordinatorWeeklyRows" class="divide-y divide-slate-100">
                    <?php foreach ($filteredWeeklyRows as $row): ?>
                        <?php
                        $studentUserId = (int) ($row['student_user_id'] ?? 0);
                        $pendingConcernCount = (int) ($row['pending_concern_count'] ?? 0);
                        $studentFullName = trim((string) (($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')));
                        ?>
                        <tr class="transition hover:bg-emerald-50/40">
                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars($studentFullName) ?></div>
                                <div class="mt-1 text-sm text-slate-500"><?= htmlspecialchars((string) ($row['section_name'] ?: ($row['section_code'] ?? 'No section assigned'))) ?></div>
                                <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string) ($row['email'] ?? '')) ?></div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-700"><?= htmlspecialchars((string) ($row['school_id'] ?? 'N/A')) ?></td>
                            <td class="px-4 py-3 font-semibold text-slate-900"><?= coordinatorFormatHours((float) ($row['total_hours'] ?? 0), true) ?></td>
                            <td class="px-4 py-3">
                                <?= coordinatorJournalStatusBadge((string) ($row['journal_status'] ?? 'missing')) ?>
                                <div class="mt-1 text-xs font-semibold text-slate-700"><?= (int) ($row['journal_points_awarded'] ?? 0) ?> / <?= max(1, (int) ($row['journal_max_points'] ?? 50)) ?> points</div>
                            </td>
                            <td class="px-4 py-3"><?= coordinatorStudentStatusBadge($row, (string) $selectedWeek['start'], $todayDate) ?></td>
                            <td class="px-4 py-3"><?= coordinatorConcernButton($studentUserId, $pendingConcernCount, (string) $selectedWeek['start']) ?></td>
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    data-emergency-open
                                    data-student-id="<?= $studentUserId ?>"
                                    data-student-name="<?= htmlspecialchars($studentFullName, ENT_QUOTES, 'UTF-8') ?>"
                                    data-week-start="<?= htmlspecialchars((string) $selectedWeek['start'], ENT_QUOTES, 'UTF-8') ?>"
                                    class="inline-flex items-center rounded-2xl border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:border-amber-400 hover:bg-amber-100"
                                >
                                    Mark Present
                                </button>
                                <button
                                    type="button"
                                    data-attendance-logs-open
                                    data-student-id="<?= $studentUserId ?>"
                                    data-student-name="<?= htmlspecialchars($studentFullName, ENT_QUOTES, 'UTF-8') ?>"
                                    class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                                >
                                    Attendance Logs
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($filteredWeeklyRows === []): ?>
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center text-sm text-slate-500">No students found for the selected week.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div id="emergencyOverrideModal" class="fixed inset-0 z-40 hidden items-center justify-center bg-slate-950/50 px-4">
        <div class="w-full max-w-xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-2xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-2xl font-bold tracking-tight text-slate-950">Emergency Attendance Override</h3>
                    <p class="mt-2 text-sm text-slate-500">Record an emergency present mark for <span id="emergencyStudentName" class="font-semibold text-slate-800">this student</span>.</p>
                </div>
                <button type="button" data-emergency-close class="rounded-2xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Close</button>
            </div>

            <form method="post" action="<?= htmlspecialchars(appUrl('coordinator/mark_emergency_attendance.php')) ?>" class="mt-6 space-y-4">
                <input type="hidden" name="student_id" id="emergencyStudentId" value="">
                <input type="hidden" name="week_start" value="<?= htmlspecialchars((string) $selectedWeek['start']) ?>">
                <input type="hidden" name="attendance_date" id="attendance_date" value="">

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="emergencyWeekStart" class="mb-2 block text-sm font-semibold text-slate-700">Attendance Week</label>
                        <select id="emergencyWeekStart" name="override_week_start" required class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-amber-500 focus:ring-4 focus:ring-amber-100">
                            <?php foreach ($overrideWeekOptions as $week): ?>
                                <option value="<?= htmlspecialchars((string) $week['start']) ?>" <?= (string) $week['start'] === $overrideSelectedWeekStart ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($week['display_label'] ?? $week['label'] ?? 'Week')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="emergencyWeekday" class="mb-2 block text-sm font-semibold text-slate-700">Absent Day</label>
                        <select id="emergencyWeekday" name="override_weekday" required class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-amber-500 focus:ring-4 focus:ring-amber-100">
                            <option value="0">Monday</option>
                            <option value="1">Tuesday</option>
                            <option value="2">Wednesday</option>
                            <option value="3">Thursday</option>
                            <option value="4">Friday</option>
                        </select>
                    </div>
                </div>

                <p id="emergencySelectedDate" class="text-sm font-medium text-slate-600" aria-live="polite"></p>

                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Select the practicum week and the weekday the student was absent. Future days are unavailable. This records a full 8.00-hour day from 08:00 to 16:00 and prevents duplicate attendance for the selected date.
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <button type="button" data-emergency-close class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">Cancel</button>
                    <button id="emergencyConfirmBtn" type="submit" class="rounded-2xl bg-amber-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-45">Confirm Override</button>
                </div>
            </form>
        </div>
    </div>
    <div id="attendanceLogsModal" class="fixed inset-0 z-40 hidden items-center justify-center bg-slate-950/50 px-4">
        <div class="w-full max-w-2xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-2xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-2xl font-bold text-slate-950">Attendance Logs</h3>
                    <p class="mt-1 text-sm text-slate-500"><span id="attendanceLogsStudentName">Student</span> · selected week</p>
                </div>
                <button type="button" data-attendance-logs-close class="rounded-2xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Close</button>
            </div>
            <div id="attendanceLogsContent" class="mt-5 overflow-hidden rounded-2xl border border-slate-200">
                <p class="p-6 text-center text-sm text-slate-500">Loading attendance logs...</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
$coordinatorLiveScript = '';
if ($context !== [] && $selectedWeek !== null) {
    $apiUrl = json_encode(appUrl('api/coordinator_live_attendance.php') . '?week_start=' . urlencode((string) $selectedWeek['start']));
    $attendanceLogsApiUrl = json_encode(appUrl('api/coordinator_student_attendance_logs.php'));
    $weekStartJson = json_encode((string) $selectedWeek['start']);
    $concernBaseUrlJson = json_encode(appUrl('coordinator/concerns.php'));
    $studentSearchJson = json_encode($studentSearch);
    $selectedStudentSectionJson = json_encode($selectedStudentSection);
    $selectedStatusJson = json_encode($selectedStatus);
    $todayDateJson = json_encode($todayDate);
    $coordinatorLiveScript = str_replace(['__API_URL__', '__WEEK_START__', '__CONCERN_BASE_URL__', '__STUDENT_SEARCH__', '__STUDENT_SECTION__', '__STATUS__', '__ATTENDANCE_LOGS_API__', '__TODAY_DATE__'], [$apiUrl, $weekStartJson, $concernBaseUrlJson, $studentSearchJson, $selectedStudentSectionJson, $selectedStatusJson, $attendanceLogsApiUrl, $todayDateJson], <<<'HTML'
<script>
    (() => {
      const apiUrl = __API_URL__;
      const weekStart = __WEEK_START__;
      const todayDate = __TODAY_DATE__;
      const concernBaseUrl = __CONCERN_BASE_URL__;
      const apiUrlForAttendanceLogs = __ATTENDANCE_LOGS_API__;
      const studentSearch = String(__STUDENT_SEARCH__ || "").toLowerCase().trim();
      const selectedStudentSection = String(__STUDENT_SECTION__ || "").trim();
      const selectedStatus = String(__STATUS__ || "").trim();
      const rowsEl = document.getElementById("coordinatorWeeklyRows");
      const totalStudentsEl = document.getElementById("summaryTotalStudents");
      const activeNowEl = document.getElementById("summaryActiveNow");
      const loggedTodayEl = document.getElementById("summaryLoggedToday");
      const completed8El = document.getElementById("summaryCompleted8");
      if (!rowsEl) return;

      function badge(label, tone) {
        const map = {
          green: "bg-emerald-100 text-emerald-700 ring-emerald-200",
          blue: "bg-blue-100 text-blue-700 ring-blue-200",
          orange: "bg-amber-50 text-amber-700 ring-amber-100",
          red: "bg-rose-50 text-rose-700 ring-rose-100",
          slate: "bg-slate-100 text-slate-700 ring-slate-200"
        };
        return `<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${map[tone] || map.orange}">${label}</span>`;
      }

      function escapeHtml(value) {
        return String(value ?? "").replace(/[&<>"']/g, (char) => ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "\"": "&quot;",
          "'": "&#039;"
        }[char]));
      }

      function weekDates() {
        const [year, month, day] = weekStart.split("-").map(Number);
        const monday = new Date(year, month - 1, day);
        return ["mon", "tue", "wed", "thu", "fri"].map((name, index) => {
          const date = new Date(monday);
          date.setDate(monday.getDate() + index);
          return { name, value: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}` };
        });
      }

      function currentTimeValue() {
        const now = new Date();
        return `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}:${String(now.getSeconds()).padStart(2, "0")}`;
      }

      function dayState(row, day, dateValue) {
        const hours = Number(row[`${day}_hours`] || 0);
        const timeIn = String(row[`${day}_time_in`] || "");
        const timeOut = String(row[`${day}_time_out`] || "");
        const isLate = timeIn.length >= 19 && timeIn.slice(11, 19) >= "08:30:00";
        if (timeIn && !timeOut) {
          if (dateValue < todayDate) return "pending_timeout";
          return isLate ? "ongoing_late" : "ongoing";
        }
        if (hours >= 8 || (timeIn && timeOut)) return isLate ? "completed_late" : "completed";
        if (dateValue === todayDate) return currentTimeValue() < "06:00:00" ? "future" : "absent";
        return dateValue > todayDate ? "future" : "absent";
      }

      function statusLabel(row) {
        let completedDays = 0;
        let presentDays = 0;
        let absentDays = 0;
        let futureDays = 0;
        let todayState = null;
        weekDates().forEach(({ name, value }) => {
          const state = dayState(row, name, value);
          if (value === todayDate) todayState = state;
          if (["completed", "completed_late"].includes(state)) {
            completedDays += 1;
            presentDays += 1;
          } else if (["ongoing", "ongoing_late", "pending_timeout"].includes(state)) {
            presentDays += 1;
          } else if (state === "future") {
            futureDays += 1;
          } else {
            absentDays += 1;
          }
        });
        if (completedDays >= 5) return "Completed";
        if (futureDays >= 5 || (todayState === "future" && currentTimeValue() < "06:00:00")) return "Upcoming";
        if (todayState === "ongoing_late") return "Ongoing late";
        if (todayState === "ongoing") return "Ongoing";
        if (absentDays >= 5 || presentDays > 0) return "Critical";
        return "Absent";
      }

      function statusBadge(row) {
        const label = statusLabel(row);
        if (label === "Ongoing late") return badge(label, "yellow");
        if (label === "Ongoing") return badge(label, "blue");
        if (label === "Upcoming") return badge(label, "slate");
        if (label === "Completed") return badge(label, "green");
        if (label === "Critical") return badge(label, "red");
        return badge(label, "red");
      }

      function coordinatorFormatHours(hours, withSuffix = true) {
        const totalMinutes = Math.max(0, Math.floor((Number(hours || 0) * 60) + 0.00001));
        const wholeHours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        const formatted = `${wholeHours}.${String(minutes).padStart(2, "0")}`;
        return withSuffix ? `${formatted}h` : formatted;
      }

      function journalBadge(status) {
        if (status === "approved") return badge("Approved", "green");
        if (status === "declined") return badge("Declined", "red");
        if (status === "reviewed") return badge("Reviewed", "orange");
        if (status === "resubmitted") return badge("Resubmitted", "blue");
        if (status === "pending") return badge("Pending", "blue");
        return badge("Missing", "red");
      }

      function concernLink(studentId, pendingCount) {
        const count=Number(pendingCount || 0);
        if(count<=0) return "";
        const label=`View Concern (${count})`;
        return `<a href="${concernBaseUrl}?student_id=${Number(studentId || 0)}&week_start=${encodeURIComponent(weekStart)}" class="inline-flex items-center rounded-2xl border border-rose-300 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-400 hover:bg-rose-100">${escapeHtml(label)}</a>`;
      }

      function filterRows(rows) {
        return rows.filter((row) => {
          const studentName = `${row.first_name || ""} ${row.last_name || ""}`.toLowerCase().trim();
          const section = String(row.section_code || row.section_name || "").trim();
          return (!studentSearch || studentName.includes(studentSearch))
            && (!selectedStudentSection || section === selectedStudentSection)
            && (!selectedStatus || statusLabel(row) === selectedStatus);
        });
      }

      function renderRows(rows) {
        if (!Array.isArray(rows) || rows.length === 0) {
          rowsEl.innerHTML = "<tr><td colspan=\"7\" class=\"px-5 py-12 text-center text-sm text-slate-500\">No students found for the selected week.</td></tr>";
          return;
        }

        rowsEl.innerHTML = rows.map((row) => {
          const hours = Number(row.total_hours || 0);
          const fullName = escapeHtml(`${row.last_name || ""}, ${row.first_name || ""}`.trim());
          const statusHtml = statusBadge(row);
          const buttonLabel = "Mark Present";
          return `<tr class="transition hover:bg-emerald-50/40">
              <td class="px-5 py-3">
                <div class="font-semibold text-slate-900">${fullName}</div>
                <div class="mt-1 text-sm text-slate-500">${escapeHtml(row.section_name || row.section_code || "No section assigned")}</div>
                <div class="mt-1 text-xs text-slate-500">${escapeHtml(row.email || "")}</div>
              </td>
              <td class="px-4 py-3 text-sm text-slate-700">${escapeHtml(row.school_id || "N/A")}</td>
              <td class="px-4 py-3 font-semibold text-slate-900">${coordinatorFormatHours(hours)}</td>
              <td class="px-4 py-3">${journalBadge(String(row.journal_status || "missing"))}<div class="mt-1 text-xs font-semibold text-slate-700">${Number(row.journal_points_awarded || 0)} / ${Math.max(1, Number(row.journal_max_points || 50))} points</div></td>
              <td class="px-4 py-3">${statusHtml}</td>
              <td class="px-4 py-3">${concernLink(row.student_user_id || 0, row.pending_concern_count || 0)}</td>
              <td class="px-4 py-3">
                <button
                  type="button"
                  data-emergency-open
                  data-student-id="${Number(row.student_user_id || 0)}"
                  data-student-name="${fullName}"
                  data-week-start="${escapeHtml(weekStart)}"
                  class="inline-flex items-center rounded-2xl border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:border-amber-400 hover:bg-amber-100"
                >${buttonLabel}</button>
                <button
                  type="button"
                  data-attendance-logs-open
                  data-student-id="${Number(row.student_user_id || 0)}"
                  data-student-name="${fullName}"
                  class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                >Attendance Logs</button>
              </td>
            </tr>`;
        }).join("");
        bindEmergencyButtons();
        bindAttendanceLogButtons();
      }

      const modal = document.getElementById("emergencyOverrideModal");
      const attendanceLogsModal = document.getElementById("attendanceLogsModal");
      const attendanceLogsName = document.getElementById("attendanceLogsStudentName");
      const attendanceLogsContent = document.getElementById("attendanceLogsContent");
      const studentIdInput = document.getElementById("emergencyStudentId");
      const studentNameEl = document.getElementById("emergencyStudentName");
      const dateInput = document.getElementById("attendance_date");
      const emergencyWeekStart = document.getElementById("emergencyWeekStart");
      const emergencyWeekday = document.getElementById("emergencyWeekday");
      const emergencySelectedDate = document.getElementById("emergencySelectedDate");
      const emergencyConfirmBtn = document.getElementById("emergencyConfirmBtn");
      let emergencyStudentId = "";
      let emergencyCreditedDates = new Set();
      let emergencyAttendanceRequest = 0;

      function dateForEmergencySelection() {
        if (!emergencyWeekStart || !emergencyWeekday) return "";
        const [year, month, day] = String(emergencyWeekStart.value || "").split("-").map(Number);
        const weekday = Number(emergencyWeekday.value || 0);
        if (!year || !month || !day || !Number.isInteger(weekday) || weekday < 0 || weekday > 4) return "";
        const selected = new Date(year, month - 1, day + weekday);
        return `${selected.getFullYear()}-${String(selected.getMonth() + 1).padStart(2, "0")}-${String(selected.getDate()).padStart(2, "0")}`;
      }

      function updateEmergencyDateSelection() {
        if (!emergencyWeekday) return;
        const labels = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
        [...emergencyWeekday.options].forEach((option, index) => {
          const [year, month, day] = String(emergencyWeekStart?.value || "").split("-").map(Number);
          const candidate = new Date(year, month - 1, day + index);
          const candidateValue = `${candidate.getFullYear()}-${String(candidate.getMonth() + 1).padStart(2, "0")}-${String(candidate.getDate()).padStart(2, "0")}`;
          const alreadyCredited = emergencyCreditedDates.has(candidateValue);
          option.disabled = candidateValue > todayDate || alreadyCredited;
          option.textContent = `${labels[index]} — ${candidate.toLocaleDateString(undefined, { month: "short", day: "numeric" })}`;
        });
        [...emergencyWeekday.options].forEach((option, index) => {
          const [year, month, day] = String(emergencyWeekStart?.value || "").split("-").map(Number);
          const candidate = new Date(year, month - 1, day + index);
          const candidateValue = `${candidate.getFullYear()}-${String(candidate.getMonth() + 1).padStart(2, "0")}-${String(candidate.getDate()).padStart(2, "0")}`;
          if (emergencyCreditedDates.has(candidateValue)) option.textContent += " (Already credited)";
        });
        if (emergencyWeekday.selectedOptions[0]?.disabled) {
          const firstEligible = [...emergencyWeekday.options].find((option) => !option.disabled);
          if (firstEligible) {
            emergencyWeekday.value = firstEligible.value;
          } else {
            emergencyWeekday.selectedIndex = -1;
          }
        }
        const selectedDate = dateForEmergencySelection();
        const selectedIsEligible = selectedDate !== "" && !emergencyCreditedDates.has(selectedDate) && selectedDate <= todayDate;
        if (dateInput) dateInput.value = selectedIsEligible ? selectedDate : "";
        if (emergencyConfirmBtn) emergencyConfirmBtn.disabled = !selectedIsEligible;
        if (emergencySelectedDate) {
          emergencySelectedDate.textContent = selectedIsEligible
            ? `Selected attendance date: ${new Date(selectedDate + "T00:00:00").toLocaleDateString(undefined, { weekday: "long", year: "numeric", month: "long", day: "numeric" })}`
            : "All eligible weekdays in this week are already credited or still in the future. Choose another week.";
        }
      }

      async function loadEmergencyCreditedDates() {
        if (!emergencyStudentId || !emergencyWeekStart) return;
        const requestId = ++emergencyAttendanceRequest;
        emergencyCreditedDates = new Set();
        if (emergencySelectedDate) emergencySelectedDate.textContent = "Checking credited attendance for this week...";
        if (emergencyConfirmBtn) emergencyConfirmBtn.disabled = true;
        try {
          const url = apiUrlForAttendanceLogs + "?student_id=" + encodeURIComponent(emergencyStudentId) + "&week_start=" + encodeURIComponent(emergencyWeekStart.value);
          const response = await fetch(url, { headers: { Accept: "application/json" } });
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.message || "Unable to load attendance for this week.");
          if (requestId !== emergencyAttendanceRequest) return;
          emergencyCreditedDates = new Set((Array.isArray(data.logs) ? data.logs : [])
            .filter((log) => Number(log.total_hours || 0) > 0 || (log.time_in && log.time_out))
            .map((log) => String(log.attendance_date || "")));
        } catch (_error) {
          if (requestId !== emergencyAttendanceRequest) return;
          emergencyCreditedDates = new Set();
        }
        if (requestId === emergencyAttendanceRequest) updateEmergencyDateSelection();
      }

      function closeEmergencyModal() {
        if (!modal) return;
        modal.classList.add("hidden");
        modal.classList.remove("flex");
      }

      function openEmergencyModal(button) {
        if (!modal || !studentIdInput || !studentNameEl) return;
        emergencyStudentId = String(button.getAttribute("data-student-id") || "");
        studentIdInput.value = emergencyStudentId;
        studentNameEl.textContent = String(button.getAttribute("data-student-name") || "this student");
        const buttonWeekStart = String(button.getAttribute("data-week-start") || "");
        if (emergencyWeekStart && buttonWeekStart && [...emergencyWeekStart.options].some((option) => option.value === buttonWeekStart)) {
          emergencyWeekStart.value = buttonWeekStart;
        }
        loadEmergencyCreditedDates();
        modal.classList.remove("hidden");
        modal.classList.add("flex");
      }

      function bindEmergencyButtons() {
        document.querySelectorAll("[data-emergency-open]").forEach((button) => {
          if (button.dataset.boundEmergency === "1") return;
          button.dataset.boundEmergency = "1";
          button.addEventListener("click", () => openEmergencyModal(button));
        });
      }

      function closeAttendanceLogsModal() {
        if (!attendanceLogsModal) return;
        attendanceLogsModal.classList.add("hidden");
        attendanceLogsModal.classList.remove("flex");
      }

      function formatAttendanceDate(value) {
        if (!value) return "—";
        return new Date(value + "T00:00:00").toLocaleDateString(undefined, {
          year: "numeric", month: "short", day: "numeric"
        });
      }

      function formatAttendanceDateTime(value) {
        if (!value) return "Pending timeout";
        const match = String(value).match(/(?:T|\s)(\d{2}):(\d{2})(?::\d{2})?/);
        if (!match) return String(value);
        const hour24 = Number(match[1]);
        const minute = match[2];
        const suffix = hour24 >= 12 ? "PM" : "AM";
        const hour12 = hour24 % 12 || 12;
        return hour12 + ":" + minute + " " + suffix;
      }

      async function openAttendanceLogsModal(button) {
        if (!attendanceLogsModal || !attendanceLogsContent) return;
        const studentId = button.getAttribute("data-student-id") || "";
        const studentName = button.getAttribute("data-student-name") || "Student";
        if (attendanceLogsName) attendanceLogsName.textContent = studentName;
        attendanceLogsContent.innerHTML = '<p class="p-6 text-center text-sm text-slate-500">Loading attendance logs...</p>';
        attendanceLogsModal.classList.remove("hidden");
        attendanceLogsModal.classList.add("flex");

        try {
          const url = apiUrlForAttendanceLogs + "?student_id=" + encodeURIComponent(studentId) + "&week_start=" + encodeURIComponent(weekStart);
          const response = await fetch(url, { headers: { Accept: "application/json" } });
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.message || "Unable to load attendance logs.");
          const logs = Array.isArray(data.logs) ? data.logs : [];
          attendanceLogsContent.innerHTML = logs.length === 0
            ? '<p class="p-6 text-center text-sm text-slate-500">No attendance logs for the selected week.</p>'
            : '<div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3 font-semibold">Date</th><th class="px-4 py-3 font-semibold">Time In</th><th class="px-4 py-3 font-semibold">Time Out</th><th class="px-4 py-3 font-semibold">Hours</th></tr></thead><tbody class="divide-y divide-slate-100">'
              + logs.map((log) => '<tr><td class="px-4 py-3">' + escapeHtml(formatAttendanceDate(log.attendance_date)) + '</td><td class="px-4 py-3">' + escapeHtml(formatAttendanceDateTime(log.time_in)) + '</td><td class="px-4 py-3">' + escapeHtml(formatAttendanceDateTime(log.time_out)) + '</td><td class="px-4 py-3">' + escapeHtml(coordinatorFormatHours(log.total_hours || 0)) + '</td></tr>').join("")
              + '</tbody></table></div>';
        } catch (error) {
          attendanceLogsContent.innerHTML = '<p class="p-6 text-center text-sm text-rose-600">' + escapeHtml(error.message) + '</p>';
        }
      }

      function bindAttendanceLogButtons() {
        document.querySelectorAll("[data-attendance-logs-open]").forEach((button) => {
          if (button.dataset.boundAttendanceLogs === "1") return;
          button.dataset.boundAttendanceLogs = "1";
          button.addEventListener("click", () => openAttendanceLogsModal(button));
        });
      }

      document.querySelectorAll("[data-emergency-close]").forEach((button) => {
        button.addEventListener("click", closeEmergencyModal);
      });
      document.querySelectorAll("[data-attendance-logs-close]").forEach((button) => {
        button.addEventListener("click", closeAttendanceLogsModal);
      });
      emergencyWeekStart?.addEventListener("change", loadEmergencyCreditedDates);
      emergencyWeekday?.addEventListener("change", updateEmergencyDateSelection);

      modal?.addEventListener("click", (event) => {
        if (event.target === modal) {
          closeEmergencyModal();
        }
      });
      attendanceLogsModal?.addEventListener("click", (event) => {
        if (event.target === attendanceLogsModal) closeAttendanceLogsModal();
      });

      async function refresh() {
        try {
          const res = await fetch(apiUrl, { headers: { Accept: "application/json" } });
          if (!res.ok) return;
          const data = await res.json();
          if (data.error) return;
          const summary = data.summary || {};
          const rows = filterRows(Array.isArray(data.weekly_rows) ? data.weekly_rows : []);
          if (totalStudentsEl) totalStudentsEl.textContent = rows.length;
          if (activeNowEl) activeNowEl.textContent = Number(summary.active_now || 0);
          if (loggedTodayEl) loggedTodayEl.textContent = Number(summary.logged_today || 0);
          if (completed8El) completed8El.textContent = Number(summary.completed_8h || 0);
          renderRows(rows);
        } catch (_err) {
        }
      }

      bindEmergencyButtons();
      bindAttendanceLogButtons();
      updateEmergencyDateSelection();
      refresh();
      window.setInterval(refresh, 5000);
    })();
    </script>
HTML);
}
renderCoordinatorPortalEnd($coordinatorLiveScript);
?>
