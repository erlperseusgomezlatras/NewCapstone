<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/UserRepository.php';

final class ProfileService
{
    private PDO $pdo;
    private UserRepository $userRepository;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->userRepository = new UserRepository($this->pdo);
    }

    public function updateProfileWithOptionalPhoto(int $userId, array $profileInput, ?array $fileInput, string $uploadDir): void
    {
        $required = ['first_name', 'last_name'];
        foreach ($required as $field) {
            if (!isset($profileInput[$field]) || trim((string) $profileInput[$field]) === '') {
                throw new InvalidArgumentException('Missing required field: ' . $field);
            }
        }

        $photoPath = null;
        if ($fileInput !== null && ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $photoPath = $this->storePhotoUpload($fileInput, $uploadDir);
        }

        $this->userRepository->updateProfile(
            $userId,
            trim((string) $profileInput['first_name']),
            isset($profileInput['middle_name']) ? trim((string) $profileInput['middle_name']) : null,
            trim((string) $profileInput['last_name']),
            isset($profileInput['phone']) ? trim((string) $profileInput['phone']) : null,
            $photoPath
        );
    }

    private function storePhotoUpload(array $file, string $uploadDir): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        $allowedMimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimeMap[$mimeType])) {
            throw new RuntimeException('Invalid image type');
        }

        $extension = $allowedMimeMap[$mimeType];
        $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Upload directory is not writable');
        }

        $absolutePath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Failed to move uploaded file');
        }

        return 'uploads/profile_photos/' . $fileName;
    }
}

