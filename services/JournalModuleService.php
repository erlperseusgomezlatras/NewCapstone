<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/JournalModuleRepository.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';

final class JournalModuleService
{
    private PDO $pdo;
    private JournalModuleRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->repository = new JournalModuleRepository($this->pdo);
    }

    public static function questionTypes(): array
    {
        return [
            'paragraph' => 'Paragraph',
            'short_answer' => 'Short Answer',
            'multiple_choice' => 'Multiple Choice',
            'checkbox' => 'Checkbox',
            'dropdown' => 'Dropdown',
        ];
    }

    public function getActiveSemesterForm(int $semesterId, int $createdBy = 0): array
    {
        if ($semesterId <= 0) {
            return [];
        }

        $form = $this->repository->seedDefaultFormIfMissing($semesterId, $createdBy);
        return $form;
    }

    public function getActiveFormWithQuestions(int $semesterId, int $createdBy = 0): ?array
    {
        if ($semesterId <= 0) {
            return null;
        }

        $form = $this->repository->seedDefaultFormIfMissing($semesterId, $createdBy);
        return $form !== [] ? $form : null;
    }

    public function listFormsForSemester(int $semesterId): array
    {
        return $semesterId > 0 ? $this->repository->listFormsForSemester($semesterId) : [];
    }

    public function saveForm(int $semesterId, string $title, string $description, bool $isActive, int $createdBy = 0, ?int $formId = null, int $maxPoints = 50): int
    {
        $title = trim($title);
        $description = trim($description);
        if ($title === '') {
            throw new InvalidArgumentException('Journal form title is required.');
        }
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester selection.');
        }
        if ($maxPoints < 1 || $maxPoints > 10000) {
            throw new InvalidArgumentException('Maximum journal points must be between 1 and 10,000.');
        }

        if ($formId !== null && $formId > 0) {
            $this->repository->updateForm($formId, $title, $description, $isActive, $maxPoints);
            return $formId;
        }

        return $this->repository->createForm($semesterId, $title, $description, $createdBy, $isActive, $maxPoints);
    }

    public function deleteForm(int $formId): void
    {
        if ($formId <= 0) {
            throw new InvalidArgumentException('Invalid journal form selection.');
        }

        $this->repository->deleteForm($formId);
    }

    public function canDeleteForm(int $formId): bool
    {
        if ($formId <= 0) {
            return false;
        }

        return $this->repository->countSubmissionsForForm($formId) === 0;
    }

    public function getFormForSemester(int $semesterId, int $formId, int $createdBy = 0): ?array
    {
        if ($semesterId <= 0) {
            return null;
        }

        if ($formId > 0) {
            $form = $this->getFormByIdWithQuestions($formId);
            if ($form !== null && (int) ($form['semester_id'] ?? 0) === $semesterId) {
                return $form;
            }
        }

        $activeForm = $this->repository->getActiveFormWithQuestions($semesterId);
        if ($activeForm !== null) {
            return $activeForm;
        }

        $forms = $this->listFormsForSemester($semesterId);
        if ($forms !== []) {
            return $this->getFormByIdWithQuestions((int) $forms[0]['id']);
        }

        return $this->getActiveFormForSemester($semesterId, $createdBy);
    }

    public function saveQuestion(
        int $formId,
        string $questionText,
        string $questionType,
        bool $required,
        int $sortOrder,
        bool $active,
        string $optionsText = '',
        ?int $questionId = null
    ): int {
        $questionText = trim($questionText);
        if ($questionText === '') {
            throw new InvalidArgumentException('Question text is required.');
        }
        $this->assertQuestionType($questionType);

        $options = $this->normalizeOptionsText($optionsText);
        if ($this->questionTypeUsesOptions($questionType) && $options === []) {
            throw new InvalidArgumentException('This question type requires at least one option.');
        }

        if ($questionId !== null && $questionId > 0) {
            $this->repository->updateQuestion($questionId, $questionText, $questionType, $required, $sortOrder, $active, $options);
            return $questionId;
        }

        return $this->repository->createQuestion($formId, $questionText, $questionType, $required, $sortOrder, $active, $options);
    }

    public function deleteQuestion(int $questionId): void
    {
        if ($questionId <= 0) {
            throw new InvalidArgumentException('Invalid question selection.');
        }

        $this->repository->deleteQuestion($questionId);
    }

    public function toggleQuestionActive(int $questionId, bool $active): void
    {
        if ($questionId <= 0) {
            throw new InvalidArgumentException('Invalid question selection.');
        }

        $this->repository->setQuestionActive($questionId, $active);
    }

    public function moveQuestion(int $questionId, int $direction): void
    {
        if ($questionId <= 0 || !in_array($direction, [-1, 1], true)) {
            throw new InvalidArgumentException('Invalid question move.');
        }

        $question = $this->repository->getQuestionById($questionId);
        if ($question === null) {
            throw new InvalidArgumentException('Question not found.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, sort_order
             FROM journal_questions
             WHERE form_id = :form_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['form_id' => (int) $question['form_id']]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($questions === []) {
            return;
        }

        $index = null;
        foreach ($questions as $i => $row) {
            if ((int) $row['id'] === $questionId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapIndex = $index + $direction;
        if (!isset($questions[$swapIndex])) {
            return;
        }

        $currentOrder = (int) $questions[$index]['sort_order'];
        $swapOrder = (int) $questions[$swapIndex]['sort_order'];

        $updateStmt = $this->pdo->prepare('UPDATE journal_questions SET sort_order = :sort_order WHERE id = :id');
        $updateStmt->execute(['sort_order' => $swapOrder, 'id' => (int) $questions[$index]['id']]);
        $updateStmt->execute(['sort_order' => $currentOrder, 'id' => (int) $questions[$swapIndex]['id']]);
    }

    public function getActiveFormForSemester(int $semesterId, int $createdBy = 0): ?array
    {
        return $this->getActiveFormWithQuestions($semesterId, $createdBy);
    }

    public function getStudentFormForSemester(int $semesterId, int $createdBy = 0): ?array
    {
        $form = $this->getActiveFormWithQuestions($semesterId, $createdBy);
        if ($form === null) {
            return null;
        }

        $form['questions'] = array_values(array_filter(
            $form['questions'] ?? [],
            static fn(array $question): bool => (int) ($question['is_active'] ?? 0) === 1
        ));

        return $form;
    }

    public function getFormByIdWithQuestions(int $formId, bool $activeOnly = false): ?array
    {
        if ($formId <= 0) {
            return null;
        }

        $form = $this->repository->getFormWithQuestions($formId);
        if ($form === null) {
            return null;
        }

        if ($activeOnly) {
            $form['questions'] = array_values(array_filter(
                $form['questions'] ?? [],
                static fn(array $question): bool => (int) ($question['is_active'] ?? 0) === 1
            ));
        }

        return $form;
    }

    public function getSubmissionForStudentWeek(int $studentId, int $semesterId, string $weekStart): ?array
    {
        return $this->repository->getSubmissionForStudentWeek($studentId, $semesterId, practicumWeekStart($weekStart));
    }

    public function getSubmissionByIdForStudent(int $submissionId, int $studentId): ?array
    {
        return $this->repository->getSubmissionByIdForStudent($submissionId, $studentId);
    }

    public function getSubmissionById(int $submissionId): ?array
    {
        return $this->repository->getSubmissionById($submissionId);
    }

    public function getCoordinatorSubmissions(int $coordinatorUserId, int $semesterId, string $weekStart): array
    {
        return $this->repository->getCoordinatorSubmissions($coordinatorUserId, $semesterId, practicumWeekStart($weekStart));
    }

    public function getStudentJournalHistory(int $studentId, int $semesterId): array
    {
        return $this->repository->getStudentJournalHistory($studentId, $semesterId);
    }

    public function getJournalWeeksForStudent(int $studentId, int $semesterId): array
    {
        return $studentId > 0 && $semesterId > 0 ? $this->repository->getJournalWeeksForStudent($studentId, $semesterId) : [];
    }

    public function getCoordinatorStudentWeekSubmission(int $coordinatorUserId, int $studentId, int $semesterId, string $weekStart): ?array
    {
        return $this->repository->getCoordinatorStudentWeekSubmission($coordinatorUserId, $studentId, $semesterId, $weekStart);
    }

    public function reopenMissingSubmission(
        int $coordinatorUserId,
        int $studentId,
        int $semesterId,
        string $weekStart,
        int $formId,
        int $weekNo
    ): int {
        if ($coordinatorUserId <= 0 || $studentId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('Invalid reopen context.');
        }

        return $this->repository->reopenMissingSubmission(
            $coordinatorUserId,
            $studentId,
            $semesterId,
            $weekStart,
            $formId,
            $weekNo,
            $coordinatorUserId
        );
    }

    /**
     * Reopen the selected week's journal for every student assigned to the coordinator.
     * The roster comes from the same coordinator-scoped query used by the submissions page.
     */
    public function reopenCoordinatorWeek(
        int $coordinatorUserId,
        int $semesterId,
        string $weekStart,
        int $formId,
        int $weekNo
    ): int {
        if ($coordinatorUserId <= 0 || $semesterId <= 0 || $formId <= 0 || $weekNo <= 0) {
            throw new InvalidArgumentException('Invalid bulk reopen context.');
        }

        $students = $this->repository->getCoordinatorSubmissions(
            $coordinatorUserId,
            $semesterId,
            practicumWeekStart($weekStart)
        );
        $studentIds = [];
        foreach ($students as $student) {
            $studentId = (int) ($student['student_user_id'] ?? 0);
            if ($studentId > 0) {
                $studentIds[$studentId] = true;
            }
        }

        foreach (array_keys($studentIds) as $studentId) {
            $this->repository->reopenMissingSubmission(
                $coordinatorUserId,
                (int) $studentId,
                $semesterId,
                $weekStart,
                $formId,
                $weekNo,
                $coordinatorUserId
            );
        }

        return count($studentIds);
    }

    public function saveSubmission(
        int $studentId,
        int $semesterId,
        int $weekNo,
        string $weekStart,
        array $form,
        array $answers,
        string $status = 'pending',
        ?int $submissionId = null
    ): int {
        if ($studentId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('Invalid submission context.');
        }

        $submissionWeekStart = practicumWeekStart($weekStart);
        if ($weekNo <= 0) {
            throw new InvalidArgumentException('Invalid practicum week.');
        }
        if (!isset($form['id'])) {
            throw new InvalidArgumentException('Journal form is required.');
        }

        $questionMap = [];
        foreach (($form['questions'] ?? []) as $question) {
            $questionMap[(int) $question['id']] = $question;
        }

        $normalizedAnswers = [];
        foreach ($answers as $questionId => $value) {
            $questionId = (int) $questionId;
            if (!isset($questionMap[$questionId])) {
                continue;
            }

            $question = $questionMap[$questionId];
            $normalizedAnswers[] = $this->normalizeAnswer($question, $value);
        }

        $submissionData = [
            'submission_id' => $submissionId,
            'student_user_id' => $studentId,
            'semester_id' => $semesterId,
            'week_no' => $weekNo,
            'week_start' => $submissionWeekStart,
            'form_id' => (int) $form['id'],
            'max_points' => max(1, (int) ($form['max_points'] ?? 50)),
            'status' => $status,
        ];

        return $this->repository->upsertSubmission($submissionData, $normalizedAnswers);
    }

    public function reviewSubmission(int $submissionId, string $status, ?string $feedback, int $reviewedBy = 0, ?int $pointsAwarded = null): void
    {
        if ($submissionId <= 0) {
            throw new InvalidArgumentException('Invalid submission selection.');
        }

        if (!in_array($status, ['approved', 'declined', 'reviewed'], true)) {
            throw new InvalidArgumentException('Invalid review status.');
        }

        $submission = $this->repository->getSubmissionById($submissionId);
        if ($submission === null) {
            throw new InvalidArgumentException('Journal submission not found.');
        }
        $maximumPoints = max(1, (int) ($submission['max_points'] ?? 50));
        $pointsAwarded ??= (int) ($submission['points_awarded'] ?? 0);
        if ($pointsAwarded < 0 || $pointsAwarded > $maximumPoints) {
            throw new InvalidArgumentException('Points awarded must be between 0 and ' . $maximumPoints . '.');
        }

        $this->repository->updateSubmissionReview(
            $submissionId,
            $status,
            $feedback !== null && trim($feedback) !== '' ? trim($feedback) : null,
            $reviewedBy,
            $pointsAwarded
        );
    }

    public function canSubmitForWeek(?array $existingSubmission): bool
    {
        if ($existingSubmission === null) {
            return true;
        }

        $status = (string) ($existingSubmission['status'] ?? '');

        if ((int) ($existingSubmission['is_reopened'] ?? 0) === 1) {
            return true;
        }

        return in_array($status, ['declined', 'resubmitted'], true);
    }

    public function submissionDisplayStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'declined' => 'Declined',
            'reviewed' => 'Reviewed',
            'resubmitted' => 'Resubmitted',
            default => 'Pending',
        };
    }

    public function studentWeekStatusLabel(?array $submission, string $weekStatus, bool $hasSubmitted = false): string
    {
        if ($submission !== null && (int) ($submission['is_reopened'] ?? 0) === 1) {
            return 'Reopened';
        }

        if ($submission !== null && !empty($submission['submitted_at'])) {
            return match ((string) ($submission['status'] ?? '')) {
                'approved' => 'Approved',
                'declined' => 'Declined',
                'reviewed' => 'Reviewed',
                'resubmitted' => 'Submitted',
                default => 'Submitted',
            };
        }

        if ($weekStatus === 'upcoming') {
            return 'Upcoming';
        }

        if ($weekStatus === 'active') {
            return $hasSubmitted ? 'Submitted' : 'Pending';
        }

        return 'Missing';
    }

    public function studentWeekCanEdit(?array $submission, string $weekStatus, bool $isFriday = false): bool
    {
        if ($submission !== null && (int) ($submission['is_reopened'] ?? 0) === 1) {
            return true;
        }

        if ($submission !== null && !empty($submission['submitted_at'])) {
            return false;
        }

        return $weekStatus === 'active' && $isFriday;
    }

    public function answerDisplayValue(array $answer): string
    {
        $type = (string) ($answer['question_type_snapshot'] ?? '');
        $raw = (string) ($answer['answer_text'] ?? '');

        if ($type === 'checkbox') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $values = array_values(array_filter(array_map('trim', $decoded), static fn(string $value): bool => $value !== ''));
                return $values !== [] ? implode(', ', $values) : 'No answer provided.';
            }
        }

        $raw = trim($raw);

        return $raw !== '' ? $raw : 'No answer provided.';
    }

    public function submissionAnswersByQuestion(array $submission): array
    {
        $out = [];
        foreach (($submission['answers'] ?? []) as $answer) {
            $out[(int) $answer['question_id']] = $answer;
        }

        return $out;
    }

    private function assertQuestionType(string $questionType): void
    {
        if (!array_key_exists($questionType, self::questionTypes())) {
            throw new InvalidArgumentException('Unsupported question type.');
        }
    }

    private function questionTypeUsesOptions(string $questionType): bool
    {
        return in_array($questionType, ['multiple_choice', 'checkbox', 'dropdown'], true);
    }

    private function normalizeOptionsText(string $optionsText): array
    {
        $items = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $optionsText) ?: []),
            static fn(string $item): bool => $item !== ''
        ));

        return $items;
    }

    private function normalizeAnswer(array $question, mixed $value): array
    {
        $questionType = (string) $question['question_type'];
        $questionId = (int) $question['id'];
        $questionText = (string) $question['question_text'];
        $questionOptions = $question['options'] ?? [];

        $answerText = null;
        if ($questionType === 'checkbox') {
            $selected = is_array($value)
                ? array_values(array_filter(array_map('trim', $value), static fn(string $item): bool => $item !== ''))
                : [];
            $answerText = json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_array($value)) {
            $answerText = trim((string) ($value['value'] ?? ''));
        } else {
            $answerText = trim((string) $value);
        }

        return [
            'question_id' => $questionId,
            'question_text_snapshot' => $questionText,
            'question_type_snapshot' => $questionType,
            'question_options_snapshot' => !empty($questionOptions) ? json_encode(array_values(array_map(static fn(array $option): string => (string) ($option['option_text'] ?? ''), $questionOptions)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'answer_text' => $answerText,
        ];
    }
}
