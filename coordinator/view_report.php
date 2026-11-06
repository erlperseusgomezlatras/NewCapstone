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

$studentUserId = max(0, (int) ($_GET['student_id'] ?? 0));
$semesterId = max(0, (int) ($_GET['semester_id'] ?? 0));
$geofenceId = max(0, (int) ($_GET['school_id'] ?? 0));

$report = ($studentUserId > 0 && $semesterId > 0 && $geofenceId > 0)
    ? $reportService->getStudentReport($coordinatorUserId, $studentUserId, $semesterId, $geofenceId)
    : null;

$student = $report['student'] ?? null;
$weeklyAttendance = $report['weekly_attendance'] ?? [];
$journalRows = $report['journal_rows'] ?? [];
$checklistRows = $report['checklist_rows'] ?? [];
$journalSummary = $report['journal_summary'] ?? [];
$checklistSummary = $report['checklist_summary'] ?? [];
$summary = $report['summary'] ?? [];
$progress = $report['progress'] ?? [];

$downloadUrl = appUrl('api/download_coordinator_report_pdf.php?' . http_build_query([
    'student_id' => $studentUserId,
    'semester_id' => $semesterId,
    'school_id' => $geofenceId,
]));

renderCoordinatorPortalStart(
    $user,
    'reports',
    'Student Report',
    $context,
    $student !== null
        ? '<a href="' . htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-800">Download Report PDF</a>'
        : '',
    true
);
?>

<section data-reveal class="mb-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Student Practicum Report</h1>
            <p class="mt-3 text-base text-slate-500">Detailed practicum performance summary for the selected student.</p>
        </div>
        <a href="<?= htmlspecialchars(appUrl('coordinator/reports.php')) ?>" class="inline-flex rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">Back to Reports</a>
    </div>
</section>

<?php if ($student === null): ?>
    <section class="rounded-[28px] border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">
        Student report not found or you do not have permission to view it.
    </section>
