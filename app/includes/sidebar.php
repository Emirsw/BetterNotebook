<?php
/**
 * Sol Menü (Sidebar)
 */
$currentPage = basename($_SERVER['PHP_SELF']);

// Kalan sınav sayısını ve aktif proje sayısını çekelim
$pdo = getDBConnection();
$sidebarExamsCount = 0;
$sidebarProjectsCount = 0;

if (isset($_SESSION['user_id'])) {
    $stmtEx = $pdo->prepare("SELECT COUNT(*) FROM exams_assignments ea 
                             JOIN courses c ON ea.course_id = c.id 
                             WHERE c.user_id = ? AND ea.status = 'pending' AND ea.due_date >= NOW()");
    $stmtEx->execute([$_SESSION['user_id']]);
    $sidebarExamsCount = $stmtEx->fetchColumn();

    $stmtPr = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ? AND status != 'completed'");
    $stmtPr->execute([$_SESSION['user_id']]);
    $sidebarProjectsCount = $stmtPr->fetchColumn();
}

$navItems = [
    [
        'title' => 'Kontrol Paneli',
        'url' => 'index.php',
        'icon' => 'layout-dashboard',
        'active' => ($currentPage == 'index.php')
    ],
    [
        'title' => 'Dersler & Notlar',
        'url' => 'courses.php',
        'icon' => 'book-open',
        'active' => ($currentPage == 'courses.php')
    ],
    [
        'title' => 'Program & Sınavlar',
        'url' => 'schedule.php',
        'icon' => 'calendar-clock',
        'badge' => $sidebarExamsCount > 0 ? $sidebarExamsCount : null,
        'badgeColor' => 'bg-amber-500/20 text-amber-300 border border-amber-500/30',
        'active' => ($currentPage == 'schedule.php')
    ],
    [
        'title' => 'Projeler & Kanban',
        'url' => 'projects.php',
        'icon' => 'kanban',
        'badge' => $sidebarProjectsCount > 0 ? $sidebarProjectsCount : null,
        'badgeColor' => 'bg-sky-500/20 text-sky-300 border border-sky-500/30',
        'active' => ($currentPage == 'projects.php')
    ],
    [
        'title' => 'Not Defteri',
        'url' => 'notes.php',
        'icon' => 'file-text',
        'active' => ($currentPage == 'notes.php')
    ],
    [
        'title' => 'Gemini AI Asistan',
        'url' => 'ai-assistant.php',
        'icon' => 'sparkles',
        'active' => ($currentPage == 'ai-assistant.php')
    ],
    [
        'title' => 'Pomodoro Sayacı',
        'url' => 'pomodoro.php',
        'icon' => 'timer',
        'active' => ($currentPage == 'pomodoro.php')
    ],
    [
        'title' => 'Ayarlar & API',
        'url' => 'settings.php',
        'icon' => 'settings',
        'active' => ($currentPage == 'settings.php')
    ],
];
?>

<!-- Mobil Arka Plan Karartması -->
<div id="sidebar-backdrop" class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm hidden md:hidden"></div>

<!-- Sidebar Kapsayıcısı -->
<aside id="main-sidebar" class="fixed top-0 bottom-0 left-0 z-50 w-64 glass-panel border-r border-slate-800 flex flex-col justify-between -translate-x-full md:translate-x-0 transition-transform duration-300">
    <!-- Üst Başlık & Logo -->
    <div>
        <div class="h-16 flex items-center justify-between px-6 border-b border-slate-800">
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="w-9 h-9 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400 group-hover:border-sky-500/50 transition">
                    <i data-lucide="compass" class="w-5 h-5"></i>
                </div>
                <div>
                    <span class="font-bold text-slate-100 text-sm tracking-tight block">AkademikHub</span>
                    <span class="text-[10px] text-slate-400 font-medium uppercase tracking-wider">Öğrenci Portalı</span>
                </div>
            </a>
            <button id="sidebar-close-btn" class="md:hidden text-slate-400 hover:text-white">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Navigasyon Linkleri -->
        <nav class="p-4 space-y-1 overflow-y-auto max-h-[calc(100vh-180px)]">
            <?php foreach ($navItems as $item): ?>
                <a href="<?= $item['url'] ?>" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl font-medium text-xs transition-all <?= $item['active'] ? 'bg-sky-600/15 text-sky-300 border border-sky-500/30' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60' ?>">
                    <div class="flex items-center gap-3">
                        <i data-lucide="<?= $item['icon'] ?>" class="w-4 h-4 <?= $item['active'] ? 'text-sky-400' : 'text-slate-400' ?>"></i>
                        <span><?= $item['title'] ?></span>
                    </div>
                    <?php if (isset($item['badge']) && $item['badge'] !== null): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $item['badgeColor'] ?>">
                            <?= $item['badge'] ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</aside>
