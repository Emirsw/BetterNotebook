<?php
/**
 * Güvenli ve Kusursuz PDF & Dosya Sunucu Uç Noktası (cPanel / Apache / Nginx / Inline Görüntüleme Uyumlu)
 */
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Yetkisiz erişim. Lütfen giriş yapın.');
}

$userId = $_SESSION['user_id'];
$noteId = !empty($_GET['note_id']) ? (int)$_GET['note_id'] : 0;
$filePathParam = !empty($_GET['file']) ? trim($_GET['file']) : '';

$pdo = getDBConnection();
$filePath = null;
$fileName = 'document.pdf';
$fileType = 'pdf';

if ($noteId > 0) {
    $stmt = $pdo->prepare("SELECT file_path, file_name, file_type FROM notes WHERE id = ? AND user_id = ? AND trashed_at IS NULL");
    $stmt->execute([$noteId, $userId]);
    $note = $stmt->fetch();
    if ($note && !empty($note['file_path'])) {
        $filePath = $note['file_path'];
        $fileName = $note['file_name'] ?: basename($filePath);
        $fileType = $note['file_type'] ?: 'pdf';
    }
} elseif (!empty($filePathParam)) {
    // Güvenlik: Path Traversal Koruması
    $cleanPath = str_replace(['..', '\\'], ['', '/'], $filePathParam);
    if (strpos($cleanPath, 'uploads/notes/') === 0) {
        $filePath = $cleanPath;
        $fileName = basename($cleanPath);
        $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
        $fileType = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']) ? 'image' : 'pdf';
    }
}

if (!$filePath) {
    http_response_code(404);
    die('Döküman bulunamadı.');
}

$absPath = __DIR__ . '/../' . ltrim($filePath, '/\\');

if (!file_exists($absPath)) {
    http_response_code(404);
    die('Sunucuda dosya bulunamadı: ' . htmlspecialchars($fileName));
}

$fileSize = filesize($absPath);
$ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// Output Buffering'i temizle
while (ob_get_level()) {
    ob_end_clean();
}

// HTTP Başlıkları (Inline Görüntüleme ve İframe İzni)
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
header('Content-Length: ' . $fileSize);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400, must-revalidate');
header('Pragma: public');
header('X-Frame-Options: SAMEORIGIN');
header('Access-Control-Allow-Origin: *');

// Dosyayı oku ve gönder
readfile($absPath);
exit;
