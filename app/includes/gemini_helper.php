<?php
/**
 * Gemini AI API Entegrasyon Yardımcısı (Multimodal PDF & Görsel Destekli)
 */

class GeminiAI {
    private $apiKey;
    private $model;

    public function __construct($apiKey = null, $model = 'gemini-2.5-flash') {
        $this->apiKey = $apiKey;
        $m = !empty($model) ? trim($model) : 'gemini-2.5-flash';
        $this->model = $m;
    }

    /**
     * Gemini REST API üzerinden metin ve dosya/görsel (Multimodal) yanıtı üretir.
     * Ayarlar sayfasında seçilen modeli kullanır; kaldırılmış modellere otomatik geçiş yapmaz.
     * @param string $prompt Metin komutu
     * @param string|null $systemInstruction Sistem talimatı
     * @param array $attachments Ekli dosyalar [ ['mime_type' => 'application/pdf', 'data' => base64_data] ]
     */
    public function generateResponse($prompt, $systemInstruction = null, $attachments = [], $responseFormat = null) {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error' => 'Gemini API Anahtarı bulunamadı! Lütfen "Ayarlar" sayfasından ücretsiz Gemini API anahtarınızı girin.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'Sunucuda PHP cURL eklentisi aktif değil! Lütfen hosting panelinizden (cPanel > Select PHP Version > Extensions) curl eklentisini aktif edin.'
            ];
        }

        // BN_SELECTED_MODEL_V1: honor the selected model; no retired fallback IDs.
        $modelsToTry = [$this->model];

        // BN_JSON_MODE: a single selected-model request; incomplete JSON is never concatenated.
        if ($responseFormat === 'json') $modelsToTry = [$this->model];
        $parts = [];
        $parts[] = ['text' => $prompt];

        // PDF ve Görsel Ekleri (Multimodal InlineData)
        if (!empty($attachments)) {
            foreach ($attachments as $att) {
                if (!empty($att['mime_type']) && !empty($att['data'])) {
                    $parts[] = [
                        'inlineData' => [
                            'mimeType' => $att['mime_type'],
                            'data' => $att['data']
                        ]
                    ];
                }
            }
        }

        $contents = [];
        $contents[] = [
            'role' => 'user',
            'parts' => $parts
        ];

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ]
        ];

        // New model families use their own sampling defaults.
        if (preg_match('/^gemini-[34](?:[.\-])/i',$this->model)) unset($payload['generationConfig']['temperature'],$payload['generationConfig']['topK'],$payload['generationConfig']['topP']);
        if ($responseFormat === 'json') {
            // BN_PAGE_BUDGET_V2: leave room for both reasoning and the final JSON.
            $payload['generationConfig']['responseMimeType']='application/json';
            $payload['generationConfig']['temperature']=0.2;
            if (preg_match('/^gemini-2\.5-(?:flash|pro)(?:-|$)/i',$this->model)) {
                $payload['generationConfig']['maxOutputTokens']=32768;
                $payload['generationConfig']['thinkingConfig']=['thinkingBudget'=>4096];
            } elseif (preg_match('/^gemini-[34](?:[.\-])/i',$this->model)) {
                $payload['generationConfig']['maxOutputTokens']=32768;
                $payload['generationConfig']['thinkingConfig']=['thinkingLevel'=>'low'];
                unset($payload['generationConfig']['temperature'],$payload['generationConfig']['topK'],$payload['generationConfig']['topP']);
            } elseif (in_array($this->model,['gemini-flash-latest','gemini-pro-latest'],true)) {
                $payload['generationConfig']['maxOutputTokens']=32768;
            }
        }
        if ($systemInstruction) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }

        $lastError = 'Bilinmeyen bir hata oluştu.';
        $lastHttpCode = 0;

        foreach ($modelsToTry as $currentModel) {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent?key=" . urlencode($this->apiKey);

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, 75);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if (defined('CURL_IPRESOLVE_V4')) {
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $lastError = 'Sunucu Bağlantı Hatası (cURL): ' . $curlError;
                continue;
            }

            $responseData = json_decode($response, true);
            $lastHttpCode = $httpCode;

            if ($httpCode === 200 && $responseFormat === 'json') {
                $candidate=$responseData['candidates'][0]??[];
                $finishReason=$candidate['finishReason']??'STOP';$texts=[];
                foreach(($candidate['content']['parts']??[]) as $part)if(empty($part['thought'])&&isset($part['text'])&&is_string($part['text']))$texts[]=$part['text'];
                $fullText=implode('',$texts);
                $complete=json_decode($fullText,true);
                if($finishReason==='MAX_TOKENS'&&(!is_array($complete)||empty($complete['steps'])||!is_array($complete['steps'])))return ['success'=>false,'error'=>'AI açıklaması tamamlanmadan modelin çıktı sınırına ulaşıldı. Kayıtlı açıklamalar korundu. Ayarlar’dan daha yüksek çıktı kapasiteli bir model seçip yeniden deneyebilirsin.'];
                if($fullText==='')return ['success'=>false,'error'=>'Model bu sayfa için kullanılabilir bir açıklama döndürmedi.'];
                return ['success'=>true,'text'=>$fullText,'model'=>$currentModel];
            }

            if ($httpCode === 200 && !empty($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                $candidate = $responseData['candidates'][0];
                $fullText = $candidate['content']['parts'][0]['text'];
                $finishReason = $candidate['finishReason'] ?? 'STOP';

                // Eğer yanıt token sınırına (MAX_TOKENS) takılıp yarım kaldıysa, otomatik olarak devamını çek (2 tura kadar)
                $contAttempts = 0;
                while ($finishReason === 'MAX_TOKENS' && $contAttempts < 2) {
                    $contAttempts++;
                    $contPayload = [
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $prompt]]],
                            ['role' => 'model', 'parts' => [['text' => $fullText]]],
                            ['role' => 'user', 'parts' => [['text' => 'Lütfen cümleni, formüllerini ve kalan sınav sorularını kaldığın noktadan eksiksiz ve kesintisiz olarak tamamla.']]]
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4,
                            'topK' => 40,
                            'topP' => 0.95,
                            'maxOutputTokens' => 8192,
                        ]
                    ];
                    if ($systemInstruction) {
                        $contPayload['systemInstruction'] = [
                            'parts' => [['text' => $systemInstruction]]
                        ];
                    }

                    $ch2 = curl_init($endpoint);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch2, CURLOPT_POST, true);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($contPayload));
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 15);
                    curl_setopt($ch2, CURLOPT_TIMEOUT, 60);
                    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 2);
                    if (defined('CURL_IPRESOLVE_V4')) {
                        curl_setopt($ch2, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                    }

                    $contResp = curl_exec($ch2);
                    $contCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    curl_close($ch2);

                    if ($contCode === 200) {
                        $contData = json_decode($contResp, true);
                        $contCandidate = $contData['candidates'][0] ?? null;
                        $contText = $contCandidate['content']['parts'][0]['text'] ?? '';
                        $finishReason = $contCandidate['finishReason'] ?? 'STOP';
                        if (!empty($contText)) {
                            $fullText .= "\n" . $contText;
                        } else {
                            break;
                        }
                    } else {
                        break;
                    }
                }

                return [
                    'success' => true,
                    'text' => $fullText,
                    'used_model' => $currentModel
                ];
            }

            // Hata Analizi
            $errMsg = $responseData['error']['message'] ?? ('HTTP ' . $httpCode);
            $lastError = $errMsg;

            // Eğer 429 (Rate Limit), 503 (High Demand / Service Unavailable), 404, 500 veya 400 (Model no longer available / not supported) ise diğer yedek modeli dene
            $isRecoverable = ($httpCode === 429 || $httpCode === 503 || $httpCode === 404 || $httpCode === 500 || $httpCode === 400 ||
                strpos($errMsg, 'quota') !== false ||
                strpos($errMsg, 'limit') !== false ||
                strpos($errMsg, 'demand') !== false ||
                strpos($errMsg, 'overload') !== false ||
                strpos($errMsg, 'temporarily') !== false ||
                strpos($errMsg, 'UNAVAILABLE') !== false ||
                strpos($errMsg, 'RESOURCE_EXHAUSTED') !== false ||
                strpos($errMsg, 'no longer available') !== false ||
                strpos($errMsg, 'not found') !== false ||
                strpos($errMsg, 'not supported') !== false ||
                strpos($errMsg, 'is deprecated') !== false);

            if ($isRecoverable) {
                // Yedek modele geçmeden önce 0.2 saniye bekle
                usleep(200000);
                continue;
            } else {
                // API key hatası (geçersiz anahtar) gibi kalıcı hatalarda döngüyü kır
                break;
            }
        }

        // Bölgesel IP / Konum Kısıtlaması Teşhisi
        if (strpos($lastError, 'location is not supported') !== false || strpos($lastError, 'User location') !== false) {
            return [
                'success' => false,
                'error' => "🌍 Google Gemini Bölge Kısıtlaması: Sunucunuzun IP adresi / lokasyonu Google AI Studio tarafından desteklenmiyor. (Hosting firmanızdan sunucu çıkış IP'sini Türkiye/Avrupa/ABD lokasyonuna yönlendirmesini talep edebilirsiniz)."
            ];
        }

        // Anlık sunucu yoğunluğu (High Demand)
        if (strpos($lastError, 'demand') !== false || strpos($lastError, 'overload') !== false || $lastHttpCode === 503) {
            return [
                'success' => false,
                'error' => "⚡ Google Gemini sunucularında şu anda küresel yoğunluk (High Demand) yaşanıyor. Lütfen 10-15 saniye bekleyip tekrar deneyin veya Ayarlar sayfasındaki güncel listeden başka bir model seçin."
            ];
        }

        // BN_PROVIDER_ERRORS_V1: generateContent is not a rate-limit signal.
        $providerError = $responseData['error'] ?? [];
        $providerStatus = is_string($providerError['status'] ?? null) ? $providerError['status'] : '';
        $safeError = str_replace([$this->apiKey, urlencode($this->apiKey)], '[gizlendi]', $lastError);
        if ($lastHttpCode === 429 || $providerStatus === 'RESOURCE_EXHAUSTED') {
            $retrySeconds = null;
            foreach (($providerError['details'] ?? []) as $detail) {
                if (($detail['@type'] ?? '') === 'type.googleapis.com/google.rpc.RetryInfo'
                    && preg_match('/^(\d+(?:\.\d+)?)s$/', $detail['retryDelay'] ?? '', $match)) {
                    $retrySeconds = (int)ceil((float)$match[1]);
                }
            }
            $wait = $retrySeconds !== null ? " Sağlayıcının önerdiği bekleme süresi: {$retrySeconds} saniye." : '';
            return ['success'=>false, 'error'=>"AI sağlayıcısının kullanım kotasına veya hız sınırına ulaşıldı (HTTP {$lastHttpCode}).{$wait} Limit seçilen modele ve projenin planına bağlıdır; günlük kota veya bu model için erişim kotası da olabilir. AI Studio’dan bu modelin limitlerini kontrol et. Ayrıntı: {$safeError}"];
        }
        return ['success'=>false, 'error'=>"AI sağlayıcısı hatası (HTTP {$lastHttpCode}" . ($providerStatus !== '' ? ", {$providerStatus}" : '') . "): {$safeError}"];

    }
}

