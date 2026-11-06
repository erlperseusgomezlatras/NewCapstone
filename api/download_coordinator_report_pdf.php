<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/CoordinatorReportService.php';
require_once __DIR__ . '/../services/HeadTeacherReportServiceClean.php';
require_once __DIR__ . '/../services/HeadTeacherReportRendererClean.php';

$user = currentUser() ?? [];
if ((int)($user['id'] ?? 0) <= 0 || (string)($user['role'] ?? '') !== 'coordinator') { http_response_code(401); exit('Unauthorized'); }

$service = new CoordinatorReportService(getPDO());
$semesterId = max(0, (int)($_GET['semester_id'] ?? 0));
$search = trim((string)($_GET['search'] ?? ''));
$sectionCode = trim((string)($_GET['section_code'] ?? ''));
$rows = [];
for ($page = 1; $page <= 20; $page++) {
    $result = $service->fetchReportRows((int)$user['id'], $semesterId, 0, $search, '', 'name', $page, 50, $sectionCode);
    $rows = array_merge($rows, $result['rows']);
    if (count($result['rows']) < 50) break;
}
$renderRows = [];
foreach ($rows as $row) {
    $row['student_name'] = CoordinatorReportService::fullName($row);
    $row['student_number'] = (string)($row['school_id'] ?? '');
    $row['partnered_school_name'] = $row['school_name'] ?? '';
    $row['section_code'] = $row['section_code'] ?? '';
    $row['compliance_label'] = (string) round(((int)($row['journals_done'] ?? 0) / 16) * 100);
    $row['rendered_hours_live_label'] = number_format((float)($row['rendered_hours'] ?? 0), 2, '.', '');
    $row['present_days'] = (int)($row['days_present'] ?? 0);
    foreach (($row['journal_week_scores'] ?? []) as $week => $journalScore) {
        $row['jp' . (int) $week] = (int) ($journalScore['points_awarded'] ?? 0);
        $row['jm' . (int) $week] = max(1, (int) ($journalScore['max_points'] ?? 50));
    }
    $renderRows[] = $row;
}
$semesterContext = ['year_label'=>'N/A', 'semester_name'=>'Semester', 'semester_status'=>'UNKNOWN'];
foreach ($service->listSemesters((int)$user['id']) as $semester) {
    if ((int)($semester['id'] ?? 0) === $semesterId) {
        $semesterContext = [
            'year_label' => $semester['year_label'],
            'semester_name' => $semester['semester_name'],
            'semester_status' => $semester['semester_status'],
            'practicum_start_date' => $semester['practicum_start_date'] ?? '',
            'practicum_status' => $semester['practicum_status'] ?? '',
            'semester_start_date' => $semester['semester_start_date'] ?? '',
            'semester_end_date' => $semester['semester_end_date'] ?? '',
        ];
        break;
    }
}
$html = headTeacherReportBuildDocumentHtml($semesterContext, $renderRows, 'PDF Export', true, true);
$mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A3-L','margin_left'=>8,'margin_right'=>8,'margin_top'=>8,'margin_bottom'=>8]);
$mpdf->WriteHTML($html);
$mpdf->Output('Coordinator_Semester_Overview.pdf', 'D');
