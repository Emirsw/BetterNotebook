<?php
// BETTERNOTEBOOK_ENTRY: preserve legacy POST handlers and an explicit fallback.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['legacy'])) {
    $notebookView = 'timer';
    require __DIR__ . '/includes/notebook_app.php';
    return;
}

$pageTitle = "Pomodoro Odaklanma";
require_once __DIR__ . '/includes/auth_check.php';

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT id, course_code, course_name FROM courses WHERE user_id = ?");
$stmt->execute([$userId]);
$myCourses = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, title FROM projects WHERE user_id = ?");
$stmt->execute([$userId]);
$myProjects = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT ss.*, c.course_code, c.course_name, p.title as project_title 
                       FROM study_sessions ss 
                       LEFT JOIN courses c ON ss.course_id = c.id 
                       LEFT JOIN projects p ON ss.project_id = p.id 
                       WHERE ss.user_id = ? 
                       ORDER BY ss.created_at DESC LIMIT 10");
$stmt->execute([$userId]);
$recentSessions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT SUM(duration_minutes) FROM study_sessions WHERE user_id = ? AND session_date = CURDATE()");
$stmt->execute([$userId]);
$todayMinutes = (int)$stmt->fetchColumn();

include_once __DIR__ . '/includes/header.php';
?>

<!-- Üst Başlık & İstatistik -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-white">Pomodoro Odaklanma Zamanlayıcısı</h2>
        <p class="text-xs text-slate-400 mt-0.5">25 dakikalık odak bloklarıyla ders ve projelerini kesintisiz çalış.</p>
    </div>
    <div class="flex items-center gap-3">
        <div class="px-4 py-2 rounded-xl glass-panel border border-slate-800 flex items-center gap-2">
            <i data-lucide="flame" class="w-4 h-4 text-emerald-400"></i>
            <span class="text-xs text-slate-300">Bugünkü Odaklanma: <strong class="text-emerald-400 font-bold"><?= $todayMinutes ?> dk</strong></span>
        </div>
    </div>
</div>

