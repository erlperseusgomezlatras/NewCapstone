<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$userId = (int) ($user['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    exit('Unauthorized');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['system_holiday_csrf'])) {
    $_SESSION['system_holiday_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['system_holiday_csrf'];
$pdo = getPDO();
$activeSemesterStmt = $pdo->query("SELECT id FROM semesters WHERE semester_status = 'active' LIMIT 1");
$hasActiveSemester = (int) ($activeSemesterStmt->fetchColumn() ?: 0) > 0;
$holidayService = new SystemHolidayService($pdo);
$partneredSchools = $holidayService->listActiveSchools();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$hasActiveSemester) {
            throw new RuntimeException('School Holidays is locked. Activate a semester before managing system-wide holidays.');
        }
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add_holiday') {
            $scope = (string) ($_POST['holiday_scope'] ?? 'global');
            if (!in_array($scope, ['global', 'local'], true)) {
                throw new InvalidArgumentException('Choose whether this closure applies to all schools or one school.');
            }
            $geofenceId = $scope === 'local' ? (int) ($_POST['geofence_id'] ?? 0) : null;
            $holidayService->add(
                trim((string) ($_POST['holiday_date'] ?? '')),
                trim((string) ($_POST['holiday_name'] ?? '')),
                $userId,
                $geofenceId
            );
            htSetFlash(
                'success',
                $scope === 'global'
                    ? 'Global holiday saved for all schools.'
                    : 'School closure saved for the selected school.'
            );
        } elseif ($action === 'remove_holiday') {
            $scope = (string) ($_POST['holiday_scope'] ?? 'global');
            if (!in_array($scope, ['global', 'local'], true)) {
                throw new InvalidArgumentException('Choose a valid holiday to remove.');
            }
            $geofenceId = $scope === 'local' ? (int) ($_POST['geofence_id'] ?? 0) : null;
            $holidayService->remove((int) ($_POST['holiday_id'] ?? 0), $geofenceId);
            htSetFlash('success', 'Holiday removed. The date will be evaluated as a regular school day again.');
        } else {
            throw new InvalidArgumentException('Choose a valid holiday action.');
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            error_log('System holiday update failed: ' . $e->getMessage());
            htSetFlash('error', 'Unable to save the holiday. Please contact the administrator.');
        } else {
            htSetFlash('error', $e->getMessage());
        }
    }

    header('Location: ' . appUrl('head_teacher/holidays.php'));
    exit;
}

$holidays = $holidayService->listAll();
renderHeadTeacherPortalStart($user, 'holidays');
?>

