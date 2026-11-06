<?php

declare(strict_types=1);

final class JournalModuleRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
             LIMIT 1'
        );
        $stmt->execute(['table_name' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS journal_forms (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                max_points INT UNSIGNED NOT NULL DEFAULT 50,
                semester_id INT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_journal_forms_semester (semester_id),
                KEY idx_journal_forms_active (semester_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS journal_questions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_id BIGINT UNSIGNED NOT NULL,
                question_text TEXT NOT NULL,
                question_type ENUM('paragraph','short_answer','multiple_choice','checkbox','dropdown') NOT NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_journal_questions_form (form_id),
                KEY idx_journal_questions_sort (form_id, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS journal_question_options (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                question_id BIGINT UNSIGNED NOT NULL,
                option_text VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                KEY idx_journal_question_options_question (question_id, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS journal_submissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_user_id BIGINT UNSIGNED NOT NULL,
                semester_id INT UNSIGNED NOT NULL,
                week_no INT NOT NULL,
                week_start DATE NOT NULL,
                form_id BIGINT UNSIGNED NOT NULL,
                points_awarded INT UNSIGNED NOT NULL DEFAULT 0,
                max_points INT UNSIGNED NOT NULL DEFAULT 50,
                status ENUM('pending','reviewed','approved','declined','resubmitted') NOT NULL DEFAULT 'pending',
                is_reopened TINYINT(1) NOT NULL DEFAULT 0,
                reopened_at DATETIME NULL,
                reopened_by BIGINT UNSIGNED NULL,
                coordinator_feedback TEXT NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_journal_submission_week (student_user_id, semester_id, week_start),
                KEY idx_journal_submission_semester_week (semester_id, week_no, week_start),
                KEY idx_journal_submission_form (form_id),
                KEY idx_journal_submission_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS journal_answers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                submission_id BIGINT UNSIGNED NOT NULL,
                question_id BIGINT UNSIGNED NOT NULL,
                question_text_snapshot TEXT NOT NULL,
                question_type_snapshot VARCHAR(30) NOT NULL,
                question_options_snapshot JSON NULL,
                answer_text LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_journal_answer_submission_question (submission_id, question_id),
                KEY idx_journal_answers_submission (submission_id),
                KEY idx_journal_answers_question (question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!$this->columnExists('journal_forms', 'max_points')) {
            $this->pdo->exec('ALTER TABLE journal_forms ADD COLUMN max_points INT UNSIGNED NOT NULL DEFAULT 50 AFTER description');
        }
        $hadSubmissionMaxPoints = $this->columnExists('journal_submissions', 'max_points');
        if (!$hadSubmissionMaxPoints) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN max_points INT UNSIGNED NOT NULL DEFAULT 50 AFTER form_id');
        }
        if (!$this->columnExists('journal_submissions', 'points_awarded')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN points_awarded INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_points');
        }
        if (!$hadSubmissionMaxPoints) {
            $this->pdo->exec('UPDATE journal_submissions js JOIN journal_forms jf ON jf.id = js.form_id SET js.max_points = GREATEST(COALESCE(jf.max_points, 50), 1)');
        }
        if (!$this->columnExists('journal_submissions', 'coordinator_feedback')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN coordinator_feedback TEXT NULL');
        }
        if (!$this->columnExists('journal_submissions', 'reviewed_by')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN reviewed_by BIGINT UNSIGNED NULL');
        }
        if (!$this->columnExists('journal_submissions', 'reviewed_at')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN reviewed_at DATETIME NULL');
        }
        if (!$this->columnExists('journal_submissions', 'is_reopened')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN is_reopened TINYINT(1) NOT NULL DEFAULT 0 AFTER form_id');
        }
        if (!$this->columnExists('journal_submissions', 'reopened_at')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN reopened_at DATETIME NULL AFTER is_reopened');
        }
        if (!$this->columnExists('journal_submissions', 'reopened_by')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN reopened_by BIGINT UNSIGNED NULL AFTER reopened_at');
        }
        if (!$this->columnExists('journal_submissions', 'week_start')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN week_start DATE NOT NULL AFTER week_no');
        }
        if (!$this->columnExists('journal_submissions', 'form_id')) {
            $this->pdo->exec('ALTER TABLE journal_submissions ADD COLUMN form_id BIGINT UNSIGNED NOT NULL AFTER week_start');
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS journal_reopen_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                coordinator_user_id BIGINT UNSIGNED NOT NULL,
                student_user_id BIGINT UNSIGNED NOT NULL,
                semester_id INT UNSIGNED NOT NULL,
                week_start DATE NOT NULL,
                reopened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_journal_reopen_audit_student_week (student_user_id, semester_id, week_start),
                KEY idx_journal_reopen_audit_coordinator (coordinator_user_id, reopened_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function getActiveForm(int $semesterId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_forms
             WHERE semester_id = :semester_id
               AND is_active = 1
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['semester_id' => $semesterId]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);

        return $form !== false ? $form : null;
    }

    public function getFormById(int $formId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM journal_forms WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $formId]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);

        return $form !== false ? $form : null;
    }

    public function getFormWithQuestions(int $formId): ?array
    {
        $form = $this->getFormById($formId);
        if ($form === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_questions
             WHERE form_id = :form_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['form_id' => $formId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($questions !== []) {
            $questionIds = array_map(static fn(array $row): int => (int) $row['id'], $questions);
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $optionsStmt = $this->pdo->prepare(
                "SELECT *
                 FROM journal_question_options
                 WHERE question_id IN ({$placeholders})
                 ORDER BY question_id ASC, sort_order ASC, id ASC"
            );
            $optionsStmt->execute($questionIds);
            $options = $optionsStmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            foreach ($options as $option) {
                $questionId = (int) $option['question_id'];
                $grouped[$questionId][] = $option;
            }

            foreach ($questions as &$question) {
                $qid = (int) $question['id'];
                $question['options'] = $grouped[$qid] ?? [];
            }
            unset($question);
        }

        $form['questions'] = $questions;

        return $form;
    }

    public function getActiveFormWithQuestions(int $semesterId): ?array
    {
        $form = $this->getActiveForm($semesterId);
        if ($form === null) {
            return null;
        }

        return $this->getFormWithQuestions((int) $form['id']);
    }

    public function listFormsForSemester(int $semesterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_forms
             WHERE semester_id = :semester_id
             ORDER BY is_active DESC, created_at DESC, id DESC'
        );
        $stmt->execute(['semester_id' => $semesterId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createForm(int $semesterId, string $title, string $description, int $createdBy, bool $isActive = true, int $maxPoints = 50): int
    {
        if ($isActive) {
            $this->deactivateFormsForSemester($semesterId);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO journal_forms (title, description, max_points, semester_id, is_active, created_by)
             VALUES (:title, :description, :max_points, :semester_id, :is_active, :created_by)'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'max_points' => $maxPoints,
            'semester_id' => $semesterId,
            'is_active' => $isActive ? 1 : 0,
            'created_by' => $createdBy > 0 ? $createdBy : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateForm(int $formId, string $title, string $description, bool $isActive, int $maxPoints = 50): void
    {
        $form = $this->getFormById($formId);
        if ($form === null) {
            throw new InvalidArgumentException('Journal form not found.');
        }

        if ($isActive) {
            $this->deactivateFormsForSemester((int) $form['semester_id'], $formId);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE journal_forms
             SET title = :title,
                 description = :description,
                 max_points = :max_points,
                 is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'max_points' => $maxPoints,
            'is_active' => $isActive ? 1 : 0,
            'id' => $formId,
        ]);
    }

    public function deactivateFormsForSemester(int $semesterId, int $excludeFormId = 0): void
    {
        $sql = 'UPDATE journal_forms SET is_active = 0 WHERE semester_id = :semester_id';
        $params = ['semester_id' => $semesterId];
        if ($excludeFormId > 0) {
            $sql .= ' AND id <> :exclude_form_id';
            $params['exclude_form_id'] = $excludeFormId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function countSubmissionsForForm(int $formId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM journal_submissions
             WHERE form_id = :form_id'
        );
        $stmt->execute(['form_id' => $formId]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteForm(int $formId): void
    {
        $form = $this->getFormById($formId);
        if ($form === null) {
            throw new InvalidArgumentException('Journal form not found.');
        }

        if ($this->countSubmissionsForForm($formId) > 0) {
            throw new InvalidArgumentException(
                "This journal already contains student submissions and cannot be deleted.\n\nYou may set it as Inactive instead."
            );
        }

        $questionStmt = $this->pdo->prepare('SELECT id FROM journal_questions WHERE form_id = :form_id');
        $questionStmt->execute(['form_id' => $formId]);
        $questionIds = array_map(static fn(array $row): int => (int) $row['id'], $questionStmt->fetchAll(PDO::FETCH_ASSOC));

        if ($questionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $this->pdo->prepare("DELETE FROM journal_question_options WHERE question_id IN ({$placeholders})")->execute($questionIds);
            $this->pdo->prepare("DELETE FROM journal_questions WHERE id IN ({$placeholders})")->execute($questionIds);
        }

        $stmt = $this->pdo->prepare('DELETE FROM journal_forms WHERE id = :id');
        $stmt->execute(['id' => $formId]);
    }

    public function createQuestion(int $formId, string $questionText, string $questionType, bool $required, int $sortOrder, bool $active = true, array $options = []): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO journal_questions (form_id, question_text, question_type, is_required, sort_order, is_active)
             VALUES (:form_id, :question_text, :question_type, :is_required, :sort_order, :is_active)'
        );
        $stmt->execute([
            'form_id' => $formId,
            'question_text' => $questionText,
            'question_type' => $questionType,
            'is_required' => $required ? 1 : 0,
            'sort_order' => $sortOrder,
            'is_active' => $active ? 1 : 0,
        ]);

        $questionId = (int) $this->pdo->lastInsertId();
        $this->replaceQuestionOptions($questionId, $options);

        return $questionId;
    }

    public function updateQuestion(int $questionId, string $questionText, string $questionType, bool $required, int $sortOrder, bool $active = true, array $options = []): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE journal_questions
             SET question_text = :question_text,
                 question_type = :question_type,
                 is_required = :is_required,
                 sort_order = :sort_order,
                 is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            'question_text' => $questionText,
            'question_type' => $questionType,
            'is_required' => $required ? 1 : 0,
            'sort_order' => $sortOrder,
            'is_active' => $active ? 1 : 0,
            'id' => $questionId,
        ]);

        $this->replaceQuestionOptions($questionId, $options);
    }

    public function setQuestionActive(int $questionId, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE journal_questions SET is_active = :is_active WHERE id = :id');
        $stmt->execute([
            'is_active' => $active ? 1 : 0,
            'id' => $questionId,
        ]);
    }

    public function deleteQuestion(int $questionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM journal_question_options WHERE question_id = :question_id');
        $stmt->execute(['question_id' => $questionId]);

        $stmt = $this->pdo->prepare('DELETE FROM journal_questions WHERE id = :id');
        $stmt->execute(['id' => $questionId]);
    }

    public function replaceQuestionOptions(int $questionId, array $options): void
    {
        $deleteStmt = $this->pdo->prepare('DELETE FROM journal_question_options WHERE question_id = :question_id');
        $deleteStmt->execute(['question_id' => $questionId]);

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO journal_question_options (question_id, option_text, sort_order)
             VALUES (:question_id, :option_text, :sort_order)'
        );

        $sortOrder = 1;
        foreach ($options as $option) {
            $optionText = trim((string) $option);
            if ($optionText === '') {
                continue;
            }

            $insertStmt->execute([
                'question_id' => $questionId,
                'option_text' => $optionText,
                'sort_order' => $sortOrder,
            ]);
            $sortOrder++;
        }
    }

    public function getSubmissionForStudentWeek(int $studentId, int $semesterId, string $weekStart): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_submissions
             WHERE student_user_id = :student_user_id
               AND semester_id = :semester_id
               AND week_start = :week_start
             LIMIT 1'
        );
        $stmt->execute([
            'student_user_id' => $studentId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($submission === false) {
            return null;
        }

        $submission['answers'] = $this->getAnswersForSubmission((int) $submission['id']);

        return $submission;
    }

    public function getSubmissionById(int $submissionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM journal_submissions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $submissionId]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($submission === false) {
            return null;
        }

        $submission['answers'] = $this->getAnswersForSubmission($submissionId);

        return $submission;
    }

    public function getSubmissionByIdForStudent(int $submissionId, int $studentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_submissions
             WHERE id = :id
               AND student_user_id = :student_user_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $submissionId,
            'student_user_id' => $studentId,
        ]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($submission === false) {
            return null;
        }

        $submission['answers'] = $this->getAnswersForSubmission($submissionId);

        return $submission;
    }

    public function getAnswersForSubmission(int $submissionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_answers
             WHERE submission_id = :submission_id
             ORDER BY id ASC'
        );
        $stmt->execute(['submission_id' => $submissionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteAnswersForSubmission(int $submissionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM journal_answers WHERE submission_id = :submission_id');
        $stmt->execute(['submission_id' => $submissionId]);
    }

    public function upsertSubmission(array $submissionData, array $answers): int
    {
        $existingId = (int) ($submissionData['submission_id'] ?? 0);
        $maxPoints = max(1, (int) ($submissionData['max_points'] ?? 50));
        if ($existingId > 0) {
            $submissionId = $existingId;
            $updateStmt = $this->pdo->prepare(
                'UPDATE journal_submissions
                 SET form_id = :form_id,
                     max_points = :max_points,
                     points_awarded = 0,
                     status = :status,
                     week_no = :week_no,
                     week_start = :week_start,
                     is_reopened = 0,
                     reopened_at = NULL,
                     reopened_by = NULL,
                     coordinator_feedback = NULL,
                     reviewed_by = NULL,
                     reviewed_at = NULL,
                     submitted_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'form_id' => $submissionData['form_id'],
                'max_points' => $maxPoints,
                'status' => $submissionData['status'],
                'week_no' => $submissionData['week_no'],
                'week_start' => $submissionData['week_start'],
                'id' => $submissionId,
            ]);
        } else {
            $insertStmt = $this->pdo->prepare(
                'INSERT INTO journal_submissions
                    (student_user_id, semester_id, week_no, week_start, form_id, max_points, points_awarded, status, submitted_at, is_reopened)
                 VALUES
                    (:student_user_id, :semester_id, :week_no, :week_start, :form_id, :max_points, 0, :status, CURRENT_TIMESTAMP, 0)'
            );
            $insertStmt->execute([
                'student_user_id' => $submissionData['student_user_id'],
                'semester_id' => $submissionData['semester_id'],
                'week_no' => $submissionData['week_no'],
                'week_start' => $submissionData['week_start'],
                'form_id' => $submissionData['form_id'],
                'max_points' => $maxPoints,
                'status' => $submissionData['status'],
            ]);
            $submissionId = (int) $this->pdo->lastInsertId();
        }

        $this->deleteAnswersForSubmission($submissionId);
        $answerStmt = $this->pdo->prepare(
            'INSERT INTO journal_answers
                (submission_id, question_id, question_text_snapshot, question_type_snapshot, question_options_snapshot, answer_text)
             VALUES
                (:submission_id, :question_id, :question_text_snapshot, :question_type_snapshot, :question_options_snapshot, :answer_text)'
        );

        foreach ($answers as $answer) {
            $answerStmt->execute([
                'submission_id' => $submissionId,
                'question_id' => $answer['question_id'],
                'question_text_snapshot' => $answer['question_text_snapshot'],
                'question_type_snapshot' => $answer['question_type_snapshot'],
                'question_options_snapshot' => $answer['question_options_snapshot'],
                'answer_text' => $answer['answer_text'],
            ]);
        }

        return $submissionId;
    }

    public function reopenMissingSubmission(
        int $coordinatorUserId,
        int $studentUserId,
        int $semesterId,
        string $weekStart,
        int $formId,
        int $weekNo,
        int $reopenedBy
    ): int {
        if ($coordinatorUserId <= 0 || $studentUserId <= 0 || $semesterId <= 0 || $formId <= 0 || $weekNo <= 0) {
            throw new InvalidArgumentException('Invalid reopen context.');
        }

        $weekStart = practicumWeekStart($weekStart);
        $form = $this->getFormById($formId);
        if ($form === null) {
            throw new InvalidArgumentException('Journal form not found.');
        }
        $maxPoints = max(1, (int) ($form['max_points'] ?? 50));
        $existing = $this->getSubmissionForStudentWeek($studentUserId, $semesterId, $weekStart);
        $this->pdo->beginTransaction();
        try {
            if ($existing !== null) {
                if ((int) ($existing['is_reopened'] ?? 0) === 1) {
                    return (int) $existing['id'];
                }

                $updateStmt = $this->pdo->prepare(
                    'UPDATE journal_submissions
                     SET form_id = :form_id,
                         max_points = :max_points,
                         points_awarded = 0,
                         week_no = :week_no,
                         status = :status,
                         coordinator_feedback = NULL,
                         reviewed_by = NULL,
                         reviewed_at = NULL,
                         is_reopened = 1,
                         reopened_at = CURRENT_TIMESTAMP,
                         reopened_by = :reopened_by,
                         submitted_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    'form_id' => $formId,
                    'max_points' => $maxPoints,
                    'week_no' => $weekNo,
                    'status' => 'pending',
                    'reopened_by' => $reopenedBy > 0 ? $reopenedBy : null,
                    'id' => (int) $existing['id'],
                ]);

                $submissionId = (int) $existing['id'];
            } else {
                $insertStmt = $this->pdo->prepare(
                    'INSERT INTO journal_submissions
                        (student_user_id, semester_id, week_no, week_start, form_id, max_points, points_awarded, status, coordinator_feedback, reviewed_by, reviewed_at, submitted_at, is_reopened, reopened_at, reopened_by)
                     VALUES
                        (:student_user_id, :semester_id, :week_no, :week_start, :form_id, :max_points, 0, :status, NULL, NULL, NULL, CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, :reopened_by)'
                );
                $insertStmt->execute([
                    'student_user_id' => $studentUserId,
                    'semester_id' => $semesterId,
                    'week_no' => $weekNo,
                    'week_start' => $weekStart,
                    'form_id' => $formId,
                    'max_points' => $maxPoints,
                    'status' => 'pending',
                    'reopened_by' => $reopenedBy > 0 ? $reopenedBy : null,
                ]);

                $submissionId = (int) $this->pdo->lastInsertId();
            }

            $auditStmt = $this->pdo->prepare(
                'INSERT INTO journal_reopen_audit
                    (coordinator_user_id, student_user_id, semester_id, week_start, reopened_at)
                 VALUES
                    (:coordinator_user_id, :student_user_id, :semester_id, :week_start, CURRENT_TIMESTAMP)'
            );
            $auditStmt->execute([
                'coordinator_user_id' => $coordinatorUserId,
                'student_user_id' => $studentUserId,
                'semester_id' => $semesterId,
                'week_start' => $weekStart,
            ]);

            $this->pdo->commit();

            return $submissionId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getCoordinatorSubmissions(int $coordinatorUserId, int $semesterId, string $weekStart): array
    {
        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
        $hasAssignments = $this->tableExists('student_geofence_assignments');
        $hasDeployments = $this->tableExists('coordinator_deployments');
        $hasSchools = $this->tableExists('coordinator_schools');
        if (!$hasAssignments) {
            return [];
        }

        $assignmentSql = null;
        if ($hasAssignments && $hasDeployments && $hasSchools) {
            $assignmentSql = "(SELECT cd.coordinator_user_id, cd.geofence_id
                                FROM coordinator_deployments cd
                                WHERE cd.deployment_status IN ('active', 'running')
                                UNION ALL
                                SELECT cs.coordinator_user_id, cs.geofence_id
                                FROM coordinator_schools cs
                                WHERE NOT EXISTS (
                                    SELECT 1
                                    FROM coordinator_deployments cd
                                    WHERE cd.coordinator_user_id = cs.coordinator_user_id
                                      AND cd.deployment_status IN ('active', 'running')
                                ))";
        } elseif ($hasAssignments && $hasDeployments) {
            $assignmentSql = '(SELECT coordinator_user_id, geofence_id
                               FROM coordinator_deployments
                               WHERE deployment_status IN (\'active\', \'running\'))';
        } elseif ($hasAssignments && $hasSchools) {
            $assignmentSql = '(SELECT coordinator_user_id, geofence_id FROM coordinator_schools)';
        }

        if ($assignmentSql === null) {
            return [];
        }

        $hasOriginalSection = $this->columnExists('user_profiles', 'original_section');
        $csvSectionSql = $hasOriginalSection ? "NULLIF(TRIM(up.original_section), '')" : "NULL";

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT
                u.id AS student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                COALESCE({$csvSectionSql}, sec.section_code) AS section_code,
                COALESCE(sec.section_name, {$csvSectionSql}) AS section_name,
                jf.id AS form_id,
                jf.title AS form_title,
                jf.description AS form_description,
                COALESCE(js.points_awarded, 0) AS points_awarded,
                COALESCE(js.max_points, jf.max_points, 50) AS max_points,
                js.id AS submission_id,
                js.week_no,
                js.week_start,
                js.status,
                COALESCE(js.is_reopened, 0) AS is_reopened,
                js.reopened_at,
                js.reopened_by,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM journal_reopen_audit jra
                    WHERE jra.student_user_id = u.id
                      AND jra.semester_id = :semester_id_reopen_audit
                      AND jra.week_start = :week_start_reopen_audit
                ) THEN 1 ELSE 0 END AS has_been_reopened,
                js.coordinator_feedback,
                js.reviewed_by,
                js.reviewed_at,
                js.submitted_at
             FROM ({$assignmentSql}) ca
             JOIN student_geofence_assignments sga
               ON sga.geofence_id = ca.geofence_id
              AND sga.assignment_status = 'active'
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = :semester_id
              AND sems.enrollment_status = 'active'
             JOIN semesters sem
               ON sem.id = sems.semester_id
              AND sem.semester_status = 'active'
             JOIN users u ON u.id = sga.student_user_id
             JOIN user_profiles up ON up.user_id = u.id
             LEFT JOIN section_students ss
               ON ss.student_user_id = u.id
              AND ss.semester_id = sems.semester_id
              AND ss.enrollment_status = 'active'
             LEFT JOIN sections sec ON sec.id = ss.section_id
             LEFT JOIN journal_forms jf
               ON jf.semester_id = :semester_id_form
              AND jf.is_active = 1
             LEFT JOIN journal_submissions js
               ON js.student_user_id = u.id
              AND js.semester_id = :semester_id_submission
              AND js.week_start = :week_start
             WHERE ca.coordinator_user_id = :coordinator_user_id
               AND sga.semester_id = :semester_id_assignment
             ORDER BY up.last_name ASC, up.first_name ASC"
        );
        $stmt->execute([
            'semester_id' => $semesterId,
            'semester_id_form' => $semesterId,
            'semester_id_submission' => $semesterId,
            'semester_id_assignment' => $semesterId,
            'semester_id_reopen_audit' => $semesterId,
            'coordinator_user_id' => $coordinatorUserId,
            'week_start' => $weekStart,
            'week_start_reopen_audit' => $weekStart,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (!empty($row['submission_id'])) {
                $row['answers'] = $this->getAnswersForSubmission((int) $row['submission_id']);
            } else {
                $row['answers'] = [];
            }
        }
        unset($row);

        return $rows;
    }

    public function isCoordinatorAssignedToStudent(int $coordinatorUserId, int $studentUserId, int $semesterId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM student_geofence_assignments sga
             JOIN semester_students sems
               ON sems.student_user_id = sga.student_user_id
              AND sems.semester_id = sga.semester_id
              AND sems.enrollment_status = 'active'
             JOIN semesters sem
               ON sem.id = sems.semester_id
              AND sem.semester_status = 'active'
             WHERE sga.coordinator_user_id = :coordinator_user_id
               AND sga.student_user_id = :student_user_id
               AND sga.semester_id = :semester_id
               AND sga.assignment_status = 'active'
             LIMIT 1"
        );
        $stmt->execute([
            'coordinator_user_id' => $coordinatorUserId,
            'student_user_id' => $studentUserId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function getCoordinatorStudentWeekSubmission(
        int $coordinatorUserId,
        int $studentUserId,
        int $semesterId,
        string $weekStart
    ): ?array {
        if (!$this->isCoordinatorAssignedToStudent($coordinatorUserId, $studentUserId, $semesterId)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                u.id AS student_user_id,
                u.school_id,
                up.first_name,
                up.last_name,
                jf.id AS form_id,
                jf.title AS form_title,
                jf.description AS form_description,
                js.id AS submission_id,
                js.week_no,
                js.week_start,
                js.status,
                COALESCE(js.is_reopened, 0) AS is_reopened,
                js.reopened_at,
                js.reopened_by,
                js.coordinator_feedback,
                js.reviewed_by,
                js.reviewed_at,
                js.submitted_at
             FROM student_geofence_assignments sga
             JOIN users u ON u.id = sga.student_user_id
             JOIN user_profiles up ON up.user_id = u.id
             LEFT JOIN journal_forms jf
               ON jf.semester_id = :semester_id_form
              AND jf.is_active = 1
             LEFT JOIN journal_submissions js
               ON js.student_user_id = u.id
              AND js.semester_id = :semester_id_submission
              AND js.week_start = :week_start
             WHERE sga.coordinator_user_id = :coordinator_user_id
               AND sga.student_user_id = :student_user_id
               AND sga.semester_id = :semester_id_assignment
               AND sga.assignment_status = 'active'
             ORDER BY sga.assigned_at DESC, sga.id DESC
             LIMIT 1"
        );
        $stmt->execute([
            'semester_id_form' => $semesterId,
            'semester_id_submission' => $semesterId,
            'semester_id_assignment' => $semesterId,
            'coordinator_user_id' => $coordinatorUserId,
            'student_user_id' => $studentUserId,
            'week_start' => practicumWeekStart($weekStart),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function getJournalWeeksForStudent(int $studentId, int $semesterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT week_start, week_no, status, is_reopened, reopened_at, submitted_at, reviewed_at, coordinator_feedback
             FROM journal_submissions
             WHERE student_user_id = :student_user_id
               AND semester_id = :semester_id
             ORDER BY week_start DESC, submitted_at DESC'
        );
        $stmt->execute([
            'student_user_id' => $studentId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStudentJournalHistory(int $studentId, int $semesterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT js.*
             FROM journal_submissions js
             WHERE js.student_user_id = :student_user_id
               AND js.semester_id = :semester_id
             ORDER BY js.week_start DESC, js.submitted_at DESC'
        );
        $stmt->execute([
            'student_user_id' => $studentId,
            'semester_id' => $semesterId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSubmissionForStudentAndSubmissionId(int $studentId, int $submissionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_submissions
             WHERE id = :id
               AND student_user_id = :student_user_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $submissionId,
            'student_user_id' => $studentId,
        ]);

        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($submission === false) {
            return null;
        }

        $submission['answers'] = $this->getAnswersForSubmission((int) $submission['id']);

        return $submission;
    }

    public function updateSubmissionReview(int $submissionId, string $status, ?string $feedback, int $reviewedBy = 0, int $pointsAwarded = 0): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE journal_submissions
             SET status = :status,
                 points_awarded = :points_awarded,
                 coordinator_feedback = :coordinator_feedback,
                 reviewed_by = :reviewed_by,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'points_awarded' => $pointsAwarded,
            'coordinator_feedback' => $feedback,
            'reviewed_by' => $reviewedBy > 0 ? $reviewedBy : null,
            'id' => $submissionId,
        ]);
    }

    public function getSubmissionByStudentWeekLabel(int $studentId, int $semesterId, int $weekNo): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_submissions
             WHERE student_user_id = :student_user_id
               AND semester_id = :semester_id
               AND week_no = :week_no
             LIMIT 1'
        );
        $stmt->execute([
            'student_user_id' => $studentId,
            'semester_id' => $semesterId,
            'week_no' => $weekNo,
        ]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($submission === false) {
            return null;
        }

        $submission['answers'] = $this->getAnswersForSubmission((int) $submission['id']);

        return $submission;
    }

    public function hasSubmissionForWeek(int $studentId, int $semesterId, string $weekStart): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM journal_submissions
             WHERE student_user_id = :student_user_id
               AND semester_id = :semester_id
               AND week_start = :week_start
             LIMIT 1'
        );
        $stmt->execute([
            'student_user_id' => $studentId,
            'semester_id' => $semesterId,
            'week_start' => $weekStart,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function seedDefaultFormIfMissing(int $semesterId, int $createdBy = 0): array
    {
        $form = $this->getActiveFormWithQuestions($semesterId);
        if ($form !== null) {
            return $form;
        }

        $formId = $this->createForm(
            $semesterId,
            'Weekly Gratitude Journal',
            'Create and manage weekly practicum journal questions from the Journal Management page.',
            $createdBy,
            true
        );

        return $this->getActiveFormWithQuestions($semesterId) ?? [];
    }

    public function getQuestionById(int $questionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM journal_questions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $questionId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);

        return $question !== false ? $question : null;
    }

    public function getQuestionOptions(int $questionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM journal_question_options
             WHERE question_id = :question_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['question_id' => $questionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
