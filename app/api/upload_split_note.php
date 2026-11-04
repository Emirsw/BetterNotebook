<?php
/**
 * Otomatik Bölünmüş PDF Notlarını Kaydetme API Uç Noktası
 */
ob_start();
require_once __DIR__ . '/../config/config.php';

// Herhangi bir PHP warning/notice çıktısını temizle
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açmanız gerekiyor.']);
    exit;
}

$userId = $_SESSION['user_id'];
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Geçersiz istek türü.']);
    exit;
}

$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$courseId = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
$projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$tags = trim($_POST['tags'] ?? '');
$isPinned = isset($_POST['is_pinned']) && $_POST['is_pinned'] == '1' ? 1 : 0;

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Not başlığı boş olamaz.']);
    exit;
}

$filePath = null;
$fileType = null;
$fileName = null;

if (isset($_FILES['note_file'])) {
    if ($_FILES['note_file']['error'] === UPLOAD_ERR_OK) {
        $origName = basename($_FILES['note_file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        
        $allowedPpt = ['pptx', 'ppt'];
        
        if ($ext !== 'pdf' && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && !in_array($ext, $allowedPpt)) {
            echo json_encode(['success' => false, 'error' => 'Yalnızca PDF, PPTX veya görsel yüklenebilir.']);
            exit;
        }

        $uploadDir = __DIR__ . '/../uploads/notes/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $cleanBaseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($origName, PATHINFO_FILENAME));

        if (in_array($ext, $allowedPpt)) {
            require_once __DIR__ . '/../includes/pptx_helper.php';
            $tempPptxName = 'temp_' . $userId . '_' . time() . '_' . uniqid() . '.' . $ext;
            $tempPptxPath = $uploadDir . $tempPptxName;

            if (move_uploaded_file($_FILES['note_file']['tmp_name'], $tempPptxPath)) {
                $targetPdfName = 'doc_' . $userId . '_' . time() . '_' . uniqid() . '.pdf';
                $targetPdfPath = $uploadDir . $targetPdfName;

                $conv = convertPptxToPdf($tempPptxPath, $targetPdfPath);
                @unlink($tempPptxPath);

                if ($conv['success']) {
                    $filePath = 'uploads/notes/' . $targetPdfName;
                    $fileType = 'pdf';
                    $fileName = pathinfo($origName, PATHINFO_FILENAME) . '.pdf';
                } else {
                    echo json_encode(['success' => false, 'error' => 'PPTX PDF\'e dönüştürülemedi: ' . ($conv['error'] ?? 'Hata')]);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Dosya sunucuya taşınamadı.']);
                exit;
            }
        } else {
            $fileType = ($ext === 'pdf') ? 'pdf' : 'image';
            $newFileName = 'doc_' . $userId . '_' . time() . '_' . uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $newFileName;

            if (move_uploaded_file($_FILES['note_file']['tmp_name'], $targetPath)) {
                $filePath = 'uploads/notes/' . $newFileName;
                $fileName = $origName;
            } else {
                echo json_encode(['success' => false, 'error' => 'Dosya diske kaydedilemedi.']);
                exit;
            }
        }
    } elseif ($_FILES['note_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadError = $_FILES['note_file']['error'];
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE   => 'Dosya boyutu sunucu (php.ini) limitini aşıyor. Lütfen parça boyutunu 10 sayfa seçin.',
            UPLOAD_ERR_FORM_SIZE  => 'Dosya boyutu form limitini aşıyor.',
            UPLOAD_ERR_PARTIAL    => 'Dosya kısmen yüklendi, lütfen tekrar deneyin.',
            UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici klasör bulunamadı.',
            UPLOAD_ERR_CANT_WRITE => 'Dosya diske yazılamadı.',
        ];
        echo json_encode(['success' => false, 'error' => $errorMessages[$uploadError] ?? 'Dosya yükleme hatası (Kod: ' . $uploadError . ')']);
        exit;
    }
}

try {
    $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, course_id, project_id, tags, is_pinned, file_path, file_type, file_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $title, $content, $courseId, $projectId, $tags, $isPinned, $filePath, $fileType, $fileName]);
    $insertedId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'note_id' => $insertedId,
        'message' => 'Not başarıyla kaydedildi.'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
}
