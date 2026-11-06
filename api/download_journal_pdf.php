<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

date_default_timezone_set('Asia/Manila');
$pdo = getPDO();
$user = currentUser();
$userRole = (string) ($user['role'] ?? '');
$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    die('Unauthorized');
}

$submissionId = (int) ($_GET['submission_id'] ?? 0);
if ($submissionId <= 0) {
    http_response_code(400);
    die('Invalid submission ID');
}

// Fetch submission with all details
$stmt = $pdo->prepare(
    "SELECT js.*,
            CONCAT(COALESCE(up.first_name,''), ' ', COALESCE(up.last_name,'')) as full_name,
            s.school_id,
            CONCAT(COALESCE(cu.first_name,''), ' ', COALESCE(cu.last_name,'')) as coordinator_name,
            g.name as school_name,
            sy.year_label as school_year,
            sem.semester_name, sem.start_date, sem.end_date
     FROM journal_submissions js
     JOIN users s ON js.student_user_id = s.id
     LEFT JOIN user_profiles up ON up.user_id = s.id
     LEFT JOIN student_geofence_assignments sga ON sga.student_user_id = s.id AND sga.semester_id = js.semester_id AND sga.assignment_status = 'active'
     LEFT JOIN users u ON u.id = sga.coordinator_user_id
     LEFT JOIN user_profiles cu ON cu.user_id = u.id
     LEFT JOIN geofence_locations g ON g.id = sga.geofence_id
     LEFT JOIN semesters sem ON js.semester_id = sem.id
     LEFT JOIN school_years sy ON sy.id = sem.school_year_id
     WHERE js.id = :id
     LIMIT 1"
);
$stmt->execute(['id' => $submissionId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    http_response_code(404);
    die('Journal submission not found');
}

// Fetch answers
$answersStmt = $pdo->prepare(
    'SELECT *
     FROM journal_answers
     WHERE submission_id = :submission_id
     ORDER BY id ASC'
);
$answersStmt->execute(['submission_id' => $submissionId]);
$submission['answers'] = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

// Authorization check
$studentId = (int) ($submission['student_user_id'] ?? 0);
$isOwner = ($userId === $studentId);
$isCoordinator = ($userRole === 'coordinator');
$isHeadTeacher = ($userRole === 'head_teacher');

if (!($isOwner || $isCoordinator || $isHeadTeacher)) {
    http_response_code(403);
    die('Access denied');
}

// Fetch journal form linked to this submission
$formId = (int) ($submission['form_id'] ?? 0);

$formStmt = $pdo->prepare(
    'SELECT jf.*
     FROM journal_forms jf
     WHERE jf.id = :id
     LIMIT 1'
);
$formStmt->execute(['id' => $formId]);
$form = $formStmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    http_response_code(404);
    die('Journal form not found for this submission');
}

// Build PDF HTML using the submission's linked form and answer snapshots
$html = buildJournalPDFHtml($submission, $submission['answers'], $form);

// Generate PDF using mPDF
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 15,
        'margin_bottom' => 15,
    ]);

    $mpdf->WriteHTML($html);

    // Generate filename
    $weekNo = (int) ($submission['week_no'] ?? 0);
    $schoolYear = (string) ($submission['school_year'] ?? date('Y'));
    $semesterName = (string) ($submission['semester_name'] ?? '');
    $studentId = (int) $submission['student_user_id'];
    $filename = sprintf(
        'Journal_Week_%d_%s_%s_%d.pdf',
        $weekNo,
        str_replace(['/', ' '], '-', $schoolYear),
        str_replace([' '], '-', $semesterName),
        $studentId
    );

    // Output PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $mpdf->Output($filename, 'D');
    exit;
} catch (Exception $e) {
    http_response_code(500);
    die('PDF generation error: ' . htmlspecialchars($e->getMessage()));
}

