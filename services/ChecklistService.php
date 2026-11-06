<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/ChecklistRepository.php';
require_once __DIR__ . '/PracticumWeekService.php';

final class ChecklistService
{
    private PDO $pdo;
    private ChecklistRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->repository = new ChecklistRepository($this->pdo);
        $this->repository->ensureChecklistItemsSchema();
        $this->repository->ensureChecklistSessionsSchema();
        $this->repository->ensureStudentWeeklyChecklistSchema();
        $this->repository->ensureChecklistStudentConfirmationsSchema();
    }

    public function getChecklistBuilderItems(?int $semesterId = null): array
    {
        return $this->repository->getAllChecklistItems($semesterId);
    }

    public function getActiveChecklistItems(): array
    {
        return $this->repository->getActiveChecklistItems();
    }

    public function getActiveChecklistItemsForSemester(int $semesterId): array
    {
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester_id');
        }

        return $this->repository->getActiveChecklistItems($semesterId);
    }

    public function createChecklistItem(string $itemName, int $points, int $createdBy, ?int $semesterId = null): void
    {
        $itemName = trim($itemName);
        if ($itemName === '') {
            throw new InvalidArgumentException('Checklist item name is required.');
        }
        if ($points <= 0) {
            throw new InvalidArgumentException('Checklist points must be at least 1.');
        }
        if ($createdBy <= 0) {
            throw new InvalidArgumentException('Invalid creator.');
        }

        $this->repository->createChecklistItem(
            $itemName,
            $points,
            max(1, $this->repository->getNextChecklistDisplayOrder($semesterId)),
            $createdBy,
            $semesterId
        );
    }

    public function updateChecklistDefinition(int $itemId, string $itemName, int $points, int $displayOrder, bool $isActive, ?int $semesterId = null): void
    {
        if ($itemId <= 0) {
            throw new InvalidArgumentException('Invalid checklist item.');
        }
        $itemName = trim($itemName);
        if ($itemName === '') {
            throw new InvalidArgumentException('Checklist item name is required.');
        }
        if ($points <= 0) {
            throw new InvalidArgumentException('Checklist points must be at least 1.');
        }

        $this->repository->updateChecklistItemDefinition(
            $itemId,
            $itemName,
            $points,
            max(0, $displayOrder),
            $isActive ? 1 : 0,
            $semesterId
        );
    }

    public function deleteChecklistItem(int $itemId, ?int $semesterId = null): void
    {
        if ($itemId <= 0) {
            throw new InvalidArgumentException('Invalid checklist item.');
        }

        $this->repository->deleteChecklistItem($itemId, $semesterId);
    }

    public function initializeWeekChecklist(int $sectionId, string $week, int $checkedBy): int
    {
        if ($sectionId <= 0) {
            throw new InvalidArgumentException('Invalid section_id');
        }
        if ($checkedBy <= 0) {
            throw new InvalidArgumentException('Invalid coordinator.');
        }

        $week = $this->normalizeWeek($week);
        $semesterId = $this->repository->getSectionSemesterId($sectionId);
        if ($semesterId === null) {
            throw new RuntimeException('Section not found.');
        }
        $today = new DateTimeImmutable('today');
        if ($week !== $this->activeWeekStartForSemester($semesterId)) {
            throw new RuntimeException('Checklist can only be started for the active practicum week.');
        }
        if ((int) $today->format('N') > 5) {
            throw new RuntimeException('Checklist can only be started Monday to Friday.');
        }

        if ($this->repository->getActiveChecklistItems($semesterId) === []) {
            throw new RuntimeException('No active checklist items found. Ask the Head Teacher to add checklist criteria first.');
        }

        $existingSession = $this->repository->getChecklistSession($sectionId, $semesterId, $week);
        if ($existingSession !== null) {
            throw new RuntimeException('Checklist has already been started for this week.');
        }

        $this->repository->createChecklistSession($sectionId, $semesterId, $week, $today->format('Y-m-d'));

        return $this->repository->initializeWeekChecklistRows($sectionId, $semesterId, $week, $checkedBy);
    }

    public function updateChecklistItem(int $studentId, int $sectionId, string $week, int $checklistItemId, int $value, int $checkedBy): void
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Invalid student_id');
        }
        if ($sectionId <= 0) {
            throw new InvalidArgumentException('Invalid section_id');
        }
        if ($checklistItemId <= 0) {
            throw new InvalidArgumentException('Invalid checklist item.');
        }
        if ($checkedBy <= 0) {
            throw new InvalidArgumentException('Invalid coordinator.');
        }

        $week = $this->normalizeWeek($week);
        $semesterId = $this->repository->getSectionSemesterId($sectionId);
        if ($semesterId === null) {
            throw new RuntimeException('Section not found.');
        }

        $row = $this->repository->getStudentChecklistItemRow($studentId, $sectionId, $semesterId, $week, $checklistItemId);
        if ($row === null) {
            throw new RuntimeException('Checklist has not been started for this week.');
        }

        if (!$this->isSessionOpen((string) ($row['checklist_date'] ?? ''), $row['closed_at'] ?? null)) {
            throw new RuntimeException('Checklist is already closed for this week.');
        }
        $item = $this->repository->getChecklistItem($checklistItemId);
        if ($item === null || (int) ($item['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Checklist item is no longer active.');
        }

        $isChecked = $value === 1 ? 1 : 0;
        $awardedPoints = $isChecked === 1 ? (int) ($item['points'] ?? 0) : 0;

        $this->repository->upsertStudentChecklistItem(
            $studentId,
            (int) ($row['section_id'] ?? 0),
            (int) ($row['semester_id'] ?? 0),
            $week,
            $checklistItemId,
            $isChecked,
            $awardedPoints,
            $checkedBy
        );
    }

    public function getChecklistBySection(int $sectionId, string $week, ?int $actorUserId = null): array
    {
        if ($sectionId <= 0) {
            throw new InvalidArgumentException('Invalid section_id');
        }
        $week = $this->normalizeWeek($week);
        $semesterId = $this->repository->getSectionSemesterId($sectionId);
        if ($semesterId === null) {
            throw new RuntimeException('Section not found.');
        }

        $this->syncChecklistWindow($sectionId, $semesterId, $week);
        if ($actorUserId !== null && $actorUserId > 0 && $this->repository->getChecklistSession($sectionId, $semesterId, $week) !== null) {
            $this->repository->syncNewActiveItemsIntoWeek($sectionId, $semesterId, $week, $actorUserId);
        }

        $rows = $this->repository->getChecklistBySection($sectionId, $semesterId, $week);
        $items = $this->repository->getActiveChecklistItems($semesterId);
        $itemCount = count($items);

        foreach ($rows as &$row) {
            $checkedCount = (int) ($row['checked_count'] ?? 0);
            $row['status'] = $itemCount > 0 && $checkedCount === $itemCount
                ? 'Completed'
                : ($checkedCount > 0 ? 'In Progress' : 'Pending');
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
            throw new RuntimeException('Section not found.');
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

    public function initializeWeekChecklistForSchool(int $geofenceId, int $semesterId, string $week, int $checkedBy): int
    {
        if ($geofenceId <= 0) {
            throw new InvalidArgumentException('Invalid geofence_id');
        }
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester_id');
        }
        if ($checkedBy <= 0) {
            throw new InvalidArgumentException('Invalid coordinator.');
        }

        $week = $this->normalizeWeek($week);
        $today = new DateTimeImmutable('today');
        if ($week !== $this->activeWeekStartForSemester($semesterId)) {
            throw new RuntimeException('Checklist can only be started for the active practicum week.');
        }
        if ((int) $today->format('N') > 5) {
            throw new RuntimeException('Checklist can only be started Monday to Friday.');
        }

        if ($this->repository->getActiveChecklistItems($semesterId) === []) {
            throw new RuntimeException('No active checklist items found for the active semester. Ask the Head Teacher to add checklist criteria first.');
        }

        $existingSession = $this->repository->getChecklistSessionByGeofence($geofenceId, $semesterId, $week);
        if ($existingSession !== null) {
            throw new RuntimeException('Checklist has already been started for this week.');
        }

        $this->repository->createChecklistSessionByGeofence($geofenceId, $semesterId, $week, $today->format('Y-m-d'));

        return $this->repository->initializeWeekChecklistRowsByGeofence($geofenceId, $semesterId, $week, $checkedBy);
    }

    public function updateChecklistItemForSchool(int $studentId, int $geofenceId, int $semesterId, string $week, int $checklistItemId, int $value, int $checkedBy): void
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Invalid student_id');
        }
        if ($geofenceId <= 0) {
            throw new InvalidArgumentException('Invalid geofence_id');
        }
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester_id');
        }
        if ($checklistItemId <= 0) {
            throw new InvalidArgumentException('Invalid checklist item.');
        }
        if ($checkedBy <= 0) {
            throw new InvalidArgumentException('Invalid coordinator.');
        }

        $week = $this->normalizeWeek($week);
        $row = $this->repository->getStudentChecklistItemRowByGeofence($studentId, $geofenceId, $semesterId, $week, $checklistItemId);
        if ($row === null) {
            throw new RuntimeException('Checklist has not been started for this week.');
        }

        if (!$this->isSessionOpen((string) ($row['checklist_date'] ?? ''), $row['closed_at'] ?? null)) {
            throw new RuntimeException('Checklist is already closed for this week.');
        }
        if ($this->repository->isChecklistStudentConfirmed($studentId, $geofenceId, $semesterId, $week)) {
            throw new RuntimeException('This student checklist has already been confirmed.');
        }

        $item = $this->repository->getChecklistItem($checklistItemId);
        if ($item === null || (int) ($item['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Checklist item is no longer active.');
        }

        $itemSemesterId = (int) ($item['semester_id'] ?? 0);
        if ($itemSemesterId > 0 && $itemSemesterId !== $semesterId) {
            throw new RuntimeException('Checklist item is not available for the active semester.');
        }

        $isChecked = $value === 1 ? 1 : 0;
        $awardedPoints = $isChecked === 1 ? (int) ($item['points'] ?? 0) : 0;

        $this->repository->upsertStudentChecklistItemByGeofence(
            $studentId,
            $geofenceId,
            $semesterId,
            $week,
            $checklistItemId,
            $isChecked,
            $awardedPoints,
            $checkedBy
        );
    }

    public function confirmChecklistForStudent(int $studentId, int $geofenceId, int $semesterId, string $week, int $confirmedBy): void
    {
        if ($studentId <= 0 || $geofenceId <= 0 || $semesterId <= 0 || $confirmedBy <= 0) {
            throw new InvalidArgumentException('Invalid checklist confirmation context.');
        }

        $week = $this->normalizeWeek($week);
        $session = $this->repository->getChecklistSessionByGeofence($geofenceId, $semesterId, $week);
        if ($session === null || !$this->isSessionOpen((string) ($session['checklist_date'] ?? ''), $session['closed_at'] ?? null)) {
            throw new RuntimeException('Checklist is not open for confirmation.');
        }

        $this->repository->confirmChecklistStudent($studentId, $geofenceId, $semesterId, $week, $confirmedBy);
    }

    public function getChecklistBySchool(int $geofenceId, int $semesterId, string $week, ?int $actorUserId = null): array
    {
        if ($geofenceId <= 0) {
            throw new InvalidArgumentException('Invalid geofence_id');
        }
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester_id');
        }

        $week = $this->normalizeWeek($week);

        $this->syncChecklistWindowBySchool($geofenceId, $semesterId, $week);
        if ($actorUserId !== null && $actorUserId > 0 && $this->repository->getChecklistSessionByGeofence($geofenceId, $semesterId, $week) !== null) {
            $this->repository->syncNewActiveItemsIntoWeekByGeofence($geofenceId, $semesterId, $week, $actorUserId);
        }

        $rows = $this->repository->getChecklistByGeofence($geofenceId, $semesterId, $week);
        $items = $this->repository->getActiveChecklistItems($semesterId);
        $itemCount = count($items);

        foreach ($rows as &$row) {
            $checkedCount = (int) ($row['checked_count'] ?? 0);
            $isConfirmed = !empty($row['confirmed_at'])
                || $this->repository->isChecklistStudentConfirmed(
                    (int) ($row['student_user_id'] ?? 0),
                    $geofenceId,
                    $semesterId,
                    $week
                );
            $row['status'] = $isConfirmed
                ? 'Confirmed'
                : ($itemCount > 0 && $checkedCount === $itemCount
                ? 'Completed'
                : ($checkedCount > 0 ? 'In Progress' : 'Pending'));
        }
        unset($row);

        return $rows;
    }

    public function getChecklistSessionStateForSchool(int $geofenceId, int $semesterId, string $week): array
    {
        if ($geofenceId <= 0) {
            throw new InvalidArgumentException('Invalid geofence_id');
        }
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('Invalid semester_id');
        }

        $week = $this->normalizeWeek($week);
        $this->syncChecklistWindowBySchool($geofenceId, $semesterId, $week);
        $session = $this->repository->getChecklistSessionByGeofence($geofenceId, $semesterId, $week);
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

    private function syncChecklistWindowBySchool(int $geofenceId, int $semesterId, string $week): void
    {
        $session = $this->repository->getChecklistSessionByGeofence($geofenceId, $semesterId, $week);
        if ($session === null) {
            return;
        }

        $checklistDate = (string) ($session['checklist_date'] ?? '');
        if ($checklistDate === '' || !$this->isSessionOpen($checklistDate, $session['closed_at'] ?? null)) {
            if (($session['closed_at'] ?? null) === null && $checklistDate !== '') {
                $this->repository->closeChecklistSessionByGeofence($geofenceId, $semesterId, $week);
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

        $checklistDay = DateTimeImmutable::createFromFormat('Y-m-d', $checklistDate);
        if ($checklistDay === false || $checklistDay->format('Y-m-d') > $today->format('Y-m-d')) {
            return false;
        }

        // A checklist opened on any weekday remains open through Friday of
        // that same week instead of expiring at the end of its start date.
        return $checklistDay->modify('monday this week')->format('Y-m-d')
            === $today->modify('monday this week')->format('Y-m-d');
    }

    private function normalizeWeek(string $week): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $week);
        if ($date === false) {
            throw new InvalidArgumentException('Invalid week');
        }

        return $date->modify('monday this week')->format('Y-m-d');
    }

    private function activeWeekStartForSemester(int $semesterId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT start_date, practicum_start_date, practicum_status, end_date, semester_status
             FROM semesters
             WHERE id = :semester_id
             LIMIT 1'
        );
        $stmt->execute(['semester_id' => $semesterId]);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($semester === false) {
            return null;
        }

        if (!practicumCanRun(
            (string) ($semester['practicum_start_date'] ?? ''),
            null,
            (string) ($semester['practicum_status'] ?? '')
        )) {
            return null;
        }

        $weeks = practicumBuildWeeksFromStart((string) ($semester['practicum_start_date'] ?? ''));
        $activeWeek = practicumActiveWeek($weeks);

        return $activeWeek['start'] ?? null;
    }
}
