<?php

declare(strict_types=1);

// Configure this file as a daily Hostinger cron job shortly after midnight.
// AttendanceService also runs this recovery on portal requests as a fallback.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AttendanceService.php';

try {
    new AttendanceService(getPDO());
    echo "Attendance integrity check completed.\n";
} catch (Throwable $e) {
    error_log('Attendance integrity cron failed: ' . $e->getMessage());
    http_response_code(500);
    echo "Attendance integrity check failed.\n";
}
