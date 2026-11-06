<?php

declare(strict_types=1);

require_once __DIR__ . '/HeadTeacherReportService.php';

function headTeacherReportEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function headTeacherReportBuildRowsHtml(array $rows): string
{
    $html = '';
    for ($week = 1; $week <= HeadTeacherReportService::JOURNALS_REQUIRED; $week++) {
        $headerCells[] = '<th>J' . $week . '</th>';
    }

    foreach ($rows as $row) {
        $html .= '<tr>';
        $html .= '<td>' . headTeacherReportEscape($row['student_name'] ?? 'Unknown Student') . '</td>';
        for ($week = 1; $week <= HeadTeacherReportService::JOURNALS_REQUIRED; $week++) {
            $html .= '<td style="text-align:center;">' . HeadTeacherReportService::journalIcon((int) ($row['j' . $week] ?? 0) === 1) . '</td>';
        }
        $html .= '<td>' . headTeacherReportEscape((int) ($row['journals_done'] ?? 0) . ' / ' . HeadTeacherReportService::JOURNALS_REQUIRED . ' (' . ($row['compliance_label'] ?? '0') . '%)') . '</td>';
        $html .= '<td>' . headTeacherReportEscape((string) ($row['rendered_hours_live_label'] ?? $row['rendered_hours_label'] ?? '0')) . ' / 180 hrs</td>';
        $html .= '<td>' . headTeacherReportEscape((int) ($row['present_days'] ?? 0)) . '</td>';
        $html .= '</tr>';
    }

    return $html;
}

function headTeacherReportBuildDocumentHtml(array $context, array $rows, string $generatedLabel = 'Generated Report'): string
{
    $yearLabel = headTeacherReportEscape($context['year_label'] ?? 'N/A');
    $semesterName = headTeacherReportEscape($context['semester_name'] ?? 'Semester');
    $semesterStatus = headTeacherReportEscape(strtoupper((string) ($context['semester_status'] ?? 'UNKNOWN')));
    $generatedLabel = headTeacherReportEscape($generatedLabel);
    $rowsHtml = headTeacherReportBuildRowsHtml($rows);
    $columnWidths = '<col style="width:28%;">';
    for ($week = 1; $week <= HeadTeacherReportService::JOURNALS_REQUIRED; $week++) {
        $columnWidths .= '<col style="width:2.75%;">';
    }
    $columnWidths .= '<col style="width:11%;">';
    $columnWidths .= '<col style="width:11%;">';
    $columnWidths .= '<col style="width:6%;">';
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="' . (HeadTeacherReportService::JOURNALS_REQUIRED + 4) . '" style="text-align:center;">No students found for the selected filters.</td></tr>';
    }

    $headers = '<tr><th>Student Name</th>';
    for ($week = 1; $week <= HeadTeacherReportService::JOURNALS_REQUIRED; $week++) {
        $headers .= '<th>J' . $week . '</th>';
    }
    $headers .= '<th>Compliance</th><th>Rendered Hours</th><th>Present Days</th></tr>';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #1f2937; font-size: 9px; }
        .header { text-align: center; border-bottom: 3px solid #0f766e; padding-bottom: 10px; margin-bottom: 14px; }
        .header h1 { margin: 0; color: #0f766e; font-size: 18px; }
        .header p { margin: 3px 0 0; color: #6b7280; font-size: 10px; }
        .meta { margin: 10px 0 14px; font-size: 10px; color: #374151; }
        .pill { display: inline-block; padding: 4px 8px; margin-right: 6px; background: #ecfeff; color: #155e75; border-radius: 9999px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d1d5db; padding: 5px 6px; vertical-align: middle; overflow: hidden; }
        th { background: #f8fafc; font-size: 8px; text-transform: uppercase; letter-spacing: 0.04em; }
        td:first-child { font-weight: bold; word-break: break-word; }
        th:nth-child(n + 2):nth-child(-n + 17), td:nth-child(n + 2):nth-child(-n + 17) { text-align: center; }
        th:last-child, td:last-child { text-align: center; white-space: nowrap; }
        .center { text-align: center; }
        .footer { margin-top: 14px; text-align: center; color: #9ca3af; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Student Practicum Reports</h1>
        <p>{$generatedLabel}</p>
    </div>
    <div class="meta">
        <span class="pill">SY {$yearLabel}</span>
        <span class="pill">{$semesterName}</span>
        <span class="pill">{$semesterStatus}</span>
    </div>
    <table>
        <colgroup>{$columnWidths}</colgroup>
        <thead>{$headers}</thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
    <div class="footer">Head Teacher Semester Overview</div>
</body>
</html>
HTML;
}
