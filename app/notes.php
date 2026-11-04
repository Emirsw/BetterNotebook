<?php
// BETTERNOTEBOOK_ENTRY: preserve legacy POST handlers and an explicit fallback.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['legacy'])) {
    $notebookView = 'notes';
    require __DIR__ . '/includes/notebook_app.php';
    return;
}

$pageTitle = "Not Defteri & Dökümanlar";
require_once __DIR__ . '/includes/auth_check.php';

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Not Ekleme / Güncelleme / Silme
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_note') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $courseId = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $tags = trim($_POST['tags'] ?? '');
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
        $removeFile = isset($_POST['remove_file']) ? 1 : 0;

        if (!empty($title)) {
            $filePath = null;
            $fileType = null;
            $fileName = null;

            // Mevcut notu kontrol et
            if ($noteId > 0) {
                $chk = $pdo->prepare("SELECT file_path, file_type, file_name FROM notes WHERE id = ? AND user_id = ? AND trashed_at IS NULL");
                $chk->execute([$noteId, $userId]);
                $existingNote = $chk->fetch();
                if ($existingNote) {
                    $filePath = $existingNote['file_path'];
                    $fileType = $existingNote['file_type'];
                    $fileName = $existingNote['file_name'];
                }
            }

            // Dosya silme işaretlendiyse
            if ($removeFile && $filePath) {
                $absOld = __DIR__ . '/' . ltrim($filePath, '/\\');
                if (file_exists($absOld)) @unlink($absOld);
                $filePath = null;
                $fileType = null;
                $fileName = null;
            }

            // Dosya Yükleme İşlemi (Normal Dosya, Otomatik Bölünmüş PDF veya PPTX Dönüştürme)
            if (isset($_FILES['note_file']) && $_FILES['note_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadError = $_FILES['note_file']['error'];
                
                if ($uploadError === UPLOAD_ERR_OK) {
                    $origName = basename($_FILES['note_file']['name']);
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $allowedImageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    $allowedPdfExts = ['pdf'];
                    $allowedPptExts = ['pptx', 'ppt'];

                    if (in_array($ext, $allowedPdfExts)) {
                        $detectedType = 'pdf';
                    } elseif (in_array($ext, $allowedImageExts)) {
                        $detectedType = 'image';
                    } elseif (in_array($ext, $allowedPptExts)) {
                        $detectedType = 'pptx';
                    } else {
                        setFlash('error', 'Desteklenmeyen dosya türü. Yalnızca PDF, PPTX veya görsel yükleyebilirsiniz.');
                        redirect('notes.php');
                    }

                    // Eski dosyayı sil
                    if ($filePath) {
                        $absOld = __DIR__ . '/' . ltrim($filePath, '/\\');
                        if (file_exists($absOld)) @unlink($absOld);
                    }

                    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'notes' . DIRECTORY_SEPARATOR;
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0777, true);
                    }

                    $cleanBaseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($origName, PATHINFO_FILENAME));

                    // ⚡ PowerPoint Sunumu ise Otomatik PDF'e Dönüştür
                    if ($detectedType === 'pptx') {
                        require_once __DIR__ . '/includes/pptx_helper.php';

                        $tempPptxName = 'temp_' . $userId . '_' . time() . '_' . substr($cleanBaseName, 0, 30) . '.' . $ext;
                        $tempPptxPath = $uploadDir . $tempPptxName;

                        if (move_uploaded_file($_FILES['note_file']['tmp_name'], $tempPptxPath)) {
                            $targetPdfName = 'doc_' . $userId . '_' . time() . '_' . substr($cleanBaseName, 0, 30) . '.pdf';
                            $targetPdfPath = $uploadDir . $targetPdfName;

                            $convResult = convertPptxToPdf($tempPptxPath, $targetPdfPath);
                            @unlink($tempPptxPath); // Geçici sunum dosyasını temizle

                            if ($convResult['success']) {
                                $filePath = 'uploads/notes/' . $targetPdfName;
                                $fileType = 'pdf';
                                $fileName = pathinfo($origName, PATHINFO_FILENAME) . '.pdf';
                                setFlash('success', 'PowerPoint sunumu başarıyla PDF dökümanına dönüştürüldü ve kaydedildi!');
                            } else {
                                setFlash('error', 'PowerPoint PDF\'e dönüştürülemedi: ' . ($convResult['error'] ?? 'Hata'));
                                redirect('notes.php');
                            }
                        } else {
                            setFlash('error', 'Sunum dosyası yüklenemedi.');
                            redirect('notes.php');
                        }
                    } else {
                        // Standart PDF veya Görsel
                        $newFileName = 'doc_' . $userId . '_' . time() . '_' . substr($cleanBaseName, 0, 30) . '.' . $ext;
                        $targetPath = $uploadDir . $newFileName;

                        if (move_uploaded_file($_FILES['note_file']['tmp_name'], $targetPath)) {
                            $filePath = 'uploads/notes/' . $newFileName;
                            $fileType = $detectedType;
                            $fileName = $origName;
                        } else {
                            setFlash('error', 'Dosya sunucuya taşınamadı. Lütfen klasör izinlerini kontrol edin.');
                            redirect('notes.php');
                        }
                    }
                } else {
                    $errorMessages = [
                        UPLOAD_ERR_INI_SIZE   => 'Dosya boyutu sunucu (php.ini) limitini aşıyor.',
                        UPLOAD_ERR_FORM_SIZE  => 'Dosya boyutu form limitini aşıyor.',
                        UPLOAD_ERR_PARTIAL    => 'Dosya kısmen yüklendi, tekrar deneyin.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici yükleme klasörü bulunamadı.',
                        UPLOAD_ERR_CANT_WRITE => 'Dosya diske yazılamadı.',
                        UPLOAD_ERR_EXTENSION  => 'Bir PHP eklentisi yüklemeyi engelledi.'
                    ];
                    $msg = $errorMessages[$uploadError] ?? 'Bilinmeyen dosya yükleme hatası (Kod: ' . $uploadError . ')';
                    setFlash('error', $msg);
                    redirect('notes.php');
                }
            }

            if ($noteId > 0) {
                $stmt = $pdo->prepare("UPDATE notes SET title = ?, content = ?, course_id = ?, project_id = ?, tags = ?, is_pinned = ?, file_path = ?, file_type = ?, file_name = ? WHERE id = ? AND user_id = ? AND trashed_at IS NULL");
                $stmt->execute([$title, $content, $courseId, $projectId, $tags, $isPinned, $filePath, $fileType, $fileName, $noteId, $userId]);
                if (empty($_SESSION['flash'])) setFlash('success', 'Not ve döküman başarıyla güncellendi.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, course_id, project_id, tags, is_pinned, file_path, file_type, file_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $title, $content, $courseId, $projectId, $tags, $isPinned, $filePath, $fileType, $fileName]);
                if (empty($_SESSION['flash'])) setFlash('success', 'Yeni not ve döküman başarıyla kaydedildi.');
            }
        } else {
            setFlash('error', 'Not başlığı boş bırakılamaz.');
        }
        redirect('notes.php');
    } elseif ($action === 'delete_note' || $action === 'delete_folder') {
        require_once __DIR__ . '/includes/notebook_trash.php';
        $ids = $action === 'delete_note' ? [$_POST['note_id'] ?? 0] : ($_POST['note_ids'] ?? []);
        foreach (is_array($ids) ? $ids : [] as $id) {
            bnTrashChange($pdo, (int)$userId, 'note', (int)$id, false);
        }
        setFlash('success', 'Notlar çöp kutusuna taşındı.');
        redirect('notes.php');
    } elseif ($action === 'toggle_pin') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE notes SET is_pinned = 1 - is_pinned WHERE id = ? AND user_id = ? AND trashed_at IS NULL");
        $stmt->execute([$noteId, $userId]);
        redirect('notes.php');
    }
}

// Filtreler
$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$searchQuery = trim($_GET['q'] ?? '');

$sql = "SELECT n.*, c.course_code, c.course_name, p.title as project_title 
        FROM notes n 
        LEFT JOIN courses c ON n.course_id = c.id 
        LEFT JOIN projects p ON n.project_id = p.id 
        WHERE n.user_id = ? AND n.trashed_at IS NULL";
$params = [$userId];

