<?php
/**
 * AI Tarafından Üretilen Yanıtı Doğrudan Not Olarak Kaydetme Uç Noktası
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
$pdo = getDBConnection();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$title = trim($input['title'] ?? '');
$content = trim($input['content'] ?? '');
$courseId = !empty($input['course_id']) ? (int)$input['course_id'] : null;
$projectId = !empty($input['project_id']) ? (int)$input['project_id'] : null;
$refNoteId = !empty($input['ref_note_id']) ? (int)$input['ref_note_id'] : null;
$tags = trim($input['tags'] ?? 'AI Çözüm, Sınav Hazırlığı');

$filePath = null;
$fileType = null;
$fileName = null;

// Eğer referans bir not seçildiyse onun PDF dosyasını ve ders bilgilerini otomatik miras al
if ($refNoteId) {
    $stmtRef = $pdo->prepare("SELECT course_id, project_id, file_path, file_type, file_name FROM notes WHERE id = ? AND user_id = ?");
    $stmtRef->execute([$refNoteId, $_SESSION['user_id']]);
    $refNote = $stmtRef->fetch();
    if ($refNote) {
        if (!$courseId && !empty($refNote['course_id'])) $courseId = $refNote['course_id'];
        if (!$projectId && !empty($refNote['project_id'])) $projectId = $refNote['project_id'];
        $filePath = $refNote['file_path'];
        $fileType = $refNote['file_type'];
        $fileName = $refNote['file_name'];
    }
}

if (empty($title)) {
    $title = 'AI Analiz & Çalışma Notu - ' . date('d.m.Y H:i');
}

if (empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Kaydedilecek içerik bulunamadı.']);
    exit;
}

$updateCurrentNote = !empty($input['update_current_note']);
$targetNoteId = !empty($input['note_id']) ? (int)$input['note_id'] : null;

try {
    if ($updateCurrentNote && $targetNoteId) {
        // Mevcut notun içeriğini doğrudan AI analiziyle güncelle
        $stmt = $pdo->prepare("UPDATE notes SET content = ?, is_ai = 1, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$content, $targetNoteId, $_SESSION['user_id']]);
        
        echo json_encode([
            'success' => true,
            'note_id' => $targetNoteId,
            'updated' => true,
            'message' => 'AI incelemesi ve sınav çözümleri bu nota kalıcı olarak kaydedildi!'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, course_id, project_id, tags, is_pinned, is_ai, file_path, file_type, file_name) VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $title, $content, $courseId, $projectId, $tags, $filePath, $fileType, $fileName]);
    $noteId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'note_id' => $noteId,
        'updated' => false,
        'message' => 'Not ve referans döküman başarıyla kaydedildi!'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Not kaydedilirken bir hata oluştu: ' . $e->getMessage()
    ]);
}
