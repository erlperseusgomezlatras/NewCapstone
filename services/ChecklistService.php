<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/ChecklistRepository.php';

final class ChecklistService
{
    private PDO $pdo;
    private ChecklistRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->repository = new ChecklistRepository($this->pdo);

        if (!$this->repository->tableExists('checklist_progress')) {
            $this->repository->createChecklistTable();
        }
        $this->repository->ensureWorkflowColumns();
    }

    public function initializeWeekChecklist(int $sectionId, string $week): int
    {
        if ($sectionId <= 0) {
            throw new InvalidArgumentException('Invalid section_id');
        }
        $week = $this->normalizeWeek($week);
        $today = new DateTimeImmutable('today');
        if ($week !== $today->modify('monday this week')->format('Y-m-d')) {
            throw new RuntimeException('Checklist can only be started for the current week.');
        }
        if ((int) $today->format('N') > 5) {
            throw new RuntimeException('Checklist can only be started Monday to Friday.');
        }

        $semesterId = $this->repository->getSectionSemesterId($sectionId);
        if ($semesterId === null) {
            throw new RuntimeException('Section not found');
        }

        $existingSession = $this->repository->getChecklistSession($sectionId, $semesterId, $week);
        if ($existingSession !== null) {
            throw new RuntimeException('Checklist has already been started for this week.');
        }

        return $this->repository->initializeWeekChecklist($sectionId, $semesterId, $week, $today->format('Y-m-d'));
    }

    public function updateChecklistItem(int $studentId, string $week, string $field, int $value): void
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Invalid student_id');
        }
        $allowedFields = ['orientation', 'uniform', 'observation', 'demo', 'portfolio'];
        if (!in_array($field, $allowedFields, true)) {
            throw new InvalidArgumentException('Invalid checklist field');
        }

        $week = $this->normalizeWeek($week);
        $row = $this->repository->getStudentChecklistRow($studentId, $week);
        if ($row === null) {
            throw new RuntimeException('Checklist has not been started for this week.');
        }

        if (!$this->isSessionOpen((string) ($row['checklist_date'] ?? ''), $row['closed_at'] ?? null)) {
            throw new RuntimeException('Checklist is already closed for this week.');
        }

        $this->repository->updateChecklistItem($studentId, $week, $field, $value === 1 ? 1 : 0);
    }

    public function getChecklistBySection(int $sectionId, string $week): array
    {
        if ($sectionId <= 0) {
            throw new InvalidArgumentException('Invalid section_id');
        }
        $week = $this->normalizeWeek($week);
        $semesterId = $this->repository->getSectionSemesterId($sectionId);
        if ($semesterId === null) {
            throw new RuntimeException('Section not found');
        }

        $this->syncChecklistWindow($sectionId, $semesterId, $week);
        $rows = $this->repository->getChecklistBySection($sectionId, $semesterId, $week);
        foreach ($rows as &$row) {
            $completed = (int) ($row['orientation'] ?? 0)
                + (int) ($row['uniform'] ?? 0)
                + (int) ($row['observation'] ?? 0)
                + (int) ($row['demo'] ?? 0)
                + (int) ($row['portfolio'] ?? 0);
            $row['completed_count'] = $completed;
            $row['status'] = $completed === 5 ? 'Completed' : ($completed > 0 ? 'In Progress' : 'Pending');
        }
        unset($row);

        return $rows;
    }

    public function getChecklistSessionState(int $sectionId, string $week): array
    {
        if ($sectionId <= 0) {
            throw new InvalidArgumentException('Invalid section_id');
        }
        $week = $this->normalizeWeek($week);
        $semesterId = $this->repository->getSectionSemesterId($sectionId);
        if ($semesterId === null) {
            throw new RuntimeException('Section not found');
        }

        $this->syncChecklistWindow($sectionId, $semesterId, $week);
        $session = $this->repository->getChecklistSession($sectionId, $semesterId, $week);
        if ($session === null) {
            return [
                'status' => 'not_started',
                'checklist_date' => null,
                'closed_at' => null,
            ];
        }

        $isOpen = $this->isSessionOpen((string) ($session['checklist_date'] ?? ''), $session['closed_at'] ?? null);

        return [
            'status' => $isOpen ? 'open' : 'closed',
            'checklist_date' => $session['checklist_date'] ?? null,
            'closed_at' => $session['closed_at'] ?? null,
        ];
    }

    private function syncChecklistWindow(int $sectionId, int $semesterId, string $week): void
    {
        $session = $this->repository->getChecklistSession($sectionId, $semesterId, $week);
        if ($session === null) {
            return;
        }

        $checklistDate = (string) ($session['checklist_date'] ?? '');
        if ($checklistDate === '' || !$this->isSessionOpen($checklistDate, $session['closed_at'] ?? null)) {
            if (($session['closed_at'] ?? null) === null && $checklistDate !== '') {
                $this->repository->closeChecklistSession($sectionId, $semesterId, $week);
            }
        }
    }

    private function isSessionOpen(string $checklistDate, mixed $closedAt): bool
    {
        if ($closedAt !== null && $closedAt !== '') {
            return false;
        }
        if ($checklistDate === '') {
            return false;
        }

        $today = new DateTimeImmutable('today');
        if ((int) $today->format('N') > 5) {
            return false;
        }

        return $checklistDate === $today->format('Y-m-d');
    }

    private function normalizeWeek(string $week): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $week);
        if ($date === false) {
            throw new InvalidArgumentException('Invalid week');
        }

        return $date->modify('monday this week')->format('Y-m-d');
    }
}
