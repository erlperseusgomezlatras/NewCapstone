<?php
declare(strict_types=1);
require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/HeadTeacherEvaluationService.php';
require_once __DIR__ . '/../vendor/autoload.php';

$service = new HeadTeacherEvaluationService(getPDO());
$submission = $service->submittedEvaluation((int) ($_GET['submission_id'] ?? 0));
if (!$submission) { http_response_code(404); exit('Submitted evaluation not found.'); }
$form = $submission['form'];
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$logoPath = realpath(__DIR__ . '/../assets/images/logo_college_pdf.png');
$logoUrl = $logoPath !== false ? 'file:///' . str_replace('\\', '/', ltrim($logoPath, '\\/')) : '';
$instructions = trim((string) ($form['instructions'] ?? ''));
$instructionsBlock = $instructions !== '' ? '<div class="instructions"><strong>Instructions</strong><br>' . nl2br($esc($instructions)) . '</div>' : '';
$submittedTimestamp = strtotime((string) ($submission['submitted_at'] ?? ''));
$submittedDate = $submittedTimestamp !== false ? date('Y-m-d', $submittedTimestamp) : 'Not set';
$html = '<style>body{font-family:dejavusans;font-size:10.5pt;line-height:1.45;color:#1e293b}.header{text-align:center;border-bottom:1px solid #cbd5e1;padding-bottom:14px}.header h1{font-size:18pt;margin:10px 0 0;color:#0f172a}.instructions{margin:16px 0;padding:11px 13px;background:#f8fafc;border:1px solid #e2e8f0}.logo{height:64px;width:64px}.meta{margin:18px 0;padding:11px 13px;background:#f8fafc;border:1px solid #e2e8f0}.section{margin-top:20px}.section h3,.comments h3{font-size:13pt;color:#0f172a;border-bottom:1px solid #94a3b8;padding-bottom:6px;margin-bottom:10px}.question{margin:13px 0 16px;page-break-inside:avoid}.question-text{font-weight:bold;color:#0f172a}.rating-scale{margin-top:6px;color:#475569;font-size:9.5pt}.rating-table{width:72%;border-collapse:collapse}.rating-table td{padding:1px 4px;text-align:center;white-space:nowrap}.rating-table td:first-child{text-align:left}.rating-table td:last-child{text-align:right}.selected-option{font-weight:bold;color:#047857}.comments{margin-top:20px}.box{border:1px solid #e2e8f0;padding:10px;margin-top:12px}</style><div class="header">' . ($logoUrl !== '' ? '<img class="logo" src="' . $esc($logoUrl) . '"><br>' : '') . '<strong>' . $esc($form['institution_name']) . '</strong><br><strong>' . $esc($form['department_name']) . '</strong><h1>' . $esc($form['title']) . '</h1></div><div class="box"><strong>Student:</strong> ' . $esc($submission['student_name']) . ' &nbsp; <strong>Student ID:</strong> ' . $esc($submission['school_id']) . '<br><strong>Section:</strong> ' . $esc($submission['section_name'] ?: 'Not set') . ' &nbsp; <strong>Partnered School:</strong> ' . $esc($submission['partnered_school'] ?: 'Not set') . '<br><strong>Supervisor:</strong> ' . $esc($submission['supervisor_name'] ?: 'Not set') . ' &nbsp; <strong>Submitted:</strong> ' . $esc($submission['submitted_at']) . '</div>' . $instructionsBlock;
$html = str_replace($esc($submission['submitted_at']), $esc($submittedDate), $html);
$html = str_replace('rating-table{width:72%;border-collapse:collapse}', 'rating-table{width:72%;table-layout:fixed;border-collapse:collapse}', $html);
foreach ($form['sections'] as $section) {
    $html .= '<div class="section"><h3>' . $esc($section['section_title']) . '</h3>';
    foreach ($section['questions'] as $question) {
        $answer = (string) ($submission['answers'][$question['id']] ?? '');
        $requiredMark = !empty($question['is_required']) ? ' *' : '';
        $columnWidth = 100 / max(1, count($form['ratings']));
        $html .= '<div class="question"><div class="question-text">' . $esc($question['question_text']) . $requiredMark . '</div><div class="rating-scale"><table class="rating-table"><tr>';
        foreach ($form['ratings'] as $index => $rating) {
            $label = $index === 0 ? 'Rating: ' . (string) $rating['rating_label'] : ($index === count($form['ratings']) - 1 ? (string) $rating['rating_label'] : '');
            $html .= '<td width="' . $columnWidth . '%">' . $esc($label) . '</td>';
        }
        $html .= '</tr><tr>';
        foreach ($form['ratings'] as $rating) {
            $value = (string) $rating['rating_value'];
            $selected = $value === $answer;
            $html .= '<td width="' . $columnWidth . '%" class="' . ($selected ? 'selected-option' : '') . '">' . ($selected ? '&#9673;' : '&#9675;') . ' ' . $esc($value) . '</td>';
        }
        $html .= '</tr></table></div></div>';
    }
    $html .= '</div>';
}
$html .= '<div class="comments"><h3>Comments</h3>';
foreach ($form['comments'] as $comment) $html .= '<p><strong>' . $esc($comment['field_title']) . '</strong><br>' . nl2br($esc($submission['comments'][$comment['id']] ?? 'No comment provided.')) . '</p>';
$cert = $submission['certification'] ?? [];
$html .= '</div><div class="comments"><h3>Certification</h3><p>' . nl2br($esc($form['certification']['certification_text'] ?? '')) . '</p><p>Supervisor: ' . $esc($cert['supervisor_name'] ?? $submission['supervisor_name'] ?? '') . '<br>Position: ' . $esc($cert['supervisor_position'] ?? $submission['supervisor_position'] ?? '') . '<br>Date: ' . $esc($cert['signed_date'] ?? $submittedDate) . '</p>';
$signatureData = pdfSafeSignatureData((string) ($submission['signature_data'] ?? ''));
$html .= $signatureData !== '' ? '<p><img class="logo" src="' . $esc($signatureData) . '" alt="Supervisor signature"></p>' : (!empty($submission['signature_data']) ? '<p><em>Signature on file.</em></p>' : '');
$html .= '</div>';

try {
    $mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'tempDir' => sys_get_temp_dir(), 'showImageErrors' => true]);
    $mpdf->SetTitle((string) $form['title']);
    $mpdf->WriteHTML($html);
    $mpdf->Output('evaluation-' . (int) $submission['student_id'] . '.pdf', 'D');
} catch (Throwable $exception) {
    http_response_code(500);
    echo 'PDF generation error: ' . htmlspecialchars($exception->getMessage());
}

function pdfSafeSignatureData(string $data): string
{
    if (!preg_match('/^data:image\/jpeg;base64,(.+)$/is', $data, $matches)) return '';
    $binary = base64_decode($matches[1], true);
    if ($binary === false || substr($binary, 0, 3) !== "\xFF\xD8\xFF") return '';
    return 'data:image/jpeg;base64,' . base64_encode($binary);
}
