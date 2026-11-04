<?php
require_once __DIR__ . '/includes/notebook_install.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?$/iD', $_SERVER['HTTP_HOST'] ?? '');
if (!$isHttps && !$isLocal) {
    http_response_code(403);
    exit('Kurulum ekranını HTTPS üzerinden aç.');
}

$token = bnSecretSetting('BETTERNOTEBOOK_SETUP_TOKEN');
$enabled = (bool)preg_match('/^[a-f0-9]{64}$/iD', $token);
$installed = false;
$available = false;
$error = '';
$username = '';
$email = '';
$csrf = '';

if ($enabled) {
    try {
        $db = getDBConnection();
        $installed = bnSchemaVersion($db) > 0 || bnHasUsers($db);
        if (!$installed) {
            bnEncryptionKey();
            $available = true;
        }
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $error = $exception instanceof PDOException ? 'Kurulum veritabanı denetlenemedi.' : $exception->getMessage();
    }
}

if ($available) {
    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_secure' => $isHttps,
        'cookie_samesite' => 'Lax',
    ]);
    $_SESSION['bn_setup_csrf'] ??= bin2hex(random_bytes(32));
    $csrf = $_SESSION['bn_setup_csrf'];
}

function bnSetupField(string $name): string
{
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $available) {
    $username = trim(bnSetupField('username'));
    $email = trim(bnSetupField('email'));
    $password = bnSetupField('password');
    if (!hash_equals($csrf, bnSetupField('csrf')) || !hash_equals($token, trim(bnSetupField('setup_token')))) {
        http_response_code(403);
        $error = 'Kurulum kodu veya oturum doğrulanamadı.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D', $username)
        || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        $error = 'Geçerli bir kullanıcı adı ve e-posta adresi gir.';
    } elseif (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
        || $password !== bnSetupField('confirm_password')) {
        http_response_code(422);
        $error = 'En az 12, en çok 72 bayt uzunluğunda bir parola gir ve iki alanda da aynı parolayı kullan.';
    } else {
        try {
            bnCreateFirstAccount($db, $username, $email, $password);
            unset($_SESSION['bn_setup_csrf']);
            session_regenerate_id(true);
            header('Location: login.php', true, 303);
            exit;
        } catch (Throwable $exception) {
            http_response_code(503);
            error_log('BetterNotebook setup failed: ' . get_class($exception) . ' / ' . $exception->getCode());
            $error = 'Kurulum tamamlanamadı. Veritabanı izinlerini ve sunucu hata kaydını kontrol et.';
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    http_response_code(403);
}

function bnSetupEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>İlk kurulum · BetterNotebook</title>
    <link rel="icon" href="assets/img/favicon.svg">
    <link rel="stylesheet" href="assets/css/setup.css">
</head>
<body>
<main class="setup-card">
    <div class="brand"><img src="assets/img/favicon.svg" width="42" height="42" alt=""><strong>Better<span>Notebook</span></strong></div>
    <?php if ($installed): ?>
        <p class="eyebrow">BETTERNOTEBOOK</p>
        <h1>Bu çalışma alanı kurulmuş.</h1>
        <p>Mevcut hesabınla giriş yapabilirsin. Eski bir sürümü güncelliyorsan sunucudaki veritabanı güncelleme komutunu kullan.</p>
        <a class="primary" href="login.php">Girişe dön <span aria-hidden="true">↗</span></a>
    <?php elseif (!$enabled): ?>
        <p class="eyebrow">KURULUM KİLİTLİ</p>
        <h1>Kurulumu etkinleştir.</h1>
        <p>Sunucu yapılandırmasına kurulum kodunu ve şifreleme anahtarını ekledikten sonra bu sayfayı yenile.</p>
        <a href="login.php">Girişe dön</a>
    <?php else: ?>
        <p class="eyebrow">İLK KURULUM</p>
        <h1>Çalışma alanını oluştur.</h1>
        <p>İlk hesabını tanımla. BetterNotebook tabloları yapılandırdığın veritabanında hazırlanacak.</p>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= bnSetupEscape($error) ?></p><?php endif; ?>
        <?php if ($available): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= bnSetupEscape($csrf) ?>">
                <label>Kurulum kodu<input type="password" name="setup_token" autocomplete="off" required minlength="64" maxlength="64"></label>
                <label>Kullanıcı adı<input name="username" value="<?= bnSetupEscape($username) ?>" autocomplete="username" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.\-]{3,50}"></label>
                <label>E-posta<input type="email" name="email" value="<?= bnSetupEscape($email) ?>" autocomplete="email" required maxlength="100"></label>
                <div class="passwords">
                    <label>Parola<input type="password" name="password" autocomplete="new-password" required minlength="12" maxlength="72"></label>
                    <label>Parolayı doğrula<input type="password" name="confirm_password" autocomplete="new-password" required minlength="12" maxlength="72"></label>
                </div>
                <button class="primary" type="submit">Kurulumu tamamla <span aria-hidden="true">↗</span></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
