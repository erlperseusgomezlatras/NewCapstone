<?php

declare(strict_types=1);

require_once __DIR__ . '/PracticumWeekService.php';
require_once __DIR__ . '/SystemHolidayService.php';
require_once __DIR__ . '/../models/JournalModuleRepository.php';

final class StudentRecordsService
{
    public function __construct(private PDO $pdo)
    {
        new JournalModuleRepository($pdo);
        $this->holidayService = new SystemHolidayService($pdo);
    }

    private SystemHolidayService $holidayService;

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name
             LIMIT 1'
        );
        $stmt->execute(['table_name' => $table]);
        return $stmt->fetchColumn() !== false;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return $stmt->fetchColumn() !== false;
    }

    public function getSemesterRecords(int $studentId, int $semesterId): array
    {
        if ($studentId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('A valid student and semester are required.');
        }

        $semesterStmt = $this->pdo->prepare(
            "SELECT sem.id, sem.semester_no, sem.semester_name, sem.start_date,
                    sem.end_date, sem.practicum_start_date, sem.practicum_status,
                    sy.year_label
             FROM semesters sem
             JOIN semester_students ss
               ON ss.semester_id = sem.id
              AND ss.student_user_id = :student_id
             LEFT JOIN school_years sy ON sy.id = sem.school_year_id
             WHERE sem.id = :semester_id
             LIMIT 1"
        );
        $semesterStmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId,
        ]);
        $semester = $semesterStmt->fetch(PDO::FETCH_ASSOC);
        if ($semester === false) {
            throw new InvalidArgumentException('No semester record was found for this student.');
        }

        $schoolId = 0;
        if ($this->tableExists('student_school_deployments')) {
            $schoolStmt = $this->pdo->prepare(
                "SELECT geofence_id
                 FROM student_school_deployments
                 WHERE student_user_id = :student_id
                   AND semester_id = :semester_id
                   AND deployment_status = 'active'
                 ORDER BY deployment_order
                 LIMIT 1"
            );
            $schoolStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
            $schoolId = (int) ($schoolStmt->fetchColumn() ?: 0);
        }
        if ($schoolId <= 0 && $this->tableExists('student_geofence_assignments')) {
            $schoolStmt = $this->pdo->prepare(
                "SELECT geofence_id
                 FROM student_geofence_assignments
                 WHERE student_user_id = :student_id
                   AND semester_id = :semester_id
                   AND assignment_status = 'active'
                 ORDER BY assigned_at DESC
                 LIMIT 1"
            );
            $schoolStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
            $schoolId = (int) ($schoolStmt->fetchColumn() ?: 0);
        }

        $startDate = trim((string) ($semester['practicum_start_date'] ?? ''));
        if ($startDate === '') {
            $startDate = (string) ($semester['start_date'] ?? '');
        }
        $today = practicumToday();
        $weeks = $startDate !== '' ? practicumBuildWeeks($startDate, (string) ($semester['end_date'] ?? ''), $today) : [];
        $holidayDates = [];
        if ($weeks !== []) {
            $holidayDates = $this->holidayService->listBetween(
                (string) $weeks[0]['start'],
                (string) end($weeks)['end'],
                $schoolId > 0 ? $schoolId : null
            );
        }

        $attendanceStmt = $this->pdo->prepare(
            'SELECT attendance_date, time_in, time_out, total_hours
             FROM attendance_records
             WHERE student_user_id = :student_id AND semester_id = :semester_id
             ORDER BY attendance_date ASC'
        );
        $attendanceStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
        $attendanceByDate = [];
        foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attendanceByDate[(string) $row['attendance_date']] = $row;
        }

        $journalByWeek = [];
        if ($this->tableExists('journal_submissions')) {
            $journalStmt = $this->pdo->prepare(
                'SELECT week_start, submitted_at, status, points_awarded, max_points
                 FROM journal_submissions
                 WHERE student_user_id = :student_id AND semester_id = :semester_id
                 ORDER BY submitted_at DESC'
            );
            $journalStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
            foreach ($journalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $weekStart = (string) $row['week_start'];
                if (!isset($journalByWeek[$weekStart])) {
                    $journalByWeek[$weekStart] = $row;
                }
            }
        }
        if ($journalByWeek === [] && $this->tableExists('student_journals')) {
            $journalStmt = $this->pdo->prepare(
                'SELECT week_start, submitted_at, status, 0 AS points_awarded, 50 AS max_points
                 FROM student_journals
                 WHERE student_user_id = :student_id AND semester_id = :semester_id
                 ORDER BY submitted_at DESC'
            );
            $journalStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
            foreach ($journalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $weekStart = (string) $row['week_start'];
                if (!isset($journalByWeek[$weekStart])) {
                    $journalByWeek[$weekStart] = $row;
                }
            }
        }

        $checklistByWeek = [];
        if ($this->tableExists('student_weekly_checklist')) {
            $checklistStmt = $this->pdo->prepare(
                'SELECT week_start, COALESCE(SUM(awarded_points), 0) AS points_earned,
                        COUNT(*) AS item_count
                 FROM student_weekly_checklist
                 WHERE student_user_id = :student_id AND semester_id = :semester_id
                 GROUP BY week_start'
            );
            $checklistStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
            foreach ($checklistStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $checklistByWeek[(string) $row['week_start']] = $row;
            }
        }

        $maximumChecklistPoints = 0;
        if ($this->tableExists('evaluation_checklist_items')) {
            $semesterCondition = $this->columnExists('evaluation_checklist_items', 'semester_id')
                ? ' AND (semester_id = :semester_id OR semester_id IS NULL)'
                : '';
            $maximumStmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(points), 0)
                 FROM evaluation_checklist_items
                 WHERE is_active = 1' . $semesterCondition
            );
            $maximumStmt->execute($semesterCondition !== '' ? ['semester_id' => $semesterId] : []);
            $maximumChecklistPoints = (int) $maximumStmt->fetchColumn();
        }

        $records = [];
        $totals = [
            'days' => ['M' => 0, 'T' => 0, 'W' => 0, 'TH' => 0, 'F' => 0],
            'journal_submitted' => 0,
            'journal_points' => 0,
            'journal_maximum' => 0,
            'checklist_points' => 0,
            'checklist_maximum' => 0,
            'credited_hours' => 0.0,
            'present_days' => 0,
            'expected_days' => 0,
        ];
        $effectiveStart = practicumEffectiveStart($startDate) ?? $startDate;
        $semesterEnd = (string) ($semester['end_date'] ?? '');

        foreach ($weeks as $week) {
            $weekStart = (string) ($week['start'] ?? '');
            $weekEnd = (string) ($week['end'] ?? '');
            $cursor = new DateTimeImmutable($weekStart, new DateTimeZone('Asia/Manila'));
            $dayKeys = ['M', 'T', 'W', 'TH', 'F'];
            $dayMarks = [];
            $weekHours = 0.0;
            $weekPresentDays = 0;
            $weekAbsentDays = 0;

            foreach ($dayKeys as $dayKey) {
                $date = $cursor->format('Y-m-d');
                $attendance = $attendanceByDate[$date] ?? null;
                $holiday = isset($holidayDates[$date]);
                $expected = $date >= $effectiveStart
                    && $date <= $today
                    && ($semesterEnd === '' || $date <= $semesterEnd);
                $present = $attendance !== null
                    && !empty($attendance['time_in'])
                    && (!empty($attendance['time_out']) || $date === $today);

                if ($holiday) {
                    $dayMarks[$dayKey] = 'holiday';
                    if ($expected) {
                        $weekPresentDays++;
                    }
                } elseif ($present) {
                    $dayMarks[$dayKey] = 'present';
                    $weekPresentDays++;
                    $weekHours += (float) ($attendance['total_hours'] ?? 0);
                } elseif ($expected) {
                    $dayMarks[$dayKey] = 'absent';
                    $weekAbsentDays++;
                } else {
                    $dayMarks[$dayKey] = 'future';
                }
                $cursor = $cursor->modify('+1 day');
            }

            $weekStatus = (string) ($week['status'] ?? 'upcoming');
            $weekJournal = $journalByWeek[$weekStart] ?? null;
            $weekChecklist = $checklistByWeek[$weekStart] ?? null;
            $journalSubmitted = $weekJournal !== null;
            $checklistDue = $weekStart <= $today && $maximumChecklistPoints > 0;
            $checklistDone = $weekChecklist !== null
                && (int) $weekChecklist['points_earned'] >= $maximumChecklistPoints;

            if ($weekStatus === 'upcoming') {
                $statusLabel = 'Not Yet Started';
            } elseif (($weekStart <= $today && !$journalSubmitted) || ($checklistDue && !$checklistDone)) {
                $statusLabel = 'Incomplete';
            } elseif ($weekAbsentDays > 0) {
                $statusLabel = 'With Absence';
            } elseif ($weekStatus === 'completed') {
                $statusLabel = 'Complete';
            } else {
                $statusLabel = 'In Progress';
            }

            $record = [
                'index' => (int) ($week['index'] ?? count($records) + 1),
                'start' => $weekStart,
                'end' => $weekEnd,
                'days' => $dayMarks,
                'journal_submitted' => $journalSubmitted,
                'journal_points' => $journalSubmitted ? (int) ($weekJournal['points_awarded'] ?? 0) : null,
                'journal_maximum' => $journalSubmitted ? max(1, (int) ($weekJournal['max_points'] ?? 50)) : null,
                'journal_status' => $journalSubmitted ? (string) ($weekJournal['status'] ?? 'pending') : '',
                'checklist_points' => $weekChecklist === null ? null : (int) $weekChecklist['points_earned'],
                'checklist_maximum' => $weekChecklist === null ? null : $maximumChecklistPoints,
                'credited_hours' => round($weekHours, 2),
                'present_days' => $weekPresentDays,
                'expected_days' => $weekPresentDays + $weekAbsentDays,
                'status' => $weekStatus,
                'status_label' => $statusLabel,
            ];
            $records[] = $record;

            foreach ($dayMarks as $dayKey => $mark) {
                if ($mark === 'present') {
                    $totals['days'][$dayKey]++;
                }
            }
            $totals['journal_submitted'] += $journalSubmitted ? 1 : 0;
            if ($journalSubmitted) {
                $totals['journal_points'] += (int) ($weekJournal['points_awarded'] ?? 0);
                $totals['journal_maximum'] += max(1, (int) ($weekJournal['max_points'] ?? 50));
            }
            if ($weekChecklist !== null) {
                $totals['checklist_points'] += (int) $weekChecklist['points_earned'];
                $totals['checklist_maximum'] += $maximumChecklistPoints;
            }
            $totals['credited_hours'] += $weekHours;
            $totals['present_days'] += $weekPresentDays;
            $totals['expected_days'] += $weekPresentDays + $weekAbsentDays;
        }

        $totals['credited_hours'] = round((float) $totals['credited_hours'], 2);
        $totals['attendance_percent'] = (int) ($totals['expected_days'] > 0
            ? round(($totals['present_days'] / $totals['expected_days']) * 100)
            : 0);

        return [
            'semester' => [
                'id' => $semesterId,
                'semester_no' => (int) $semester['semester_no'],
                'semester_name' => (string) $semester['semester_name'],
                'year_label' => (string) ($semester['year_label'] ?? ''),
            ],
            'records' => $records,
            'totals' => $totals,
        ];
    }
}
