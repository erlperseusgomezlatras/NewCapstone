<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/JournalModuleService.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$journalService = new JournalModuleService($pdo);
$context = $attendanceService->getCoordinatorSectionContext((int) ($user['id'] ?? 0)) ?? [];
$weekOptions = array_values(array_filter(
    coordinatorWeekOptions($context),
    static fn(array $week): bool => (string) ($week['status'] ?? '') !== 'upcoming'
));
$requestedWeekStart = $_GET['week_start'] ?? $_POST['week_start'] ?? null;
$selectedWeek = coordinatorSelectedWeek($context, is_string($requestedWeekStart) ? $requestedWeekStart : null);
if ($selectedWeek !== null && (string) ($selectedWeek['status'] ?? '') === 'upcoming') {
    $selectedWeek = practicumActiveWeek($weekOptions) ?? ($weekOptions[0] ?? $selectedWeek);
}
$selectedWeekRow = null;
foreach ($weekOptions as $weekOption) {
    if ($selectedWeek !== null && (string) ($weekOption['start'] ?? '') === (string) ($selectedWeek['start'] ?? '')) {
        $selectedWeekRow = $weekOption;
        break;
    }
}
$selectedWeekRow = $selectedWeekRow ?? $selectedWeek;
$hasAssignedSchool = $context !== [] && (int) ($context['geofence_id'] ?? 0) > 0;
$hasActiveSemester = ((string) ($context['semester_status'] ?? '')) === 'active';
$hasStartedPracticum = practicumCanRun((string) ($context['practicum_start_date'] ?? ''), null, (string) ($context['practicum_status'] ?? ''));
$studentSearch = trim((string) ($_GET['student_search'] ?? $_POST['student_search'] ?? ''));
$selectedStudentSection = trim((string) ($_GET['section_code'] ?? $_POST['section_code'] ?? ''));
$selectedJournalStatus = trim((string) ($_GET['journal_status'] ?? $_POST['journal_status'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasAssignedSchool && $hasActiveSemester && $hasStartedPracticum && $selectedWeek !== null) {
    $action = (string) ($_POST['action'] ?? '');
    $selectedStudentId = max(0, (int) ($_POST['student_id'] ?? 0));
    try {
        if ($action === 'reopen_all_journals') {
            if ($selectedWeekRow === null || (string) ($selectedWeekRow['status'] ?? '') !== 'completed') {
                throw new InvalidArgumentException('Only closed past weeks can be reopened.');
            }

            $activeForm = $journalService->getActiveFormWithQuestions((int) ($context['semester_id'] ?? 0));
            if ($activeForm === null) {
                throw new InvalidArgumentException('No active journal form is available for reopening.');
            }

            $weekNo = 0;
            foreach ($weekOptions as $index => $weekOption) {
                if ((string) ($weekOption['start'] ?? '') === (string) $selectedWeekRow['start']) {
                    $weekNo = (int) ($weekOption['index'] ?? $index + 1);
                    break;
                }
            }
            if ($weekNo <= 0) {
                throw new InvalidArgumentException('Unable to resolve the selected week.');
            }

            $reopenedCount = $journalService->reopenCoordinatorWeek(
                (int) ($user['id'] ?? 0),
                (int) ($context['semester_id'] ?? 0),
                (string) $selectedWeekRow['start'],
                (int) ($activeForm['id'] ?? 0),
                $weekNo
            );
            coordinatorSetFlash('success', $reopenedCount === 0
                ? 'No assigned students were found for the selected week.'
                : 'Journal reopened for ' . $reopenedCount . ' assigned student' . ($reopenedCount === 1 ? '' : 's') . '.');
        } elseif ($action === 'reopen_journal') {
            if ($selectedStudentId <= 0) {
                throw new InvalidArgumentException('Invalid student selection.');
            }

            if ($selectedWeekRow === null) {
                throw new InvalidArgumentException('Select a valid practicum week.');
            }
            if ((string) ($selectedWeekRow['status'] ?? '') !== 'completed') {
                throw new InvalidArgumentException('Only closed past weeks can be reopened.');
            }

            $activeForm = $journalService->getActiveFormWithQuestions((int) ($context['semester_id'] ?? 0));
            if ($activeForm === null) {
                throw new InvalidArgumentException('No active journal form is available for reopening.');
            }

            $weekNo = 0;
            foreach ($weekOptions as $index => $weekOption) {
                if ((string) ($weekOption['start'] ?? '') === (string) $selectedWeekRow['start']) {
                    $weekNo = (int) ($weekOption['index'] ?? $index + 1);
                    break;
                }
            }
            if ($weekNo <= 0) {
                throw new InvalidArgumentException('Unable to resolve the selected week.');
            }

            $journalService->reopenMissingSubmission(
                (int) ($user['id'] ?? 0),
                $selectedStudentId,
                (int) ($context['semester_id'] ?? 0),
                (string) $selectedWeekRow['start'],
                (int) ($activeForm['id'] ?? 0),
                $weekNo
            );
            coordinatorSetFlash('success', 'Journal reopened for the selected student and week.');
        } else {
            $submissionId = (int) ($_POST['submission_id'] ?? 0);
            $existingSubmission = $submissionId > 0 ? $journalService->getSubmissionById($submissionId) : null;
            if ($existingSubmission === null) {
                throw new InvalidArgumentException('Journal submission not found.');
            }
            if (in_array($action, ['approve_submission', 'decline_submission'], true)) {
                throw new InvalidArgumentException('Open the journal submission to enter its score before reviewing it.');
            }
            if ((string) ($existingSubmission['status'] ?? '') === 'approved') {
                throw new InvalidArgumentException('This journal has already been approved.');
            }

            if ($action === 'approve_submission') {
                $journalService->reviewSubmission(
                    $submissionId,
                    'approved',
                    trim((string) ($_POST['coordinator_feedback'] ?? '')) ?: null,
                    (int) ($user['id'] ?? 0)
                );
                coordinatorSetFlash('success', 'Journal approved.');
            } elseif ($action === 'decline_submission') {
                $journalService->reviewSubmission(
                    $submissionId,
                    'declined',
                    trim((string) ($_POST['coordinator_feedback'] ?? '')) ?: null,
                    (int) ($user['id'] ?? 0)
                );
                coordinatorSetFlash('success', 'Journal declined and returned for revision.');
            }
        }
    } catch (Throwable $e) {
        coordinatorSetFlash('error', $e->getMessage());
    }

    $redirectQuery = [
        'week_start' => (string) ($selectedWeek['start'] ?? ''),
        'student_search' => $studentSearch,
        'section_code' => $selectedStudentSection,
        'journal_status' => $selectedJournalStatus,
    ];
    header('Location: ' . appUrl('coordinator/journal_submissions.php?' . http_build_query($redirectQuery)));
    exit;
}

$rows = ($hasAssignedSchool && $hasActiveSemester && $hasStartedPracticum && $selectedWeek !== null)
    ? $journalService->getCoordinatorSubmissions((int) ($user['id'] ?? 0), (int) ($context['semester_id'] ?? 0), (string) $selectedWeek['start'])
    : [];
$studentSections = [];
foreach ($rows as $studentRow) {
    $sectionCode = trim((string) ($studentRow['section_code'] ?? ''));
    $sectionName = trim((string) ($studentRow['section_name'] ?? ''));
    if ($sectionCode !== '' || $sectionName !== '') {
        $studentSections[$sectionCode !== '' ? $sectionCode : $sectionName] = $sectionName !== '' ? $sectionName : $sectionCode;
    }
}
$filteredRows = array_values(array_filter(
    $rows,
    static function (array $row) use ($studentSearch, $selectedStudentSection, $selectedJournalStatus): bool {
        $name = strtolower(trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
        $query = strtolower($studentSearch);
        $sectionCode = trim((string) ($row['section_code'] ?? ''));
        $sectionName = trim((string) ($row['section_name'] ?? ''));
        $section = $sectionCode !== '' ? $sectionCode : $sectionName;
        $hasSubmission = !empty($row['submission_id']);
        $isReopened = (int) ($row['is_reopened'] ?? 0) === 1;
        $status = $isReopened ? 'Reopened' : (!$hasSubmission ? 'Missing' : match ((string) ($row['status'] ?? '')) {
            'approved' => 'Approved',
            'declined' => 'Declined',
            'reviewed' => 'Reviewed',
            'resubmitted' => 'Resubmitted',
            default => !empty($row['submitted_at']) ? 'Submitted' : 'Pending',
        });

        return ($query === '' || str_contains($name, $query))
            && ($selectedStudentSection === '' || $section === $selectedStudentSection)
            && ($selectedJournalStatus === '' || $status === $selectedJournalStatus);
    }
));
$hasUnreopenedStudent = false;
foreach ($rows as $row) {
    if ((int) ($row['has_been_reopened'] ?? 0) !== 1) {
        $hasUnreopenedStudent = true;
        break;
    }
}
$flash = coordinatorGetFlash();
$manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$todayDate = $manilaNow->format('Y-m-d');
$todayName = $manilaNow->format('l');

function coordinatorJournalWeekLabel(array $week, string $todayDate): string
{
    $start = (string) ($week['start'] ?? '');
    $end = (string) ($week['end'] ?? '');
    $label = (string) ($week['week_label'] ?? $week['label'] ?? 'Week');
    if ($start === '' || $end === '') {
        return practicumWeekDisplayLabel($week);
    }

    $status = practicumWeekStatus($start, $end, $todayDate);
    $suffix = $status === 'active' ? ' (Active)' : '';

    return "{$label}{$suffix}, {$start} to {$end}";
}

function coordinatorJournalBadgeLabel(string $status): string
{
    return match ($status) {
        'approved' => coordinatorTopBadge('Approved', 'green'),
        'declined' => coordinatorTopBadge('Declined', 'red'),
        'reviewed' => coordinatorTopBadge('Reviewed', 'orange'),
        'resubmitted' => coordinatorTopBadge('Resubmitted', 'blue'),
        default => coordinatorTopBadge('Pending', 'blue'),
    };
}

function coordinatorJournalSubmissionLabel(array $row): string
{
    if ((int) ($row['is_reopened'] ?? 0) === 1) {
        return 'Reopened';
    }

    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['approved', 'declined', 'reviewed'], true)) {
        return ucfirst($status);
    }
    if ($status === 'resubmitted') {
        return 'Resubmitted';
    }

    if (!empty($row['submitted_at'])) {
        return 'Submitted';
    }

    return 'Missing';
}

function coordinatorJournalSubmissionStatusClass(array $row): string
{
    return match (coordinatorJournalSubmissionLabel($row)) {
        'Submitted' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        'Resubmitted' => 'bg-blue-100 text-blue-700 ring-blue-200',
        'Reopened' => 'bg-amber-50 text-amber-700 ring-amber-100',
        'Missing' => 'bg-rose-50 text-rose-700 ring-rose-100',
        'Approved' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        'Declined' => 'bg-rose-50 text-rose-700 ring-rose-100',
        'Reviewed' => 'bg-amber-50 text-amber-700 ring-amber-100',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
}
?>
<?php renderCoordinatorPortalStart(
    $user,
    'journals',
    'Journal Submissions',
    $context,
    '<a href="' . htmlspecialchars(appUrl('coordinator/history.php'), ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">History</a>',
    true
); ?>

<?php if ($flash !== null): ?>
    <div data-reveal class="<?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> mb-6 rounded-3xl border px-5 py-4 text-sm font-medium shadow-panel">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
    </div>
<?php endif; ?>

<?php if ($hasAssignedSchool && $hasActiveSemester && $selectedWeek !== null): ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Journal Submissions</h1>
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                    <?= htmlspecialchars($todayName . ' - TRACKING') ?>
                </span>
            </div>
            <p class="mt-3 max-w-2xl text-base text-slate-500 sm:text-lg">
                Review weekly dynamic journal submissions for students assigned to your partnered school.
            </p>
        </div>
        <form method="get" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <label for="week_start" class="text-sm font-medium text-slate-500">Week:</label>
            <select id="week_start" name="week_start" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <?php foreach ($weekOptions as $option): ?>
                    <option value="<?= htmlspecialchars((string) $option['start']) ?>" <?= $option['start'] === $selectedWeek['start'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(coordinatorJournalWeekLabel($option, $todayDate)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="student_search" value="<?= htmlspecialchars($studentSearch) ?>" placeholder="Search student name" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
            <select name="section_code" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <option value="">All Sections</option>
                <?php foreach ($studentSections as $sectionCode => $sectionName): ?>
                    <option value="<?= htmlspecialchars($sectionCode) ?>" <?= $sectionCode === $selectedStudentSection ? 'selected' : '' ?>><?= htmlspecialchars($sectionName) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="journal_status" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <option value="">All Journal Statuses</option>
                <?php foreach (['Missing', 'Pending', 'Submitted', 'Resubmitted', 'Reopened', 'Approved', 'Declined', 'Reviewed'] as $statusOption): ?>
                    <option value="<?= htmlspecialchars($statusOption) ?>" <?= $selectedJournalStatus === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars($statusOption) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Go</button>
        </form>
    </section>

    <section data-reveal class="rounded-[28px] border border-blue-200 bg-blue-50/80 p-5 shadow-panel">
        <div class="flex items-start gap-3">
            <div class="rounded-2xl bg-white p-2 text-blue-600 shadow-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="h-5 w-5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 17A2.5 2.5 0 0 0 4 19.5V5a2 2 0 0 1 2-2h14v14"/><path d="M8 7h8"/><path d="M8 11h6"/></svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-blue-800">Weekly Journal Review</h3>
                <p class="mt-2 text-sm leading-7 text-blue-700 sm:text-base">
                    Each student can submit one journal per practicum week unless you decline it for revision. Open a submission to review answers, add feedback, and approve or decline.
                </p>
            </div>
        </div>
    </section>

    <section data-reveal class="mt-6 overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
        <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-2xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars(coordinatorJournalWeekLabel($selectedWeek, $todayDate)) ?></h3>
                <p class="mt-1 text-sm text-slate-500">Student, week, status, and review action for the selected practicum week.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <?= coordinatorTopBadge(count($filteredRows) . ' Students', 'slate') ?>
                <?php if ((string) ($selectedWeekRow['status'] ?? '') === 'completed' && $hasUnreopenedStudent): ?>
                    <?php
                        $bulkReopenConfirmMessage = 'Reopen Journals for All Students' . "\n\n"
                            . 'Are you sure you want to reopen the journals for all ' . count($rows) . ' assigned students for '
                            . (string) ($selectedWeek['display_label'] ?? $selectedWeek['label'] ?? 'this week') . '?' . "\n\n"
                            . 'Students will be allowed to edit and resubmit their journals.';
                    ?>
                    <form method="post" class="reopen-journal-form" data-swal-title="Reopen Journals for All Students" data-swal-message="<?= htmlspecialchars($bulkReopenConfirmMessage, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="reopen_all_journals">
                        <input type="hidden" name="week_start" value="<?= htmlspecialchars((string) ($selectedWeek['start'] ?? '')) ?>">
                        <button type="submit" class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800 transition hover:border-amber-400 hover:bg-amber-100">Reopen All Journals</button>
                    </form>
                <?php elseif ((string) ($selectedWeekRow['status'] ?? '') === 'completed'): ?>
                    <button type="button" disabled class="cursor-not-allowed rounded-2xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-400" title="All journals for this week have already been reopened">Journals Already Reopened</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="scroll-slim overflow-x-auto">
            <table class="min-w-full text-left">
                <thead class="bg-slate-50/80">
                    <tr class="text-xs uppercase tracking-[0.22em] text-slate-500">
                        <th class="px-5 py-4 font-semibold">Student</th>
                        <th class="px-4 py-4 font-semibold">Week</th>
                        <th class="px-4 py-4 font-semibold">Status</th>
                        <th class="px-4 py-4 font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($filteredRows as $row): ?>
                        <?php $hasSubmission = !empty($row['submission_id']); ?>
                        <?php $isReopened = (int) ($row['is_reopened'] ?? 0) === 1; ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-5 py-4 font-semibold text-slate-900">
                                <?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?>
                                <div class="mt-1 text-xs font-normal text-slate-500"><?= htmlspecialchars((string) ($row['section_name'] ?: ($row['section_code'] ?? 'No section assigned'))) ?></div>
                            </td>
                            <td class="px-4 py-4 text-slate-600"><?= htmlspecialchars((string) ($selectedWeek['display_label'] ?? $selectedWeek['label'] ?? 'Week')) ?></td>
                            <td class="px-4 py-4">
                                <?php if ($hasSubmission): ?>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset <?= coordinatorJournalSubmissionStatusClass($row) ?>">
                                        <?= htmlspecialchars(coordinatorJournalSubmissionLabel($row)) ?>
                                    </span>
                                    <div class="mt-1 text-xs font-bold text-slate-700"><?= (int) ($row['points_awarded'] ?? 0) ?> / <?= max(1, (int) ($row['max_points'] ?? 50)) ?> points</div>
                                <?php else: ?>
                                    <?= coordinatorTopBadge('Missing', 'red') ?>
                                    <div class="mt-1 text-xs font-bold text-slate-500">0 / <?= max(1, (int) ($row['max_points'] ?? 50)) ?> points</div>
                                <?php endif; ?>
                            </td>
                        <td class="px-4 py-4">
                            <?php if ($hasSubmission): ?>
                                <div class="flex flex-wrap items-center gap-4 text-sm font-semibold">
                                    <a class="text-blue-600 transition hover:text-blue-700" href="<?= htmlspecialchars(appUrl('coordinator/review_journals.php')) ?>?submission_id=<?= (int) $row['submission_id'] ?>&week_start=<?= urlencode((string) $selectedWeek['start']) ?>">View Submission</a>
                                </div>
                                <?php else: ?>
                                    <span class="text-sm text-slate-400">No submission</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($filteredRows === []): ?>
                        <tr>
                            <td colspan="4" class="px-5 py-12 text-center text-sm text-slate-500">No journal records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <?php if (!$hasAssignedSchool): ?>
            <p class="text-slate-500">No partnered school is assigned to your coordinator account yet.</p>
            <p class="mt-1 text-slate-500">Please contact the Head Teacher.</p>
        <?php else: ?>
            <?php if ($hasActiveSemester && !$hasStartedPracticum): ?>
                <p class="text-slate-500">Practicum has not started yet.</p>
                <p class="mt-1 text-slate-500">Start Date: <?= htmlspecialchars(!empty($context['practicum_start_date']) ? date('F j, Y', strtotime((string) $context['practicum_start_date'])) : 'Not set') ?></p>
            <?php else: ?>
                <p class="text-slate-500">No active semester is available yet.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php
$extraScript = <<<'HTML'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.querySelectorAll('.reopen-journal-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.Swal || form.dataset.swalConfirmed === '1') {
                return;
            }

            event.preventDefault();
            Swal.fire({
                title: form.dataset.swalTitle || 'Reopen Journal',
                text: form.dataset.swalMessage || 'The student will be allowed to edit and resubmit this journal.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, reopen',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#d97706',
                cancelButtonColor: '#64748b'
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.dataset.swalConfirmed = '1';
                    form.submit();
                }
            });
        });
    });
</script>
HTML;
renderCoordinatorPortalEnd($extraScript);
?>
