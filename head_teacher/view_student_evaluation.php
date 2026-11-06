<?php
declare(strict_types=1);
require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/HeadTeacherEvaluationService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$service = new HeadTeacherEvaluationService(getPDO());
$submission = $service->submittedEvaluation((int) ($_GET['submission_id'] ?? 0));
if (!$submission) { http_response_code(404); exit('Submitted evaluation not found.'); }
$form = $submission['form'];
$safe = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$answerFor = static fn(array $question) => (string) ($submission['answers'][$question['id']] ?? '');
$labelFor = static function (array $ratings, string $value): string {
    foreach ($ratings as $rating) if ((string) $rating['rating_value'] === $value) return (string) $rating['rating_label'];
    return '';
};
renderHeadTeacherPortalStart($user, 'evaluation_management');
?>
<section class="mx-auto mt-6 max-w-5xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><a href="<?= $safe(appUrl('head_teacher/student_evaluation_list.php')) ?>" class="text-sm font-semibold text-emerald-700">← Back to Student List</a><a href="<?= $safe(appUrl('api/download_head_teacher_evaluation_pdf.php?submission_id=' . (int) $submission['id'])) ?>" class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white">Download PDF</a></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex items-center gap-4 border-b border-slate-200 pb-5"><img src="<?= $safe(appUrl('assets/images/logo_college.png')) ?>" alt="College logo" class="h-20 w-20 object-contain"><div><p class="text-sm font-bold uppercase tracking-wide text-emerald-700"><?= $safe($form['institution_name']) ?></p><p class="font-semibold text-slate-700"><?= $safe($form['department_name']) ?></p><h2 class="mt-1 text-2xl font-bold text-slate-900"><?= $safe($form['title']) ?></h2></div></div>
        <div class="mt-5 whitespace-pre-line rounded-xl bg-slate-50 p-4 text-sm text-slate-700"><?= $safe($form['instructions']) ?></div>
        <div class="mt-5 grid gap-3 rounded-xl border border-slate-200 p-4 text-sm sm:grid-cols-2"><p>Student Name: <strong><?= $safe($submission['student_name']) ?></strong></p><p>Student ID: <strong><?= $safe($submission['school_id']) ?></strong></p><p>Section: <strong><?= $safe($submission['section_name'] ?: 'Not set') ?></strong></p><p>Partnered School: <strong><?= $safe($submission['partnered_school'] ?: 'Not set') ?></strong></p><p>Supervisor: <strong><?= $safe($submission['supervisor_name'] ?: 'Not set') ?></strong></p><p>Supervisor Email: <strong><?= $safe($submission['supervisor_email']) ?></strong></p><p>Supervisor Position: <strong><?= $safe($submission['supervisor_position'] ?: ($submission['certification']['supervisor_position'] ?? 'Not set')) ?></strong></p><p>Submission Date: <strong><?= $safe($submission['submitted_at']) ?></strong></p></div>
        <?php foreach ($form['sections'] as $section): ?><div class="mt-8"><h3 class="border-b border-slate-300 pb-2 text-lg font-bold"><?= $safe($section['section_title']) ?></h3><?php foreach ($section['questions'] as $question): $answer = $answerFor($question); $ratingCount = count($form['ratings']); ?><div class="mt-4"><p class="font-medium"><?= $safe($question['question_text']) ?><?= !empty($question['is_required']) ? ' *' : '' ?></p><div class="mt-3 overflow-x-auto"><div class="min-w-[20rem]"><div class="grid items-end gap-2 text-sm font-semibold text-slate-600" style="grid-template-columns: repeat(<?= max(1, $ratingCount) ?>, minmax(0, 1fr));"><?php foreach ($form['ratings'] as $ratingIndex => $rating): ?><span class="<?= $ratingIndex === 0 ? 'text-left' : ($ratingIndex === $ratingCount - 1 ? 'text-right' : 'text-center') ?>"><?= $ratingIndex === 0 ? 'Rating: '.$safe($rating['rating_label']) : ($ratingIndex === $ratingCount - 1 ? $safe($rating['rating_label']) : '') ?></span><?php endforeach; ?></div><div class="mt-1 grid gap-2" style="grid-template-columns: repeat(<?= max(1, $ratingCount) ?>, minmax(0, 1fr));"><?php foreach ($form['ratings'] as $rating): $value = (string) $rating['rating_value']; $selected = $value === $answer; ?><span class="flex items-center justify-center gap-1 text-sm <?= $selected ? 'font-bold text-emerald-700' : '' ?>"><?= $selected ? '◉' : '○' ?> <?= $safe($value) ?></span><?php endforeach; ?></div></div></div></div><?php endforeach; ?></div><?php endforeach; ?>
        <?php if ($form['comments']): ?><div class="mt-8"><h3 class="text-lg font-bold">Comments</h3><?php foreach ($form['comments'] as $comment): ?><div class="mt-4"><p class="text-sm font-semibold"><?= $safe($comment['field_title']) ?></p><div class="mt-1 whitespace-pre-line rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm"><?= $safe($submission['comments'][$comment['id']] ?? 'No comment provided.') ?></div></div><?php endforeach; ?></div><?php endif; ?>
        <div class="mt-8 border-t border-slate-200 pt-5"><h3 class="text-lg font-bold">Certification</h3><p class="mt-2 whitespace-pre-line text-sm text-slate-700"><?= $safe($form['certification']['certification_text'] ?? '') ?></p><div class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><p>Supervisor: <strong><?= $safe($submission['certification']['supervisor_name'] ?? $submission['supervisor_name']) ?></strong></p><p>Position: <strong><?= $safe($submission['certification']['supervisor_position'] ?? $submission['supervisor_position']) ?></strong></p><p>Date: <strong><?= $safe($submission['certification']['signed_date'] ?? $submission['submitted_at']) ?></strong></p></div><?php if ($submission['signature_data'] !== ''): ?><img src="<?= $safe($submission['signature_data']) ?>" alt="Supervisor signature" class="mt-4 max-h-32 max-w-xs rounded border border-slate-300 bg-white p-2"><?php endif; ?></div>
    </div>
</section>
<?php renderHeadTeacherPortalEnd(); ?>
