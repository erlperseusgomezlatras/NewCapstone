<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/_portal.php';

$pdo = getPDO();
$todayDate = date('Y-m-d');
$todayLabel = date('l, F j, Y');
$weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
$weekEnd = (new DateTimeImmutable('friday this week'))->format('Y-m-d');

$semesterStmt = $pdo->query(
    "SELECT sem.id, sem.semester_name, sy.year_label
     FROM semesters sem
     JOIN school_years sy ON sy.id = sem.school_year_id
     ORDER BY (sem.semester_status = 'active') DESC, sy.start_date DESC, sem.semester_no ASC
     LIMIT 1"
);
$currentSemester = $semesterStmt->fetch() ?: null;
$currentSemesterId = $currentSemester ? (int) $currentSemester['id'] : 0;

$kpi = [
    'active_now' => 0,
    'logged_today' => 0,
    'completed_8h' => 0,
    'sections_monitored' => 0,
];
$sectionStatuses = [];
$weeklyRows = [];
$selectedSectionId = 0;

if ($currentSemesterId > 0) {
    $kpiStmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL AND ar.time_out IS NULL THEN ar.student_user_id END) AS active_now,
            COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL THEN ar.student_user_id END) AS logged_today,
            COUNT(DISTINCT CASE WHEN ar.total_hours >= 8 THEN ar.student_user_id END) AS completed_8h,
            (SELECT COUNT(*) FROM sections s2 WHERE s2.semester_id = :semester_for_sections) AS sections_monitored
         FROM attendance_records ar
         WHERE ar.semester_id = :semester_id
           AND ar.attendance_date = :today_date"
    );
    $kpiStmt->execute([
        'semester_for_sections' => $currentSemesterId,
        'semester_id' => $currentSemesterId,
        'today_date' => $todayDate,
    ]);
    $kpi = $kpiStmt->fetch() ?: $kpi;

    $sectionsStmt = $pdo->prepare(
        "SELECT
            s.id,
            s.section_name,
            COUNT(DISTINCT CASE WHEN ss.enrollment_status = 'active' THEN ss.student_user_id END) AS total_students,
            COUNT(DISTINCT CASE WHEN ar.time_in IS NOT NULL THEN ar.student_user_id END) AS active_today
         FROM sections s
         LEFT JOIN section_students ss
            ON ss.section_id = s.id
           AND ss.semester_id = s.semester_id
         LEFT JOIN attendance_records ar
            ON ar.section_id = s.id
           AND ar.semester_id = s.semester_id
           AND ar.attendance_date = :today_date
         WHERE s.semester_id = :semester_id
         GROUP BY s.id, s.section_name
         ORDER BY s.section_name ASC"
    );
    $sectionsStmt->execute([
        'today_date' => $todayDate,
        'semester_id' => $currentSemesterId,
    ]);
    $sectionStatuses = $sectionsStmt->fetchAll();

    $selectedSectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0;
    if ($selectedSectionId <= 0 && count($sectionStatuses) > 0) {
        $selectedSectionId = (int) $sectionStatuses[0]['id'];
    }

    if ($selectedSectionId > 0) {
        $weeklyStmt = $pdo->prepare(
            "SELECT
                ss.student_user_id,
                u.school_id,
                up.last_name,
                up.first_name,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 0 THEN ar.total_hours ELSE 0 END), 0) AS mon_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 1 THEN ar.total_hours ELSE 0 END), 0) AS tue_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 2 THEN ar.total_hours ELSE 0 END), 0) AS wed_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 3 THEN ar.total_hours ELSE 0 END), 0) AS thu_hours,
                COALESCE(SUM(CASE WHEN WEEKDAY(ar.attendance_date) = 4 THEN ar.total_hours ELSE 0 END), 0) AS fri_hours,
                COALESCE(SUM(ar.total_hours), 0) AS total_hours
             FROM section_students ss
             JOIN users u ON u.id = ss.student_user_id
             JOIN user_profiles up ON up.user_id = ss.student_user_id
             LEFT JOIN attendance_records ar
                ON ar.student_user_id = ss.student_user_id
               AND ar.section_id = ss.section_id
               AND ar.semester_id = ss.semester_id
               AND ar.attendance_date BETWEEN :week_start AND :week_end
             WHERE ss.section_id = :section_id
               AND ss.semester_id = :semester_id
               AND ss.enrollment_status = 'active'
             GROUP BY ss.student_user_id, u.school_id, up.last_name, up.first_name
             ORDER BY up.last_name, up.first_name"
        );
        $weeklyStmt->execute([
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'section_id' => $selectedSectionId,
            'semester_id' => $currentSemesterId,
        ]);
        $weeklyRows = $weeklyStmt->fetchAll();
    }
}

