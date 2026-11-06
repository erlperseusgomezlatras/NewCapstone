<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/CoordinatorReportService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$coordinatorUserId = (int) ($user['id'] ?? 0);
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$reportService = new CoordinatorReportService($pdo);
$context = $attendanceService->getCoordinatorSectionContext($coordinatorUserId) ?? [];

$search = trim((string) ($_GET['search'] ?? ''));
$selectedSemesterId = isset($_GET['semester_id']) ? max(0, (int) $_GET['semester_id']) : 0;
$selectedSectionCode = trim((string) ($_GET['section_code'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 10;

$semesters = $reportService->listSemesters($coordinatorUserId);
if ($selectedSemesterId <= 0 && $context !== []) {
    $selectedSemesterId = (int) ($context['semester_id'] ?? 0);
}
$selectedSemester = null;
foreach ($semesters as $semester) {
    if ((int) ($semester['id'] ?? 0) === $selectedSemesterId) {
        $selectedSemester = $semester;
        break;
    }
}
$practicumStartDate = trim((string) ($selectedSemester['practicum_start_date'] ?? ''));
if ($practicumStartDate === '') {
    $practicumStartDate = trim((string) ($selectedSemester['semester_start_date'] ?? ''));
}
$journalWeeksToDisplay = $selectedSemester !== null
    ? practicumJournalWeeksToDisplay(
        $practicumStartDate,
        null,
        CoordinatorReportService::JOURNALS_REQUIRED,
        (string) ($selectedSemester['semester_end_date'] ?? ''),
        (string) ($selectedSemester['practicum_status'] ?? $selectedSemester['semester_status'] ?? '')
    )
    : 0;
$sectionStmt = $pdo->prepare("SELECT DISTINCT TRIM(COALESCE(up.original_section, '')) AS section_code
    FROM student_geofence_assignments sga
    JOIN users u ON u.id = sga.student_user_id
    JOIN user_profiles up ON up.user_id = u.id
    WHERE sga.coordinator_user_id = :coordinator_user_id
      AND sga.semester_id = :semester_id
      AND sga.assignment_status = 'active'
      AND TRIM(COALESCE(up.original_section, '')) <> ''
    ORDER BY section_code");
$sectionStmt->execute(['coordinator_user_id' => $coordinatorUserId, 'semester_id' => $selectedSemesterId]);
$sections = $sectionStmt->fetchAll(PDO::FETCH_COLUMN);

$result = $reportService->fetchReportRows(
    $coordinatorUserId,
    $selectedSemesterId,
    0,
    $search,
    '',
    'name',
    $page,
    $pageSize,
    $selectedSectionCode
);
$rows = $result['rows'];
$totalRows = (int) $result['total'];
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$offset = ($page - 1) * $pageSize;
$journalsRequired = CoordinatorReportService::JOURNALS_REQUIRED;
$targetHours = CoordinatorReportService::TARGET_HOURS;
$exportParams = [
    'semester_id' => $selectedSemesterId,
    'search' => $search,
    'section_code' => $selectedSectionCode,
];
$exportExcelUrl = appUrl('api/download_coordinator_report_excel.php?' . http_build_query($exportParams));
$exportPdfUrl = appUrl('api/download_coordinator_report_pdf.php?' . http_build_query($exportParams));

function reportsBaseQuery(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || $value === 0 || $value === '0') {
            unset($query[$key]);
        }
    }

    return '?' . http_build_query($query);
}

renderCoordinatorPortalStart($user, 'reports', 'Student Practicum Reports', $context, '', true);
?>

<section data-reveal class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
  <div>
    <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Student Practicum Reports</h1>
    <p class="mt-3 text-base text-slate-500 sm:text-lg">View and monitor the overall practicum performance of your assigned students.</p>
  </div>
  <div class="flex flex-wrap gap-2 sm:pt-2">
    <a href="<?= htmlspecialchars($exportExcelUrl) ?>" class="inline-flex items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">Export Excel</a>
    <a href="<?= htmlspecialchars($exportPdfUrl) ?>" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Export PDF</a>
  </div>
</section>

<form method="get" data-reveal class="mb-6 rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
    <div class="grid gap-3 lg:grid-cols-4">
        <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search Student Name / Student ID" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900">
        <select name="semester_id" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900">
            <option value="0">All Semesters</option>
            <?php foreach ($semesters as $semester): ?>
                <option value="<?= (int) $semester['id'] ?>" <?= (int) $semester['id'] === $selectedSemesterId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($semester['year_label'] . ' - ' . $semester['semester_name'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="section_code" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900">
            <option value="">All Sections</option>
            <?php foreach ($sections as $section): ?>
                <option value="<?= htmlspecialchars((string) $section) ?>" <?= $selectedSectionCode === (string) $section ? 'selected' : '' ?>><?= htmlspecialchars((string) $section) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="rounded-2xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-800">Apply Filters</button>
            <a href="<?= htmlspecialchars(appUrl('coordinator/reports.php')) ?>" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">Reset</a>
        </div>
    </div>
</form>

<section data-reveal class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
    <div class="border-b border-slate-100 px-5 py-5">
        <h2 class="text-2xl font-bold tracking-tight text-slate-950">Semester Overview</h2>
        <p class="mt-1 text-sm text-slate-500">
            Showing <?= $totalRows === 0 ? 0 : ($offset + 1) ?> to <?= min($offset + $pageSize, $totalRows) ?> of <?= $totalRows ?> student report(s).
        </p>
        <p class="mt-1 text-xs text-slate-500">
            <?= $journalWeeksToDisplay > 0
                ? 'Journal scores and compliance show weeks reached so far (J1–J' . $journalWeeksToDisplay . '). Later weeks appear as practicum progresses.'
                : 'Journal scores will appear when practicum begins.' ?>
            A semester requires <?= $journalsRequired ?> journals in total.
        </p>
    </div>
    <div class="overflow-x-auto">
        <table style="min-width: <?= 1100 + ($journalWeeksToDisplay * 58) ?>px" class="w-full border-separate border-spacing-0 text-left text-sm">
            <thead class="bg-slate-50/80">
                <tr class="text-[11px] uppercase tracking-wide text-slate-500">
                    <th class="sticky left-0 z-10 border-b border-slate-200 bg-slate-50/95 px-4 py-3 font-semibold shadow-[2px_0_4px_rgba(15,23,42,0.08)]">Student Name</th>
                    <?php for ($week = 1; $week <= $journalWeeksToDisplay; $week++): ?><th class="px-2 py-3 text-center font-semibold">J<?= $week ?> Score</th><?php endfor; ?>
                    <th class="px-4 py-3 font-semibold">Compliance</th>
                    <th class="px-4 py-3 font-semibold">Rendered Hours</th>
                    <th class="px-4 py-3 font-semibold">Present Days</th>
                    <th class="px-4 py-3 font-semibold">Checklist Average</th>
                    <th class="px-4 py-3 font-semibold">Evaluation Average</th>
                    <th class="px-4 py-3 font-semibold">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($rows as $row): ?>
                    <?php
                    $journalsApprovedToDate = 0;
                    for ($week = 1; $week <= $journalWeeksToDisplay; $week++) {
                        if ((int) ($row['j' . $week] ?? 0) === 1) {
                            $journalsApprovedToDate++;
                        }
                    }
                    $journalComplianceLabel = $journalWeeksToDisplay > 0
                        ? $journalsApprovedToDate . ' / ' . $journalWeeksToDisplay . ' (' . round(($journalsApprovedToDate / $journalWeeksToDisplay) * 100) . '%)'
                        : 'Not started';
                    ?>
                    <tr class="transition hover:bg-slate-50/80" data-journal-week-statuses="<?= htmlspecialchars(json_encode($row['journal_week_statuses'] ?? []), ENT_QUOTES, 'UTF-8') ?>" data-assigned-schools="<?= htmlspecialchars(json_encode($row['assigned_schools'] ?? []), ENT_QUOTES, 'UTF-8') ?>">
                        <td class="sticky left-0 z-10 border-b border-slate-100 bg-white px-4 py-3 font-semibold text-slate-900 shadow-[2px_0_4px_rgba(15,23,42,0.08)]">
                            <?php $section = trim((string) ($row['section_code'] ?? $row['section_name'] ?? '')); ?>
                            <div><?= htmlspecialchars(CoordinatorReportService::fullName($row)) ?><?php if ($section !== ''): ?> <span class="font-normal text-slate-500">- <?= htmlspecialchars($section) ?></span><?php endif; ?></div>
                            <div class="mt-1 break-all text-xs font-medium text-slate-500">
                                <?= htmlspecialchars((string) ($row['email'] ?? '')) ?>
                                <?php if (!empty($row['school_id'])): ?>
                                    <span class="text-slate-400"> - (<?= htmlspecialchars((string) $row['school_id']) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2 space-y-0.5 text-xs font-medium text-slate-600">
                                <?php if (!empty($row['assigned_schools'])): ?>
                                    <?php foreach ($row['assigned_schools'] as $assignedSchool): ?>
                                        <div><?= htmlspecialchars((string) ($assignedSchool['school_name'] ?? '')) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div><?= htmlspecialchars((string) ($row['school_name'] ?? 'No partnered school')) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php for ($week = 1; $week <= $journalWeeksToDisplay; $week++): ?><?php $journalScore = $row['journal_week_scores'][$week] ?? ['points_awarded' => 0, 'max_points' => 50]; ?><td class="px-2 py-3 text-center font-semibold text-slate-800"><?= (int) $journalScore['points_awarded'] ?> / <?= (int) $journalScore['max_points'] ?></td><?php endfor; ?>
                        <td class="px-4 py-3 font-semibold text-slate-900"><?= htmlspecialchars($journalComplianceLabel) ?></td>
                        <td class="px-4 py-3 font-semibold text-slate-900"><?= coordinatorFormatHours((float) ($row['rendered_hours'] ?? 0)) ?> / 180 hrs</td>
                        <td class="px-4 py-3 font-semibold text-slate-900"><?= (int) ($row['days_present'] ?? 0) ?></td>
                        <td class="px-4 py-3 font-semibold text-slate-900"><?= ($row['checklist_average'] ?? null) !== null ? htmlspecialchars((string) $row['checklist_average']) : 'N/A' ?></td>
                        <td class="px-4 py-3 font-semibold text-slate-900"><?= ($row['evaluation_average'] ?? null) !== null ? htmlspecialchars((string) $row['evaluation_average']) : 'N/A' ?></td>
                        <td class="px-4 py-3">
                            <a href="<?= htmlspecialchars(appUrl('coordinator/view_report.php?' . http_build_query([
                                'student_id' => (int) $row['student_user_id'],
                                'semester_id' => (int) $row['semester_id'],
                                'school_id' => (int) $row['geofence_id'],
                            ]))) ?>" class="inline-flex rounded-xl bg-brand-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-800">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="<?= 8 + $journalWeeksToDisplay ?>" class="px-3 py-12 text-center text-sm text-slate-500">No student reports found for the selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-500">Page <?= $page ?> of <?= $totalPages ?></p>
        <div class="flex gap-2">
            <a href="<?= htmlspecialchars(reportsBaseQuery(['page' => max(1, $page - 1)])) ?>" class="rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 <?= $page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>">Previous</a>
            <a href="<?= htmlspecialchars(reportsBaseQuery(['page' => min($totalPages, $page + 1)])) ?>" class="rounded-2xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50' ?>">Next</a>
        </div>
    </div>
</section>

<script>
document.querySelectorAll('[data-journal-week-statuses]').forEach(function (row) {
    let statuses = {};
    try {
        statuses = JSON.parse(row.dataset.journalWeekStatuses || '{}');
    } catch (error) {
        return;
    }
    for (let week = 1; week <= <?= (int) $journalWeeksToDisplay ?>; week++) {
        if (!statuses[week] || statuses[week] === 'upcoming') {
            const cell = row.children[Number(week)];
            if (cell) cell.textContent = '';
        }
    }
});

</script>

<?php renderCoordinatorPortalEnd(); ?>
