<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/CoordinatorReportRepository.php';
require_once __DIR__ . '/../models/JournalModuleRepository.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';

final class CoordinatorReportService
{
    public const TARGET_HOURS = 180.0;
    public const JOURNALS_REQUIRED = 16;

    private CoordinatorReportRepository $repository;
    private SystemHolidayService $holidayService;

    public function __construct(?PDO $pdo = null)
    {
        $pdo ??= getPDO();
        new JournalModuleRepository($pdo);
        $this->repository = new CoordinatorReportRepository($pdo);
        $this->holidayService = new SystemHolidayService($pdo);
    }

    public function listSemesters(int $coordinatorUserId): array
    {
        return $coordinatorUserId > 0 ? $this->repository->listSemesters($coordinatorUserId) : [];
    }

    public function listPartneredSchools(int $coordinatorUserId, int $semesterId = 0): array
    {
        return $coordinatorUserId > 0 ? $this->repository->listPartneredSchools($coordinatorUserId, $semesterId) : [];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function fetchReportRows(
        int $coordinatorUserId,
        int $semesterId,
        int $schoolId,
        string $search,
        string $statusFilter,
        string $sortBy,
        int $page,
        int $pageSize = 10,
        string $sectionCode = ''
    ): array {
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $result = $this->repository->fetchReportRows(
            $coordinatorUserId,
            $semesterId,
            $schoolId,
            trim($search),
            strtolower(trim($statusFilter)),
            $sortBy,
            $pageSize,
            $offset,
            trim($sectionCode)
        );

        foreach ($result['rows'] as &$row) {
            $row = $this->enrichRow($row);
            $row['journal_flags'] = $this->repository->fetchJournalWeekFlags(
                (int) ($row['student_user_id'] ?? 0),
                (int) ($row['semester_id'] ?? 0)
            );
            $row['journal_week_scores'] = $this->repository->fetchJournalWeekScores(
                (int) ($row['student_user_id'] ?? 0),
                (int) ($row['semester_id'] ?? 0)
            );
            $row['assigned_schools'] = $this->repository->fetchStudentDeploymentSchools(
                (int) ($row['student_user_id'] ?? 0),
                (int) ($row['coordinator_user_id'] ?? $coordinatorUserId),
                (int) ($row['semester_id'] ?? 0)
            );
            foreach ($row['journal_flags'] as $week => $approved) {
                $row['j' . $week] = (int) $approved;
            }
        }
        unset($row);

        return $result;
    }

    public function getStudentReport(int $coordinatorUserId, int $studentUserId, int $semesterId, int $geofenceId): ?array
    {
        $student = $this->repository->verifyStudentAccess($coordinatorUserId, $studentUserId, $semesterId, $geofenceId);
        if ($student === null) {
            return null;
        }

        $aggregates = $this->repository->fetchStudentAggregates($studentUserId, $semesterId);
        $student = array_merge($student, $aggregates);
        $student = $this->enrichRow($student);

        $weeklyAttendance = $this->repository->fetchWeeklyAttendance(
            $studentUserId,
            $semesterId,
            (string) ($student['practicum_start_date'] ?? ''),
            (string) ($student['semester_start_date'] ?? ''),
            (string) ($student['semester_end_date'] ?? '')
        );

        $journalRows = $this->repository->fetchJournalRows($studentUserId, $semesterId);
        $checklistRows = $this->repository->fetchWeeklyChecklistScores($studentUserId, $semesterId);
        // The report card is semester-to-date. A weekly absent count here made
        // the value appear to reset when a new practicum week started.
        $totalAbsentDays = (int) ($student['days_absent'] ?? 0);

        $journalsRequired = self::JOURNALS_REQUIRED;
        $journalsDone = (int) ($student['journals_done'] ?? 0);
        $journalsDue = self::countJournalsDue(
            (string) ($student['practicum_start_date'] ?? ''),
            (string) ($student['semester_status'] ?? ''),
            (string) ($student['semester_end_date'] ?? '')
        );
        $missingJournals = max(0, $journalsDue - $journalsDone);

        return [
            'student' => $student,
            'weekly_attendance' => $weeklyAttendance,
            'journal_rows' => $journalRows,
            'checklist_rows' => $checklistRows,
            'journal_summary' => [
                'submitted' => $journalsDone,
                'missing' => $missingJournals,
                'due' => $journalsDue,
                'pending' => (int) ($student['journals_pending'] ?? 0),
                'approved' => (int) ($student['journals_approved'] ?? 0),
                'declined' => (int) ($student['journals_declined'] ?? 0),
                'required' => $journalsRequired,
            ],
            'checklist_summary' => [
                'total_items' => (int) ($student['checklist_total'] ?? 0),
                'completed' => (int) ($student['checklist_done'] ?? 0),
                'average_score' => (int) ($student['checklist_score'] ?? 0),
                'overall_percent' => (int) ($student['checklist_percent'] ?? 0),
            ],
            'summary' => [
                'days_absent' => $totalAbsentDays,
            ],
            'progress' => [
                'attendance' => [
                    'current' => (int) ($student['days_present'] ?? 0),
                    'target' => (int) ($student['target_days'] ?? 0),
                ],
                'hours' => [
                    'current' => (float) ($student['rendered_hours'] ?? 0),
                    'target' => self::TARGET_HOURS,
                ],
                'journals' => [
                    'current' => $journalsDone,
                    'target' => $journalsRequired,
                ],
                'checklist' => [
                    'percent' => (int) ($student['checklist_percent'] ?? 0),
                ],
            ],
        ];
    }

    public static function fullName(array $row): string
    {
        $name = trim((string) (($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')));

        return $name !== ',' && $name !== '' ? $name : 'Unknown Student';
    }

    public static function statusBadge(string $status): string
    {
        return match ($status) {
            'Completed' => coordinatorTopBadge($status, 'green'),
            'Critical' => coordinatorTopBadge($status, 'red'),
            'Absent' => coordinatorTopBadge($status, 'red'),
            default => coordinatorTopBadge($status, 'blue'),
        };
    }

    public static function weekdaysBetween(string $start, string $end): int
    {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $end);
        if ($startDate === false || $endDate === false || $endDate < $startDate) {
            return 0;
        }

        $count = 0;
        for ($cursor = $startDate; $cursor <= $endDate; $cursor = $cursor->modify('+1 day')) {
            if ((int) $cursor->format('N') <= 5) {
                $count++;
            }
        }

        return $count;
    }

    public static function practicumStatusLabel(array $row): string
    {
        $status = practicumStatus((string) ($row['practicum_start_date'] ?? ''));

        return practicumStatusLabel($status);
    }

    public static function countJournalsDue(
        ?string $practicumStartDate,
        string $semesterStatus = '',
        ?string $semesterEndDate = null,
        ?string $today = null
    ): int {
        $today = $today ?? practicumToday();
        if (in_array($semesterStatus, ['closed', 'archived'], true)) {
            $endDate = trim((string) $semesterEndDate);
            if ($endDate !== '') {
                $today = $endDate;
            }
        }

        $effectiveStart = practicumEffectiveStart($practicumStartDate);
        if ($effectiveStart === null || $today < $effectiveStart) {
            return 0;
        }

        $weeks = practicumBuildWeeksFromStart($practicumStartDate, $today);
        if ($weeks === []) {
            return 0;
        }

        $due = 0;
        foreach ($weeks as $week) {
            $status = (string) ($week['status'] ?? practicumWeekStatus(
                (string) ($week['start'] ?? ''),
                (string) ($week['end'] ?? ''),
                $today
            ));
            if ($status === 'completed') {
                $due++;
            }
        }

        return min($due, self::JOURNALS_REQUIRED);
    }

    private function enrichRow(array $row): array
    {
        $semesterStart = practicumEffectiveStart((string) ($row['practicum_start_date'] ?? ''))
            ?: (string) ($row['semester_start_date'] ?? '');
        $semesterEnd = (string) ($row['semester_end_date'] ?? '');
        $today = practicumToday();
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $rangeEnd = $semesterEnd !== '' && $semesterEnd < $yesterday ? $semesterEnd : $yesterday;

        $holidayDates = $semesterStart !== '' && $rangeEnd >= $semesterStart
            ? $this->holidayService->listBetween($semesterStart, $rangeEnd)
            : [];
        $targetDays = max(0, self::weekdaysBetween($semesterStart, $rangeEnd) - count($holidayDates));
        $daysPresent = (int) ($row['days_present'] ?? 0) + count($holidayDates);
        $row['target_days'] = $targetDays;
        $row['days_absent'] = max(0, $targetDays - $daysPresent);
        $today = practicumToday();
        $currentWeekStart = practicumWeekStart($today);
        $currentWeekEnd = (new DateTimeImmutable($currentWeekStart))->modify('+4 days')->format('Y-m-d');
        $currentHolidayStart = max($currentWeekStart, $semesterStart);
        $currentHolidayEnd = min($today, $currentWeekEnd, $semesterEnd !== '' ? $semesterEnd : $currentWeekEnd);
        $currentWeekHolidays = $currentHolidayEnd >= $currentHolidayStart
            ? $this->holidayService->listBetween($currentHolidayStart, $currentHolidayEnd)
            : [];
        $currentWeekTargetDays = max(0, $this->currentWeekTargetDays() - count($currentWeekHolidays));
        $currentWeekPresentDays = (int) ($row['current_week_present_days'] ?? 0) + count($currentWeekHolidays);
        $row['current_week_target_days'] = $currentWeekTargetDays;
        $row['current_week_absent_days'] = max(0, $currentWeekTargetDays - $currentWeekPresentDays);
        $row['attendance_percent'] = $targetDays > 0
            ? min(100, round(($daysPresent / $targetDays) * 100, 0))
            : 0;
        $row['hours_percent'] = self::TARGET_HOURS > 0
            ? min(100, round(((float) ($row['rendered_hours'] ?? 0) / self::TARGET_HOURS) * 100, 0))
            : 0;
        $row['completion_status'] = (string) ($row['completion_status'] ?? 'Ongoing');
        $row['evaluation_average'] = $row['evaluation_average'] !== null
            ? number_format((float) $row['evaluation_average'], 2, '.', '')
            : null;
        $row['checklist_average'] = ($row['checklist_average'] ?? null) !== null
            ? number_format((float) ($row['checklist_average'] ?? 0), 2, '.', '')
            : null;

        $weekStatusMap = [];
        $weekStartDate = trim((string) ($row['practicum_start_date'] ?? ''));
        if ($weekStartDate === '') {
            $weekStartDate = trim((string) ($row['semester_start_date'] ?? ''));
        }
        foreach (practicumBuildWeeksFromStart($weekStartDate, $today, self::JOURNALS_REQUIRED) as $week) {
            $weekIndex = (int) ($week['index'] ?? 0);
            if ($weekIndex > 0) {
                $weekStatusMap[$weekIndex] = (string) ($week['status'] ?? 'past');
            }
        }
        $row['journal_week_statuses'] = $weekStatusMap;

        return $row;
    }

    private function currentWeekTargetDays(): int
    {
        $today = practicumToday();
        $weekStart = practicumWeekStart($today);
        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
        $currentEnd = $today < $weekEnd ? $today : $weekEnd;

        $count = 0;
        for ($cursor = new DateTimeImmutable($weekStart); $cursor <= new DateTimeImmutable($currentEnd); $cursor = $cursor->modify('+1 day')) {
            if ((int) $cursor->format('N') <= 5) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Return the absent-day count for the currently active week.
     * If no active week exists, fall back to the most recent non-upcoming week.
     */
    private function getCurrentWeeklyAbsentDays(array $weeklyAttendance): int
    {
        $fallback = null;

        foreach ($weeklyAttendance as $week) {
            $status = (string) ($week['status'] ?? '');
            if ($status === 'active') {
                return (int) ($week['absent_days'] ?? 0);
            }

            if ($status !== 'upcoming') {
                $fallback = $week;
            }
        }

        return $fallback !== null ? (int) ($fallback['absent_days'] ?? 0) : 0;
    }
}