renderHeadTeacherPortalStart($user ?? [], 'attendance');
?>
<section class="mt-6 space-y-6">
    <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Live Practicum Monitoring</h2>
            <p class="mt-1 text-sm text-slate-600">Real-time attendance tracking across all geofenced locations.</p>
            <span class="mt-3 inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">LIVE - <?= (int) $kpi['active_now'] ?> online</span>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Today</p>
                <p class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($todayLabel) ?></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current Time</p>
                <p id="live-time" class="mt-1 text-sm font-semibold text-slate-900">00:00:00</p>
            </div>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Active Students Now</p>
            <p class="mt-2 text-3xl font-bold text-slate-900"><?= (int) $kpi['active_now'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Logged In Today</p>
            <p class="mt-2 text-3xl font-bold text-slate-900"><?= (int) $kpi['logged_today'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Completed 8 Hours</p>
            <p class="mt-2 text-3xl font-bold text-slate-900"><?= (int) $kpi['completed_8h'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Sections Monitored</p>
            <p class="mt-2 text-3xl font-bold text-slate-900"><?= (int) $kpi['sections_monitored'] ?></p>
        </article>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-lg font-semibold text-slate-900">Live Section Status</h3>
        <div class="mt-4 grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($sectionStatuses as $section): ?>
                <a href="?section_id=<?= (int) $section['id'] ?>" class="rounded-xl border p-5 transition hover:bg-slate-50 <?= ((int) $section['id'] === $selectedSectionId) ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50' ?>">
                    <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) $section['section_name']) ?></p>
                    <p class="mt-2 text-2xl font-bold text-slate-900">
                        <?= (int) $section['active_today'] ?>
                        <span class="text-base font-medium text-slate-500">/ <?= (int) $section['total_students'] ?> Active</span>
                    </p>
                </a>
            <?php endforeach; ?>
            <?php if (!$sectionStatuses): ?>
                <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">No sections available.</div>
            <?php endif; ?>
        </div>

        <div class="mt-5 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            <p class="font-semibold">Attendance Policy Reminder</p>
            <p class="mt-1">Daily attendance is credited up to <span class="font-semibold">8 hours/day</span>. Weekly maximum is <span class="font-semibold">40 hours</span> (Monday-Friday only).</p>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="text-lg font-semibold text-slate-900">Weekly Attendance Analytics</h3>
            <p class="text-sm text-slate-500">Week: <?= htmlspecialchars($weekStart) ?> to <?= htmlspecialchars($weekEnd) ?></p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[760px] w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                <tr class="text-xs uppercase tracking-wide text-slate-500">
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Student</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Mon</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Tue</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Wed</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Thu</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Fri</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Total</th>
                    <th class="border-b border-slate-200 px-4 py-3 font-semibold">Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($weeklyRows as $row): ?>
                    <?php
                    $total = (float) $row['total_hours'];
                    $statusClass = $total >= 16 ? 'bg-blue-100 text-blue-700' : ($total > 0 ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700');
                    $statusLabel = $total >= 16 ? 'On Track' : ($total > 0 ? 'Needs Follow-up' : 'Critical');
                    ?>
                    <tr class="transition hover:bg-slate-50">
                        <td class="border-b border-slate-100 px-4 py-3 font-medium">
                            <?= htmlspecialchars((string) ($row['last_name'] . ', ' . $row['first_name'])) ?>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) $row['school_id']) ?></div>
                        </td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= number_format((float) $row['mon_hours'], 2) ?>h</td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= number_format((float) $row['tue_hours'], 2) ?>h</td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= number_format((float) $row['wed_hours'], 2) ?>h</td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= number_format((float) $row['thu_hours'], 2) ?>h</td>
                        <td class="border-b border-slate-100 px-4 py-3"><?= number_format((float) $row['fri_hours'], 2) ?>h</td>
                        <td class="border-b border-slate-100 px-4 py-3 font-semibold"><?= number_format($total, 2) ?></td>
                        <td class="border-b border-slate-100 px-4 py-3"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$weeklyRows): ?>
                    <tr>
                        <td colspan="8" class="border-b border-slate-100 px-4 py-6 text-center text-sm text-slate-500">No weekly attendance data yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php
$extraScript = '<script>const clockElement=document.getElementById("live-time");function updateClock(){const now=new Date();clockElement.textContent=now.toLocaleTimeString("en-US",{hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:true});}updateClock();setInterval(updateClock,1000);</script>';
renderHeadTeacherPortalEnd($extraScript);

