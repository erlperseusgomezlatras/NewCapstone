<?php

declare(strict_types=1);

final class SystemHolidayService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_holidays (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                holiday_date DATE NOT NULL,
                holiday_name VARCHAR(160) NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_system_holiday_date (holiday_date),
                KEY idx_system_holiday_date (holiday_date)
            ) ENGINE=InnoDB"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS school_holidays (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                geofence_id BIGINT UNSIGNED NOT NULL,
                holiday_date DATE NOT NULL,
                holiday_name VARCHAR(160) NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_school_holiday_date (geofence_id, holiday_date),
                KEY idx_school_holiday_date (holiday_date),
                CONSTRAINT fk_school_holidays_geofence
                    FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_school_holidays_created_by
                    FOREIGN KEY (created_by) REFERENCES users(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB"
        );
    }

    public function listBetween(string $startDate, string $endDate, ?int $geofenceId = null): array
    {
        $this->assertDate($startDate);
        $this->assertDate($endDate);
        if ($endDate < $startDate) {
            throw new InvalidArgumentException('The end date must not be earlier than the start date.');
        }
        if ($geofenceId !== null && $geofenceId <= 0) {
            throw new InvalidArgumentException('A valid partnered school is required.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT holiday_date, holiday_name
             FROM system_holidays
             WHERE holiday_date BETWEEN :start_date AND :end_date
             ORDER BY holiday_date'
        );
        $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
        $holidays = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $holiday) {
            $holidays[(string) $holiday['holiday_date']] = (string) $holiday['holiday_name'];
        }

        if ($geofenceId !== null) {
            $schoolStmt = $this->pdo->prepare(
                'SELECT holiday_date, holiday_name
                 FROM school_holidays
                 WHERE geofence_id = :geofence_id
                   AND holiday_date BETWEEN :start_date AND :end_date
                 ORDER BY holiday_date'
            );
            $schoolStmt->execute([
                'geofence_id' => $geofenceId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
            foreach ($schoolStmt->fetchAll(PDO::FETCH_ASSOC) as $holiday) {
                $holidays[(string) $holiday['holiday_date']] = (string) $holiday['holiday_name'];
            }
        }

        return $holidays;
    }

    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, holiday_date, holiday_name, created_at, 'global' AS holiday_scope, NULL AS geofence_id, 'All schools' AS school_name
             FROM system_holidays
             UNION ALL
             SELECT sh.id, sh.holiday_date, sh.holiday_name, sh.created_at,
                    'local' AS holiday_scope, sh.geofence_id, g.name AS school_name
             FROM school_holidays sh
             JOIN geofence_locations g ON g.id = sh.geofence_id
             ORDER BY holiday_date DESC, holiday_scope ASC, school_name ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listActiveSchools(): array
    {
        $stmt = $this->pdo->query(
            "SELECT g.id, g.name
             FROM geofence_locations g
             JOIN partner_schools ps ON ps.geofence_id = g.id
             WHERE g.is_active = 1
               AND ps.school_status = 'active'
             ORDER BY g.name ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function add(string $date, string $name, int $createdBy, ?int $geofenceId = null): void
    {
        $this->assertDate($date);
        $name = trim($name);
        if ($name === '' || strlen($name) > 480) {
            throw new InvalidArgumentException('Enter a holiday name up to 160 characters.');
        }
        if ($createdBy <= 0) {
            throw new InvalidArgumentException('A valid Head Teacher account is required.');
        }
        if ((int) (new DateTimeImmutable($date))->format('N') > 5) {
            throw new InvalidArgumentException('Only school weekdays can be marked as holidays.');
        }

        if ($geofenceId !== null) {
            if ($geofenceId <= 0) {
                throw new InvalidArgumentException('Select a valid partnered school.');
            }
            $schoolStmt = $this->pdo->prepare(
                "SELECT 1
                 FROM geofence_locations g
                 JOIN partner_schools ps ON ps.geofence_id = g.id
                 WHERE g.id = :geofence_id
                   AND g.is_active = 1
                   AND ps.school_status = 'active'
                 LIMIT 1"
            );
            $schoolStmt->execute(['geofence_id' => $geofenceId]);
            if ($schoolStmt->fetchColumn() === false) {
                throw new InvalidArgumentException('Select an active partnered school.');
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO school_holidays (geofence_id, holiday_date, holiday_name, created_by)
                 VALUES (:geofence_id, :holiday_date, :holiday_name, :created_by)
                 ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), created_by = VALUES(created_by)'
            );
            $stmt->execute([
                'geofence_id' => $geofenceId,
                'holiday_date' => $date,
                'holiday_name' => $name,
                'created_by' => $createdBy,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO system_holidays (holiday_date, holiday_name, created_by)
             VALUES (:holiday_date, :holiday_name, :created_by)
             ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), created_by = VALUES(created_by)'
        );
        $stmt->execute([
            'holiday_date' => $date,
            'holiday_name' => $name,
            'created_by' => $createdBy,
        ]);
    }

    public function remove(int $holidayId, ?int $geofenceId = null): void
    {
        if ($holidayId <= 0) {
            throw new InvalidArgumentException('A valid holiday must be selected.');
        }

        $table = $geofenceId === null ? 'system_holidays' : 'school_holidays';
        $where = $geofenceId === null ? 'id = :id' : 'id = :id AND geofence_id = :geofence_id';
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE {$where}");
        $params = ['id' => $holidayId];
        if ($geofenceId !== null) {
            $params['geofence_id'] = $geofenceId;
        }
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('The holiday was not found or has already been removed.');
        }
    }

    public function isHoliday(string $date, ?int $geofenceId = null): bool
    {
        $this->assertDate($date);
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM system_holidays
             WHERE holiday_date = :global_date
             UNION ALL
             SELECT 1
             FROM school_holidays
             WHERE holiday_date = :school_date
               AND geofence_id = :geofence_id
             LIMIT 1'
        );
        $stmt->execute([
            'global_date' => $date,
            'school_date' => $date,
            'geofence_id' => max(0, (int) $geofenceId),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Enter a valid holiday date.');
        }
    }
}
