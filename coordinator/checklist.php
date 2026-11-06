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
$coordinatorUserId = (int) ($user['id'] ?? 0);
$assignedGeofenceId = (int) ($context['geofence_id'] ?? 0);
$activeSemesterId = (int) ($context['semester_id'] ?? 0);
$activeChecklistItems = $activeSemesterId > 0
    ? $checklistService->getActiveChecklistItemsForSemester($activeSemesterId)
    : [];
$selectedWeek = coordinatorSelectedWeek($context, isset($_GET['week_start']) ? (string) $_GET['week_start'] : null);
$studentSearch = trim((string) ($_GET['student_search'] ?? ''));
$weekOptions = coordinatorWeekOptions($context);
$hasAssignedSchool = $context !== [] && $assignedGeofenceId > 0;
$hasActiveSemester = $activeSemesterId > 0
    && ((string) ($context['semester_status'] ?? '')) === 'active'
    && ((string) ($context['year_status'] ?? '')) === 'active';
$hasChecklistContext = $hasAssignedSchool && $hasActiveSemester;
$hasStartedPracticum = practicumCanRun((string) ($context['practicum_start_date'] ?? ''), null, (string) ($context['practicum_status'] ?? ''));
$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasChecklistContext && $selectedWeek !== null) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'start_checklist') {
            $checklistService->initializeWeekChecklistForSchool($assignedGeofenceId, $activeSemesterId, $selectedWeek['start'], $coordinatorUserId);
            if ($isAjaxRequest) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            coordinatorSetFlash('success', 'Checklist initialized for the selected week.');
        } elseif ($action === 'toggle_checklist') {
            $checklistService->updateChecklistItemForSchool(
                (int) ($_POST['student_id'] ?? 0),
                $assignedGeofenceId,
                $activeSemesterId,
                $selectedWeek['start'],
                (int) ($_POST['checklist_item_id'] ?? 0),
                (int) ($_POST['value'] ?? 0),
                $coordinatorUserId
            );
            if ($isAjaxRequest) {
                $updatedRows = $checklistService->getChecklistBySchool($assignedGeofenceId, $activeSemesterId, $selectedWeek['start'], $coordinatorUserId);
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
                    'session' => $checklistService->getChecklistSessionStateForSchool($assignedGeofenceId, $activeSemesterId, $selectedWeek['start']),
                ]);
                exit;
            }
            coordinatorSetFlash('success', 'Checklist item updated.');
        } elseif ($action === 'confirm_checklist') {
            $checklistService->confirmChecklistForStudent(
                (int) ($_POST['student_id'] ?? 0),
                $assignedGeofenceId,
                $activeSemesterId,
                $selectedWeek['start'],
                $coordinatorUserId
            );
            if ($isAjaxRequest) {
                $updatedRows = $checklistService->getChecklistBySchool($assignedGeofenceId, $activeSemesterId, $selectedWeek['start'], $coordinatorUserId);
                $updatedRow = null;
                foreach ($updatedRows as $candidate) {
                    if ((int) ($candidate['student_user_id'] ?? 0) === (int) ($_POST['student_id'] ?? 0)) {
                        $updatedRow = $candidate;
                        break;
                    }
                }

                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'row' => $updatedRow]);
                exit;
            }
            coordinatorSetFlash('success', 'Checklist confirmed for the selected student.');
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

    $redirectQuery = http_build_query([
        'week_start' => (string) $selectedWeek['start'],
        'student_search' => $studentSearch,
    ]);
    header('Location: ' . appUrl('coordinator/checklist.php?' . $redirectQuery));
    exit;
}

$sessionState = ($hasChecklistContext && $selectedWeek !== null)
    ? $checklistService->getChecklistSessionStateForSchool($assignedGeofenceId, $activeSemesterId, $selectedWeek['start'])
    : ['status' => 'not_started', 'checklist_date' => null, 'closed_at' => null];
    $rows = ($hasChecklistContext && $selectedWeek !== null)
    ? $checklistService->getChecklistBySchool($assignedGeofenceId, $activeSemesterId, $selectedWeek['start'], $coordinatorUserId)
    : [];
$allRows = $rows;
if ($studentSearch !== '') {
    $searchNeedle = function_exists('mb_strtolower') ? mb_strtolower($studentSearch) : strtolower($studentSearch);
    $rows = array_values(array_filter($rows, static function (array $row) use ($searchNeedle): bool {
        $haystack = implode(' ', [
            (string) ($row['first_name'] ?? ''),
            (string) ($row['last_name'] ?? ''),
            (string) ($row['school_id'] ?? ''),
            (string) ($row['student_user_id'] ?? ''),
        ]);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);
        return str_contains($haystack, $searchNeedle);
    }));
}
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
    '<a href="' . htmlspecialchars(appUrl('coordinator/history.php'), ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">History</a>',
    true
); ?>