<!-- Pomodoro Ana Alanı -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- Zamanlayıcı Kartı -->
    <div class="lg:col-span-2 glass-panel rounded-3xl p-8 flex flex-col items-center justify-center text-center relative overflow-hidden border border-slate-800">
        
        <!-- Mod Seçimi -->
        <div class="flex items-center gap-2 p-1.5 rounded-2xl bg-slate-900 border border-slate-800 mb-8">
            <button type="button" onclick="setMode(25, 'pomodoro')" id="btn-mode-pomodoro" class="px-4 py-2 rounded-xl text-xs font-semibold transition bg-sky-600 text-white">
                🎯 Odaklan (25 dk)
            </button>
            <button type="button" onclick="setMode(5, 'short_break')" id="btn-mode-short_break" class="px-4 py-2 rounded-xl text-xs font-semibold transition text-slate-400 hover:text-white">
                ☕ Kısa Mola (5 dk)
            </button>
            <button type="button" onclick="setMode(15, 'long_break')" id="btn-mode-long_break" class="px-4 py-2 rounded-xl text-xs font-semibold transition text-slate-400 hover:text-white">
                🌴 Uzun Mola (15 dk)
            </button>
        </div>

        <!-- Dijital Zaman Göstergesi -->
        <div class="relative mb-8">
            <div class="text-7xl sm:text-8xl font-bold tracking-tight text-white font-mono" id="timer-display">
                25:00
            </div>
            <span class="text-xs uppercase tracking-widest text-slate-400 font-medium mt-2 block" id="timer-status-text">Odaklanmaya Hazır</span>
        </div>

        <!-- İlgili Ders veya Proje Seçimi -->
        <div class="w-full max-w-md grid grid-cols-1 sm:grid-cols-2 gap-3 mb-8">
            <div>
                <label class="block text-[11px] font-semibold text-slate-400 mb-1 text-left">Çalışılan Ders</label>
                <select id="select-course" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    <option value="">-- Ders Seçiniz --</option>
                    <?php foreach ($myCourses as $mc): ?>
                        <option value="<?= $mc['id'] ?>"><?= e($mc['course_code']) ?> - <?= e($mc['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-400 mb-1 text-left">Veya Proje</label>
                <select id="select-project" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    <option value="">-- Proje Seçiniz --</option>
                    <?php foreach ($myProjects as $mp): ?>
                        <option value="<?= $mp['id'] ?>"><?= e($mp['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Kontrol Butonları -->
        <div class="flex items-center gap-3">
            <button type="button" id="btn-start" onclick="toggleTimer()" class="px-8 py-3 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-semibold text-xs shadow-sm transition flex items-center gap-2">
                <i data-lucide="play" class="w-4 h-4" id="btn-icon"></i>
                <span id="btn-label">Başlat</span>
            </button>
            <button type="button" onclick="resetTimer()" class="px-5 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs transition border border-slate-700 flex items-center gap-1.5">
                <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                <span>Sıfırla</span>
            </button>
        </div>

    </div>

    <!-- Son Çalışma Geçmişi -->
    <div class="glass-panel rounded-2xl p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-xs font-bold text-white flex items-center gap-2">
                <i data-lucide="history" class="w-4 h-4 text-slate-400"></i>
                <span>Son Odaklanma Oturumları</span>
            </h3>
        </div>

        <?php if (!empty($recentSessions)): ?>
            <div class="space-y-2 max-h-[420px] overflow-y-auto pr-1">
                <?php foreach ($recentSessions as $session): ?>
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800 flex items-center justify-between">
                        <div>
                            <div class="flex items-center gap-1.5">
                                <?php if ($session['course_code']): ?>
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-800 text-sky-300"><?= e($session['course_code']) ?></span>
                                <?php elseif ($session['project_title']): ?>
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-800 text-slate-300">🚀 <?= e($session['project_title']) ?></span>
                                <?php else: ?>
                                    <span class="text-[10px] text-slate-400">Genel Çalışma</span>
                                <?php endif; ?>
                            </div>
                            <span class="text-[10px] text-slate-500 mt-1 block"><?= formatTurkishDate($session['session_date']) ?></span>
                        </div>
                        <span class="text-xs font-bold text-emerald-400">+<?= $session['duration_minutes'] ?> dk</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-xs text-slate-400 text-center py-8">Henüz bir odaklanma kaydı bulunmuyor.</p>
        <?php endif; ?>
    </div>

</div>

<!-- Pomodoro Script & Web Audio Synthesizer -->
<script>
let totalSeconds = 25 * 60;
let initialMinutes = 25;
let timerInterval = null;
let isRunning = false;
let currentMode = 'pomodoro';

function updateDisplay() {
    const min = Math.floor(totalSeconds / 60);
    const sec = totalSeconds % 60;
    const str = `${String(min).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    document.getElementById('timer-display').innerText = str;
    document.title = `(${str}) Pomodoro - <?= APP_NAME ?>`;
}

function setMode(minutes, mode) {
    if (isRunning) toggleTimer();
    initialMinutes = minutes;
    totalSeconds = minutes * 60;
    currentMode = mode;
    updateDisplay();

    document.querySelectorAll('[id^="btn-mode-"]').forEach(btn => {
        btn.className = 'px-4 py-2 rounded-xl text-xs font-semibold transition text-slate-400 hover:text-white';
    });

    const activeBtn = document.getElementById('btn-mode-' + mode);
    if (activeBtn) {
        activeBtn.className = 'px-4 py-2 rounded-xl text-xs font-semibold transition bg-sky-600 text-white';
    }

    document.getElementById('timer-status-text').innerText = (mode === 'pomodoro') ? 'Odaklanmaya Hazır' : 'Mola Zamanı';
}

function toggleTimer() {
    const btnLabel = document.getElementById('btn-label');
    const statusText = document.getElementById('timer-status-text');

    if (isRunning) {
        clearInterval(timerInterval);
        isRunning = false;
        btnLabel.innerText = 'Devam Et';
        statusText.innerText = 'Duraklatıldı';
    } else {
        isRunning = true;
        btnLabel.innerText = 'Duraklat';
        statusText.innerText = (currentMode === 'pomodoro') ? 'Odaklanma Sürüyor...' : 'Mola Veriliyor...';
        
        timerInterval = setInterval(() => {
            if (totalSeconds > 0) {
                totalSeconds--;
                updateDisplay();
            } else {
                clearInterval(timerInterval);
                isRunning = false;
                playChime();
                btnLabel.innerText = 'Başlat';
                statusText.innerText = 'Süre Tamamlandı!';
                
                if (currentMode === 'pomodoro') {
                    saveSession(initialMinutes);
                } else {
                    showToast('info', 'Mola bitti!');
                }
            }
        }, 1000);
    }
}

function resetTimer() {
    if (isRunning) toggleTimer();
    totalSeconds = initialMinutes * 60;
    updateDisplay();
    document.getElementById('btn-label').innerText = 'Başlat';
    document.getElementById('timer-status-text').innerText = 'Sıfırlandı';
}

function saveSession(minutes) {
    const courseId = document.getElementById('select-course').value;
    const projectId = document.getElementById('select-project').value;

    fetch('api/pomodoro_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            duration_minutes: minutes,
            course_id: courseId || null,
            project_id: projectId || null,
            notes: 'Pomodoro Odak Seansı'
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Tebrikler! 🎉',
                text: `${minutes} dakikalık odaklanma oturumunu tamamladın.`,
                icon: 'success',
                confirmButtonText: 'Tamam',
                background: '#131b2e',
                color: '#f8fafc'
            }).then(() => location.reload());
        }
    });
}

function playChime() {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, audioCtx.currentTime);
        osc.frequency.setValueAtTime(880, audioCtx.currentTime + 0.2);
        gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 1.0);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 1.0);
    } catch (e) {}
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
