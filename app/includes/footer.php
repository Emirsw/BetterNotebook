<?php
/**
 * Global Footer Bileşeni
 */
?>
    <?php if (isset($currentUser) && $currentUser): ?>
            </main>
            
            <!-- Alt Bilgi (Footer) -->
            <footer class="px-6 py-3.5 border-t border-white/5 text-center text-xs text-zinc-500">
                <span><?= APP_NAME ?> &copy; <?= date('Y') ?></span>
            </footer>
        </div>
    <?php endif; ?>

    <!-- Global App JS -->
    <script src="<?= getBaseUrl() ?>/assets/js/app.js"></script>
    <script>
        // Mobil Menü Aç/Kapat
        const mobileBtn = document.getElementById('mobile-menu-btn');
        const sidebar = document.getElementById('main-sidebar');
        const backdrop = document.getElementById('sidebar-backdrop');
        const closeBtn = document.getElementById('sidebar-close-btn');

        if (mobileBtn && sidebar && backdrop) {
            mobileBtn.addEventListener('click', () => {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
            });

            const closeSidebar = () => {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            };

            if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
            backdrop.addEventListener('click', closeSidebar);
        }

        // Sayfa yüklendiğinde ikonları yenile
        if (window.lucide) {
            lucide.createIcons();
        }
    </script>
</body>
</html>
