<?php
require_once __DIR__ . '/config/config.php';

if (isset($_SESSION['user_id'])) {
    redirect('index.php');
}

$error = '';
require_once __DIR__ . '/includes/notebook_install.php';
try {
    $db = getDBConnection();
    if (!bnHasUsers($db) && !bnIsInstalled($db)) {
        redirect('setup.php');
    }
    if (!bnIsInstalled($db)) {
        http_response_code(503);
        $error = 'Veritabanı güncellemesi gerekiyor. Sunucuda php scripts/database.php upgrade çalıştır.';
    }
} catch (RuntimeException $exception) {
    http_response_code(503);
    $error = 'Veritabanına erişilemiyor. İlk kurulum için setup.php sayfasına bak.';
}

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDBConnection();
    $usernameOrEmail = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($usernameOrEmail) || empty($password)) {
        $error = 'Lütfen tüm alanları doldurun.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            setFlash('success', 'Tekrar hoş geldiniz, ' . ($user['full_name'] ?: $user['username']) . '!');
            redirect('index.php');
        } else {
            $error = 'Kullanıcı adı veya şifre hatalı!';
        }
    }
}
require __DIR__ . '/includes/notebook_login.php';