if ($filterCourse) {
    $sql .= " AND n.course_id = ?";
    $params[] = $filterCourse;
}
if ($searchQuery) {
    $sql .= " AND (n.title LIKE ? OR n.content LIKE ? OR n.tags LIKE ? OR n.file_name LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$sql .= " ORDER BY n.is_pinned DESC, n.updated_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll();

// =========================================================================
// 📁 AKILLI NOT & KLASÖR GRUPLAMA VE KATEGORİZASYON MANTIĞI:
// 1. Bölünmüş parçalı kitaplar -> "Klasör" (Döküman)
// 2. AI tarafından üretilen çözümler/rehberler (is_ai = 1) -> "AI Sınav Rehberi"
// 3. Ekli PDF/Görseli olan orijinal dökümanlar -> "Ders Dökümanı"
// 4. Kullanıcının kendi girdiği notlar -> "Kişisel Not"
// =========================================================================
$groupedDisplayItems = [];
$folderMap = [];

foreach ($notes as $n) {
    $title = $n['title'];
    // Bölüm deseni yakalama: "Engineering Economics - Bölüm 1 (s. 1-5)" veya "... - Bölüm 1"
    if (preg_match('/^(.*?)\s*-\s*Bölüm\s*(\d+)(.*)$/ui', $title, $m)) {
        $baseTitle = trim($m[1]);
        $partNum = (int)$m[2];
        $groupKey = 'folder_' . md5(mb_strtolower($baseTitle) . '_' . ($n['course_id'] ?? 0));
        
        if (!isset($folderMap[$groupKey])) {
            $folderMap[$groupKey] = [
                'is_folder' => true,
                'category_type' => 'document',
                'folder_key' => $groupKey,
                'folder_title' => $baseTitle,
                'course_id' => $n['course_id'],
                'course_code' => $n['course_code'],
                'course_name' => $n['course_name'],
                'project_id' => $n['project_id'],
                'project_title' => $n['project_title'],
                'is_pinned' => $n['is_pinned'],
                'updated_at' => $n['updated_at'],
                'items' => []
            ];
            $groupedDisplayItems[] = &$folderMap[$groupKey];
        }
        
        $folderMap[$groupKey]['items'][] = $n;
        if ($n['is_pinned']) {
            $folderMap[$groupKey]['is_pinned'] = 1;
        }
        if (strtotime($n['updated_at']) > strtotime($folderMap[$groupKey]['updated_at'])) {
            $folderMap[$groupKey]['updated_at'] = $n['updated_at'];
        }
    } else {
        // Tekil not türünü belirle:
        // Eğer dosyası varsa (PDF/PPTX/Görsel), o her zaman "Döküman" kategorisindedir (AI analizi eklense dahi).
        $catType = 'personal';
        if (!empty($n['file_path'])) {
            $catType = 'document';
        } elseif (!empty($n['is_ai'])) {
            $catType = 'ai';
        }

        $groupedDisplayItems[] = [
            'is_folder' => false,
            'category_type' => $catType,
            'note' => $n
        ];
    }
}

// Klasörlerin içindeki bölümleri 1, 2, 3... doğal sırasında diz
foreach ($groupedDisplayItems as &$item) {
    if (!empty($item['is_folder']) && !empty($item['items'])) {
        usort($item['items'], function($a, $b) {
            preg_match('/Bölüm\s*(\d+)/ui', $a['title'], $ma);
            preg_match('/Bölüm\s*(\d+)/ui', $b['title'], $mb);
            $numA = isset($ma[1]) ? (int)$ma[1] : 0;
            $numB = isset($mb[1]) ? (int)$mb[1] : 0;
            return $numA <=> $numB;
        });
    }
}
unset($item);

// Kategori Sayaçları
$countAll = count($groupedDisplayItems);
$countDocuments = 0;
$countAI = 0;
$countPersonal = 0;

foreach ($groupedDisplayItems as $dItem) {
    if ($dItem['category_type'] === 'document') $countDocuments++;
    elseif ($dItem['category_type'] === 'ai') $countAI++;
    elseif ($dItem['category_type'] === 'personal') $countPersonal++;
}

// Dersler & Projeler
$stmt = $pdo->prepare("SELECT id, course_code, course_name FROM courses WHERE user_id = ? AND trashed_at IS NULL ORDER BY course_code ASC");
$stmt->execute([$userId]);
$myCourses = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, title FROM projects WHERE user_id = ? ORDER BY title ASC");
$stmt->execute([$userId]);
$myProjects = $stmt->fetchAll();

include_once __DIR__ . '/includes/header.php';
?>

<!-- Üst Başlık & Arama & Yeni Not Butonu -->
<div class="space-y-4 mb-2">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white flex items-center gap-2.5">
                <span>Ders Notları & Döküman Merkezi</span>
                <span id="total-visible-count" class="text-xs px-2.5 py-0.5 rounded-full bg-slate-800 text-sky-300 border border-slate-700 font-bold"><?= $countAll ?> Öğe</span>
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">Ders materyalleri, AI destekli sınav rehberleri ve kişisel notların düzenli bir şekilde ayrılmıştır.</p>
        </div>
        <div class="flex items-center gap-2.5">
            <div class="relative">
                <i data-lucide="search" class="w-3.5 h-3.5 absolute left-3 top-3 text-slate-400"></i>
                <input type="text" id="live-notes-search" oninput="applyAllFilters()" value="<?= e($searchQuery) ?>" placeholder="Not, konu veya etiket ara..." class="bg-slate-900 border border-slate-800 rounded-xl pl-9 pr-4 py-2 text-xs text-white focus:outline-none focus:border-sky-500 w-44 sm:w-60 shadow-inner">
            </div>
            <button onclick="openNewNoteModal()" class="px-3.5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold flex items-center gap-1.5 transition shadow-sm flex-shrink-0">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Yeni Ekle</span>
            </button>
        </div>
    </div>

    <!-- Filtreleme & Sekme Çubuğu (Ders Seçici + Tür Sekmeleri) -->
    <div class="glass-panel rounded-2xl p-3 border border-slate-800 bg-slate-900/90 flex flex-col md:flex-row md:items-center justify-between gap-3 shadow-lg">
        
        <!-- Tür Sekmeleri (Kategori Filtresi) -->
        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0 scrollbar-none" id="category-tabs-container">
            <button type="button" onclick="setCategoryFilter('all')" data-tab="all" class="category-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 bg-sky-600 text-white shadow-sm border border-sky-500">
                <span>✨ Tümü</span>
                <span class="text-[10px] px-1.5 py-0.2 rounded-full bg-black/30 text-white font-mono"><?= $countAll ?></span>
            </button>
            <button type="button" onclick="setCategoryFilter('document')" data-tab="document" class="category-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-slate-800 transition flex items-center gap-1.5 border border-transparent">
                <i data-lucide="folder-archive" class="w-3.5 h-3.5 text-amber-400"></i>
                <span>📁 Dökümanlar & Kitaplar</span>
                <span class="text-[10px] px-1.5 py-0.2 rounded-full bg-slate-800 text-amber-300 font-mono"><?= $countDocuments ?></span>
            </button>
            <button type="button" onclick="setCategoryFilter('ai')" data-tab="ai" class="category-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-slate-800 transition flex items-center gap-1.5 border border-transparent">
                <i data-lucide="sparkles" class="w-3.5 h-3.5 text-purple-400"></i>
                <span>🤖 AI Sınav & Konu Rehberleri</span>
                <span class="text-[10px] px-1.5 py-0.2 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30 font-mono"><?= $countAI ?></span>
            </button>
            <button type="button" onclick="setCategoryFilter('personal')" data-tab="personal" class="category-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-slate-800 transition flex items-center gap-1.5 border border-transparent">
                <i data-lucide="file-text" class="w-3.5 h-3.5 text-emerald-400"></i>
                <span>✍️ Kişisel Notlarım</span>
                <span class="text-[10px] px-1.5 py-0.2 rounded-full bg-slate-800 text-slate-300 font-mono"><?= $countPersonal ?></span>
            </button>
        </div>

        <!-- Ders Filtresi Seçici -->
        <div class="flex items-center gap-2 flex-shrink-0 pt-2 md:pt-0 border-t md:border-t-0 border-slate-800">
            <label for="filter-course-select" class="text-xs font-semibold text-slate-400 flex items-center gap-1 flex-shrink-0">
                <i data-lucide="book" class="w-3.5 h-3.5 text-sky-400"></i>
                <span>Ders:</span>
            </label>
            <select id="filter-course-select" onchange="applyAllFilters()" class="bg-slate-950 border border-slate-700/80 rounded-xl px-3 py-1.5 text-xs text-white focus:outline-none focus:border-sky-500 max-w-[200px] sm:max-w-[260px] truncate shadow-inner">
                <option value="">🎓 Tüm Dersler (<?= $countAll ?>)</option>
                <?php foreach ($myCourses as $mc): ?>
                    <option value="<?= $mc['id'] ?>" <?= ($filterCourse === (int)$mc['id']) ? 'selected' : '' ?>>
                        <?= e($mc['course_code']) ?> - <?= e($mc['course_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<!-- Notlar & Klasörler Listesi Grid -->
<?php if (!empty($groupedDisplayItems)): ?>
    <div id="notes-grid-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($groupedDisplayItems as $idx => $displayItem): 
            $catType = $displayItem['category_type'];
            $courseIdAttr = $displayItem['is_folder'] ? ($displayItem['course_id'] ?? '') : ($displayItem['note']['course_id'] ?? '');
            $titleSearch = $displayItem['is_folder'] ? $displayItem['folder_title'] : $displayItem['note']['title'];
            $contentSearch = $displayItem['is_folder'] ? '' : ($displayItem['note']['content'] . ' ' . $displayItem['note']['tags']);
        ?>
            <?php if (!empty($displayItem['is_folder'])): 
                $folder = $displayItem;
                $folderJson = htmlspecialchars(json_encode($folder), ENT_QUOTES, 'UTF-8');
            ?>
                <!-- 📁 1. BÜYÜK DÖKÜMAN / BÖLÜNMÜŞ KİTAP KLASÖR KARTI -->
                <div class="note-card-item glass-panel rounded-2xl p-5 flex flex-col justify-between relative border border-amber-500/35 hover:border-amber-400/70 bg-gradient-to-br from-slate-900/95 via-slate-900/80 to-amber-950/20 shadow-xl transition-all duration-200 group cursor-pointer" 
                     data-category="document" 
                     data-course="<?= $courseIdAttr ?>" 
                     data-title="<?= htmlspecialchars(mb_strtolower($titleSearch), ENT_QUOTES) ?>" 
                     data-content="<?= htmlspecialchars(mb_strtolower($contentSearch), ENT_QUOTES) ?>" 
                     onclick="openFolderModal(<?= $folderJson ?>)">
                    <div>
                        <!-- Rozetler & Üst Bilgi -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-300 border border-amber-500/30 flex items-center gap-1">
                                    <i data-lucide="folder" class="w-3 h-3 text-amber-400"></i>
                                    <span><?= count($folder['items']) ?> Bölüm / Kitap Parçaları</span>
                                </span>
                                <?php if ($folder['course_code']): ?>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-800 text-sky-400 border border-slate-700">
                                        <?= e($folder['course_code']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                                <form method="POST" onsubmit="return confirm('Bu klasörü ve içindeki tüm <?= count($folder['items']) ?> bölümü silmek istediğinize emin misiniz?');" class="inline">
                                    <input type="hidden" name="action" value="delete_folder">
                                    <?php foreach ($folder['items'] as $it): ?>
                                        <input type="hidden" name="note_ids[]" value="<?= $it['id'] ?>">
                                    <?php endforeach; ?>
                                    <button type="submit" class="p-1 rounded text-slate-500 hover:text-red-400 transition" title="Tüm Klasörü Sil">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Klasör Başlığı & İkonu -->
                        <div class="flex items-start gap-3 mb-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/10 border border-amber-500/30 flex items-center justify-center text-amber-400 flex-shrink-0 shadow-inner group-hover:scale-105 transition">
                                <i data-lucide="folder-archive" class="w-5 h-5"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-bold text-white group-hover:text-amber-300 transition line-clamp-2 leading-snug">
                                    <?= e($folder['folder_title']) ?>
                                </h3>
                                <span class="text-[11px] text-slate-400 mt-0.5 block">
                                    Bölünmüş Kitap / Slayt Döküman Seti
                                </span>
                            </div>
                        </div>

                        <!-- Mini Bölüm Önizlemesi (İlk 3 Bölüm) -->
                        <div class="space-y-1.5 p-2.5 rounded-xl bg-slate-950/70 border border-slate-800/80 mb-3 text-xs">
                            <?php foreach (array_slice($folder['items'], 0, 3) as $subItem): ?>
                                <div class="flex items-center justify-between text-[11px] text-slate-300">
                                    <span class="truncate pr-2 flex items-center gap-1">
                                        <i data-lucide="file-text" class="w-3 h-3 text-rose-400 flex-shrink-0"></i>
                                        <span class="truncate"><?= e($subItem['title']) ?></span>
                                    </span>
                                    <span class="text-[10px] text-sky-400 flex-shrink-0 font-mono">PDF</span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($folder['items']) > 3): ?>
                                <div class="text-[10px] text-amber-400 font-semibold pt-1 border-t border-slate-900 flex items-center justify-between">
                                    <span>+ <?= count($folder['items']) - 3 ?> bölüm daha mevcut</span>
                                    <span class="underline">İncele →</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Alt Butonlar -->
                    <div class="mt-2 pt-3 border-t border-slate-800/80 flex items-center justify-between" onclick="event.stopPropagation()">
                        <span class="text-[10px] text-slate-500"><?= formatTurkishDate($folder['updated_at']) ?></span>
                        
                        <button type="button" onclick="openFolderModal(<?= $folderJson ?>)" class="px-3 py-1.5 rounded-lg bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 font-semibold text-xs flex items-center gap-1.5 transition border border-amber-500/30 shadow-sm">
                            <i data-lucide="folder-open" class="w-3.5 h-3.5"></i>
                            <span>Klasörü Aç (<?= count($folder['items']) ?> Bölüm)</span>
                        </button>
                    </div>
                </div>

            <?php elseif ($catType === 'ai'): 
                $n = $displayItem['note'];
                $nJson = htmlspecialchars(json_encode($n), ENT_QUOTES, 'UTF-8');
            ?>
                <!-- 🤖 2. AI SINAV & ÇALIŞMA REHBERİ KARTI (Mor/İndigo Özel Tasarım) -->
                <div class="note-card-item glass-panel rounded-2xl p-5 flex flex-col justify-between relative border border-purple-500/40 hover:border-purple-400/80 bg-gradient-to-br from-slate-900/95 via-slate-900/85 to-purple-950/25 shadow-xl transition-all duration-200 group cursor-pointer" 
                     data-category="ai" 
                     data-course="<?= $courseIdAttr ?>" 
                     data-title="<?= htmlspecialchars(mb_strtolower($titleSearch), ENT_QUOTES) ?>" 
                     data-content="<?= htmlspecialchars(mb_strtolower($contentSearch), ENT_QUOTES) ?>" 
                     onclick="openNoteReader(<?= $nJson ?>)">
                    <div>
                        <!-- Rozetler & Sabitleme -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-gradient-to-r from-purple-500/20 to-sky-500/20 text-purple-300 border border-purple-500/40 flex items-center gap-1 shadow-inner">
                                    <i data-lucide="sparkles" class="w-3 h-3 text-purple-400"></i>
                                    <span>AI Sınav & Konu Rehberi</span>
                                </span>
                                <?php if ($n['course_code']): ?>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-800 text-sky-400 border border-slate-700">
                                        <?= e($n['course_code']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_pin">
                                    <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="p-1 rounded text-slate-500 hover:text-amber-400 transition" title="Sabitle">
                                        <i data-lucide="pin" class="w-3.5 h-3.5 <?= $n['is_pinned'] ? 'text-amber-400 fill-amber-400' : '' ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Silmek istiyor musunuz?');" class="inline">
                                    <input type="hidden" name="action" value="delete_note">
                                    <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="p-1 rounded text-slate-500 hover:text-red-400 transition" title="Sil">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Başlık & İkon -->
                        <div class="flex items-start gap-3 mb-2.5">
                            <div class="w-10 h-10 rounded-xl bg-purple-500/20 border border-purple-500/30 flex items-center justify-center text-purple-300 flex-shrink-0 shadow-inner group-hover:scale-105 transition">
                                <i data-lucide="bot" class="w-5 h-5 text-purple-400"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-bold text-white group-hover:text-purple-300 transition line-clamp-2 leading-snug">
                                    <?= e($n['title']) ?>
                                </h3>
                                <?php if (!empty($n['file_path'])): ?>
                                    <span class="text-[10px] text-purple-300/80 flex items-center gap-1 mt-0.5 truncate">
                                        <i data-lucide="paperclip" class="w-3 h-3 text-purple-400"></i>
                                        <span class="truncate">Referans: <?= e($n['file_name'] ?: 'Ders PDF Dökümanı') ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Metin Önizlemesi -->
                        <?php if (!empty($n['content'])): ?>
                            <div class="text-xs text-slate-300/90 line-clamp-3 leading-relaxed mb-3 p-2.5 rounded-xl bg-slate-950/60 border border-purple-500/20 font-sans">
                                <?= e(mb_substr(strip_tags($n['content']), 0, 180)) ?>...
                            </div>
                        <?php endif; ?>

                        <?php if ($n['tags']): ?>
                            <div class="flex flex-wrap gap-1 mt-2">
                                <?php foreach (explode(',', $n['tags']) as $tag): ?>
                                    <span class="text-[10px] text-purple-300 bg-purple-950/40 px-2 py-0.5 rounded border border-purple-800/40">#<?= trim(e($tag)) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-3 pt-3 border-t border-purple-500/20 flex items-center justify-between" onclick="event.stopPropagation()">
                        <span class="text-[10px] text-slate-500"><?= formatTurkishDate($n['updated_at']) ?></span>
                        
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="openNoteReader(<?= $nJson ?>)" class="px-3 py-1.5 rounded-lg bg-gradient-to-r from-purple-600 to-sky-600 hover:from-purple-500 hover:to-sky-500 text-white font-semibold text-xs flex items-center gap-1.5 transition shadow-sm">
                                <i data-lucide="book-open" class="w-3.5 h-3.5"></i>
                                <span>Rehberi Oku & Çalış</span>
                            </button>
                            <button onclick="openEditNote(<?= $nJson ?>)" class="p-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white transition" title="Düzenle">
                                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>
                </div>

            <?php else: 
                // 📄 3. ORİJİNAL DÖKÜMAN / KİŞİSEL NOT KARTI
                $n = $displayItem['note'];
                $nJson = htmlspecialchars(json_encode($n), ENT_QUOTES, 'UTF-8');
                $isDoc = ($catType === 'document');
            ?>
                <div class="note-card-item glass-panel rounded-2xl p-5 flex flex-col justify-between relative hover:border-sky-500/50 transition duration-200 group cursor-pointer <?= $isDoc ? 'border-slate-800 hover:border-rose-500/40' : 'border-slate-800 hover:border-emerald-500/40' ?>" 
                     data-category="<?= $catType ?>" 
                     data-course="<?= $courseIdAttr ?>" 
                     data-title="<?= htmlspecialchars(mb_strtolower($titleSearch), ENT_QUOTES) ?>" 
                     data-content="<?= htmlspecialchars(mb_strtolower($contentSearch), ENT_QUOTES) ?>" 
                     onclick="openNoteReader(<?= $nJson ?>)">
                    <div>
                        <!-- Rozetler & Sabitleme -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <?php if ($isDoc): ?>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded <?= ($n['file_type'] === 'pdf') ? 'bg-rose-500/15 text-rose-300 border border-rose-500/30' : 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' ?>">
                                        <?= ($n['file_type'] === 'pdf') ? '📄 Orijinal Ders PDF' : '🖼️ Fotoğraf Eki' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 flex items-center gap-1">
                                        <i data-lucide="file-text" class="w-3 h-3 text-emerald-400"></i>
                                        <span>Kişisel Ders Notu</span>
                                    </span>
                                <?php endif; ?>

                                <?php if ($n['course_code']): ?>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-800 text-sky-400 border border-slate-700">
                                        <?= e($n['course_code']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_pin">
                                    <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="p-1 rounded text-slate-500 hover:text-amber-400 transition" title="Sabitle">
                                        <i data-lucide="pin" class="w-3.5 h-3.5 <?= $n['is_pinned'] ? 'text-amber-400 fill-amber-400' : '' ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Silmek istiyor musunuz?');" class="inline">
                                    <input type="hidden" name="action" value="delete_note">
                                    <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="p-1 rounded text-slate-500 hover:text-red-400 transition" title="Sil">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <h3 class="text-sm font-bold text-white mb-1.5 leading-snug group-hover:text-sky-300 transition"><?= e($n['title']) ?></h3>
                        
                        <?php if (!empty($n['content'])): ?>
                            <div class="text-xs text-slate-400 line-clamp-3 leading-relaxed whitespace-pre-line mb-3">
                                <?= e($n['content']) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Ekli PDF veya Fotoğraf Kutusu -->
                        <?php if (!empty($n['file_path'])): ?>
                            <div class="mb-3 p-2.5 rounded-xl bg-slate-900 border border-slate-800" onclick="event.stopPropagation()">
                                <?php if ($n['file_type'] === 'image'): ?>
                                    <a href="<?= getBaseUrl() . '/' . e($n['file_path']) ?>" target="_blank" class="block relative group/img overflow-hidden rounded-lg">
                                        <img src="<?= getBaseUrl() . '/' . e($n['file_path']) ?>" alt="Ekli Görsel" class="w-full h-28 object-cover rounded-lg group-hover/img:scale-105 transition duration-300">
                                        <div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover/img:opacity-100 transition text-[11px] text-white font-medium gap-1">
                                            <i data-lucide="maximize-2" class="w-3.5 h-3.5"></i> Görüntüle
                                        </div>
                                    </a>
                                <?php else: ?>
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2 truncate">
                                            <div class="w-8 h-8 rounded-lg bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400 flex-shrink-0">
                                                <i data-lucide="file-text" class="w-4 h-4"></i>
                                            </div>
                                            <div class="truncate">
                                                <span class="block text-xs font-semibold text-white truncate"><?= e($n['file_name'] ?: 'Ders_Notu.pdf') ?></span>
                                                <span class="text-[10px] text-slate-400">PDF Dökümanı</span>
                                            </div>
                                        </div>
                                        <a href="<?= getBaseUrl() . '/' . e($n['file_path']) ?>" target="_blank" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-400 text-xs font-semibold flex items-center gap-1 transition flex-shrink-0 border border-slate-700">
                                            <i data-lucide="external-link" class="w-3 h-3"></i>
                                            <span>Aç</span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($n['tags']): ?>
                            <div class="flex flex-wrap gap-1 mt-2">
                                <?php foreach (explode(',', $n['tags']) as $tag): ?>
                                    <span class="text-[10px] text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">#<?= trim(e($tag)) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-4 pt-3 border-t border-slate-800 flex items-center justify-between" onclick="event.stopPropagation()">
                        <span class="text-[10px] text-slate-500"><?= formatTurkishDate($n['updated_at']) ?></span>
                        
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="openNoteReader(<?= $nJson ?>)" class="px-2.5 py-1 rounded-lg bg-sky-500/15 hover:bg-sky-500/25 text-sky-300 border border-sky-500/30 text-xs font-semibold flex items-center gap-1 transition">
                                <i data-lucide="book-open" class="w-3.5 h-3.5"></i>
                                <span>Oku & İncele</span>
                            </button>
                            <a href="ai-assistant.php?type=exam_prep&note_id=<?= $n['id'] ?>&prompt=<?= urlencode($n['title'] . ' ders notunu ve ekli dosyasını referans alarak; konunun mantığını derinlemesine anlatan zengin bir Konu Anlatımı & Temel İlkeler bölümü oluştur, kritik formülleri LaTeX ($...$) ile detaylandır ve vize/finalde çıkabilecek en kritik 5 sınav sorusunu adım adım model çözümleri ve püf noktalarıyla birlikte hazırla.') ?>" title="AI ile bu not ve ekli PDF'i referans alarak analiz et" class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-300 hover:text-white transition border border-slate-700">
                                <i data-lucide="sparkles" class="w-3.5 h-3.5 text-sky-400"></i>
                            </a>
                            <button onclick="openEditNote(<?= $nJson ?>)" class="p-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white transition" title="Düzenle">
                                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <!-- Filtreleme Sonucu Boş Kalırsa Görünecek Mesaj -->
    <div id="no-filtered-notes-msg" class="glass-panel rounded-2xl p-12 text-center max-w-lg mx-auto hidden mt-6">
        <div class="w-14 h-14 rounded-2xl bg-slate-800 border border-slate-700 flex items-center justify-center text-amber-400 mx-auto mb-4">
            <i data-lucide="search-x" class="w-7 h-7"></i>
        </div>
        <h3 class="text-base font-bold text-white mb-1">Eşleşen Not Bulunamadı</h3>
        <p class="text-xs text-slate-400 mb-4">Seçilen filtre ve arama kriterine uygun bir ders notu veya döküman bulunamadı.</p>
        <button onclick="resetAllFilters()" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 text-xs font-semibold">
            Filtreleri Temizle
        </button>
    </div>

<?php else: ?>
    <div class="glass-panel rounded-2xl p-12 text-center max-w-lg mx-auto">
        <div class="w-14 h-14 rounded-2xl bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400 mx-auto mb-4">
            <i data-lucide="file-text" class="w-7 h-7"></i>
        </div>
        <h3 class="text-base font-bold text-white mb-1">Henüz Not Eklenmemiş</h3>
        <p class="text-xs text-slate-400 mb-6">Derslerde aldığın notları, PDF dökümanlarını veya tahta fotoğraflarını kaydedebilirsin.</p>
        <button onclick="openNewNoteModal()" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold shadow-sm">
            + İlk Notunu Oluştur
        </button>
    </div>
<?php endif; ?>

<!-- 📖 MODAL: TAM EKRAN ÇALIŞMA İSTASYONU (SOL: PDF, ORTA: AI ÖZET & NOT, SAĞ: AI SORU PANELİ) -->
<div id="modal-note-reader" class="hidden" style="position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; width: 100vw !important; height: 100vh !important; z-index: 999999 !important; margin: 0 !important; padding: 0 !important; background-color: #050505 !important;">
    <div class="w-full h-full flex flex-col bg-[#050505] p-0 m-0 overflow-hidden" style="width: 100vw !important; height: 100vh !important;">
        <!-- Reader Header -->
        <div class="px-5 py-2.5 border-b border-zinc-800/80 flex flex-wrap items-center justify-between gap-3 bg-[#09090b]/95 flex-shrink-0">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                <div class="w-8 h-8 rounded-lg bg-zinc-900 text-zinc-200 flex items-center justify-center border border-zinc-700/60 flex-shrink-0 shadow-sm">
                    <i data-lucide="book-open" class="w-4 h-4 text-zinc-300"></i>
                </div>
                <div class="min-w-0">
                    <h3 id="reader-note-title" class="text-sm sm:text-base font-bold text-white truncate tracking-tight"></h3>
                    <div class="flex items-center gap-2 text-xs text-zinc-400 mt-0.5 flex-wrap">
                        <span id="reader-note-course" class="text-zinc-300 font-semibold"></span>
                        <span id="reader-note-date" class="text-zinc-500 text-[11px]"></span>
                    </div>
                </div>
            </div>

            <!-- Toolbar Controls -->
            <div class="flex items-center gap-2 flex-wrap">
                <!-- Layout Görünüm Seçenekleri -->
                <div id="reader-layout-toggle" class="flex items-center bg-zinc-900 rounded-lg p-0.5 border border-zinc-800">
                    <button type="button" onclick="setReaderLayout('trio')" id="layout-btn-trio" title="3'lü Görünüm (PDF + Not + AI Soru Paneli)" class="px-2.5 py-1 text-xs font-semibold text-white bg-zinc-800 border border-zinc-700/70 rounded flex items-center gap-1 shadow-sm">
                        <i data-lucide="layout-grid" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">3'lü Görünüm</span>
                    </button>
                    <button type="button" onclick="setReaderLayout('split')" id="layout-btn-split" title="Yan Yana (PDF & Not)" class="px-2.5 py-1 text-xs font-semibold text-zinc-400 hover:text-white rounded flex items-center gap-1">
                        <i data-lucide="columns" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">PDF & Not</span>
                    </button>
                    <button type="button" onclick="setReaderLayout('pdf')" id="layout-btn-pdf" title="Yalnızca PDF" class="px-2.5 py-1 text-xs font-semibold text-zinc-400 hover:text-white rounded flex items-center gap-1">
                        <i data-lucide="file" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">Sadece PDF</span>
                    </button>
                    <button type="button" onclick="setReaderLayout('note')" id="layout-btn-note" title="Yalnızca Not / AI Özeti" class="px-2.5 py-1 text-xs font-semibold text-zinc-400 hover:text-white rounded flex items-center gap-1">
                        <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">Sadece Not</span>
                    </button>
                </div>

                <!-- AI Soru Paneli Hızlı Aç/Kapat Butonu -->
                <button type="button" id="btn-toggle-ai-chat" onclick="toggleAIChatPanel()" class="px-2.5 py-1 text-xs font-semibold text-zinc-200 bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 rounded-lg flex items-center gap-1.5 transition shadow-sm" title="Sağdaki AI Soru Sorma Panelini Aç / Gizle">
                    <i data-lucide="sparkles" class="w-3.5 h-3.5 text-zinc-300"></i>
                    <span class="hidden md:inline">AI Soru Paneli</span>
                </button>

                <!-- Font Size -->
                <div class="flex items-center bg-zinc-900 rounded-lg p-0.5 border border-zinc-800">
                    <button onclick="changeReaderFontSize('sm')" class="px-2 py-1 text-[10px] font-bold text-zinc-400 hover:text-white rounded">A-</button>
                    <button onclick="changeReaderFontSize('md')" class="px-2 py-1 text-[10px] font-bold text-white bg-zinc-800 rounded">A</button>
                    <button onclick="changeReaderFontSize('lg')" class="px-2 py-1 text-[10px] font-bold text-zinc-400 hover:text-white rounded">A+</button>
                </div>

                <!-- Kopyala -->
                <button onclick="copyReaderContent()" title="Not Metnini Kopyala" class="p-2 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-800 transition">
                    <i data-lucide="copy" class="w-4 h-4"></i>
                </button>

                <!-- Yazdır -->
                <button onclick="printReaderContent()" title="Yazdır" class="p-2 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-800 transition">
                    <i data-lucide="printer" class="w-4 h-4"></i>
                </button>

                <!-- Anlık Popup İçinde AI İnceleme Butonu (Lüks Beyaz / Titanyum) -->
                <button type="button" id="reader-ai-btn" onclick="triggerReaderAIAnalysis()" class="px-3.5 py-1.5 rounded-lg bg-zinc-100 hover:bg-white text-zinc-950 text-xs font-bold flex items-center gap-1.5 transition shadow-[0_0_15px_rgba(255,255,255,0.08)] border border-white/20">
                    <i data-lucide="sparkles" class="w-3.5 h-3.5 text-zinc-900"></i>
                    <span id="reader-ai-btn-text" class="hidden md:inline">AI İncele & Çözüm Üret</span>
                </button>

                <!-- Kapat -->
                <button onclick="closeModal('modal-note-reader')" class="p-2 rounded-lg bg-zinc-900 text-zinc-400 hover:text-white border border-zinc-800 transition">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
        </div>

        <!-- Reader Main Body: 3 Bölümlü Tam Ekran Çalışma Alanı -->
        <div id="reader-main-grid" class="flex-1 min-h-0 flex flex-col md:flex-row divide-y md:divide-y-0 md:divide-x divide-zinc-800/80 overflow-hidden">
            <!-- 1. SOL PANEL: Referans PDF / Görsel Canlı Görüntüleyici -->
            <div id="reader-pdf-panel" class="flex-1 min-w-0 h-full overflow-hidden bg-black flex flex-col">
                <div class="px-4 py-2 bg-[#09090b] border-b border-zinc-800/80 flex items-center justify-between text-xs flex-shrink-0">
                    <span class="text-zinc-300 flex items-center gap-1.5 font-medium truncate">
                        <i data-lucide="paperclip" class="w-3.5 h-3.5 text-zinc-400"></i>
                        <span id="reader-pdf-name-text">Referans PDF</span>
                    </span>
                    <a id="reader-pdf-external-link" href="#" target="_blank" class="px-2.5 py-1 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-200 border border-zinc-800 text-xs font-semibold flex items-center gap-1 transition">
                        <i data-lucide="external-link" class="w-3 h-3"></i>
                        <span>Yeni Sekmede Aç</span>
                    </a>
                </div>
                <div class="flex-1 min-h-0 relative bg-black" id="reader-pdf-frame-wrapper">
                    <!-- Dinamik iframe veya img yüklenir -->
                </div>
            </div>

            <!-- 2. ORTA PANEL: Not & AI Çözümleri / Konu Özeti (Temiz Okuma & LaTeX Formülleri) -->
            <div id="reader-note-panel" class="flex-1 min-w-0 h-full overflow-y-auto p-6 md:p-8 bg-[#08080a]">
                <div id="reader-rendered-content" class="ai-markdown-content font-size-md"></div>
            </div>

            <!-- 3. SAĞ PANEL: Bağımsız AI Çalışma & Soru Sorma Paneli (Dedicated Q&A Sidebar) -->
            <div id="reader-ai-chat-panel" class="w-full md:w-80 lg:w-96 flex-shrink-0 h-full bg-[#09090b] flex flex-col border-t md:border-t-0 md:border-l border-zinc-800/80">
                <!-- AI Panel Header -->
                <div class="px-3.5 py-2.5 bg-[#0e0e12] border-b border-zinc-800 flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg bg-zinc-800 text-zinc-200 flex items-center justify-center border border-zinc-700">
                            <i data-lucide="bot" class="w-3.5 h-3.5"></i>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-white flex items-center gap-1.5">
                                <span>AI Soru Paneli</span>
                            </h4>
                            <span class="text-[10px] text-zinc-400">PDF & Özet Odaklı Q&A</span>
                        </div>
                    </div>
                    <button type="button" onclick="clearReaderQAMessages()" title="Sohbeti Temizle" class="p-1.5 rounded-lg text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800 transition">
                        <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                    </button>
                </div>

                <!-- Scrollable Q&A Messages Area -->
                <div id="reader-chat-scroll-container" class="flex-1 overflow-y-auto p-3.5 space-y-3.5">
                    <!-- Boş Durum Karşılama Kartı -->
                    <div id="reader-chat-empty-state" class="p-4 rounded-xl bg-zinc-900/50 border border-zinc-800/80 text-center space-y-2">
                        <div class="w-8 h-8 rounded-full bg-zinc-800 text-zinc-300 flex items-center justify-center mx-auto border border-zinc-700">
                            <i data-lucide="help-circle" class="w-4 h-4"></i>
                        </div>
                        <h5 class="text-xs font-bold text-zinc-200">Çalışırken Soru Sor</h5>
                        <p class="text-[11px] text-zinc-400 leading-relaxed">
                            PDF ve özet üzerindeki formülleri, çözümleri veya ek soru taleplerini sorabilirsin.
                        </p>
                    </div>

                    <!-- Mesajlar Listesi -->
                    <div id="reader-qa-messages" class="space-y-3"></div>
                </div>

                <!-- Alt Sabit Soru Sorma Çubuğu -->
                <div class="p-3 bg-[#0e0e12] border-t border-zinc-800 flex-shrink-0 space-y-2 backdrop-blur-md">
                    <!-- Hızlı Öneri Çipleri -->
                    <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-[10px] scrollbar-none">
                        <button type="button" onclick="setReaderQuickPrompt('Bu konudan vize/finalde gelebilecek 1 tane daha zor ve tipik sayısal sınav sorusu çöz.')" class="px-2.5 py-1 rounded-md bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-750 whitespace-nowrap transition flex items-center gap-1">
                            <span>💡 Zor Soru</span>
                        </button>
                        <button type="button" onclick="setReaderQuickPrompt('Buradaki temel formülleri ve değişkenlerin anlamlarını adım adım özetle.')" class="px-2.5 py-1 rounded-md bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-750 whitespace-nowrap transition flex items-center gap-1">
                            <span>📐 Formüller</span>
                        </button>
                        <button type="button" onclick="setReaderQuickPrompt('Hoca bu konudan sınavda en çok nereden yanıltıcı/tuzak soru sorabilir?')" class="px-2.5 py-1 rounded-md bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-750 whitespace-nowrap transition flex items-center gap-1">
                            <span>⚠️ Tuzaklar</span>
                        </button>
                        <button type="button" onclick="setReaderQuickPrompt('Vize öncesi hızlı tekrar için hap özet ve akılda kalıcı maddeler çıkar.')" class="px-2.5 py-1 rounded-md bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-750 whitespace-nowrap transition flex items-center gap-1">
                            <span>📝 Hap Özet</span>
                        </button>
                    </div>

                    <!-- Soru Giriş Alanı -->
                    <div class="flex items-end gap-1.5">
                        <div class="relative flex-1">
                            <textarea id="reader-custom-question" rows="2" onkeydown="handleReaderQuestionKey(event)" placeholder="PDF ve özete dair soru sor..." class="w-full bg-[#050505] border border-zinc-800 focus:border-zinc-500 rounded-xl px-3 py-2 text-xs text-white placeholder-zinc-500 resize-none focus:outline-none transition max-h-24 shadow-inner"></textarea>
                        </div>
                        <button type="button" id="btn-reader-ask" onclick="submitReaderCustomQuestion()" class="p-2.5 rounded-xl bg-zinc-100 hover:bg-white text-zinc-950 font-bold text-xs flex items-center justify-center transition flex-shrink-0 shadow-sm border border-white/20" title="Sor">
                            <i data-lucide="send" class="w-4 h-4 text-zinc-950"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 📁 MODAL: KLASÖR İÇERİĞİ VE BÖLÜM GEZGİNİ -->
<div id="modal-folder-view" class="fixed inset-0 z-50 bg-black/75 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="glass-panel rounded-2xl p-6 md:p-8 max-w-4xl w-full max-h-[90vh] overflow-y-auto border border-amber-500/30 shadow-2xl space-y-5">
        <!-- Klasör Üst Başlığı -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-slate-800 gap-3">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center border border-amber-500/30 flex-shrink-0">
                    <i data-lucide="folder-open" class="w-6 h-6"></i>
                </div>
                <div>
                    <h3 id="folder-modal-title" class="text-base font-bold text-white"></h3>
                    <p class="text-xs text-slate-400 flex items-center gap-2 mt-0.5">
                        <span id="folder-modal-course" class="font-semibold text-sky-400"></span>
                        <span>•</span>
                        <span id="folder-modal-count" class="text-amber-300 font-bold"></span>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <div class="relative">
                    <i data-lucide="search" class="w-3.5 h-3.5 absolute left-3 top-2.5 text-slate-400"></i>
                    <input type="text" id="folder-search-input" onkeyup="filterFolderParts(this.value)" placeholder="Bölüm veya sayfa ara..." class="bg-slate-900 border border-slate-800 rounded-xl pl-8 pr-3 py-1.5 text-xs text-white focus:outline-none focus:border-amber-500 w-44">
                </div>
                <button onclick="closeModal('modal-folder-view')" class="p-2 rounded-lg bg-slate-800 text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
        </div>

        <!-- Bölümler Listesi Grid -->
        <div id="folder-parts-container" class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <!-- JS ile dinamik doldurulur -->
        </div>

        <!-- Alt Kısım / Toplu İşlem -->
        <div class="pt-4 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3 text-xs">
            <span class="text-slate-400">Bu klasördeki herhangi bir bölüme tıklayarak doğrudan PDF açabilir veya Gemini AI analizi başlatabilirsiniz.</span>
            <button type="button" onclick="closeModal('modal-folder-view')" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold">Kapat</button>
        </div>
    </div>
</div>

<!-- Modal: Not Ekle / Düzenle (Akıllı PDF Bölücü & Dosya Yükleyici) -->
<div id="modal-note-editor" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="glass-panel rounded-2xl p-6 md:p-8 max-w-2xl w-full max-h-[90vh] overflow-y-auto border border-slate-800 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-5">
            <h3 id="modal-note-title" class="text-base font-bold text-white flex items-center gap-2">
                <i data-lucide="file-plus" class="w-5 h-5 text-sky-400"></i>
                <span id="modal-note-heading">Yeni Not Yaz</span>
            </h3>
            <button onclick="closeModal('modal-note-editor')" class="text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>

        <form action="notes.php" method="POST" enctype="multipart/form-data" id="form-note-main" class="space-y-4" onsubmit="handleNoteFormSubmit(event)">
            <input type="hidden" name="action" value="save_note">
            <input type="hidden" name="note_id" id="form-note-id" value="0">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Not Başlığı *</label>
                <input type="text" name="title" id="form-note-title" required placeholder="Örn: Algoritmalar Vize Konu Özeti / Hafta 4 Notları" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">İlgili Ders</label>
                    <select name="course_id" id="form-note-course" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                        <option value="">-- Genel / Ders Yok --</option>
                        <?php foreach ($myCourses as $mc): ?>
                            <option value="<?= $mc['id'] ?>"><?= e($mc['course_code']) ?> - <?= e($mc['course_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">İlgili Proje</label>
                    <select name="project_id" id="form-note-project" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                        <option value="">-- Proje Yok --</option>
                        <?php foreach ($myProjects as $mp): ?>
                            <option value="<?= $mp['id'] ?>"><?= e($mp['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Not Metni / Açıklaması</label>
                <textarea name="content" id="form-note-content" rows="4" placeholder="Ders notlarını, formülleri veya özet bilgileri buraya yazabilirsin..." class="w-full bg-slate-900 border border-slate-800 rounded-xl p-3 text-xs text-white focus:outline-none focus:border-sky-500 font-sans"></textarea>
            </div>

            <!-- PDF veya Görsel Yükleme Alanı -->
            <div class="p-4 rounded-xl bg-slate-900/80 border border-slate-800 space-y-3">
                <label class="block text-xs font-semibold text-slate-300 flex items-center justify-between">
                    <span class="flex items-center gap-1.5">
                        <i data-lucide="paperclip" class="w-3.5 h-3.5 text-sky-400"></i>
                        <span>Ders Notu / PDF / PowerPoint Sunumu (PPTX)</span>
                    </span>
                    <span class="text-[10px] text-slate-500 font-normal">PDF, PPTX, PPT, JPG, PNG</span>
                </label>
                
                <div class="relative">
                    <input type="file" name="note_file" id="form-note-file" accept=".pdf,.pptx,.ppt,image/jpeg,image/png,image/webp" onchange="handleFileSelection(this)" class="block w-full text-xs text-slate-400 file:mr-3 file:py-2 file:px-3.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-800 file:text-sky-300 hover:file:bg-slate-700 cursor-pointer">
                </div>
                
                <!-- Seçilen dosya durumu -->
                <div id="selected-file-info" class="hidden text-xs text-sky-400 flex items-center justify-between pt-1">
                    <div class="flex items-center gap-1.5">
                        <i data-lucide="check-circle" class="w-3.5 h-3.5 text-emerald-400 flex-shrink-0"></i>
                        <span id="selected-file-name-text"></span>
                    </div>
                </div>

                <!-- ⚡ Otomatik PDF Bölücü & Sayfa Ayıklayıcı Paneli -->
                <div id="pdf-splitter-panel" class="hidden p-4 rounded-xl bg-slate-950 border border-sky-500/30 space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-lg bg-sky-500/20 text-sky-400 flex items-center justify-center font-bold text-xs">
                                <i data-lucide="scissors" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <span class="text-xs font-bold text-white">Akıllı & Otomatik PDF Parçalayıcı</span>
                                <span class="block text-[10px] text-slate-400" id="pdf-total-pages-text">Toplam Sayfa: Hesaplanıyor...</span>
                            </div>
                        </div>
                        <span class="text-[10px] px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 font-bold border border-emerald-500/30">Tarayıcıda 0 Sn'de Ayrışır</span>
                    </div>

                    <!-- Bölme Modu Sekmeleri -->
                    <div class="flex border-b border-slate-800 text-xs">
                        <button type="button" onclick="switchPdfSplitTab('bulk')" id="tab-btn-bulk" class="pb-2 px-3 font-semibold text-sky-400 border-b-2 border-sky-500 flex items-center gap-1.5 transition">
                            <i data-lucide="layers" class="w-3.5 h-3.5"></i>
                            <span>⚡ Tüm Kitabı Otomatik Parçala (Toplu Kayıt)</span>
                        </button>
                        <button type="button" onclick="switchPdfSplitTab('single')" id="tab-btn-single" class="pb-2 px-3 font-semibold text-slate-400 hover:text-slate-200 border-b-2 border-transparent flex items-center gap-1.5 transition">
                            <i data-lucide="scissors" class="w-3.5 h-3.5"></i>
                            <span>🎯 Özel Sayfa Aralığı Ayıkla (Tek Not)</span>
                        </button>
                    </div>

                    <!-- Sekme 1: Tüm Kitabı Otomatik Parçalara Bölüp Ayrı Notlar Olarak Kaydetme -->
                    <div id="split-tab-bulk" class="space-y-3">
                        <p class="text-[11px] text-slate-300 leading-relaxed">
                            Bu PDF'i (örn. 240+ MB) <strong>otomatik olarak 10'ar sayfalık parçalara</strong> böler ve her parçayı dersin altına ayrı bir modül/not olarak kaydeder. <strong>Gemini AI</strong> ve sunucu limitlerine (40 MB) tam uyumlu, en hafif formata gelir!
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-400 mb-1">Parça Başına Sayfa</label>
                                <select id="bulk-chunk-size" onchange="calculateBulkPreview()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-sky-500">
                                    <option value="5">5 Sayfa (~8-10 MB - En Hızlı & Güvenli)</option>
                                    <option value="8" selected>8 Sayfa (~12-15 MB - Tavsiye Edilen)</option>
                                    <option value="10">10 Sayfa (~16-20 MB)</option>
                                    <option value="15">15 Sayfa (~25-30 MB)</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2 flex flex-col justify-end">
                                <span class="text-[11px] text-sky-300 font-medium" id="bulk-chunks-summary">Hesaplanıyor...</span>
                                <span class="text-[10px] text-slate-500 truncate" id="bulk-chunks-detail"></span>
                            </div>
                        </div>

                        <!-- Toplu Bölme İlerleme Çubuğu -->
                        <div id="bulk-progress-container" class="hidden space-y-2 p-3 rounded-lg bg-slate-900 border border-sky-500/30">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-white font-semibold flex items-center gap-1.5" id="bulk-progress-title">
                                    <i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin text-sky-400"></i>
                                    <span>Parçalanıyor & Kaydediliyor...</span>
                                </span>
                                <span class="text-sky-400 font-mono font-bold" id="bulk-progress-percent">%0</span>
                            </div>
                            <div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden">
                                <div id="bulk-progress-bar" class="bg-gradient-to-r from-sky-500 to-indigo-500 h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                            <span class="text-[10px] text-slate-400 block" id="bulk-progress-status">Başlatılıyor...</span>
                        </div>

                        <button type="button" onclick="startBulkPdfSplit()" id="btn-start-bulk" class="w-full py-2 px-3 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white font-bold text-xs transition flex items-center justify-center gap-2 shadow-sm">
                            <i data-lucide="zap" class="w-4 h-4"></i>
                            <span id="btn-start-bulk-text">🚀 Tüm Kitabı Otomatik Böl ve Sisteme Aktar</span>
                        </button>
                    </div>

                    <!-- Sekme 2: Özel Sayfa Aralığı Ayıkla -->
                    <div id="split-tab-single" class="hidden space-y-3">
                        <p class="text-[11px] text-slate-300 leading-relaxed">
                            Yalnızca çalışacağınız üniteyi veya sınav sayfalarını seçin. Dosya anında <strong>küçük bir PDF (~2 MB)</strong> olarak ayıklanıp bu nota eklenir.
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-400 mb-1">Sayfa Aralığı (Örn: 1-30 veya 45-75)</label>
                                <input type="text" id="pdf-page-range" value="1-30" placeholder="1-30" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-sky-500 font-mono">
                            </div>
                            <div class="flex items-end">
                                <button type="button" onclick="splitSelectedPDF()" id="btn-split-pdf" class="w-full py-1.5 px-3 rounded-lg bg-sky-600 hover:bg-sky-500 text-white font-bold text-xs transition flex items-center justify-center gap-1.5 shadow-sm">
                                    <i data-lucide="scissors" class="w-3.5 h-3.5"></i>
                                    <span id="btn-split-text">Seçilen Sayfaları Ayıkla & Bu Nota Ekle</span>
                                </button>
                            </div>
                        </div>

                        <div id="split-success-info" class="hidden p-2 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-[11px] flex items-center gap-1.5">
                            <i data-lucide="check" class="w-4 h-4 text-emerald-400"></i>
                            <span id="split-success-msg"></span>
                        </div>
                    </div>
                </div>

                <!-- Mevcut dosya bilgisi ve silme kutucuğu -->
                <div id="existing-file-box" class="hidden pt-2 border-t border-slate-800 flex items-center justify-between text-xs">
                    <span id="existing-file-name" class="text-slate-300 font-medium truncate max-w-[280px]"></span>
                    <label class="flex items-center gap-1.5 text-[11px] text-red-400 cursor-pointer">
                        <input type="checkbox" name="remove_file" id="form-remove-file" class="rounded bg-slate-900 border-slate-800">
                        <span>Bu Dosyayı Kaldır</span>
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Etiketler (Virgülle ayırın)</label>
                    <input type="text" name="tags" id="form-note-tags" placeholder="vize, hafta1, algoritmalar" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div class="flex items-center pt-6">
                    <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-300">
                        <input type="checkbox" name="is_pinned" id="form-note-pinned" class="w-4 h-4 rounded text-sky-600 bg-slate-900 border-slate-800">
                        <span>Başa Sabitle (Pin)</span>
                    </label>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-800 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-note-editor')" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold">İptal</button>
                <button type="submit" id="btn-save-note" class="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold flex items-center gap-2">
                    <span id="btn-save-text">Kaydet</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let loadedPdfBytes = null;
let originalSelectedPdfFile = null;
let parsedPdfTotalPages = 0;

function switchPdfSplitTab(tab) {
    const tabBulk = document.getElementById('split-tab-bulk');
    const tabSingle = document.getElementById('split-tab-single');
    const btnBulk = document.getElementById('tab-btn-bulk');
    const btnSingle = document.getElementById('tab-btn-single');

    if (tab === 'bulk') {
        tabBulk.classList.remove('hidden');
        tabSingle.classList.add('hidden');
        btnBulk.className = "pb-2 px-3 font-semibold text-sky-400 border-b-2 border-sky-500 flex items-center gap-1.5 transition";
        btnSingle.className = "pb-2 px-3 font-semibold text-slate-400 hover:text-slate-200 border-b-2 border-transparent flex items-center gap-1.5 transition";
    } else {
        tabBulk.classList.add('hidden');
        tabSingle.classList.remove('hidden');
        btnSingle.className = "pb-2 px-3 font-semibold text-sky-400 border-b-2 border-sky-500 flex items-center gap-1.5 transition";
        btnBulk.className = "pb-2 px-3 font-semibold text-slate-400 hover:text-slate-200 border-b-2 border-transparent flex items-center gap-1.5 transition";
    }
    if (window.lucide) lucide.createIcons();
}

function openNewNoteModal() {
    document.getElementById('modal-note-heading').innerText = 'Yeni Not & Döküman Yaz';
    document.getElementById('form-note-id').value = '0';
    document.getElementById('form-note-title').value = '';
    document.getElementById('form-note-content').value = '';
    document.getElementById('form-note-course').value = '';
    document.getElementById('form-note-project').value = '';
    document.getElementById('form-note-tags').value = '';
    document.getElementById('form-note-pinned').checked = false;
    document.getElementById('form-note-file').value = '';
    document.getElementById('selected-file-info').classList.add('hidden');
    document.getElementById('pdf-splitter-panel').classList.add('hidden');
    document.getElementById('split-success-info').classList.add('hidden');
    document.getElementById('bulk-progress-container').classList.add('hidden');
    document.getElementById('existing-file-box').classList.add('hidden');
    document.getElementById('form-remove-file').checked = false;
    loadedPdfBytes = null;
    originalSelectedPdfFile = null;
    parsedPdfTotalPages = 0;
    resetSaveButton();

    openModal('modal-note-editor');
}

function openEditNote(note) {
    document.getElementById('modal-note-heading').innerText = 'Notu Düzenle';
    document.getElementById('form-note-id').value = note.id;
    document.getElementById('form-note-title').value = note.title;
    document.getElementById('form-note-content').value = note.content;
    document.getElementById('form-note-course').value = note.course_id || '';
    document.getElementById('form-note-project').value = note.project_id || '';
    document.getElementById('form-note-tags').value = note.tags || '';
    document.getElementById('form-note-pinned').checked = (note.is_pinned == 1);
    document.getElementById('form-note-file').value = '';
    document.getElementById('selected-file-info').classList.add('hidden');
    document.getElementById('pdf-splitter-panel').classList.add('hidden');
    document.getElementById('split-success-info').classList.add('hidden');
    document.getElementById('bulk-progress-container').classList.add('hidden');
    loadedPdfBytes = null;
    originalSelectedPdfFile = null;
    parsedPdfTotalPages = 0;
    resetSaveButton();

    const fileBox = document.getElementById('existing-file-box');
    const fileNameSpan = document.getElementById('existing-file-name');
    const removeFileCheck = document.getElementById('form-remove-file');
    removeFileCheck.checked = false;

    if (note.file_path) {
        fileBox.classList.remove('hidden');
        fileNameSpan.innerText = '📎 ' + (note.file_name || 'Ekli Dosya');
    } else {
        fileBox.classList.add('hidden');
    }

    openModal('modal-note-editor');
}

async function handleFileSelection(input) {
    const infoBox = document.getElementById('selected-file-info');
    const textSpan = document.getElementById('selected-file-name-text');
    const splitterPanel = document.getElementById('pdf-splitter-panel');
    const totalPagesSpan = document.getElementById('pdf-total-pages-text');
    const splitSuccess = document.getElementById('split-success-info');

    splitSuccess.classList.add('hidden');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        originalSelectedPdfFile = file;
        const sizeMB = (file.size / (1024 * 1024)).toFixed(1);
        textSpan.innerText = `Seçildi: ${file.name} (${sizeMB} MB)`;
        infoBox.classList.remove('hidden');

        // Otomatik başlık öner
        const titleInput = document.getElementById('form-note-title');
        if (!titleInput.value.trim()) {
            titleInput.value = file.name.replace(/\.[^/.]+$/, "").replace(/_/g, ' ');
        }

        const isPpt = file.name.toLowerCase().endsWith('.pptx') || file.name.toLowerCase().endsWith('.ppt');
        const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');

        if (isPpt) {
            textSpan.innerHTML = `📊 <span class="font-bold text-amber-300">PowerPoint Sunumu Seçildi:</span> ${file.name} (${sizeMB} MB)<br><span class="text-[10px] text-emerald-400 font-semibold block pt-0.5">⚡ Sunum slaytları kaydedilirken sunucuda otomatik yüksek kaliteli PDF'e dönüştürülecektir.</span>`;
            splitterPanel.classList.add('hidden');
            loadedPdfBytes = null;
        } else if (isPdf) {
            splitterPanel.classList.remove('hidden');
            totalPagesSpan.innerText = 'PDF Sayfaları okunuyor...';

            try {
                const arrayBuffer = await file.arrayBuffer();
                loadedPdfBytes = arrayBuffer;
                const pdfDoc = await PDFLib.PDFDocument.load(arrayBuffer, { ignoreEncryption: true });
                parsedPdfTotalPages = pdfDoc.getPageCount();
                totalPagesSpan.innerText = `Toplam: ${parsedPdfTotalPages} Sayfa (${sizeMB} MB)`;
                
                // Tekil aralık ayarla
                const maxRange = Math.min(30, parsedPdfTotalPages);
                document.getElementById('pdf-page-range').value = `1-${maxRange}`;

                // Toplu mod hesaplamasını güncelle
                calculateBulkPreview();
            } catch (err) {
                totalPagesSpan.innerText = `PDF yüklendi (${sizeMB} MB)`;
            }
        } else {
            splitterPanel.classList.add('hidden');
            loadedPdfBytes = null;
        }
    } else {
        infoBox.classList.add('hidden');
        splitterPanel.classList.add('hidden');
    }
    if (window.lucide) lucide.createIcons();
}

function calculateBulkPreview() {
    if (!parsedPdfTotalPages) return;
    const chunkSize = parseInt(document.getElementById('bulk-chunk-size').value, 10) || 10;
    const totalChunks = Math.ceil(parsedPdfTotalPages / chunkSize);
    
    document.getElementById('bulk-chunks-summary').innerText = `Toplam ${totalChunks} Parçaya Bölünecek`;
    document.getElementById('bulk-chunks-detail').innerText = `Her parça ${chunkSize} sayfa (Örn: 1-${Math.min(chunkSize, parsedPdfTotalPages)}, ${chunkSize+1}-${Math.min(chunkSize*2, parsedPdfTotalPages)}...)`;
}

// 🚀 TÜM KİTABI OTOMATİK PARÇALARA BÖLÜP AJAX İLE VERİTABANINA AYRI NOTLAR OLARAK EKLEME
async function startBulkPdfSplit() {
    if (!loadedPdfBytes || !originalSelectedPdfFile) {
        showToast('warning', 'Lütfen önce geçerli bir PDF dosyası seçin.');
        return;
    }

    const titleBase = document.getElementById('form-note-title').value.trim() || originalSelectedPdfFile.name.replace(/\.[^/.]+$/, "");
    const courseId = document.getElementById('form-note-course').value;
    const projectId = document.getElementById('form-note-project').value;
    const tags = document.getElementById('form-note-tags').value;
    const userChunkSize = parseInt(document.getElementById('bulk-chunk-size').value, 10) || 8;

    const btn = document.getElementById('btn-start-bulk');
    const btnText = document.getElementById('btn-start-bulk-text');
    const progressContainer = document.getElementById('bulk-progress-container');
    const progressBar = document.getElementById('bulk-progress-bar');
    const progressPercent = document.getElementById('bulk-progress-percent');
    const progressStatus = document.getElementById('bulk-progress-status');

    btn.disabled = true;
    progressContainer.classList.remove('hidden');
    progressStatus.className = "text-[10px] text-slate-400 block";

    try {
        progressStatus.innerText = 'PDF belgesi analiz ediliyor...';
        const srcDoc = await PDFLib.PDFDocument.load(loadedPdfBytes, { ignoreEncryption: true });
        const totalPages = srcDoc.getPageCount();

        if (totalPages <= 0) {
            throw new Error('PDF dosyasında geçerli sayfa bulunamadı.');
        }

        // Dilim aralıklarını hazırla
        const sliceRanges = [];
        let currStart = 1;
        while (currStart <= totalPages) {
            let currEnd = Math.min(currStart + userChunkSize - 1, totalPages);
            sliceRanges.push({ start: currStart, end: currEnd });
            currStart = currEnd + 1;
        }

        const totalChunks = sliceRanges.length;

        for (let i = 0; i < totalChunks; i++) {
            const range = sliceRanges[i];
            const startPage = range.start;
            const endPage = range.end;
            const partNum = i + 1;

            const basePct = Math.round((i / totalChunks) * 100);
            const midPct = Math.round(((i + 0.4) / totalChunks) * 100);
            const uploadPct = Math.round(((i + 0.8) / totalChunks) * 100);

            progressBar.style.width = `${basePct}%`;
            progressPercent.innerText = `%${basePct}`;
            progressStatus.innerText = `Bölüm ${partNum}/${totalChunks} (s. ${startPage}-${endPage}) ayıklanıyor...`;
            await new Promise(resolve => setTimeout(resolve, 50));

            const pageIndices = [];
            for (let p = startPage - 1; p < endPage; p++) {
                if (p >= 0 && p < totalPages) {
                    pageIndices.push(p);
                }
            }

            if (pageIndices.length === 0) continue;

            // Sayfaları ayıkla
            const newDoc = await PDFLib.PDFDocument.create();
            const copiedPages = await newDoc.copyPages(srcDoc, pageIndices);
            copiedPages.forEach(p => newDoc.addPage(p));

            progressBar.style.width = `${midPct}%`;
            progressPercent.innerText = `%${midPct}`;
            progressStatus.innerText = `Bölüm ${partNum}/${totalChunks} (s. ${startPage}-${endPage}) optimize ediliyor...`;
            await new Promise(resolve => setTimeout(resolve, 50));

            const sliceBytes = await newDoc.save();
            const sliceBlob = new Blob([sliceBytes], { type: 'application/pdf' });
            const sliceSizeMB = (sliceBlob.size / (1024 * 1024)).toFixed(1);

            progressBar.style.width = `${uploadPct}%`;
            progressPercent.innerText = `%${uploadPct}`;
            progressStatus.innerText = `Bölüm ${partNum}/${totalChunks} (s. ${startPage}-${endPage}, ${sliceSizeMB} MB) kaydediliyor...`;

            const sliceFileName = `part_${partNum}_pages_${startPage}_to_${endPage}.pdf`;
            const sliceFile = new File([sliceBlob], sliceFileName, { type: 'application/pdf' });

            const formData = new FormData();
            formData.append('title', `${titleBase} - Bölüm ${partNum} (s. ${startPage}-${endPage})`);
            formData.append('content', `Otomatik ayıklanan ders notu parçası: Sayfa ${startPage} - ${endPage} (${pageIndices.length} sayfa, ${sliceSizeMB} MB).`);
            formData.append('course_id', courseId);
            formData.append('project_id', projectId);
            formData.append('tags', tags ? `${tags}, bolum${partNum}` : `bolum${partNum}`);
            formData.append('note_file', sliceFile);

            const resp = await fetch('api/upload_split_note.php', {
                method: 'POST',
                body: formData
            });

            const respText = await resp.text();
            let resData;
            try {
                const jsonMatch = respText.match(/\{[\s\S]*\}/);
                if (jsonMatch) {
                    resData = JSON.parse(jsonMatch[0]);
                } else {
                    throw new Error(respText.replace(/<[^>]*>/g, '').trim());
                }
            } catch (e) {
                throw new Error(respText.replace(/<[^>]*>/g, '').trim() || 'Sunucudan geçersiz yanıt alındı.');
            }

            if (!resData || !resData.success) {
                throw new Error((resData && resData.error) ? resData.error : `Bölüm ${partNum} sunucuya kaydedilirken hata oluştu.`);
            }

            const donePercent = Math.round(((i + 1) / totalChunks) * 100);
            progressBar.style.width = `${donePercent}%`;
            progressPercent.innerText = `%${donePercent}`;
            progressStatus.innerText = `Bölüm ${partNum}/${totalChunks} kaydedildi (${pageIndices.length} sayfa, ${sliceSizeMB} MB) ✓`;
            await new Promise(resolve => setTimeout(resolve, 80));
        }

        progressBar.style.width = '100%';
        progressPercent.innerText = '%100';
        progressStatus.innerText = `🎉 Tebrikler! Tüm ${totalChunks} bölüm başarıyla oluşturuldu.`;
        progressStatus.className = "text-[11px] text-emerald-400 font-bold block";

        closeModal('modal-note-editor');

        await Swal.fire({
            icon: 'success',
            title: 'Tüm Kitap Başarıyla Bölündü! 🎉',
            text: `Toplam ${totalChunks} adet ders notu parçası oluşturuldu ve Gemini AI analizine hazırlandı!`,
            background: '#131b2e',
            color: '#f8fafc',
            confirmButtonColor: '#0284c7'
        });

        window.location.reload();

    } catch (err) {
        console.error(err);
        progressStatus.innerText = '❌ Hata: ' + err.message;
        progressStatus.className = "text-[11px] text-rose-400 font-semibold block";
        btn.disabled = false;
        btnText.innerText = '🚀 Yeniden Dene';

        Swal.fire({
            icon: 'error',
            title: 'Bölme Sırasında Bir Hata Oluştu',
            text: err.message,
            background: '#131b2e',
            color: '#f8fafc',
            confirmButtonColor: '#0284c7'
        });
    }
}

// Seçilen Sayfa Aralığını Tarayıcı İçinde 1 Saniyede Bölüp Tekil Forma Dosya Yapma
async function splitSelectedPDF() {
    if (!loadedPdfBytes) {
        showToast('warning', 'Lütfen önce bir PDF dosyası seçin.');
        return;
    }

    const rangeStr = document.getElementById('pdf-page-range').value.trim();
    const btn = document.getElementById('btn-split-pdf');
    const btnText = document.getElementById('btn-split-text');
    const successBox = document.getElementById('split-success-info');
    const successMsg = document.getElementById('split-success-msg');
    const fileInput = document.getElementById('form-note-file');

    btn.disabled = true;
    btnText.innerText = 'Sayfalar Ayıklanıyor...';

    try {
        const srcDoc = await PDFLib.PDFDocument.load(loadedPdfBytes, { ignoreEncryption: true });
        const totalPages = srcDoc.getPageCount();
        const newDoc = await PDFLib.PDFDocument.create();

        // Sayfa aralıklarını çözümle (Örn: "1-30" veya "1,2,5-10")
        const pagesToExtract = parsePageRange(rangeStr, totalPages);

        if (pagesToExtract.length === 0) {
            showToast('error', 'Geçerli bir sayfa aralığı giriniz (Örn: 1-25).');
            btn.disabled = false;
            btnText.innerText = 'Seçilen Sayfaları Ayıkla & Bu Nota Ekle';
            return;
        }

        // Sayfaları kopyala
        const copiedPages = await newDoc.copyPages(srcDoc, pagesToExtract);
        copiedPages.forEach(p => newDoc.addPage(p));

        const newPdfBytes = await newDoc.save();
        const newBlob = new Blob([newPdfBytes], { type: 'application/pdf' });
        const newSizeMB = (newBlob.size / (1024 * 1024)).toFixed(2);

        // Yeni oluşturulan küçük PDF dosyasını HTML file input'a bağla (DataTransfer API)
        const baseName = originalSelectedPdfFile ? originalSelectedPdfFile.name.replace(/\.[^/.]+$/, "") : "Ders_Notu";
        const newFileName = `${baseName}_Sayfa_${rangeStr}.pdf`;
        const newFile = new File([newBlob], newFileName, { type: 'application/pdf' });

        const dt = new DataTransfer();
        dt.items.add(newFile);
        fileInput.files = dt.files;

        // UI güncelle
        document.getElementById('selected-file-name-text').innerText = `Seçildi (Ayıklandı): ${newFileName} (${newSizeMB} MB)`;
        successMsg.innerText = `Harika! ${pagesToExtract.length} sayfa ayıklandı ve yeni PDF (${newSizeMB} MB) olarak hazırlandı. Şimdi kaydedebilirsiniz.`;
        successBox.classList.remove('hidden');
        showToast('success', `${pagesToExtract.length} sayfa başarıyla ayrıldı!`);

        btn.disabled = false;
        btnText.innerText = 'Yeniden Ayıkla';
    } catch (err) {
        console.error(err);
        showToast('error', 'PDF ayıklanırken hata oluştu: ' + err.message);
        btn.disabled = false;
        btnText.innerText = 'Seçilen Sayfaları Ayıkla & Bu Nota Ekle';
    }
    if (window.lucide) lucide.createIcons();
}

function parsePageRange(rangeStr, maxPages) {
    const pages = new Set();
    const parts = rangeStr.split(',');

    parts.forEach(part => {
        part = part.trim();
        if (part.includes('-')) {
            const [start, end] = part.split('-').map(n => parseInt(n.trim(), 10));
            if (!isNaN(start) && !isNaN(end)) {
                const min = Math.max(1, Math.min(start, end));
                const max = Math.min(maxPages, Math.max(start, end));
                for (let i = min; i <= max; i++) {
                    pages.add(i - 1); // 0-indexed
                }
            }
        } else {
            const p = parseInt(part, 10);
            if (!isNaN(p) && p >= 1 && p <= maxPages) {
                pages.add(p - 1);
            }
        }
    });

    return Array.from(pages).sort((a, b) => a - b);
}

function handleNoteFormSubmit(e) {
    const fileInput = document.getElementById('form-note-file');
    const saveBtn = document.getElementById('btn-save-note');
    const saveText = document.getElementById('btn-save-text');

    if (fileInput.files && fileInput.files[0]) {
        const file = fileInput.files[0];
        const sizeMB = (file.size / (1024 * 1024)).toFixed(1);
        const isPpt = file.name.toLowerCase().endsWith('.pptx') || file.name.toLowerCase().endsWith('.ppt');
        saveBtn.disabled = true;
        
        if (isPpt) {
            saveText.innerHTML = `<span class="inline-flex items-center gap-2"><i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> PowerPoint PDF'e Dönüştürülüyor (${sizeMB} MB)...</span>`;
        } else {
            saveText.innerHTML = `<span class="inline-flex items-center gap-2"><i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Dosya Yükleniyor (${sizeMB} MB)...</span>`;
        }
        if (window.lucide) lucide.createIcons();
    }
}

function resetSaveButton() {
    const saveBtn = document.getElementById('btn-save-note');
    const saveText = document.getElementById('btn-save-text');
    saveBtn.disabled = false;
    saveText.innerText = 'Kaydet';
}

// =========================================================================
// 📖 TAM SAYFA NOT, PDF VE AI ASİSTANI OKUYUCU MOTORU (Reader View JS)
// =========================================================================
let currentReaderNote = null;
let currentReaderLayout = 'trio';

function openNoteReader(note) {
    currentReaderNote = note;
    
    // Modal'ı document.body'ye taşıyarak tüm kapsayıcı ve header sınırlarını aşmasını sağla
    const readerModal = document.getElementById('modal-note-reader');
    if (readerModal && readerModal.parentElement !== document.body) {
        document.body.appendChild(readerModal);
    }

    document.getElementById('reader-note-title').innerText = note.title;
    document.getElementById('reader-note-course').innerText = (note.course_code ? (note.course_code + ' - ' + (note.course_name || '')) : (note.project_title ? ('🚀 ' + note.project_title) : 'Ders Notu'));
    document.getElementById('reader-note-date').innerText = 'Güncelleme: ' + (note.updated_at || '');
    
    // AI buton durumunu sıfırla
    const aiBtn = document.getElementById('reader-ai-btn');
    const aiBtnText = document.getElementById('reader-ai-btn-text');
    if (aiBtn) aiBtn.disabled = false;
    if (aiBtnText) aiBtnText.innerText = note.is_ai ? 'AI Tekrar İncele' : 'AI İncele & Çözüm Üret';

    // Sağ AI Soru-Cevap panelini ve inputunu sıfırla
    const customInput = document.getElementById('reader-custom-question');
    if (customInput) customInput.value = '';

    // Not içeriğini Tokenized Markdown & LaTeX motoru ile parse et
    const contentBox = document.getElementById('reader-rendered-content');
    const rawContent = note.content || '*(Bu notta metin açıklaması bulunmuyor)*';
    renderMarkdownWithMath(contentBox, rawContent);

    // Q&A Geçmişini Yükle ve Render Et
    if (typeof note.qa_history === 'string' && note.qa_history.trim()) {
        try {
            note.qa_history = JSON.parse(note.qa_history);
        } catch (e) {
            note.qa_history = [];
        }
    } else if (!Array.isArray(note.qa_history)) {
        note.qa_history = [];
    }
    renderReaderQAHistory(note.qa_history);

    // PDF / Görsel Eki Kontrolü
    const layoutToggle = document.getElementById('reader-layout-toggle');
    const pdfPanel = document.getElementById('reader-pdf-panel');
    const frameWrapper = document.getElementById('reader-pdf-frame-wrapper');
    const pdfNameText = document.getElementById('reader-pdf-name-text');
    const externalLink = document.getElementById('reader-pdf-external-link');

    if (note.file_path) {
        layoutToggle.classList.remove('hidden');
        pdfPanel.classList.remove('hidden');
        
        // Sunucu ve cPanel uyumlu güvenli PDF servis endpoint'i
        const fileUrl = 'api/serve_file.php?note_id=' + note.id;
        const directFileUrl = '<?= getBaseUrl() ?>/' + note.file_path.replace(/^\/+/, '');
        pdfNameText.innerText = note.file_name || 'Referans Döküman';
        externalLink.href = fileUrl;

        if (note.file_type === 'image') {
            frameWrapper.innerHTML = `<div class="w-full h-full overflow-auto flex items-center justify-center p-4 bg-black/40"><img src="${fileUrl}" alt="Not Görseli" class="max-w-full max-h-full object-contain rounded-xl shadow-lg"></div>`;
        } else {
            frameWrapper.innerHTML = `
                <object data="${fileUrl}#toolbar=1&navpanes=0" type="application/pdf" class="w-full h-full border-0">
                    <iframe src="${fileUrl}#toolbar=1" class="w-full h-full border-0" title="PDF Görüntüleyici">
                        <div class="p-6 text-center text-zinc-400 space-y-3">
                            <p class="text-xs">Tarayıcınız yerleşik PDF görüntülemeyi doğrudan yükleyemedi.</p>
                            <a href="${fileUrl}" target="_blank" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold shadow-sm">
                                <span>PDF'i Yeni Sekmede Aç</span>
                            </a>
                        </div>
                    </iframe>
                </object>
            `;
        }
        setReaderLayout('trio');
    } else {
        layoutToggle.classList.add('hidden');
        pdfPanel.classList.add('hidden');
        frameWrapper.innerHTML = '';
        setReaderLayout('note');
    }

    openModal('modal-note-reader');
    if (window.lucide) lucide.createIcons();
}

// 🧠 GELİŞMİŞ TOKENİZE MARKDOWN & LATEX GÖRSELLEŞTİRME MOTORU
function renderMarkdownWithMath(container, rawMarkdown) {
    if (!container) return;
    let text = String(rawMarkdown || '');

    // 1. Dikey alt alta dizilmiş harf gruplarını (y \n e \n r \n i \n n \n e) tek kelimeye birleştir
    text = text.replace(/(?:^|\n|\s)([a-zA-ZçğıöşüÇĞİÖŞÜ])\s*\n\s*([a-zA-ZçğıöşüÇĞİÖŞÜ])(?:\s*\n\s*([a-zA-ZçğıöşüÇĞİÖŞÜ]))+/gu, function(match) {
        return match.replace(/\s*\n\s*/g, '');
    });

    // Helper: Formülleri temizleme
    function cleanMathExpression(math) {
        if (!math) return '';
        let m = String(math).replace(/\$/g, '');
        m = m.replace(/\\(?:text|operatorname)\{I_?\{?m\}?\}?/gi, '\\operatorname{Im}');
        m = m.replace(/\\(?:text|operatorname)\{R_?\{?e\}?\}?/gi, '\\operatorname{Re}');
        m = m.replace(/(\\math(?:bf|rm|it|bb|cal|frak|sf|tt|scr|normal|boldsymbol|mathbf)?\{[A-Za-z]\})\{([a-zA-Z0-9,\+\-]+)\}/g, (match, p1, p2) => `${p1}_{${p2}}`);
        m = m.replace(/(\\math(?:bf|rm|it|bb|cal|frak|sf|tt|scr|normal|boldsymbol|mathbf)?\{[A-Za-z]\})([0-9]+|[a-zA-Z]{1,4})(?=[^a-zA-Z0-9_\{\(]|$)/g, (match, p1, p2) => `${p1}_{${p2}}`);
        m = m.replace(/_+\$?([0-9a-zA-Z]+)\$?/g, (match, p1) => `_{${p1}}`);
        m = m.replace(/\{([0-9a-zA-Z]+)\$\}/g, (match, p1) => `{${p1}}`);
        m = m.replace(/\\mathbf\{\s*\}\s*$/g, '\\mathbf{Z}_{23} + \\mathbf{Z}_{31}');
        m = m.replace(/\\mathbf\{\s*\}/g, '');
        return m.trim();
    }

    // 2. Display Math ($$...$$) Tokenize Et (Büyük formüller)
    const displayTokens = [];
    text = text.replace(/\$\$([\s\S]+?)\$\$/g, function(match, inner) {
        const id = 'MATHDISPLAYTOKEN' + displayTokens.length + 'END';
        displayTokens.push(cleanMathExpression(inner));
        return '\n\n' + id + '\n\n';
    });

    // 3. Inline Math ($...$) Tokenize Et (Satır içi formüller)
    const inlineTokens = [];
    text = text.replace(/\$([^\$\n]+?)\$/g, function(match, inner) {
        const id = 'MATHINLINETOKEN' + inlineTokens.length + 'END';
        inlineTokens.push(cleanMathExpression(inner));
        return id;
    });

    // 4. Kapatılmamış tek kalmış $\mathbf{...} ifadelerini tokenize et
    text = text.replace(/\$(\\[a-zA-Z]+(?:\{[^\}]*\})*(?:_\{[^\}]*\}|_?[0-9a-zA-Z]+)?)(?=[,\.\:\;\s\)]|$)(?!\$)/g, function(match, p1) {
        const id = 'MATHINLINETOKEN' + inlineTokens.length + 'END';
        inlineTokens.push(cleanMathExpression(p1));
        return id;
    });

    // 5. Marked ile saf Markdown'ı parse et (Matematik ifadeleri token olarak korunduğundan _ asla <em>'e dönüşmez)
    let html = '';
    if (window.marked) {
        marked.setOptions({ breaks: true, gfm: true });
        html = marked.parse(text);
    } else {
        html = escapeHtml(text).replace(/\n/g, '<br>');
    }

    // 6. Display Math Token'larını split/join ile geri yükle (JS string replacement kaçış sorunlarını önler)
    displayTokens.forEach((math, i) => {
        const token = 'MATHDISPLAYTOKEN' + i + 'END';
        html = html.split('<p>' + token + '</p>').join('$$' + math + '$$');
        html = html.split('<p> ' + token + ' </p>').join('$$' + math + '$$');
        html = html.split(token).join('$$' + math + '$$');
    });

    // 7. Inline Math Token'larını split/join ile geri yükle
    inlineTokens.forEach((math, i) => {
        const token = 'MATHINLINETOKEN' + i + 'END';
        html = html.split(token).join('$' + math + '$');
    });

    container.innerHTML = html;

    // 8. Akademik kart & blok vurgulamalarını uygula
    if (typeof enhanceAcademicDom === 'function') {
        enhanceAcademicDom(container);
    }

    // 9. KaTeX Formüllerini Kusursuz Render Et
    if (window.renderMathInElement) {
        renderMathInElement(container, {
            delimiters: [
                {left: '$$', right: '$$', display: true},
                {left: '$', right: '$', display: false},
                {left: '\\(', right: '\\)', display: false},
                {left: '\\[', right: '\\]', display: true}
            ],
            throwOnError: false
        });
    }
}

function setReaderLayout(layout) {
    currentReaderLayout = layout;
    const notePanel = document.getElementById('reader-note-panel');
    const pdfPanel = document.getElementById('reader-pdf-panel');
    const aiChatPanel = document.getElementById('reader-ai-chat-panel');

    const btnTrio = document.getElementById('layout-btn-trio');
    const btnSplit = document.getElementById('layout-btn-split');
    const btnNote = document.getElementById('layout-btn-note');
    const btnPdf = document.getElementById('layout-btn-pdf');
    const btnToggleAi = document.getElementById('btn-toggle-ai-chat');

    [btnTrio, btnSplit, btnNote, btnPdf].forEach(b => {
        if (b) {
            b.classList.remove('text-white', 'bg-zinc-800', 'border-zinc-700/70');
            b.classList.add('text-zinc-400');
        }
    });

    if (layout === 'trio') {
        notePanel.classList.remove('hidden');
        notePanel.className = "flex-1 min-w-0 h-full overflow-y-auto p-6 md:p-8 bg-[#08080a]";
        pdfPanel.classList.remove('hidden');
        pdfPanel.className = "flex-1 min-w-0 h-full overflow-hidden bg-black flex flex-col";
        aiChatPanel.classList.remove('hidden');
        
        if (btnTrio) {
            btnTrio.classList.add('text-white', 'bg-zinc-800', 'border-zinc-700/70');
            btnTrio.classList.remove('text-zinc-400');
        }
        updateAIChatBtnState(true);
    } else if (layout === 'split') {
        notePanel.classList.remove('hidden');
        notePanel.className = "flex-1 min-w-0 h-full overflow-y-auto p-6 md:p-8 bg-[#08080a]";
        pdfPanel.classList.remove('hidden');
        pdfPanel.className = "flex-1 min-w-0 h-full overflow-hidden bg-black flex flex-col";
        aiChatPanel.classList.add('hidden');
        
        if (btnSplit) {
            btnSplit.classList.add('text-white', 'bg-zinc-800', 'border-zinc-700/70');
            btnSplit.classList.remove('text-zinc-400');
        }
        updateAIChatBtnState(false);
    } else if (layout === 'note') {
        notePanel.classList.remove('hidden');
        notePanel.className = "flex-1 min-w-0 h-full overflow-y-auto p-6 md:p-8 bg-[#08080a]";
        pdfPanel.classList.add('hidden');
        
        if (btnNote) {
            btnNote.classList.add('text-white', 'bg-zinc-800', 'border-zinc-700/70');
            btnNote.classList.remove('text-zinc-400');
        }
    } else if (layout === 'pdf') {
        notePanel.classList.add('hidden');
        pdfPanel.classList.remove('hidden');
        pdfPanel.className = "flex-1 min-w-0 h-full overflow-hidden bg-black flex flex-col";
        
        if (btnPdf) {
            btnPdf.classList.add('text-white', 'bg-zinc-800', 'border-zinc-700/70');
            btnPdf.classList.remove('text-zinc-400');
        }
    }
    if (window.lucide) lucide.createIcons();
}

function toggleAIChatPanel() {
    const aiChatPanel = document.getElementById('reader-ai-chat-panel');
    if (!aiChatPanel) return;

    if (aiChatPanel.classList.contains('hidden')) {
        aiChatPanel.classList.remove('hidden');
        updateAIChatBtnState(true);
    } else {
        aiChatPanel.classList.add('hidden');
        updateAIChatBtnState(false);
    }
    if (window.lucide) lucide.createIcons();
}

function updateAIChatBtnState(isActive) {
    const btn = document.getElementById('btn-toggle-ai-chat');
    if (!btn) return;
    if (isActive) {
        btn.className = "px-2.5 py-1 text-xs font-semibold text-zinc-100 bg-zinc-800 border border-zinc-600 hover:bg-zinc-750 rounded-lg flex items-center gap-1.5 transition shadow-sm";
    } else {
        btn.className = "px-2.5 py-1 text-xs font-semibold text-zinc-400 bg-zinc-900 border border-zinc-800 hover:text-white rounded-lg flex items-center gap-1.5 transition";
    }
}

// Q&A Geçmişini Panoda Görüntüleme
function renderReaderQAHistory(history) {
    const qaMessages = document.getElementById('reader-qa-messages');
    const emptyState = document.getElementById('reader-chat-empty-state');
    if (!qaMessages) return;

    qaMessages.innerHTML = '';

    if (!Array.isArray(history) || history.length === 0) {
        if (emptyState) emptyState.classList.remove('hidden');
        return;
    }

    if (emptyState) emptyState.classList.add('hidden');

    history.forEach((item, index) => {
        const itemNode = buildQABubbleElement(item);
        qaMessages.appendChild(itemNode);
    });

    if (typeof enhanceAcademicDom === 'function') {
        enhanceAcademicDom(qaMessages);
    }

    if (window.renderMathInElement) {
        renderMathInElement(qaMessages, {
            delimiters: [
                {left: '$$', right: '$$', display: true},
                {left: '$', right: '$', display: false},
                {left: '\\(', right: '\\)', display: false},
                {left: '\\[', right: '\\]', display: true}
            ],
            throwOnError: false
        });
    }

    if (window.lucide) lucide.createIcons();
}

function buildQABubbleElement(item) {
    const wrapper = document.createElement('div');
    wrapper.className = "space-y-2.5 border-b border-zinc-800/80 pb-3 mb-3 last:border-b-0 last:pb-0 last:mb-0";
    wrapper.setAttribute('data-qa-id', item.id);

    // Kullanıcı Soru Balonu
    const userBubble = document.createElement('div');
    userBubble.className = "p-3 rounded-xl bg-zinc-900/90 border border-zinc-800 text-xs text-white space-y-1 shadow-sm";
    
    let dateStr = 'Kayıtlı';
    if (item.created_at) {
        try {
            const d = new Date(item.created_at);
            dateStr = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' • ' + d.toLocaleDateString();
        } catch(e){}
    }

    userBubble.innerHTML = `
        <div class="flex items-center justify-between text-[10px] text-zinc-400 font-bold mb-1">
            <span class="flex items-center gap-1 text-zinc-300"><i data-lucide="user" class="w-3 h-3 text-zinc-400"></i> Senin Sorun:</span>
            <span class="text-[9px] text-zinc-500 font-normal">${dateStr}</span>
        </div>
        <div class="text-zinc-200 leading-relaxed whitespace-pre-wrap text-[11px]">${escapeHtml(item.question)}</div>
    `;

    // AI Cevap Balonu
    const aiBubble = document.createElement('div');
    aiBubble.className = "p-3.5 rounded-xl bg-zinc-900/80 border border-zinc-700/60 text-xs text-white space-y-2.5 shadow-md";
    const itemId = item.id;

    aiBubble.innerHTML = `
        <div class="flex items-center justify-between border-b border-zinc-800 pb-1.5 mb-1.5">
            <span class="flex items-center gap-1.5 text-[11px] font-bold text-zinc-200">
                <i data-lucide="bot" class="w-3.5 h-3.5 text-zinc-300"></i>
                <span>Gemini Yanıtı</span>
                <span class="text-[9px] px-1.5 py-0.2 rounded bg-zinc-800 text-emerald-400 border border-zinc-700 font-normal">Kayıtlı ✓</span>
            </span>
            <div class="flex items-center gap-1">
                <button type="button" onclick="appendQAToNoteDirectly('${itemId}', this)" class="px-2 py-0.5 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 border border-zinc-700 text-[10px] font-semibold flex items-center gap-1 transition" title="Bu soru ve cevabı ortadaki ders notuna kalıcı ekle">
                    <i data-lucide="bookmark-plus" class="w-3 h-3 text-zinc-400"></i>
                    <span>Notuma Ekle</span>
                </button>
                <button type="button" onclick="copyQAText('${itemId}')" class="p-1 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-400 hover:text-white transition" title="Kopyala">
                    <i data-lucide="copy" class="w-3 h-3"></i>
                </button>
                <button type="button" onclick="deleteQAItem('${itemId}')" class="p-1 rounded-lg bg-zinc-800 hover:bg-red-950 text-zinc-400 hover:text-red-400 transition" title="Bu Soruyu Sil">
                    <i data-lucide="trash-2" class="w-3 h-3"></i>
                </button>
            </div>
        </div>
        <div class="ai-markdown-content font-size-sm leading-relaxed text-[11px]"></div>
    `;

    const qaBody = aiBubble.querySelector('.ai-markdown-content');
    renderMarkdownWithMath(qaBody, item.answer || '');

    wrapper.appendChild(userBubble);
    wrapper.appendChild(aiBubble);
    return wrapper;
}

async function clearReaderQAMessages() {
    if (!currentReaderNote) return;
    
    if (!confirm('Bu ders notuna ait tüm AI soru-cevap geçmişini temizlemek istediğinizden emin misiniz?')) {
        return;
    }

    currentReaderNote.qa_history = [];
    const qaMessages = document.getElementById('reader-qa-messages');
    const emptyState = document.getElementById('reader-chat-empty-state');
    if (qaMessages) qaMessages.innerHTML = '';
    if (emptyState) emptyState.classList.remove('hidden');

    try {
        await fetch('api/save_note_qa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                note_id: currentReaderNote.id,
                action: 'clear'
            })
        });
        if (typeof showToast === 'function') showToast('info', 'AI Soru geçmişi temizlendi.');
    } catch(e) {}
}

function changeReaderFontSize(size) {
    const content = document.getElementById('reader-rendered-content');
    content.classList.remove('font-size-sm', 'font-size-md', 'font-size-lg');
    content.classList.add('font-size-' + size);
}

function copyReaderContent() {
    if (currentReaderNote && currentReaderNote.content) {
        copyToClipboard(currentReaderNote.content, 'Not içeriği panoya kopyalandı!');
    }
}

function printReaderContent() {
    window.print();
}

// =========================================================================
// 🤖 POPUP İÇİNDE ANLIK AI DÖKÜMAN ANALİZ & ÇÖZÜM MOTORU
// =========================================================================
async function triggerReaderAIAnalysis() {
    if (!currentReaderNote) return;

    const contentBox = document.getElementById('reader-rendered-content');
    const aiBtn = document.getElementById('reader-ai-btn');
    const aiBtnText = document.getElementById('reader-ai-btn-text');

    aiBtn.disabled = true;
    if (aiBtnText) aiBtnText.innerText = 'AI İnceliyor...';

    contentBox.innerHTML = `
        <div class="py-16 px-4 text-center space-y-4">
            <div class="w-16 h-16 rounded-2xl bg-zinc-900 border border-zinc-700/80 text-zinc-200 flex items-center justify-center mx-auto shadow-2xl animate-pulse">
                <i data-lucide="sparkles" class="w-8 h-8 animate-spin text-zinc-300"></i>
            </div>
            <div class="space-y-1.5">
                <h4 class="text-sm font-bold text-white tracking-tight">Gemini AI Dökümanı İnceliyor...</h4>
                <p class="text-xs text-zinc-400 max-w-md mx-auto leading-relaxed">
                    Döküman analiz ediliyor, zenginleştirilmiş konu anlatımı oluşturuluyor, kritik formüller LaTeX ile modelleniyor ve 5 kritik sınav sorusu çözümleriyle hazırlanıyor...
                </p>
            </div>
            <div class="flex items-center justify-center gap-1.5 pt-2">
                <span class="w-2.5 h-2.5 rounded-full bg-zinc-200 animate-bounce"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-zinc-400 animate-bounce [animation-delay:0.2s]"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-zinc-600 animate-bounce [animation-delay:0.4s]"></span>
            </div>
        </div>
    `;
    if (window.lucide) lucide.createIcons();

    if (currentReaderNote.file_path && currentReaderLayout === 'pdf') {
        setReaderLayout('trio');
    }

    try {
        const promptText = `${currentReaderNote.title} ders notunu ve ekli dosyasını referans alarak; konunun mantığını derinlemesine anlatan zengin bir Konu Anlatımı & Temel İlkeler bölümü oluştur, kritik formülleri LaTeX ($...$) ile detaylandır ve vize/finalde çıkabilecek en kritik 5 sınav sorusunu adım adım model çözümleri ve püf noktalarıyla birlikte hazırla.`;

        const resp = await fetch('api/ai_prompt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'exam_prep',
                note_id: currentReaderNote.id,
                course_id: currentReaderNote.course_id,
                project_id: currentReaderNote.project_id,
                prompt: promptText
            })
        });

        const respText = await resp.text();
        let data;
        try {
            const jsonMatch = respText.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                data = JSON.parse(jsonMatch[0]);
            } else {
                throw new Error(respText.replace(/<[^>]*>/g, '').trim() || 'Sunucudan geçersiz yanıt alındı.');
            }
        } catch (e) {
            throw new Error(respText.replace(/<[^>]*>/g, '').trim() || 'Sunucudan geçersiz yanıt alındı.');
        }

        if (!data || !data.success) {
            throw new Error((data && data.error) ? data.error : 'AI yanıtı üretilirken bir hata oluştu.');
        }

        const cleanMarkdown = data.response || '';
        currentReaderNote.content = cleanMarkdown;
        currentReaderNote.is_ai = 1;

        // Tokenized Markdown & LaTeX render
        renderMarkdownWithMath(contentBox, cleanMarkdown);

        const banner = document.createElement('div');
        banner.className = "mb-6 p-3.5 rounded-xl bg-zinc-900 border border-zinc-700/80 flex items-center justify-between text-xs text-zinc-100 shadow-md";
        banner.innerHTML = `
            <div class="flex items-center gap-2">
                <i data-lucide="check-circle" class="w-4 h-4 text-zinc-200 flex-shrink-0"></i>
                <span class="font-medium text-white">✨ AI Sınav Rehberi & Konu Anlatımı üretildi ve bu nota kaydedildi!</span>
            </div>
            <span class="text-[10px] text-zinc-400 font-mono">LaTeX & KaTeX Aktif</span>
        `;
        contentBox.insertBefore(banner, contentBox.firstChild);

        // Veritabanında güncelle
        const saveResp = await fetch('api/save_ai_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                note_id: currentReaderNote.id,
                content: cleanMarkdown,
                update_current_note: true
            })
        });

        const saveResult = await saveResp.json().catch(() => null);
        if (saveResult && saveResult.success) {
            // Eğer klasör açıksa içindeki ilgili parçayı da güncelle
            if (currentOpenFolder && Array.isArray(currentOpenFolder.items)) {
                const foundItem = currentOpenFolder.items.find(it => String(it.id) === String(currentReaderNote.id));
                if (foundItem) {
                    foundItem.content = cleanMarkdown;
                    foundItem.is_ai = 1;
                }
            }
            if (typeof showToast === 'function') showToast('success', 'AI incelemesi ve çözümler bu nota kaydedildi!');
        }

        if (aiBtnText) aiBtnText.innerText = 'AI Tekrar İncele';
        if (window.lucide) lucide.createIcons();

    } catch (err) {
        contentBox.innerHTML = `
            <div class="p-8 rounded-2xl bg-zinc-900 border border-red-500/40 text-center space-y-3">
                <div class="w-12 h-12 rounded-xl bg-red-500/10 text-red-400 flex items-center justify-center mx-auto border border-red-500/20">
                    <i data-lucide="alert-triangle" class="w-6 h-6"></i>
                </div>
                <h4 class="text-sm font-bold text-white">AI İncelemesi Başarısız Oldu</h4>
                <p class="text-xs text-zinc-400">${err.message}</p>
                <div class="flex justify-center gap-2 pt-2">
                    <button type="button" onclick="triggerReaderAIAnalysis()" class="px-4 py-2 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-white text-xs font-semibold border border-zinc-700">
                        Tekrar Dene
                    </button>
                    <a href="settings.php" class="px-4 py-2 rounded-xl bg-zinc-100 hover:bg-white text-zinc-950 text-xs font-bold">
                        API Ayarlarını Kontrol Et
                    </a>
                </div>
            </div>
        `;
        if (aiBtnText) aiBtnText.innerText = 'Tekrar Dene';
        if (window.lucide) lucide.createIcons();
    } finally {
        aiBtn.disabled = false;
    }
}