<section class="mt-6 space-y-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-700">System-wide attendance</p>
        <h2 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">School Holidays</h2>
        <p class="mt-3 max-w-3xl text-sm text-slate-600">
            Record global dates such as Christmas for all schools, or a local event for one partnered school.
            The affected school's closure dates count as excused/present in attendance and never add practicum hours.
        </p>
    </div>

    <?php if (!$hasActiveSemester): ?>
        <div class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950" role="status">
            <h3 class="font-semibold">School Holidays is locked</h3>
            <p class="mt-1">Activate a semester in Academic Control to add, update, or remove school closures.</p>
            <a class="mt-3 inline-flex font-semibold text-emerald-800 underline" href="<?= htmlspecialchars(appUrl('head_teacher/academic_control.php'), ENT_QUOTES, 'UTF-8') ?>">Open Academic Control</a>
        </div>
    <?php endif; ?>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,0.9fr),minmax(0,1.1fr)]">
        <form method="post" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm <?= !$hasActiveSemester ? 'opacity-60' : '' ?>">
            <h3 class="text-lg font-semibold text-slate-900">Add a school closure</h3>
            <fieldset <?= !$hasActiveSemester ? 'disabled' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add_holiday">
            <div class="mt-5 space-y-4">
                <label class="block text-sm font-medium text-slate-700" for="holiday_scope">
                    Applies to
                    <select id="holiday_scope" name="holiday_scope" required
                        class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2.5">
                        <option value="global">All schools (global)</option>
                        <option value="local">One school (local)</option>
                    </select>
                </label>
                <label id="school_selection" class="hidden block text-sm font-medium text-slate-700" for="geofence_id">
                    School
                    <select id="geofence_id" name="geofence_id" disabled
                        class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2.5">
                        <option value="">Select a partnered school</option>
                        <?php foreach ($partneredSchools as $school): ?>
                            <option value="<?= (int) $school['id'] ?>"><?= htmlspecialchars((string) $school['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($partneredSchools === []): ?>
                        <span class="mt-1 block text-xs text-amber-800">There are no active partnered schools to select.</span>
                    <?php endif; ?>
                </label>
                <label class="block text-sm font-medium text-slate-700" for="holiday_date">
                    Date
                    <input id="holiday_date" name="holiday_date" type="date" required
                        class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2.5">
                </label>
                <label class="block text-sm font-medium text-slate-700" for="holiday_name">
                    Holiday name or reason
                    <input id="holiday_name" name="holiday_name" type="text" maxlength="160" required
                        placeholder="e.g. Local Foundation Day"
                        class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2.5">
                </label>
                <p id="holiday_scope_help" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    A global closure applies to every school. A local closure affects only students deployed to the selected school.
                </p>
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                    Save Closure
                </button>
            </div>
            </fieldset>
        </form>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-5">
                <h3 class="text-lg font-semibold text-slate-900">School closure calendar</h3>
                <p class="mt-1 text-sm text-slate-500">Global closures affect every school; local closures affect only the selected school.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-5 py-3 font-semibold">Date</th>
                        <th class="px-5 py-3 font-semibold">Holiday or reason</th>
                        <th class="px-5 py-3 font-semibold">Applies to</th>
                        <th class="px-5 py-3 text-right font-semibold">Action</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($holidays as $holiday): ?>
                        <tr>
                            <td class="whitespace-nowrap px-5 py-3"><?= htmlspecialchars((new DateTimeImmutable((string) $holiday['holiday_date']))->format('M j, Y')) ?></td>
                            <td class="px-5 py-3 font-medium text-slate-800"><?= htmlspecialchars((string) $holiday['holiday_name']) ?></td>
                            <td class="px-5 py-3"><?= htmlspecialchars((string) $holiday['school_name']) ?></td>
                            <td class="px-5 py-3 text-right">
                                <form method="post" onsubmit="return confirm('Remove this school closure? Students may be marked absent for this date again.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="remove_holiday">
                                    <input type="hidden" name="holiday_id" value="<?= (int) $holiday['id'] ?>">
                                    <input type="hidden" name="holiday_scope" value="<?= htmlspecialchars((string) $holiday['holiday_scope'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if ($holiday['geofence_id'] !== null): ?>
                                        <input type="hidden" name="geofence_id" value="<?= (int) $holiday['geofence_id'] ?>">
                                    <?php endif; ?>
                                    <button type="submit" <?= !$hasActiveSemester ? 'disabled' : '' ?> class="font-semibold text-rose-700 hover:text-rose-900 disabled:cursor-not-allowed disabled:text-slate-400">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($holidays === []): ?>
                        <tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">No school closures have been added.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const scope = document.getElementById('holiday_scope');
    const schoolSelection = document.getElementById('school_selection');
    const school = document.getElementById('geofence_id');
    const help = document.getElementById('holiday_scope_help');

    function updateScopeFields() {
        const isLocal = scope.value === 'local';
        schoolSelection.classList.toggle('hidden', !isLocal);
        school.disabled = !isLocal;
        school.required = isLocal;
        help.textContent = isLocal
            ? 'This closure affects only students deployed to the selected school. Other schools remain open.'
            : 'This global closure applies to every school and student.';
    }

    scope.addEventListener('change', updateScopeFields);
    updateScopeFields();
});
</script>

<?php renderHeadTeacherPortalEnd(); ?>
