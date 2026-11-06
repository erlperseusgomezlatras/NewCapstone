<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/HeadTeacherReportRepository.php';
require_once __DIR__ . '/../models/JournalModuleRepository.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';

final class HeadTeacherReportService
{
    public const JOURNALS_REQUIRED = HeadTeacherReportRepository::JOURNALS_REQUIRED;
    public const TARGET_HOURS = HeadTeacherReportRepository::TARGET_HOURS;

    private HeadTeacherReportRepository $repository;

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= getPDO();
        new JournalModuleRepository($this->pdo);
        $this->repository = new HeadTeacherReportRepository($this->pdo);
    }

    public function getDefaultContext(): ?array
    {
        return $this->repository->getDefaultSemesterContext();
    }

    public function resolveContext(int $schoolYearId, int $semesterNo): ?array
    {
        return $this->repository->getSemesterBySelection($schoolYearId, $semesterNo);
    }

    public function listSchoolYears(): array
    {
        return $this->repository->listSchoolYears();
    }

    public function listPartneredSchools(): array
    {
        return $this->repository->listPartneredSchools();
    }

    public function listSections(int $semesterId): array
    {
        return $this->repository->listSections($semesterId);
    }

    /**
     * @return array{context: array<string, mixed>|null, rows: array<int, array<string, mixed>>}
     */
    public function fetchReport(array $filters): array
    {
        $schoolYearId = max(0, (int) ($filters['school_year_id'] ?? 0));
        $semesterNo = max(0, (int) ($filters['semester_no'] ?? 0));
        $viewBy = strtolower(trim((string) ($filters['view_by'] ?? 'partnered_school')));
        $schoolId = max(0, (int) ($filters['school_id'] ?? 0));
        $sectionCode = trim((string) ($filters['section_code'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        $context = $this->resolveContext($schoolYearId, $semesterNo);
        if ($context === null) {
            $context = $this->getDefaultContext();
        }

        if ($context === null) {
            return ['context' => null, 'rows' => []];
        }

        // An active semester is setup time, not proof that practicum has
        // begun. Never create attendance/journal compliance expectations
        // until practicum is actually ongoing; completed semesters remain
        // reportable as historical records.
        $practicumLifecycle = strtolower(trim((string) ($context['practicum_status'] ?? '')));
        if (!in_array($practicumLifecycle, ['ongoing', 'completed', 'ended'], true)) {
            return ['context' => $context, 'rows' => []];
        }

        $rows = $this->repository->fetchReportRows(
            (int) ($context['semester_id'] ?? 0),
            $viewBy,
            $schoolId,
            $sectionCode,
            $search
        );

        foreach ($rows as &$row) {
            $row = $this->normalizeRow($row);
        }
        unset($row);

        return [
            'context' => $context,
            'rows' => $rows,
        ];
    }

    public static function fullName(array $row): string
    {
        $name = trim((string) (($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')));

        return $name !== ',' && $name !== '' ? $name : 'Unknown Student';
    }

    public static function formatHours(float $hours): string
    {
        $formatted = practicumFormatHours($hours);
        return preg_replace('/\.00$/', '', $formatted) ?? $formatted;
    }

    public static function formatLiveHours(float $hours): string
    {
        return preg_replace('/\.00$/', '', practicumFormatHours($hours)) ?? practicumFormatHours($hours);
    }

    public static function formatCompliance(float $percent): string
    {
        $formatted = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    public static function safeFilename(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? 'report';
        $value = trim($value, '-_');

        return $value !== '' ? $value : 'report';
    }

    private function normalizeRow(array $row): array
    {
        $row['student_name'] = self::fullName($row);
        $row['compliance_percent'] = (float) ($row['compliance_percent'] ?? 0);
        $row['compliance_label'] = self::formatCompliance($row['compliance_percent']);
        $row['rendered_hours_label'] = self::formatHours((float) ($row['rendered_hours'] ?? 0));
        $row['rendered_hours_live_label'] = self::formatLiveHours((float) ($row['rendered_hours'] ?? 0));
        $row['present_days'] = (int) ($row['present_days'] ?? 0);
        $row['journals_done'] = (int) ($row['journals_done'] ?? 0);
        $row['evaluation_average'] = $row['evaluation_average'] !== null
            ? number_format((float) $row['evaluation_average'], 2, '.', '')
            : null;
        $row['checklist_average'] = $row['checklist_average'] !== null
            ? number_format((float) $row['checklist_average'], 2, '.', '')
            : null;

        $defaultMaximum = max(1, (int) ($row['journal_default_max_points'] ?? 50));
        for ($week = 1; $week <= self::JOURNALS_REQUIRED; $week++) {
            $row['j' . $week] = (int) ($row['j' . $week] ?? 0);
            $row['jp' . $week] = max(0, (int) ($row['jp' . $week] ?? 0));
            $row['jm' . $week] = max(1, (int) ($row['jm' . $week] ?? $defaultMaximum));
        }

        return $row;
    }
}
