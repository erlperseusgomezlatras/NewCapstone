<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/HeadTeacherReportServiceClean.php';
require_once __DIR__ . '/../services/HeadTeacherReportRendererClean.php';

date_default_timezone_set('Asia/Manila');

$user = currentUser();
$userRole = (string) ($user['role'] ?? '');
$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0 || $userRole !== 'head_teacher') {
    http_response_code(401);
    die('Unauthorized');
}

$reportService = new HeadTeacherReportService();
$report = $reportService->fetchReport([
    'school_year_id' => (int) ($_GET['school_year_id'] ?? 0),
    'semester_no' => (int) ($_GET['semester_no'] ?? 0),
    'view_by' => (string) ($_GET['view_by'] ?? 'partnered_school'),
    'school_id' => (int) ($_GET['school_id'] ?? 0),
    'section_code' => (string) ($_GET['section_code'] ?? ''),
    'search' => (string) ($_GET['search'] ?? ''),
]);

$context = $report['context'] ?? $reportService->getDefaultContext();
if ($context === null) {
    http_response_code(404);
    die('No semester available');
}

$html = headTeacherReportBuildDocumentHtml($context, $report['rows'] ?? [], 'Excel Export');
$filename = sprintf(
    'Student_Practicum_Reports_%s_%s.xls',
    HeadTeacherReportService::safeFilename((string) ($context['year_label'] ?? 'school-year')),
    HeadTeacherReportService::safeFilename((string) ($context['semester_name'] ?? 'semester'))
);

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
echo $html;
