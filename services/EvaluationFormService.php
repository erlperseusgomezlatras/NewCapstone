<?php

declare(strict_types=1);

final class EvaluationFormService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    /**
     * The project is deployed through XAMPP without a migration runner. Keep
     * the module usable when the feature SQL has not yet been imported.
     * The migration remains available at config/evaluation_management.sql.
     */
    private function ensureSchema(): void
    {
        $check = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'evaluation_forms'"
        );
        $check->execute();
        if ((int) $check->fetchColumn() > 0) {
            foreach ([
                'institution_name' => "ALTER TABLE evaluation_forms ADD COLUMN institution_name VARCHAR(255) NOT NULL DEFAULT 'PHINMA - CAGAYAN DE ORO COLLEGE' AFTER title",
                'department_name' => "ALTER TABLE evaluation_forms ADD COLUMN department_name VARCHAR(255) NOT NULL DEFAULT 'COLLEGE OF EDUCATION' AFTER institution_name",
                'target_school_type' => "ALTER TABLE evaluation_forms ADD COLUMN target_school_type ENUM('public_school','private_school') NOT NULL DEFAULT 'public_school' AFTER title",
            ] as $column => $ddl) {
                $columnCheck = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='evaluation_forms' AND column_name=?");
                $columnCheck->execute([$column]);
                if ((int) $columnCheck->fetchColumn() === 0) $this->pdo->exec($ddl);
            }
            return;
        }

        $schemaPath = __DIR__ . '/../config/evaluation_management.sql';
        if (!is_readable($schemaPath)) {
            throw new RuntimeException('Evaluation Management database schema is missing.');
        }

        $this->pdo->exec((string) file_get_contents($schemaPath));
    }

    public function listForms(): array
    {
        return $this->pdo->query(
            "SELECT ef.*, sem.semester_name, sy.year_label,
                    (SELECT COUNT(*) FROM evaluation_sections es WHERE es.form_id = ef.id) AS section_count,
                    (SELECT COUNT(*) FROM evaluation_questions eq JOIN evaluation_sections es2 ON es2.id = eq.section_id WHERE es2.form_id = ef.id) AS question_count
             FROM evaluation_forms ef
             JOIN semesters sem ON sem.id = ef.semester_id
             JOIN school_years sy ON sy.id = sem.school_year_id
             ORDER BY ef.created_at DESC, ef.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $this->pdo->prepare('SELECT ef.*, sem.semester_name, sy.year_label FROM evaluation_forms ef JOIN semesters sem ON sem.id = ef.semester_id JOIN school_years sy ON sy.id = sem.school_year_id WHERE ef.id = :id');
        $stmt->execute(['id' => $id]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$form) return null;

        $stmt = $this->pdo->prepare('SELECT * FROM evaluation_rating_scales WHERE form_id = :id ORDER BY display_order, id');
        $stmt->execute(['id' => $id]); $form['ratings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $form['rating_highest'] = $form['ratings'] ? (int) $form['ratings'][0]['rating_value'] : 5;
        $form['rating_lowest'] = $form['ratings'] ? (int) $form['ratings'][count($form['ratings']) - 1]['rating_value'] : 1;
        foreach ($form['ratings'] as $index => &$rating) {
            $rating['rating_label'] = $index === 0 ? 'Excellent' : ($index === count($form['ratings']) - 1 ? 'Below Average' : '');
        }
        unset($rating);
        $stmt = $this->pdo->prepare('SELECT * FROM evaluation_sections WHERE form_id = :id ORDER BY display_order, id');
        $stmt->execute(['id' => $id]); $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $questionStmt = $this->pdo->prepare('SELECT * FROM evaluation_questions WHERE section_id = :id ORDER BY display_order, id');
        foreach ($sections as &$section) { $questionStmt->execute(['id' => (int) $section['id']]); $section['questions'] = $questionStmt->fetchAll(PDO::FETCH_ASSOC); }
        unset($section); $form['sections'] = $sections;
        $stmt = $this->pdo->prepare('SELECT * FROM evaluation_comment_fields WHERE form_id = :id ORDER BY display_order, id');
        $stmt->execute(['id' => $id]); $form['comments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare('SELECT * FROM evaluation_certification_settings WHERE form_id = :id');
        $stmt->execute(['id' => $id]); $form['certification'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return $form;
    }

    public function save(array $data, int $userId, ?int $id = null): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        $semesterId = (int) ($data['semester_id'] ?? 0);
        $schoolType = (string) ($data['target_school_type'] ?? '');
        if ($title === '') throw new InvalidArgumentException('Evaluation title is required.');
        if ($semesterId <= 0) throw new InvalidArgumentException('School year and semester are required.');
        if (!in_array($schoolType, ['public_school', 'private_school'], true)) throw new InvalidArgumentException('Choose whether this evaluation is for public or private school deployment.');
        $semesterStmt = $this->pdo->prepare('SELECT semester_no FROM semesters WHERE id = :semester_id LIMIT 1');
        $semesterStmt->execute(['semester_id' => $semesterId]);
        $semesterNo = (int) ($semesterStmt->fetchColumn() ?: 0);
        $requiredSchoolType = match ($semesterNo) {
            1 => 'public_school',
            2 => 'private_school',
            default => null,
        };
        if ($requiredSchoolType === null || $schoolType !== $requiredSchoolType) {
            $semesterLabel = $semesterNo === 1 ? '1st Semester' : ($semesterNo === 2 ? '2nd Semester' : 'Selected semester');
            $schoolLabel = $semesterNo === 1 ? 'Public' : 'Private';
            throw new InvalidArgumentException($semesterLabel . ' evaluations must be for ' . $schoolLabel . ' School placements.');
        }
        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
        $highest = filter_var($data['rating_highest'] ?? null, FILTER_VALIDATE_INT);
        $lowest = filter_var($data['rating_lowest'] ?? null, FILTER_VALIDATE_INT);
        if ($highest === false || $lowest === false || $highest <= $lowest) {
            throw new InvalidArgumentException('Highest rating must be a whole number greater than the lowest rating.');
        }
        if (($highest - $lowest) > 100) throw new InvalidArgumentException('The rating scale may contain at most 101 values.');
        $ratings = [];
        for ($value = $highest; $value >= $lowest; $value--) {
            $ratings[] = ['value' => (string) $value, 'label' => $value === $highest ? 'Excellent' : ($value === $lowest ? 'Below Average' : '')];
        }
        foreach ($sections as $section) { if (trim((string) ($section['title'] ?? '')) === '') throw new InvalidArgumentException('Every section needs a title.'); foreach ((array) ($section['questions'] ?? []) as $question) if (trim((string) ($question['text'] ?? '')) === '') throw new InvalidArgumentException('Every question needs text.'); }
        $active = !empty($data['is_active']);

        $this->pdo->beginTransaction();
        try {
            // Keep one active template per deployment school type.
            if ($active) { $this->pdo->query('SELECT id FROM evaluation_forms FOR UPDATE'); }
            if ($id !== null && $id > 0) {
                $check = $this->pdo->prepare('SELECT id FROM evaluation_forms WHERE id = :id'); $check->execute(['id' => $id]); if (!$check->fetchColumn()) throw new InvalidArgumentException('Evaluation form not found.');
                $stmt = $this->pdo->prepare('UPDATE evaluation_forms SET semester_id=:semester_id,title=:title,target_school_type=:target_school_type,institution_name=:institution_name,department_name=:department_name,description=:description,instructions=:instructions,is_active=:active WHERE id=:id');
                $stmt->execute(['semester_id'=>$semesterId,'title'=>$title,'target_school_type'=>$schoolType,'institution_name'=>trim((string)($data['institution_name']??'')) ?: 'PHINMA - CAGAYAN DE ORO COLLEGE','department_name'=>trim((string)($data['department_name']??'')) ?: 'COLLEGE OF EDUCATION','description'=>trim((string)($data['description']??'')) ?: null,'instructions'=>trim((string)($data['instructions']??'')) ?: null,'active'=>$active?1:0,'id'=>$id]);
                $formId = $id;
                // Preserve the scale used by existing submissions before the
                // editable form scale is replaced below.
                $this->pdo->exec("INSERT INTO evaluation_submission_ratings (submission_id, rating_value, rating_label, display_order)
                    SELECT es.id, rs.rating_value, rs.rating_label, rs.display_order
                    FROM evaluation_submissions es
                    JOIN evaluation_rating_scales rs ON rs.form_id = es.evaluation_form_id
                    WHERE es.evaluation_form_id = {$formId}
                      AND NOT EXISTS (
                          SELECT 1 FROM evaluation_submission_ratings existing
                          WHERE existing.submission_id = es.id
                      )");
                foreach (['evaluation_rating_scales','evaluation_certification_settings'] as $table) { $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE form_id = :id"); $stmt->execute(['id'=>$formId]); }

                // Questions are referenced by submitted evaluation answers. Do not
                // delete and recreate them during an edit, or those answers lose
                // their parent question and the FK correctly rejects the change.
                $existingSectionsStmt = $this->pdo->prepare('SELECT id FROM evaluation_sections WHERE form_id = :form_id ORDER BY display_order, id');
                $existingSectionsStmt->execute(['form_id' => $formId]);
                $existingSectionIds = array_map('intval', $existingSectionsStmt->fetchAll(PDO::FETCH_COLUMN));
                $existingQuestionsStmt = $this->pdo->prepare('SELECT eq.id, eq.section_id FROM evaluation_questions eq JOIN evaluation_sections es ON es.id = eq.section_id WHERE es.form_id = :form_id ORDER BY es.display_order, eq.display_order, eq.id');
                $existingQuestionsStmt->execute(['form_id' => $formId]);
                $existingQuestions = $existingQuestionsStmt->fetchAll(PDO::FETCH_ASSOC);
                $existingCommentsStmt = $this->pdo->prepare('SELECT id FROM evaluation_comment_fields WHERE form_id = :form_id');
                $existingCommentsStmt->execute(['form_id' => $formId]);
                $existingCommentIds = array_map('intval', $existingCommentsStmt->fetchAll(PDO::FETCH_COLUMN));
            } else {
                $stmt = $this->pdo->prepare('INSERT INTO evaluation_forms (semester_id,title,target_school_type,institution_name,department_name,description,instructions,is_active,created_by) VALUES (:semester_id,:title,:target_school_type,:institution_name,:department_name,:description,:instructions,:active,:created_by)');
                $stmt->execute(['semester_id'=>$semesterId,'title'=>$title,'target_school_type'=>$schoolType,'institution_name'=>trim((string)($data['institution_name']??'')) ?: 'PHINMA - CAGAYAN DE ORO COLLEGE','department_name'=>trim((string)($data['department_name']??'')) ?: 'COLLEGE OF EDUCATION','description'=>trim((string)($data['description']??'')) ?: null,'instructions'=>trim((string)($data['instructions']??'')) ?: null,'active'=>$active?1:0,'created_by'=>$userId > 0 ? $userId : null]);
                $formId = (int) $this->pdo->lastInsertId();
            }
            if ($active) { $this->pdo->prepare('UPDATE evaluation_forms SET is_active = CASE WHEN id = :id THEN 1 ELSE 0 END WHERE target_school_type=:school_type')->execute(['id'=>$formId,'school_type'=>$schoolType]); }
            $stmt = $this->pdo->prepare('INSERT INTO evaluation_rating_scales (form_id,rating_value,rating_label,display_order) VALUES (?,?,?,?)'); foreach ($ratings as $i=>$rating) $stmt->execute([$formId,trim((string)$rating['value']),trim((string)$rating['label']),$i]);
            $sectionStmt = $this->pdo->prepare('INSERT INTO evaluation_sections (form_id,section_title,display_order) VALUES (?,?,?)');
            $sectionUpdateStmt = $this->pdo->prepare('UPDATE evaluation_sections SET section_title=:title, display_order=:display_order WHERE id=:id AND form_id=:form_id');
            $questionStmt = $this->pdo->prepare('INSERT INTO evaluation_questions (section_id,question_text,display_order,is_required) VALUES (?,?,?,?)');
            $questionUpdateStmt = $this->pdo->prepare('UPDATE evaluation_questions SET question_text=:text, display_order=:display_order, is_required=:required WHERE id=:id AND section_id=:section_id');
            $submittedQuestionIds = [];
            $submittedSectionIds = [];
            foreach ($sections as $si=>$section) {
                $requestedSectionId = (int) ($section['id'] ?? 0);
                $sectionId = 0;
                if ($requestedSectionId > 0 && in_array($requestedSectionId, $existingSectionIds ?? [], true)) {
                    $sectionId = $requestedSectionId;
                    $sectionUpdateStmt->execute(['title'=>trim((string)$section['title']), 'display_order'=>$si, 'id'=>$sectionId, 'form_id'=>$formId]);
                } else {
                    $sectionStmt->execute([$formId,trim((string)$section['title']),$si]);
                    $sectionId=(int)$this->pdo->lastInsertId();
                }
                $submittedSectionIds[] = $sectionId;
                foreach ((array)($section['questions']??[]) as $qi=>$question) {
                    $requestedQuestionId = (int) ($question['id'] ?? 0);
                    $belongsToSection = false;
                    foreach (($existingQuestions ?? []) as $existingQuestion) {
                        if ((int)$existingQuestion['id'] === $requestedQuestionId && (int)$existingQuestion['section_id'] === $sectionId) { $belongsToSection = true; break; }
                    }
                    if ($requestedQuestionId > 0 && $belongsToSection) {
                        $questionUpdateStmt->execute(['text'=>trim((string)$question['text']), 'display_order'=>$qi, 'required'=>!empty($question['required'])?1:0, 'id'=>$requestedQuestionId, 'section_id'=>$sectionId]);
                        $submittedQuestionIds[] = $requestedQuestionId;
                    } else {
                        $questionStmt->execute([$sectionId,trim((string)$question['text']),$qi,!empty($question['required'])?1:0]);
                        $submittedQuestionIds[] = (int)$this->pdo->lastInsertId();
                    }
                }
            }
            if ($id !== null && $id > 0) {
                // A question may already be part of a submitted evaluation.  Do
                // the ownership and answer check in the DELETE itself so a
                // question can never be removed between the check and delete
                // (and so the FK remains the final safeguard, not the normal
                // edit path).
                $deleteQuestionStmt = $this->pdo->prepare(
                    'DELETE eq FROM evaluation_questions eq
                     INNER JOIN evaluation_sections es ON es.id = eq.section_id
                     WHERE eq.id = :id AND es.form_id = :form_id
                       AND NOT EXISTS (
                           SELECT 1 FROM evaluation_answers ea
                           WHERE ea.question_id = eq.id
                       )'
                );
                foreach ($existingQuestions as $existingQuestion) {
                    $questionId = (int) $existingQuestion['id'];
                    if (in_array($questionId, $submittedQuestionIds, true)) continue;
                    $deleteQuestionStmt->execute(['id' => $questionId, 'form_id' => $formId]);
                }
                $deleteEmptySectionStmt = $this->pdo->prepare(
                    'DELETE es FROM evaluation_sections es
                     WHERE es.id = :id AND es.form_id = :form_id
                       AND NOT EXISTS (
                           SELECT 1 FROM evaluation_questions eq
                           WHERE eq.section_id = es.id
                       )'
                );
                foreach ($existingSectionIds as $existingSectionId) {
                    if (in_array($existingSectionId, $submittedSectionIds, true)) continue;
                    $deleteEmptySectionStmt->execute(['id' => $existingSectionId, 'form_id' => $formId]);
                }
            }
            $commentStmt = $this->pdo->prepare('INSERT INTO evaluation_comment_fields (form_id,field_title,placeholder,is_required,is_enabled,display_order) VALUES (?,?,?,?,?,?)');
            $commentUpdateStmt = $this->pdo->prepare('UPDATE evaluation_comment_fields SET field_title=:title, placeholder=:placeholder, is_required=:required, is_enabled=:enabled, display_order=:display_order WHERE id=:id AND form_id=:form_id');
            $submittedCommentIds = [];
            foreach ((array)($data['comments']??[]) as $i=>$comment) {
                if (trim((string)($comment['title']??'')) === '') continue;
                $commentId = (int)($comment['id']??0);
                $commentValues = ['title'=>trim((string)$comment['title']), 'placeholder'=>trim((string)($comment['placeholder']??''))?:null, 'required'=>!empty($comment['required'])?1:0, 'enabled'=>!empty($comment['enabled'])?1:0, 'display_order'=>$i];
                if ($commentId > 0 && in_array($commentId, $existingCommentIds ?? [], true)) {
                    $commentUpdateStmt->execute($commentValues + ['id'=>$commentId, 'form_id'=>$formId]);
                    $submittedCommentIds[] = $commentId;
                } else {
                    $commentStmt->execute([$formId,$commentValues['title'],$commentValues['placeholder'],$commentValues['required'],$commentValues['enabled'],$i]);
                    $submittedCommentIds[] = (int)$this->pdo->lastInsertId();
                }
            }
            if ($id !== null && $id > 0) {
                $commentUseCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM evaluation_submission_comments WHERE comment_field_id = :comment_id');
                $deleteCommentStmt = $this->pdo->prepare('DELETE FROM evaluation_comment_fields WHERE id = :id AND form_id = :form_id');
                foreach ($existingCommentIds as $existingCommentId) {
                    if (in_array($existingCommentId, $submittedCommentIds, true)) continue;
                    $commentUseCountStmt->execute(['comment_id'=>$existingCommentId]);
                    if ((int)$commentUseCountStmt->fetchColumn() === 0) $deleteCommentStmt->execute(['id'=>$existingCommentId, 'form_id'=>$formId]);
                }
            }
            $cert = is_array($data['certification'] ?? null) ? $data['certification'] : [];
            $stmt = $this->pdo->prepare('INSERT INTO evaluation_certification_settings (form_id,certification_text,require_supervisor_name,require_signature,require_date,require_position) VALUES (?,?,?,?,?,?)'); $stmt->execute([$formId,trim((string)($cert['text']??''))?:null,!empty($cert['supervisor_name'])?1:0,!empty($cert['signature'])?1:0,!empty($cert['date'])?1:0,!empty($cert['position'])?1:0]);
            $this->pdo->commit(); return $formId;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('SELECT ef.target_school_type, sem.semester_no FROM evaluation_forms ef JOIN semesters sem ON sem.id = ef.semester_id WHERE ef.id = :id');
        $stmt->execute(['id' => $id]);
        $formContext = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$formContext) throw new InvalidArgumentException('Evaluation form not found.');
        $requiredSchoolType = match ((int) $formContext['semester_no']) {
            1 => 'public_school',
            2 => 'private_school',
            default => null,
        };
        if ($active && ($requiredSchoolType === null || (string) $formContext['target_school_type'] !== $requiredSchoolType)) {
            throw new InvalidArgumentException('Evaluation school type must match its semester placement rule.');
        }
        $this->pdo->beginTransaction(); try {
            $schoolType = (string) $formContext['target_school_type'];
            if ($active) {
                $this->pdo->query('SELECT id FROM evaluation_forms FOR UPDATE');
                $this->pdo->prepare('UPDATE evaluation_forms SET is_active=0 WHERE target_school_type=:school_type')->execute(['school_type'=>$schoolType]);
            }
            $this->pdo->prepare('UPDATE evaluation_forms SET is_active=:active WHERE id=:id')->execute(['active'=>$active?1:0,'id'=>$id]);
            $this->pdo->commit();
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    public function duplicate(int $id, int $userId): int
    {
        $form=$this->get($id); if(!$form) throw new InvalidArgumentException('Evaluation form not found.');
        $form['title']=trim((string)$form['title']).' (Copy)'; $form['is_active']=false;
        $form['certification']=[
            'text'=>$form['certification']['certification_text']??'',
            'supervisor_name'=>$form['certification']['require_supervisor_name']??0,
            'signature'=>$form['certification']['require_signature']??0,
            'date'=>$form['certification']['require_date']??0,
            'position'=>$form['certification']['require_position']??0,
        ];
        return $this->save($form,$userId);
    }

    public function delete(int $id): void
    {
        $formStmt = $this->pdo->prepare('SELECT is_active FROM evaluation_forms WHERE id=:id');
        $formStmt->execute(['id' => $id]);
        $isActive = $formStmt->fetchColumn();
        if ($isActive === false || (int) $isActive === 1) {
            throw new InvalidArgumentException('Only inactive evaluation forms can be deleted.');
        }

        // Submitted evaluations are historical records. Their answers and
        // comments reference this form's questions and comment fields, so
        // deleting the template would either fail on foreign keys or destroy
        // the historical form structure.
        $submissionStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM evaluation_submissions WHERE evaluation_form_id=:id'
        );
        $submissionStmt->execute(['id' => $id]);
        if ((int) $submissionStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException(
                'This evaluation form cannot be deleted because it has submitted evaluations. Deactivate it to preserve historical records.'
            );
        }

        $stmt = $this->pdo->prepare('DELETE FROM evaluation_forms WHERE id=:id AND is_active=0');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) throw new InvalidArgumentException('Only inactive evaluation forms can be deleted.');
    }
}
