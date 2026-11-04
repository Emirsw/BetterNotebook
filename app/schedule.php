<?php
$pageTitle = "Program & Sınavlar";
require_once __DIR__ . '/includes/auth_check.php';

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Sınav / Ders Programı Ekleme / Silme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_schedule_slot') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $dayOfWeek = $_POST['day_of_week'] ?? 'Monday';
        $startTime = $_POST['start_time'] ?? '09:00';
        $endTime = $_POST['end_time'] ?? '10:30';
        $classroom = trim($_POST['classroom'] ?? '');

        if ($courseId && $dayOfWeek && $startTime && $endTime) {
            $stmt = $pdo->prepare("INSERT INTO course_schedules (course_id, day_of_week, start_time, end_time, classroom) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$courseId, $dayOfWeek, $startTime, $endTime, $classroom]);
            setFlash('success', 'Ders programı saati eklendi.');
        } else {
            setFlash('error', 'Lütfen tüm alanları doldurun.');
        }
        redirect('schedule.php');
    } elseif ($action === 'add_exam') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'vize';
        $weight = (int)($_POST['weight_percentage'] ?? 40);
        $dueDate = $_POST['due_date'] ?? '';
        $targetScore = (float)($_POST['target_score'] ?? 70);
        $difficulty = $_POST['difficulty'] ?? 'medium';
        $description = trim($_POST['description'] ?? '');

        if ($courseId && !empty($title) && !empty($dueDate)) {
            $stmt = $pdo->prepare("INSERT INTO exams_assignments (course_id, title, type, weight_percentage, due_date, target_score, difficulty, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$courseId, $title, $type, $weight, $dueDate, $targetScore, $difficulty, $description]);
            setFlash('success', 'Sınav / Teslim başarıyla takvime eklendi!');
        } else {
            setFlash('error', 'Lütfen zorunlu alanları doldurun.');
        }
        redirect('schedule.php');
    } elseif ($action === 'delete_schedule_slot') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE cs FROM course_schedules cs 
                               JOIN courses c ON cs.course_id = c.id 
                               WHERE cs.id = ? AND c.user_id = ?");
        $stmt->execute([$slotId, $userId]);
        setFlash('success', 'Ders saati silindi.');
        redirect('schedule.php');
    } elseif ($action === 'toggle_exam_status') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'completed';
        $stmt = $pdo->prepare("UPDATE exams_assignments ea 
                               JOIN courses c ON ea.course_id = c.id 
                               SET ea.status = ? 
                               WHERE ea.id = ? AND c.user_id = ?");
        $stmt->execute([$newStatus, $examId, $userId]);
        setFlash('success', 'Sınav durumu güncellendi.');
        redirect('schedule.php');
    } elseif ($action === 'delete_exam') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE ea FROM exams_assignments ea 
                               JOIN courses c ON ea.course_id = c.id 
                               WHERE ea.id = ? AND c.user_id = ?");
        $stmt->execute([$examId, $userId]);
        setFlash('success', 'Sınav kaydı silindi.');
        redirect('schedule.php');
    }
}

// Kullanıcının Dersleri
$stmt = $pdo->prepare("SELECT id, course_code, course_name, color FROM courses WHERE user_id = ? ORDER BY course_code ASC");
$stmt->execute([$userId]);
$myCourses = $stmt->fetchAll();

$daysMap = [
    'Monday' => 'Pazartesi',
    'Tuesday' => 'Salı',
    'Wednesday' => 'Çarşamba',
    'Thursday' => 'Perşembe',
    'Friday' => 'Cuma',
    'Saturday' => 'Cumartesi',
    'Sunday' => 'Pazar'
];

