<?php
declare(strict_types=1);

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = rtrim($scriptDir, '/');
$target = ($basePath === '' ? '' : $basePath) . '/public/';

header('Location: ' . $target, true, 302);
exit;