<?php
// BETTERNOTEBOOK_ENTRY: preserve legacy POST handlers and an explicit fallback.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['legacy'])) {
    $notebookView = 'settings';
    require __DIR__ . '/includes/notebook_app.php';
    return;
}

$pageTitle = "Ayarlar & Yapay Zeka";
require_once __DIR__ . '/includes/auth_check.php';

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Ayarları Kaydetme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $submittedKey = trim($_POST['gemini_api_key'] ?? '');
        $geminiApiKey = $submittedKey === '' ? null : bnEncryptGeminiKey($submittedKey, (int)$userId);
        $geminiModel = $_POST['gemini_model'] ?? 'gemini-2.0-flash';
        $currentTerm = trim($_POST['current_term'] ?? 'Uzatma Dönemi');
        $targetGpa = (float)($_POST['target_gpa'] ?? 3.00);
        $gradDate = !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null;

        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->execute([$fullName, $email, $userId]);

        $chk = $pdo->prepare("SELECT id FROM settings WHERE user_id = ?");
        $chk->execute([$userId]);
        if ($chk->fetch()) {
            $stmt = $pdo->prepare("UPDATE settings SET 
                gemini_api_key = COALESCE(?, gemini_api_key), gemini_model = ?, 
                current_term = ?, target_gpa = ?, graduation_date = ? 
                WHERE user_id = ?");
            $stmt->execute([
                $geminiApiKey, $geminiModel, 
                $currentTerm, $targetGpa, $gradDate, $userId
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO settings (
                user_id, gemini_api_key, gemini_model, 
                current_term, target_gpa, graduation_date
            ) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $geminiApiKey, $geminiModel, 
                $currentTerm, $targetGpa, $gradDate
            ]);
        }

        setFlash('success', 'Ayarlarınız başarıyla kaydedildi!');
        redirect('settings.php');
    } elseif ($action === 'change_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($newPass !== $confirmPass) {
            setFlash('error', 'Yeni şifreler eşleşmiyor.');
        } elseif (strlen($newPass) < 6) {
            setFlash('error', 'Yeni şifre en az 6 karakter olmalıdır.');
        } else {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();

            if ($u && password_verify($currentPass, $u['password_hash'])) {
                $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $upd->execute([$newHash, $userId]);
                setFlash('success', 'Şifreniz güncellendi.');
            } else {
                setFlash('error', 'Mevcut şifreniz hatalı.');
            }
        }
    }
}

$currentUser = getCurrentUser();

// AI Kullanım İstatistikleri
$todayAiCount = 0;
$totalAiNotes = 0;
try {
    $stmtAi = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE user_id = ? AND (is_ai = 1 OR tags LIKE '%AI%') AND DATE(updated_at) = CURDATE()");
    $stmtAi->execute([$userId]);
    $todayAiCount = (int)$stmtAi->fetchColumn();

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM notes WHERE user_id = ? AND (is_ai = 1 OR tags LIKE '%AI%')");
    $stmtTotal->execute([$userId]);
    $totalAiNotes = (int)$stmtTotal->fetchColumn();
} catch (Exception $e) {
    $todayAiCount = 0;
    $totalAiNotes = 0;
}

$selectedModel = $currentUser['gemini_model'] ?? 'gemini-2.0-flash';
$isPro = (strpos($selectedModel, 'pro') !== false);
$dailyLimit = $isPro ? 50 : 1500;
$rpmLimit = $isPro ? '2 İstek / dk' : '15 İstek / dk';
$tpmLimit = $isPro ? '32.000 Token / dk' : '1.000.000 Token / dk';
$remainingToday = max(0, $dailyLimit - $todayAiCount);
$usedPercent = min(100, round(($todayAiCount / $dailyLimit) * 100, 1));

include_once __DIR__ . '/includes/header.php';
?>

