<?php

declare(strict_types=1);

require_once __DIR__ . '/HeadTeacherReportServiceClean.php';
require_once __DIR__ . '/PracticumWeekService.php';

function headTeacherReportEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function headTeacherReportBuildRowsHtml(
    array $rows,
    bool $includeSection = true,
    bool $includeStudentId = false,
    int $journalWeeksToDisplay = HeadTeacherReportService::JOURNALS_REQUIRED
): string
{
    $html = '';
    $today = practicumToday();
    foreach ($rows as $row) {
        $weekStatusMap = headTeacherReportWeekStatusMap($row, $today);
        $html .= '<tr>';
        $html .= '<td>' . headTeacherReportEscape($row['student_name'] ?? 'Unknown Student') . '</td>';
        if ($includeStudentId) {
            $html .= '<td>' . headTeacherReportEscape($row['student_number'] ?? $row['school_id'] ?? '') . '</td>';
        }
        $html .= '<td>' . headTeacherReportEscape($row['email'] ?? '') . '</td>';
        if ($includeSection) {
            $html .= '<td>' . headTeacherReportEscape($row['section_code'] ?? '') . '</td>';
        }
        $assignedSchools = $row['assigned_schools'] ?? [];
        if (is_array($assignedSchools) && $assignedSchools !== []) {
            $schoolLabels = [];
            foreach ($assignedSchools as $assignedSchool) {
                $schoolName = trim((string) ($assignedSchool['school_name'] ?? ''));
                if ($schoolName !== '') {
                    $schoolLabels[] = headTeacherReportEscape($schoolName);
                }
            }
            $html .= '<td>' . implode('<br>', $schoolLabels) . '</td>';
        } else {
            $html .= '<td>' . headTeacherReportEscape($row['partnered_school_name'] ?? '') . '</td>';
        }
        $journalsApprovedToDate = 0;
        for ($week = 1; $week <= $journalWeeksToDisplay; $week++) {
            $status = (string) ($weekStatusMap[$week] ?? 'upcoming');
            $submitted = (int) ($row['j' . $week] ?? 0) === 1;
            if ($submitted) {
                $journalsApprovedToDate++;
            }
            if ($status === 'upcoming') {
                $html .= '<td class="journal-week">&nbsp;</td>';
                continue;
            }

            $html .= '<td class="journal-week" style="font-weight:bold;color:#1f2937;">'
                . (int) ($row['jp' . $week] ?? 0) . ' / ' . (int) ($row['jm' . $week] ?? 50) . '</td>';
        }
        $journalCompliance = $journalWeeksToDisplay > 0
            ? $journalsApprovedToDate . ' / ' . $journalWeeksToDisplay . ' (' . round(($journalsApprovedToDate / $journalWeeksToDisplay) * 100) . '%)'
            : 'Not started';
        $html .= '<td>' . headTeacherReportEscape($journalCompliance) . '</td>';
        $html .= '<td>' . headTeacherReportEscape((string) ($row['rendered_hours_live_label'] ?? $row['rendered_hours_label'] ?? '0')) . ' / 180 hrs</td>';
        $html .= '<td>' . headTeacherReportEscape((int) ($row['present_days'] ?? 0)) . '</td>';
        $html .= '<td>' . headTeacherReportEscape($row['checklist_average'] !== null ? (string) $row['checklist_average'] : 'N/A') . '</td>';
        $html .= '<td>' . headTeacherReportEscape($row['evaluation_average'] !== null ? (string) $row['evaluation_average'] : 'N/A') . '</td>';
        $html .= '</tr>';
    }

    return $html;
}

function headTeacherReportBuildDocumentHtml(array $context, array $rows, string $generatedLabel = 'Generated Report', bool $includeSection = true, bool $includeStudentId = false): string
{
    $yearLabel = headTeacherReportEscape($context['year_label'] ?? 'N/A');
    $semesterName = headTeacherReportEscape($context['semester_name'] ?? 'Semester');
    $semesterStatus = headTeacherReportEscape(strtoupper((string) ($context['semester_status'] ?? 'UNKNOWN')));
    $generatedLabel = headTeacherReportEscape($generatedLabel);
    $practicumStartDate = trim((string) ($context['practicum_start_date'] ?? ''));
    if ($practicumStartDate === '') {
        $practicumStartDate = trim((string) ($context['semester_start_date'] ?? ''));
    }
    $journalWeeksToDisplay = practicumJournalWeeksToDisplay(
        $practicumStartDate,
        null,
        HeadTeacherReportService::JOURNALS_REQUIRED,
        (string) ($context['semester_end_date'] ?? ''),
        (string) ($context['practicum_status'] ?? $context['semester_status'] ?? '')
    );
    $rowsHtml = headTeacherReportBuildRowsHtml($rows, $includeSection, $includeStudentId, $journalWeeksToDisplay);
    $columnWidths = '<col style="width:15%;">';
    if ($includeStudentId) {
        $columnWidths .= '<col style="width:8%;">';
    }
    $columnWidths .= '<col style="width:17%;">';
    if ($includeSection) {
        $columnWidths .= '<col style="width:9%;">';
    }
    $columnWidths .= '<col style="width:16%;">';
    for ($week = 1; $week <= $journalWeeksToDisplay; $week++) {
        $columnWidths .= '<col style="width:2.5%;">';
    }
    $columnWidths .= '<col style="width:11%;">';
    $columnWidths .= '<col style="width:11%;">';
    $columnWidths .= '<col style="width:6%;">';
    $columnWidths .= '<col style="width:8%;">';
    $columnWidths .= '<col style="width:8%;">';

    if ($rowsHtml === '') {
        $columnCount = 8 + (int) $includeSection + (int) $includeStudentId + $journalWeeksToDisplay;
        $rowsHtml = '<tr><td colspan="' . $columnCount . '" style="text-align:center;">No students found for the selected filters.</td></tr>';
    }

    $headers = '<tr><th>Student Name</th>';
    if ($includeStudentId) {
        $headers .= '<th>Student ID</th>';
    }
    $headers .= '<th>Email Address</th>';
    if ($includeSection) {
        $headers .= '<th>Section</th>';
    }
    $headers .= '<th>Assigned School</th>';
    for ($week = 1; $week <= $journalWeeksToDisplay; $week++) {
        $headers .= '<th class="journal-week">J' . $week . ' Score</th>';
    }
    $headers .= '<th>Compliance</th><th>Rendered Hours</th><th>Present Days</th><th>Checklist Average</th><th>Evaluation Average</th></tr>';

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
        th { background: #f8fafc; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
        td:first-child { font-weight: bold; word-break: break-word; }
        .journal-week { text-align: center; }
        th:last-child, td:last-child { text-align: center; white-space: nowrap; }
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

function headTeacherReportWeekStatusMap(array $row, string $today): array
{
    $practicumStart = trim((string) ($row['practicum_start_date'] ?? ''));
    if ($practicumStart === '') {
        $practicumStart = trim((string) ($row['semester_start_date'] ?? ''));
    }

    if ($practicumStart === '') {
        return [];
    }

    $weeks = practicumBuildWeeksFromStart($practicumStart, $today, HeadTeacherReportService::JOURNALS_REQUIRED);
    $map = [];
    foreach ($weeks as $week) {
        $index = (int) ($week['index'] ?? 0);
        if ($index > 0) {
            $map[$index] = (string) ($week['status'] ?? 'past');
        }
    }

    return $map;
}
