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
$selectedWeek = isset($_GET['week_start']) ? (string) $_GET['week_start'] : ((new DateTimeImmutable('today'))->modify('monday this week')->format('Y-m-d'));
$studentId = (int) ($_GET['student_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $context !== []) {
    $action = (string) ($_POST['action'] ?? '');
    $studentId = (int) ($_POST['student_id'] ?? $studentId);
    $feedback = trim((string) ($_POST['coordinator_feedback'] ?? ''));

    try {
        if ($action === 'approve_journal') {
            $journalService->approveJournal($studentId, (int) $context['semester_id'], $selectedWeek, $feedback);
            coordinatorSetFlash('success', 'Journal approved.');
        } elseif ($action === 'decline_journal') {
            $journalService->declineJournal($studentId, (int) $context['semester_id'], $selectedWeek, $feedback);
            coordinatorSetFlash('success', 'Journal marked for revision.');
        }
    } catch (Throwable $e) {
        coordinatorSetFlash('error', $e->getMessage());
    }

    header('Location: /practicum_system/coordinator/journal_submissions.php?week_start=' . urlencode($selectedWeek));
    exit;
}

$journal = ($context !== [] && $studentId > 0)
    ? $journalService->getJournalDetail($studentId, (int) $context['semester_id'], $selectedWeek)
    : null;
$flash = coordinatorGetFlash();
?>
<?php renderCoordinatorPortalStart(
    $user,
    'journals',
    'Review Journal',
    $context,
    '<a href="/practicum_system/coordinator/journal_submissions.php?week_start=' . urlencode($selectedWeek) . '" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Back</a>'
); ?>

<?php if ($flash !== null): ?>
    <div data-reveal class="<?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> mb-6 rounded-3xl border px-5 py-4 text-sm font-medium shadow-panel">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </div>
<?php endif; ?>

<?php if ($journal === null): ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <p class="text-slate-500">Journal not found for the selected student and week.</p>
    </section>
<?php else: ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Review Journal</h1>
            <p class="mt-3 text-base text-slate-500 sm:text-lg">
                <?= htmlspecialchars((string) ($journal['last_name'] . ', ' . $journal['first_name'])) ?> | Week of <?= htmlspecialchars((string) $journal['week_start']) ?>
            </p>
        </div>
        <div>
            <?= ((string) $journal['status'] === 'approved') ? coordinatorTopBadge('Approved', 'green') : (((string) $journal['status'] === 'revise') ? coordinatorTopBadge('Revise', 'red') : coordinatorTopBadge('Pending Review', 'blue')) ?>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <h3 class="text-xs font-bold uppercase tracking-[0.24em] text-slate-400">I Am Grateful For</h3>
            <p class="mt-4 whitespace-pre-line text-base leading-8 text-slate-700"><?= htmlspecialchars((string) $journal['grateful_for']) ?></p>
        </article>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <h3 class="text-xs font-bold uppercase tracking-[0.24em] text-slate-400">Something I Am Proud Of</h3>
            <p class="mt-4 whitespace-pre-line text-base leading-8 text-slate-700"><?= htmlspecialchars((string) $journal['proud_of']) ?></p>
        </article>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <h3 class="text-xs font-bold uppercase tracking-[0.24em] text-slate-400">Words To Inspire</h3>
            <p class="mt-4 whitespace-pre-line text-base leading-8 text-slate-700"><?= htmlspecialchars((string) $journal['words_to_inspire']) ?></p>
        </article>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <h3 class="text-xs font-bold uppercase tracking-[0.24em] text-slate-400">Words Of Affirmation</h3>
            <p class="mt-4 whitespace-pre-line text-base leading-8 text-slate-700"><?= htmlspecialchars((string) $journal['affirmations']) ?></p>
        </article>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <h3 class="text-xs font-bold uppercase tracking-[0.24em] text-slate-400">Next Week I Look Forward To</h3>
            <p class="mt-4 whitespace-pre-line text-base leading-8 text-slate-700"><?= htmlspecialchars((string) $journal['look_forward_to']) ?></p>
        </article>
        <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <h3 class="text-xs font-bold uppercase tracking-[0.24em] text-slate-400">Feeling</h3>
            <p class="mt-4 text-base leading-8 text-slate-700"><?= htmlspecialchars((string) $journal['feeling']) ?></p>
        </article>
    </section>

    <form data-reveal method="post" class="mt-6 rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
        <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
        <label for="coordinator_feedback" class="block text-lg font-bold text-slate-950">Coordinator Feedback</label>
        <textarea id="coordinator_feedback" name="coordinator_feedback" class="mt-4 min-h-[140px] w-full rounded-3xl border border-slate-300 bg-white px-5 py-4 text-base text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100"><?= htmlspecialchars((string) ($journal['coordinator_feedback'] ?? '')) ?></textarea>
        <div class="mt-5 flex flex-wrap gap-3">
            <button type="submit" name="action" value="approve_journal" class="rounded-2xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-800">Approve</button>
            <button type="submit" name="action" value="decline_journal" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Decline</button>
        </div>
    </form>
<?php endif; ?>

<?php renderCoordinatorPortalEnd(); ?>