<?php if ($flash !== null): ?>
    <div data-reveal class="<?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> mb-6 rounded-3xl border px-5 py-4 text-sm font-medium shadow-panel">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </div>
<?php endif; ?>

<?php if ($hasChecklistContext && $selectedWeek !== null): ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Weekly Checklist</h1>
                <span class="inline-flex items-center rounded-full bg-blue-100 px-4 py-2 text-sm font-semibold text-blue-700 ring-1 ring-inset ring-blue-200">
                    <?= htmlspecialchars(practicumWeekDisplayLabel($selectedWeek) . ($isOpen ? ' - OPEN TODAY' : ($isClosed ? ' - CLOSED' : ' - NOT STARTED'))) ?>
                </span>
                <?= practicumWeekStatusBadge($selectedWeek) ?>
            </div>
            <p class="mt-3 max-w-2xl text-base text-slate-500 sm:text-lg">
                Coordinator verifies and marks practicum requirements for students under the assigned school during the active semester.
            </p>
        </div>
        <form method="get" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label for="week_start" class="text-sm font-medium text-slate-500">Week:</label>
            <select id="week_start" name="week_start" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <?php foreach ($weekOptions as $option): ?>
                    <option value="<?= htmlspecialchars((string) $option['start']) ?>" <?= $option['start'] === $selectedWeek['start'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(practicumWeekFilterLabel($option)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="student_search" class="text-sm font-medium text-slate-500">Search Student:</label>
            <input id="student_search" name="student_search" type="search" value="<?= htmlspecialchars($studentSearch) ?>" placeholder="Name or ID" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
            <button type="submit" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Go</button>
        </form>
    </section>

    <section data-reveal class="<?= $isClosed ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-700' ?> rounded-[28px] border px-5 py-4 shadow-panel">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm leading-7">
                <?php if (!$isStarted): ?>
                    Checklist has not been started for this week yet. The coordinator can start it once during the active practicum week on any weekday.
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
            <h2 class="text-2xl font-bold tracking-tight text-slate-950">Start <?= htmlspecialchars(practicumWeekDisplayLabel($selectedWeek)) ?> Checklist</h2>
            <p class="mx-auto mt-4 max-w-2xl text-lg leading-8 text-slate-500">
                Starting the checklist will initialize tracking for all <?= count($allRows) ?> students this week. All items will start as unchecked.
            </p>
            <?php if ($activeChecklistItems === []): ?>
                <p class="mx-auto mt-3 max-w-2xl text-sm font-medium text-rose-600">
                    No active checklist items are available yet. Ask the Head Teacher to add checklist criteria first.
                </p>
            <?php endif; ?>
            <form method="post" class="mt-8">
                <input type="hidden" name="action" value="start_checklist">
                <button type="submit" <?= $activeChecklistItems === [] ? 'disabled' : '' ?> class="rounded-2xl bg-brand-700 px-6 py-4 text-base font-semibold text-white transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50">Start Checklist for <?= htmlspecialchars(practicumWeekDisplayLabel($selectedWeek)) ?></button>
            </form>
        </section>
    <?php endif; ?>

    <section data-reveal class="mt-6 overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-2xl font-bold tracking-tight text-slate-950">Assigned School Checklist Progress</h3>
            </div>
            <div><?= coordinatorTopBadge(count($rows) . ' Students', 'slate') ?></div>
        </div>
        <div class="scroll-slim overflow-x-auto">
            <table class="min-w-full text-left">
                <thead class="bg-slate-50/80">
                    <tr class="text-xs uppercase tracking-[0.22em] text-slate-500">
                        <th class="px-5 py-4 font-semibold">Student Name</th>
                        <?php foreach ($activeChecklistItems as $item): ?>
                            <th class="px-4 py-4 text-center font-semibold">
                                <?= htmlspecialchars((string) $item['item_name']) ?>
                                <div class="mt-1 text-[10px] font-medium normal-case tracking-normal text-slate-400"><?= (int) $item['points'] ?> pts</div>
                            </th>
                        <?php endforeach; ?>
                        <th class="px-4 py-4 font-semibold">Total Points</th>
                        <th class="px-4 py-4 font-semibold">Checklist</th>
                        <th class="px-4 py-4 font-semibold">Confirm</th>
                    </tr>
                </thead>
                <tbody id="checklistRows" class="divide-y divide-slate-100">
                    <?php foreach ($rows as $row): ?>
                                <?php
                                $studentUserId = (int) ($row['student_user_id'] ?? 0);
                                ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-5 py-4 font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?></td>
                            <?php foreach (($row['items'] ?? []) as $itemState): ?>
                                <?php
                                $isOn = (int) ($itemState['is_checked'] ?? 0) === 1;
                                $itemId = (int) ($itemState['id'] ?? 0);
                                $toggleId = 'checklist-toggle-' . $studentUserId . '-' . $itemId;
                                ?>
                                <td class="px-4 py-4 text-center">
                                    <?php if ($isStarted): ?>
                                        <button
                                            id="<?= htmlspecialchars($toggleId) ?>"
                                            type="button"
                                            data-checklist-toggle
                                            data-checklist-key="<?= htmlspecialchars($toggleId) ?>"
                                            data-student-id="<?= $studentUserId ?>"
                                            data-checklist-item-id="<?= $itemId ?>"
                                            data-week-start="<?= htmlspecialchars((string) $selectedWeek['start']) ?>"
                                            data-next-value="<?= $isOn ? 0 : 1 ?>"
                                            <?= $isOpen && empty($row['confirmed_at']) ? '' : 'disabled' ?>
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
                            <td class="px-4 py-4 font-semibold text-slate-900" data-row-total-points="<?= (int) $row['student_user_id'] ?>"><?= (int) ($row['total_points'] ?? 0) ?></td>
                            <td class="px-4 py-4" data-row-status="<?= (int) $row['student_user_id'] ?>">
                                <?= $row['status'] === 'Confirmed'
                                    ? coordinatorTopBadge('Confirmed', 'green')
                                    : ($row['status'] === 'Completed'
                                        ? coordinatorTopBadge('Completed', 'green')
                                        : ($row['status'] === 'In Progress'
                                            ? coordinatorTopBadge('In Progress', 'blue')
                                            : coordinatorTopBadge('Pending', 'orange'))) ?>
                            </td>
                            <td class="px-4 py-4" data-row-confirm="<?= (int) $studentUserId ?>">
                                <?php if (($row['status'] ?? '') === 'Confirmed' || !empty($row['confirmed_at'])): ?>
                                    <?= coordinatorTopBadge('Confirmed', 'green') ?>
                                <?php elseif ($isOpen): ?>
                                    <button type="button" data-checklist-confirm data-student-id="<?= $studentUserId ?>" data-week-start="<?= htmlspecialchars((string) $selectedWeek['start']) ?>" class="rounded-2xl bg-brand-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50">Confirm</button>
                                <?php else: ?>
                                    <span class="text-sm text-slate-400">Unavailable</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="<?= 4 + count($activeChecklistItems) ?>" class="px-5 py-12 text-center text-sm text-slate-500">No students available for checklist tracking.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <p class="text-slate-500">
            <?= !$hasAssignedSchool
                ? 'No partnered school is assigned to your coordinator account yet. Please contact the Head Teacher.'
                : ($hasActiveSemester && !$hasStartedPracticum
                    ? 'Practicum has not started yet. Start Date: ' . (!empty($context['practicum_start_date']) ? date('F j, Y', strtotime((string) $context['practicum_start_date'])) : 'Not set')
                    : 'No active semester found for checklist loading yet.') ?>
        </p>
    </section>
<?php endif; ?>

<?php
$extraScript = '';
if ($hasChecklistContext && $selectedWeek !== null) {
    $extraScript = <<<'HTML'
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        (function () {
            const rowsEl = document.getElementById("checklistRows");
            const pendingKeys = new Set();

            function statusBadge(status) {
                if (status === "Confirmed") {
                    return '<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset bg-emerald-100 text-emerald-700 ring-emerald-200">Confirmed</span>';
                }
                if (status === "Completed") {
                    return '<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset bg-emerald-100 text-emerald-700 ring-emerald-200">Completed</span>';
                }
                if (status === "In Progress") {
                    return '<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset bg-blue-100 text-blue-700 ring-blue-200">In Progress</span>';
                }
                return '<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-100">Pending</span>';
            }

            rowsEl?.addEventListener("click", async function (event) {
                const button = event.target instanceof Element
                    ? event.target.closest("[data-checklist-confirm]")
                    : null;
                if (!(button instanceof HTMLButtonElement) || button.disabled) {
                    return;
                }

                const studentId = button.getAttribute("data-student-id") || "";
                const requestWeek = button.getAttribute("data-week-start") || "";
                const confirmed = window.Swal
                    ? (await Swal.fire({
                        title: "Confirm student checklist?",
                        text: "This will finalize the checklist for this student, even if some items are unchecked.",
                        icon: "question",
                        showCancelButton: true,
                        confirmButtonText: "Yes, confirm",
                        cancelButtonText: "Cancel",
                        confirmButtonColor: "#047857"
                    })).isConfirmed
                    : window.confirm("Confirm this student's checklist? This will finalize it even if some items are unchecked.");
                if (!confirmed) {
                    return;
                }

                const formData = new FormData();
                formData.append("action", "confirm_checklist");
                formData.append("student_id", studentId);
                button.disabled = true;
                button.textContent = "Confirming...";

                fetch(window.location.pathname + "?week_start=" + encodeURIComponent(requestWeek), {
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
                            throw new Error(data.message || "Checklist confirmation failed.");
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    const row = data.row || {};
                    const statusCell = document.querySelector('[data-row-status="' + studentId + '"]');
                    if (statusCell) {
                        statusCell.innerHTML = statusBadge("Confirmed");
                    }
                    const confirmCell = document.querySelector('[data-row-confirm="' + studentId + '"]');
                    if (confirmCell) {
                        confirmCell.innerHTML = statusBadge("Confirmed");
                    }
                    document.querySelectorAll('[data-checklist-toggle][data-student-id="' + studentId + '"]').forEach(function (itemButton) {
                        itemButton.disabled = true;
                    });
                })
                .catch(function (error) {
                    button.disabled = false;
                    button.textContent = "Confirm";
                    window.alert(error.message);
                });
            });

            function buttonContent(isOn) {
                if (isOn) {
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="M5 12l5 5L20 7"></path></svg>';
                }
                return '<span class="text-xl leading-none">-</span>';
            }

            function setButtonVisualState(button, isOn) {
                button.setAttribute("data-next-value", isOn ? "0" : "1");
                button.className = (isOn
                    ? "border-emerald-200 bg-emerald-100 text-emerald-700"
                    : "border-slate-300 bg-white text-slate-400")
                    + " inline-flex h-11 w-11 items-center justify-center rounded-2xl border transition hover:border-brand-400 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-50";
                button.innerHTML = buttonContent(isOn);
            }

            rowsEl?.addEventListener("click", function (event) {
                const button = event.target instanceof Element
                    ? event.target.closest("[data-checklist-toggle]")
                    : null;
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }
                if (button.disabled) {
                    return;
                }

                const pendingKey = button.getAttribute("data-checklist-key") || "";
                if (pendingKey !== "" && pendingKeys.has(pendingKey)) {
                    return;
                }

                const studentId = button.getAttribute("data-student-id");
                const checklistItemId = button.getAttribute("data-checklist-item-id");
                const nextValue = button.getAttribute("data-next-value");
                const requestWeek = button.getAttribute("data-week-start") || "";
                const formData = new FormData();
                formData.append("action", "toggle_checklist");
                formData.append("student_id", studentId || "");
                formData.append("checklist_item_id", checklistItemId || "");
                formData.append("value", nextValue || "0");

                if (pendingKey !== "") {
                    pendingKeys.add(pendingKey);
                }
                button.setAttribute("aria-busy", "true");
                button.classList.add("opacity-60");

                fetch(window.location.pathname + "?week_start=" + encodeURIComponent(requestWeek), {
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
                    const items = Array.isArray(row.items) ? row.items : [];
                    const itemState = items.find(function (item) {
                        return Number(item.id || 0) === Number(checklistItemId || 0);
                    }) || {};
                    const isOn = Number(itemState.is_checked || 0) === 1;
                    setButtonVisualState(button, isOn);

                    const totalPointsCell = document.querySelector('[data-row-total-points="' + studentId + '"]');
                    if (totalPointsCell) {
                        totalPointsCell.textContent = String(Number(row.total_points || 0));
                    }

                    const statusCell = document.querySelector('[data-row-status="' + studentId + '"]');
                    if (statusCell && row.status) {
                        statusCell.innerHTML = statusBadge(row.status);
                    }
                })
                .catch(function (error) {
                    window.alert(error.message);
                })
                .finally(function () {
                    if (pendingKey !== "") {
                        pendingKeys.delete(pendingKey);
                    }
                    button.removeAttribute("aria-busy");
                    button.classList.remove("opacity-60");
                });
            });
        })();
    </script>
HTML;
}

renderCoordinatorPortalEnd($extraScript);
?>
