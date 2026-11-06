<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/JournalModuleService.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$journalService = new JournalModuleService($pdo);
$user = currentUser() ?? [];

function htJournalTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
         LIMIT 1'
    );
    $stmt->execute(['table_name' => $table]);

    return $stmt->fetchColumn() !== false;
}

function htJournalRedirectUrl(int $formId = 0): string
{
    $url = appUrl('head_teacher/journal_management.php');
    if ($formId > 0) {
        $url .= '?form_id=' . $formId;
    }

    return $url;
}

$semesterStmt = $pdo->query(
    "SELECT sem.id, sem.semester_name, sem.semester_no, sem.start_date, sem.practicum_start_date, sem.end_date, sem.semester_status, sy.year_label
     FROM semesters sem
     JOIN school_years sy ON sy.id = sem.school_year_id
     ORDER BY
       CASE sem.semester_status
         WHEN 'active' THEN 0
         WHEN 'planned' THEN 1
         WHEN 'closed' THEN 2
         ELSE 3
       END,
       sy.start_date DESC,
       sem.semester_no ASC
     LIMIT 1"
);
$currentSemester = $semesterStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$semesterId = (int) ($currentSemester['id'] ?? 0);
$isActiveSemester = $currentSemester !== null && (string) ($currentSemester['semester_status'] ?? '') === 'active';
$flash = null;
$redirectFormId = (int) ($_GET['form_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['journal_action'] ?? '');
    $redirectFormId = (int) ($_POST['form_id'] ?? $redirectFormId);

    try {
        if ($action === 'save_form') {
            $formId = (int) ($_POST['form_id'] ?? 0);
            $isActive = (string) ($_POST['is_active'] ?? '0') === '1';
            $savedFormId = $journalService->saveForm(
                $semesterId,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                $isActive,
                (int) ($user['id'] ?? 0),
                $formId > 0 ? $formId : null,
                (int) ($_POST['max_points'] ?? 50)
            );
            $redirectFormId = $savedFormId;
            htSetFlash('success', $isActive ? 'Journal form saved and activated.' : 'Journal form saved.');
        } elseif ($action === 'create_form') {
            $isActive = (string) ($_POST['is_active'] ?? '0') === '1';
            $redirectFormId = $journalService->saveForm(
                $semesterId,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                $isActive,
                (int) ($user['id'] ?? 0),
                null,
                (int) ($_POST['max_points'] ?? 50)
            );
            htSetFlash('success', 'Journal form created.');
        } elseif ($action === 'delete_form') {
            $formId = (int) ($_POST['form_id'] ?? 0);
            $journalService->deleteForm($formId);
            $redirectFormId = 0;
            htSetFlash('success', 'Journal form deleted.');
        } elseif ($action === 'add_question') {
            $journalService->saveQuestion(
                (int) ($_POST['form_id'] ?? 0),
                (string) ($_POST['question_text'] ?? ''),
                (string) ($_POST['question_type'] ?? 'paragraph'),
                isset($_POST['is_required']),
                (int) ($_POST['sort_order'] ?? 0),
                isset($_POST['is_active']),
                (string) ($_POST['options_text'] ?? '')
            );
            htSetFlash('success', 'Journal question added.');
        } elseif ($action === 'update_question') {
            $journalService->saveQuestion(
                (int) ($_POST['form_id'] ?? 0),
                (string) ($_POST['question_text'] ?? ''),
                (string) ($_POST['question_type'] ?? 'paragraph'),
                isset($_POST['is_required']),
                (int) ($_POST['sort_order'] ?? 0),
                isset($_POST['is_active']),
                (string) ($_POST['options_text'] ?? ''),
                (int) ($_POST['question_id'] ?? 0)
            );
            htSetFlash('success', 'Journal question updated.');
        } elseif ($action === 'delete_question') {
            $journalService->deleteQuestion((int) ($_POST['question_id'] ?? 0));
            htSetFlash('success', 'Journal question deleted.');
        } elseif ($action === 'toggle_question') {
            $journalService->toggleQuestionActive(
                (int) ($_POST['question_id'] ?? 0),
                (int) ($_POST['toggle_to'] ?? 0) === 1
            );
            htSetFlash('success', 'Question status updated.');
        } elseif ($action === 'move_question') {
            $journalService->moveQuestion((int) ($_POST['question_id'] ?? 0), (int) ($_POST['direction'] ?? 0));
            htSetFlash('success', 'Question order updated.');
        }
    } catch (Throwable $e) {
        htSetFlash('error', $e->getMessage());
    }

    header('Location: ' . htJournalRedirectUrl($redirectFormId));
    exit;
}

$flash = htGetFlash();
$selectedFormId = (int) ($_GET['form_id'] ?? 0);
$forms = $semesterId > 0 ? $journalService->listFormsForSemester($semesterId) : [];
$form = $semesterId > 0
    ? $journalService->getFormForSemester($semesterId, $selectedFormId, (int) ($user['id'] ?? 0))
    : null;
$canDeleteCurrentForm = $form !== null && $journalService->canDeleteForm((int) ($form['id'] ?? 0));

function htJournalQuestionOptionsText(array $question): string
{
    $options = $question['options'] ?? [];
    if ($options === []) {
        return '';
    }

    $lines = [];
    foreach ($options as $option) {
        $lines[] = (string) ($option['option_text'] ?? '');
    }

    return implode("\n", $lines);
}

$questionTypes = JournalModuleService::questionTypes();
renderHeadTeacherPortalStart($user, 'journal_management');
?>
<section class="mt-6 space-y-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Journal Management</h2>
                <p class="mt-1 text-sm text-slate-600">Create and manage weekly practicum journal forms and questions for the current semester.</p>
            </div>
            <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                <?php if ($semesterId > 0): ?>
                    <button type="button" id="open-create-journal-modal" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                        + Create Journal
                    </button>
                <?php endif; ?>
                <div class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    <?= $currentSemester !== null ? htmlspecialchars((string) $currentSemester['semester_name'] . ' | ' . (string) $currentSemester['year_label']) : 'No semester available' ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <div class="rounded-xl border <?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> px-4 py-3 text-sm whitespace-pre-line">
            <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <?php if ($semesterId <= 0): ?>
        <section class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <p class="text-slate-600">No semester is available yet.</p>
        </section>
    <?php else: ?>
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
                <h3 class="text-lg font-semibold text-slate-900">Form Settings</h3>
                <?php if ($form === null): ?>
                    <p class="mt-4 text-sm text-slate-500">No journal forms yet. Use <strong>+ Create Journal</strong> to add one.</p>
                <?php else: ?>
                    <form method="post" class="mt-4 space-y-4">
                        <input type="hidden" name="journal_action" value="save_form">
                        <input type="hidden" name="form_id" value="<?= (int) ($form['id'] ?? 0) ?>">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Title</label>
                            <input type="text" name="title" value="<?= htmlspecialchars((string) ($form['title'] ?? '')) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" required>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Description</label>
                            <textarea name="description" class="min-h-[120px] w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"><?= htmlspecialchars((string) ($form['description'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="journal-max-points">Maximum Points</label>
                            <input id="journal-max-points" type="number" name="max_points" min="1" max="10000" step="1" value="<?= (int) ($form['max_points'] ?? 50) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" required>
                            <p class="mt-1 text-xs text-slate-500">Coordinators will grade each weekly submission out of this score.</p>
                        </div>
                        <div>
                            <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Status</span>
                            <div class="space-y-2">
                                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input type="radio" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>>
                                    Active
                                </label>
                                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input type="radio" name="is_active" value="0" <?= empty($form['is_active']) ? 'checked' : '' ?>>
                                    Inactive
                                </label>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">Only one journal may be active per semester. Activating this form will deactivate the current active journal.</p>
                        </div>
                        <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save Form</button>
                    </form>

                    <?php if ($canDeleteCurrentForm): ?>
                        <form method="post" class="mt-3" onsubmit="return confirm('Delete this journal form? This cannot be undone.')">
                            <input type="hidden" name="journal_action" value="delete_form">
                            <input type="hidden" name="form_id" value="<?= (int) ($form['id'] ?? 0) ?>">
                            <button type="submit" class="w-full rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-100">Delete Form</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="mt-6">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Semester Forms</h4>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($forms as $entry): ?>
                            <?php
                            $entryId = (int) ($entry['id'] ?? 0);
                            $isSelected = $form !== null && $entryId === (int) ($form['id'] ?? 0);
                            $isEntryActive = !empty($entry['is_active']);
                            ?>
                            <a href="<?= htmlspecialchars(htJournalRedirectUrl($entryId)) ?>"
                               class="block rounded-xl border px-3 py-3 text-sm transition hover:border-slate-300 hover:bg-slate-50 <?= $isSelected ? 'border-slate-400 bg-slate-50 ring-1 ring-slate-300' : 'border-slate-200 bg-white' ?>">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($entry['title'] ?? 'Journal Form')) ?></div>
                                    <span class="shrink-0 text-xs font-medium <?= $isEntryActive ? 'text-emerald-700' : 'text-slate-500' ?>">
                                        <?= $isEntryActive ? '🟢 Active' : '⚪ Inactive' ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($forms === []): ?>
                            <p class="text-sm text-slate-500">No forms yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                <?php if ($form === null): ?>
                    <p class="text-sm text-slate-500">Select or create a journal form to manage questions.</p>
                <?php else: ?>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-xl font-semibold text-slate-900">Questions</h3>
                            <p class="mt-1 text-sm text-slate-600">Use active questions only. Reorder with the arrows, or update sort order directly.</p>
                        </div>
                        <div class="text-sm text-slate-500"><?= count($form['questions'] ?? []) ?> question(s)</div>
                    </div>

                    <div class="mt-5 space-y-4">
                        <?php foreach (($form['questions'] ?? []) as $question): ?>
                            <form method="post" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <input type="hidden" name="journal_action" value="update_question">
                                <input type="hidden" name="form_id" value="<?= (int) ($form['id'] ?? 0) ?>">
                                <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                                <div class="grid gap-4 lg:grid-cols-12">
                                    <div class="lg:col-span-6">
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Question</label>
                                        <textarea name="question_text" class="min-h-[110px] w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm" required><?= htmlspecialchars((string) ($question['question_text'] ?? '')) ?></textarea>
                                    </div>
                                    <div class="lg:col-span-3">
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Type</label>
                                        <select name="question_type" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                            <?php foreach ($questionTypes as $value => $label): ?>
                                                <option value="<?= htmlspecialchars($value) ?>" <?= (string) ($question['question_type'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label class="mt-4 inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <input type="checkbox" name="is_required" value="1" <?= !empty($question['is_required']) ? 'checked' : '' ?>>
                                            Required
                                        </label>
                                        <label class="mt-2 inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <input type="checkbox" name="is_active" value="1" <?= !empty($question['is_active']) ? 'checked' : '' ?>>
                                            Enabled
                                        </label>
                                    </div>
                                    <div class="lg:col-span-3">
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Sort Order</label>
                                        <input type="number" min="0" name="sort_order" value="<?= (int) ($question['sort_order'] ?? 0) ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                        <label class="mt-4 mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Options</label>
                                        <textarea name="options_text" class="min-h-[110px] w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="One option per line"><?= htmlspecialchars(htJournalQuestionOptionsText($question)) ?></textarea>
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-wrap items-center gap-2">
                                    <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save Question</button>
                                    <button type="submit" name="journal_action" value="move_question" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" formaction="<?= htmlspecialchars(appUrl('head_teacher/journal_management.php')) ?>" onclick="this.form.querySelector('input[name=direction]').value='-1';">Move Up</button>
                                    <button type="submit" name="journal_action" value="move_question" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" onclick="this.form.querySelector('input[name=direction]').value='1';">Move Down</button>
                                    <input type="hidden" name="direction" value="0">
                                    <button type="submit" name="journal_action" value="toggle_question" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" onclick="this.form.querySelector('input[name=toggle_to]').value='<?= !empty($question['is_active']) ? '0' : '1' ?>';"><?= !empty($question['is_active']) ? 'Disable' : 'Enable' ?></button>
                                    <button type="submit" name="journal_action" value="delete_question" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700" onclick="return confirm('Delete this question?')">Delete</button>
                                    <input type="hidden" name="toggle_to" value="<?= !empty($question['is_active']) ? '0' : '1' ?>">
                                </div>
                            </form>
                        <?php endforeach; ?>
                        <?php if (($form['questions'] ?? []) === []): ?>
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                                No journal questions yet. Add the first question below.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
                        <h4 class="text-base font-semibold text-slate-900">Add Question</h4>
                        <form method="post" class="mt-4 grid gap-4 lg:grid-cols-12">
                            <input type="hidden" name="journal_action" value="add_question">
                            <input type="hidden" name="form_id" value="<?= (int) ($form['id'] ?? 0) ?>">
                            <div class="lg:col-span-5">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Question Text</label>
                                <textarea name="question_text" class="min-h-[120px] w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" required></textarea>
                            </div>
                            <div class="lg:col-span-3">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Type</label>
                                <select name="question_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    <?php foreach ($questionTypes as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="mt-4 inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input type="checkbox" name="is_required" value="1" checked>
                                    Required
                                </label>
                                <label class="mt-2 inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input type="checkbox" name="is_active" value="1" checked>
                                    Enabled
                                </label>
                            </div>
                            <div class="lg:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Order</label>
                                <input type="number" min="0" name="sort_order" value="<?= count($form['questions'] ?? []) + 1 ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <label class="mt-4 mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Options</label>
                                <textarea name="options_text" class="min-h-[120px] w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="One option per line"></textarea>
                            </div>
                            <div class="lg:col-span-2 lg:flex lg:items-end">
                                <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800">Add Question</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</section>

<?php if ($semesterId > 0): ?>
<div id="create-journal-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
        <h3 class="text-lg font-semibold text-slate-900">Create Journal</h3>
        <form method="post" class="mt-4 space-y-4">
            <input type="hidden" name="journal_action" value="create_form">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Journal Title *</label>
                <input type="text" name="title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Description</label>
                <textarea name="description" class="min-h-[100px] w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="new-journal-max-points">Maximum Points *</label>
                <input id="new-journal-max-points" type="number" name="max_points" min="1" max="10000" step="1" value="50" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" required>
                <p class="mt-1 text-xs text-slate-500">The coordinator grades each weekly submission out of this score.</p>
            </div>
            <div>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Status</span>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="radio" name="is_active" value="1">
                        Active
                    </label>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="radio" name="is_active" value="0" checked>
                        Inactive
                    </label>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" id="close-create-journal-modal" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Create</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('create-journal-modal');
    const openBtn = document.getElementById('open-create-journal-modal');
    const closeBtn = document.getElementById('close-create-journal-modal');
    if (!modal || !openBtn || !closeBtn) {
        return;
    }

    const showModal = () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    const hideModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    openBtn.addEventListener('click', showModal);
    closeBtn.addEventListener('click', hideModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            hideModal();
        }
    });
})();
</script>
<?php endif; ?>

<?php renderHeadTeacherPortalEnd(); ?>
