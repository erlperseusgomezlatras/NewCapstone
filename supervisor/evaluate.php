<?php
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$user = currentUser() ?? [];
$service = new SupervisorService($pdo);
$supervisorId = (int) ($user['id'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);

if (!$service->isAssigned($studentId, $supervisorId)) {
    http_response_code(403);
    exit('You are not authorized to evaluate this student.');
}

$form = $service->activeFormForStudent($studentId);
if (!$form) {
    http_response_code(404);
    exit("No active evaluation form is available.\nPlease contact the Head Teacher.");
}

$submitted = $service->getSubmission($studentId, $supervisorId, (int) $form['id']);
$message = '';
$error = '';
$studentRows = $service->assignedStudents($supervisorId);
$student = array_values(array_filter($studentRows, static fn(array $row): bool => (int) $row['student_id'] === $studentId))[0] ?? [];
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$submitted) {
    try {
        if (trim((string) ($form['certification']['certification_text'] ?? '')) !== '' && empty($_POST['certification_ack'])) {
            throw new RuntimeException('Please confirm the certification statement before submitting.');
        }
        $answers = (array) ($_POST['answers'] ?? []);
        $comments = (array) ($_POST['comments'] ?? []);
        foreach ($form['sections'] as $section) {
            foreach ($section['questions'] as $question) {
                if (!empty($question['is_required']) && !isset($answers[$question['id']])) {
                    throw new RuntimeException('Please answer every required question.');
                }
            }
        }
        foreach ($form['comments'] as $comment) {
            if (!empty($comment['is_required']) && trim((string) ($comments[$comment['id']] ?? '')) === '') {
                throw new RuntimeException('Please complete every required comment field.');
            }
        }
        $signatureData = trim((string) ($_POST['signature_data'] ?? ''));
        if (!empty($form['certification']['require_signature']) && $signatureData === '') {
            throw new RuntimeException('Please draw your digital signature before submitting.');
        }
        $service->submit(
            $studentId,
            $supervisorId,
            $form,
            $answers,
            $comments,
            trim((string) ($_POST['overall_score'] ?? '')),
            $signatureData,
            [
                'supervisor_name' => (string) ($_POST['cert_require_supervisor_name'] ?? ''),
                'date' => $today,
            ]
        );
        $message = 'Evaluation submitted successfully.';
        $submitted = $service->getSubmission($studentId, $supervisorId, (int) $form['id']);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($submitted && !empty($submitted['rating_scale_snapshot'])) {
    $form['ratings'] = $submitted['rating_scale_snapshot'];
}

renderSupervisorStart($user, 'students');
?>
<div class="mx-auto mt-6 flex max-w-4xl items-center justify-between"><a class="text-sm font-semibold text-emerald-700" href="<?= htmlspecialchars(appUrl('supervisor/assigned_students.php?student_id=' . $studentId)) ?>">← Back to student</a><?php if ($submitted): ?><a class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white" href="<?= htmlspecialchars(appUrl('api/download_supervisor_evaluation_pdf.php?student_id=' . $studentId)) ?>">Save as PDF</a><?php endif; ?></div>
<?php if ($message): ?><div class="mx-auto mt-4 max-w-4xl rounded-xl bg-emerald-50 p-4 text-emerald-700"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="mx-auto mt-4 max-w-4xl rounded-xl bg-rose-50 p-4 text-rose-700"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($submitted): ?><div class="mx-auto mt-4 max-w-4xl rounded-xl border border-emerald-200 bg-emerald-50 p-4 font-semibold text-emerald-800">Evaluation Submitted — read-only record.</div><?php endif; ?>
<style>section.mx-auto{display:flex;flex-direction:column}section.mx-auto>div.flex{order:0}section.mx-auto>a,section.mx-auto>p,section.mx-auto>.bg-emerald-50,section.mx-auto>.bg-rose-50{display:none}section.mx-auto>.grid{order:1}section.mx-auto>.whitespace-pre-line{order:2}section.mx-auto>form{order:3}</style>
<section class="mx-auto mt-6 max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex items-center gap-4 border-b border-slate-200 pb-5">
        <img src="<?= htmlspecialchars(appUrl('assets/images/logo_college.png')) ?>" alt="College logo" class="h-20 w-20 object-contain">
        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-emerald-700"><?= htmlspecialchars((string) ($form['institution_name'] ?? 'PHINMA - CAGAYAN DE ORO COLLEGE')) ?></p>
            <p class="font-semibold text-slate-700"><?= htmlspecialchars((string) ($form['department_name'] ?? 'COLLEGE OF EDUCATION')) ?></p>
            <h3 class="mt-1 text-2xl font-bold text-slate-900"><?= htmlspecialchars((string) ($form['title'] ?? 'Evaluation')) ?></h3>
        </div>
    </div>
    <a class="mt-5 inline-block text-sm font-semibold text-emerald-700" href="<?= htmlspecialchars(appUrl('supervisor/assigned_students.php?student_id=' . $studentId)) ?>">← Back to student</a>
    <p class="mt-2 text-sm text-slate-500">Student: <?= htmlspecialchars((string) ($student['student_name'] ?? '')) ?> · <?= htmlspecialchars((string) ($student['partnered_school'] ?? '')) ?></p>
    <?php if ($message): ?><div class="mt-5 rounded-xl bg-emerald-50 p-4 text-emerald-700"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mt-5 rounded-xl bg-rose-50 p-4 text-rose-700"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="mt-5 whitespace-pre-line rounded-xl bg-slate-50 p-4 text-sm"><?= htmlspecialchars((string) $form['instructions']) ?></div>
    <div class="mt-5 grid gap-3 rounded-xl border border-slate-200 p-4 text-sm sm:grid-cols-2">
        <p>Student Name: <strong><?= htmlspecialchars((string) ($student['student_name'] ?? '')) ?></strong></p>
        <p>Student ID: <strong><?= htmlspecialchars((string) ($student['school_id'] ?? '')) ?></strong></p>
        <p>Partnered School: <strong><?= htmlspecialchars((string) ($student['partnered_school'] ?? '')) ?></strong></p>
        <p>Submission Date: <strong><?= $submitted ? htmlspecialchars((string) $submitted['submitted_at']) : 'After submission' ?></strong></p>
    </div>
    <?php if ($submitted): ?><div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 font-semibold text-emerald-800">Evaluation Submitted — read-only record. <a class="ml-3 underline" href="<?= htmlspecialchars(appUrl('api/download_supervisor_evaluation_pdf.php?student_id=' . $studentId)) ?>">Save as PDF</a></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="mt-6 space-y-7">
        <input type="hidden" name="student_id" value="<?= $studentId ?>">
        <?php foreach ($form['sections'] as $section): ?>
            <div>
                <h4 class="border-b border-slate-300 pb-2 text-lg font-bold"><?= htmlspecialchars((string) $section['section_title']) ?></h4>
                <?php foreach ($section['questions'] as $question): ?>
                    <fieldset class="mt-4">
                        <legend class="font-medium"><?= htmlspecialchars((string) $question['question_text']) ?><?= !empty($question['is_required']) ? ' *' : '' ?></legend>
                        <?php $ratingCount = count($form['ratings']); ?>
                        <div class="mt-3 overflow-x-auto">
                            <div class="min-w-[20rem]">
                                <div class="grid items-end gap-2 text-sm font-semibold text-slate-600" style="grid-template-columns: repeat(<?= max(1, $ratingCount) ?>, minmax(0, 1fr));">
                                    <?php foreach ($form['ratings'] as $ratingIndex => $rating): ?>
                                        <span class="<?= $ratingIndex === 0 ? 'text-left' : ($ratingIndex === $ratingCount - 1 ? 'text-right' : 'text-center') ?>"><?= $ratingIndex === 0 ? 'Rating: ' . htmlspecialchars((string) $rating['rating_label']) : ($ratingIndex === $ratingCount - 1 ? htmlspecialchars((string) $rating['rating_label']) : '') ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-1 grid gap-2" style="grid-template-columns: repeat(<?= max(1, $ratingCount) ?>, minmax(0, 1fr));">
                                    <?php foreach ($form['ratings'] as $rating): $checked = $submitted && ((string) ($submitted['answers'][$question['id']] ?? '') === (string) $rating['rating_value']); ?>
                                        <label class="flex items-center justify-center gap-1 text-sm"><input type="radio" name="answers[<?= (int) $question['id'] ?>]" value="<?= htmlspecialchars((string) $rating['rating_value'], ENT_QUOTES) ?>" <?= $checked ? 'checked' : '' ?> <?= $submitted ? 'disabled' : '' ?>><?= htmlspecialchars((string) $rating['rating_value']) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($form['comments']): ?><div><h4 class="text-lg font-bold">Comments</h4><?php foreach ($form['comments'] as $comment): ?><label class="mt-4 block text-sm font-semibold"><?= htmlspecialchars((string) $comment['field_title']) ?><?= !empty($comment['is_required']) ? ' *' : '' ?><textarea name="comments[<?= (int) $comment['id'] ?>]" class="mt-1 w-full rounded-xl border border-slate-300 p-3" placeholder="<?= htmlspecialchars((string) $comment['placeholder'], ENT_QUOTES) ?>" <?= $submitted ? 'disabled' : '' ?>><?= htmlspecialchars((string) ($submitted['comments'][$comment['id']] ?? '')) ?></textarea></label><?php endforeach; ?></div><?php endif; ?>
        <div>
            <h4 class="text-lg font-bold">Certification</h4>
            <?php if (trim((string) ($form['certification']['certification_text'] ?? '')) !== ''): ?><label class="mt-3 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700"><input type="checkbox" name="certification_ack" value="1" class="mt-1 h-4 w-4 shrink-0 accent-emerald-700" <?= $submitted ? 'checked disabled' : 'required' ?>> <span><?= htmlspecialchars((string) $form['certification']['certification_text']) ?></span></label><?php endif; ?>
            <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <?php foreach (['require_supervisor_name' => 'Supervisor Name', 'require_date' => 'Date'] as $key => $label): ?><?php if (!empty($form['certification'][$key])): ?><?php $certValue = $submitted ? (string) ($submitted['certification'][$key === 'require_supervisor_name' ? 'supervisor_name' : 'signed_date'] ?? '') : ($key === 'require_supervisor_name' ? (string) ($user['name'] ?? '') : $today); ?><?php if ($key === 'require_date' && $certValue === '') $certValue = $today; ?><label><?= $label ?><input class="mt-1 w-full rounded-xl border p-2 <?= $key === 'require_date' ? 'bg-slate-100 text-slate-600' : '' ?>" name="cert_<?= $key ?>" type="text" value="<?= htmlspecialchars($certValue, ENT_QUOTES) ?>" <?= $key === 'require_date' || $submitted ? 'readonly' : '' ?> <?= $submitted ? 'disabled' : '' ?> required></label><?php endif; ?><?php endforeach; ?>
            </div>
            <?php if (!empty($form['certification']['require_signature'])): ?>
                <div class="mt-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h5 class="text-sm font-bold text-slate-800">Digital Signature <span class="text-rose-600">*</span></h5>
                            <p class="mt-1 text-xs text-slate-500">Draw your signature using a mouse, trackpad, finger, or stylus.</p>
                        </div>
                        <?php if (!$submitted): ?><button id="clearSignature" type="button" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-600 transition hover:border-emerald-600 hover:text-emerald-700">Clear signature</button><?php endif; ?>
                    </div>
                    <?php if ($submitted && !empty($submitted['signature_data'])): ?>
                        <img src="<?= htmlspecialchars((string) $submitted['signature_data']) ?>" alt="Submitted signature" class="mt-3 max-h-40 max-w-full rounded-xl border border-slate-300 bg-white p-3">
                    <?php else: ?>
                        <div class="mt-3 overflow-hidden rounded-2xl border-2 border-dashed border-emerald-200 bg-emerald-50/40 p-2">
                            <canvas id="signatureCanvas" class="block h-44 w-full touch-none rounded-xl bg-white" aria-label="Digital signature drawing area"></canvas>
                        </div>
                        <input type="hidden" name="signature_data" id="signatureData">
                        <p id="signatureHint" class="mt-2 text-xs text-slate-500">Your signature will be attached to this evaluation when you submit it.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!$submitted): ?><button class="rounded-xl bg-emerald-700 px-5 py-3 font-bold text-white" type="submit">Submit Evaluation</button><?php endif; ?>
    </form>
</section>
<script>
(() => {
    const canvas = document.getElementById('signatureCanvas');
    const field = document.getElementById('signatureData');
    const clear = document.getElementById('clearSignature');
    const hint = document.getElementById('signatureHint');
    if (!canvas || !field) return;
    const context = canvas.getContext('2d');
    let drawing = false;
    let hasSignature = false;
    const resize = () => {
        const box = canvas.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.max(1, Math.floor(box.width * ratio));
        canvas.height = Math.max(1, Math.floor(box.height * ratio));
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.lineCap = 'round'; context.lineJoin = 'round'; context.lineWidth = 2.5; context.strokeStyle = '#0f3d31';
        hasSignature = false; field.value = '';
    };
    const point = event => { const box = canvas.getBoundingClientRect(); return { x: event.clientX - box.left, y: event.clientY - box.top }; };
    const save = () => { if (hasSignature) { field.value = canvas.toDataURL('image/png'); if (hint) hint.textContent = 'Signature captured and ready to submit.'; } };
    canvas.addEventListener('pointerdown', event => { drawing = true; canvas.setPointerCapture(event.pointerId); const p = point(event); context.beginPath(); context.moveTo(p.x, p.y); });
    canvas.addEventListener('pointermove', event => { if (!drawing) return; const p = point(event); context.lineTo(p.x, p.y); context.stroke(); hasSignature = true; });
    const stop = () => { if (!drawing) return; drawing = false; save(); };
    canvas.addEventListener('pointerup', stop); canvas.addEventListener('pointercancel', stop); canvas.addEventListener('pointerleave', stop);
    clear && clear.addEventListener('click', () => { context.clearRect(0, 0, canvas.width, canvas.height); hasSignature = false; field.value = ''; if (hint) hint.textContent = 'Your signature will be attached to this evaluation when you submit it.'; });
    window.addEventListener('resize', resize); resize();
})();
</script>
<?php renderSupervisorEnd(); ?>
