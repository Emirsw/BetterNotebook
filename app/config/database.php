<?php
require_once __DIR__ . '/secrets.php';

function bnDatabaseName(): string
{
    return defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'kisisel_not_sistemi');
}

function getDBConnection(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $port = defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: 3306);
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'root');
    $password = defined('DB_PASS') ? DB_PASS : getenv('DB_PASS');
    $database = bnDatabaseName();
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $host)
        || !preg_match('/^[a-zA-Z0-9_-]+$/D', $database)
        || !ctype_digit((string)$port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('Veritabanı yapılandırması geçersiz.');
    }

    try {
        $connection = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $user,
            $password === false ? '' : $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $error) {
        error_log('BetterNotebook database connection failed: ' . $error->getCode());
        throw new RuntimeException('Veritabanına bağlanılamadı. Sunucu yapılandırmasını kontrol et.');
    }
    return $connection;
}
