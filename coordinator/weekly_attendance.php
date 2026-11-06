<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('coordinator');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';
require_once __DIR__ . '/_portal.php';

$user = currentUser() ?? [];
$coordinatorUserId = (int) ($user['id'] ?? 0);
$pdo = getPDO();
$attendanceService = new AttendanceService($pdo);
$context = $attendanceService->getCoordinatorSectionContext($coordinatorUserId) ?? [];
$assignedGeofenceId = (int) ($context['geofence_id'] ?? 0);
$hasAssignedSchool = $context !== [] && $assignedGeofenceId > 0;
$hasActiveSemester = ((string) ($context['semester_status'] ?? '')) === 'active'
    && ((string) ($context['year_status'] ?? '')) === 'active';
$selectedWeek = coordinatorSelectedWeek($context, isset($_GET['week_start']) ? (string) $_GET['week_start'] : null);
$weekOptions = coordinatorWeekOptions($context);
$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$assignedGeofenceId = (int) ($context['geofence_id'] ?? 0);
$coordinatorHolidayDates = $selectedWeek !== null
    ? (new SystemHolidayService($pdo))->listBetween(
        (string) $selectedWeek['start'],
        (string) $selectedWeek['end'],
        $assignedGeofenceId > 0 ? $assignedGeofenceId : null
    )
    : [];
$dashboard = $hasAssignedSchool && $hasActiveSemester && $selectedWeek !== null
    ? $attendanceService->getCoordinatorDashboardData($coordinatorUserId, $todayDate, $selectedWeek['start'], $selectedWeek['end'])
    : [
        'summary' => ['total_students' => 0],
        'weekly_rows' => [],
    ];
