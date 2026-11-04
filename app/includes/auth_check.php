<?php
/**
 * Oturum Kontrol Middleware
 */
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    setFlash('warning', 'Lütfen devam etmek için giriş yapınız.');
    redirect('login.php');
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}
