<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/ChecklistService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$checklistService = new ChecklistService($pdo);
$context = $attendanceService->getCoordinatorSectionContext((int) ($user['id'] ?? 0)) ?? [];
$selectedWeek = coordinatorSelectedWeek($context, isset($_GET['week_start']) ? (string) $_GET['week_start'] : null);
$weekOptions = coordinatorWeekOptions($context);
$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $context !== [] && $selectedWeek !== null) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'start_checklist') {
            $checklistService->initializeWeekChecklist((int) $context['section_id'], $selectedWeek['start']);
            if ($isAjaxRequest) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            coordinatorSetFlash('success', 'Checklist initialized for the selected week.');
        } elseif ($action === 'toggle_checklist') {
            $checklistService->updateChecklistItem(
                (int) ($_POST['student_id'] ?? 0),
                $selectedWeek['start'],
                (string) ($_POST['field'] ?? ''),
                (int) ($_POST['value'] ?? 0)
            );
            if ($isAjaxRequest) {
                $updatedRows = $checklistService->getChecklistBySection((int) $context['section_id'], $selectedWeek['start']);
                $updatedRow = null;
                foreach ($updatedRows as $candidate) {
                    if ((int) ($candidate['student_user_id'] ?? 0) === (int) ($_POST['student_id'] ?? 0)) {
                        $updatedRow = $candidate;
                        break;
                    }
                }

                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => true,
                    'row' => $updatedRow,
                    'session' => $checklistService->getChecklistSessionState((int) $context['section_id'], $selectedWeek['start']),
                ]);
                exit;
            }
            coordinatorSetFlash('success', 'Checklist item updated.');
        }
    } catch (Throwable $e) {
        if ($isAjaxRequest) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
            exit;
        }
        coordinatorSetFlash('error', $e->getMessage());
    }

    header('Location: /practicum_system/coordinator/checklist.php?week_start=' . urlencode((string) $selectedWeek['start']));
    exit;
}

$sessionState = ($context !== [] && $selectedWeek !== null)
    ? $checklistService->getChecklistSessionState((int) $context['section_id'], $selectedWeek['start'])
    : ['status' => 'not_started', 'checklist_date' => null, 'closed_at' => null];
$rows = ($context !== [] && $selectedWeek !== null)
    ? $checklistService->getChecklistBySection((int) $context['section_id'], $selectedWeek['start'])
    : [];
$isStarted = ($sessionState['status'] ?? 'not_started') !== 'not_started';
$isOpen = ($sessionState['status'] ?? '') === 'open';
$isClosed = ($sessionState['status'] ?? '') === 'closed';
$flash = coordinatorGetFlash();
?>
<?php renderCoordinatorPortalStart(
    $user,
    'checklist',
    'Weekly Checklist',
    $context,
    '<a href="/practicum_system/coordinator/history.php" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">History</a>'
); ?>

<?php if ($flash !== null): ?>
    <div data-reveal class="<?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> mb-6 rounded-3xl border px-5 py-4 text-sm font-medium shadow-panel">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </div>
<?php endif; ?>

