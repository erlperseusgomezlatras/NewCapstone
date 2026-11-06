<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/HeadTeacherReportRepository.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';

final class HeadTeacherReportService
{
    public const JOURNALS_REQUIRED = HeadTeacherReportRepository::JOURNALS_REQUIRED;
    public const TARGET_HOURS = HeadTeacherReportRepository::TARGET_HOURS;

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= getPDO();
        $this->repository = new HeadTeacherReportRepository($this->pdo);
    }

    private HeadTeacherReportRepository $repository;

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
        return preg_replace('/\.00$/', '', practicumFormatHours($hours)) ?? practicumFormatHours($hours);
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

    public static function journalIcon(bool $submitted): string
    {
        if ($submitted) {
            return '<span class="inline-flex items-center justify-center rounded-full bg-emerald-100 px-2 py-1 text-sm font-black leading-none text-emerald-700" aria-label="Submitted">✓</span>';
        }

        return '<span class="inline-flex items-center justify-center rounded-full bg-rose-100 px-2 py-1 text-sm font-black leading-none text-rose-700" aria-label="Missing">✗</span>';
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
        for ($week = 1; $week <= self::JOURNALS_REQUIRED; $week++) {
            $row['j' . $week] = (int) ($row['j' . $week] ?? 0);
        }

        return $row;
    }
}
