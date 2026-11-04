<?php
/**
 * Pomodoro Çalışma Süresi Kaydetme AJAX Uç Noktası
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim.']);
    exit;
}

$pdo = getDBConnection();
$input = json_decode(file_get_contents('php://input'), true);

$duration = (int)($input['duration_minutes'] ?? 25);
$courseId = !empty($input['course_id']) ? (int)$input['course_id'] : null;
$projectId = !empty($input['project_id']) ? (int)$input['project_id'] : null;
$notes = trim($input['notes'] ?? 'Pomodoro Odaklanma Oturumu');
$sessionDate = date('Y-m-d');

$stmt = $pdo->prepare("INSERT INTO study_sessions (user_id, course_id, project_id, duration_minutes, session_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$_SESSION['user_id'], $courseId, $projectId, $duration, $sessionDate, $notes]);

echo json_encode([
    'success' => true,
    'message' => 'Tebrikler! ' . $duration . ' dakikalık odaklanma oturumu kaydedildi.'
]);
