<?php
/**
 * Kanban Görev Yönetimi AJAX Uç Noktası
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim.']);
    exit;
}

$pdo = getDBConnection();
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'update_status') {
    $taskId = (int)($input['task_id'] ?? 0);
    $newStatus = $input['status'] ?? 'todo';

    if (!in_array($newStatus, ['todo', 'in_progress', 'done'])) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz durum.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE project_tasks pt 
                           JOIN projects p ON pt.project_id = p.id 
                           SET pt.status = ? 
                           WHERE pt.id = ? AND p.user_id = ?");
    $stmt->execute([$newStatus, $taskId, $_SESSION['user_id']]);

    echo json_encode(['success' => true]);
    exit;
} elseif ($action === 'add_task') {
    $projectId = (int)($input['project_id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $priority = $input['priority'] ?? 'medium';

    if (empty($title) || empty($projectId)) {
        echo json_encode(['success' => false, 'error' => 'Görev başlığı zorunludur.']);
        exit;
    }

    // Proje kullanıcıya mı ait kontrol et
    $chk = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
    $chk->execute([$projectId, $_SESSION['user_id']]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Proje bulunamadı.']);
        exit;
    }

    $ins = $pdo->prepare("INSERT INTO project_tasks (project_id, title, priority, status) VALUES (?, ?, ?, 'todo')");
    $ins->execute([$projectId, $title, $priority]);

    echo json_encode([
        'success' => true,
        'task_id' => $pdo->lastInsertId(),
        'title' => htmlspecialchars($title),
        'priority' => $priority
    ]);
    exit;
} elseif ($action === 'delete_task') {
    $taskId = (int)($input['task_id'] ?? 0);

    $stmt = $pdo->prepare("DELETE pt FROM project_tasks pt 
                           JOIN projects p ON pt.project_id = p.id 
                           WHERE pt.id = ? AND p.user_id = ?");
    $stmt->execute([$taskId, $_SESSION['user_id']]);

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Geçersiz istek.']);
