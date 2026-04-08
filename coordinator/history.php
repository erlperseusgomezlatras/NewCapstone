<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$coordinatorUserId = (int) ($user['id'] ?? 0);
$context = $attendanceService->getCoordinatorSectionContext($coordinatorUserId) ?? [];
$semesterHistory = $attendanceService->getCoordinatorSectionHistory($coordinatorUserId);
$studentHistoryRows = $attendanceService->getCoordinatorHandledStudentsHistory($coordinatorUserId);

$studentHistoryBySection = [];
foreach ($studentHistoryRows as $row) {
    $sectionId = (int) ($row['section_id'] ?? 0);
    if ($sectionId <= 0) {
        continue;
    }

    if (!isset($studentHistoryBySection[$sectionId])) {
        $studentHistoryBySection[$sectionId] = [];
    }
    $studentHistoryBySection[$sectionId][] = $row;
}
?>
<?php renderCoordinatorPortalStart(
    $user,
    'history',
    'Coordinator History',
    $context,
    '<a href="/practicum_system/coordinator/dashboard.php" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Student List</a>'
); ?>

<section data-reveal class="mb-6">
    <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">History</h1>
    <p class="mt-3 text-base text-slate-500 sm:text-lg">Past sections and the students you have handled are listed here for read-only reference.</p>
</section>

<section class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($semesterHistory as $row): ?>
        <?php $completionRate = (float) ($row['completion_rate'] ?? 0); ?>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <?= coordinatorTopBadge((string) ($row['year_label'] ?? 'N/A'), 'blue') ?>
                <?= coordinatorTopBadge((string) ($row['semester_name'] ?? 'N/A'), 'slate') ?>
                <?= coordinatorTopBadge((string) strtoupper((string) ($row['semester_status'] ?? 'unknown')), 'green') ?>
            </div>
            <h2 class="text-2xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars((string) ($row['section_code'] ?? 'Unknown Section')) ?></h2>
            <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars((string) ($row['section_name'] ?? '')) ?></p>
            <div class="mt-5 flex items-center justify-between text-sm">
                <span class="text-slate-500">Students handled</span>
                <span class="font-semibold text-slate-900"><?= (int) ($row['total_students'] ?? 0) ?></span>
            </div>
            <div class="mt-2 flex items-center justify-between text-sm">
                <span class="text-slate-500">Completion rate</span>
                <span class="font-semibold text-slate-900"><?= number_format($completionRate, 0) ?>%</span>
            </div>
            <div class="mt-3 h-2 rounded-full bg-slate-100">
                <div class="h-2 rounded-full bg-gradient-to-r from-brand-600 to-emerald-400" style="width:<?= max(0, min(100, $completionRate)) ?>%"></div>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if ($semesterHistory === []): ?>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 text-slate-500 shadow-panel">
            No section history found yet.
        </article>
    <?php endif; ?>
</section>

<section class="space-y-5">
    <div data-reveal class="flex items-center gap-2 text-xl font-bold text-slate-950">
        <span class="inline-block h-2.5 w-2.5 rounded-full bg-brand-600"></span>
        <span>Previous Sections And Students</span>
    </div>

    <?php foreach ($semesterHistory as $row): ?>
        <?php $sectionId = (int) ($row['section_id'] ?? 0); ?>
        <?php $students = $studentHistoryBySection[$sectionId] ?? []; ?>
        <article data-reveal class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h3 class="text-2xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars((string) ($row['section_code'] ?? 'Unknown Section')) ?></h3>
                    <p class="mt-2 text-base text-slate-500">
                        <?= htmlspecialchars((string) (($row['year_label'] ?? 'N/A') . ' • ' . ($row['semester_name'] ?? 'N/A'))) ?>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <?= coordinatorTopBadge(((int) ($row['total_students'] ?? 0)) . ' Students', 'slate') ?>
                    <?= coordinatorTopBadge((string) strtoupper((string) ($row['semester_status'] ?? 'unknown')), 'blue') ?>
                </div>
            </div>

            <div class="scroll-slim overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead class="bg-slate-50/80">
                        <tr class="text-xs uppercase tracking-[0.22em] text-slate-500">
                            <th class="px-5 py-4 font-semibold">Student</th>
                            <th class="px-4 py-4 font-semibold">School ID</th>
                            <th class="px-4 py-4 font-semibold">Email</th>
                            <th class="px-4 py-4 font-semibold">Attendance Days</th>
                            <th class="px-4 py-4 font-semibold">Total Hours</th>
                            <th class="px-4 py-4 font-semibold">Enrollment</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($students as $student): ?>
                            <tr class="transition hover:bg-slate-50/80">
                                <td class="px-5 py-4 font-semibold text-slate-900"><?= htmlspecialchars((string) (($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? ''))) ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($student['school_id'] ?? 'N/A')) ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($student['email'] ?? 'N/A')) ?></td>
                                <td class="px-4 py-4 font-semibold text-slate-900"><?= (int) ($student['days_present'] ?? 0) ?></td>
                                <td class="px-4 py-4 font-semibold text-slate-900"><?= number_format((float) ($student['total_hours'] ?? 0), 0) ?>h</td>
                                <td class="px-4 py-4">
                                    <?= coordinatorTopBadge((string) strtoupper((string) ($student['enrollment_status'] ?? 'unknown')), ((string) ($student['enrollment_status'] ?? '') === 'active' ? 'green' : 'orange')) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($students === []): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">No student roster found for this section.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php endforeach; ?>

    <?php if ($semesterHistory === []): ?>
        <div data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 text-slate-500 shadow-panel">
            Once this coordinator handles sections, the past section roster will appear here.
        </div>
    <?php endif; ?>
</section>

<?php renderCoordinatorPortalEnd(); ?>
