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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function editJournalFlash(string $type, string $message): void
{
    $_SESSION['edit_journal_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function pullEditJournalFlash(): ?array
{
    $flash = $_SESSION['edit_journal_flash'] ?? null;
    unset($_SESSION['edit_journal_flash']);

    return is_array($flash) ? $flash : null;
}

function renderEditJournalField(array $question, mixed $value): void
{
    $questionId = (int) ($question['id'] ?? 0);
    $questionText = (string) ($question['question_text'] ?? 'Question');
    $required = (int) ($question['is_required'] ?? 0) === 1;
    $questionType = (string) ($question['question_type'] ?? 'paragraph');
    $options = $question['options'] ?? [];
    $fieldName = 'answers[' . $questionId . ']';

    echo '<div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">';
    echo '<label class="block text-sm font-semibold text-slate-800">' . htmlspecialchars($questionText);
    if ($required) {
        echo ' <span class="text-rose-600">*</span>';
    }
    echo '</label>';

    if ($questionType === 'paragraph') {
        echo '<textarea name="' . htmlspecialchars($fieldName) . '" class="mt-3 min-h-[140px] w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"' . ($required ? ' required' : '') . '>' . htmlspecialchars((string) ($value ?? '')) . '</textarea>';
    } elseif ($questionType === 'short_answer') {
        echo '<input type="text" name="' . htmlspecialchars($fieldName) . '" value="' . htmlspecialchars((string) ($value ?? '')) . '" class="mt-3 w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"' . ($required ? ' required' : '') . '>';
    } elseif ($questionType === 'dropdown') {
        echo '<select name="' . htmlspecialchars($fieldName) . '" class="mt-3 w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"' . ($required ? ' required' : '') . '>';
        echo '<option value="">Select an option</option>';
        foreach ($options as $option) {
            $optionText = (string) ($option['option_text'] ?? '');
            $selected = (string) ($value ?? '') === $optionText ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($optionText) . '"' . $selected . '>' . htmlspecialchars($optionText) . '</option>';
        }
        echo '</select>';
    } elseif ($questionType === 'multiple_choice') {
        echo '<div class="mt-3 space-y-2">';
        foreach ($options as $option) {
            $optionText = (string) ($option['option_text'] ?? '');
            $checked = (string) ($value ?? '') === $optionText ? ' checked' : '';
            echo '<label class="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-700">';
            echo '<input type="radio" name="' . htmlspecialchars($fieldName) . '" value="' . htmlspecialchars($optionText) . '"' . $checked . ($required ? ' required' : '') . '>';
            echo '<span>' . htmlspecialchars($optionText) . '</span>';
            echo '</label>';
        }
        echo '</div>';
    } elseif ($questionType === 'checkbox') {
        $selectedValues = is_array($value) ? $value : [];
        echo '<div class="mt-3 space-y-2">';
        foreach ($options as $option) {
            $optionText = (string) ($option['option_text'] ?? '');
            $checked = in_array($optionText, $selectedValues, true) ? ' checked' : '';
            echo '<label class="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-700">';
            echo '<input type="checkbox" name="' . htmlspecialchars($fieldName) . '[]" value="' . htmlspecialchars($optionText) . '"' . $checked . '>';
            echo '<span>' . htmlspecialchars($optionText) . '</span>';
            echo '</label>';
        }
        echo '</div>';
    }

    echo '</div>';
}

function editJournalAnswerValue(array $question, ?array $answer): mixed
{
    if ($answer === null) {
        return null;
    }

    $type = (string) ($question['question_type'] ?? '');
    $raw = (string) ($answer['answer_text'] ?? '');
    if ($type === 'checkbox') {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    return $raw;
}

$journalService = new JournalModuleService($pdo);
$submissionId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['submission_id'] ?? 0);
$submission = $submissionId > 0 ? $journalService->getSubmissionByIdForStudent($submissionId, $studentId) : null;
$flash = pullEditJournalFlash();

if ($submission === null) {
    http_response_code(404);
}

$form = null;
$questions = [];
$answersByQuestion = [];
if ($submission !== null) {
    $form = $journalService->getFormByIdWithQuestions((int) ($submission['form_id'] ?? 0), false);
    $questions = array_values(array_filter(
        $form['questions'] ?? [],
        static fn(array $question): bool => (int) ($question['is_active'] ?? 0) === 1
    ));
    $answersByQuestion = $journalService->submissionAnswersByQuestion($submission);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $submission !== null) {
    try {
        $isReopened = (int) ($submission['is_reopened'] ?? 0) === 1;
        if ((string) ($submission['status'] ?? '') !== 'declined' && !$isReopened) {
            throw new RuntimeException('Only declined or reopened journals can be edited and resubmitted.');
        }

        if ($questions === []) {
            throw new RuntimeException('No active journal questions are available for this submission.');
        }

        $postedAnswers = $_POST['answers'] ?? [];
        if (!is_array($postedAnswers)) {
            $postedAnswers = [];
        }

        $sanitizedAnswers = [];
        foreach ($questions as $question) {
            $questionId = (int) ($question['id'] ?? 0);
            $questionType = (string) ($question['question_type'] ?? 'paragraph');
            $required = (int) ($question['is_required'] ?? 0) === 1;
            $value = $postedAnswers[$questionId] ?? null;

            if ($questionType === 'checkbox') {
                $value = is_array($value)
                    ? array_values(array_filter(array_map('trim', $value), static fn(string $item): bool => $item !== ''))
                    : [];
                if ($required && $value === []) {
                    throw new RuntimeException('Please answer this question.');
                }
            } else {
                $value = trim((string) $value);
                if ($required && $value === '') {
                    throw new RuntimeException('Please answer this question.');
                }
            }

            $sanitizedAnswers[$questionId] = $value;
        }

        $journalService->saveSubmission(
            $studentId,
            (int) ($submission['semester_id'] ?? 0),
            (int) ($submission['week_no'] ?? 0),
            (string) ($submission['week_start'] ?? ''),
            $form ?? ['id' => 0, 'questions' => $questions],
            $sanitizedAnswers,
            'resubmitted',
            (int) ($submission['id'] ?? 0)
        );

        editJournalFlash('success', 'Journal resubmitted for coordinator review.');
        header('Location: ' . appUrl('student/view_journal.php?id=' . urlencode((string) $submissionId)));
        exit;
    } catch (Throwable $e) {
        $flash = [
            'type' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

$status = (string) ($submission['status'] ?? '');
$statusLabel = $submission !== null ? $journalService->submissionDisplayStatus($status) : 'Not found';
$submittedAt = $submission !== null && !empty($submission['submitted_at'])
    ? (new DateTimeImmutable((string) $submission['submitted_at']))->format('M j, Y h:i A')
    : '--';
$weekLabel = 'Week';
$semesterName = '';
$schoolYear = '';

if ($submission !== null) {
    $metaStmt = $pdo->prepare(
        "SELECT sem.semester_name, sy.year_label, sem.practicum_start_date
         FROM semesters sem
         JOIN school_years sy ON sy.id = sem.school_year_id
         WHERE sem.id = :semester_id
         LIMIT 1"
    );
    $metaStmt->execute(['semester_id' => (int) ($submission['semester_id'] ?? 0)]);
    $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $semesterName = (string) ($meta['semester_name'] ?? '');
    $schoolYear = (string) ($meta['year_label'] ?? '');
    $weekRow = practicumSelectWeek(
        practicumBuildWeeksFromStart((string) ($meta['practicum_start_date'] ?? '')),
        (string) ($submission['week_start'] ?? '')
    );
    if ($weekRow !== null) {
        $weekLabel = practicumWeekDisplayLabel($weekRow);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit Journal</title>
  <link rel="icon" type="image/png" href="/assets/images/logo_college.png">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900">
<main class="mx-auto max-w-4xl px-4 py-8">
  <?php if ($submission === null): ?>
    <section class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
      <h1 class="text-2xl font-semibold">Journal not found</h1>
      <p class="mt-2 text-sm text-slate-600">This journal either does not exist or cannot be edited from your account.</p>
      <a href="<?= htmlspecialchars(appUrl('student/dashboard.php?tab=records&records_tab=journal')) ?>" class="mt-5 inline-flex rounded-full bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Back to Journal Records</a>
    </section>
  <?php else: ?>
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700">Journal Revision</p>
          <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950"><?= htmlspecialchars((string) ($form['title'] ?? 'Weekly Gratitude Journal')) ?></h1>
          <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars($weekLabel) ?> | <?= htmlspecialchars($semesterName) ?> | <?= htmlspecialchars($schoolYear) ?></p>
        </div>
        <div class="rounded-full bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 ring-1 ring-amber-200">
          <?= htmlspecialchars($statusLabel) ?>
        </div>
      </div>
      <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        Update your declined journal and resubmit it for coordinator review. Submitted previously: <?= htmlspecialchars($submittedAt) ?>.
      </div>
      <?php if (!empty($submission['coordinator_feedback'])): ?>
        <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 whitespace-pre-line"><?= htmlspecialchars((string) $submission['coordinator_feedback']) ?></div>
      <?php endif; ?>
    </section>

    <?php if ($flash !== null): ?>
      <div class="mt-6 rounded-2xl border <?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?> px-4 py-3 text-sm shadow-sm">
        <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
      </div>
    <?php endif; ?>

    <form method="post" class="mt-6 space-y-5">
      <input type="hidden" name="submission_id" value="<?= (int) ($submission['id'] ?? 0) ?>">
      <?php foreach ($questions as $question): ?>
        <?php renderEditJournalField($question, editJournalAnswerValue($question, $answersByQuestion[(int) ($question['id'] ?? 0)] ?? null)); ?>
      <?php endforeach; ?>

      <div class="flex flex-wrap gap-3">
        <button type="submit" class="inline-flex items-center rounded-full bg-emerald-700 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-800">Resubmit Journal</button>
        <a href="<?= htmlspecialchars(appUrl('student/view_journal.php?id=' . urlencode((string) $submissionId))) ?>" class="inline-flex items-center rounded-full border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
