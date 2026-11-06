<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/role_check.php';
require_once __DIR__ . '/../services/JournalModuleService.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';

requireRole('student');
$pdo = getPDO();
$user = currentUser() ?? [];
$studentId = (int) ($user['id'] ?? 0);
if ($studentId <= 0) {
    header('Location: ' . appUrl('public/login.php'));
    exit;
}

function tableExists(PDO $pdo, string $table): bool
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

function journalStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'reviewed' => 'Reviewed',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'resubmitted' => 'Resubmitted',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function journalStatusClass(string $status): string
{
    return match ($status) {
        'approved' => 'ok',
        'declined' => 'bad',
        default => 'warn',
    };
}

function renderLegacyField(string $heading, string $value): void
{
    $value = trim($value);
    if ($value === '') {
        echo '<div class="mb-4"><h3 class="text-sm font-semibold text-slate-600 mb-2">' . htmlspecialchars($heading) . '</h3><div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-slate-500">No content provided.</div></div>';
        return;
    }

    echo '<div class="mb-4"><h3 class="text-sm font-semibold text-slate-600 mb-2">' . htmlspecialchars($heading) . '</h3><div class="rounded-2xl border border-slate-200 bg-white p-4 text-slate-700 whitespace-pre-line">' . htmlspecialchars($value) . '</div></div>';
}

function journalViewValue(array $answer): string
{
    $type = (string) ($answer['question_type_snapshot'] ?? '');
    $raw = (string) ($answer['answer_text'] ?? '');
    if ($type === 'checkbox') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $values = array_values(array_filter(array_map('trim', $decoded), static fn(string $item): bool => $item !== ''));
            return $values !== [] ? implode(', ', $values) : 'No content provided.';
        }
    }

    $raw = trim($raw);
    return $raw !== '' ? $raw : 'No content provided.';
}

$journalService = new JournalModuleService($pdo);
$submissionId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$requestedSource = (string) ($_GET['source'] ?? 'dynamic');
$journal = null;
$journalSource = 'new';

