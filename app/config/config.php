<?php
/**
 * Genel Sistem Yapılandırması ve Yardımcı Fonksiyonlar
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

// Büyük dosya yüklemelerinde post_max_size aşımı kontrolü (Yalnızca gerçek aşım durumlarında)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_LENGTH'])) {
    $contentLength = (int)$_SERVER['CONTENT_LENGTH'];
    $maxPostStr = ini_get('post_max_size');
    
    // post_max_size stringini güvenli şekilde bayt cinsine çevir
    $maxBytes = 0;
    if (!empty($maxPostStr)) {
        $val = trim((string)$maxPostStr);
        if (strlen($val) > 0) {
            $last = strtolower(substr($val, -1));
            $num = (int)$val;
            switch($last) {
                case 'g': $maxBytes = $num * 1024 * 1024 * 1024; break;
                case 'm': $maxBytes = $num * 1024 * 1024; break;
                case 'k': $maxBytes = $num * 1024; break;
                default:  $maxBytes = $num; break;
            }
        }
    }

    // Yalnızca CONTENT_LENGTH gerçekten post_max_size bayt sınırını aştığında ve $_POST boşaldığında tetikle
    if ($maxBytes > 0 && $contentLength > $maxBytes && empty($_POST) && empty($_FILES)) {
        $isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) || 
                 (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
                 
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => "Yüklenen dosya sunucunun kabul edebileceği maksimum boyutu ({$maxPostStr}) aşıyor. Lütfen daha küçük veya bölünmüş bir PDF yükleyin."
            ]);
            exit;
        }

        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => "Yüklenen dosya sunucunun kabul edebileceği maksimum boyutu ({$maxPostStr}) aşıyor. Lütfen daha küçük veya bölünmüş bir PDF yükleyin."
        ];
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

require_once __DIR__ . '/database.php';

// Temel Ayarlar
define('APP_NAME', 'Akademik & Proje Takip Sistemi');
define('APP_VERSION', '1.0.0');

// URL Yardımcısı (SSL / Proxy / Cloudflare & cPanel Uyumlu)
function getBaseUrl() {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false);

    $protocol = $isHttps ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $script = str_replace('\\', '/', $script);
    $script = rtrim($script, '/');
    if ($script === '/') $script = '';
    return $protocol . $host . $script;
}

// XSS Koruması
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// CSRF Token Oluştur & Doğrula
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Flash Mesajları
function setFlash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type, // success, error, warning, info
        'message' => $message
    ];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Yönlendirme
function redirect($path) {
    header("Location: " . $path);
    exit;
}

// Giriş Yapan Kullanıcıyı Getir
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT u.*, s.gemini_api_key, s.gemini_model, s.ai_provider, s.groq_api_key, s.groq_model, s.openrouter_api_key, s.openrouter_model, s.theme_mode, s.current_term, s.target_gpa, s.graduation_date 
                           FROM users u 
                           LEFT JOIN settings s ON u.id = s.user_id 
                           WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

// Tarih ve Kalan Gün Hesaplayıcı
function formatTurkishDate($dateTimeStr, $includeTime = false) {
    if (!$dateTimeStr) return '-';
    $timestamp = strtotime($dateTimeStr);
    $months = [
        'January' => 'Ocak', 'February' => 'Şubat', 'March' => 'Mart',
        'April' => 'Nisan', 'May' => 'Mayıs', 'June' => 'Haziran',
        'July' => 'Temmuz', 'August' => 'Ağustos', 'September' => 'Eylül',
        'October' => 'Ekim', 'November' => 'Kasım', 'December' => 'Aralık'
    ];
    $monthEn = date('F', $timestamp);
    $monthTr = $months[$monthEn] ?? $monthEn;
    
    if ($includeTime) {
        return date('d', $timestamp) . ' ' . $monthTr . ' ' . date('Y - H:i', $timestamp);
    }
    return date('d', $timestamp) . ' ' . $monthTr . ' ' . date('Y', $timestamp);
}

function getDaysRemaining($dateStr) {
    if (!$dateStr) return null;
    $target = new DateTime($dateStr);
    $today = new DateTime('today');
    $diff = $today->diff($target);
    
    if ($today > $target) {
        return -$diff->days;
    }
    return $diff->days;
}
