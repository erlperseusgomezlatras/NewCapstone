<?php

declare(strict_types=1);

// Keep deployment credentials in database.local.php. It is deliberately not
// tracked by Git so production passwords never end up in the repository.
$databaseConfig = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'practicum_system',
    'user' => 'root',
    'pass' => '',
];

$localConfigPath = __DIR__ . '/database.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (!is_array($localConfig)) {
        throw new RuntimeException('config/database.local.php must return an array.');
    }
    $databaseConfig = array_replace($databaseConfig, $localConfig);
}

define('DB_HOST', (string) $databaseConfig['host']);
define('DB_PORT', (int) $databaseConfig['port']);
define('DB_NAME', (string) $databaseConfig['name']);
define('DB_USER', (string) $databaseConfig['user']);
define('DB_PASS', (string) $databaseConfig['pass']);

function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        http_response_code(500);
        exit('Database connection failed: ' . htmlspecialchars($exception->getMessage()));
    }

    return $pdo;
}
