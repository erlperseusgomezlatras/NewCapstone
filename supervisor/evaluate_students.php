<?php
declare(strict_types=1);
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$user = currentUser() ?? [];
$supervisorId = (int) ($user['id'] ?? 0);
$service = new SupervisorService($pdo);
$students = $service->assignedStudents($supervisorId);
$activeForm = $students ? $service->activeFormForStudent((int) $students[0]['student_id']) : null;
renderSupervisorStart($user, 'evaluations');
?>
<section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Supervisor workspace</p>
            <h3 class="mt-1 text-xl font-bold text-slate-900">Evaluate Students</h3>
            <p class="mt-1 text-sm text-slate-500">Complete one evaluation for each assigned student when Head Teacher opens an evaluation form.</p>
        </div>
        <?php if ($activeForm): ?><span class="w-fit rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700">Evaluation open</span><?php else: ?><span class="w-fit rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Evaluation locked</span><?php endif; ?>
    </div>
    <?php if ($activeForm): ?>
        <div class="mt-5 flex flex-col gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Active Evaluation Form</p><p class="mt-1 font-bold text-emerald-950"><?= htmlspecialchars((string) $activeForm['title']) ?></p></div><p class="text-sm text-emerald-800">You may now evaluate your assigned students.</p></div>
    <?php else: ?>
        <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p class="font-bold">Evaluation has not started yet.</p><p class="mt-1">Ask the Head Teacher to select and start an evaluation form. Student evaluation remains unavailable until then.</p></div>
    <?php endif; ?>
    <div class="mt-5 overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Student</th><th class="px-4 py-3">Student ID</th><th class="px-4 py-3">Partnered School</th><th class="px-4 py-3">Evaluation Status</th><th class="px-4 py-3 text-right">Action</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($students as $student): ?>
                    <?php $studentForm = $service->activeFormForStudent((int) $student['student_id']); $submission = $studentForm ? $service->getSubmission((int) $student['student_id'], $supervisorId, (int) $studentForm['id']) : null; ?>
                    <tr><td class="px-4 py-3"><p class="font-semibold text-slate-800"><?= htmlspecialchars((string) $student['student_name']) ?></p><p class="text-xs text-slate-500"><?= htmlspecialchars((string) $student['email']) ?></p></td><td class="px-4 py-3"><?= htmlspecialchars((string) $student['school_id']) ?></td><td class="px-4 py-3"><?= htmlspecialchars((string) $student['partnered_school']) ?></td><td class="px-4 py-3"><?php if (!$studentForm): ?><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">Locked</span><?php elseif ($submission): ?><span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700">Submitted</span><?php else: ?><span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-bold text-sky-700">Ready</span><?php endif; ?></td><td class="px-4 py-3 text-right"><?php if (!$studentForm): ?><span class="text-xs font-semibold text-slate-400">Waiting for matching form</span><?php elseif ($submission): ?><a class="inline-flex rounded-xl border border-emerald-300 px-3 py-2 text-xs font-bold text-emerald-700 hover:bg-emerald-50" href="<?= htmlspecialchars(appUrl('supervisor/evaluate.php?student_id=' . (int) $student['student_id'])) ?>">View evaluation</a><?php else: ?><a class="inline-flex rounded-xl bg-emerald-700 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-800" href="<?= htmlspecialchars(appUrl('supervisor/evaluate.php?student_id=' . (int) $student['student_id'])) ?>">Evaluate student</a><?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$students): ?><tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No students are assigned to your partnered school.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php renderSupervisorEnd(); ?>
