<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/JournalModuleService.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$journalService = new JournalModuleService($pdo);
$context = $attendanceService->getCoordinatorSectionContext((int) ($user['id'] ?? 0)) ?? [];
$hasAssignedSchool = $context !== [] && (int) ($context['geofence_id'] ?? 0) > 0;
$hasActiveSemester = ((string) ($context['semester_status'] ?? '')) === 'active';
$hasStartedPracticum = practicumCanRun((string) ($context['practicum_start_date'] ?? ''), null, (string) ($context['practicum_status'] ?? ''));
$selectedWeek = isset($_GET['week_start'])
    ? practicumWeekStart((string) $_GET['week_start'])
    : ((new DateTimeImmutable('today'))->modify('monday this week')->format('Y-m-d'));
$submissionId = (int) ($_GET['submission_id'] ?? $_POST['submission_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasAssignedSchool && $hasActiveSemester && $hasStartedPracticum) {
    $feedback = trim((string) ($_POST['coordinator_feedback'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');
    $pointsRaw = trim((string) ($_POST['points_awarded'] ?? ''));

    try {
        $existingSubmission = $submissionId > 0 ? $journalService->getSubmissionById($submissionId) : null;
        if ($existingSubmission === null) {
            throw new InvalidArgumentException('Journal submission not found.');
        }
        if ((int) ($existingSubmission['is_reopened'] ?? 0) === 1) {
            throw new InvalidArgumentException('This journal has been reopened and is awaiting student submission.');
        }
        if ((string) ($existingSubmission['status'] ?? '') === 'approved' && $action !== 'save_score') {
            throw new InvalidArgumentException('This journal has already been approved.');
        }
        if ($pointsRaw === '' || !ctype_digit($pointsRaw)) {
            throw new InvalidArgumentException('Enter the coordinator-awarded journal points.');
        }
        $pointsAwarded = (int) $pointsRaw;

        if ($action === 'approve_submission') {
            $journalService->reviewSubmission($submissionId, 'approved', $feedback, (int) ($user['id'] ?? 0), $pointsAwarded);
            coordinatorSetFlash('success', 'Journal graded and approved.');
        } elseif ($action === 'decline_submission') {
            $journalService->reviewSubmission($submissionId, 'declined', $feedback, (int) ($user['id'] ?? 0), $pointsAwarded);
            coordinatorSetFlash('success', 'Journal graded and returned for revision.');
        } elseif ($action === 'save_score') {
            $journalService->reviewSubmission(
                $submissionId,
                (string) ($existingSubmission['status'] ?? 'approved'),
                (string) ($existingSubmission['coordinator_feedback'] ?? ''),
                (int) ($user['id'] ?? 0),
                $pointsAwarded
            );
            coordinatorSetFlash('success', 'Journal score saved.');
        }
    } catch (Throwable $e) {
        coordinatorSetFlash('error', $e->getMessage());
    }

    header('Location: ' . appUrl('coordinator/review_journals.php?submission_id=' . $submissionId . '&week_start=' . urlencode($selectedWeek)));
    exit;
}

$submission = ($hasAssignedSchool && $hasActiveSemester && $hasStartedPracticum && $submissionId > 0)
    ? $journalService->getSubmissionById($submissionId)
    : null;
$flash = coordinatorGetFlash();

if ($submission !== null) {
    $ownershipStmt = $pdo->prepare(
        "SELECT
            js.id,
            u.school_id,
            up.first_name,
            up.last_name,
            sem.practicum_start_date,
            sem.semester_name,
            sy.year_label,
            g.name AS partnered_school
         FROM journal_submissions js
         JOIN users u ON u.id = js.student_user_id
         JOIN user_profiles up ON up.user_id = u.id
         JOIN semesters sem ON sem.id = js.semester_id
         JOIN school_years sy ON sy.id = sem.school_year_id
         JOIN student_geofence_assignments sga
           ON sga.student_user_id = js.student_user_id
          AND sga.semester_id = js.semester_id
          AND sga.assignment_status = 'active'
         JOIN geofence_locations g ON g.id = sga.geofence_id
         WHERE js.id = :submission_id
           AND sga.coordinator_user_id = :coordinator_user_id
         ORDER BY sga.assigned_at DESC, sga.id DESC
         LIMIT 1"
    );
    $ownershipStmt->execute([
        'submission_id' => $submissionId,
        'coordinator_user_id' => (int) ($user['id'] ?? 0),
    ]);
    $submissionMeta = $ownershipStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($submissionMeta === null) {
        $submission = null;
    } else {
        $submission = array_merge($submission, $submissionMeta);
    }
}

$weekLabel = 'Week';
if ($submission !== null) {
    $weekRow = practicumSelectWeek(
        practicumBuildWeeksFromStart((string) ($submission['practicum_start_date'] ?? '')),
        (string) ($submission['week_start'] ?? '')
    );
    if ($weekRow !== null) {
        $weekLabel = practicumWeekDisplayLabel($weekRow);
    }
}
$submissionIsReopened = $submission !== null && (int) ($submission['is_reopened'] ?? 0) === 1;
$submissionStatusLabel = $submissionIsReopened ? 'reopened' : (string) ($submission['status'] ?? 'pending');
$submissionPointsAwarded = $submission !== null ? (int) ($submission['points_awarded'] ?? 0) : 0;
$submissionMaxPoints = $submission !== null ? max(1, (int) ($submission['max_points'] ?? 50)) : 50;

function coordinatorReviewBadge(string $status): string
{
    return match ($status) {
        'approved' => coordinatorTopBadge('Approved', 'green'),
        'declined' => coordinatorTopBadge('Declined', 'red'),
        'reviewed' => coordinatorTopBadge('Reviewed', 'orange'),
        'resubmitted' => coordinatorTopBadge('Resubmitted', 'blue'),
        'reopened' => coordinatorTopBadge('Reopened', 'orange'),
        default => coordinatorTopBadge('Pending', 'blue'),
    };
}

function coordinatorCanReviewJournal(string $status): bool
{
    return !in_array((string) $status, ['approved', 'reopened'], true);
}
?>
<?php renderCoordinatorPortalStart(
    $user,
    'journals',
    'Review Journal',
    $context,
    '<a href="' . htmlspecialchars(appUrl('coordinator/journal_submissions.php?week_start=' . urlencode($selectedWeek)), ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Back</a>'
); ?>

<?php if ($flash !== null): ?>
    <div data-reveal class="<?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> mb-6 rounded-3xl border px-5 py-4 text-sm font-medium shadow-panel">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </div>
<?php endif; ?>

<?php if ($submission === null): ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <?php if ($hasActiveSemester && !$hasStartedPracticum): ?>
            <p class="text-slate-500">Practicum has not started yet.</p>
            <p class="mt-1 text-slate-500">Start Date: <?= htmlspecialchars(!empty($context['practicum_start_date']) ? date('F j, Y', strtotime((string) $context['practicum_start_date'])) : 'Not set') ?></p>
        <?php else: ?>
            <p class="text-slate-500">Journal submission not found for the selected week.</p>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Review Journal</h1>
            <p class="mt-3 text-base text-slate-500 sm:text-lg">
                <?= htmlspecialchars((string) ($submission['last_name'] . ', ' . $submission['first_name'])) ?> | <?= htmlspecialchars($weekLabel) ?>
            </p>
        </div>
        <div>
            <?= coordinatorReviewBadge($submissionStatusLabel) ?>
            <div class="mt-2 text-right text-sm font-bold text-slate-700"><?= $submissionPointsAwarded ?> / <?= $submissionMaxPoints ?> points</div>
        </div>
    </section>

    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
        <div class="grid gap-3 text-sm text-slate-700 sm:grid-cols-2 lg:grid-cols-3">
            <div><span class="font-semibold text-slate-500">Student:</span> <?= htmlspecialchars((string) ($submission['last_name'] . ', ' . $submission['first_name'])) ?></div>
            <div><span class="font-semibold text-slate-500">School ID:</span> <?= htmlspecialchars((string) ($submission['school_id'] ?? '')) ?></div>
            <div><span class="font-semibold text-slate-500">Partnered School:</span> <?= htmlspecialchars((string) ($submission['partnered_school'] ?? '--')) ?></div>
            <div><span class="font-semibold text-slate-500">Semester:</span> <?= htmlspecialchars((string) ($submission['semester_name'] ?? '')) ?></div>
            <div><span class="font-semibold text-slate-500">School Year:</span> <?= htmlspecialchars((string) ($submission['year_label'] ?? '')) ?></div>
            <div><span class="font-semibold text-slate-500">Submitted At:</span> <?= !empty($submission['submitted_at']) ? htmlspecialchars((new DateTimeImmutable((string) $submission['submitted_at']))->format('M j, Y h:i A')) : '--' ?></div>
        </div>
    </section>

    <section class="mt-6 space-y-4">
        <?php foreach (($submission['answers'] ?? []) as $answer): ?>
            <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
                <h3 class="text-xs font-bold uppercase tracking-[0.24em] text-slate-400"><?= htmlspecialchars((string) ($answer['question_text_snapshot'] ?? 'Question')) ?></h3>
                <p class="mt-4 whitespace-pre-line text-base leading-8 text-slate-700"><?= htmlspecialchars($journalService->answerDisplayValue($answer)) ?></p>
            </article>
        <?php endforeach; ?>
        <?php if (($submission['answers'] ?? []) === []): ?>
            <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-panel">
                No dynamic answers were stored for this submission.
            </article>
        <?php endif; ?>
    </section>

    <form data-reveal method="post" class="mt-6 rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
        <input type="hidden" name="submission_id" value="<?= (int) $submissionId ?>">
        <label for="coordinator_feedback" class="block text-lg font-bold text-slate-950">Coordinator Feedback</label>
        <?php $submissionStatus = $submissionStatusLabel; ?>
        <?php $canReview = coordinatorCanReviewJournal($submissionStatus); ?>
        <textarea id="coordinator_feedback" name="coordinator_feedback" <?= $canReview ? '' : 'readonly' ?> class="mt-4 min-h-[140px] w-full rounded-3xl border border-slate-300 bg-white px-5 py-4 text-base text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100 <?= $canReview ? '' : 'bg-slate-50 text-slate-600' ?>"><?= htmlspecialchars((string) ($submission['coordinator_feedback'] ?? '')) ?></textarea>
        <?php if (!$submissionIsReopened): ?>
            <div class="mt-5 max-w-sm">
                <label for="points_awarded" class="block text-sm font-bold text-slate-800">Points Awarded <span class="font-semibold text-slate-500">/ <?= $submissionMaxPoints ?></span></label>
                <input id="points_awarded" name="points_awarded" type="number" min="0" max="<?= $submissionMaxPoints ?>" step="1" value="<?= $submissionPointsAwarded ?>" required class="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3 text-base font-semibold text-slate-900 outline-none focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <p class="mt-1 text-xs text-slate-500">Enter a whole-number grade from 0 to <?= $submissionMaxPoints ?>.</p>
            </div>
        <?php endif; ?>
        <div class="mt-5 flex flex-wrap gap-3">
            <a href="<?= htmlspecialchars(appUrl('api/download_journal_pdf.php') . '?submission_id=' . urlencode((string) $submissionId)) ?>" class="inline-flex items-center gap-2 rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
              </svg>
              Download PDF
            </a>
            <?php if ($canReview): ?>
                <button type="submit" name="action" value="approve_submission" class="rounded-2xl bg-brand-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-800">Save Score &amp; Approve</button>
                <button type="submit" name="action" value="decline_submission" class="rounded-2xl border border-rose-300 bg-white px-5 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-50">Save Score &amp; Decline</button>
            <?php elseif (!$submissionIsReopened): ?>
                <button type="submit" name="action" value="save_score" class="rounded-2xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-800">Update Score</button>
            <?php else: ?>
                <p class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-700">
                    <?= $submissionIsReopened ? 'This journal has been reopened and is waiting for the student to resubmit it.' : 'This journal has already been approved.' ?>
                </p>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<?php renderCoordinatorPortalEnd(); ?>