function buildJournalPDFHtml(array $submission, array $answersList, array $form): string
{
    $journalTitle = htmlspecialchars(trim((string) ($form['title'] ?? '')));
    $journalDescription = htmlspecialchars(trim((string) ($form['description'] ?? '')));
    $schoolYear = htmlspecialchars((string) ($submission['school_year'] ?? ''));
    $semesterName = htmlspecialchars((string) ($submission['semester_name'] ?? ''));
    $studentName = htmlspecialchars((string) ($submission['full_name'] ?? 'N/A'));
    $schoolId = htmlspecialchars((string) ($submission['school_id'] ?? 'N/A'));
    $schoolName = htmlspecialchars((string) ($submission['school_name'] ?? 'N/A'));
    $coordinatorName = htmlspecialchars((string) ($submission['coordinator_name'] ?? 'N/A'));
    $weekNo = (int) ($submission['week_no'] ?? 0);
    $submittedAt = $submission['submitted_at'] ?? now();
    $isReopened = (int) ($submission['is_reopened'] ?? 0) === 1;
    $status = htmlspecialchars($isReopened ? 'Reopened' : ucfirst((string) ($submission['status'] ?? 'pending')));
    $statusClass = htmlspecialchars($isReopened ? 'pending' : strtolower((string) ($submission['status'] ?? 'pending')));
    $feedback = htmlspecialchars((string) ($submission['coordinator_feedback'] ?? ''));

    $descriptionHtml = '';
    if ($journalDescription !== '') {
        $descriptionHtml = <<<HTML
            <div class="header-description">{$journalDescription}</div>
HTML;
    }

    $html = <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: 'Arial', sans-serif;
                color: #333;
                line-height: 1.6;
            }
            .header {
                text-align: center;
                border-bottom: 3px solid #047857;
                padding-bottom: 15px;
                margin-bottom: 25px;
            }
            .header-title {
                font-size: 18px;
                font-weight: bold;
                color: #047857;
                margin-bottom: 8px;
            }
            .header-description {
                font-size: 12px;
                color: #666;
                font-weight: 500;
                white-space: pre-wrap;
                word-wrap: break-word;
            }
            .info-section {
                margin-bottom: 20px;
                page-break-inside: avoid;
            }
            .section-title {
                font-size: 13px;
                font-weight: bold;
                background-color: #f0fdf4;
                color: #047857;
                padding: 8px 10px;
                margin-bottom: 10px;
                border-left: 4px solid #047857;
            }
            .info-grid {
                width: 100%;
                margin-bottom: 10px;
            }
            .info-row {
                display: flex;
                margin-bottom: 8px;
                page-break-inside: avoid;
            }
            .info-label {
                width: 35%;
                font-weight: bold;
                color: #666;
                font-size: 11px;
            }
            .info-value {
                width: 65%;
                color: #333;
                font-size: 11px;
                word-wrap: break-word;
            }
            .question-block {
                margin-bottom: 20px;
                page-break-inside: avoid;
                border: 1px solid #e5e7eb;
                padding: 12px;
                background-color: #fafafa;
            }
            .question-text {
                font-weight: bold;
                color: #047857;
                margin-bottom: 8px;
                font-size: 12px;
            }
            .answer-text {
                color: #333;
                font-size: 11px;
                white-space: pre-wrap;
                word-wrap: break-word;
                line-height: 1.5;
            }
            .status-badge {
                display: inline-block;
                padding: 5px 12px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: bold;
            }
            .status-approved {
                background-color: #dcfce7;
                color: #166534;
            }
            .status-declined {
                background-color: #fee2e2;
                color: #991b1b;
            }
            .status-pending {
                background-color: #fef3c7;
                color: #92400e;
            }
            .feedback-box {
                background-color: #eff6ff;
                border-left: 4px solid #3b82f6;
                padding: 12px;
                margin-top: 15px;
                page-break-inside: avoid;
            }
            .feedback-title {
                font-weight: bold;
                color: #1e40af;
                margin-bottom: 8px;
                font-size: 12px;
            }
            .feedback-content {
                color: #333;
                font-size: 11px;
                white-space: pre-wrap;
                word-wrap: break-word;
                line-height: 1.5;
            }
            .footer {
                margin-top: 30px;
                padding-top: 15px;
                border-top: 1px solid #e5e7eb;
                font-size: 10px;
                color: #999;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="header-title">{$journalTitle}</div>
            {$descriptionHtml}
        </div>

        <div class="info-section">
            <div class="section-title">Student Information</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Student Name:</div>
                    <div class="info-value">{$studentName}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">School ID:</div>
                    <div class="info-value">{$schoolId}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Partnered School:</div>
                    <div class="info-value">{$schoolName}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Coordinator:</div>
                    <div class="info-value">{$coordinatorName}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">School Year:</div>
                    <div class="info-value">{$schoolYear}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Semester:</div>
                    <div class="info-value">{$semesterName}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Week Number:</div>
                    <div class="info-value">Week {$weekNo}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Submission Date:</div>
                    <div class="info-value">{$submittedAt}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Journal Status:</div>
                    <div class="info-value">
                        <span class="status-badge status-{$statusClass}">
                            {$status}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-section">
            <div class="section-title">Journal Responses</div>
HTML;

    foreach ($answersList as $answer) {
        $qtext = htmlspecialchars((string) ($answer['question_text_snapshot'] ?? ''));
        $rawAnswer = (string) ($answer['answer_text'] ?? '');
        $qtype = (string) ($answer['question_type_snapshot'] ?? '');

        if ($qtype === 'checkbox') {
            $decoded = json_decode($rawAnswer, true);
            if (is_array($decoded)) {
                $values = array_values(array_filter(array_map('trim', $decoded), static fn(string $item): bool => $item !== ''));
                $answerText = htmlspecialchars($values !== [] ? implode(', ', $values) : '(No response)');
            } else {
                $answerText = htmlspecialchars($rawAnswer !== '' ? $rawAnswer : '(No response)');
            }
        } else {
            $answerText = htmlspecialchars($rawAnswer !== '' ? $rawAnswer : '(No response)');
        }

        $html .= <<<HTML
            <div class="question-block">
                <div class="question-text">Q: {$qtext}</div>
                <div class="answer-text">A: {$answerText}</div>
            </div>
HTML;
    }

    if ($feedback !== '') {
        $html .= <<<HTML
        </div>

        <div class="feedback-box">
            <div class="feedback-title">Coordinator Feedback</div>
            <div class="feedback-content">{$feedback}</div>
        </div>
HTML;
    } else {
        $html .= '</div>';
    }

    $html .= <<<HTML
        <div class="footer">
            <p>This is an official journal record generated by the Practicum Management System.</p>
            <p>Generated on: {$submittedAt}</p>
        </div>
    </body>
    </html>
HTML;

    return $html;
}
