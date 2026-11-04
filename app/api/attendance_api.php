<?php
/**
 * Devamsızlık Güncelleme AJAX Uç Noktası
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim.']);
    exit;
}

$pdo = getDBConnection();
$input = json_decode(file_get_contents('php://input'), true);

$courseId = (int)($input['course_id'] ?? 0);
$change = (int)($input['change'] ?? 0); // +1 veya -1

if (!$courseId || !$change) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz parametreler.']);
    exit;
}

// Kursu bul
$stmt = $pdo->prepare("SELECT id, used_absenteeism, max_absenteeism FROM courses WHERE id = ? AND user_id = ?");
$stmt->execute([$courseId, $_SESSION['user_id']]);
$course = $stmt->fetch();

if (!$course) {
    echo json_encode(['success' => false, 'error' => 'Ders bulunamadı.']);
    exit;
}

$newCount = max(0, $course['used_absenteeism'] + $change);
$upd = $pdo->prepare("UPDATE courses SET used_absenteeism = ? WHERE id = ?");
$upd->execute([$newCount, $courseId]);

echo json_encode([
    'success' => true,
    'used_absenteeism' => $newCount,
    'max_absenteeism' => $course['max_absenteeism'],
    'remaining' => max(0, $course['max_absenteeism'] - $newCount)
]);
