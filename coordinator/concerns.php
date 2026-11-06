<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/ConcernService.php';
require_once __DIR__ . '/_portal.php';

function coordinatorConcernStatusLabel(string $status): string
{
    return match ($status) {
        'resolved' => 'Resolved',
        'declined' => 'Declined',
        default => 'Pending',
    };
}

function coordinatorConcernStatusBadge(string $status): string
{
    return match ($status) {
        'resolved' => coordinatorTopBadge('Resolved', 'green'),
        'declined' => coordinatorTopBadge('Declined', 'red'),
        default => coordinatorTopBadge('Pending', 'orange'),
    };
}

$user = currentUser() ?? [];
$coordinatorUserId = (int) ($user['id'] ?? 0);
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$concernService = new ConcernService($pdo);
$context = $attendanceService->getCoordinatorSectionContext($coordinatorUserId) ?? [];
$semesterId = (int) ($context['semester_id'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$weekStart = (string) ($_GET['week_start'] ?? $_POST['week_start'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $studentId > 0) {
    $action = (string) ($_POST['action'] ?? '');
    $concernId = (int) ($_POST['concern_id'] ?? 0);

    try {
        if ($action === 'reply_concern') {
            $concernService->replyAsCoordinator($concernId, $coordinatorUserId, $studentId, $semesterId, (string) ($_POST['message'] ?? ''), $_FILES['attachment'] ?? null);
            coordinatorSetFlash('success', 'Reply sent to the student.');
        } elseif ($action === 'resolve_concern') {
            $concernService->updateConcernStatus($coordinatorUserId, $studentId, $semesterId, $concernId, 'resolved');
            coordinatorSetFlash('success', 'Concern marked as resolved.');
        } elseif ($action === 'decline_concern') {
            $concernService->updateConcernStatus($coordinatorUserId, $studentId, $semesterId, $concernId, 'declined');
            coordinatorSetFlash('success', 'Concern declined.');
        }
    } catch (Throwable $e) {
        coordinatorSetFlash('error', $e->getMessage());
    }

    $redirectQuery = ['student_id' => $studentId];
    if ($weekStart !== '') {
        $redirectQuery['week_start'] = $weekStart;
    }
    header('Location: ' . appUrl('coordinator/concerns.php?' . http_build_query($redirectQuery)));
    exit;
}

$flash = coordinatorGetFlash();
$studentProfile = $studentId > 0 ? $concernService->getCoordinatorManagedStudentProfile($coordinatorUserId, $studentId) : null;
$concerns = $studentProfile !== null ? $concernService->getCoordinatorStudentConcerns($coordinatorUserId, $studentId, $semesterId) : [];

$backQuery = $weekStart !== '' ? '?week_start=' . urlencode($weekStart) : '';
?>
<?php renderCoordinatorPortalStart(
    $user,
    'dashboard',
    'Student Concerns',
    $context,
    '<a href="' . htmlspecialchars(appUrl('coordinator/dashboard.php' . $backQuery), ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Back</a>'
); ?>

<?php if ($flash !== null): ?>
    <div data-reveal class="<?= ($flash['type'] ?? '') === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> mb-6 rounded-3xl border px-5 py-4 text-sm font-medium shadow-panel">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </div>
<?php endif; ?>

<?php if ($studentProfile === null): ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <h1 class="text-3xl font-bold tracking-tight text-slate-950">Student Concerns</h1>
        <p class="mt-3 text-slate-500">This student is not under your current handled list.</p>
    </section>
<?php else: ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Student Concerns</h1>
            <p class="mt-3 text-base text-slate-500 sm:text-lg">
                <?= htmlspecialchars((string) ($studentProfile['student_name'] ?? 'Student')) ?> | <?= htmlspecialchars((string) ($studentProfile['school_id'] ?? 'N/A')) ?>
            </p>
            <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars((string) ($studentProfile['email'] ?? '')) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <span><?= coordinatorTopBadge((string) count($concerns) . ' Total', 'slate') ?></span>
            <span><?= coordinatorTopBadge((string) count(array_filter($concerns, static fn(array $concern): bool => (string) ($concern['concern_status'] ?? '') === 'pending')) . ' Pending', 'orange') ?></span>
        </div>
    </section>

    <?php if ($concerns === []): ?>
        <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
            <p class="text-slate-500">This student has not submitted any concerns yet.</p>
        </section>
    <?php else: ?>
        <section class="space-y-4">
            <?php foreach ($concerns as $concern): ?>
                <?php
                $status = (string) ($concern['concern_status'] ?? 'pending');
                $coordinatorName = trim((string) ($concern['coordinator_name'] ?? ''));
                ?>
                <article data-reveal class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-2xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars((string) ($concern['subject'] ?? 'Concern')) ?></h2>
                            <p class="mt-2 text-sm text-slate-500">
                                Submitted <?= !empty($concern['created_at']) ? htmlspecialchars((new DateTimeImmutable((string) $concern['created_at']))->format('M j, Y h:i A')) : '--' ?>
                            </p>
                            <p class="mt-1 text-sm text-slate-500">
                                Handled by:
                                <span class="font-semibold text-slate-700"><?= htmlspecialchars($coordinatorName !== '' ? $coordinatorName : 'Not assigned yet') ?></span>
                            </p>
                        </div>
                        <div><?= coordinatorConcernStatusBadge($status) ?></div>
                    </div>

                    <button type="button" data-coordinator-concern-open="<?= (int) ($concern['id'] ?? 0) ?>" class="mt-4 w-full rounded-2xl border border-emerald-200 bg-white px-5 py-3 text-left text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Open conversation and decision panel</button>
                    <div id="coordinator-concern-modal-<?= (int) ($concern['id'] ?? 0) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm" data-coordinator-concern-modal>
                      <div class="flex max-h-[94vh] w-full max-w-[1500px] flex-col overflow-hidden rounded-[28px] border-2 border-white bg-slate-50 shadow-2xl">
                        <header class="flex items-center justify-between gap-4 bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-800 px-6 py-5 text-white sm:px-8"><div class="flex items-center gap-4"><span class="grid h-12 w-12 place-items-center rounded-2xl bg-emerald-500 text-2xl">☏</span><div><h3 class="text-xl font-black sm:text-2xl"><?= htmlspecialchars((string) ($concern['subject'] ?? 'Concern')) ?></h3><p class="mt-1 text-sm text-emerald-100">Conversation, attachments, and final feedback</p></div></div><div class="flex items-center gap-3"><?php if ($status !== 'pending'): ?><span class="rounded-full bg-emerald-500 px-4 py-2 text-sm font-bold">✓ <?= htmlspecialchars(coordinatorConcernStatusLabel($status)) ?></span><?php endif; ?><button type="button" data-coordinator-concern-close aria-label="Close" class="rounded-full bg-white/10 px-4 py-2 text-2xl text-white hover:bg-white/20">&times;</button></div></header>
                        <div class="min-h-0 flex-1 gap-3 overflow-y-auto p-3 sm:p-4" style="display:grid;grid-template-columns:190px minmax(0,1fr) 300px;align-items:stretch">
                        <aside class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-emerald-800 text-2xl font-bold text-white"><?= htmlspecialchars(strtoupper(substr((string) ($studentProfile['first_name'] ?? 'S'), 0, 1) . substr((string) ($studentProfile['last_name'] ?? 'T'), 0, 1))) ?></div><h4 class="mt-3 text-center font-black text-slate-900"><?= htmlspecialchars((string) ($studentProfile['student_name'] ?? 'Student')) ?></h4><p class="text-center text-sm text-slate-500">Student</p><div class="my-4 border-t border-slate-100"></div><dl class="space-y-3 text-sm"><div><dt class="text-slate-500">Student ID</dt><dd class="font-bold text-slate-900"><?= htmlspecialchars((string) ($studentProfile['school_id'] ?? '—')) ?></dd></div><div><dt class="text-slate-500">Email</dt><dd class="break-all font-semibold text-slate-800"><?= htmlspecialchars((string) ($studentProfile['email'] ?? '—')) ?></dd></div><div><dt class="text-slate-500">Coordinator</dt><dd class="font-semibold text-slate-800"><?= htmlspecialchars($coordinatorName !== '' ? $coordinatorName : 'You') ?></dd></div></dl></aside>
                                                <main style="min-width:0" class="flex min-h-[420px] min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4"><h4 class="font-black text-slate-900">💬 Conversation &amp; Feedback</h4><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800"><?= count($concern['messages'] ?? []) ?> messages</span></div><div class="flex-1 space-y-4 overflow-y-auto bg-slate-50/60 p-4 sm:p-5">
                                <?php foreach (($concern['messages'] ?? []) as $message): ?>
                                    <?php
                                    $messageRole = (string) ($message['sender_role'] ?? 'user');
                                    $isOwnMessage = $messageRole === 'coordinator';
                                    $senderName = trim((string) ($message['sender_name'] ?? ''));
                                    $senderLabel = $isOwnMessage
                                        ? 'You · Coordinator'
                                        : ucwords(str_replace('_', ' ', $messageRole)) . ($senderName !== '' ? ' · ' . $senderName : '');
                                    $messageTime = !empty($message['created_at']) ? (new DateTimeImmutable((string) $message['created_at']))->format('M j, Y h:i A') : '--';
                                    ?>
                                    <div class="flex <?= $isOwnMessage ? 'justify-end' : 'justify-start' ?>">
                                        <article class="w-full max-w-[88%] rounded-2xl border px-4 py-3 shadow-sm <?= $isOwnMessage ? 'border-emerald-700 bg-emerald-800 text-white' : 'border-slate-200 bg-white text-slate-800' ?>">
                                            <div class="flex flex-wrap justify-between gap-2 text-xs <?= $isOwnMessage ? 'text-emerald-100' : 'text-slate-500' ?>"><span class="font-semibold <?= $isOwnMessage ? 'text-white' : 'text-slate-700' ?>"><?= htmlspecialchars($senderLabel) ?></span><span><?= htmlspecialchars($messageTime) ?></span></div>
                                            <?php if (trim((string) ($message['message'] ?? '')) !== ''): ?><p class="mt-2 whitespace-pre-line text-sm leading-6 <?= $isOwnMessage ? 'text-white' : 'text-slate-700' ?>"><?= htmlspecialchars((string) $message['message']) ?></p><?php endif; ?>
                                            <?php if (!empty($message['attachment_path'])): ?>
                                                <?php $attachmentUrl = appUrl((string) $message['attachment_path']); ?>
                                                <?php if (preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $message['attachment_path'])): ?>
                                                    <img class="mt-3 max-h-80 w-auto rounded-xl border <?= $isOwnMessage ? 'border-white/30' : 'border-slate-200' ?> object-contain" src="<?= htmlspecialchars($attachmentUrl) ?>" alt="<?= htmlspecialchars((string) ($message['attachment_name'] ?? 'Concern attachment')) ?>">
                                                <?php else: ?>
                                                    <a download="<?= htmlspecialchars((string) ($message['attachment_name'] ?? 'attachment')) ?>" class="mt-2 inline-block text-sm font-semibold <?= $isOwnMessage ? 'text-emerald-100 hover:text-white' : 'text-emerald-700 hover:underline' ?>" href="<?= htmlspecialchars($attachmentUrl) ?>">Download attachment: <?= htmlspecialchars((string) ($message['attachment_name'] ?? 'file')) ?></a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </article>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($status === 'pending'): ?>
                                <form method="post" enctype="multipart/form-data" class="border-t border-slate-200 bg-white p-4 sm:p-5">
                                    <input type="hidden" name="student_id" value="<?= $studentId ?>"><input type="hidden" name="concern_id" value="<?= (int) ($concern['id'] ?? 0) ?>"><input type="hidden" name="week_start" value="<?= htmlspecialchars($weekStart) ?>"><input type="hidden" name="action" value="reply_concern">
                                    <label class="mb-2 block text-sm font-bold text-slate-800">Reply in conversation</label>
                                    <textarea name="message" required class="min-h-20 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm" placeholder="Write a message to the student..."></textarea>
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-3"><input type="file" name="attachment" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" class="min-w-0 flex-1 text-xs"><button type="submit" class="rounded-xl bg-emerald-800 px-5 py-2.5 text-sm font-bold text-white">Send Reply</button></div>
                                </form>
                            <?php endif; ?>
                        </main>

                        <aside style="min-width:0" class="flex min-w-0 flex-col gap-3">
                            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h4 class="font-black text-slate-900">Concern Details</h4><dl class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200 px-3 text-sm"><div class="flex justify-between gap-3 py-3"><dt class="text-slate-500">Concern Type</dt><dd class="font-bold text-emerald-800"><?= htmlspecialchars((string) ($concern['category'] ?? 'General')) ?></dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-slate-500">Date Filed</dt><dd class="text-right font-medium"><?= !empty($concern['created_at']) ? htmlspecialchars((new DateTimeImmutable((string) $concern['created_at']))->format('M j, Y · g:i A')) : '—' ?></dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-slate-500">Status</dt><dd><?= coordinatorConcernStatusBadge($status) ?></dd></div><?php if (!empty($concern['updated_at']) && $status !== 'pending'): ?><div class="flex justify-between gap-3 py-3"><dt class="text-slate-500">Updated</dt><dd class="text-right font-medium"><?= htmlspecialchars((new DateTimeImmutable((string) $concern['updated_at']))->format('M j, Y · g:i A')) ?></dd></div><?php endif; ?></dl></section>
                                                        <?php if ($status === 'pending'): ?>
                                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                    <h4 class="font-black text-slate-900">Coordinator Decision</h4><p class="mt-1 text-xs text-slate-500">Set the final status of this concern.</p>
                                    <div class="mt-3 grid gap-2">
                                        <form method="post"><input type="hidden" name="student_id" value="<?= $studentId ?>"><input type="hidden" name="concern_id" value="<?= (int) ($concern['id'] ?? 0) ?>"><input type="hidden" name="week_start" value="<?= htmlspecialchars($weekStart) ?>"><button type="submit" name="action" value="resolve_concern" data-concern-decision="resolve" class="w-full rounded-2xl bg-emerald-700 px-4 py-3 text-sm font-bold text-white">Mark as Resolved</button></form>
                                        <form method="post"><input type="hidden" name="student_id" value="<?= $studentId ?>"><input type="hidden" name="concern_id" value="<?= (int) ($concern['id'] ?? 0) ?>"><input type="hidden" name="week_start" value="<?= htmlspecialchars($weekStart) ?>"><button type="submit" name="action" value="decline_concern" data-concern-decision="decline" class="w-full rounded-2xl border border-rose-300 bg-white px-4 py-3 text-sm font-bold text-rose-700">Decline</button></form>
                                    </div>
                                </section>
                            <?php else: ?>
                                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h4 class="font-black text-slate-900">Add Final Feedback</h4><p class="mt-1 text-xs text-slate-500">Send the student your final resolution message.</p>
                                    <form method="post" class="mt-3 flex flex-col gap-3"><input type="hidden" name="student_id" value="<?= $studentId ?>"><input type="hidden" name="concern_id" value="<?= (int) ($concern['id'] ?? 0) ?>"><input type="hidden" name="week_start" value="<?= htmlspecialchars($weekStart) ?>"><input type="hidden" name="action" value="reply_concern"><textarea name="message" required class="min-h-24 rounded-xl border border-slate-300 px-4 py-3 text-sm" placeholder="Type your final feedback to the student..."></textarea><button type="submit" class="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white">Send Final Feedback</button></form>
                                </section>
                            <?php endif; ?>
                        </aside>
                    </div>
                      </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<script>
document.querySelectorAll('[data-coordinator-concern-modal]').forEach((modal) => document.body.appendChild(modal));
const syncCoordinatorConcernModalScroll = () => {
  const hasOpenModal = Array.from(document.querySelectorAll('[data-coordinator-concern-modal]')).some((modal) => !modal.classList.contains('hidden'));
  document.body.classList.toggle('overflow-hidden', hasOpenModal);
};
document.querySelectorAll('[data-coordinator-concern-open]').forEach((button) => button.addEventListener('click', () => {
  const modal = document.getElementById('coordinator-concern-modal-' + button.dataset.coordinatorConcernOpen);
  if (modal) { modal.classList.remove('hidden'); modal.classList.add('flex'); syncCoordinatorConcernModalScroll(); }
}));
document.querySelectorAll('[data-coordinator-concern-close]').forEach((button) => button.addEventListener('click', () => {
  const modal = button.closest('[data-coordinator-concern-modal]');
  if (modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); syncCoordinatorConcernModalScroll(); }
}));
document.querySelectorAll('[data-coordinator-concern-modal]').forEach((modal) => modal.addEventListener('click', (event) => {
  if (event.target === modal) {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    syncCoordinatorConcernModalScroll();
  }
}));
document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('[data-coordinator-concern-modal]').forEach((modal) => {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  });
  syncCoordinatorConcernModalScroll();
});
document.querySelectorAll('[data-concern-decision]').forEach((button) => button.addEventListener('click', (event) => {
  event.preventDefault();
  const resolving = button.dataset.concernDecision === 'resolve';
  Swal.fire({title: resolving ? 'Mark this concern as resolved?' : 'Decline this concern?', text: resolving ? 'The student will see that this concern is resolved.' : 'The student will see that this concern was declined.', icon: 'question', showCancelButton: true, confirmButtonText: resolving ? 'Resolve concern' : 'Decline concern', cancelButtonText: 'Go back', confirmButtonColor: resolving ? '#047857' : '#e11d48'}).then((result) => { if (result.isConfirmed) { const form = button.closest('form'); const action = document.createElement('input'); action.type = 'hidden'; action.name = 'action'; action.value = button.value; form.appendChild(action); form.submit(); } });
}));
<?php if ($flash !== null): ?>
Swal.fire({icon: <?= json_encode(($flash['type'] ?? '') === 'error' ? 'error' : 'success') ?>, title: <?= json_encode(($flash['type'] ?? '') === 'error' ? 'Concern update failed' : 'Concern updated') ?>, text: <?= json_encode((string) ($flash['message'] ?? '')) ?>, confirmButtonColor: '#047857'});
<?php endif; ?>
</script>

<?php renderCoordinatorPortalEnd(); ?>
