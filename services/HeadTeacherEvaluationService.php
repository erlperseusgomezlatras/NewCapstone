<?php
declare(strict_types=1);

require_once __DIR__ . '/EvaluationFormService.php';
require_once __DIR__ . '/SupervisorService.php';

final class HeadTeacherEvaluationService
{
    private EvaluationFormService $forms;

    public function __construct(private PDO $pdo)
    {
        new SupervisorService($pdo);
        $this->forms = new EvaluationFormService($pdo);
    }

    public function activeSemesterId(): int
    {
        $stmt = $this->pdo->query("SELECT id FROM semesters WHERE semester_status = 'active' ORDER BY start_date DESC, id DESC LIMIT 1");
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function filterOptions(int $semesterId): array
    {
        if ($semesterId <= 0) return ['schools' => [], 'sections' => []];

        $base = "FROM semester_students sems
                 JOIN users u ON u.id = sems.student_user_id
                 JOIN roles r ON r.id = u.role_id AND r.role_code = 'student'
                 LEFT JOIN user_profiles up ON up.user_id = u.id
                 LEFT JOIN student_geofence_assignments sga ON sga.student_user_id = u.id AND sga.semester_id = sems.semester_id AND sga.assignment_status = 'active'
                 LEFT JOIN geofence_locations assigned_school ON assigned_school.id = sga.geofence_id
                 WHERE sems.semester_id = :semester_id AND sems.enrollment_status = 'active'";

        $schoolsStmt = $this->pdo->prepare("SELECT DISTINCT TRIM(COALESCE(assigned_school.name, '')) AS value {$base} AND TRIM(COALESCE(assigned_school.name, '')) <> '' ORDER BY value");
        $schoolsStmt->execute(['semester_id' => $semesterId]);
        $sectionsStmt = $this->pdo->prepare("SELECT DISTINCT TRIM(COALESCE(up.original_section, '')) AS value {$base} AND TRIM(COALESCE(up.original_section, '')) <> '' ORDER BY value");
        $sectionsStmt->execute(['semester_id' => $semesterId]);

        return [
            'schools' => array_map('strval', $schoolsStmt->fetchAll(PDO::FETCH_COLUMN)),
            'sections' => array_map('strval', $sectionsStmt->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    public function students(int $semesterId, string $school = '', string $section = '', string $search = '', string $evaluationStatus = ''): array
    {
        if ($semesterId <= 0) return [];

        $where = ["sems.semester_id = :semester_id", "sems.enrollment_status = 'active'"];
        $params = ['semester_id' => $semesterId];
        if ($school !== '') { $where[] = 'TRIM(COALESCE(assigned_school.name, \'\')) = :school'; $params['school'] = $school; }
        if ($section !== '') { $where[] = 'TRIM(COALESCE(up.original_section, \'\')) = :section'; $params['section'] = $section; }
        if ($search !== '') {
            // Native PDO MySQL prepares do not allow one named placeholder
            // to appear more than once in a statement.
            $where[] = '(CONCAT_WS(\' \', up.first_name, up.last_name) LIKE :search_name OR u.school_id LIKE :search_school_id)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_school_id'] = '%' . $search . '%';
        }
        if ($evaluationStatus !== '') {
            $statusCondition = match ($evaluationStatus) {
                'Submitted' => 'esub.id IS NOT NULL',
                'In Progress' => 'esub.id IS NULL AND sa.supervisor_id IS NOT NULL',
                'Not Evaluated' => 'esub.id IS NULL AND sa.supervisor_id IS NULL',
                default => '',
            };
            if ($statusCondition !== '') {
                $where[] = $statusCondition;
            }
        }

        $sql = "SELECT u.id AS student_id, u.school_id,
                       CONCAT_WS(' ', up.first_name, up.last_name) AS student_name,
                       TRIM(COALESCE(up.original_section, '')) AS section_name,
                       TRIM(COALESCE(assigned_school.name, '')) AS partnered_school,
                       COALESCE(NULLIF(CONCAT_WS(' ', submitted_sup_profile.first_name, submitted_sup_profile.last_name), ''), CONCAT_WS(' ', sup_profile.first_name, sup_profile.last_name)) AS supervisor_name,
                       esub.id AS submission_id,
                       CASE WHEN esub.id IS NOT NULL THEN 'Submitted'
                            WHEN sa.supervisor_id IS NOT NULL THEN 'In Progress'
                            ELSE 'Not Evaluated' END AS evaluation_status
                FROM semester_students sems
                JOIN users u ON u.id = sems.student_user_id
                JOIN roles r ON r.id = u.role_id AND r.role_code = 'student'
                LEFT JOIN user_profiles up ON up.user_id = u.id
                LEFT JOIN supervisor_student_selections sa
                  ON sa.student_id = u.id
                 AND sa.semester_id = sems.semester_id
                LEFT JOIN supervisor_profiles sp ON sp.user_id = sa.supervisor_id
                LEFT JOIN user_profiles sup_profile ON sup_profile.user_id = sa.supervisor_id
                LEFT JOIN student_geofence_assignments sga ON sga.student_user_id = u.id AND sga.semester_id = sems.semester_id AND sga.assignment_status = 'active'
                LEFT JOIN geofence_locations assigned_school ON assigned_school.id = sga.geofence_id
                LEFT JOIN evaluation_submissions esub ON esub.student_id = u.id AND esub.semester_id = :submission_semester_id
                LEFT JOIN user_profiles submitted_sup_profile ON submitted_sup_profile.user_id = esub.supervisor_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY student_name, u.school_id";
        $params['submission_semester_id'] = $semesterId;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function submittedEvaluation(int $submissionId): ?array
    {
        if ($submissionId <= 0) return null;
        $stmt = $this->pdo->prepare(
            "SELECT es.*, u.school_id, CONCAT_WS(' ', student_profile.first_name, student_profile.last_name) AS student_name,
                    TRIM(COALESCE(student_profile.original_section, '')) AS section_name,
                    COALESCE(NULLIF(sah.partnered_school, ''), sp.partnered_school) AS partnered_school,
                    COALESCE(NULLIF(sah.supervisor_email, ''), sup.email) AS supervisor_email,
                    COALESCE(NULLIF(sah.supervisor_name, ''), CONCAT_WS(' ', supervisor_profile.first_name, supervisor_profile.last_name)) AS supervisor_name,
                    COALESCE(NULLIF(sah.position_title, ''), sp.position_title) AS supervisor_position
             FROM evaluation_submissions es
             JOIN users u ON u.id = es.student_id
             LEFT JOIN user_profiles student_profile ON student_profile.user_id = u.id
             LEFT JOIN supervisor_profiles sp ON sp.user_id = es.supervisor_id
             LEFT JOIN users sup ON sup.id = es.supervisor_id
             LEFT JOIN user_profiles supervisor_profile ON supervisor_profile.user_id = es.supervisor_id
             LEFT JOIN supervisor_assignment_history sah ON sah.student_id = es.student_id AND sah.supervisor_id = es.supervisor_id AND sah.semester_id = es.semester_id
             WHERE es.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $submissionId]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$submission) return null;

        $form = $this->forms->get((int) $submission['evaluation_form_id']);
        if (!$form) return null;
        $scale = $this->pdo->prepare('SELECT rating_value, rating_label, display_order FROM evaluation_submission_ratings WHERE submission_id = :id ORDER BY display_order');
        $scale->execute(['id' => $submissionId]);
        $ratingSnapshot = $scale->fetchAll(PDO::FETCH_ASSOC);
        if ($ratingSnapshot) $form['ratings'] = $ratingSnapshot;
        $answers = $this->pdo->prepare('SELECT question_id, rating_value FROM evaluation_answers WHERE submission_id = :id');
        $answers->execute(['id' => $submissionId]);
        $submission['answers'] = $answers->fetchAll(PDO::FETCH_KEY_PAIR);
        $comments = $this->pdo->prepare('SELECT comment_field_id, comment_text FROM evaluation_submission_comments WHERE submission_id = :id');
        $comments->execute(['id' => $submissionId]);
        $submission['comments'] = $comments->fetchAll(PDO::FETCH_KEY_PAIR);
        $cert = $this->pdo->prepare('SELECT supervisor_name, supervisor_position, signed_date FROM evaluation_submission_certification WHERE submission_id = :id');
        $cert->execute(['id' => $submissionId]);
        $submission['certification'] = $cert->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($submission['certification']['supervisor_name'])) $submission['supervisor_name'] = $submission['certification']['supervisor_name'];
        $signature = $this->pdo->prepare('SELECT signature_image, mime_type FROM evaluation_signatures WHERE submission_id = :id');
        $signature->execute(['id' => $submissionId]);
        $signatureRow = $signature->fetch(PDO::FETCH_ASSOC);
        $submission['signature_data'] = $signatureRow
            ? 'data:' . ($signatureRow['mime_type'] ?: 'image/png') . ';base64,' . base64_encode((string) $signatureRow['signature_image'])
            : '';
        $submission['form'] = $form;
        return $submission;
    }
}
