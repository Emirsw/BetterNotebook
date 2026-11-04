<?php
/**
 * Not AI Soru & Cevap (Q&A) Geçmişi Kaydetme / Güncelleme Uç Noktası
 */
ob_start();
require_once __DIR__ . '/../config/config.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açmanız gerekiyor.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo = getDBConnection();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$noteId = !empty($input['note_id']) ? (int)$input['note_id'] : 0;
$action = $input['action'] ?? 'append'; // 'append', 'set', 'clear', 'delete_item'

if ($noteId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz not ID.']);
    exit;
}

// Notun bu kullanıcıya ait olduğunu doğrula
$stmt = $pdo->prepare("SELECT id, qa_history FROM notes WHERE id = ? AND user_id = ?");
$stmt->execute([$noteId, $userId]);
$note = $stmt->fetch();

if (!$note) {
    echo json_encode(['success' => false, 'error' => 'Not bulunamadı veya erişim yetkiniz yok.']);
    exit;
}

$qaHistory = [];
if (!empty($note['qa_history'])) {
    $decoded = json_decode($note['qa_history'], true);
    if (is_array($decoded)) {
        $qaHistory = $decoded;
    }
}

try {
    if ($action === 'append') {
        $question = trim($input['question'] ?? '');
        $answer = trim($input['answer'] ?? '');

        if (empty($question) || empty($answer)) {
            echo json_encode(['success' => false, 'error' => 'Soru veya cevap boş olamaz.']);
            exit;
        }

        $newItem = [
            'id' => (string)($input['item_id'] ?? (round(microtime(true) * 1000))),
            'question' => $question,
            'answer' => $answer,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $qaHistory[] = $newItem;

        $upd = $pdo->prepare("UPDATE notes SET qa_history = ? WHERE id = ? AND user_id = ?");
        $upd->execute([json_encode($qaHistory, JSON_UNESCAPED_UNICODE), $noteId, $userId]);

        echo json_encode([
            'success' => true,
            'message' => 'Soru ve cevap başarıyla kaydedildi.',
            'item' => $newItem,
            'qa_history' => $qaHistory
        ]);
        exit;
    } elseif ($action === 'set') {
        $newHistory = $input['qa_history'] ?? [];
        if (!is_array($newHistory)) $newHistory = [];

        $upd = $pdo->prepare("UPDATE notes SET qa_history = ? WHERE id = ? AND user_id = ?");
        $upd->execute([json_encode($newHistory, JSON_UNESCAPED_UNICODE), $noteId, $userId]);

        echo json_encode([
            'success' => true,
            'message' => 'Q&A geçmişi güncellendi.',
            'qa_history' => $newHistory
        ]);
        exit;
    } elseif ($action === 'clear') {
        $upd = $pdo->prepare("UPDATE notes SET qa_history = NULL WHERE id = ? AND user_id = ?");
        $upd->execute([$noteId, $userId]);

        echo json_encode([
            'success' => true,
            'message' => 'Q&A geçmişi temizlendi.',
            'qa_history' => []
        ]);
        exit;
    } elseif ($action === 'delete_item') {
        $itemId = (string)($input['item_id'] ?? '');
        $filtered = array_values(array_filter($qaHistory, function($item) use ($itemId) {
            return (string)($item['id'] ?? '') !== $itemId;
        }));

        $jsonToSave = !empty($filtered) ? json_encode($filtered, JSON_UNESCAPED_UNICODE) : null;
        $upd = $pdo->prepare("UPDATE notes SET qa_history = ? WHERE id = ? AND user_id = ?");
        $upd->execute([$jsonToSave, $noteId, $userId]);

        echo json_encode([
            'success' => true,
            'message' => 'Soru silindi.',
            'qa_history' => $filtered
        ]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'İşlem başarısız: ' . $e->getMessage()]);
}
