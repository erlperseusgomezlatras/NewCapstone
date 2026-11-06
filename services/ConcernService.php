<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/ConcernRepository.php';

final class ConcernService
{
    private ConcernRepository $repository;

    public function __construct(?PDO $pdo = null)
    {
        $this->repository = new ConcernRepository($pdo ?? getPDO());
        $this->repository->ensureConcernSchema();
    }

    public function submitConcern(int $studentUserId, int $semesterId, string $category, string $subject, string $message, ?array $file = null): int
    {
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Invalid student account.');
        }
        if ($semesterId <= 0) {
            throw new InvalidArgumentException('No active semester is available for this concern.');
        }
        $category = trim($category);
        $validCategories = ['Attendance', 'Medical / Emergency', 'Practicum', 'Deployment', 'Other'];
        if (!in_array($category, $validCategories, true)) {
            throw new InvalidArgumentException('Select a valid concern category.');
        }
        $subject = trim($subject);
        $message = trim($message);

        if ($subject === '' || $message === '') {
            throw new InvalidArgumentException('Subject and concern message are required.');
        }
        if (mb_strlen($subject) > 150) {
            throw new InvalidArgumentException('Subject must be 150 characters or fewer.');
        }

        return $this->repository->createConcern($studentUserId, $semesterId, $category, $subject, $message, $this->storeAttachment($file));
    }

    public function getStudentConcerns(int $studentUserId, int $semesterId): array
    {
        if ($studentUserId <= 0) {
            return [];
        }

        return $this->repository->getStudentConcerns($studentUserId, $semesterId);
    }

    public function getStudentConcernSummary(int $studentUserId, int $semesterId): array
    {
        if ($studentUserId <= 0) {
            return [
                'total' => 0,
                'pending' => 0,
                'declined' => 0,
                'resolved' => 0,
            ];
        }

        return $this->repository->getStudentConcernSummary($studentUserId, $semesterId);
    }

    public function getCoordinatorManagedStudentProfile(int $coordinatorUserId, int $studentUserId): ?array
    {
        if ($coordinatorUserId <= 0 || $studentUserId <= 0) {
            return null;
        }

        return $this->repository->getCoordinatorManagedStudentProfile($coordinatorUserId, $studentUserId);
    }

    public function getCoordinatorStudentConcerns(int $coordinatorUserId, int $studentUserId, int $semesterId): array
    {
        if ($coordinatorUserId <= 0 || $studentUserId <= 0) {
            return [];
        }

        return $this->repository->getCoordinatorStudentConcerns($coordinatorUserId, $studentUserId, $semesterId);
    }

    public function updateConcernStatus(int $coordinatorUserId, int $studentUserId, int $semesterId, int $concernId, string $status): void
    {
        if (!in_array($status, ['resolved', 'declined'], true)) {
            throw new InvalidArgumentException('Invalid concern status.');
        }

        $this->repository->updateConcernStatus($coordinatorUserId, $studentUserId, $semesterId, $concernId, $status);
    }

    public function getHeadTeacherConcerns(int $semesterId): array
    {
        return $semesterId > 0 ? $this->repository->getHeadTeacherConcerns($semesterId) : [];
    }

    public function replyToConcern(int $concernId, int $senderUserId, string $senderRole, string $message, ?array $file = null): void
    {
        if ($concernId <= 0 || $senderUserId <= 0 || !in_array($senderRole, ['student', 'coordinator', 'head_teacher'], true)) {
            throw new InvalidArgumentException('Invalid concern reply.');
        }
        $message = trim($message);
        if ($message === '' && $file === null) {
            throw new InvalidArgumentException('Enter a reply or attach a file.');
        }
        if (mb_strlen($message) > 5000) {
            throw new InvalidArgumentException('Reply must be 5,000 characters or fewer.');
        }

        $this->repository->addMessage($concernId, $senderUserId, $senderRole, $message, $this->storeAttachment($file));
    }

    public function replyAsStudent(int $concernId, int $studentUserId, string $message, ?array $file = null): void
    {
        if (!$this->repository->concernBelongsToStudent($concernId, $studentUserId)) {
            throw new RuntimeException('Concern not found.');
        }
        $this->replyToConcern($concernId, $studentUserId, 'student', $message, $file);
    }

    public function replyAsCoordinator(int $concernId, int $coordinatorUserId, int $studentUserId, int $semesterId, string $message, ?array $file = null): void
    {
        $concerns = $this->repository->getCoordinatorStudentConcerns($coordinatorUserId, $studentUserId, $semesterId);
        $allowed = false;
        foreach ($concerns as $concern) {
            if ((int) ($concern['id'] ?? 0) === $concernId) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new RuntimeException('Concern not found for this student.');
        }
        $this->replyToConcern($concernId, $coordinatorUserId, 'coordinator', $message, $file);
    }

    private function storeAttachment(?array $file): ?array
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The attachment could not be uploaded.');
        }
        if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Each attachment must be 10 MB or smaller.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $originalName = trim((string) ($file['name'] ?? 'attachment'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'docx'];
        if ($tmp === '' || !is_uploaded_file($tmp) || !in_array($extension, $allowed, true)) {
            throw new InvalidArgumentException('Attach a JPG, PNG, WEBP, GIF, PDF, or DOCX file.');
        }

        $directory = dirname(__DIR__) . '/assets/uploads/concerns';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to prepare the attachment folder.');
        }
        $filename = 'concern_' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file($tmp, $directory . '/' . $filename)) {
            throw new RuntimeException('Unable to save the attachment.');
        }

        return [
            'path' => 'assets/uploads/concerns/' . $filename,
            'name' => mb_substr($originalName, 0, 255),
        ];
    }
}
