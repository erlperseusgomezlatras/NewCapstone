<?php
declare(strict_types=1);
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$user = currentUser() ?? [];
$service = new SupervisorService($pdo);
$supervisorId = (int) ($user['id'] ?? 0);
$students = $service->assignedStudents($supervisorId);
$availableStudents = $service->studentsAvailableForSelection($supervisorId);
$selectionMessage = '';
$selectionError = '';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['supervisor_student_selection_csrf'])) {
    $_SESSION['supervisor_student_selection_csrf'] = bin2hex(random_bytes(32));
}
$selectionCsrf = (string) $_SESSION['supervisor_student_selection_csrf'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_student_selection') {
    try {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if ($submittedToken === '' || !hash_equals($selectionCsrf, $submittedToken)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $service->saveStudentSelection($supervisorId, (array) ($_POST['student_ids'] ?? []));
        $selectionMessage = 'Your student selection has been saved.';
        $students = $service->assignedStudents($supervisorId);
        $availableStudents = $service->studentsAvailableForSelection($supervisorId);
    } catch (Throwable $exception) {
        $selectionError = $exception->getMessage();
    }
}
renderSupervisorStart($user, 'students');
?>
<?php if ($selectionMessage !== ''): ?><div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800"><?= htmlspecialchars($selectionMessage) ?></div><?php endif; ?>
<?php if ($selectionError !== ''): ?><div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800"><?= htmlspecialchars($selectionError) ?></div><?php endif; ?>
<section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Student selection</p>
            <h3 class="mt-1 text-xl font-bold text-slate-900">Choose students to evaluate</h3>
            <p class="mt-1 text-sm text-slate-500">Select up to 6 active students assigned to your partnered school. Only selected students are available for evaluation.</p>
        </div>
        <span class="w-fit rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700"><span data-selected-count><?= count($students) ?></span> / 6 selected</span>
    </div>
    <form method="post" class="mt-5">
        <input type="hidden" name="action" value="save_student_selection">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($selectionCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="w-14 px-4 py-3">Select</th><th class="px-4 py-3">Student Name</th><th class="px-4 py-3">Student ID</th><th class="px-4 py-3">Section</th><th class="px-4 py-3">Partnered School</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($availableStudents as $candidate): ?>
                        <tr>
                            <td class="px-4 py-3"><input data-supervisor-student-choice type="checkbox" name="student_ids[]" value="<?= (int) $candidate['student_id'] ?>" <?= (int) $candidate['selected'] === 1 ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"></td>
                            <td class="px-4 py-3 font-semibold text-slate-800"><?= htmlspecialchars((string) $candidate['student_name']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars((string) $candidate['school_id']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars((string) ($candidate['section_name'] ?? 'Not set')) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars((string) $candidate['partnered_school']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$availableStudents): ?><tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No active-semester students are currently assigned to your partnered school.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($availableStudents): ?><button type="submit" class="mt-4 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">Save Selection</button><?php endif; ?>
    </form>
</section>
<section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Placement roster</p>
            <h3 class="mt-1 text-xl font-bold text-slate-900">Assigned Students</h3>
            <p class="mt-1 text-sm text-slate-500">Only the students you selected above are assigned to you for evaluation.</p>
        </div>
        <span class="w-fit rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700"><?= count($students) ?> student(s)</span>
    </div>
    <div class="mt-5 overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Student Name</th><th class="px-4 py-3">Student ID</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Partnered School</th><th class="px-4 py-3">Course</th><th class="px-4 py-3">Section</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($students as $row): ?><tr><td class="px-4 py-3 font-semibold text-slate-800"><?= htmlspecialchars((string) $row['student_name']) ?></td><td class="px-4 py-3"><?= htmlspecialchars((string) $row['school_id']) ?></td><td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string) $row['email']) ?></td><td class="px-4 py-3"><?= htmlspecialchars((string) $row['partnered_school']) ?></td><td class="px-4 py-3"><?= htmlspecialchars((string) ($row['course'] ?? 'Not set')) ?></td><td class="px-4 py-3"><?= htmlspecialchars((string) ($row['section_name'] ?? 'Not set')) ?></td></tr><?php endforeach; ?>
                <?php if (!$students): ?><tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No active-semester students are currently assigned to your partnered school.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-5 rounded-xl border border-sky-100 bg-sky-50 px-4 py-3 text-sm text-sky-900">Evaluation actions are available in <a class="font-bold underline" href="<?= htmlspecialchars(appUrl('supervisor/evaluate_students.php')) ?>">Evaluate Students</a>.</div>
</section>
<script>
(() => {
    const choices = Array.from(document.querySelectorAll('[data-supervisor-student-choice]'));
    const counter = document.querySelector('[data-selected-count]');
    const updateSelectionLimit = () => {
        const selected = choices.filter((choice) => choice.checked).length;
        if (counter) counter.textContent = String(selected);
        choices.forEach((choice) => {
            choice.disabled = !choice.checked && selected >= 6;
        });
    };
    choices.forEach((choice) => choice.addEventListener('change', updateSelectionLimit));
    updateSelectionLimit();
})();
</script>
<?php renderSupervisorEnd(); ?>