<?php if ($context !== [] && $selectedWeek !== null): ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Weekly Checklist</h1>
                <span class="inline-flex items-center rounded-full bg-blue-100 px-4 py-2 text-sm font-semibold text-blue-700 ring-1 ring-inset ring-blue-200">
                    <?= htmlspecialchars('Week ' . (string) $selectedWeek['index'] . ($isOpen ? ' - OPEN TODAY' : ($isClosed ? ' - CLOSED' : ' - NOT STARTED'))) ?>
                </span>
            </div>
            <p class="mt-3 max-w-2xl text-base text-slate-500 sm:text-lg">
                Coordinator verifies and marks practicum requirements for <?= htmlspecialchars((string) $context['section_code']) ?> students.
            </p>
        </div>
        <form method="get" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label for="week_start" class="text-sm font-medium text-slate-500">Week:</label>
            <select id="week_start" name="week_start" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <?php foreach ($weekOptions as $option): ?>
                    <option value="<?= htmlspecialchars((string) $option['start']) ?>" <?= $option['start'] === $selectedWeek['start'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Go</button>
        </form>
    </section>

    <section data-reveal class="<?= $isClosed ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-700' ?> rounded-[28px] border px-5 py-4 shadow-panel">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm leading-7">
                <?php if (!$isStarted): ?>
                    Checklist has not been started for this week yet. The coordinator can start it once during the current week on any weekday.
                <?php elseif ($isOpen): ?>
                    Checklist is open today for this week. It will close automatically at the end of the day unless closed manually by the teacher.
                <?php else: ?>
                    Checklist for this week is already closed. It can only be completed once per week.
                <?php endif; ?>
            </div>
            <?php if (!empty($sessionState['checklist_date'])): ?>
                <div class="text-sm font-semibold">
                    Started: <?= htmlspecialchars(date('M j, Y', strtotime((string) $sessionState['checklist_date']))) ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$isStarted): ?>
        <section data-reveal class="rounded-[28px] border border-slate-200 bg-white px-6 py-10 text-center shadow-panel sm:px-10">
            <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-10 w-10"><path d="M9 11l2 2 4-4"/><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3h6"/></svg>
            </div>
            <h2 class="text-2xl font-bold tracking-tight text-slate-950">Start Week <?= (int) $selectedWeek['index'] ?> Checklist</h2>
            <p class="mx-auto mt-4 max-w-2xl text-lg leading-8 text-slate-500">
                Starting the checklist will initialize tracking for all <?= count($rows) ?> students this week. All items will start as unchecked.
            </p>
            <form method="post" class="mt-8">
                <input type="hidden" name="action" value="start_checklist">
                <button type="submit" class="rounded-2xl bg-brand-700 px-6 py-4 text-base font-semibold text-white transition hover:bg-brand-800">Start Checklist for Week <?= (int) $selectedWeek['index'] ?></button>
            </form>
        </section>
    <?php endif; ?>

    <section data-reveal class="mt-6 overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-2xl font-bold tracking-tight text-slate-950">Section <?= htmlspecialchars((string) $context['section_code']) ?> - Checklist Progress</h3>
            </div>
            <div><?= coordinatorTopBadge(count($rows) . ' Students', 'slate') ?></div>
        </div>
        <div class="scroll-slim overflow-x-auto">
            <table class="min-w-full text-left">
                <thead class="bg-slate-50/80">
                    <tr class="text-xs uppercase tracking-[0.22em] text-slate-500">
                        <th class="px-5 py-4 font-semibold">Student Name</th>
                        <th class="px-4 py-4 text-center font-semibold">Orientation</th>
                        <th class="px-4 py-4 text-center font-semibold">Uniform</th>
                        <th class="px-4 py-4 text-center font-semibold">Observation</th>
                        <th class="px-4 py-4 text-center font-semibold">Demo</th>
                        <th class="px-4 py-4 text-center font-semibold">Portfolio</th>
                        <th class="px-4 py-4 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $row): ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-5 py-4 font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?></td>
                            <?php foreach (['orientation', 'uniform', 'observation', 'demo', 'portfolio'] as $field): ?>
                                <?php $isOn = (int) ($row[$field] ?? 0) === 1; ?>
                                <td class="px-4 py-4 text-center">
                                    <?php if ($isStarted): ?>
                                        <button
                                            type="button"
                                            data-checklist-toggle
                                            data-student-id="<?= (int) $row['student_user_id'] ?>"
                                            data-field="<?= htmlspecialchars($field) ?>"
                                            data-next-value="<?= $isOn ? 0 : 1 ?>"
                                            <?= $isOpen ? '' : 'disabled' ?>
                                            class="<?= $isOn ? 'border-emerald-200 bg-emerald-100 text-emerald-700' : 'border-slate-300 bg-white text-slate-400' ?> inline-flex h-11 w-11 items-center justify-center rounded-2xl border transition hover:border-brand-400 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                                            <?php if ($isOn): ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="M5 12l5 5L20 7"/></svg>
                                            <?php else: ?>
                                                <span class="text-xl leading-none">-</span>
                                            <?php endif; ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-300 bg-white text-xl leading-none text-slate-400">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="px-4 py-4" data-row-status="<?= (int) $row['student_user_id'] ?>"><?= $row['status'] === 'Completed' ? coordinatorTopBadge('Completed', 'green') : ($row['status'] === 'In Progress' ? coordinatorTopBadge('In Progress', 'blue') : coordinatorTopBadge('Pending', 'orange')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center text-sm text-slate-500">No students available for checklist tracking.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <p class="text-slate-500">No assigned section found yet.</p>
    </section>
<?php endif; ?>

<?php
$extraScript = '';
if ($context !== [] && $selectedWeek !== null) {
    $extraScript = '<script>
        (function () {
            const buttons = document.querySelectorAll("[data-checklist-toggle]");
            const weekStart = ' . json_encode((string) $selectedWeek['start']) . ';

            function statusBadge(status) {
                if (status === "Completed") {
                    return \'<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset bg-emerald-100 text-emerald-700 ring-emerald-200">Completed</span>\';
                }
                if (status === "In Progress") {
                    return \'<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset bg-blue-100 text-blue-700 ring-blue-200">In Progress</span>\';
                }
                return \'<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-100">Pending</span>\';
            }

            function buttonContent(isOn) {
                if (isOn) {
                    return \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="M5 12l5 5L20 7"></path></svg>\';
                }
                return \'<span class="text-xl leading-none">-</span>\';
            }

            buttons.forEach(function (button) {
                button.addEventListener("click", function () {
                    if (button.disabled) {
                        return;
                    }

                    const studentId = button.getAttribute("data-student-id");
                    const field = button.getAttribute("data-field");
                    const nextValue = button.getAttribute("data-next-value");
                    const formData = new FormData();
                    formData.append("action", "toggle_checklist");
                    formData.append("student_id", studentId || "");
                    formData.append("field", field || "");
                    formData.append("value", nextValue || "0");

                    button.disabled = true;

                    fetch(window.location.pathname + "?week_start=" + encodeURIComponent(weekStart), {
                        method: "POST",
                        headers: {
                            "X-Requested-With": "XMLHttpRequest",
                            "Accept": "application/json"
                        },
                        body: formData
                    })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok || !data.ok) {
                                throw new Error(data.message || "Checklist update failed.");
                            }
                            return data;
                        });
                    })
                    .then(function (data) {
                        const row = data.row || {};
                        const isOn = Number(row[field] || 0) === 1;
                        button.setAttribute("data-next-value", isOn ? "0" : "1");
                        button.className = (isOn
                            ? "border-emerald-200 bg-emerald-100 text-emerald-700"
                            : "border-slate-300 bg-white text-slate-400")
                            + " inline-flex h-11 w-11 items-center justify-center rounded-2xl border transition hover:border-brand-400 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-50";
                        button.innerHTML = buttonContent(isOn);

                        const statusCell = document.querySelector(\'[data-row-status="\' + studentId + \'"]\');
                        if (statusCell && row.status) {
                            statusCell.innerHTML = statusBadge(row.status);
                        }
                    })
                    .catch(function (error) {
                        window.alert(error.message);
                    })
                    .finally(function () {
                        button.disabled = false;
                    });
                });
            });
        })();
    </script>';
}

renderCoordinatorPortalEnd($extraScript);
?>
