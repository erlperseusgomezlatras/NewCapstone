<?php
declare(strict_types=1);
require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/HeadTeacherEvaluationService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$service = new HeadTeacherEvaluationService(getPDO());
$semesterId = $service->activeSemesterId();
$school = trim((string) ($_GET['school'] ?? ''));
$section = trim((string) ($_GET['section'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$evaluationStatus = trim((string) ($_GET['evaluation_status'] ?? ''));
$options = $service->filterOptions($semesterId);
$students = $service->students($semesterId, $school, $section, $search, $evaluationStatus);
$safe = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

renderHeadTeacherPortalStart($user, 'evaluation_management');
?>
<section class="mt-6 space-y-6">
    <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div><h2 class="text-2xl font-semibold text-slate-900">Student Evaluation List</h2><p class="mt-1 text-sm text-slate-600">View evaluation completion and submitted supervisor evaluations.</p></div>
        <a href="<?= $safe(appUrl('head_teacher/evaluation_management.php')) ?>" class="text-sm font-semibold text-emerald-700">Back to Evaluation Management</a>
    </div>

    <form method="get" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm md:grid-cols-4">
        <label class="text-sm font-semibold text-slate-700">Partnered School
            <select name="school" class="mt-1 w-full rounded-xl border border-slate-300 p-3 font-normal">
                <option value="">All Schools</option>
                <?php foreach ($options['schools'] as $value): ?><option value="<?= $safe($value) ?>" <?= $school === $value ? 'selected' : '' ?>><?= $safe($value) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-700">Section
            <select name="section" class="mt-1 w-full rounded-xl border border-slate-300 p-3 font-normal">
                <option value="">All Sections</option>
                <?php foreach ($options['sections'] as $value): ?><option value="<?= $safe($value) ?>" <?= $section === $value ? 'selected' : '' ?>><?= $safe($value) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-700 md:col-span-2">Search Student Name or Student ID
            <input type="search" name="search" value="<?= $safe($search) ?>" placeholder="Search Student Name or Student ID" class="mt-1 w-full rounded-xl border border-slate-300 p-3 font-normal">
        </label>
        <label class="text-sm font-semibold text-slate-700">Evaluation Status
            <select name="evaluation_status" class="mt-1 w-full rounded-xl border border-slate-300 p-3 font-normal">
                <option value="">All Statuses</option>
                <?php foreach (['Submitted', 'In Progress', 'Not Evaluated'] as $status): ?>
                    <option value="<?= $safe($status) ?>" <?= $evaluationStatus === $status ? 'selected' : '' ?>><?= $safe($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="md:col-span-4 flex flex-wrap gap-2"><button class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-800">Apply Filters</button><a href="<?= $safe(appUrl('head_teacher/student_evaluation_list.php')) ?>" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700">Clear</a></div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-4">Student Name</th><th class="px-5 py-4">Student ID</th><th class="px-5 py-4">Section</th><th class="px-5 py-4">Partnered School</th><th class="px-5 py-4">Supervisor</th><th class="px-5 py-4">Evaluation Status</th><th class="px-5 py-4">Action</th></tr></thead><tbody class="divide-y divide-slate-100">
        <?php foreach ($students as $student): ?><tr><td class="px-5 py-4 font-semibold text-slate-900"><?= $safe($student['student_name']) ?></td><td class="px-5 py-4"><?= $safe($student['school_id']) ?></td><td class="px-5 py-4"><?= $safe($student['section_name'] ?: 'Not set') ?></td><td class="px-5 py-4"><?= $safe($student['partnered_school'] ?: 'Not set') ?></td><td class="px-5 py-4"><?= $safe($student['supervisor_name'] ?: 'Not assigned') ?></td><td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs font-semibold <?= $student['evaluation_status'] === 'Submitted' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>"><?= $safe($student['evaluation_status']) ?></span></td><td class="px-5 py-4"><?php if (!empty($student['submission_id'])): ?><a class="font-bold text-emerald-700 hover:underline" href="<?= $safe(appUrl('head_teacher/view_student_evaluation.php?submission_id=' . (int) $student['submission_id'])) ?>">View Evaluation</a><?php else: ?><span class="text-slate-400">—</span><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$students): ?><tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">No students match the selected filters.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</section>
<?php renderHeadTeacherPortalEnd(); ?>
