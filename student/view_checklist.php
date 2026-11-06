<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/role_check.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';

date_default_timezone_set('Asia/Manila');
requireRole('student');

$pdo = getPDO();
$user = currentUser() ?? [];
$studentId = (int) ($user['id'] ?? 0);
if ($studentId <= 0) {
    header('Location: ' . appUrl('public/login.php'));
    exit;
}

function monday(DateTimeImmutable $date): DateTimeImmutable
{
    return $date->modify('-' . ((int) $date->format('N') - 1) . ' days')->setTime(0, 0, 0);
}

function checklistStatusLabel(int $checkedCount, int $totalCount): string
{
    if ($totalCount <= 0) {
        return 'No Items';
    }

    return $checkedCount === $totalCount ? 'Completed' : 'Incomplete';
}

function checklistStatusClass(int $checkedCount, int $totalCount): string
{
    if ($totalCount <= 0) {
        return 'warn';
    }

    return $checkedCount === $totalCount ? 'ok' : 'warn';
}

$contextStmt = $pdo->prepare(
    "SELECT sem.id AS semester_id, sem.start_date, sem.practicum_start_date, sem.end_date
     FROM semesters sem
     WHERE sem.semester_status = 'active'
     ORDER BY sem.id DESC
     LIMIT 1"
);
$contextStmt->execute();
$context = $contextStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$weekStart = isset($_GET['week_start']) ? (string) $_GET['week_start'] : '';
$records = [];
$weekLabel = 'Checklist';
$checkedCount = 0;
$totalCount = 0;
$totalScore = 0;
$errors = [];

if ($context === null) {
    $errors[] = 'No active practicum context was found for your account.';
} elseif ($weekStart === '') {
    $errors[] = 'A checklist week was not specified.';
} else {
    $weekDate = DateTimeImmutable::createFromFormat('Y-m-d', $weekStart);
    if ($weekDate === false) {
        $errors[] = 'Invalid checklist week.';
    } else {
        $weekStart = monday($weekDate)->format('Y-m-d');

        $semesterWeeks = [];
        foreach (practicumBuildWeeksFromStart((string) ($context['practicum_start_date'] ?? '')) as $week) {
            $semesterWeeks[(string) $week['start']] = $week;
        }
        $weekLabel = isset($semesterWeeks[$weekStart])
            ? practicumWeekDisplayLabel($semesterWeeks[$weekStart])
            : 'Week';

        $labelColumn = 'item_name';
        $displayOrderColumn = 'display_order';
        $columnCheckStmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $columnCheckStmt->execute([
            'table_name' => 'evaluation_checklist_items',
            'column_name' => 'item_name',
        ]);
        if ($columnCheckStmt->fetchColumn() === false) {
            $labelColumn = 'label';
        }

        $columnCheckStmt->execute([
            'table_name' => 'evaluation_checklist_items',
            'column_name' => 'display_order',
        ]);
        if ($columnCheckStmt->fetchColumn() === false) {
            $displayOrderColumn = 'sort_order';
        }

        $stmt = $pdo->prepare(
            "SELECT
                ci.{$labelColumn} AS label,
                ci.points,
                swc.is_checked,
                swc.awarded_points,
                swc.week_start
             FROM student_weekly_checklist swc
             JOIN evaluation_checklist_items ci
               ON ci.id = swc.checklist_item_id
             WHERE swc.student_user_id = :student_user_id
               AND swc.semester_id = :semester_id
               AND swc.week_start = :week_start
             ORDER BY ci.{$displayOrderColumn} ASC, ci.id ASC"
        );
        $stmt->execute([
            'student_user_id' => $studentId,
            'semester_id' => (int) $context['semester_id'],
            'week_start' => $weekStart,
        ]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($records as $record) {
            $isChecked = (int) ($record['is_checked'] ?? 0) === 1;
            $totalCount++;
            if ($isChecked) {
                $checkedCount++;
                $totalScore += (int) ($record['awarded_points'] ?? $record['points'] ?? 0);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>View Checklist</title>
  <link rel="icon" type="image/png" href="/assets/images/logo_college.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body{background:#f4f6fb}
    .card{background:#fff;border:1px solid #dbe4ef;border-radius:14px}
    .status{font-size:.75rem;padding:.3rem .7rem;border-radius:999px;font-weight:700}
    .status.ok{background:#dcfce7;color:#166534}
    .status.warn{background:#fef3c7;color:#92400e}
    .status.bad{background:#fee2e2;color:#991b1b}
  </style>
</head>
<body class="text-slate-900">
<header class="bg-emerald-700 text-white">
  <div class="max-w-5xl mx-auto px-4 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <div class="font-semibold">Student / Checklist</div>
      <div class="text-sm text-emerald-100">Review your weekly checklist evaluation</div>
    </div>
    <a href="<?= htmlspecialchars(appUrl('student/dashboard.php') . '?tab=records&records_tab=checklist') ?>" class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/20">Back to Records</a>
  </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-8 space-y-4">
  <?php foreach ($errors as $error): ?>
    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= htmlspecialchars($error) ?></div>
  <?php endforeach; ?>

  <?php if ($errors === []): ?>
    <section class="card p-6 space-y-4">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 class="text-3xl font-bold tracking-tight"><?= htmlspecialchars($weekLabel) ?></h1>
          <p class="mt-2 text-sm text-slate-600">Week start: <?= htmlspecialchars($weekStart) ?></p>
        </div>
        <div class="flex items-center gap-3">
          <span class="status <?= checklistStatusClass($checkedCount, $totalCount) ?>"><?= htmlspecialchars(checklistStatusLabel($checkedCount, $totalCount)) ?></span>
          <span class="rounded-full bg-sky-50 px-4 py-2 text-sm font-semibold text-sky-700"><?= (int) $totalScore ?> pts</span>
        </div>
      </div>

      <div class="overflow-auto border rounded-xl">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="text-left p-3">Checklist Item</th>
              <th class="text-left p-3">Status</th>
              <th class="text-left p-3">Points</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $item): ?>
              <?php $isChecked = (int) ($item['is_checked'] ?? 0) === 1; ?>
              <tr class="border-t">
                <td class="p-3"><?= htmlspecialchars((string) ($item['label'] ?? '')) ?></td>
                <td class="p-3">
                  <span class="<?= $isChecked ? 'text-emerald-700' : 'text-rose-700' ?>">
                    <?= $isChecked ? '✓ Checked' : '✗ Not Checked' ?>
                  </span>
                </td>
                <td class="p-3"><?= $isChecked ? (int) ($item['awarded_points'] ?? $item['points'] ?? 0) : 0 ?> pts</td>
              </tr>
            <?php endforeach; ?>
            <?php if ($records === []): ?>
              <tr><td colspan="3" class="p-4 text-center text-slate-500">No checklist items were recorded for this week.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="rounded-2xl border border-sky-200 bg-sky-50 px-5 py-4 text-sky-800 font-semibold">
        Total Score: <?= (int) $totalScore ?> pts
      </div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