$stmt = $pdo->prepare("SELECT cs.*, c.course_code, c.course_name, c.color 
                       FROM course_schedules cs 
                       JOIN courses c ON cs.course_id = c.id 
                       WHERE c.user_id = ? 
                       ORDER BY cs.start_time ASC");
$stmt->execute([$userId]);
$allSlots = $stmt->fetchAll();

$scheduleByDay = [];
foreach ($daysMap as $dayEn => $dayTr) {
    $scheduleByDay[$dayEn] = [];
}
foreach ($allSlots as $slot) {
    if (isset($scheduleByDay[$slot['day_of_week']])) {
        $scheduleByDay[$slot['day_of_week']][] = $slot;
    }
}

// Sınavlar
$stmt = $pdo->prepare("SELECT ea.*, c.course_code, c.course_name, c.color 
                       FROM exams_assignments ea 
                       JOIN courses c ON ea.course_id = c.id 
                       WHERE c.user_id = ? 
                       ORDER BY ea.status ASC, ea.due_date ASC");
$stmt->execute([$userId]);
$exams = $stmt->fetchAll();

include_once __DIR__ . '/includes/header.php';
?>

<!-- Üst Başlık & Hızlı Ekleme Butonları -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-white">Ders Programı & Sınavlar</h2>
        <p class="text-xs text-slate-400 mt-0.5">Haftalık saatleri planla ve sınav günlerini takip et.</p>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="openModal('modal-add-slot')" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium flex items-center gap-1.5 transition border border-slate-700">
            <i data-lucide="clock" class="w-3.5 h-3.5 text-sky-400"></i>
            <span>Ders Saati Ekle</span>
        </button>
        <button onclick="openModal('modal-add-exam')" class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold flex items-center gap-1.5 transition shadow-sm">
            <i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i>
            <span>Sınav / Ödev Ekle</span>
        </button>
    </div>
</div>

<!-- 1. Bölüm: Haftalık Ders Programı Izgarası -->
<div class="space-y-3">
    <div class="flex items-center justify-between">
        <h3 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
            <i data-lucide="calendar" class="w-3.5 h-3.5 text-sky-400"></i>
            <span>Haftalık Ders Çizelgesi</span>
        </h3>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-5 lg:grid-cols-7 gap-3">
        <?php foreach ($daysMap as $dayEn => $dayTr): ?>
            <?php 
                $isToday = (date('l') === $dayEn);
                $daySlots = $scheduleByDay[$dayEn];
            ?>
            <div class="glass-panel rounded-2xl p-3 flex flex-col min-h-[200px] <?= $isToday ? 'border-sky-500/50 bg-slate-900/90' : '' ?>">
                <!-- Gün Başlığı -->
                <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-800">
                    <span class="text-xs font-bold <?= $isToday ? 'text-sky-400' : 'text-slate-300' ?>"><?= $dayTr ?></span>
                    <?php if ($isToday): ?>
                        <span class="text-[9px] px-1.5 py-0.5 rounded bg-sky-600 text-white font-bold">BUGÜN</span>
                    <?php endif; ?>
                </div>

                <!-- Günün Dersleri -->
                <div class="space-y-2 flex-1">
                    <?php if (!empty($daySlots)): ?>
                        <?php foreach ($daySlots as $slot): ?>
                            <div class="p-2.5 rounded-xl bg-slate-900/80 border border-slate-800 text-left relative group">
                                <div class="flex items-start justify-between">
                                    <span class="text-[11px] font-bold text-sky-300"><?= e($slot['course_code']) ?></span>
                                    <form method="POST" onsubmit="return confirm('Silmek istiyor musunuz?');" class="opacity-0 group-hover:opacity-100 transition">
                                        <input type="hidden" name="action" value="delete_schedule_slot">
                                        <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
                                        <button type="submit" class="text-slate-500 hover:text-red-400 text-[10px]">✕</button>
                                    </form>
                                </div>
                                <p class="text-[11px] text-slate-200 font-medium truncate mt-0.5"><?= e($slot['course_name']) ?></p>
                                <div class="mt-1 flex items-center justify-between text-[10px] text-slate-400">
                                    <span><?= substr($slot['start_time'], 0, 5) ?> - <?= substr($slot['end_time'], 0, 5) ?></span>
                                    <?php if ($slot['classroom']): ?>
                                        <span class="truncate max-w-[60px]"><?= e($slot['classroom']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="h-full flex items-center justify-center text-[11px] text-slate-600 italic py-6">
                            Ders yok
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 2. Bölüm: Sınavlar ve Ödevler Listesi -->
<div class="glass-panel rounded-2xl p-6 space-y-4">
    <div class="flex items-center justify-between border-b border-slate-800 pb-4">
        <h3 class="text-sm font-bold text-white flex items-center gap-2">
            <i data-lucide="clock" class="w-4 h-4 text-amber-400"></i>
            <span>Sınav & Ödev Takip Listesi</span>
        </h3>
        <span class="text-xs text-slate-400"><?= count($exams) ?> Kayıt</span>
    </div>

    <?php if (!empty($exams)): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($exams as $exam): ?>
                <?php 
                    $days = getDaysRemaining($exam['due_date']);
                    $isDone = ($exam['status'] === 'completed');
                    $badgeClass = $isDone ? 'bg-slate-800 text-slate-500 border-slate-700' : (($days <= 3) ? 'bg-red-500/15 text-red-300 border-red-500/30' : (($days <= 7) ? 'bg-amber-500/15 text-amber-300 border-amber-500/30' : 'bg-slate-800 text-slate-300 border-slate-700'));
                ?>
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 flex flex-col justify-between <?= $isDone ? 'opacity-60' : '' ?>">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold px-2 py-0.5 rounded bg-slate-800 text-sky-400 border border-slate-700"><?= e($exam['course_code']) ?></span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded border <?= $badgeClass ?>">
                                <?= $isDone ? '✓ Tamamlandı' : (($days == 0) ? 'Bugün!' : (($days < 0) ? 'Geçti' : $days . ' Gün Kaldı')) ?>
                            </span>
                        </div>

                        <h4 class="text-xs font-bold text-white mt-1"><?= e($exam['title']) ?></h4>
                        <div class="flex items-center gap-2 mt-1 text-[11px] text-slate-400">
                            <span class="uppercase font-semibold px-1.5 py-0.5 rounded bg-slate-800 text-slate-400"><?= e($exam['type']) ?></span>
                            <span>&bull; Etki: %<?= $exam['weight_percentage'] ?></span>
                            <span>&bull; Hedef: <?= number_format($exam['target_score'], 0) ?> Puan</span>
                        </div>

                        <p class="text-xs text-slate-400 mt-2 flex items-center gap-1.5">
                            <i data-lucide="calendar" class="w-3 h-3 text-slate-500"></i>
                            <span><?= formatTurkishDate($exam['due_date'], true) ?></span>
                        </p>
                    </div>

                    <div class="mt-4 pt-3 border-t border-slate-800 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="toggle_exam_status">
                                <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $isDone ? 'pending' : 'completed' ?>">
                                <button type="submit" class="text-[11px] font-medium px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                                    <?= $isDone ? 'Geri Al' : 'Tamamlandı' ?>
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Silmek istiyor musunuz?');" class="inline">
                                <input type="hidden" name="action" value="delete_exam">
                                <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
                                <button type="submit" class="p-1 text-slate-500 hover:text-red-400 transition" title="Sil">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        </div>

                        <?php if (!$isDone): ?>
                            <a href="ai-assistant.php?prompt=<?= urlencode($exam['course_code'] . ' dersinin ' . $exam['title'] . ' sınavı ' . $days . ' gün sonra. Hedef: ' . $exam['target_score'] . '. Çalışma planı ve soru tahminleri hazırla.') ?>" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-300 text-xs font-semibold flex items-center gap-1 transition border border-slate-700">
                                <i data-lucide="sparkles" class="w-3 h-3 text-sky-400"></i>
                                <span>AI Plan</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-xs text-slate-400 text-center py-6">Henüz sınav veya ödev eklenmemiş.</p>
    <?php endif; ?>
</div>

<!-- Modal: Ders Saati Ekle -->
<div id="modal-add-slot" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="glass-panel rounded-2xl p-6 md:p-8 max-w-md w-full border border-slate-800 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-5">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i data-lucide="clock" class="w-4 h-4 text-sky-400"></i>
                <span>Programa Ders Saati Ekle</span>
            </h3>
            <button onclick="closeModal('modal-add-slot')" class="text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_schedule_slot">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Ders Seçin *</label>
                <select name="course_id" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    <option value="">-- Ders Seçiniz --</option>
                    <?php foreach ($myCourses as $mc): ?>
                        <option value="<?= $mc['id'] ?>"><?= e($mc['course_code']) ?> - <?= e($mc['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Gün *</label>
                <select name="day_of_week" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    <?php foreach ($daysMap as $dayEn => $dayTr): ?>
                        <option value="<?= $dayEn ?>"><?= $dayTr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Başlangıç Saati *</label>
                    <input type="time" name="start_time" value="09:00" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Bitiş Saati *</label>
                    <input type="time" name="end_time" value="10:30" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Derslik / Salon</label>
                <input type="text" name="classroom" placeholder="Amfi 1 / Lab 3" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
            </div>

            <div class="pt-4 border-t border-slate-800 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-add-slot')" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold">İptal</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold">Programa Ekle</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Sınav / Ödev Ekle -->
<div id="modal-add-exam" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="glass-panel rounded-2xl p-6 md:p-8 max-w-lg w-full max-h-[90vh] overflow-y-auto border border-slate-800 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-5">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i data-lucide="calendar-plus" class="w-4 h-4 text-amber-400"></i>
                <span>Yeni Sınav veya Ödev Ekle</span>
            </h3>
            <button onclick="closeModal('modal-add-exam')" class="text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_exam">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">İlgili Ders *</label>
                <select name="course_id" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    <option value="">-- Ders Seçiniz --</option>
                    <?php foreach ($myCourses as $mc): ?>
                        <option value="<?= $mc['id'] ?>"><?= e($mc['course_code']) ?> - <?= e($mc['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Başlık *</label>
                <input type="text" name="title" required placeholder="Vize 1 / Final Sınavı" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tür *</label>
                    <select name="type" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                        <option value="vize">Vize Sınavı</option>
                        <option value="final">Final Sınavı</option>
                        <option value="but">Bütünleme</option>
                        <option value="odev">Ödev Teslimi</option>
                        <option value="proje">Dönem Projesi</option>
                        <option value="quiz">Quiz</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Not Ağırlığı (%)</label>
                    <input type="number" name="weight_percentage" value="40" min="0" max="100" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tarih & Saat *</label>
                    <input type="datetime-local" name="due_date" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Hedef Puan</label>
                    <input type="number" name="target_score" value="75" min="0" max="100" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>
            </div>

            <div class="pt-4 border-t border-slate-800 flex justify-end gap-3">
                <button type="button" onclick="closeModal('modal-add-exam')" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold">İptal</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold">Sınavı Kaydet</button>
            </div>
        </form>
    </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
