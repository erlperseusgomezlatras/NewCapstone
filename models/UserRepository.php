<?php

declare(strict_types=1);

final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getRoleIdByCode(string $roleCode): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE role_code = :role_code LIMIT 1');
        $stmt->execute(['role_code' => $roleCode]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function createUser(
        int $roleId,
        string $schoolId,
        string $email,
        string $passwordHash,
        string $accountStatus = 'active'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (role_id, school_id, email, password_hash, account_status)
             VALUES (:role_id, :school_id, :email, :password_hash, :account_status)'
        );
        $stmt->execute([
            'role_id' => $roleId,
            'school_id' => $schoolId,
            'email' => $email,
            'password_hash' => $passwordHash,
            'account_status' => $accountStatus,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createProfile(
        int $userId,
        string $firstName,
        ?string $middleName,
        string $lastName,
        ?string $phone,
        ?string $photoPath
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, phone, photo_path)
             VALUES (:user_id, :first_name, :middle_name, :last_name, :phone, :photo_path)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'phone' => $phone,
            'photo_path' => $photoPath,
        ]);
    }

    public function updateProfile(
        int $userId,
        string $firstName,
        ?string $middleName,
        string $lastName,
        ?string $phone,
        ?string $photoPath
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE user_profiles
             SET first_name = :first_name,
                 middle_name = :middle_name,
                 last_name = :last_name,
                 phone = :phone,
                 photo_path = COALESCE(:photo_path, photo_path),
                 updated_at = NOW()
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'phone' => $phone,
            'photo_path' => $photoPath,
            'user_id' => $userId,
        ]);
    }
}