<?php else: ?>
    <?php
    $studentName = CoordinatorReportService::fullName($student);
    $coordinatorName = trim((string) (($student['coordinator_last_name'] ?? '') . ', ' . ($student['coordinator_first_name'] ?? '')));
    if ($coordinatorName === ',') {
        $coordinatorName = 'Coordinator';
    }
    $studentSection = trim((string) ($student['original_section'] ?? ''));
    if ($studentSection === '') {
        $studentSection = 'Unassigned';
    }
    $checklistWeekLabels = [];
    $checklistStart = practicumEffectiveStart((string) ($student['practicum_start_date'] ?? ''))
        ?: trim((string) ($student['semester_start_date'] ?? ''));
    $checklistEnd = trim((string) ($student['semester_end_date'] ?? ''));
    if ($checklistStart !== '' && $checklistEnd !== '') {
        foreach (practicumBuildWeeks($checklistStart, $checklistEnd, $checklistEnd) as $week) {
            $weekStart = (string) ($week['start'] ?? '');
            $weekIndex = (int) ($week['index'] ?? 0);
            if ($weekStart !== '' && $weekIndex > 0) {
                $checklistWeekLabels[$weekStart] = 'Week ' . $weekIndex;
            }
        }
    }
    ?>

    <section data-reveal class="mb-6 rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
        <h2 class="text-xl font-bold text-slate-950">Student Information</h2>
        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Student Name</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars($studentName) ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Student ID</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) ($student['school_id'] ?? 'N/A')) ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Email Address</div><div class="mt-1 font-semibold text-slate-900 break-all"><?= htmlspecialchars((string) ($student['email'] ?? 'N/A')) ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Partnered School</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) ($student['school_name'] ?? 'N/A')) ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Assigned Coordinator</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars($coordinatorName) ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">School Year</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) ($student['year_label'] ?? 'N/A')) ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Semester</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) ($student['semester_name'] ?? 'N/A')) ?></div></div>
            <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-semibold uppercase text-slate-500">Section</div><div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars($studentSection) ?></div></div>
        </div>
    </section>

    <section data-reveal class="mb-6 grid gap-4 md:grid-cols-3 xl:grid-cols-7">
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Days Present (Total)</p><p class="mt-2 text-2xl font-bold"><?= (int) ($student['days_present'] ?? 0) ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Days Absent (Total)</p><p class="mt-2 text-2xl font-bold"><?= (int) ($summary['days_absent'] ?? 0) ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Late Count</p><p class="mt-2 text-2xl font-bold"><?= (int) ($student['late_count'] ?? 0) ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Total Attendance Records</p><p class="mt-2 text-2xl font-bold"><?= (int) ($student['total_attendance_records'] ?? 0) ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Hours Rendered</p><p class="mt-2 text-2xl font-bold"><?= coordinatorFormatHours((float) ($student['rendered_hours'] ?? 0)) ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Target Hours</p><p class="mt-2 text-2xl font-bold"><?= (int) CoordinatorReportService::TARGET_HOURS ?></p></div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel"><p class="text-xs font-semibold uppercase text-slate-500">Completion %</p><p class="mt-2 text-2xl font-bold"><?= (int) ($student['hours_percent'] ?? 0) ?>%</p></div>
    </section>

    <section data-reveal class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel">
            <p class="text-xs font-semibold uppercase text-slate-500">Hours</p>
            <p class="mt-2 text-2xl font-bold"><?= coordinatorFormatHours((float) ($progress['hours']['current'] ?? 0)) ?> / <?= coordinatorFormatHours((float) ($progress['hours']['target'] ?? 0)) ?> hrs</p>
        </div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel">
            <p class="text-xs font-semibold uppercase text-slate-500">Journals</p>
            <p class="mt-2 text-2xl font-bold"><?= (int) ($progress['journals']['current'] ?? 0) ?> / <?= (int) ($progress['journals']['target'] ?? 0) ?></p>
        </div>
        <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-panel">
            <p class="text-xs font-semibold uppercase text-slate-500">Checklist Points</p>
            <p class="mt-2 text-2xl font-bold"><?= (int) ($checklistSummary['average_score'] ?? 0) ?></p>
        </div>
    </section>

    <section data-reveal class="mb-6 rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
        <h3 class="text-xl font-bold text-slate-950">Weekly Attendance</h3>
        <div class="mt-4">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Week</th>
                        <th class="px-4 py-3">Present Days</th>
                        <th class="px-4 py-3">Absent Days</th>
                        <th class="px-4 py-3">Rendered Hours</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($weeklyAttendance as $week): ?>
                        <tr>
                            <td class="px-4 py-3 font-semibold"><?= htmlspecialchars((string) $week['label']) ?></td>
                            <td class="px-4 py-3"><?= (int) $week['present_days'] ?></td>
                            <td class="px-4 py-3"><?= (int) $week['absent_days'] ?></td>
                            <td class="px-4 py-3"><?= coordinatorFormatHours((float) $week['rendered_hours']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($weeklyAttendance === []): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No weekly attendance data available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section data-reveal class="mb-6 grid gap-6 xl:grid-cols-2">
        <article class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
            <h3 class="text-xl font-bold text-slate-950">Journal Summary</h3>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Submitted</div><div class="mt-1 text-xl font-bold"><?= (int) ($journalSummary['submitted'] ?? 0) ?></div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Missing</div><div class="mt-1 text-xl font-bold"><?= (int) ($journalSummary['missing'] ?? 0) ?></div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Pending</div><div class="mt-1 text-xl font-bold"><?= (int) ($journalSummary['pending'] ?? 0) ?></div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Approved</div><div class="mt-1 text-xl font-bold"><?= (int) ($journalSummary['approved'] ?? 0) ?></div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Declined</div><div class="mt-1 text-xl font-bold"><?= (int) ($journalSummary['declined'] ?? 0) ?></div></div>
            </div>
        </article>
        <article class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
            <h3 class="text-xl font-bold text-slate-950">Checklist Summary</h3>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Total Items</div><div class="mt-1 text-xl font-bold"><?= (int) ($checklistSummary['total_items'] ?? 0) ?></div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Completed</div><div class="mt-1 text-xl font-bold"><?= (int) ($checklistSummary['completed'] ?? 0) ?></div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Total Points</div><div class="mt-1 text-xl font-bold"><?= (int) ($checklistSummary['average_score'] ?? 0) ?></div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs uppercase text-slate-500">Overall %</div><div class="mt-1 text-xl font-bold"><?= (int) ($checklistSummary['overall_percent'] ?? 0) ?>%</div></div>
            </div>
        </article>
    </section>

    <section data-reveal class="mb-6 rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
        <h3 class="text-xl font-bold text-slate-950">Journal Submissions</h3>
        <div class="mt-4">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Week</th>
                        <th class="px-4 py-3">Submission Date</th>
                        <th class="px-4 py-3">Journal Score</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Coordinator Feedback</th>
                        <th class="px-4 py-3">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($journalRows as $journal): ?>
                        <?php
                        $weekLabel = !empty($journal['week_no'])
                            ? 'Week ' . (int) $journal['week_no']
                            : (string) ($journal['week_start'] ?? 'Week');
                        $submittedAt = !empty($journal['submitted_at'])
                            ? (new DateTimeImmutable((string) $journal['submitted_at']))->format('M j, Y')
                            : '--';
                        $source = (string) ($journal['source'] ?? 'dynamic');
                        $journalId = (int) ($journal['id'] ?? 0);
                        ?>
                        <tr>
                            <td class="px-4 py-3 font-semibold"><?= htmlspecialchars($weekLabel) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($submittedAt) ?></td>
                            <td class="px-4 py-3 font-semibold"><?= (int) ($journal['points_awarded'] ?? 0) ?> / <?= max(1, (int) ($journal['max_points'] ?? 50)) ?></td>
                            <td class="px-4 py-3"><?= coordinatorTopBadge(ucwords((string) ($journal['status'] ?? 'pending')), 'blue') ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= nl2br(htmlspecialchars((string) ($journal['coordinator_feedback'] ?? ''))) ?></td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <?php if ($source === 'dynamic'): ?>
                                        <a href="<?= htmlspecialchars(appUrl('coordinator/review_journals.php?submission_id=' . $journalId)) ?>" class="inline-flex rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">View Journal</a>
                                        <a href="<?= htmlspecialchars(appUrl('api/download_journal_pdf.php?submission_id=' . $journalId)) ?>" class="inline-flex rounded-xl bg-brand-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-800">Download PDF</a>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars(appUrl('student/view_journal.php?id=' . $journalId . '&source=legacy')) ?>" class="inline-flex rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">View Journal</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($journalRows === []): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No journal submissions found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section data-reveal class="mb-6 rounded-[28px] border border-slate-200 bg-white p-5 shadow-panel">
        <h3 class="text-xl font-bold text-slate-950">Weekly Checklist Scores</h3>
        <div class="mt-4">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Week</th>
                        <th class="px-4 py-3">Checklist Score</th>
                        <th class="px-4 py-3">Remarks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($checklistRows as $checklist): ?>
                        <tr>
                            <?php $checklistWeekStart = (string) ($checklist['week_start'] ?? ''); ?>
                            <td class="px-4 py-3 font-semibold"><?= htmlspecialchars($checklistWeekLabels[$checklistWeekStart] ?? $checklistWeekStart) ?></td>
                            <td class="px-4 py-3"><?= (int) ($checklist['score_percent'] ?? 0) ?>% (<?= (int) ($checklist['total_score'] ?? 0) ?> pts)</td>
                            <td class="px-4 py-3 whitespace-pre-line text-slate-600"><?= htmlspecialchars((string) ($checklist['remarks'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($checklistRows === []): ?>
                        <tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">No checklist scores recorded.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="mb-6 flex justify-end">
        <a href="<?= htmlspecialchars($downloadUrl) ?>" class="inline-flex rounded-2xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-800">Download Report PDF</a>
    </div>
<?php endif; ?>

<?php renderCoordinatorPortalEnd(); ?>
