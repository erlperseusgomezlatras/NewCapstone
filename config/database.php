<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'practicum_system';
const DB_USER = 'root';
const DB_PASS = '';

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