<!-- Üst Başlık -->
<div class="flex items-center justify-between">
    <div>
        <h2 class="text-xl font-bold text-white">Sistem & Profil Ayarları</h2>
        <p class="text-xs text-slate-400 mt-0.5">Gemini AI anahtarınızı yönetin, kalan kullanım kotanızı takip edin ve profilinizi güncelleyin.</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- 1. Google Gemini API Ayarları & Kota Paneli (Sol Kolon) -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- Gemini AI Yapılandırması & Profil Formu -->
        <div class="glass-panel rounded-2xl p-6 space-y-5 border border-slate-800">
            <div class="flex items-start justify-between gap-4 border-b border-slate-800 pb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-sky-400">
                            <i data-lucide="sparkles" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-sm font-bold text-white">Google Gemini AI Yapılandırması (Multimodal PDF & Görsel)</h3>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">
                        Sistemin PDF ders notlarını, el yazılarını ve sınav sorularını eksiksiz analiz edebilmesi için ücretsiz Google Gemini API anahtarınızı girin.
                    </p>
                </div>
                <a href="https://aistudio.google.com/app/apikey" target="_blank" class="flex-shrink-0 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 text-xs font-semibold flex items-center gap-1.5 transition">
                    <span>Ücretsiz Key Al</span>
                    <i data-lucide="external-link" class="w-3 h-3"></i>
                </a>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_settings">
                
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Gemini API Key</label>
                    <div class="relative">
                        <i data-lucide="key" class="w-4 h-4 absolute left-3.5 top-3 text-slate-400"></i>
                        <input type="password" name="gemini_api_key" autocomplete="new-password" placeholder="AIzaSy..." class="w-full bg-slate-900 border border-slate-800 rounded-xl pl-10 pr-4 py-2 text-xs text-white focus:outline-none focus:border-sky-500 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Gemini Modeli</label>
                        <select name="gemini_model" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                            <option value="gemini-2.0-flash" <?= in_array($currentUser['gemini_model'] ?? '', ['gemini-2.0-flash', '']) ? 'selected' : '' ?>>Gemini 2.0 Flash (Önerilen • Hızlı, Multimodal & Kararlı)</option>
                            <option value="gemini-1.5-flash" <?= ($currentUser['gemini_model'] ?? '') === 'gemini-1.5-flash' ? 'selected' : '' ?>>Gemini 1.5 Flash (Ultra Kararlı • 1.500 İstek/Gün)</option>
                            <option value="gemini-2.5-flash" <?= ($currentUser['gemini_model'] ?? '') === 'gemini-2.5-flash' ? 'selected' : '' ?>>Gemini 2.5 Flash (Yeni Nesil)</option>
                            <option value="gemini-3.1-pro-preview" <?= in_array($currentUser['gemini_model'] ?? '', ['gemini-3.1-pro-preview', 'gemini-2.5-pro', 'gemini-pro-latest']) ? 'selected' : '' ?>>Gemini 3.1 Pro Preview (Derin Akıl Yürütme)</option>
                            <option value="gemini-1.5-pro" <?= ($currentUser['gemini_model'] ?? '') === 'gemini-1.5-pro' ? 'selected' : '' ?>>Gemini 1.5 Pro (Geniş 2M Hafıza)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Dönem Adı</label>
                        <input type="text" name="current_term" value="<?= e($currentUser['current_term'] ?? 'Bahar / Uzatma Dönemi') ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Hedef GPA</label>
                        <input type="number" step="0.01" min="2.00" max="4.00" name="target_gpa" value="<?= e($currentUser['target_gpa'] ?? 3.25) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Hedef Mezuniyet Tarihi</label>
                        <input type="date" name="graduation_date" value="<?= e($currentUser['graduation_date'] ?? '') ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    </div>
                </div>

                <!-- Tekil Profil Bilgileri (Ad Soyad & E-posta) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-slate-800/80">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Ad Soyad</label>
                        <input type="text" name="full_name" value="<?= e($currentUser['full_name'] ?? '') ?>" placeholder="Adınız Soyadınız" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">E-Posta</label>
                        <input type="email" name="email" value="<?= e($currentUser['email'] ?? '') ?>" placeholder="eposta@ornek.com" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-800 flex justify-end">
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-semibold text-xs shadow-sm transition flex items-center gap-1.5">
                        <i data-lucide="save" class="w-3.5 h-3.5"></i>
                        <span>Ayarları Kaydet</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Gemini AI Ücretsiz Kullanım & Kota Takip Paneli -->
        <div class="glass-panel rounded-2xl p-6 space-y-5 border border-slate-800 relative overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 pb-4">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                        <i data-lucide="gauge" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                            Gemini AI Ücretsiz Kullanım & Kota Takibi
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                Ücretsiz Paket ($0.00 / Ay)
                            </span>
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">Google AI Studio Free Tier resmi kullanım limitleri ve canlı bağlantı durumu</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button id="btn-test-api-quota" onclick="testGeminiQuota()" type="button" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-semibold flex items-center gap-1.5 transition">
                        <i data-lucide="activity" class="w-3.5 h-3.5 text-sky-400"></i>
                        <span>Canlı Bağlantıyı Test Et</span>
                    </button>
                    <a href="https://aistudio.google.com/app/plan_information" target="_blank" class="px-3 py-1.5 rounded-xl bg-sky-500/10 hover:bg-sky-500/20 text-sky-400 border border-sky-500/30 text-xs font-semibold flex items-center gap-1.5 transition">
                        <span>Resmi Kota Paneli</span>
                        <i data-lucide="external-link" class="w-3 h-3"></i>
                    </a>
                </div>
            </div>

            <!-- Canlı Test Sonuç Alanı (AJAX ile dolar) -->
            <div id="api-test-result" class="hidden p-3 rounded-xl border text-xs transition"></div>

            <!-- Kota İlerleme & Sayaç Kartı -->
            <div class="bg-slate-900/60 border border-slate-800/80 rounded-xl p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between text-xs gap-2">
                    <span class="text-slate-300 font-semibold flex items-center gap-1.5">
                        <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                        Günlük İstek Kotası (RPD):
                    </span>
                    <span class="text-white font-mono font-bold">
                        Kalan: <span class="text-emerald-400"><?= number_format($remainingToday) ?></span> / <?= number_format($dailyLimit) ?> İstek
                    </span>
                </div>

                <!-- İlerleme Çubuğu -->
                <div class="w-full bg-slate-800 rounded-full h-2.5 overflow-hidden">
                    <div class="bg-emerald-500 h-2.5 rounded-full transition-all duration-500" style="width: <?= max(2, $usedPercent) ?>%"></div>
                </div>

                <div class="flex items-center justify-between text-[11px] text-slate-400">
                    <span>Bugün sistemde <strong><?= $todayAiCount ?></strong> adet AI işlemi yapıldı</span>
                    <span>%<?= $usedPercent ?> Kullanıldı</span>
                </div>
            </div>

            <!-- Limitler Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="bg-slate-900/40 border border-slate-800 rounded-xl p-3 text-center space-y-1">
                    <div class="text-[11px] text-slate-400">Günlük İstek Limiti (RPD)</div>
                    <div class="text-sm font-bold text-white font-mono"><?= number_format($dailyLimit) ?> İstek / Gün</div>
                    <div class="text-[10px] text-slate-500">Her gece 00:00 PST sıfırlanır</div>
                </div>
                <div class="bg-slate-900/40 border border-slate-800 rounded-xl p-3 text-center space-y-1">
                    <div class="text-[11px] text-slate-400">Dakikalık İstek Hızı (RPM)</div>
                    <div class="text-sm font-bold text-white font-mono"><?= $rpmLimit ?></div>
                    <div class="text-[10px] text-slate-500">Anlık istek sınırlayıcı</div>
                </div>
                <div class="bg-slate-900/40 border border-slate-800 rounded-xl p-3 text-center space-y-1">
                    <div class="text-[11px] text-slate-400">Dakikalık Token Hacmi (TPM)</div>
                    <div class="text-sm font-bold text-white font-mono"><?= $tpmLimit ?></div>
                    <div class="text-[10px] text-slate-500">Multimodal PDF & Metin</div>
                </div>
            </div>

            <div class="p-3 bg-slate-900/30 border border-slate-800/60 rounded-xl text-[11px] text-slate-400 space-y-1.5 leading-relaxed">
                <div class="flex items-center gap-1.5 font-semibold text-slate-300">
                    <i data-lucide="info" class="w-3.5 h-3.5 text-sky-400"></i>
                    <span>Ücretsiz Kullanım Hakkında Önemli Bilgiler:</span>
                </div>
                <p>
                    • Google AI Studio ücretsiz planında (Free Tier) hiçbir ücret yansıtılmaz ($0.00).
                </p>
                <p>
                    • <strong><?= htmlspecialchars($selectedModel) ?></strong> modeli ile günde <strong><?= number_format($dailyLimit) ?> adede kadar</strong> kapsamlı sınav analizi, PDF özetleme ve soru tahmini yapabilirsiniz.
                </p>
                <p>
                    • Gerçek zamanlı grafikler, anlık token tüketimi ve detaylı Google Cloud kotaları için yukarıdaki <strong>"Resmi Kota Paneli"</strong> bağlantısını kullanabilirsiniz.
                </p>
            </div>
        </div>

    </div>

    <!-- 2. Şifre Değiştirme & Sistem Bilgileri (Sağ Kolon) -->
    <div class="space-y-6">
        
        <div class="glass-panel rounded-2xl p-6 space-y-4 border border-slate-800">
            <h3 class="text-xs font-bold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
                <i data-lucide="shield" class="w-4 h-4 text-emerald-400"></i>
                <span>Şifre Güncelle</span>
            </h3>

            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="change_password">

                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Mevcut Şifre</label>
                    <input type="password" name="current_password" required placeholder="••••••••" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Yeni Şifre</label>
                    <input type="password" name="new_password" required placeholder="••••••••" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Yeni Şifre Tekrar</label>
                    <input type="password" name="confirm_password" required placeholder="••••••••" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-sky-500">
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-semibold text-xs border border-slate-700 transition flex items-center justify-center gap-1.5">
                        <i data-lucide="lock" class="w-3.5 h-3.5"></i>
                        <span>Şifremi Güncelle</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="glass-panel rounded-2xl p-6 space-y-3 text-xs border border-slate-800">
            <h3 class="text-xs font-bold text-white flex items-center gap-2 border-b border-slate-800 pb-2">
                <i data-lucide="server" class="w-4 h-4 text-sky-400"></i>
                <span>Sistem & Sunucu Durumu</span>
            </h3>

            <div class="space-y-2 text-slate-400 text-[11px]">
                <div class="flex justify-between">
                    <span>PHP Sürümü:</span>
                    <strong class="text-slate-200"><?= phpversion() ?></strong>
                </div>
                <div class="flex justify-between">
                    <span>Veritabanı:</span>
                    <span class="text-emerald-400 font-bold">MySQL (Bağlı ✓)</span>
                </div>
                <div class="flex justify-between">
                    <span>Aktif AI Modeli:</span>
                    <strong class="text-sky-400 font-mono"><?= htmlspecialchars($selectedModel) ?></strong>
                </div>
                <div class="flex justify-between">
                    <span>Toplam AI Not/Özet Sayısı:</span>
                    <strong class="text-slate-200"><?= $totalAiNotes ?> adet</strong>
                </div>
                <div class="flex justify-between">
                    <span>Sistem Versiyonu:</span>
                    <strong class="text-sky-400"><?= APP_VERSION ?></strong>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
