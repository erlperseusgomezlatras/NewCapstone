<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ConcernService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$pdo = getPDO();
$concernService = new ConcernService($pdo);
$semester = $pdo->query("SELECT id, semester_name FROM semesters WHERE semester_status = 'active' ORDER BY start_date DESC, id DESC LIMIT 1")->fetch() ?: null;
$semesterId = (int) ($semester['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $concernId = (int) ($_POST['concern_id'] ?? 0);
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'reply_concern') {
            $concernService->replyToConcern($concernId, (int) ($user['id'] ?? 0), 'head_teacher', (string) ($_POST['message'] ?? ''), $_FILES['attachment'] ?? null);
            htSetFlash('success', 'Reply sent to the concern thread.');
        }
    } catch (Throwable $e) {
        htSetFlash('error', $e->getMessage());
    }
    header('Location: ' . appUrl('head_teacher/concerns.php'));
    exit;
}

$flash = htGetFlash();
$concerns = $concernService->getHeadTeacherConcerns($semesterId);

function headTeacherConcernBadge(string $status): string
{
    $label = match ($status) {
        'resolved' => 'Resolved',
        'declined' => 'Declined',
        default => 'Pending',
    };
    $class = match ($status) {
        'resolved' => 'bg-emerald-100 text-emerald-700',
        'declined' => 'bg-rose-100 text-rose-700',
        default => 'bg-amber-100 text-amber-700',
    };
    return '<span class="rounded-full px-3 py-1 text-xs font-bold ' . $class . '">' . htmlspecialchars($label) . '</span>';
}
?>
<?php renderHeadTeacherPortalStart($user, 'concerns'); ?>

<?php if ($flash !== null): ?>
    <div class="mb-6 rounded-2xl border px-5 py-4 text-sm font-medium <?= ($flash['type'] ?? '') === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>"><?= htmlspecialchars((string) ($flash['message'] ?? '')) ?></div>
<?php endif; ?>

<section class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div><p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-700">Student support</p><h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950">Concern Conversations</h1><p class="mt-2 text-slate-500">Monitor student concerns. Coordinators handle the conversation and make the final decision.</p></div>
    <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-700"><?= count(array_filter($concerns, static fn(array $c): bool => ($c['concern_status'] ?? 'pending') === 'pending')) ?> Pending</span>
</section>

<?php if ($concerns === []): ?>
    <section class="rounded-3xl border border-slate-200 bg-white p-8 text-slate-500 shadow-sm">No concerns have been submitted for the active semester.</section>
<?php else: ?>
    <section class="space-y-5">
        <?php foreach ($concerns as $concern): ?>
            <?php $status = (string) ($concern['concern_status'] ?? 'pending'); ?>
            <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 class="text-xl font-bold text-slate-950"><?= htmlspecialchars((string) ($concern['subject'] ?? 'Concern')) ?></h2><p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars((string) ($concern['student_name'] ?? 'Student')) ?> · <?= htmlspecialchars((string) ($concern['school_id'] ?? '')) ?></p></div><?= headTeacherConcernBadge($status) ?></div>
                <div class="mt-5 space-y-3 rounded-2xl bg-slate-50 p-4">
                    <?php foreach (($concern['messages'] ?? []) as $message): ?>
                        <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3"><div class="flex flex-wrap justify-between gap-2 text-xs text-slate-500"><span class="font-semibold text-slate-700"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($message['sender_role'] ?? 'user')))) ?></span><span><?= !empty($message['created_at']) ? htmlspecialchars((new DateTimeImmutable((string) $message['created_at']))->format('M j, Y h:i A')) : '--' ?></span></div><?php if (trim((string) ($message['message'] ?? '')) !== ''): ?><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700"><?= htmlspecialchars((string) $message['message']) ?></p><?php endif; ?><?php if (!empty($message['attachment_path'])): ?><?php $attachmentUrl = appUrl((string) $message['attachment_path']); ?><?php if (preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $message['attachment_path'])): ?><img class="mt-3 max-h-80 w-auto rounded-xl border border-slate-200 object-contain" src="<?= htmlspecialchars($attachmentUrl) ?>" alt="<?= htmlspecialchars((string) ($message['attachment_name'] ?? 'Concern attachment')) ?>"><?php else: ?><a download="<?= htmlspecialchars((string) ($message['attachment_name'] ?? 'attachment')) ?>" class="mt-2 inline-block text-sm font-semibold text-emerald-700 hover:underline" href="<?= htmlspecialchars($attachmentUrl) ?>">Download attachment: <?= htmlspecialchars((string) ($message['attachment_name'] ?? 'file')) ?></a><?php endif; ?><?php endif; ?></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($status === 'pending'): ?>
                    <p class="mt-4 text-sm text-slate-500">Waiting for the assigned coordinator to review the conversation and decide the outcome.</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php renderHeadTeacherPortalEnd(); ?>