$weeklyRows = $dashboard['weekly_rows'] ?? [];
$studentSearch = trim((string) ($_GET['student_search'] ?? ''));
$selectedStudentSection = trim((string) ($_GET['section_code'] ?? ''));
$selectedStatus = trim((string) ($_GET['status'] ?? ''));
if (!in_array($selectedStatus, ['Critical', 'Absent', 'Completed'], true)) {
    $selectedStatus = '';
}
$studentSections = [];
foreach ($weeklyRows as $studentRow) {
    $sectionCode = trim((string) ($studentRow['section_code'] ?? ''));
    $sectionName = trim((string) ($studentRow['section_name'] ?? ''));
    if ($sectionCode !== '' || $sectionName !== '') {
        $studentSections[$sectionCode !== '' ? $sectionCode : $sectionName] = $sectionName !== '' ? $sectionName : $sectionCode;
    }
}
$filteredWeeklyRows = array_values(array_filter(
    $weeklyRows,
    static function (array $row) use ($studentSearch, $selectedStudentSection, $selectedStatus, $selectedWeek, $todayDate): bool {
        $name = strtolower(trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
        $query = strtolower($studentSearch);
        $sectionCode = trim((string) ($row['section_code'] ?? ''));
        $sectionName = trim((string) ($row['section_name'] ?? ''));
        $section = $sectionCode !== '' ? $sectionCode : $sectionName;
        return ($query === '' || str_contains($name, $query))
            && ($selectedStudentSection === '' || $section === $selectedStudentSection)
            && ($selectedStatus === '' || coordinatorAttendanceStatusLabel($row, (string) $selectedWeek['start'], $todayDate) === $selectedStatus);
    }
));

function coordinatorAttendanceWeekDates(string $weekStart): array
{
    $start = new DateTimeImmutable($weekStart);

    return [
        'mon' => $start->format('Y-m-d'),
        'tue' => $start->modify('+1 day')->format('Y-m-d'),
        'wed' => $start->modify('+2 days')->format('Y-m-d'),
        'thu' => $start->modify('+3 days')->format('Y-m-d'),
        'fri' => $start->modify('+4 days')->format('Y-m-d'),
    ];
}

function coordinatorAttendanceDayState(array $row, string $day, string $date, string $todayDate): string
{
    if (isset($GLOBALS['coordinatorHolidayDates'][$date])) {
        return 'holiday';
    }
    $hours = (float) ($row[$day . '_hours'] ?? 0);
    $isActiveToday = (int) ($row['is_active_today'] ?? 0) === 1;
    $timeIn = (string) ($row[$day . '_time_in'] ?? '');
    $timeOut = (string) ($row[$day . '_time_out'] ?? '');
    $isLate = $timeIn !== '' && (new DateTimeImmutable($timeIn))->format('H:i:s') >= '08:30:00';

    if ($timeIn !== '' && $timeOut === '') {
        if ($date === $todayDate && (int) ($row['today_geofence_inside'] ?? 1) !== 1) {
            return 'outside_geofence';
        }
        if ($date < $todayDate) return 'pending_timeout';
        return $isLate ? 'ongoing_late' : 'ongoing';
    }
    if ($hours >= 8 || ($timeIn !== '' && $timeOut !== '')) {
        return $isLate ? 'completed_late' : 'completed';
    }
    if ($hours > 0) {
        return 'partial';
    }

    if ($date === $todayDate) {
        return date('H:i:s') >= '06:00:00' ? 'absent' : 'future';
    }

    return $date > $todayDate ? 'future' : 'absent';
}

function coordinatorAttendanceDayBadge(array $row, string $day, string $date, string $todayDate): string
{
    $hours = (float) ($row[$day . '_hours'] ?? 0);

    return match (coordinatorAttendanceDayState($row, $day, $date, $todayDate)) {
        'holiday' => coordinatorTopBadge('H - Holiday', 'slate'),
        'completed' => coordinatorTopBadge(coordinatorFormatHours($hours), 'green'),
        'completed_late' => coordinatorTopBadge(coordinatorFormatHours($hours), 'yellow'),
        'ongoing' => coordinatorTopBadge(coordinatorFormatHours($hours), 'blue'),
        'ongoing_late' => coordinatorTopBadge(coordinatorFormatHours($hours), 'yellow'),
        'outside_geofence' => coordinatorTopBadge(coordinatorFormatHours($hours), 'red'),
        'pending_timeout' => '<span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-center text-xs font-semibold leading-tight text-amber-700 ring-1 ring-inset ring-amber-100">Pending<br>Timeout</span>',
        'partial' => coordinatorTopBadge(coordinatorFormatHours($hours), 'orange'),
        'future' => coordinatorTopBadge('Upcoming', 'slate'),
        default => coordinatorTopBadge('Absent', 'red'),
    };
}

function coordinatorAttendanceStatusLabel(array $row, string $weekStart, string $todayDate): string
{
    $completedDays = 0;
    $presentDays = 0;
    $absentDays = 0;
    $futureDays = 0;
    $todayState = null;

    foreach (coordinatorAttendanceWeekDates($weekStart) as $day => $date) {
        $state = coordinatorAttendanceDayState($row, $day, $date, $todayDate);
        if ($date === $todayDate) $todayState = $state;
        if ($state === 'completed' || $state === 'completed_late' || $state === 'holiday') {
            $completedDays++;
            $presentDays++;
        } elseif ($state === 'ongoing' || $state === 'ongoing_late' || $state === 'pending_timeout' || $state === 'partial') {
            $presentDays++;
        } elseif ($state === 'future') {
            $futureDays++;
        } else {
            $absentDays++;
        }
    }

    if ($completedDays >= 5) {
        return 'Completed';
    }
    if ($futureDays >= 5 || ($todayState === 'future' && date('H:i:s') < '06:00:00')) {
        return 'Upcoming';
    }
    if ($todayState === 'ongoing_late') {
        return 'Ongoing late';
    }
    if ($todayState === 'ongoing') {
        return 'Ongoing';
    }
    if ($absentDays >= 5) {
        return 'Critical';
    }
    if ($presentDays > 0) {
        return 'Critical';
    }

    return 'Absent';
}

function coordinatorAttendanceStatusBadge(array $row, string $weekStart, string $todayDate): string
{
    return match (coordinatorAttendanceStatusLabel($row, $weekStart, $todayDate)) {
        'Ongoing' => coordinatorTopBadge('Ongoing', 'blue'),
        'Ongoing late' => coordinatorTopBadge('Ongoing late', 'yellow'),
        'Pending Timeout' => coordinatorTopBadge('Pending Timeout', 'orange'),
        'Upcoming' => coordinatorTopBadge('Upcoming', 'slate'),
        'Completed' => coordinatorTopBadge('Completed', 'green'),
        'Critical' => coordinatorTopBadge('Critical', 'red'),
        default => coordinatorTopBadge('Absent', 'red'),
    };
}

?>
<?php renderCoordinatorPortalStart(
    $user,
    'dashboard',
    'Weekly Attendance',
    $context,
    '<a href="' . htmlspecialchars(appUrl('coordinator/dashboard.php'), ENT_QUOTES, 'UTF-8') . '" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Student List</a>',
    true
); ?>

<?php if (!$hasAssignedSchool || !$hasActiveSemester || $selectedWeek === null): ?>
    <section data-reveal class="rounded-[28px] border border-slate-200 bg-white p-8 shadow-panel">
        <h1 class="text-3xl font-bold text-slate-900">Weekly Attendance</h1>
        <p class="mt-3 text-slate-500">
            <?= !$hasAssignedSchool
                ? 'No partnered school is assigned to your coordinator account yet.'
                : ($hasActiveSemester && $selectedWeek === null
                    ? 'Practicum has not started yet.'
                    : 'No active semester is available yet.') ?>
        </p>
    </section>
<?php else: ?>
    <section data-reveal class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">Weekly Attendance</h1>
                <?= practicumWeekStatusBadge($selectedWeek) ?>
            </div>
            <p class="mt-3 max-w-2xl text-base text-slate-500 sm:text-lg">
                <?= htmlspecialchars(practicumWeekDisplayLabel($selectedWeek)) ?>, <?= htmlspecialchars((string) $selectedWeek['start']) ?> to <?= htmlspecialchars((string) $selectedWeek['end']) ?>
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
            <input type="search" name="student_search" value="<?= htmlspecialchars($studentSearch) ?>" placeholder="Search student name" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
            <select name="section_code" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <option value="">All Sections</option>
                <?php foreach ($studentSections as $sectionCode => $sectionName): ?>
                    <option value="<?= htmlspecialchars($sectionCode) ?>" <?= $sectionCode === $selectedStudentSection ? 'selected' : '' ?>><?= htmlspecialchars($sectionName) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
                <option value="">All Statuses</option>
                <?php foreach (['Critical', 'Absent', 'Completed'] as $statusOption): ?>
                    <option value="<?= htmlspecialchars($statusOption) ?>" <?= $selectedStatus === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars($statusOption) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">View</button>
        </form>
    </section>

    <section data-reveal class="mb-6 grid gap-4 md:grid-cols-2">
        <article class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-panel">
            <p class="text-sm font-semibold text-slate-500">Students</p>
            <h2 id="weeklyTotalStudents" class="mt-3 text-3xl font-bold tracking-tight text-slate-950"><?= count($filteredWeeklyRows) ?></h2>
        </article>
        <article class="rounded-[28px] border border-emerald-200 bg-emerald-50 p-6 shadow-panel">
            <p class="text-sm font-semibold text-emerald-800">Live Refresh</p>
            <p class="mt-2 text-sm text-emerald-900">Open attendance sessions update automatically.</p>
        </article>
    </section>

    <section data-reveal class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-panel">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3 class="text-2xl font-bold tracking-tight text-slate-950">Students</h3>
                <p class="mt-2 text-base text-slate-500">Daily credited hours are capped at 8 hours from Monday to Friday.</p>
            </div>
            <a href="<?= htmlspecialchars(appUrl('coordinator/dashboard.php?' . http_build_query(['week_start' => (string) $selectedWeek['start'], 'student_search' => $studentSearch, 'section_code' => $selectedStudentSection])), ENT_QUOTES, 'UTF-8') ?>" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">Back to Current Students</a>
        </div>
        <div class="scroll-slim overflow-x-auto">
            <table class="min-w-[760px] w-full text-left text-sm">
                <thead class="bg-slate-50/80">
                    <tr class="text-xs uppercase tracking-[0.18em] text-slate-500">
                        <th class="px-5 py-4 font-semibold">Student</th>
                        <th class="px-4 py-4 font-semibold">Mon</th>
                        <th class="px-4 py-4 font-semibold">Tue</th>
                        <th class="px-4 py-4 font-semibold">Wed</th>
                        <th class="px-4 py-4 font-semibold">Thu</th>
                        <th class="px-4 py-4 font-semibold">Fri</th>
                        <th class="px-4 py-4 font-semibold">Total</th>
                        <th class="px-4 py-4 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody id="coordinatorAttendanceRows" class="divide-y divide-slate-100">
                    <?php foreach ($filteredWeeklyRows as $row): ?>
                        <?php
                        $weekDates = coordinatorAttendanceWeekDates((string) $selectedWeek['start']);
                        $publicHours = (float) ($row['public_hours'] ?? 0);
                        $privateHours = (float) ($row['private_hours'] ?? 0);
                        ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-5 py-4 font-semibold text-slate-900">
                                <?= htmlspecialchars(trim((string) (($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')))) ?>
                                <div class="mt-1 text-xs font-normal text-slate-500"><?= htmlspecialchars((string) ($row['section_name'] ?: ($row['section_code'] ?? 'No section assigned'))) ?></div>
                                <div class="mt-1 text-xs font-normal text-slate-500"><?= htmlspecialchars((string) ($row['email'] ?? '')) ?></div>
                            </td>
                            <td class="px-4 py-4"><?= coordinatorAttendanceDayBadge($row, 'mon', $weekDates['mon'], $todayDate) ?></td>
                            <td class="px-4 py-4"><?= coordinatorAttendanceDayBadge($row, 'tue', $weekDates['tue'], $todayDate) ?></td>
                            <td class="px-4 py-4"><?= coordinatorAttendanceDayBadge($row, 'wed', $weekDates['wed'], $todayDate) ?></td>
                            <td class="px-4 py-4"><?= coordinatorAttendanceDayBadge($row, 'thu', $weekDates['thu'], $todayDate) ?></td>
                            <td class="px-4 py-4"><?= coordinatorAttendanceDayBadge($row, 'fri', $weekDates['fri'], $todayDate) ?></td>
                            <td class="px-4 py-4 font-semibold text-slate-900"><div>Pub: <?= coordinatorFormatHours($publicHours) ?></div><div>Priv: <?= coordinatorFormatHours($privateHours) ?></div></td>
                            <td class="px-4 py-4"><?= coordinatorAttendanceStatusBadge($row, (string) $selectedWeek['start'], $todayDate) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($filteredWeeklyRows === []): ?>
                        <tr>
                            <td colspan="8" class="px-5 py-12 text-center text-sm text-slate-500">No students found for the selected week.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php
$extraScript = '';
if ($hasAssignedSchool && $hasActiveSemester && $selectedWeek !== null) {
    $apiUrl = json_encode(appUrl('api/coordinator_live_attendance.php') . '?week_start=' . urlencode((string) $selectedWeek['start']));
    $weekStartJson = json_encode((string) $selectedWeek['start']);
    $todayDateJson = json_encode($todayDate);
    $studentSearchJson = json_encode($studentSearch);
    $selectedStudentSectionJson = json_encode($selectedStudentSection);
    $selectedStatusJson = json_encode($selectedStatus);
    $holidayDatesJson = json_encode(array_keys($coordinatorHolidayDates), JSON_THROW_ON_ERROR);
    $extraScript = str_replace(
        ['__API_URL__', '__WEEK_START__', '__TODAY_DATE__', '__STUDENT_SEARCH__', '__STUDENT_SECTION__', '__STATUS__', '__HOLIDAYS__'],
        [$apiUrl, $weekStartJson, $todayDateJson, $studentSearchJson, $selectedStudentSectionJson, $selectedStatusJson, $holidayDatesJson],
        <<<'HTML'
<script>
(() => {
  const apiUrl = __API_URL__;
  const weekStart = __WEEK_START__;
  const todayDate = __TODAY_DATE__;
  const studentSearch = String(__STUDENT_SEARCH__ || "").toLowerCase().trim();
  const selectedStudentSection = String(__STUDENT_SECTION__ || "").trim();
  const selectedStatus = String(__STATUS__ || "").trim();
  let holidayDates = new Set(__HOLIDAYS__ || []);
  const rowsEl = document.getElementById("coordinatorAttendanceRows");
  const totalStudentsEl = document.getElementById("weeklyTotalStudents");
  if (!rowsEl) return;

  const badgeClasses = {
    green: "bg-emerald-100 text-emerald-700 ring-emerald-200",
    blue: "bg-blue-100 text-blue-700 ring-blue-200",
    yellow: "bg-yellow-100 text-yellow-800 ring-yellow-200",
    orange: "bg-amber-50 text-amber-700 ring-amber-100",
    red: "bg-rose-50 text-rose-700 ring-rose-100",
    slate: "bg-slate-100 text-slate-700 ring-slate-200"
  };
  const dayNames = ["mon", "tue", "wed", "thu", "fri"];

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      "\"": "&quot;",
      "'": "&#039;"
    }[char]));
  }

  function badge(label, tone) {
    const safeLabel = escapeHtml(label).replace(/\n/g, "<br>");
    return `<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${badgeClasses[tone] || badgeClasses.slate}">${safeLabel}</span>`;
  }

  function weekDates() {
    const [year, month, day] = weekStart.split("-").map(Number);
    const monday = new Date(year, month - 1, day);
    return dayNames.map((name, index) => {
      const date = new Date(monday);
      date.setDate(monday.getDate() + index);
      return {
        name,
        value: [
          date.getFullYear(),
          String(date.getMonth() + 1).padStart(2, "0"),
          String(date.getDate()).padStart(2, "0")
        ].join("-")
      };
    });
  }

  const days = weekDates();

  function currentTimeValue() {
    const now = new Date();
    return `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}:${String(now.getSeconds()).padStart(2, "0")}`;
  }

  function dayState(row, day, dateValue) {
    if (holidayDates.has(dateValue)) return "holiday";
    const hours = Number(row[`${day}_hours`] || 0);
    const isActiveToday = Number(row.is_active_today || 0) === 1;
    const timeIn = String(row[`${day}_time_in`] || "");
    const timeOut = String(row[`${day}_time_out`] || "");
    const isLate = timeIn.length >= 19 && timeIn.slice(11, 19) >= "08:30:00";
    if (timeIn && !timeOut && dateValue < todayDate) return "pending_timeout";
    if (timeIn && !timeOut && dateValue === todayDate && Number(row.today_geofence_inside ?? 1) !== 1) return "outside_geofence";
    if (timeIn && !timeOut) return isLate ? "ongoing_late" : "ongoing";
    if (hours >= 8 || (timeIn && timeOut)) return isLate ? "completed_late" : "completed";
    if (hours > 0) return "partial";
    if (dateValue === todayDate) return currentTimeValue() >= "06:00:00" ? "absent" : "future";
    return dateValue > todayDate ? "future" : "absent";
  }

  function formatHours(hours) {
    const totalMinutes = Math.max(0, Math.floor((Number(hours || 0) * 60) + 0.00001));
    const wholeHours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${wholeHours}.${String(minutes).padStart(2, "0")}`;
  }

  function dayBadge(row, day, dateValue) {
    const hours = Number(row[`${day}_hours`] || 0);
    const state = dayState(row, day, dateValue);
    if (state === "holiday") return badge("H - Holiday", "slate");
    if (state === "completed") return badge(formatHours(hours), "green");
    if (state === "completed_late") return badge(formatHours(hours), "yellow");
    if (state === "ongoing") return badge(formatHours(hours), "blue");
    if (state === "ongoing_late") return badge(formatHours(hours), "yellow");
    if (state === "outside_geofence") return badge(formatHours(hours), "red");
    if (state === "pending_timeout") return badge("Pending\nTimeout", "orange");
    if (state === "partial") return badge(formatHours(hours), "orange");
    if (state === "future") return badge("Upcoming", "slate");
    return badge("Absent", "red");
  }

  function statusBadgeLabel(row) {
    let completedDays = 0;
    let presentDays = 0;
    let absentDays = 0;
    let futureDays = 0;
    let todayState = null;

    days.forEach(({ name, value }) => {
      const state = dayState(row, name, value);
      if (value === todayDate) todayState = state;
      if (state === "completed" || state === "completed_late" || state === "holiday") {
        completedDays += 1;
        presentDays += 1;
      } else if (state === "ongoing" || state === "ongoing_late" || state === "pending_timeout" || state === "partial") {
        presentDays += 1;
      } else if (state === "future") {
        futureDays += 1;
      } else {
        absentDays += 1;
      }
    });

    if (completedDays >= 5) return "Completed";
    if (futureDays >= 5 || (todayState === "future" && currentTimeValue() < "06:00:00")) return "Upcoming";
    if (todayState === "ongoing_late") return "Ongoing late";
    if (todayState === "ongoing") return "Ongoing";
    if (absentDays >= 5 || presentDays > 0) return "Critical";
    return "Absent";
  }

  function statusBadge(row) {
    const label = statusBadgeLabel(row);
    const tone = label === "Completed" ? "green"
      : label === "Upcoming" ? "slate"
      : label === "Ongoing" ? "blue"
      : label === "Ongoing late" ? "yellow"
      : "red";
    return badge(label, tone);
  }

  function filterRows(rows) {
    return rows.filter((row) => {
      const studentName = String(row.first_name || "") + " " + String(row.last_name || "");
      const section = String(row.section_code || row.section_name || "").trim();
      return (!studentSearch || studentName.toLowerCase().trim().includes(studentSearch))
        && (!selectedStudentSection || section === selectedStudentSection)
        && (!selectedStatus || statusBadgeLabel(row) === selectedStatus);
    });
  }

  function renderRows(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
      rowsEl.innerHTML = "<tr><td colspan=\"8\" class=\"px-5 py-12 text-center text-sm text-slate-500\">No students found for the selected week.</td></tr>";
      return;
    }

    rowsEl.innerHTML = rows.map((row) => {
      const publicHours = Number(row.public_hours || 0);
      const privateHours = Number(row.private_hours || 0);
      const student = `${row.last_name || ""}, ${row.first_name || ""}`.trim();
      return `<tr class="transition hover:bg-slate-50/80">
          <td class="px-5 py-4 font-semibold text-slate-900">
            ${escapeHtml(student)}
            <div class="mt-1 text-xs font-normal text-slate-500">${escapeHtml(row.section_name || row.section_code || "No section assigned")}</div>
            <div class="mt-1 text-xs font-normal text-slate-500">${escapeHtml(row.email || "")}</div>
          </td>
          <td class="px-4 py-4">${dayBadge(row, "mon", days[0].value)}</td>
          <td class="px-4 py-4">${dayBadge(row, "tue", days[1].value)}</td>
          <td class="px-4 py-4">${dayBadge(row, "wed", days[2].value)}</td>
          <td class="px-4 py-4">${dayBadge(row, "thu", days[3].value)}</td>
          <td class="px-4 py-4">${dayBadge(row, "fri", days[4].value)}</td>
          <td class="px-4 py-4 font-semibold text-slate-900"><div>Pub: ${formatHours(publicHours)}</div><div>Priv: ${formatHours(privateHours)}</div></td>
          <td class="px-4 py-4">${statusBadge(row)}</td>
        </tr>`;
    }).join("");
  }

  async function refresh() {
    try {
      const response = await fetch(apiUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const data = await response.json();
      if (data.error) return;
      holidayDates = new Set(Object.keys(data.holidays || {}));
      const summary = data.summary || {};
      if (totalStudentsEl) totalStudentsEl.textContent = Number(summary.total_students || 0);
      const rows = filterRows(Array.isArray(data.weekly_rows) ? data.weekly_rows : []);
      if (totalStudentsEl) totalStudentsEl.textContent = rows.length;
      renderRows(rows);
    } catch (_error) {
    }
  }

  refresh();
  window.setInterval(refresh, 5000);
})();
</script>
HTML
    );
}

renderCoordinatorPortalEnd($extraScript);
?>
