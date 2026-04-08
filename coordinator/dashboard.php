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
$selectedWeek = coordinatorSelectedWeek($context, isset($_GET['week_start']) ? (string) $_GET['week_start'] : null);
$weekOptions = coordinatorWeekOptions($context);
$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$dashboard = $selectedWeek !== null
    ? $attendanceService->getCoordinatorDashboardData($coordinatorUserId, $todayDate, $selectedWeek['start'], $selectedWeek['end'])
    : [
        'context' => $context !== [] ? $context : null,
        'summary' => ['active_now' => 0, 'logged_today' => 0, 'completed_8h' => 0, 'total_students' => 0],
        'live_section' => null,
        'recent_logged_in' => [],
        'weekly_rows' => [],
    ];

function coordinatorStudentStatusBadge(float $hours): string
{
    if ($hours >= 40) {
        return coordinatorTopBadge('On Track', 'green');
    }
    if ($hours > 0) {
        return coordinatorTopBadge('In Progress', 'blue');
    }

    return coordinatorTopBadge('No Hours Yet', 'orange');
}
?>
<?php renderCoordinatorPortalStart(
    $user,
    'dashboard',
    'Coordinator Student List',
    $context,
    '<a href="/practicum_system/coordinator/history.php" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">History</a>'
); ?>

<?php if ($context === [] || $selectedWeek === null): ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <h1 class="text-3xl font-bold text-slate-900">Student List</h1>
        <p class="mt-3 text-slate-500">No assigned section found yet.</p>
    </section>
<?php else: ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Student List</h1>
            <p class="mt-3 max-w-2xl text-base text-slate-500 sm:text-lg">
                Current roster for <?= htmlspecialchars((string) $context['section_code']) ?>. This workspace stays focused on students only.
            </p>
        </div>
        <form method="get" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label for="week_start" class="text-sm font-medium text-slate-500">Week:</label>
            <select id="week_start" name="week_start" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <?php foreach ($weekOptions as $option): ?>
                    <option value="<?= htmlspecialchars((string) $option['start']) ?>" <?= $option['start'] === $selectedWeek['start'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">View</button>
        </form>
    </section>

    <section class="mb-6 grid gap-4 md:grid-cols-3">
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <p class="text-sm font-semibold text-slate-500">Section</p>
            <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars((string) $context['section_code']) ?></h2>
            <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars((string) $context['section_name']) ?></p>
        </article>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <p class="text-sm font-semibold text-slate-500">Students</p>
            <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= (int) ($dashboard['summary']['total_students'] ?? 0) ?></h2>
            <p class="mt-2 text-sm text-slate-500">Active students in this assigned section</p>
        </article>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <p class="text-sm font-semibold text-slate-500">School</p>
            <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars((string) ($context['school_name'] ?? 'No assigned school')) ?></h2>
            <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars((string) ($context['semester_name'] . ' • ' . $context['year_label'])) ?></p>
        </article>
    </section>

    <section data-reveal class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
        <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-2xl font-bold tracking-tight text-slate-950">Current Students</h3>
                <p class="mt-2 text-base text-slate-500">Each row is one student in the assigned section for the selected week.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="/practicum_system/coordinator/checklist.php?week_start=<?= urlencode((string) $selectedWeek['start']) ?>" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Checklist</a>
                <a href="/practicum_system/coordinator/journal_submissions.php?week_start=<?= urlencode((string) $selectedWeek['start']) ?>" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Journals</a>
                <a href="/practicum_system/coordinator/history.php" class="rounded-2xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-800">Open History</a>
            </div>
        </div>
        <div class="scroll-slim overflow-x-auto">
            <table class="min-w-full text-left">
                <thead class="bg-slate-50/80">
                    <tr class="text-xs uppercase tracking-[0.22em] text-slate-500">
                        <th class="px-5 py-4 font-semibold">Student</th>
                        <th class="px-4 py-4 font-semibold">School ID</th>
                        <th class="px-4 py-4 font-semibold">Week Hours</th>
                        <th class="px-4 py-4 font-semibold">Journal</th>
                        <th class="px-4 py-4 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($dashboard['weekly_rows'] as $row): ?>
                        <?php $totalHours = (float) ($row['total_hours'] ?? 0); ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-5 py-4">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?></div>
                                <div class="mt-1 text-sm text-slate-500">Handled by current assigned section</div>
                            </td>
                            <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($row['school_id'] ?? 'N/A')) ?></td>
                            <td class="px-4 py-4 font-semibold text-slate-900"><?= number_format($totalHours, 0) ?>h</td>
                            <td class="px-4 py-4">
                                <?= ((string) ($row['journal_status'] ?? 'missing')) === 'missing'
                                    ? coordinatorTopBadge('Missing', 'red')
                                    : coordinatorTopBadge('Submitted', 'green') ?>
                            </td>
                            <td class="px-4 py-4"><?= coordinatorStudentStatusBadge($totalHours) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (($dashboard['weekly_rows'] ?? []) === []): ?>
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center text-sm text-slate-500">No students found for the selected week.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php renderCoordinatorPortalEnd(); ?>
