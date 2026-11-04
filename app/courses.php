<?php
$pageTitle = "Dersler & Not Takibi";
require_once __DIR__ . '/includes/auth_check.php';

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Ders Kaydetme (Ekleme / Güncelleme) & Silme İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_course') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $courseCode = trim($_POST['course_code'] ?? '');
        $courseName = trim($_POST['course_name'] ?? '');
        $instructor = trim($_POST['instructor'] ?? '');
        $classroom = trim($_POST['classroom'] ?? '');
        $credits = (int)($_POST['credits'] ?? 3);
        $akts = (int)($_POST['akts'] ?? 5);
        $minPassGrade = (float)($_POST['min_pass_grade'] ?? 50.0);
        $maxAbsenteeism = (int)($_POST['max_absenteeism'] ?? 4);
        $usedAbsenteeism = (int)($_POST['used_absenteeism'] ?? 0);
        $color = $_POST['color'] ?? '#0ea5e9';
        $targetGrade = $_POST['target_grade'] ?? 'AA';
        $status = $_POST['status'] ?? 'ongoing';
        $notes = trim($_POST['notes'] ?? '');

        if (!empty($courseCode) && !empty($courseName)) {
            if ($courseId > 0) {
                // Güncelleme
                $stmt = $pdo->prepare("UPDATE courses SET 
                                        course_code = ?, course_name = ?, instructor = ?, classroom = ?, 
                                        credits = ?, akts = ?, min_pass_grade = ?, max_absenteeism = ?, 
                                        used_absenteeism = ?, color = ?, target_grade = ?, status = ?, notes = ? 
                                       WHERE id = ? AND user_id = ?");
                $stmt->execute([$courseCode, $courseName, $instructor, $classroom, $credits, $akts, $minPassGrade, $maxAbsenteeism, $usedAbsenteeism, $color, $targetGrade, $status, $notes, $courseId, $userId]);
                setFlash('success', 'Ders bilgileri başarıyla güncellendi!');
            } else {
                // Yeni Kayıt
                $stmt = $pdo->prepare("INSERT INTO courses (user_id, course_code, course_name, instructor, classroom, credits, akts, min_pass_grade, max_absenteeism, used_absenteeism, color, target_grade, status, notes) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $courseCode, $courseName, $instructor, $classroom, $credits, $akts, $minPassGrade, $maxAbsenteeism, $usedAbsenteeism, $color, $targetGrade, $status, $notes]);
                setFlash('success', 'Yeni ders başarıyla eklendi!');
            }
        } else {
            setFlash('error', 'Ders kodu ve ders adı alanları zorunludur.');
        }
        redirect('courses.php');
    } elseif ($action === 'delete_course') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        require_once __DIR__ . '/includes/notebook_trash.php';
        bnTrashChange($pdo, (int)$userId, 'course', $courseId, false);
        setFlash('success', 'Ders çöp kutusuna taşındı.');
        redirect('courses.php');
    }
}

