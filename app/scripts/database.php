<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$command = $argv[1] ?? '';
if ($command === 'secret') {
    echo bin2hex(random_bytes(32)) . PHP_EOL;
    exit;
}
if (!in_array($command, ['upgrade', 'purge-trash'], true)) {
    fwrite(STDERR, "Kullanım: php scripts/database.php secret|upgrade|purge-trash\n");
    exit(1);
}

require_once __DIR__ . '/../includes/notebook_install.php';
try {
    $db = getDBConnection();
    if ($command === 'purge-trash') {
        if (!bnIsInstalled($db)) {
            throw new RuntimeException('Önce veritabanı güncellemesini çalıştır.');
        }
        require_once __DIR__ . '/../includes/notebook_trash.php';
        bnInstallLock($db, function () use ($db): void {
            echo 'Süresi dolan öğe: ' . bnTrashPurge($db) . PHP_EOL;
        });
        exit;
    }
    bnInstallLock($db, function () use ($db): void {
        if (!bnHasUsers($db)) {
            throw new RuntimeException('Mevcut kullanıcı bulunamadı. İlk kurulum için setup.php sayfasını aç.');
        }
        bnUpgradeDatabase($db);
        bnMarkInstalled($db);
    });
    echo "Veritabanı güncellendi. Gemini anahtarları şifreli saklanıyor.\n";
} catch (Throwable $error) {
    if ($error instanceof PDOException) {
        fwrite(STDERR, 'Veritabanı güncellenemedi. SQLSTATE: ' . $error->getCode() . PHP_EOL);
    } else {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
    }
    exit(1);
}
