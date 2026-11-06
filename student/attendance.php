<?php
declare(strict_types=1);

header('Location: ' . (dirname($_SERVER['SCRIPT_NAME'] ?? '') ?: '') . '/dashboard.php?tab=attendance');
exit;