// Dersleri Getir
$stmt = $pdo->prepare("SELECT c.*, 
                       (SELECT COUNT(*) FROM exams_assignments ea WHERE ea.course_id = c.id AND ea.status = 'pending') as pending_exams_count 
                       FROM courses c 
                       WHERE c.user_id = ? AND c.trashed_at IS NULL 
                       ORDER BY c.status ASC, c.created_at DESC");
$stmt->execute([$userId]);
$courses = $stmt->fetchAll();

include_once __DIR__ . '/includes/header.php';
?>

<!-- Üst Başlık & Yeni Ders Ekleme Butonu -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-white">Ders Takibi & Not Simülasyonu</h2>
        <p class="text-xs text-slate-400 mt-0.5">Ders detaylarını düzenle, devamsızlığını ve geçme notu hedeflerini yönet.</p>
    </div>
    <button onclick="openNewCourseModal()" class="px-4 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold flex items-center gap-2 transition shadow-sm">
        <i data-lucide="plus" class="w-4 h-4"></i>
        <span>Yeni Ders Ekle</span>
    </button>
</div>

<!-- Ders Listesi Kartları -->
<?php if (!empty($courses)): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        <?php foreach ($courses as $c): ?>
            <?php 
                $absencePercent = ($c['max_absenteeism'] > 0) ? min(100, ($c['used_absenteeism'] / $c['max_absenteeism']) * 100) : 0;
                $absenceColor = ($absencePercent >= 75) ? 'bg-red-500' : (($absencePercent >= 50) ? 'bg-amber-500' : 'bg-emerald-500');
                $isPassed = ($c['status'] === 'passed');
                $isFailed = ($c['status'] === 'failed');
            ?>
            <div class="glass-panel rounded-2xl p-6 relative flex flex-col justify-between border-t-2 transition hover:border-slate-700 <?= $isPassed ? 'opacity-75' : '' ?>" style="border-top-color: <?= e($c['color']) ?>">
                
                <div>
                    <!-- Üst Başlık, Kod, Düzenle & Sil Butonları -->
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold px-2.5 py-1 rounded-lg bg-slate-900 border border-slate-800 text-sky-400">
                                    <?= e($c['course_code']) ?>
                                </span>
                                <?php if ($isPassed): ?>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">GEÇİLDİ</span>
                                <?php elseif ($isFailed): ?>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-red-500/15 text-red-300 border border-red-500/30">KALDI</span>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-sm font-bold text-white mt-2 leading-snug"><?= e($c['course_name']) ?></h3>
                        </div>

                        <!-- Aksiyon İkonları (Düzenle & Sil) -->
                        <div class="flex items-center gap-1">
                            <button type="button" onclick="openEditCourse(<?= htmlspecialchars(json_encode($c)) ?>)" class="p-1.5 text-slate-400 hover:text-sky-400 rounded-lg hover:bg-slate-800 transition" title="Ders Detaylarını Düzenle">
                                <i data-lucide="edit-3" class="w-4 h-4"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Bu dersi ve bu derse bağlı tüm not/sınavları silmek istediğinize emin misiniz?');" class="inline">
                                <input type="hidden" name="action" value="delete_course">
                                <input type="hidden" name="course_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="p-1.5 text-slate-400 hover:text-red-400 rounded-lg hover:bg-red-500/10 transition" title="Dersi Sil">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Eğitmen & Mekan & Kredi Bilgileri -->
                    <div class="space-y-1.5 text-xs text-slate-400 mb-4 bg-slate-900/60 p-3 rounded-xl border border-slate-800">
                        <?php if ($c['instructor']): ?>
                            <div class="flex items-center gap-2">
                                <i data-lucide="user-check" class="w-3.5 h-3.5 text-slate-500"></i>
                                <span class="text-slate-300"><?= e($c['instructor']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($c['classroom']): ?>
                            <div class="flex items-center gap-2">
                                <i data-lucide="map-pin" class="w-3.5 h-3.5 text-slate-500"></i>
                                <span>Derslik: <strong class="text-slate-300"><?= e($c['classroom']) ?></strong></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between pt-1 text-[11px] border-t border-slate-800 text-slate-400">
                            <span>Kredi: <strong class="text-white"><?= $c['credits'] ?></strong> &bull; AKTS: <strong class="text-white"><?= $c['akts'] ?></strong></span>
                            <span>Hedef: <strong class="text-emerald-400 font-bold"><?= e($c['target_grade']) ?></strong> (Min: <?= number_format($c['min_pass_grade'], 0) ?>)</span>
                        </div>
                    </div>

                    <!-- Devamsızlık Takip Barı -->
                    <div class="mb-4 bg-slate-900/60 p-3 rounded-xl border border-slate-800">
                        <div class="flex items-center justify-between text-xs mb-1.5">
                            <span class="text-slate-400 flex items-center gap-1.5">
                                <i data-lucide="user-x" class="w-3.5 h-3.5 text-amber-400"></i> Devamsızlık
                            </span>
                            <span class="font-semibold text-slate-300" id="abs-text-<?= $c['id'] ?>">
                                <?= $c['used_absenteeism'] ?> / <?= $c['max_absenteeism'] ?> Hafta
                            </span>
                        </div>
                        <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden mb-2.5">
                            <div id="abs-bar-<?= $c['id'] ?>" class="h-full <?= $absenceColor ?> transition-all duration-300" style="width: <?= $absencePercent ?>%"></div>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] text-slate-500" id="abs-rem-<?= $c['id'] ?>">
                                Kalan: <?= max(0, $c['max_absenteeism'] - $c['used_absenteeism']) ?> Hafta
                            </span>
                            <div class="flex items-center gap-1">
                                <button type="button" onclick="updateAbsence(<?= $c['id'] ?>, -1)" class="px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-xs text-slate-300 border border-slate-700" title="Azalt">-</button>
                                <button type="button" onclick="updateAbsence(<?= $c['id'] ?>, 1)" class="px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-xs text-red-400 border border-slate-700" title="1 Hafta Gitmedim">+</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alt Aksiyonlar & AI Soru Tahmini -->
                <div class="pt-3 border-t border-slate-800 flex items-center justify-between gap-2">
                    <a href="notes.php?course_id=<?= $c['id'] ?>" class="text-xs font-medium text-slate-400 hover:text-white flex items-center gap-1">
                        <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                        <span>Notlar</span>
                    </a>
                    <a href="ai-assistant.php?prompt=<?= urlencode($c['course_code'] . ' - ' . $c['course_name'] . ' dersi için sınav çalışma rehberi ve kritik soru tahminleri çıkar.') ?>" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-300 text-xs font-semibold flex items-center gap-1.5 transition border border-slate-700">
                        <i data-lucide="sparkles" class="w-3.5 h-3.5 text-sky-400"></i>
                        <span>AI Soru Tahmini</span>
                    </a>
                </div>

            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="glass-panel rounded-2xl p-12 text-center max-w-lg mx-auto">
        <div class="w-14 h-14 rounded-2xl bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400 mx-auto mb-4">
            <i data-lucide="book-plus" class="w-7 h-7"></i>
        </div>
        <h3 class="text-base font-bold text-white mb-1">Henüz Ders Eklenmemiş</h3>
        <p class="text-xs text-slate-400 mb-6">Uzatma döneminde aldığın veya vermen gereken dersleri ekleyerek takibe başla.</p>
        <button onclick="openNewCourseModal()" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold shadow-sm">
            + İlk Dersini Ekle
        </button>
    </div>
<?php endif; ?>

<!-- Modal: Ders Ekle / Düzenle -->
<div id="modal-course-form" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="glass-panel rounded-2xl p-6 md:p-8 max-w-lg w-full max-h-[90vh] overflow-y-auto border border-slate-800 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-5">
            <h3 id="modal-course-title" class="text-base font-bold text-white flex items-center gap-2">
                <i data-lucide="book-open" class="w-5 h-5 text-sky-400"></i>
                <span id="modal-title-text">Yeni Ders Ekle</span>
            </h3>
            <button onclick="closeModal('modal-course-form')" class="text-slate-400 hover:text-white">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_course">
            <input type="hidden" name="course_id" id="course_form_id" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Ders Kodu *</label>
                    <input type="text" name="course_code" id="form_course_code" required placeholder="CENG401" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Renk Etiketi</label>
                    <input type="color" name="color" id="form_color" value="#0ea5e9" class="w-full h-9 bg-slate-900 border border-slate-800 rounded-xl px-2 py-1 cursor-pointer">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Ders Adı *</label>
                <input type="text" name="course_name" id="form_course_name" required placeholder="Yazılım Mimarisi & Tasarımı" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Öğretim Görevlisi</label>
                    <input type="text" name="instructor" id="form_instructor" placeholder="Prof. Dr. Ahmet Yılmaz" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Derslik / Amfi</label>
                    <input type="text" name="classroom" id="form_classroom" placeholder="B-204 / Online" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kredi</label>
                    <input type="number" name="credits" id="form_credits" value="3" min="1" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">AKTS</label>
                    <input type="number" name="akts" id="form_akts" value="5" min="1" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Maks. Devamsızlık</label>
                    <input type="number" name="max_absenteeism" id="form_max_absenteeism" value="4" min="0" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kullanılan Devamsızlık</label>
                    <input type="number" name="used_absenteeism" id="form_used_absenteeism" value="0" min="0" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Hedef Harf</label>
                    <select name="target_grade" id="form_target_grade" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                        <option value="AA">AA (4.0)</option>
                        <option value="BA" selected>BA (3.5)</option>
                        <option value="BB">BB (3.0)</option>
                        <option value="CB">CB (2.5)</option>
                        <option value="CC">CC (2.0)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Min. Geçme Notu</label>
                    <input type="number" name="min_pass_grade" id="form_min_pass_grade" value="50" min="0" max="100" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Ders Durumu</label>
                <select name="status" id="form_status" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    <option value="ongoing">Devam Ediyor</option>
                    <option value="passed">Geçildi (Tamamlandı)</option>
                    <option value="failed">Kaldı</option>
                </select>
            </div>

            <div class="pt-4 border-t border-slate-800 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-course-form')" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold">İptal</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold">Kaydet</button>
            </div>
        </form>
    </div>
</div>

<script>
// Yeni Ders Ekle Modalını Sıfırla ve Aç
function openNewCourseModal() {
    document.getElementById('modal-title-text').innerText = 'Yeni Ders Ekle';
    document.getElementById('course_form_id').value = '0';
    document.getElementById('form_course_code').value = '';
    document.getElementById('form_course_name').value = '';
    document.getElementById('form_instructor').value = '';
    document.getElementById('form_classroom').value = '';
    document.getElementById('form_credits').value = '3';
    document.getElementById('form_akts').value = '5';
    document.getElementById('form_max_absenteeism').value = '4';
    document.getElementById('form_used_absenteeism').value = '0';
    document.getElementById('form_min_pass_grade').value = '50';
    document.getElementById('form_color').value = '#0ea5e9';
    document.getElementById('form_target_grade').value = 'BA';
    document.getElementById('form_status').value = 'ongoing';
    
    openModal('modal-course-form');
}

// Mevcut Dersi Düzenle Modalını Doldur ve Aç
function openEditCourse(c) {
    document.getElementById('modal-title-text').innerText = 'Dersi Düzenle: ' + c.course_code;
    document.getElementById('course_form_id').value = c.id;
    document.getElementById('form_course_code').value = c.course_code || '';
    document.getElementById('form_course_name').value = c.course_name || '';
    document.getElementById('form_instructor').value = c.instructor || '';
    document.getElementById('form_classroom').value = c.classroom || '';
    document.getElementById('form_credits').value = c.credits || '3';
    document.getElementById('form_akts').value = c.akts || '5';
    document.getElementById('form_max_absenteeism').value = c.max_absenteeism || '4';
    document.getElementById('form_used_absenteeism').value = c.used_absenteeism || '0';
    document.getElementById('form_min_pass_grade').value = c.min_pass_grade || '50';
    document.getElementById('form_color').value = c.color || '#0ea5e9';
    document.getElementById('form_target_grade').value = c.target_grade || 'BA';
    document.getElementById('form_status').value = c.status || 'ongoing';
    
    openModal('modal-course-form');
}

// Devamsızlık Butonları (+/-)
function updateAbsence(courseId, change) {
    fetch('api/attendance_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ course_id: courseId, change: change })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('abs-text-' + courseId).innerText = data.used_absenteeism + ' / ' + data.max_absenteeism + ' Hafta';
            document.getElementById('abs-rem-' + courseId).innerText = 'Kalan: ' + data.remaining + ' Hafta';
            
            const pct = data.max_absenteeism > 0 ? Math.min(100, (data.used_absenteeism / data.max_absenteeism) * 100) : 0;
            const bar = document.getElementById('abs-bar-' + courseId);
            bar.style.width = pct + '%';
            bar.className = 'h-full transition-all duration-300 ' + (pct >= 75 ? 'bg-red-500' : (pct >= 50 ? 'bg-amber-500' : 'bg-emerald-500'));
            showToast('success', 'Devamsızlık güncellendi.');
        } else {
            showToast('error', data.error || 'Hata oluştu!');
        }
    });
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
