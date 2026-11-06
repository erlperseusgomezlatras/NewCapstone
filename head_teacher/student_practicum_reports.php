<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/HeadTeacherReportServiceClean.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$reportService = new HeadTeacherReportService();

$defaultContext = $reportService->getDefaultContext();
$selectedSchoolYearId = max(0, (int) ($_GET['school_year_id'] ?? ($defaultContext['school_year_id'] ?? 0)));
$selectedSemesterNo = max(0, (int) ($_GET['semester_no'] ?? ($defaultContext['semester_no'] ?? 1)));
$viewBy = strtolower(trim((string) ($_GET['view_by'] ?? 'partnered_school')));
if (!in_array($viewBy, ['partnered_school', 'section'], true)) {
    $viewBy = 'partnered_school';
}

$selectedSchoolId = max(0, (int) ($_GET['school_id'] ?? 0));
$selectedSectionCode = trim((string) ($_GET['section_code'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

$report = $reportService->fetchReport([
    'school_year_id' => $selectedSchoolYearId,
    'semester_no' => $selectedSemesterNo,
    'view_by' => $viewBy,
    'school_id' => $selectedSchoolId,
    'section_code' => $selectedSectionCode,
    'search' => $search,
]);

$context = $report['context'] ?? $defaultContext;
$rows = $report['rows'] ?? [];
$reportTrackingStarted = $context !== null && in_array(
    strtolower(trim((string) ($context['practicum_status'] ?? ''))),
    ['ongoing', 'completed', 'ended'],
    true
);
$practicumStartDate = trim((string) ($context['practicum_start_date'] ?? ''));
if ($practicumStartDate === '') {
    $practicumStartDate = trim((string) ($context['semester_start_date'] ?? ''));
}
$journalWeeksToDisplay = $reportTrackingStarted && $context !== null
    ? practicumJournalWeeksToDisplay(
        $practicumStartDate,
        null,
        HeadTeacherReportService::JOURNALS_REQUIRED,
        (string) ($context['semester_end_date'] ?? ''),
        (string) ($context['practicum_status'] ?? '')
    )
    : 0;

if ($context !== null) {
    $selectedSchoolYearId = (int) ($context['school_year_id'] ?? $selectedSchoolYearId);
    $selectedSemesterNo = (int) ($context['semester_no'] ?? $selectedSemesterNo);
}

$semesterId = (int) ($context['semester_id'] ?? 0);
$schoolYears = $reportService->listSchoolYears();
$partneredSchools = $reportService->listPartneredSchools();
$sections = $semesterId > 0 ? $reportService->listSections($semesterId) : [];

if ($viewBy === 'partnered_school' && $selectedSchoolId > 0) {
    $schoolExists = false;
    foreach ($partneredSchools as $school) {
        if ((int) ($school['id'] ?? 0) === $selectedSchoolId) {
            $schoolExists = true;
            break;
        }
    }
    if (!$schoolExists) {
        $selectedSchoolId = 0;
    }
}

if ($viewBy === 'section' && $selectedSectionCode !== '') {
    $sectionExists = false;
    foreach ($sections as $section) {
        if ((string) ($section['section_code'] ?? '') === $selectedSectionCode) {
            $sectionExists = true;
            break;
        }
    }
    if (!$sectionExists) {
        $selectedSectionCode = '';
    }
}

$exportParams = [
    'school_year_id' => $selectedSchoolYearId,
    'semester_no' => $selectedSemesterNo,
    'view_by' => $viewBy,
    'school_id' => $selectedSchoolId,
    'section_code' => $selectedSectionCode,
    'search' => $search,
];

$exportPdfUrl = appUrl('api/download_head_teacher_report_pdf.php?' . http_build_query($exportParams));
$exportExcelUrl = appUrl('api/download_head_teacher_report_excel.php?' . http_build_query($exportParams));

function headTeacherReportWeekStatusMap(array $row): array
{
    $practicumStart = trim((string) ($row['practicum_start_date'] ?? ''));
    if ($practicumStart === '') {
        $practicumStart = trim((string) ($row['semester_start_date'] ?? ''));
    }
    if ($practicumStart === '') {
        return [];
    }

    $today = practicumToday();
    $weeks = practicumBuildWeeksFromStart($practicumStart, $today, HeadTeacherReportService::JOURNALS_REQUIRED);
    $map = [];
    foreach ($weeks as $week) {
        $index = (int) ($week['index'] ?? 0);
        if ($index > 0) {
            $map[$index] = (string) ($week['status'] ?? 'upcoming');
        }
    }

    return $map;
}

renderHeadTeacherPortalStart($user, 'reports');
?>

<section class="mt-6 space-y-6">
    <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm xl:flex-row xl:items-start xl:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-700">Reports</p>
            <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">Student Practicum Reports</h2>
            <p class="mt-3 max-w-3xl text-sm text-slate-600 sm:text-base">
                Semester-wide oversight for every student's journal compliance, attendance, rendered hours, and present days.
            </p>
            <?php if ($context !== null): ?>
                <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold">
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-emerald-700">SY <?= htmlspecialchars((string) ($context['year_label'] ?? 'N/A')) ?></span>
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-slate-700"><?= htmlspecialchars((string) ($context['semester_name'] ?? 'Semester')) ?></span>
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-slate-700"><?= htmlspecialchars(strtoupper((string) ($context['semester_status'] ?? 'UNKNOWN'))) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($exportExcelUrl) ?>" class="inline-flex items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">Export Excel</a>
            <a href="<?= htmlspecialchars($exportPdfUrl) ?>" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Export PDF</a>
        </div>
    </div>

    <form method="get" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 xl:grid-cols-12">
            <div class="xl:col-span-3">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">School Year</label>
                <select name="school_year_id" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800">
                    <?php foreach ($schoolYears as $schoolYear): ?>
                        <option value="<?= (int) $schoolYear['id'] ?>" <?= (int) $schoolYear['id'] === $selectedSchoolYearId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $schoolYear['year_label']) ?> (<?= htmlspecialchars((string) $schoolYear['year_status']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="xl:col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Semester</label>
                <select name="semester_no" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800">
                    <option value="1" <?= $selectedSemesterNo === 1 ? 'selected' : '' ?>>1st Semester</option>
                    <option value="2" <?= $selectedSemesterNo === 2 ? 'selected' : '' ?>>2nd Semester</option>
                </select>
            </div>

            <div class="xl:col-span-3">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">View By</label>
                <select id="viewBySelect" name="view_by" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800">
                    <option value="partnered_school" <?= $viewBy === 'partnered_school' ? 'selected' : '' ?>>Partnered School</option>
                    <option value="section" <?= $viewBy === 'section' ? 'selected' : '' ?>>Section</option>
                </select>
            </div>

            <div id="partneredSchoolWrap" class="xl:col-span-2 <?= $viewBy === 'partnered_school' ? '' : 'hidden' ?>">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Partnered School</label>
                <select name="school_id" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800">
                    <option value="0">All Partnered Schools</option>
                    <?php foreach ($partneredSchools as $school): ?>
                        <option value="<?= (int) $school['id'] ?>" <?= (int) $school['id'] === $selectedSchoolId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $school['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="sectionWrap" class="xl:col-span-2 <?= $viewBy === 'section' ? '' : 'hidden' ?>">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Section</label>
                <select name="section_code" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $section): ?>
                        <?php $sectionCode = (string) $section['section_code']; ?>
                        <option value="<?= htmlspecialchars($sectionCode) ?>" <?= $sectionCode === $selectedSectionCode ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sectionCode) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="xl:col-span-8">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Search</label>
                <input
                    type="search"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search Student Name"
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800"
                >
            </div>

            <div class="flex items-end gap-2 xl:col-span-4">
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-600">Apply Filters</button>
                <a href="<?= htmlspecialchars(appUrl('head_teacher/student_practicum_reports.php')) ?>" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50">Reset</a>
            </div>
        </div>
    </form>

    <?php if (!$reportTrackingStarted && $context !== null): ?>
        <section class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 shadow-sm">
            <p class="font-bold">Practicum tracking has not started.</p>
            <p class="mt-1">Students are visible in setup, but attendance, journals, checklist scoring, and compliance will not be evaluated until you start practicum.</p>
        </section>
    <?php endif; ?>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-950">Semester Overview</h3>
                    <p class="mt-1 text-sm text-slate-500">Showing <?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?>.</p>
                </div>
                <div class="text-sm text-slate-500">
                    <?= $journalWeeksToDisplay > 0
                        ? 'Journal scores and compliance show weeks reached so far (J1–J' . $journalWeeksToDisplay . '). Later weeks appear as practicum progresses.'
                        : 'Journal scores will appear after practicum starts.' ?>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table style="min-width: <?= 1050 + ($journalWeeksToDisplay * 58) ?>px" class="w-full border-separate border-spacing-0 text-left text-sm">
                <thead class="bg-slate-50/90 text-[11px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="sticky left-0 z-10 border-b border-slate-200 bg-slate-50 px-4 py-3 font-semibold">Student Name</th>
                        <?php for ($week = 1; $week <= $journalWeeksToDisplay; $week++): ?>
                            <th class="border-b border-slate-200 px-2 py-3 text-center font-semibold">J<?= $week ?> Score</th>
                        <?php endfor; ?>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Compliance</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Rendered Hours</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Present Days</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Checklist Average</th>
                        <th class="border-b border-slate-200 px-4 py-3 font-semibold">Evaluation Average</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $weekStatusMap = headTeacherReportWeekStatusMap($row);
                        $journalsApprovedToDate = 0;
                        for ($week = 1; $week <= $journalWeeksToDisplay; $week++) {
                            if ((int) ($row['j' . $week] ?? 0) === 1) {
                                $journalsApprovedToDate++;
                            }
                        }
                        $journalComplianceLabel = $journalWeeksToDisplay > 0
                            ? $journalsApprovedToDate . ' / ' . $journalWeeksToDisplay . ' (' . HeadTeacherReportService::formatCompliance(($journalsApprovedToDate / $journalWeeksToDisplay) * 100) . '%)'
                            : 'Not started';
                        ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="sticky left-0 z-10 border-b border-slate-100 bg-white px-4 py-3 font-semibold text-slate-900">
                                <div class="truncate">
                                    <?= htmlspecialchars((string) ($row['student_name'] ?? 'Unknown Student')) ?>
                                    <?php if (!empty($row['section_code'])): ?>
                                        <span class="font-medium text-slate-500"> - <?= htmlspecialchars((string) $row['section_code']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-1 truncate text-xs font-medium text-slate-500">
                                    <?= htmlspecialchars((string) ($row['email'] ?? '')) ?>
                                    <?php if (!empty($row['student_number'])): ?>
                                        <span class="text-slate-400"> - (<?= htmlspecialchars((string) $row['student_number']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2 space-y-0.5 text-xs font-medium text-slate-600">
                                    <?php $assignedSchools = array_filter(array_map('trim', explode('||', (string) ($row['assigned_school_names'] ?? '')))); ?>
                                    <?php foreach ($assignedSchools as $assignedSchool): ?>
                                        <div><?= htmlspecialchars($assignedSchool) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <?php for ($week = 1; $week <= $journalWeeksToDisplay; $week++): ?>
                                <?php $weekStatus = (string) ($weekStatusMap[$week] ?? 'upcoming'); ?>
                                <td class="border-b border-slate-100 px-2 py-3 text-center">
                                    <?php if ($weekStatus === 'upcoming'): ?>
                                        <span class="inline-flex h-5 w-5 items-center justify-center text-slate-300">&nbsp;</span>
                                    <?php else: ?>
                                        <span class="font-semibold text-slate-800"><?= (int) ($row['jp' . $week] ?? 0) ?> / <?= (int) ($row['jm' . $week] ?? 50) ?></span>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                            <td class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">
                                <?= htmlspecialchars($journalComplianceLabel) ?>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">
                                <?= htmlspecialchars((string) ($row['rendered_hours_live_label'] ?? $row['rendered_hours_label'] ?? '0')) ?> / 180 hrs
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">
                                <?= (int) ($row['present_days'] ?? 0) ?>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">
                                <?= $row['checklist_average'] !== null ? htmlspecialchars((string) $row['checklist_average']) : 'N/A' ?>
                            </td>
                            <td class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">
                                <?= $row['evaluation_average'] !== null ? htmlspecialchars((string) $row['evaluation_average']) : 'N/A' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="<?= 6 + $journalWeeksToDisplay ?>" class="px-4 py-12 text-center text-sm text-slate-500">
                                No students found for the selected filters.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>

<script>
(() => {
    const viewBySelect = document.getElementById('viewBySelect');
    const partneredSchoolWrap = document.getElementById('partneredSchoolWrap');
    const sectionWrap = document.getElementById('sectionWrap');

    function syncView() {
        if (!viewBySelect || !partneredSchoolWrap || !sectionWrap) return;
        const value = viewBySelect.value;
        partneredSchoolWrap.classList.toggle('hidden', value !== 'partnered_school');
        sectionWrap.classList.toggle('hidden', value !== 'section');
    }

    viewBySelect?.addEventListener('change', syncView);
    syncView();
})();
</script>

<?php renderHeadTeacherPortalEnd(); ?>
