<?php
declare(strict_types=1);
require_once __DIR__ . '/../middleware/role_check.php';
requireRole('supervisor');
require_once __DIR__ . '/../services/SupervisorService.php';
require_once __DIR__ . '/../vendor/autoload.php';

$user = currentUser() ?? [];
$studentId = (int) ($_GET['student_id'] ?? 0);
$service = new SupervisorService(getPDO());
$supervisorId = (int) ($user['id'] ?? 0);
if (!$service->isAssigned($studentId, $supervisorId)) { http_response_code(403); exit('Forbidden'); }
$form = $service->activeFormForStudent($studentId);
if (!$form) { http_response_code(404); exit('Evaluation form not found'); }
$submission = $service->getSubmission($studentId, $supervisorId, (int) $form['id']);
if (!$submission) { http_response_code(404); exit('Evaluation has not been submitted'); }
if (!empty($submission['rating_scale_snapshot'])) $form['ratings'] = $submission['rating_scale_snapshot'];
$student = [];
foreach ($service->assignedStudents($supervisorId) as $row) if ((int) $row['student_id'] === $studentId) { $student = $row; break; }
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

// Use the opaque PDF copy because this PHP installation does not have GD PNG support.
// The transparent source remains available for normal web pages.
$logoPath = realpath(__DIR__ . '/../assets/images/logo_college_pdf.png');
$logoUrl = $logoPath !== false ? 'file:///' . str_replace('\\', '/', ltrim($logoPath, '\\/')) : '';
$instructions = trim((string) ($form['instructions'] ?? ''));
$instructionsBlock = $instructions !== '' ? '<div class="instructions"><strong>Instructions</strong><br>' . nl2br($esc($instructions)) . '</div>' : '';
$studentSection = $esc($student['section_name'] ?? 'Not set');
$submittedTimestamp = strtotime((string) ($submission['submitted_at'] ?? ''));
$submittedDate = $submittedTimestamp !== false ? date('Y-m-d', $submittedTimestamp) : 'Not set';
$html = '<style>body{font-family:dejavusans;font-size:10.5pt;line-height:1.45;color:#1e293b}.header{text-align:center;border-bottom:1px solid #cbd5e1;padding-bottom:14px}.header h1{font-size:18pt;margin:10px 0 0;color:#0f172a}.instructions{margin:16px 0;padding:11px 13px;background:#f8fafc;border:1px solid #e2e8f0}.logo{height:64px;width:64px}.meta{margin:16px 0;padding:11px 13px;background:#f8fafc;border:1px solid #e2e8f0}.section{margin-top:20px}.section h3{font-size:13pt;color:#0f172a;border-bottom:1px solid #94a3b8;padding-bottom:6px;margin-bottom:10px}.question{margin:13px 0 16px;page-break-inside:avoid}.question-text{font-weight:bold;color:#0f172a}.rating-scale{margin-top:6px;color:#475569;font-size:9.5pt}.rating-table{width:72%;border-collapse:collapse}.rating-table td{padding:1px 4px;text-align:center;white-space:nowrap}.rating-table td:first-child{text-align:left}.rating-table td:last-child{text-align:right}.selected-option{font-weight:bold;color:#047857}.comments{margin-top:20px}.comments h3{font-size:13pt;color:#0f172a;border-bottom:1px solid #94a3b8;padding-bottom:6px}</style><div class="header">' . ($logoUrl !== '' ? '<img class="logo" src="' . $esc($logoUrl) . '"><br>' : '') . '<strong>' . $esc($form['institution_name'] ?? '') . '</strong><br><strong>' . $esc($form['department_name'] ?? '') . '</strong><h1>' . $esc($form['title']) . '</h1></div><div class="meta"><strong>Student:</strong> ' . $esc($student['student_name'] ?? '') . ' &nbsp; <strong>Student ID:</strong> ' . $esc($student['school_id'] ?? '') . '<br><strong>Partnered School:</strong> ' . $esc($student['partnered_school'] ?? '') . ' &nbsp; <strong>Submitted:</strong> ' . $esc($submittedDate) . '</div>' . $instructionsBlock;
$html = str_replace('<br><strong>Partnered School:', '<br><strong>Section:</strong> ' . $studentSection . ' &nbsp; <strong>Partnered School:', $html);
$html = str_replace('rating-table{width:72%;border-collapse:collapse}', 'rating-table{width:72%;table-layout:fixed;border-collapse:collapse}', $html);
$studentMeta = '<div class="meta"><strong>Student:</strong> ' . $esc($student['student_name'] ?? '') . ' &nbsp; <strong>Student ID:</strong> ' . $esc($student['school_id'] ?? '') . '<br><strong>Section:</strong> ' . $studentSection . ' &nbsp; <strong>Partnered School:</strong> ' . $esc($student['partnered_school'] ?? 'Not set') . '<br><strong>Supervisor:</strong> ' . $esc($user['name'] ?? 'Not set') . ' &nbsp; <strong>Submitted:</strong> ' . $esc($submittedDate) . '</div>';
$html = preg_replace('/<div class="meta">.*?<\/div>/', $studentMeta, $html, 1) ?? $html;
foreach ($form['sections'] as $section) {
    $html .= '<div class="section"><h3>' . $esc($section['section_title']) . '</h3>';
    $questionNumber = 0;
    foreach ($section['questions'] as $question) {
        $questionNumber++;
        $rating = (string) ($submission['answers'][$question['id']] ?? '');
        $questionText = preg_replace('/^\s*\d+\.\s*/', '', (string) $question['question_text']);
        $requiredMark = !empty($question['is_required']) ? ' *' : '';
        $columnWidth = 100 / max(1, count($form['ratings']));
        $html .= '<div class="question"><div class="question-text">' . $questionNumber . '. ' . $esc($questionText) . $requiredMark . '</div><div class="rating-scale"><table class="rating-table"><tr>';
        foreach ($form['ratings'] as $index => $scale) {
            $label = $index === 0 ? 'Rating: ' . (string) $scale['rating_label'] : ($index === count($form['ratings']) - 1 ? (string) $scale['rating_label'] : '');
            $html .= '<td width="' . $columnWidth . '%">' . $esc($label) . '</td>';
        }
        $html .= '</tr><tr>';
        foreach ($form['ratings'] as $scale) {
            $scaleValue = (string) $scale['rating_value'];
            $isSelected = $scaleValue === $rating;
            $html .= '<td width="' . $columnWidth . '%" class="' . ($isSelected ? 'selected-option' : '') . '">' . ($isSelected ? '&#9673; ' : '&#9675; ') . $esc($scaleValue) . '</td>';
        }
        $html .= '</tr></table></div></div>';
    }
    $html .= '</div>';
}
$html .= '<div class="comments"><h3>Comments</h3>';
foreach ($form['comments'] as $comment) $html .= '<p><strong>' . $esc($comment['field_title']) . '</strong><br>' . nl2br($esc($submission['comments'][$comment['id']] ?? '')) . '</p>';
$cert = $submission['certification'] ?? [];
$html .= '</div><div class="comments"><h3>Certification</h3><p>' . nl2br($esc($form['certification']['certification_text'] ?? '')) . '</p><p>Supervisor: ' . $esc($cert['supervisor_name'] ?? $user['name'] ?? '') . '<br>Date: ' . $esc($cert['signed_date'] ?? $submittedDate) . '</p>';
$signatureData = pdfSafeSignatureData((string) ($submission['signature_data'] ?? ''));
if ($signatureData !== '') {
    $html .= '<p><img class="logo" src="' . $esc($signatureData) . '" alt="Signature"></p>';
} elseif (!empty($submission['signature_data'])) {
    // PNG signatures cannot be decoded reliably by this PHP build without GD.
    $html .= '<p><em>Signature on file.</em></p>';
}
$html .= '</div>';

try {
    $mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'tempDir' => sys_get_temp_dir(), 'showImageErrors' => true]);
    $mpdf->SetTitle((string) $form['title']);
    $mpdf->WriteHTML($html);
    $mpdf->Output('evaluation-' . $studentId . '.pdf', 'D');
} catch (Throwable $exception) {
    http_response_code(500);
    echo 'PDF generation error: ' . htmlspecialchars($exception->getMessage());
}

/**
 * Keep unsupported or malformed uploaded PNG data out of mPDF.
 * JPEGs are passed through because mPDF can embed them without GD.
 */
function pdfSafeSignatureData(string $data): string
{
    if (!preg_match('/^data:image\/jpeg;base64,(.+)$/is', $data, $matches)) {
        return '';
    }

    $binary = base64_decode($matches[1], true);
    if ($binary === false || substr($binary, 0, 3) !== "\xFF\xD8\xFF") {
        return '';
    }

    return 'data:image/jpeg;base64,' . base64_encode($binary);
}
