<?php
// BETTERNOTEBOOK_ENTRY: preserve legacy POST handlers and an explicit fallback.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['legacy'])) {
    $notebookView = 'today';
    require __DIR__ . '/includes/notebook_app.php';
    return;
}

$pageTitle = "Kontrol Paneli";
require_once __DIR__ . '/includes/auth_check.php';

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// 1. İstatistikleri Çekelim
// Aktif Ders Sayısı
$stmt = $pdo->prepare("SELECT COUNT(*), SUM(akts) FROM courses WHERE user_id = ? AND status = 'ongoing'");
$stmt->execute([$userId]);
list($activeCoursesCount, $totalAkts) = $stmt->fetch(PDO::FETCH_NUM);
$totalAkts = $totalAkts ?: 0;

// Yaklaşan Sınavlar ve Ödevler (Gelecek 30 gün)
$stmt = $pdo->prepare("SELECT ea.*, c.course_name, c.course_code, c.color 
                       FROM exams_assignments ea 
                       JOIN courses c ON ea.course_id = c.id 
                       WHERE c.user_id = ? AND ea.status = 'pending' AND ea.due_date >= NOW() 
                       ORDER BY ea.due_date ASC LIMIT 5");
$stmt->execute([$userId]);
$upcomingExams = $stmt->fetchAll();

// En Yakın Sınava Kalan Gün
$nearestExam = $upcomingExams[0] ?? null;
$nearestExamDays = $nearestExam ? getDaysRemaining($nearestExam['due_date']) : null;

// Aktif Proje Sayısı
$stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ? AND status != 'completed'");
$stmt->execute([$userId]);
$activeProjectsCount = $stmt->fetchColumn();

// Bugünün Dersleri
$todayDay = date('l');
$stmt = $pdo->prepare("SELECT cs.*, c.course_name, c.course_code, c.color 
                       FROM course_schedules cs 
                       JOIN courses c ON cs.course_id = c.id 
                       WHERE c.user_id = ? AND cs.day_of_week = ? 
                       ORDER BY cs.start_time ASC");
$stmt->execute([$userId, $todayDay]);
$todaysClasses = $stmt->fetchAll();

// Bu Haftaki Toplam Çalışma Süresi (Dakika)
$stmt = $pdo->prepare("SELECT SUM(duration_minutes) FROM study_sessions 
                       WHERE user_id = ? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$stmt->execute([$userId]);
$weeklyStudyMinutes = (int)$stmt->fetchColumn();

// Son 7 günün günlük çalışma grafiği verisi
$stmt = $pdo->prepare("SELECT session_date, SUM(duration_minutes) as total_min 
                       FROM study_sessions 
                       WHERE user_id = ? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                       GROUP BY session_date ORDER BY session_date ASC");
$stmt->execute([$userId]);
$chartRows = $stmt->fetchAll();

$chartLabels = [];
$chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dLabel = date('d M', strtotime("-$i days"));
    $chartLabels[] = $dLabel;
    
    $found = 0;
    foreach ($chartRows as $cr) {
        if ($cr['session_date'] === $d) {
            $found = (int)$cr['total_min'];
            break;
        }
    }
    $chartValues[] = $found;
}

// Sabitlenmiş veya Son Notlar
$stmt = $pdo->prepare("SELECT n.*, c.course_code FROM notes n 
                       LEFT JOIN courses c ON n.course_id = c.id 
                       WHERE n.user_id = ? ORDER BY n.is_pinned DESC, n.updated_at DESC LIMIT 4");
$stmt->execute([$userId]);
$recentNotes = $stmt->fetchAll();

include_once __DIR__ . '/includes/header.php';
?>

<!-- Üst Karşılama Kartı -->
<div class="rounded-2xl p-6 md:p-8 glass-panel border border-slate-800">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div class="space-y-2">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-lg bg-slate-800 border border-slate-700 text-slate-300 text-xs font-medium">
                <i data-lucide="compass" class="w-3.5 h-3.5 text-sky-400"></i>
                <span>Akademik Strateji &bull; Mezuniyet Takibi</span>
            </div>
            <h2 class="text-2xl font-bold text-white tracking-tight">
                Hoş Geldin, <?= e($currentUser['full_name'] ?: $currentUser['username']) ?> 👋
            </h2>
            <p class="text-xs text-slate-400 max-w-2xl leading-relaxed">
                Kalan <strong class="text-slate-200"><?= $activeCoursesCount ?> dersini</strong> ve kişisel projelerini planına göre takip edebilirsin.
            </p>
        </div>

        <!-- Hızlı Aksiyon Butonları -->
        <div class="flex flex-wrap items-center gap-3">
            <a href="pomodoro.php" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium text-xs flex items-center gap-2 transition border border-slate-700">
                <i data-lucide="play" class="w-4 h-4 text-emerald-400"></i>
                <span>Odaklan (Pomodoro)</span>
            </a>
            <a href="ai-assistant.php" class="px-4 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-semibold text-xs flex items-center gap-2 transition shadow-sm">
                <i data-lucide="sparkles" class="w-4 h-4"></i>
                <span>AI Çalışma Planı</span>
            </a>
        </div>
    </div>
</div>

<!-- 4'lü Temel Metrik Kartları -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
    <!-- Kart 1: Kalan Dersler -->
    <div class="glass-panel glass-card-hover rounded-2xl p-5 relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Kalan Dersler</span>
            <div class="w-8 h-8 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400">
                <i data-lucide="book-open" class="w-4 h-4"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-extrabold text-white"><?= $activeCoursesCount ?></span>
            <span class="text-xs text-slate-400">Ders (<?= $totalAkts ?> AKTS)</span>
        </div>
        <div class="mt-3 text-xs text-sky-400 flex items-center gap-1 font-medium">
            <a href="courses.php" class="hover:underline flex items-center gap-1">Ders listesi <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i></a>
        </div>
    </div>

    <!-- Kart 2: En Yakın Sınav -->
    <div class="glass-panel glass-card-hover rounded-2xl p-5 relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">En Yakın Sınav</span>
            <div class="w-8 h-8 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-amber-400">
                <i data-lucide="clock" class="w-4 h-4"></i>
            </div>
        </div>
        <?php if ($nearestExam): ?>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-amber-400"><?= $nearestExamDays ?></span>
                <span class="text-xs text-slate-400">Gün Kaldı</span>
            </div>
            <div class="mt-3 text-xs text-slate-300 truncate">
                <strong class="text-white"><?= e($nearestExam['course_code']) ?>:</strong> <?= e($nearestExam['title']) ?>
            </div>
        <?php else: ?>
            <div class="flex items-baseline gap-2">
                <span class="text-xl font-bold text-slate-400">Planlanmadı</span>
            </div>
            <div class="mt-3 text-xs text-slate-500">
                <a href="schedule.php" class="text-sky-400 hover:underline">+ Yeni sınav ekle</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Kart 3: Aktif Projeler -->
    <div class="glass-panel glass-card-hover rounded-2xl p-5 relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Yazılım Projeleri</span>
            <div class="w-8 h-8 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400">
                <i data-lucide="code-2" class="w-4 h-4"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-extrabold text-white"><?= $activeProjectsCount ?></span>
            <span class="text-xs text-slate-400">Aktif Proje</span>
        </div>
        <div class="mt-3 text-xs text-sky-400 flex items-center gap-1 font-medium">
            <a href="projects.php" class="hover:underline flex items-center gap-1">Kanban tahtası <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i></a>
        </div>
    </div>

    <!-- Kart 4: Haftalık Çalışma Süresi -->
    <div class="glass-panel glass-card-hover rounded-2xl p-5 relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Haftalık Odak</span>
            <div class="w-8 h-8 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-emerald-400">
                <i data-lucide="flame" class="w-4 h-4"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-extrabold text-white"><?= round($weeklyStudyMinutes / 60, 1) ?></span>
            <span class="text-xs text-slate-400">Saat (<?= $weeklyStudyMinutes ?> dk)</span>
        </div>
        <div class="mt-3 text-xs text-emerald-400 flex items-center gap-1 font-medium">
            <span>✓ Düzenli çalışma</span>
        </div>
    </div>
</div>

<!-- Orta Blok: Yaklaşan Sınavlar & Bugünün Programı & Haftalık Grafik -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- 1. Yaklaşan Sınavlar ve Teslimler -->
    <div class="lg:col-span-2 glass-panel rounded-2xl p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
            <h3 class="font-bold text-white text-sm flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                <span>Yaklaşan Sınavlar & Teslim Tarihleri</span>
            </h3>
            <a href="schedule.php" class="text-xs font-semibold text-sky-400 hover:underline">Tümünü Gör</a>
        </div>

        <?php if (!empty($upcomingExams)): ?>
            <div class="space-y-3">
                <?php foreach ($upcomingExams as $exam): ?>
                    <?php 
                        $days = getDaysRemaining($exam['due_date']); 
                        $badgeColor = ($days <= 3) ? 'bg-red-500/15 text-red-300 border-red-500/30' : (($days <= 7) ? 'bg-amber-500/15 text-amber-300 border-amber-500/30' : 'bg-slate-800 text-slate-300 border-slate-700');
                    ?>
                    <div class="flex items-center justify-between p-4 rounded-xl bg-slate-900/60 border border-slate-800 hover:border-slate-700 transition">
                        <div class="flex items-center gap-3.5">
                            <div class="w-2.5 h-10 rounded-full bg-sky-500"></div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700"><?= e($exam['course_code']) ?></span>
                                    <span class="text-xs font-semibold text-white"><?= e($exam['title']) ?></span>
                                    <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-slate-800 text-slate-400"><?= e($exam['type']) ?></span>
                                </div>
                                <p class="text-xs text-slate-400 mt-1 flex items-center gap-2">
                                    <i data-lucide="calendar" class="w-3 h-3 text-slate-500"></i>
                                    <span><?= formatTurkishDate($exam['due_date'], true) ?></span>
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 rounded-lg text-xs font-bold border <?= $badgeColor ?>">
                                <?= ($days == 0) ? 'Bugün!' : (($days < 0) ? 'Geçti' : $days . ' Gün') ?>
                            </span>
                            <a href="ai-assistant.php?prompt=<?= urlencode($exam['course_code'] . ' ' . $exam['title'] . ' sınavı için ' . $days . ' günüm var. Nasıl çalışmalıyım?') ?>" title="Bu sınava AI ile çalışma planı çıkar" class="p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                                <i data-lucide="sparkles" class="w-3.5 h-3.5 text-sky-400"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-8 text-center bg-slate-900/30 rounded-xl border border-dashed border-slate-800">
                <i data-lucide="check-circle-2" class="w-8 h-8 text-emerald-400 mx-auto mb-2 opacity-80"></i>
                <p class="text-xs text-slate-300 font-medium">Yakın tarihte bir sınav veya teslim bulunmuyor.</p>
                <p class="text-[11px] text-slate-500 mt-1">Ders programı sayfasından yeni sınavlar ekleyebilirsin.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- 2. Bugünün Ders Programı & Haftalık Grafik -->
    <div class="glass-panel rounded-2xl p-6 space-y-5">
        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
            <h3 class="font-bold text-white text-sm flex items-center gap-2">
                <i data-lucide="calendar" class="w-4 h-4 text-sky-400"></i>
                <span>Bugünün Dersleri</span>
            </h3>
            <span class="text-xs text-slate-400"><?= formatTurkishDate(date('Y-m-d')) ?></span>
        </div>

        <?php if (!empty($todaysClasses)): ?>
            <div class="space-y-2.5">
                <?php foreach ($todaysClasses as $tc): ?>
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-sky-400"><?= e($tc['course_code']) ?></span>
                            <h4 class="text-xs font-semibold text-white"><?= e($tc['course_name']) ?></h4>
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                <?= substr($tc['start_time'], 0, 5) ?> - <?= substr($tc['end_time'], 0, 5) ?>
                                <?php if ($tc['classroom']): ?> &bull; <?= e($tc['classroom']) ?><?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-6 text-center bg-slate-900/30 rounded-xl border border-dashed border-slate-800">
                <i data-lucide="coffee" class="w-6 h-6 text-slate-400 mx-auto mb-1.5 opacity-80"></i>
                <p class="text-xs text-slate-300 font-medium">Bugün dersiniz bulunmuyor.</p>
            </div>
        <?php endif; ?>

        <!-- Mini Haftalık Odak Grafiği -->
        <div class="pt-3 border-t border-slate-800">
            <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Haftalık Çalışma (Dk)</h4>
            <div id="weeklyStudyChart" class="h-40"></div>
        </div>
    </div>

</div>

<!-- Alt Kısım: Son Notlar & Hızlı Yapay Zeka Tavsiyesi -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Son Notlar -->
    <div class="glass-panel rounded-2xl p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
            <h3 class="font-bold text-white text-sm flex items-center gap-2">
                <i data-lucide="file-text" class="w-4 h-4 text-slate-400"></i>
                <span>Önemli Notlar</span>
            </h3>
            <a href="notes.php" class="text-xs font-semibold text-sky-400 hover:underline">+ Yeni Not</a>
        </div>

        <?php if (!empty($recentNotes)): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php foreach ($recentNotes as $note): ?>
                    <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 hover:border-slate-700 transition flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <?php if ($note['course_code']): ?>
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-800 text-sky-300"><?= e($note['course_code']) ?></span>
                                <?php else: ?>
                                    <span class="text-[10px] text-slate-400">Genel</span>
                                <?php endif; ?>
                                <?php if ($note['is_pinned']): ?>
                                    <i data-lucide="pin" class="w-3.5 h-3.5 text-amber-400 fill-amber-400"></i>
                                <?php endif; ?>
                            </div>
                            <h4 class="text-xs font-semibold text-white mb-1 line-clamp-1"><?= e($note['title']) ?></h4>
                            <p class="text-xs text-slate-400 line-clamp-2 leading-relaxed">
                                <?= e(strip_tags($note['content'])) ?>
                            </p>
                        </div>
                        <div class="mt-3 pt-2 border-t border-slate-800 flex items-center justify-between text-[10px] text-slate-500">
                            <span><?= formatTurkishDate($note['updated_at']) ?></span>
                            <a href="notes.php?id=<?= $note['id'] ?>" class="text-sky-400 hover:underline">Aç</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-xs text-slate-400 text-center py-6">Henüz bir not eklenmedi. <a href="notes.php" class="text-sky-400 underline">İlk notunu ekle!</a></p>
        <?php endif; ?>
    </div>

    <!-- Gemini AI Hızlı Çalışma Koçu -->
    <div class="glass-panel rounded-2xl p-6 relative overflow-hidden flex flex-col justify-between">
        <div class="space-y-3">
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400">
                    <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
                </div>
                <h3 class="font-bold text-white text-sm">Akademik Çalışma Tavsiyesi</h3>
            </div>
            <div class="p-4 rounded-xl bg-slate-900/80 border border-slate-800 text-slate-300 text-xs leading-relaxed">
                <p>
                    💡 "Uzatma döneminde konuları son günlere bırakmamak en büyük avantajdır. Günlük 25 dakikalık 2 adet Pomodoro odak seansı tamamlayarak hedeflerine rahatlıkla ulaşabilirsin."
                </p>
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-slate-800 flex items-center justify-between">
            <span class="text-xs text-slate-400">Kişiselleştirilmiş plan ister misin?</span>
            <a href="ai-assistant.php" class="px-3.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-medium text-xs transition flex items-center gap-1.5">
                <i data-lucide="message-square-plus" class="w-3.5 h-3.5 text-sky-400"></i>
                <span>AI Asistana Sor</span>
            </a>
        </div>
    </div>
</div>

<!-- ApexCharts Script -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const chartOptions = {
        series: [{
            name: 'Çalışma Süresi',
            data: <?= json_encode($chartValues) ?>
        }],
        chart: {
            type: 'area',
            height: 150,
            toolbar: { show: false },
            sparkline: { enabled: false }
        },
        colors: ['#0ea5e9'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.35,
                opacityTo: 0.05,
                stops: [0, 90, 100]
            }
        },
        stroke: { curve: 'smooth', width: 2 },
        dataLabels: { enabled: false },
        xaxis: {
            categories: <?= json_encode($chartLabels) ?>,
            labels: { style: { colors: '#64748b', fontSize: '10px' } },
            axisBorder: { show: false },
            axisTicks: { show: false }
        },
        yaxis: {
            labels: { style: { colors: '#64748b', fontSize: '10px' }, formatter: (val) => val + ' dk' }
        },
        grid: { borderColor: '#1e293b', strokeDashArray: 3 },
        tooltip: {
            theme: 'dark',
            y: { formatter: (val) => val + ' Dakika Odaklanma' }
        }
    };

    const chart = new ApexCharts(document.querySelector("#weeklyStudyChart"), chartOptions);
    chart.render();
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
