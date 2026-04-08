<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/JournalService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$journalService = new JournalService($pdo);
$context = $attendanceService->getCoordinatorSectionContext((int) ($user['id'] ?? 0)) ?? [];
$selectedWeek = coordinatorSelectedWeek($context, isset($_GET['week_start']) ? (string) $_GET['week_start'] : null);
$weekOptions = coordinatorWeekOptions($context);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $context !== [] && $selectedWeek !== null) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'approve_journal') {
            $journalService->approveJournal((int) ($_POST['student_id'] ?? 0), (int) $context['semester_id'], $selectedWeek['start']);
            coordinatorSetFlash('success', 'Journal approved.');
        } elseif ($action === 'decline_journal') {
            $journalService->declineJournal((int) ($_POST['student_id'] ?? 0), (int) $context['semester_id'], $selectedWeek['start']);
            coordinatorSetFlash('success', 'Journal marked for revision.');
        }
    } catch (Throwable $e) {
        coordinatorSetFlash('error', $e->getMessage());
    }

    header('Location: /practicum_system/coordinator/journal_submissions.php?week_start=' . urlencode((string) $selectedWeek['start']));
    exit;
}

$rows = ($context !== [] && $selectedWeek !== null)
    ? $journalService->getSectionJournals((int) $context['section_id'], (int) $context['semester_id'], $selectedWeek['start'])
    : [];
$flash = coordinatorGetFlash();
$todayName = (new DateTimeImmutable('today'))->format('l');
$isFriday = $todayName === 'Friday';
?>
<?php renderCoordinatorPortalStart(
    $user,
    'journals',
    'Journal Submissions',
    $context,
    '<a href="/practicum_system/coordinator/history.php" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">History</a>'
); ?>

<?php if ($flash !== null): ?>
    <div data-reveal class="<?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> mb-6 rounded-3xl border px-5 py-4 text-sm font-medium shadow-panel">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </div>
<?php endif; ?>

<?php if ($context !== [] && $selectedWeek !== null): ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Journal Submissions</h1>
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                    <?= htmlspecialchars($todayName . ' - ' . ($isFriday ? 'OPEN' : 'TRACKING')) ?>
                </span>
            </div>
            <p class="mt-3 max-w-2xl text-base text-slate-500 sm:text-lg">
                Review and manage weekly Gratitude Journal submissions for <?= htmlspecialchars((string) $context['section_code']) ?>.
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
            <button type="submit" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Go</button>
        </form>
    </section>

    <section data-reveal class="rounded-[28px] border border-emerald-200 bg-emerald-50/70 p-5 shadow-panel">
        <div class="flex items-start gap-3">
            <div class="rounded-2xl bg-white p-2 text-emerald-600 shadow-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="h-5 w-5"><path d="M5 12l4 4L19 6"/></svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-emerald-800">Journal Submission - <?= htmlspecialchars($isFriday ? 'OPEN' : 'TRACKING') ?></h3>
                <p class="mt-2 text-sm leading-7 text-emerald-700 sm:text-base">
                    Today is <strong><?= htmlspecialchars($todayName) ?></strong>. Students can submit their weekly Gratitude Journal. You can approve or decline submissions here.
                </p>
            </div>
        </div>
    </section>

    <section data-reveal class="mt-6 overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
        <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-2xl font-bold tracking-tight text-slate-950">Week <?= (int) $selectedWeek['index'] ?> - Journal Submissions</h3>
            </div>
            <div><?= coordinatorTopBadge(count($rows) . ' Students', 'slate') ?></div>
        </div>
        <div class="scroll-slim overflow-x-auto">
            <table class="min-w-full text-left">
                <thead class="bg-slate-50/80">
                    <tr class="text-xs uppercase tracking-[0.22em] text-slate-500">
                        <th class="px-5 py-4 font-semibold">Student Name</th>
                        <th class="px-4 py-4 font-semibold">Week</th>
                        <th class="px-4 py-4 font-semibold">Status</th>
                        <th class="px-4 py-4 font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $row): ?>
                        <?php $hasJournal = !empty($row['journal_id']); ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-5 py-4 font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?></td>
                            <td class="px-4 py-4 text-slate-600">Week <?= (int) $selectedWeek['index'] ?> of <?= max(1, count($weekOptions)) ?></td>
                            <td class="px-4 py-4">
                                <?= !$hasJournal ? coordinatorTopBadge('Missing', 'red') : (((string) $row['status'] === 'approved') ? coordinatorTopBadge('Approved', 'green') : (((string) $row['status'] === 'revise') ? coordinatorTopBadge('Revise', 'red') : coordinatorTopBadge('Pending Review', 'blue'))) ?>
                            </td>
                            <td class="px-4 py-4">
                                <?php if ($hasJournal): ?>
                                    <div class="flex flex-wrap items-center gap-4 text-sm font-semibold">
                                        <a class="text-blue-600 transition hover:text-blue-700" href="/practicum_system/coordinator/review_journals.php?student_id=<?= (int) $row['student_user_id'] ?>&week_start=<?= urlencode((string) $selectedWeek['start']) ?>">View</a>
                                        <form method="post">
                                            <input type="hidden" name="action" value="approve_journal">
                                            <input type="hidden" name="student_id" value="<?= (int) $row['student_user_id'] ?>">
                                            <button type="submit" class="text-emerald-600 transition hover:text-emerald-700">Approve</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="action" value="decline_journal">
                                            <input type="hidden" name="student_id" value="<?= (int) $row['student_user_id'] ?>">
                                            <button type="submit" class="text-rose-600 transition hover:text-rose-700">Decline</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-sm text-slate-400">No submission</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="4" class="px-5 py-12 text-center text-sm text-slate-500">No journal records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <p class="text-slate-500">No assigned section found yet.</p>
    </section>
<?php endif; ?>

<?php renderCoordinatorPortalEnd(); ?>
