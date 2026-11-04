<?php
require_once __DIR__ . '/../config/database.php';

const BN_SCHEMA_VERSION = 2;

function bnTableExists(PDO $db, string $table): bool
{
    $query = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}

function bnHasUsers(PDO $db): bool
{
    return bnTableExists($db, 'users') && (bool)$db->query('SELECT EXISTS(SELECT 1 FROM users)')->fetchColumn();
}

function bnSchemaVersion(PDO $db): int
{
    return bnTableExists($db, 'notebook_schema')
        ? (int)$db->query('SELECT version FROM notebook_schema WHERE id = 1')->fetchColumn() : 0;
}

function bnIsInstalled(PDO $db): bool
{
    return bnSchemaVersion($db) >= BN_SCHEMA_VERSION;
}

function bnInstallLock(PDO $db, callable $operation): void
{
    $name = 'bn:install:' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
    $lock = $db->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute([$name]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Başka bir kurulum işlemi sürüyor. Biraz sonra tekrar dene.');
    }
    try {
        $operation();
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$name]);
    }
}

function bnApplyBaseSchema(PDO $db): void
{
    $path = __DIR__ . '/../sql/schema.sql';
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Kurulum şeması bulunamadı.');
    }

    // This bundled DDL file contains no routines or semicolons inside literals.
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
}

function bnAddLegacyColumns(PDO $db, string $table, array $definitions): void
{
    $existing = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($definitions as $name => $definition) {
        if (!in_array($name, $existing, true)) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
        }
    }
}

function bnUpgradeDatabase(PDO $db): void
{
    bnEncryptionKey();
    $version = bnSchemaVersion($db);
    if ($version < BN_SCHEMA_VERSION) {
        bnApplyBaseSchema($db);
        foreach (['notes', 'courses', 'notebook_media'] as $table) {
            bnAddLegacyColumns($db, $table, ['trashed_at' => 'DATETIME DEFAULT NULL']);
            $indexes = $db->query("SHOW INDEX FROM {$table} WHERE Key_name = 'bn_trash_expiry'");
            if (!$indexes->fetch()) {
                $db->exec("ALTER TABLE {$table} ADD INDEX bn_trash_expiry (trashed_at)");
            }
        }
    }
    if ($version < 1) {
        bnAddLegacyColumns($db, 'notes', [
            'qa_history' => 'LONGTEXT DEFAULT NULL',
            'file_path' => 'VARCHAR(255) DEFAULT NULL',
            'file_type' => 'VARCHAR(50) DEFAULT NULL',
            'file_name' => 'VARCHAR(255) DEFAULT NULL',
            'is_ai' => 'TINYINT(1) DEFAULT 0',
        ]);
        bnAddLegacyColumns($db, 'settings', [
            'ui_language' => "VARCHAR(2) NOT NULL DEFAULT 'tr'",
            'ai_provider' => "VARCHAR(30) DEFAULT 'gemini'",
            'groq_api_key' => 'VARCHAR(255) DEFAULT NULL',
            'groq_model' => "VARCHAR(100) DEFAULT 'llama-3.3-70b-versatile'",
            'openrouter_api_key' => 'VARCHAR(255) DEFAULT NULL',
            'openrouter_model' => "VARCHAR(100) DEFAULT 'deepseek/deepseek-r1:free'",
        ]);
        bnAddLegacyColumns($db, 'notebook_summaries', ['applied_at' => 'DATETIME DEFAULT NULL']);
        $db->exec("ALTER TABLE settings
            MODIFY COLUMN gemini_api_key TEXT DEFAULT NULL,
            MODIFY COLUMN gemini_model VARCHAR(100) DEFAULT 'gemini-2.5-flash'");
    }

    // Validate already encrypted rows too. An incorrect master key must not mark an upgrade complete.
    $db->beginTransaction();
    try {
        $rows = $db->query("SELECT id, user_id, gemini_api_key FROM settings
            WHERE gemini_api_key IS NOT NULL AND gemini_api_key <> '' FOR UPDATE");
        $update = $db->prepare('UPDATE settings SET gemini_api_key = ?, updated_at = updated_at WHERE id = ?');
        foreach ($rows as $row) {
            $stored = $row['gemini_api_key'];
            $userId = (int)$row['user_id'];
            if (str_starts_with($stored, BN_GEMINI_PREFIX)) {
                bnDecryptGeminiKey($stored, $userId);
                continue;
            }
            $update->execute([bnEncryptGeminiKey($stored, $userId), $row['id']]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function bnMarkInstalled(PDO $db): void
{
    $query = $db->prepare('INSERT INTO notebook_schema (id, version) VALUES (1, ?)
        ON DUPLICATE KEY UPDATE version = GREATEST(version, VALUES(version))');
    $query->execute([BN_SCHEMA_VERSION]);
}

function bnCreateFirstAccount(PDO $db, string $username, string $email, string $password): void
{
    bnInstallLock($db, function () use ($db, $username, $email, $password): void {
        if (bnSchemaVersion($db) > 0 || bnHasUsers($db)) {
            throw new RuntimeException('Bu veritabanında kurulum zaten tamamlanmış.');
        }
        bnUpgradeDatabase($db);
        $db->beginTransaction();
        try {
            $insert = $db->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
            $insert->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $settings = $db->prepare('INSERT INTO settings (user_id) VALUES (?)');
            $settings->execute([$db->lastInsertId()]);
            bnMarkInstalled($db);
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    });
}
