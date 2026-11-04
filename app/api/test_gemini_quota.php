<?php
/**
 * Gemini API Key & Kota / Bağlantı Testi Uç Noktası
 */
ob_start();
require_once __DIR__ . '/../config/config.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açmanız gerekiyor.']);
    exit;
}

$currentUser = getCurrentUser();
try {
    $apiKey = bnAccountGeminiKey($currentUser) ?? '';
} catch (RuntimeException $error) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
    exit;
}
$model = $currentUser['gemini_model'] ?? 'gemini-2.5-flash';
if ($model === 'gemini-2.5-pro') {
    $model = 'gemini-3.1-pro-preview';
}

if (empty($apiKey)) {
    echo json_encode([
        'success' => false,
        'error' => 'Gemini API anahtarı girilmemiş. Lütfen yukarıdan API anahtarınızı kaydedin.'
    ]);
    exit;
}

// Google AI Studio modeller listesini sorgulayarak anahtarı doğrula (Token/Kota harcamaz)
$startTime = microtime(true);
$url = "https://generativelanguage.googleapis.com/v1beta/models";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-goog-api-key: ' . $apiKey]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
if (defined('CURL_IPRESOLVE_V4')) {
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$latencyMs = round((microtime(true) - $startTime) * 1000);

if ($curlError) {
    echo json_encode([
        'success' => false,
        'error' => 'Sunucudan Google\'a Bağlantı Hatası: ' . $curlError . ' (Hosting güvenlik duvarınız Google 443 portunu engelliyor olabilir).',
        'latency_ms' => $latencyMs
    ]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode === 200 && !empty($data['models'])) {
    // Model limit bilgilerini belirle
    $isPro = (strpos($model, 'pro') !== false);
    
    $limits = [
        'plan_name' => 'Google AI Studio Ücretsiz Katman (Free Tier)',
        'price' => '$0.00 / Ay (Ücretsiz)',
        'model' => $model,
        'rpd' => $isPro ? 50 : 1500, // Requests per day
        'rpm' => $isPro ? 2 : 15,    // Requests per minute
        'tpm' => $isPro ? 32000 : 1000000, // Tokens per minute
        'status' => 'Aktif & Kullanıma Hazır',
        'latency_ms' => $latencyMs,
        'models_count' => count($data['models']),
        'official_dashboard' => 'https://aistudio.google.com/app/plan_information'
    ];

    echo json_encode([
        'success' => true,
        'message' => 'API Anahtarınız başarıyla doğrulandı ve Google sunucularına bağlı!',
        'data' => $limits
    ]);
} elseif ($httpCode === 400 || $httpCode === 403 || $httpCode === 401) {
    $errMsg = str_replace($apiKey, '[redacted]', $data['error']['message'] ?? 'Geçersiz veya yetkisiz API Anahtarı.');
    echo json_encode([
        'success' => false,
        'error' => 'Google API Hatası (' . $httpCode . '): ' . $errMsg,
        'latency_ms' => $latencyMs
    ]);
} else {
    $errMsg = $data['error']['message'] ?? 'Bilinmeyen API yanıtı (HTTP ' . $httpCode . ')';
    echo json_encode([
        'success' => false,
        'error' => $errMsg,
        'latency_ms' => $latencyMs
    ]);
}