// =========================================================================
// 💬 DÖKÜMAN ÜZERİNE SAĞ PANELDE CANLI SORU SORMA & Q&A MOTORU
// =========================================================================
function setReaderQuickPrompt(text) {
    const input = document.getElementById('reader-custom-question');
    if (input) {
        input.value = text;
        input.focus();
    }
}

function handleReaderQuestionKey(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submitReaderCustomQuestion();
    }
}

async function submitReaderCustomQuestion(forcedText) {
    if (!currentReaderNote) return;

    const input = document.getElementById('reader-custom-question');
    const questionText = (forcedText || (input ? input.value : '')).trim();
    if (!questionText) return;

    if (input) input.value = '';

    const emptyState = document.getElementById('reader-chat-empty-state');
    const qaMessages = document.getElementById('reader-qa-messages');
    const askBtn = document.getElementById('btn-reader-ask');
    const scrollContainer = document.getElementById('reader-chat-scroll-container');

    if (emptyState) emptyState.classList.add('hidden');

    const tempId = 'qa_' + Date.now();

    // Kullanıcı Soru Balonu
    const userBubble = document.createElement('div');
    userBubble.className = "p-3 rounded-xl bg-zinc-900 border border-zinc-800 text-xs text-white space-y-1 shadow-sm";
    userBubble.innerHTML = `
        <div class="flex items-center justify-between text-[10px] text-zinc-400 font-bold mb-1">
            <span class="flex items-center gap-1 text-zinc-300"><i data-lucide="user" class="w-3 h-3"></i> Senin Sorun:</span>
            <span class="text-[9px] text-zinc-500 font-normal">Şimdi</span>
        </div>
        <div class="text-zinc-200 leading-relaxed whitespace-pre-wrap text-[11px]">${escapeHtml(questionText)}</div>
    `;
    qaMessages.appendChild(userBubble);

    // AI Yükleme Balonu (Skeleton)
    const aiBubble = document.createElement('div');
    aiBubble.id = 'loading-' + tempId;
    aiBubble.className = "p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 text-xs text-zinc-300 space-y-2 shadow-sm animate-pulse";
    aiBubble.innerHTML = `
        <div class="flex items-center gap-2 text-zinc-300 font-bold text-[11px]">
            <i data-lucide="sparkles" class="w-3.5 h-3.5 animate-spin text-zinc-400"></i>
            <span>Gemini yanıtı hazırlıyor...</span>
        </div>
        <p class="text-[10px] text-zinc-500">PDF ve mevcut özet inceleniyor...</p>
    `;
    qaMessages.appendChild(aiBubble);
    if (window.lucide) lucide.createIcons();

    if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
    if (askBtn) askBtn.disabled = true;

    try {
        const resp = await fetch('api/ai_prompt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'followup',
                note_id: currentReaderNote.id,
                course_id: currentReaderNote.course_id,
                project_id: currentReaderNote.project_id,
                prompt: questionText
            })
        });

        const respText = await resp.text();
        let data;
        try {
            const jsonMatch = respText.match(/\{[\s\S]*\}/);
            if (jsonMatch) data = JSON.parse(jsonMatch[0]);
            else throw new Error(respText.replace(/<[^>]*>/g, '').trim());
        } catch(e) {
            throw new Error(respText.replace(/<[^>]*>/g, '').trim() || 'Sunucudan geçersiz yanıt alındı.');
        }

        if (!data || !data.success) {
            throw new Error((data && data.error) ? data.error : 'AI yanıtı alınamadı.');
        }

        const rawAnswer = data.response || '';
        
        // Geçici yükleme balonlarını kaldır
        userBubble.remove();
        aiBubble.remove();

        const newQAItem = {
            id: tempId,
            question: questionText,
            answer: rawAnswer,
            created_at: new Date().toISOString()
        };

        if (!Array.isArray(currentReaderNote.qa_history)) {
            currentReaderNote.qa_history = [];
        }
        currentReaderNote.qa_history.push(newQAItem);

        // UI'ya kalıcı ekle (buildQABubbleElement renderMarkdownWithMath ile güvenli render eder)
        const bubbleElement = buildQABubbleElement(newQAItem);
        qaMessages.appendChild(bubbleElement);

        if (window.lucide) lucide.createIcons();

        // 💾 VERİTABANINA OTOMATİK KALICI KAYDET
        fetch('api/save_note_qa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                note_id: currentReaderNote.id,
                action: 'append',
                item_id: tempId,
                question: questionText,
                answer: rawAnswer
            })
        });

    } catch (err) {
        aiBubble.classList.remove('animate-pulse');
        aiBubble.className = "p-3 rounded-xl bg-zinc-900 border border-red-500/30 text-xs text-red-300 space-y-1";
        aiBubble.innerHTML = `
            <div class="font-bold flex items-center gap-1.5 text-red-400 text-[11px]">
                <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
                <span>Cevap alınamadı</span>
            </div>
            <p class="text-[10px] text-zinc-400">${err.message}</p>
        `;
        if (window.lucide) lucide.createIcons();
    } finally {
        if (askBtn) askBtn.disabled = false;
        if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
    }
}

