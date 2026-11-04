<?php
/**
 * Gemini AI AJAX Uç Noktası (Multimodal PDF & Görsel Referansı Destekli)
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
@set_time_limit(300);
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '512M');

ob_start();

// Herhangi bir ölümcül PHP hatasında (Fatal Error / Memory / Timeout) JSON yanıtı döndürmeyi garantiye al
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Sunucu İşlem Hatası: ' . $error['message'] . ' (Dosya: ' . basename($error['file']) . ' Satır: ' . $error['line'] . ')'
        ]);
        exit;
    }
});

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../includes/gemini_helper.php';

    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Oturum açmanız gerekiyor. Lütfen giriş yapın.']);
        exit;
    }

    $currentUser = getCurrentUser();
    $pdo = getDBConnection();

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
    $promptType = $input['type'] ?? 'general';
    $userInput = trim($input['prompt'] ?? '');
    $courseId = $input['course_id'] ?? null;
    $projectId = $input['project_id'] ?? null;
    $noteId = !empty($input['note_id']) ? (int)$input['note_id'] : null;
    $hasFile = !empty($input['file_base64']);

    // Eğer kullanıcı özel bir metin yazmadıysa, seçilen araca veya nota göre akıllı varsayılan istek oluştur
    if (empty($userInput)) {
        if (!empty($noteId) || $hasFile) {
            if ($promptType === 'exam_prep') {
                $userInput = "Ekli ders notunu ve PDF dökümanını detaylıca analiz et. Konunun mantığını ve teorisini derinlemesine anlatan zengin bir Konu Anlatımı & Temel İlkeler bölümü oluştur, kritik formülleri LaTeX ($...$) ile açıkla ve vize/final sınavlarında çıkabilecek en kritik 5 sınav sorusunu adım adım model çözümleri ve püf noktalarıyla birlikte hazırla.";
            } elseif ($promptType === 'study_plan') {
                $userInput = "Ekli ders dökümanını inceleyerek sınav hazırlığı için gün gün, konu başlıklarına göre uygulanabilir bir çalışma planı oluştur.";
            } elseif ($promptType === 'project_roadmap') {
                $userInput = "Bu dökümanı ve gereksinimleri referans alarak teknik mimari yol haritası ve geliştirme görevleri listesi hazırla.";
            } else {
                $userInput = "Ekli dökümanı derinlemesine incele, konunun temel mantığını özetle ve en kritik formül, kavram ve çalışma noktalarını maddeler halinde açıkla.";
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Lütfen bir soru/istek yazın veya referans almak için bir ders notu/PDF seçin.']);
            exit;
        }
    }

    $apiKey = bnAccountGeminiKey($currentUser) ?? '';
    $model = $currentUser['gemini_model'] ?? 'gemini-2.0-flash';

    if (empty($apiKey)) {
        echo json_encode([
            'success' => false, 
            'error' => "Google Gemini API Anahtarınız kayıtlı değil! Lütfen 'Ayarlar' sayfasından ücretsiz Google Gemini API anahtarınızı kaydedin."
        ]);
        exit;
    }

    $gemini = new GeminiAI($apiKey, $model);
    $attachments = [];

    // Eğer bir nota referans veriliyorsa not içeriğini ve ekli dosyasını (PDF/Fotoğraf) çekelim
    if ($noteId) {
        $stmtNote = $pdo->prepare("SELECT * FROM notes WHERE id = ? AND user_id = ? AND trashed_at IS NULL");
        $stmtNote->execute([$noteId, $_SESSION['user_id']]);
        $note = $stmtNote->fetch();

        if ($note) {
            $userInput = "İLGİLİ DERS NOTU BAŞLIĞI: " . ($note['title'] ?? '') . "\nİÇERİK:\n" . ($note['content'] ?? '') . "\n\nKULLANICI TALEBİ / SORUSU:\n" . $userInput;
            
            if (!empty($note['file_path'])) {
                $absFilePath = __DIR__ . '/../' . ltrim($note['file_path'], '/\\');
                if (file_exists($absFilePath)) {
                    $fSizeMB = filesize($absFilePath) / (1024 * 1024);
                    
                    // Gemini REST API sınırı genelde ~20-25 MB'dir.
                    if ($fSizeMB <= 25) {
                        $fileContent = @file_get_contents($absFilePath);
                        $mimeType = 'application/pdf';
                        if (function_exists('mime_content_type')) {
                            $detectedMime = @mime_content_type($absFilePath);
                            if (!empty($detectedMime) && $detectedMime !== 'application/octet-stream') {
                                $mimeType = $detectedMime;
                            }
                        }
                        
                        if ($mimeType === 'application/pdf' || $mimeType === 'application/octet-stream') {
                            $ext = strtolower(pathinfo($absFilePath, PATHINFO_EXTENSION));
                            if ($ext === 'pdf') $mimeType = 'application/pdf';
                            elseif (in_array($ext, ['jpg', 'jpeg'])) $mimeType = 'image/jpeg';
                            elseif ($ext === 'png') $mimeType = 'image/png';
                            elseif ($ext === 'webp') $mimeType = 'image/webp';
                        }

                        if ($fileContent && $mimeType) {
                            $attachments[] = [
                                'mime_type' => $mimeType,
                                'data' => base64_encode($fileContent)
                            ];
                        }
                    } else {
                        $userInput .= "\n\n[NOT: Ekli döküman {$fSizeMB} MB boyutunda olduğu için doğrudan tüm sayfalar API'ye aktarılamadı. Lütfen bu notun metnini referans alarak cevap ver ve kullanıcıya sadece ilgili sınav sayfalarını ayrı PDF olarak bölüp eklemesini öner.]";
                    }
                }
            }
        }
    }

    // Doğrudan arayüzden gönderilen base64 ek dosya var mı?
    if (!empty($input['file_base64']) && !empty($input['file_mime'])) {
        $mime = $input['file_mime'];
        $data = $input['file_base64'];

        // Eğer PPTX / PPT gönderildiyse arka planda PDF'e çevir
        if (strpos($mime, 'presentation') !== false || strpos($mime, 'powerpoint') !== false) {
            require_once __DIR__ . '/../includes/pptx_helper.php';
            $uploadDir = __DIR__ . '/../uploads/notes/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

            $tempPptx = $uploadDir . 'temp_ai_' . time() . '_' . uniqid() . '.pptx';
            $tempPdf = $uploadDir . 'temp_ai_' . time() . '_' . uniqid() . '.pdf';

            @file_put_contents($tempPptx, base64_decode($data));
            $conv = convertPptxToPdf($tempPptx, $tempPdf);
            @unlink($tempPptx);

            if ($conv['success'] && file_exists($tempPdf)) {
                $pdfBytes = @file_get_contents($tempPdf);
                @unlink($tempPdf);
                if ($pdfBytes) {
                    $mime = 'application/pdf';
                    $data = base64_encode($pdfBytes);
                }
            }
        }

        $attachments[] = [
            'mime_type' => $mime,
            'data' => $data
        ];
    }

    // Sistem Talimatı (Akademik Profesör ve Zengin Rehber Formatı)
    $systemInstruction = "Sen alanında uzman bir Üniversite Profesörü ve kıdemli bir Akademik Koçsun.
Sana iletilen ders notlarını, sunum slaytlarını ve ekli PDF dökümanlarını derinlemesine incele.

GİRİŞ VE ÜSLUP KURALLARI (ÇOK ÖNEMLİ):
1. Yanıtına KESİNLİKLE 'Harika bir hedef belirlemişsin! Üniversite son sınıfta okulu uzatmak...' gibi kalıp, robotik veya yapay motivasyon cümleleriyle BAŞLAMA.
2. Açılışı doğrudan, samimi ve saygın bir profesör üslubuyla yap (Örn: 'Merhaba sevgili öğrencim, [Ders Adı/Konu Başlığı] dökümanını inceledim. Konunun mantığını kavramanı sağlayacak ve sınavda yüksek başarı elde etmeni garantileyecek kapsamlı bir rehber hazırladım.').
3. Öğrencinin konuyu derinlemesine öğrenmesini, ezberden ziyade mantığını kavramasını sağla.

FORMATLAMA VE MATEMATİKSEL FORMÜL STANDARTLARI (ÇOK KRİTİK):
1. Yanıtlarını temiz, okuması rahat, hiyerarşik ve profesyonel bir Markdown formatında sun.
2. Matematiksel, fiziksel ve elektriksel formülleri MUTLAKA hatasız ve standart LaTeX formatında yaz:
   - Bağımsız büyük denklemler için `$$ ... $$`, satır içi formüller için `$ ... $` kullan.
   - Alt indisleri (subscript) MUTLAKA alt çizgi `_` ile yaz (Örn: `\\mathbf{Z}_1`, `\\mathbf{Z}_{12}`, `\\mathbf{Z}_{31}`, `R_1`, `V_{in}`, `I_{out}`). ASLA alt çizgisiz `\\mathbf{Z}1` veya `\\mathbf{Z}{12}` şeklinde hatalı yazma!
   - Reel ve İmajiner parça operatörlerini mutlaka `\\operatorname{Re}(\\mathbf{Z})` ve `\\operatorname{Im}(\\mathbf{Z})` olarak yaz (ASLA `\\text{I_{m}}` veya `\\text{I_m}` gibi geçersiz LaTeX yazma).
   - Tanım ve madde listelerinde bağıntıları ASLA çıplak/dolarsız bırakma. MUTLAKA `$ ... $` içine sar:
     * Örnek: `$B = \\operatorname{Im}(\\mathbf{Y})$: Süseptans (susceptance).`
     * Örnek: `$X = \\operatorname{Im}(\\mathbf{Z})$: Reaktans (reactance).`
     * Örnek: `$R = \\operatorname{Re}(\\mathbf{Z})$: Direnç (resistance).`
   - Türkçe kelimeleri, bağlaçları veya notları (Örn: 'yerine', 'olmak üzere', 'için', 've') ASLA formülün / dolar işaretinin içine koyma! Formülü kapatıp normal metin olarak yaz (Örn: `$\\mathbf{Z}_{12}$ yerine $\\mathbf{Z}_{13}$ kullanılır`).
   - Dökümanda OCR veya taranmış sayfalardan kaynaklanan dikey harf dizilimlerini (y-e-r-i-n-e vb.) birleştir, akıcı ve tek parça kelime olarak yaz.
   - Kesirler için `\\frac{a}{b}`, çarpımlar için `\\cdot` veya `\\times` kullan.
3. Sınav sorularında her soruyu '### Soru X: [Başlık]' şeklinde başlat.
4. Soru metni, alt şıklar (a, b, c), 'Model Çözüm' ve 'Püf Noktası / İpucu' kısımlarını net başlık ve paragraflarla birbirinden ayır.
5. Vurgulanması gereken anahtar terimleri **kalın** yap.
6. Berrak, akıcı ve akademik Türkçe kullan.";

    $finalPrompt = $userInput;

    if ($promptType === 'study_plan') {
        $finalPrompt = "TALEP: Sınav Çalışma Planı Hazırla.\n" . $userInput . "\n\nLütfen ekli ders notlarını ve dökümanları inceleyerek gün gün, kritik konu başlıklarına göre uygulanabilir bir çalışma planı çıkar.";
    } elseif ($promptType === 'exam_prep') {
        $finalPrompt = "TALEP: Kapsamlı Konu Anlatımı, Zenginleştirilmiş Çalışma Rehberi ve Sınav Soruları Çıkar.\n" . $userInput . "\n\n" .
        "YÖNERGELER:\n" .
        "1. Döküman giriş veya teorik slaytlar olsa bile, öğrencinin konuyu tam anlaması için önce zengin bir 'Konu Anlatımı & Temel Kavramlar' bölümü sun.\n" .
        "2. Varsa kritik formülleri ve mühendislik ilkelerini hatasız LaTeX ile ($...$ ve $$...$$) detaylandır.\n" .
        "3. Ardından hocanın bu üniteden vize/finalde sorabileceği en kritik 5 sınav sorusunu (Kavramsal yorum, senaryo veya sayısal problemler) oluştur.\n" .
        "4. Her soru için puan kazandıracak adım adım 'Model Çözüm' ve 'Püf Noktası' ekle.\n" .
        "5. ÇOK ÖNEMLİ: Soru 1, Soru 2, Soru 3, Soru 4 ve Soru 5'in TÜMÜNÜ adım adım çözerek rehberi tam ve eksiksiz olarak tamamla. Asla yarıda kesme.";
    } elseif ($promptType === 'followup') {
        $finalPrompt = "TALEP: Bu Ders Dökümanı ve Mevcut AI Özeti Hakkında Öğrenci Sorusu & Ek Çözüm İsteği.\n" .
        $userInput . "\n\n" .
        "YÖNERGELER:\n" .
        "1. Öğrencinin sorusunu hem ekli orijinal dökümanı hem de mevcut ders notlarını baz alarak doğrudan, net ve eğitici bir dille yanıtla.\n" .
        "2. Tüm matematiksel veya mühendislik formüllerini mutlaka standart LaTeX ($...$ veya $$...$$) formatında ve alt indisleri `_` ile yaz.\n" .
        "3. Eğer ek soru/problem istendiyse adım adım model çözüm ve sınav püf noktaları sun.\n" .
        "4. Açıklamayı akademik, berrak ve motive edici bir üslupla sun.";
    } elseif ($promptType === 'project_roadmap') {
        $finalPrompt = "TALEP: Yazılım Proje Rehberi.\n" . $userInput;
    }

    $result = $gemini->generateResponse($finalPrompt, $systemInstruction, $attachments);

    if (ob_get_length()) ob_clean();

    if ($result['success']) {
        // AI Log kaydetme (hata verirse ana akışı bozmasın)
        try {
            $stmt = $pdo->prepare("INSERT INTO ai_logs (user_id, feature_type, prompt_text, response_text) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $promptType, mb_substr($userInput, 0, 500), $result['text']]);
        } catch (\Throwable $logErr) {
            @error_log('AI Log Error: ' . $logErr->getMessage());
        }

        echo json_encode([
            'success' => true,
            'response' => $result['text']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error']
        ]);
    }
} catch (\Throwable $e) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'İşlem Hatası: ' . $e->getMessage()
    ]);
}
