<?php
/**
 * Global Header Bileşeni
 */
require_once __DIR__ . '/../config/config.php';
$currentUser = getCurrentUser();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="tr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?><?= APP_NAME ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS v3.4 CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                        },
                        dark: {
                            bg: '#0c111d',
                            card: '#131b2e',
                            cardHover: '#1a243b',
                            border: '#1e293b',
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <!-- ApexCharts -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- SortableJS -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <!-- PDF-Lib (Tarayıcıda Anında PDF Bölme & İşleme) -->
    <script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>

    <!-- KaTeX (Matematiksel Formül Render Motoru) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/auto-render.min.js"></script>

    <!-- Marked.js (Markdown Render Motoru) -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

    <!-- Custom Style -->
    <link rel="stylesheet" href="<?= getBaseUrl() ?>/assets/css/style.css">
</head>
<body class="bg-dark-bg bg-mesh text-slate-100 flex min-h-screen">

    <?php if ($currentUser): ?>
        <!-- Sol Menü (Sidebar) -->
        <?php include_once __DIR__ . '/sidebar.php'; ?>
        
        <!-- Ana İçerik Kapsayıcısı -->
        <div class="flex-1 flex flex-col min-w-0 md:pl-64 transition-all duration-300">
            <!-- Üst Bar (Topbar) -->
            <header class="sticky top-0 z-30 flex items-center justify-between h-16 px-6 glass-panel border-b border-slate-800">
                <!-- Mobil Menü Butonu & Başlık -->
                <div class="flex items-center gap-4">
                    <button id="mobile-menu-btn" class="md:hidden p-2 rounded-lg bg-slate-800 text-slate-300 hover:text-white">
                        <i data-lucide="menu" class="w-5 h-5"></i>
                    </button>
                    <div>
                        <h1 class="text-base font-bold text-slate-100 flex items-center gap-2">
                            <?= isset($pageTitle) ? e($pageTitle) : 'Dashboard' ?>
                        </h1>
                    </div>
                </div>

                <!-- Sağ Butonlar & Profil -->
                <div class="flex items-center gap-3">
                    <!-- Dönem Bilgisi Rozeti -->
                    <div class="hidden sm:flex items-center gap-2 px-3 py-1 rounded-lg bg-slate-800 border border-slate-700 text-slate-300 text-xs font-medium">
                        <i data-lucide="graduation-cap" class="w-3.5 h-3.5 text-sky-400"></i>
                        <span><?= e($currentUser['current_term'] ?? 'Son Sınıf (Uzatma)') ?></span>
                    </div>

                    <!-- AI Hızlı Asistan Butonu -->
                    <a href="ai-assistant.php" class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold transition">
                        <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">Gemini AI</span>
                    </a>

                    <!-- Profil & Çıkış -->
                    <div class="flex items-center gap-2 pl-2 border-l border-slate-800">
                        <a href="settings.php" class="flex items-center gap-2 p-1 rounded-lg hover:bg-slate-800 transition">
                            <div class="w-8 h-8 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400 font-bold text-sm">
                                <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 1)) ?>
                            </div>
                            <span class="hidden md:inline text-xs font-medium text-slate-300"><?= e($currentUser['username']) ?></span>
                        </a>
                        <a href="logout.php" title="Çıkış Yap" class="p-2 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition">
                            <i data-lucide="log-out" class="w-4 h-4"></i>
                        </a>
                    </div>
                </div>
            </header>

            <!-- Sayfa İçeriği -->
            <main class="flex-1 p-6 md:p-8 space-y-6">
    <?php endif; ?>

    <?php if ($flash): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                showToast('<?= $flash['type'] ?>', '<?= addslashes($flash['message']) ?>');
            });
        </script>
    <?php endif; ?>