async function testGeminiQuota() {
    const btn = document.getElementById('btn-test-api-quota');
    const resultBox = document.getElementById('api-test-result');
    if (!btn || !resultBox) return;

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="inline-block w-3.5 h-3.5 border-2 border-sky-400 border-t-transparent rounded-full animate-spin"></span> <span>Test Ediliyor...</span>`;
    
    resultBox.className = 'p-3 rounded-xl border border-slate-800 bg-slate-900/60 text-xs text-slate-300 flex items-center gap-2';
    resultBox.innerHTML = `<span>Google AI Studio sunucuları ile bağlantı kuruluyor...</span>`;
    resultBox.classList.remove('hidden');

    try {
        const res = await fetch('api/test_gemini_quota.php');
        const data = await res.json();

        if (data.success) {
            resultBox.className = 'p-3 rounded-xl border border-emerald-500/30 bg-emerald-950/20 text-xs text-emerald-300 space-y-1.5';
            resultBox.innerHTML = `
                <div class="flex items-center justify-between font-bold">
                    <span class="flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                        ${data.message}
                    </span>
                    <span class="text-[11px] font-mono text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20">⚡ ${data.data.latency_ms} ms</span>
                </div>
                <div class="text-[11px] text-slate-300">
                    Aktif Model: <strong class="text-white">${data.data.model}</strong> • Paket: <strong class="text-white">${data.data.plan_name}</strong> (${data.data.price}) • Günlük İstek Kotası: <strong class="text-white">${data.data.rpd} İstek/Gün</strong>
                </div>
            `;
        } else {
            resultBox.className = 'p-3 rounded-xl border border-rose-500/30 bg-rose-950/20 text-xs text-rose-300 space-y-1';
            resultBox.innerHTML = `
                <div class="font-bold flex items-center gap-1.5">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-rose-400"></i>
                    API Doğrulama Başarısız
                </div>
                <div class="text-[11px] text-rose-200/90">${data.error || 'Bilinmeyen bir hata oluştu.'}</div>
            `;
        }
    } catch (err) {
        resultBox.className = 'p-3 rounded-xl border border-rose-500/30 bg-rose-950/20 text-xs text-rose-300';
        resultBox.innerHTML = `Bağlantı hatası: ` + err.message;
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
