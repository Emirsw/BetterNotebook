<?php
$pageTitle = "Gemini AI Asistanı";
require_once __DIR__ . '/includes/auth_check.php';

$pdo = getDBConnection();
$userId = $_SESSION['user_id'];

// Geçmiş AI Logları
$stmt = $pdo->prepare("SELECT * FROM ai_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$userId]);
$pastLogs = $stmt->fetchAll();

// Kullanıcının notları (Referans için)
$stmt = $pdo->prepare("SELECT id, title, file_path, file_type, file_name FROM notes WHERE user_id = ? ORDER BY updated_at DESC");
$stmt->execute([$userId]);
$myNotes = $stmt->fetchAll();

$initialPrompt = $_GET['prompt'] ?? '';
$initialType = $_GET['type'] ?? 'study_plan';
$initialNoteId = isset($_GET['note_id']) ? (int)$_GET['note_id'] : 0;

include_once __DIR__ . '/includes/header.php';
?>

<!-- Üst Başlık -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h2 class="text-xl font-bold text-white">Gemini AI Akademik Asistan</h2>
            <span class="px-2.5 py-0.5 rounded-lg bg-slate-800 border border-slate-700 text-sky-400 text-xs font-semibold">
                <?= e($currentUser['gemini_model'] ?? 'gemini-2.5-flash') ?>
            </span>
            <span class="px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 text-[10px] font-bold">
                📄 PDF & 🖼️ Görsel Destekli
            </span>
        </div>
        <p class="text-xs text-slate-400 mt-0.5">Ders notlarını, PDF dökümanlarını veya tahta fotoğraflarını referans alarak soru tahminleri ve çalışma planı çıkar.</p>
    </div>

    <?php if (empty($currentUser['gemini_api_key'])): ?>
        <a href="settings.php" class="px-4 py-2 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-300 text-xs font-semibold flex items-center gap-2">
            <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-400"></i>
            <span>API Anahtarı Tanımla</span>
        </a>
    <?php endif; ?>
</div>

<!-- 4'lü Hızlı Araç Butonları -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
    <button type="button" onclick="selectAITool('study_plan', '📅 Sınav Çalışma Planı Üretici', 'Hangi dersten hangi sınava gireceksin ve kaç günün var?')" class="ai-tool-btn p-4 rounded-2xl glass-panel text-left transition border border-sky-500/40 bg-slate-900/90" id="tool-study_plan">
        <div class="w-8 h-8 rounded-xl bg-slate-800 flex items-center justify-center text-sky-400 mb-2">
            <i data-lucide="calendar" class="w-4 h-4"></i>
        </div>
        <h4 class="text-xs font-bold text-white">Çalışma Planı Üret</h4>
        <p class="text-[10px] text-slate-400 mt-0.5">Kalan güne göre program</p>
    </button>

    <button type="button" onclick="selectAITool('exam_prep', '📝 Sınav Sorusu Tahmin Edici', 'Ders notunu, PDF veya fotoğrafını seçerek olası sınav sorularını üret...')" class="ai-tool-btn p-4 rounded-2xl glass-panel text-left transition border border-slate-800 hover:border-slate-700" id="tool-exam_prep">
        <div class="w-8 h-8 rounded-xl bg-slate-800 flex items-center justify-center text-slate-300 mb-2">
            <i data-lucide="help-circle" class="w-4 h-4"></i>
        </div>
        <h4 class="text-xs font-bold text-white">Soru & Cevap Tahmini</h4>
        <p class="text-[10px] text-slate-400 mt-0.5">PDF/Fotoğraftan soru çıkar</p>
    </button>

    <button type="button" onclick="selectAITool('project_roadmap', '🚀 Proje & Kod Rehberi', 'Projenin adını, amacını ve kullandığın dilleri belirt...')" class="ai-tool-btn p-4 rounded-2xl glass-panel text-left transition border border-slate-800 hover:border-slate-700" id="tool-project_roadmap">
        <div class="w-8 h-8 rounded-xl bg-slate-800 flex items-center justify-center text-slate-300 mb-2">
            <i data-lucide="code-2" class="w-4 h-4"></i>
        </div>
        <h4 class="text-xs font-bold text-white">Proje Yol Haritası</h4>
        <p class="text-[10px] text-slate-400 mt-0.5">Mimari ve görev checklisti</p>
    </button>

    <button type="button" onclick="selectAITool('general', '💬 Serbest Akademik Sohbet', 'Ders çalışma motivasyonu veya akademik bir soru sor...')" class="ai-tool-btn p-4 rounded-2xl glass-panel text-left transition border border-slate-800 hover:border-slate-700" id="tool-general">
        <div class="w-8 h-8 rounded-xl bg-slate-800 flex items-center justify-center text-emerald-400 mb-2">
            <i data-lucide="sparkles" class="w-4 h-4"></i>
        </div>
        <h4 class="text-xs font-bold text-white">Genel Sohbet</h4>
        <p class="text-[10px] text-slate-400 mt-0.5">Mezuniyet ve strateji rehberi</p>
    </button>
</div>

<!-- Ana AI Çalışma Alanı -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- Sol Taraf: Giriş, Dosya Referansı ve Yanıt Alanı -->
    <div class="lg:col-span-2 space-y-4">
        <div class="glass-panel rounded-2xl p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 id="current-tool-title" class="text-xs font-bold text-white flex items-center gap-2">
                    <i data-lucide="sparkles" class="w-4 h-4 text-sky-400"></i>
                    <span>📅 Sınav Çalışma Planı Üretici</span>
                </h3>
            </div>

            <!-- Kayıtlı Not Referansı veya Doğrudan Dosya Yükleme -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3.5 rounded-xl bg-slate-900/80 border border-slate-800">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-300 mb-1 flex items-center gap-1">
                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-sky-400"></i>
                        <span>Kayıtlı Notu Referans Al (Opsiyonel)</span>
                    </label>
                    <select id="select-note-ref" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-sky-500">
                        <option value="">-- Not Seçilmedi (Serbest İstek) --</option>
                        <?php foreach ($myNotes as $note): ?>
                            <option value="<?= $note['id'] ?>" <?= ($note['id'] == $initialNoteId) ? 'selected' : '' ?>>
                                <?= e($note['title']) ?><?= !empty($note['file_path']) ? ' (📎 ' . ($note['file_type'] === 'pdf' ? 'PDF' : 'Görsel') . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-300 mb-1 flex items-center gap-1">
                        <i data-lucide="paperclip" class="w-3.5 h-3.5 text-emerald-400"></i>
                        <span>Veya Anlık PDF / PPTX Sunumu / Fotoğraf Yükle</span>
                    </label>
                    <input type="file" id="direct-file-input" accept=".pdf,.pptx,.ppt,application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.ms-powerpoint,image/jpeg,image/png,image/webp" class="block w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-[11px] file:font-semibold file:bg-slate-800 file:text-sky-300 hover:file:bg-slate-700 cursor-pointer">
                </div>
            </div>

            <div>
                <textarea id="ai-prompt-input" rows="4" placeholder="Hangi dersten hangi sınava gireceksin ve kaç günün var? Detayları yaz..." class="w-full bg-slate-900 border border-slate-800 rounded-2xl p-4 text-xs text-white focus:outline-none focus:border-sky-500 transition leading-relaxed"><?= e($initialPrompt) ?></textarea>
            </div>

            <div class="flex items-center justify-between">
                <span class="text-[10px] text-slate-500">Gemini 1.5 / 2.0 Multimodal (PDF & Görsel Okuma Aktif)</span>
                <button type="button" id="btn-generate-ai" onclick="submitAIPrompt()" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold flex items-center gap-2 shadow-sm transition">
                    <i data-lucide="send" class="w-3.5 h-3.5"></i>
                    <span id="btn-text">Yanıt Üret</span>
                </button>
            </div>
        </div>

        <!-- Yanıt Çıktı Kartı -->
        <div id="ai-response-container" class="glass-panel rounded-2xl p-6 hidden space-y-4 border border-sky-500/30 bg-slate-900/80 shadow-2xl">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 pb-3.5">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-sky-500/20 text-sky-400 flex items-center justify-center border border-sky-500/30 shadow-inner">
                        <i data-lucide="sparkles" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <span class="text-xs font-bold text-white block">Gemini AI Soru & Çözüm Analizi</span>
                        <span class="text-[10px] text-slate-400">Akademik Format & Matematiksel Formül Motoru Aktif</span>
                    </div>
                </div>

                <!-- Araç Çubuğu (Toolbar) -->
                <div class="flex items-center gap-1.5 flex-wrap">
                    <!-- Yazı Boyutu Ayarı -->
                    <div class="flex items-center bg-slate-800/80 rounded-lg p-0.5 border border-slate-700 mr-1">
                        <button onclick="changeFontSize('sm')" title="Küçük Yazı" class="px-2 py-1 text-[10px] font-bold text-slate-400 hover:text-white rounded">A-</button>
                        <button onclick="changeFontSize('md')" title="Normal Yazı" class="px-2 py-1 text-[10px] font-bold text-sky-400 bg-slate-750 rounded">A</button>
                        <button onclick="changeFontSize('lg')" title="Büyük Yazı" class="px-2 py-1 text-[10px] font-bold text-slate-400 hover:text-white rounded">A+</button>
                    </div>

                    <!-- Notlarıma Kaydet -->
                    <button onclick="saveResponseAsNote()" title="Bu yanıtı ders notlarıma kaydet" class="px-2.5 py-1.5 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 border border-emerald-500/30 text-xs font-medium flex items-center gap-1.5 transition">
                        <i data-lucide="bookmark-plus" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">Notlarıma Kaydet</span>
                    </button>

                    <!-- Kopyala -->
                    <button onclick="copyResponseText()" title="Panoya Kopyala" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium flex items-center gap-1.5 transition border border-slate-700">
                        <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                        <span>Kopyala</span>
                    </button>

                    <!-- Yazdır / PDF -->
                    <button onclick="printAIResponse()" title="Yazdır / PDF Al" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white text-xs font-medium flex items-center gap-1.5 transition border border-slate-700">
                        <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">Yazdır</span>
                    </button>
                </div>
            </div>

            <!-- AI İçerik Gövdesi -->
            <div id="ai-response-body" class="ai-markdown-content font-size-md p-1"></div>
        </div>
    </div>

    <!-- Sağ Taraf: Geçmiş İstekler -->
    <div class="glass-panel rounded-2xl p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-xs font-bold text-white flex items-center gap-2">
                <i data-lucide="history" class="w-4 h-4 text-slate-400"></i>
                <span>Geçmiş AI İstekleri</span>
            </h3>
            <span class="text-[10px] text-slate-500"><?= count($pastLogs) ?> Kayıt</span>
        </div>

        <?php if (!empty($pastLogs)): ?>
            <div class="space-y-2.5 max-h-[500px] overflow-y-auto pr-1">
                <?php foreach ($pastLogs as $log): ?>
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800 hover:border-slate-700 transition cursor-pointer" onclick="viewPastLog(<?= htmlspecialchars(json_encode($log['prompt_text'])) ?>, <?= htmlspecialchars(json_encode($log['response_text'])) ?>)">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[9px] uppercase font-bold px-1.5 py-0.5 rounded bg-slate-800 text-slate-300"><?= e($log['feature_type']) ?></span>
                            <span class="text-[10px] text-slate-500"><?= formatTurkishDate($log['created_at']) ?></span>
                        </div>
                        <p class="text-xs text-slate-200 font-medium line-clamp-2"><?= e($log['prompt_text']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-xs text-slate-400 text-center py-8">Henüz geçmiş bir AI isteğiniz bulunmuyor.</p>
        <?php endif; ?>
    </div>

</div>

<script>
let currentSelectedType = '<?= $initialType ?>';
let lastRawResponse = '';

function selectAITool(type, title, placeholder) {
    currentSelectedType = type;
    document.getElementById('current-tool-title').innerHTML = '<i data-lucide="sparkles" class="w-4 h-4 text-sky-400 inline"></i> <span>' + title + '</span>';
    document.getElementById('ai-prompt-input').placeholder = placeholder;

    document.querySelectorAll('.ai-tool-btn').forEach(btn => {
        btn.classList.remove('border-sky-500/40', 'bg-slate-900/90');
        btn.classList.add('border-slate-800');
    });

    const activeBtn = document.getElementById('tool-' + type);
    if (activeBtn) {
        activeBtn.classList.remove('border-slate-800');
        activeBtn.classList.add('border-sky-500/40', 'bg-slate-900/90');
    }
    lucide.createIcons();
}

function renderAIContent(rawMarkdown) {
    lastRawResponse = rawMarkdown;
    const resBody = document.getElementById('ai-response-body');
    if (!resBody) return;

    if (!rawMarkdown) {
        resBody.innerHTML = '';
        return;
    }

    renderMarkdownWithMath(resBody, rawMarkdown);
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

function enhanceAcademicDom(container) {
    // Soru başlıkları ve bölümleri tespit edip modern kart yapısına dönüştürelim
    const headings = container.querySelectorAll('h3, h4');
    headings.forEach(h => {
        const text = h.innerText.trim();
        if (/Soru\s*\d+/i.test(text)) {
            h.classList.add('flex', 'items-center', 'gap-2', 'text-sky-400', 'font-bold', 'border-b', 'border-sky-500/30', 'pb-2', 'mt-6');
            h.innerHTML = '<span class="px-2 py-0.5 rounded bg-sky-500/20 text-sky-300 text-xs font-mono font-bold border border-sky-500/30">SORU</span> ' + h.innerHTML;
        }
    });

    // Model Cevap & Çözüm bölümlerini vurgulayalım
    const paragraphs = container.querySelectorAll('p, blockquote');
    paragraphs.forEach(p => {
        const html = p.innerHTML;
        if (html.includes('Model Cevap') || html.includes('Model Çözüm')) {
            p.classList.add('ai-solution-card');
        } else if (html.includes('Püf Noktası') || html.includes('İpucu:')) {
            p.classList.add('ai-tip-card');
        }
    });
}

function changeFontSize(size) {
    const resBody = document.getElementById('ai-response-body');
    resBody.classList.remove('font-size-sm', 'font-size-md', 'font-size-lg');
    resBody.classList.add('font-size-' + size);
}

function printAIResponse() {
    window.print();
}

async function saveResponseAsNote() {
    if (!lastRawResponse) {
        showToast('warning', 'Kaydedilecek bir yanıt bulunamadı.');
        return;
    }

    const promptInput = document.getElementById('ai-prompt-input').value.trim();
    const selectNote = document.getElementById('select-note-ref');
    const noteId = selectNote ? selectNote.value : null;
    const selectedNoteText = (selectNote && selectNote.selectedIndex > 0) ? selectNote.options[selectNote.selectedIndex].text : '';

    if (noteId) {
        const result = await Swal.fire({
            title: 'Nasıl Kaydetmek İstersiniz?',
            html: `<div class="text-xs text-slate-300 space-y-1.5"><p>Referans Alınan Not:</p><p class="font-bold text-sky-400">${escapeHtml(selectedNoteText)}</p></div>`,
            icon: 'question',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: '📝 Seçili Notun İçine Kaydet (Güncelle)',
            denyButtonText: '➕ Ayrı Yeni Not Olarak Ekle',
            cancelButtonText: 'İptal',
            background: '#131b2e',
            color: '#fff',
            confirmButtonColor: '#059669',
            denyButtonColor: '#0284c7',
            cancelButtonColor: '#475569'
        });

        if (result.isConfirmed) {
            // Seçili referans notun içine doğrudan kaydet
            try {
                const res = await fetch('api/save_ai_note.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        note_id: noteId,
                        content: lastRawResponse,
                        update_current_note: true
                    })
                });
                const data = await res.json();
                if (data && data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Başarıyla Kaydedildi!',
                        text: 'AI incelemesi ve sınav çözümleri seçili notun içeriğine kaydedildi.',
                        background: '#131b2e',
                        color: '#fff',
                        confirmButtonColor: '#0ea5e9'
                    });
                } else {
                    showToast('error', (data && data.error) ? data.error : 'Kaydedilemedi.');
                }
            } catch(e) {
                showToast('error', 'Sunucu bağlantı hatası.');
            }
            return;
        } else if (!result.isDenied) {
            return; // İptal edildi
        }
    }

    const defaultTitle = promptInput ? (promptInput.substring(0, 50) + '... (AI Çözüm)') : 'AI Sınav & Çalışma Notu';

    const { value: title } = await Swal.fire({
        title: 'Yeni Not Olarak Kaydet',
        text: 'Bu AI analizini yeni bir ders notu olarak eklemek için başlık girin:',
        input: 'text',
        inputValue: defaultTitle,
        showCancelButton: true,
        confirmButtonText: 'Kaydet',
        cancelButtonText: 'İptal',
        background: '#131b2e',
        color: '#fff',
        confirmButtonColor: '#0ea5e9',
        cancelButtonColor: '#475569'
    });

    if (!title) return;

    try {
        const res = await fetch('api/save_ai_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title: title,
                content: lastRawResponse,
                course_id: null,
                ref_note_id: noteId || null,
                tags: 'AI Hazırlık, Sınav Soruları, Çözümler'
            })
        });
        const data = await res.json();
        if (data && data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Kaydedildi!',
                text: 'AI yanıtı ders notlarınıza başarıyla eklendi.',
                background: '#131b2e',
                color: '#fff',
                confirmButtonColor: '#0ea5e9'
            });
        } else {
            showToast('error', (data && data.error) ? data.error : 'Kaydedilemedi.');
        }
    } catch (e) {
        showToast('error', 'Sunucu bağlantı hatası.');
    }
}

