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

    public function getCoordinatorSchoolJournals(int $coordinatorUserId, int $semesterId, string $weekStart): array
    {
        if ($coordinatorUserId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('Invalid coordinator_user_id or semester_id');
        }

        return $this->repository->getCoordinatorSchoolJournals($coordinatorUserId, $semesterId, $this->normalizeWeek($weekStart));
    }

    public function getJournalDetail(int $studentId, int $semesterId, string $weekStart): ?array
    {
        if ($studentId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('Invalid student_id or semester_id');
        }

        return $this->repository->getJournalDetail($studentId, $semesterId, $this->normalizeWeek($weekStart));
    }

    public function approveJournal(int $studentId, int $semesterId, string $weekStart, string $feedback = '', int $reviewedBy = 0): void
    {
        $this->repository->updateJournalStatus(
            $studentId,
            $semesterId,
            $this->normalizeWeek($weekStart),
            'approved',
            $feedback !== '' ? $feedback : null,
            $reviewedBy
        );
    }

    public function declineJournal(int $studentId, int $semesterId, string $weekStart, string $feedback = '', int $reviewedBy = 0): void
    {
        $this->repository->updateJournalStatus(
            $studentId,
            $semesterId,
            $this->normalizeWeek($weekStart),
            'declined',
            $feedback !== '' ? $feedback : null,
            $reviewedBy
        );
    }

    public function resubmitJournal(int $journalId, int $studentId, string $gratefulFor, string $proudOf, array $wordsToInspire, array $affirmations, string $lookForwardTo, ?string $feeling): void
    {
        if ($journalId <= 0 || $studentId <= 0) {
            throw new InvalidArgumentException('Invalid journal_id or student_id');
        }

        $allowedFeelings = ['great', 'good', 'neutral', 'lean_not', 'not_good'];
        if ($feeling !== null && $feeling !== '' && !in_array($feeling, $allowedFeelings, true)) {
            throw new InvalidArgumentException('Invalid mood value.');
        }

        $gratefulFor = trim($gratefulFor);
        $proudOf = trim($proudOf);
        $lookForwardTo = trim($lookForwardTo);
        if ($gratefulFor === '' || $proudOf === '' || $lookForwardTo === '') {
            throw new InvalidArgumentException('Please complete required journal fields.');
        }

        $normalizeList = static function (array $values): array {
            return array_values(array_filter(
                array_map(static fn($value): string => trim((string) $value), $values),
                static fn(string $value): bool => $value !== ''
            ));
        };

        $this->repository->resubmitJournal(
            $journalId,
            $studentId,
            $gratefulFor,
            $proudOf,
            implode("\n", $normalizeList($wordsToInspire)),
            implode("\n", $normalizeList($affirmations)),
            $lookForwardTo,
            $feeling !== null && $feeling !== '' ? $feeling : null
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
