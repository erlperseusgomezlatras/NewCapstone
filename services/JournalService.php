<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/JournalRepository.php';

final class JournalService
{
    private PDO $pdo;
    private JournalRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->repository = new JournalRepository($this->pdo);
    }

    public function getSectionJournals(int $sectionId, int $semesterId, string $weekStart): array
    {
        if ($sectionId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('Invalid section_id or semester_id');
        }

        return $this->repository->getSectionJournals($sectionId, $semesterId, $this->normalizeWeek($weekStart));
    }

    public function getJournalDetail(int $studentId, int $semesterId, string $weekStart): ?array
    {
        if ($studentId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('Invalid student_id or semester_id');
        }

        return $this->repository->getJournalDetail($studentId, $semesterId, $this->normalizeWeek($weekStart));
    }

    public function approveJournal(int $studentId, int $semesterId, string $weekStart, string $feedback = ''): void
    {
        $this->repository->updateJournalStatus(
            $studentId,
            $semesterId,
            $this->normalizeWeek($weekStart),
            'approved',
            $feedback !== '' ? $feedback : null
        );
    }

    public function declineJournal(int $studentId, int $semesterId, string $weekStart, string $feedback = ''): void
    {
        $this->repository->updateJournalStatus(
            $studentId,
            $semesterId,
            $this->normalizeWeek($weekStart),
            'revise',
            $feedback !== '' ? $feedback : null
        );
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