// Belirli bir Q&A öğesini notun metnine ekleme
async function appendQAToNoteDirectly(itemId, btnEl) {
    if (!currentReaderNote || !Array.isArray(currentReaderNote.qa_history)) return;

    const qaItem = currentReaderNote.qa_history.find(q => String(q.id) === String(itemId));
    if (!qaItem) return;

    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = `<i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> Ekleniyor...`;
        if (window.lucide) lucide.createIcons();
    }

    const additionText = `\n\n---\n\n### 💬 Soru: ${qaItem.question}\n\n${qaItem.answer}`;
    const newContent = (currentReaderNote.content || '') + additionText;
    currentReaderNote.content = newContent;

    try {
        const resp = await fetch('api/save_ai_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                note_id: currentReaderNote.id,
                content: newContent,
                update_current_note: true
            })
        });
        const data = await resp.json();
        if (data && data.success) {
            if (btnEl) {
                btnEl.className = "px-2 py-0.5 rounded-lg bg-zinc-850 text-zinc-100 border border-zinc-600 text-[10px] font-bold flex items-center gap-1 shadow-sm";
                btnEl.innerHTML = `<i data-lucide="check" class="w-3 h-3 text-white"></i> Eklendi ✓`;
                if (window.lucide) lucide.createIcons();
            }

            // Ortadaki okuma alanını da hemen güncelle
            const contentBox = document.getElementById('reader-rendered-content');
            if (contentBox) {
                renderMarkdownWithMath(contentBox, newContent);
            }

            if (typeof showToast === 'function') showToast('success', 'Soru & Cevap bu ders notunun içeriğine kalıcı olarak eklendi!');
        } else {
            throw new Error(data.error || 'Kaydedilemedi');
        }
    } catch(e) {
        if (btnEl) {
            btnEl.disabled = false;
            btnEl.innerText = 'Tekrar Dene';
        }
        if (typeof showToast === 'function') showToast('error', e.message);
    }
}

