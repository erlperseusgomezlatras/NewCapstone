<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');

header('Location: /practicum_system/head_teacher/attendance_intelegence.php');
exit;