async function submitAIPrompt() {
    const input = document.getElementById('ai-prompt-input');
    const prompt = input.value.trim();
    const noteId = document.getElementById('select-note-ref').value;
    const fileInput = document.getElementById('direct-file-input');

    if (!prompt && !noteId && !fileInput.files.length) {
        showToast('warning', 'Lütfen bir soru yazın veya referans bir not/dosya seçin.');
        return;
    }

    const btn = document.getElementById('btn-generate-ai');
    const btnText = document.getElementById('btn-text');
    const resContainer = document.getElementById('ai-response-container');
    const resBody = document.getElementById('ai-response-body');

    btn.disabled = true;
    btnText.innerText = 'AI İnceliyor...';
    resContainer.classList.remove('hidden');
    resBody.innerHTML = '<div class="flex items-center gap-3 text-sky-400 py-6 px-2"><i data-lucide="loader-2" class="w-6 h-6 animate-spin"></i><div><p class="font-bold text-sm text-white">Gemini Dökümanları & Formülleri İnceliyor</p><p class="text-xs text-slate-400">Kritik sınav soruları ve adım adım çözümler hazırlanıyor...</p></div></div>';
    lucide.createIcons();

    // Scroll to response
    resContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });

    let fileBase64 = null;
    let fileMime = null;

    if (fileInput.files && fileInput.files[0]) {
        const file = fileInput.files[0];
        fileMime = file.type || 'application/pdf';
        fileBase64 = await toBase64(file);
    }

    fetch('api/ai_prompt.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: currentSelectedType,
            prompt: prompt,
            note_id: noteId || null,
            file_base64: fileBase64,
            file_mime: fileMime
        })
    })
    .then(async res => {
        const text = await res.text();
        try {
            const jsonMatch = text.match(/\{[\s\S]*\}/);
            if (jsonMatch) return JSON.parse(jsonMatch[0]);
            throw new Error(text.replace(/<[^>]*>/g, '').trim());
        } catch (e) {
            throw new Error(text.replace(/<[^>]*>/g, '').trim() || 'Geçersiz sunucu yanıtı.');
        }
    })
    .then(data => {
        btn.disabled = false;
        btnText.innerText = 'Yanıt Üret';

        if (data && data.success) {
            renderAIContent(data.response);
            showToast('success', 'Analiz ve soru çözümleri hazırlandı!');
        } else {
            const err = (data && data.error) ? data.error : 'Hata oluştu.';
            resBody.innerHTML = '<div class="p-4 bg-red-500/10 border border-red-500/30 text-red-400 rounded-xl font-medium flex items-center gap-2"><i data-lucide="alert-triangle" class="w-5 h-5 text-red-400"></i><span>' + err + '</span></div>';
            lucide.createIcons();
            showToast('error', err);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btnText.innerText = 'Yanıt Üret';
        resBody.innerHTML = '<div class="p-4 bg-red-500/10 border border-red-500/30 text-red-400 rounded-xl font-medium flex items-center gap-2"><i data-lucide="alert-triangle" class="w-5 h-5 text-red-400"></i><span>İstek hatası: ' + err.message + '</span></div>';
        lucide.createIcons();
        showToast('error', 'Hata oluştu!');
    });
}

function toBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => {
            const encoded = reader.result.split(',')[1];
            resolve(encoded);
        };
        reader.onerror = error => reject(error);
    });
}

function viewPastLog(promptText, responseText) {
    document.getElementById('ai-prompt-input').value = promptText;
    const resContainer = document.getElementById('ai-response-container');
    resContainer.classList.remove('hidden');
    renderAIContent(responseText);
    resContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function copyResponseText() {
    if (lastRawResponse) {
        copyToClipboard(lastRawResponse, 'Tüm Markdown ve Çözümler Panoya Kopyalandı!');
    } else {
        const text = document.getElementById('ai-response-body').innerText;
        copyToClipboard(text, 'Panoya kopyalandı!');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', () => {
    selectAITool('<?= $initialType ?>', 'Seçili Araç', '');
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