// Q&A Metnini Kopyalama
function copyQAText(itemId) {
    if (!currentReaderNote || !Array.isArray(currentReaderNote.qa_history)) return;
    const qaItem = currentReaderNote.qa_history.find(q => String(q.id) === String(itemId));
    if (!qaItem) return;

    const textToCopy = `Soru: ${qaItem.question}\n\nCevap:\n${qaItem.answer}`;
    copyToClipboard(textToCopy, 'Soru ve cevap panoya kopyalandı!');
}

// Q&A Listesinden Tekil Soru Silme
async function deleteQAItem(itemId) {
    if (!currentReaderNote || !Array.isArray(currentReaderNote.qa_history)) return;
    
    if (!confirm('Bu soruyu ve cevabını silmek istediğinizden emin misiniz?')) return;

    currentReaderNote.qa_history = currentReaderNote.qa_history.filter(q => String(q.id) !== String(itemId));
    renderReaderQAHistory(currentReaderNote.qa_history);

    try {
        await fetch('api/save_note_qa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                note_id: currentReaderNote.id,
                action: 'delete_item',
                item_id: itemId
            })
        });
        if (typeof showToast === 'function') showToast('info', 'Soru silindi.');
    } catch(e){}
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeJsString(str) {
    if (!str) return '';
    return String(str).replace(/\\/g, '\\\\').replace(/`/g, '\\`').replace(/\$/g, '\\$');
}


// 📁 KLASÖR İÇERİĞİ YÖNETİMİ (Folder Explorer JS)
let currentOpenFolder = null;

function openFolderModal(folder) {
    currentOpenFolder = folder;
    document.getElementById('folder-modal-title').innerText = folder.folder_title;
    document.getElementById('folder-modal-course').innerText = (folder.course_code ? (folder.course_code + ' - ' + (folder.course_name || '')) : 'Genel Döküman Seti');
    document.getElementById('folder-modal-count').innerText = folder.items.length + ' Bölüm / Parça';
    document.getElementById('folder-search-input').value = '';
    
    renderFolderParts(folder.items);
    openModal('modal-folder-view');
}

function renderFolderParts(items) {
    const container = document.getElementById('folder-parts-container');
    if (!items || items.length === 0) {
        container.innerHTML = '<div class="col-span-full p-8 text-center text-slate-400 text-xs">Aradığınız kriterde bir bölüm bulunamadı.</div>';
        return;
    }

    let html = '';
    const baseUrl = '<?= getBaseUrl() ?>';

    items.forEach((item, i) => {
        const encodedPrompt = encodeURIComponent(item.title + ' ders notunu ve ekli dosyasını referans alarak; konunun mantığını derinlemesine anlatan zengin bir Konu Anlatımı & Temel İlkeler bölümü oluştur, kritik formülleri LaTeX ($...$) ile detaylandır ve vize/finalde çıkabilecek en kritik 5 sınav sorusunu adım adım model çözümleri ve püf noktalarıyla birlikte hazırla.');
        const fileUrl = item.file_path ? (baseUrl + '/' + item.file_path.replace(/^\/+/, '')) : '';
        const itemJson = JSON.stringify(item).replace(/"/g, '&quot;');

        html += `
            <div class="p-4 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-amber-500/40 transition flex flex-col justify-between space-y-3 group/part shadow-sm">
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-amber-500/15 text-amber-300 border border-amber-500/30">
                            Bölüm ${i + 1}
                        </span>
                        <div class="flex items-center gap-1">
                            <button onclick="openEditNoteFromFolder(${itemJson})" class="p-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white transition" title="Düzenle">
                                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Bu bölümü silmek istiyor musunuz?');" class="inline">
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" value="${item.id}">
                                <button type="submit" class="p-1.5 rounded-lg bg-slate-800 text-slate-400 hover:text-red-400 transition" title="Sil">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <h4 class="text-xs font-bold text-white group-hover/part:text-amber-200 transition line-clamp-2 leading-snug">
                        ${item.title}
                    </h4>
                    
                    ${item.content ? `<p class="text-[11px] text-slate-400 line-clamp-2 leading-relaxed">${item.content}</p>` : ''}
                </div>

                <div class="pt-2.5 border-t border-slate-800 flex flex-wrap items-center justify-between gap-2">
                    <button type="button" onclick="openNoteReaderFromFolder(${itemJson})" class="px-2.5 py-1.5 rounded-lg bg-sky-500/15 hover:bg-sky-500/25 text-sky-300 border border-sky-500/30 text-[11px] font-semibold flex items-center gap-1 transition">
                        <i data-lucide="book-open" class="w-3.5 h-3.5"></i>
                        <span>Oku & PDF İncele</span>
                    </button>

                    <a href="ai-assistant.php?type=exam_prep&note_id=${item.id}&prompt=${encodedPrompt}" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 text-[11px] font-semibold flex items-center gap-1 transition">
                        <i data-lucide="sparkles" class="w-3 h-3"></i>
                        <span>AI Sınav</span>
                    </a>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function filterFolderParts(query) {
    if (!currentOpenFolder || !currentOpenFolder.items) return;
    const q = (query || '').toLowerCase().trim();
    if (!q) {
        renderFolderParts(currentOpenFolder.items);
        return;
    }
    const filtered = currentOpenFolder.items.filter(it => 
        (it.title && it.title.toLowerCase().includes(q)) ||
        (it.content && it.content.toLowerCase().includes(q)) ||
        (it.file_name && it.file_name.toLowerCase().includes(q))
    );
    renderFolderParts(filtered);
}

function openEditNoteFromFolder(note) {
    closeModal('modal-folder-view');
    openEditNote(note);
}

function openNoteReaderFromFolder(note) {
    closeModal('modal-folder-view');
    openNoteReader(note);
}

// =========================================================================
// 🔍 DİNAMİK CANLI FİLTRELEME & KATEGORİ SEÇİCİ MOTORU
// =========================================================================
let currentCategoryFilter = 'all';

function setCategoryFilter(category) {
    currentCategoryFilter = category;
    
    // Tab butonlarının stillerini güncelle
    const tabs = document.querySelectorAll('.category-filter-btn');
    tabs.forEach(tab => {
        if (tab.getAttribute('data-tab') === category) {
            tab.className = "category-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 bg-sky-600 text-white shadow-sm border border-sky-500";
        } else {
            tab.className = "category-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-slate-800 transition flex items-center gap-1.5 border border-transparent";
        }
    });

    applyAllFilters();
}

function applyAllFilters() {
    const searchInput = document.getElementById('live-notes-search');
    const q = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const courseSelect = document.getElementById('filter-course-select');
    const selectedCourseId = (courseSelect ? courseSelect.value : '').trim();

    const cards = document.querySelectorAll('.note-card-item');
    let visibleCount = 0;

    cards.forEach(card => {
        const cat = card.getAttribute('data-category') || '';
        const courseId = card.getAttribute('data-course') || '';
        const title = card.getAttribute('data-title') || '';
        const content = card.getAttribute('data-content') || '';

        // Kategori Kontrolü
        let matchCat = (currentCategoryFilter === 'all' || cat === currentCategoryFilter);
        
        // Ders Kontrolü
        let matchCourse = (!selectedCourseId || courseId === selectedCourseId);

        // Arama Kontrolü
        let matchSearch = true;
        if (q) {
            matchSearch = (title.includes(q) || content.includes(q));
        }

        if (matchCat && matchCourse && matchSearch) {
            card.classList.remove('hidden');
            visibleCount++;
        } else {
            card.classList.add('hidden');
        }
    });

    const totalCountEl = document.getElementById('total-visible-count');
    if (totalCountEl) {
        totalCountEl.innerText = `${visibleCount} Öğe`;
    }

    const noMsg = document.getElementById('no-filtered-notes-msg');
    if (noMsg) {
        if (visibleCount === 0 && cards.length > 0) {
            noMsg.classList.remove('hidden');
        } else {
            noMsg.classList.add('hidden');
        }
    }
}

function resetAllFilters() {
    const searchInput = document.getElementById('live-notes-search');
    if (searchInput) searchInput.value = '';
    const courseSelect = document.getElementById('filter-course-select');
    if (courseSelect) courseSelect.value = '';
    setCategoryFilter('all');
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