if ($submissionId > 0) {
    if ($requestedSource !== 'legacy') {
        $journal = $journalService->getSubmissionByIdForStudent($submissionId, $studentId);
    }

    if ($journal === null && tableExists($pdo, 'student_journals')) {
        $stmt = $pdo->prepare(
            "SELECT sj.*, sem.practicum_start_date, sem.start_date AS semester_start_date, sem.end_date AS semester_end_date, sy.year_label,
                    sem.semester_name, CONCAT(up.last_name, ', ', up.first_name) AS coordinator_name
             FROM student_journals sj
             JOIN semesters sem ON sem.id = sj.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             LEFT JOIN user_profiles up ON up.user_id = sj.reviewed_by
             WHERE sj.id = :id
               AND sj.student_user_id = :student_user_id
             LIMIT 1"
        );
        $stmt->execute([
            'id' => $submissionId,
            'student_user_id' => $studentId,
        ]);
        $journal = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $journalSource = 'legacy';
    } elseif ($journal !== null) {
        $metaStmt = $pdo->prepare(
            "SELECT sem.practicum_start_date, sem.start_date AS semester_start_date, sem.end_date AS semester_end_date, sy.year_label, sem.semester_name
             FROM journal_submissions js
             JOIN semesters sem ON sem.id = js.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             WHERE js.id = :id
             LIMIT 1"
        );
        $metaStmt->execute(['id' => $submissionId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $journal = array_merge($journal, $meta);
    }
}

$journalFound = $journal !== null;
$status = $journalFound ? (string) ($journal['status'] ?? '') : '';
$statusLabel = $journalFound
    ? ((int) ($journal['is_reopened'] ?? 0) === 1 ? 'Reopened' : journalStatusLabel($status))
    : 'Not found';
$submittedAt = $journalFound && !empty($journal['submitted_at'])
    ? (new DateTimeImmutable((string) $journal['submitted_at']))->format('M j, Y h:i A')
    : '--';
$feedback = $journalFound ? (string) ($journal['coordinator_feedback'] ?? '') : '';
$reviewedBy = $journalFound ? (string) ($journal['coordinator_name'] ?? '') : '';
$reviewedAt = $journalFound && !empty($journal['reviewed_at'])
    ? (new DateTimeImmutable((string) $journal['reviewed_at']))->format('M j, Y h:i A')
    : null;
$journalWeekLabel = 'Journal';
if ($journalFound) {
    $weekRow = practicumSelectWeek(
        practicumBuildWeeksFromStart((string) ($journal['practicum_start_date'] ?? '')),
        (string) ($journal['week_start'] ?? '')
    );
    $journalWeekLabel = $weekRow !== null ? practicumWeekDisplayLabel($weekRow) : 'Week';
}

$studentName = (string) ($user['name'] ?? 'Student');
$schoolId = (string) ($user['school_id'] ?? '');
$partneredSchool = (string) ($user['school_name'] ?? '');
if ($partneredSchool === '' && $journalFound && !empty($journal['semester_id']) && tableExists($pdo, 'student_geofence_assignments')) {
    $schoolStmt = $pdo->prepare(
        "SELECT g.name
         FROM student_geofence_assignments sga
         JOIN geofence_locations g ON g.id = sga.geofence_id
         WHERE sga.student_user_id = :student_user_id
           AND sga.semester_id = :semester_id
         ORDER BY sga.assigned_at DESC, sga.id DESC
         LIMIT 1"
    );
    $schoolStmt->execute([
        'student_user_id' => $studentId,
        'semester_id' => (int) ($journal['semester_id'] ?? 0),
    ]);
    $partneredSchool = (string) ($schoolStmt->fetchColumn() ?: '');
}
$schoolYear = (string) ($journal['year_label'] ?? '');
$semesterName = (string) ($journal['semester_name'] ?? '');
$weekStart = (string) ($journal['week_start'] ?? '');
$questionAnswers = $journalFound && $journalSource === 'new' ? (array) ($journal['answers'] ?? []) : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>View Journal</title>
  <link rel="icon" type="image/png" href="/assets/images/logo_college.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body{background:#f4f6fb}
    .card{background:#fff;border:1px solid #dbe4ef;border-radius:14px}
    .status{font-size:.75rem;padding:.3rem .7rem;border-radius:999px;font-weight:700}
    .status.ok{background:#dcfce7;color:#166534}
    .status.warn{background:#fef3c7;color:#92400e}
    .status.bad{background:#fee2e2;color:#991b1b}
    @media print {
      .no-print { display: none !important; }
      body { background: #fff; }
      main { max-width: none !important; padding: 0 !important; }
      .print-sheet { border: 0 !important; box-shadow: none !important; }
    }
  </style>
</head>
<body class="text-slate-900">
<header class="bg-emerald-700 text-white no-print">
  <div class="max-w-5xl mx-auto px-4 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <div class="font-semibold">Student / Journal</div>
      <div class="text-sm text-emerald-100">View your journal and coordinator feedback</div>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php if ($journalFound && $journalSource === 'new' && $status === 'declined'): ?>
        <a href="<?= htmlspecialchars(appUrl('student/edit_journal.php') . '?id=' . urlencode((string) $submissionId)) ?>" class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100">Revise Journal</a>
      <?php endif; ?>
      <?php if ($journalFound): ?>
        <a href="<?= htmlspecialchars(appUrl('api/download_journal_pdf.php') . '?submission_id=' . urlencode((string) $submissionId)) ?>" class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
          Download Journal PDF
        </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(appUrl('student/dashboard.php') . '?tab=records&records_tab=journal') ?>" class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/20">Back to Records</a>
    </div>
  </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-8 space-y-4">
  <?php if (!$journalFound): ?>
    <div class="card p-6 text-slate-700">
      <h1 class="text-2xl font-semibold mb-2">Journal not found</h1>
      <p class="text-sm text-slate-600">This journal either does not exist or is not accessible from your account.</p>
      <p class="mt-4"><a href="<?= htmlspecialchars(appUrl('student/dashboard.php') . '?tab=records&records_tab=journal') ?>" class="inline-flex rounded-full bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Return to Journal Records</a></p>
    </div>
  <?php else: ?>
    <section class="space-y-4 print-sheet">
      <div class="card p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 class="text-3xl font-bold tracking-tight"><?= htmlspecialchars($journalWeekLabel) ?> Journal</h1>
            <p class="mt-2 text-sm text-slate-600">Submitted at <?= htmlspecialchars($submittedAt) ?> | Status: <span class="status <?= journalStatusClass($status) ?>"><?= htmlspecialchars($statusLabel) ?></span></p>
            <?php if ($journalFound && (int) ($journal['is_reopened'] ?? 0) === 1): ?>
              <p class="mt-2 text-sm text-amber-700">This journal was reopened by your coordinator. Use the Journal tab to submit it again.</p>
            <?php endif; ?>
          </div>
          <div class="text-sm text-slate-500 no-print">Official journal record</div>
        </div>
      </div>

      <div class="card p-6">
        <div class="grid gap-3 sm:grid-cols-2 text-sm text-slate-700">
          <div><span class="font-semibold text-slate-500">Student Name:</span> <?= htmlspecialchars($studentName) ?></div>
          <div><span class="font-semibold text-slate-500">School ID:</span> <?= htmlspecialchars($schoolId) ?></div>
          <div><span class="font-semibold text-slate-500">Partnered School:</span> <?= htmlspecialchars($partneredSchool !== '' ? $partneredSchool : '--') ?></div>
          <div><span class="font-semibold text-slate-500">School Year:</span> <?= htmlspecialchars($schoolYear) ?></div>
          <div><span class="font-semibold text-slate-500">Semester:</span> <?= htmlspecialchars($semesterName) ?></div>
          <div><span class="font-semibold text-slate-500">Week:</span> <?= htmlspecialchars($journalWeekLabel) ?></div>
          <div><span class="font-semibold text-slate-500">Submission Date:</span> <?= htmlspecialchars($submittedAt) ?></div>
          <div><span class="font-semibold text-slate-500">Week Start:</span> <?= htmlspecialchars($weekStart) ?></div>
          <div><span class="font-semibold text-slate-500">Journal Score:</span>
            <?php if ($journalSource === 'new'): ?>
              <?= (int) ($journal['points_awarded'] ?? 0) ?> / <?= max(1, (int) ($journal['max_points'] ?? 50)) ?> points
            <?php else: ?>
              Not graded
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card p-6 space-y-5">
        <h2 class="text-xl font-semibold">Journal Content</h2>
        <?php if ($journalSource === 'new'): ?>
          <?php foreach ($questionAnswers as $answer): ?>
            <?php renderLegacyField((string) ($answer['question_text_snapshot'] ?? 'Question'), journalViewValue($answer)); ?>
          <?php endforeach; ?>
        <?php else: ?>
          <?php
            renderLegacyField('I am grateful for', (string) ($journal['grateful_for'] ?? ''));
            renderLegacyField('Something I am proud of', (string) ($journal['proud_of'] ?? ''));
            renderLegacyField('Words to inspire', (string) ($journal['words_to_inspire'] ?? ''));
            renderLegacyField('Words of affirmation', (string) ($journal['affirmations'] ?? ''));
            renderLegacyField('Next week I look forward to', (string) ($journal['look_forward_to'] ?? ''));
            renderLegacyField('Feeling', (string) ($journal['feeling'] ?? ''));
          ?>
        <?php endif; ?>
      </div>

      <div class="card p-6">
        <h2 class="text-xl font-semibold mb-4">Coordinator Feedback</h2>
        <?php if ($feedback !== ''): ?>
          <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-900 whitespace-pre-line"><?= htmlspecialchars($feedback) ?></div>
          <div class="mt-4 text-sm text-slate-600 space-y-1">
            <div>Reviewed by: <?= htmlspecialchars($reviewedBy !== '' ? $reviewedBy : 'Coordinator') ?></div>
            <?php if ($reviewedAt !== null): ?><div>Reviewed at: <?= htmlspecialchars($reviewedAt) ?></div><?php endif; ?>
          </div>
        <?php else: ?>
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-slate-500">No feedback yet.</div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
