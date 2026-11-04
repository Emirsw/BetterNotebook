<?php
/**
 * Çoklu Sağlayıcı AI Entegrasyon Motoru (Google Gemini, Groq & OpenRouter Free)
 */

require_once __DIR__ . '/gemini_helper.php';

class AIEngine {
    private $provider;
    private $apiKey;
    private $model;

    public function __construct($provider = 'gemini', $apiKey = null, $model = null) {
        $this->provider = strtolower(trim($provider ?: 'gemini'));
        $this->apiKey = trim($apiKey ?? '');
        $this->model = trim($model ?? '');

        // Varsayılan model atamaları & Decommissioned model haritalamaları
        $deprecatedGroq = ['deepseek-r1-distill-llama-70b', 'mixtral-8x7b-32768', 'gemma2-9b-it', 'llama-3.2-11b-vision-preview', 'llama3-70b-8192', 'llama3-8b-8192'];
        if (empty($this->model) || in_array($this->model, $deprecatedGroq)) {
            if ($this->provider === 'groq') {
                $this->model = 'llama-3.3-70b-versatile';
            } elseif ($this->provider === 'openrouter') {
                $this->model = 'deepseek/deepseek-r1:free';
            } else {
                $this->model = 'gemini-2.0-flash';
            }
        }
    }

    /**
     * Sağlayıcıya göre AI yanıtı üretir
     */
    public function generateResponse($prompt, $systemInstruction = null, $attachments = []) {
        if (empty($this->apiKey)) {
            $providerName = ucfirst($this->provider);
            return [
                'success' => false,
                'error' => "{$providerName} API Anahtarınız kayıtlı değil! Lütfen 'Ayarlar' sayfasından ücretsiz {$providerName} API anahtarınızı girin."
            ];
        }

        switch ($this->provider) {
            case 'groq':
                return $this->callGroq($prompt, $systemInstruction, $attachments);
            case 'openrouter':
                return $this->callOpenRouter($prompt, $systemInstruction, $attachments);
            case 'gemini':
            default:
                $gemini = new GeminiAI($this->apiKey, $this->model);
                return $gemini->generateResponse($prompt, $systemInstruction, $attachments);
        }
    }

    /**
     * Groq Cloud API (Ücretsiz, Ultra Hızlı LPU, Meta Llama 3.3 70B & Llama 3.1 8B)
     */
    private function callGroq($prompt, $systemInstruction, $attachments = []) {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'Sunucuda PHP cURL eklentisi aktif değil.'];
        }

        $deprecatedGroq = ['deepseek-r1-distill-llama-70b', 'mixtral-8x7b-32768', 'gemma2-9b-it', 'llama-3.2-11b-vision-preview', 'llama3-70b-8192', 'llama3-8b-8192'];
        $activeModel = in_array($this->model, $deprecatedGroq) ? 'llama-3.3-70b-versatile' : $this->model;
        $modelsToTry = [$activeModel];
        $fallbacks = ['qwen/qwen3.8-27b', 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant'];
        foreach ($fallbacks as $fb) {
            if (!in_array($fb, $modelsToTry)) $modelsToTry[] = $fb;
        }

        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $lastError = 'Groq API hatası.';
        $lastHttpCode = 0;

        foreach ($modelsToTry as $currentModel) {
            $payload = [
                'model' => $currentModel,
                'messages' => $messages,
                'temperature' => 0.6,
                'max_tokens' => 8192,
                'top_p' => 0.95
            ];

            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 75);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $lastError = 'Groq cURL Hatası: ' . $curlErr;
                continue;
            }

            $lastHttpCode = $httpCode;
            $data = json_decode($response, true);

            $choiceMsg = $data['choices'][0]['message'] ?? null;
            if ($httpCode === 200 && $choiceMsg) {
                $text = $choiceMsg['content'] ?? '';
                // Bazı akıl yürütme modellerinde içerik reasoning alanında olabilir
                if (empty($text) && !empty($choiceMsg['reasoning'])) {
                    $text = $choiceMsg['reasoning'];
                }

                if (!empty($text)) {
                    return [
                        'success' => true,
                        'text' => $text,
                        'used_model' => "Groq ({$currentModel})"
                    ];
                }
            }

            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            $lastError = $errMsg;

            // Hız limiti, model meşgulse veya decommissioned/deprecated ise diğer modele geç
            $isRecoverable = ($httpCode === 429 || $httpCode === 503 || $httpCode === 404 || $httpCode === 400 ||
                strpos($errMsg, 'rate') !== false ||
                strpos($errMsg, 'limit') !== false ||
                strpos($errMsg, 'decommissioned') !== false ||
                strpos($errMsg, 'not supported') !== false ||
                strpos($errMsg, 'not found') !== false ||
                strpos($errMsg, 'deprecated') !== false);

            if ($isRecoverable) {
                usleep(200000);
                continue;
            } else {
                break;
            }
        }

        if ($lastHttpCode === 429 || strpos($lastError, 'rate') !== false) {
            return [
                'success' => false,
                'error' => "⚡ Groq dakikalık istek limitine ulaşıldı. Lütfen 10 saniye bekleyip tekrar deneyin veya Ayarlar'dan başka bir model seçin."
            ];
        }

        return [
            'success' => false,
            'error' => 'Groq API Hatası: ' . $lastError
        ];
    }

    /**
     * OpenRouter API (Ücretsiz DeepSeek R1, DeepSeek V3, Llama 3.3, Qwen 2.5)
     */
    private function callOpenRouter($prompt, $systemInstruction, $attachments = []) {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'Sunucuda PHP cURL eklentisi aktif değil.'];
        }

        $modelsToTry = [$this->model];
        $fallbacks = [
            'deepseek/deepseek-r1:free',
            'deepseek/deepseek-chat:free',
            'meta-llama/llama-3.3-70b-instruct:free',
            'qwen/qwen-2.5-72b-instruct:free',
            'deepseek/deepseek-r1-distill-llama-70b:free'
        ];
        foreach ($fallbacks as $fb) {
            if (!in_array($fb, $modelsToTry)) $modelsToTry[] = $fb;
        }

        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $lastError = 'OpenRouter API hatası.';
        $lastHttpCode = 0;

        foreach ($modelsToTry as $currentModel) {
            $payload = [
                'model' => $currentModel,
                'messages' => $messages,
                'temperature' => 0.6,
                'max_tokens' => 8192
            ];

            $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: https://mecebi.com.tr',
                'X-Title: Akademik Not Sistemi',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 90);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $lastError = 'OpenRouter cURL Hatası: ' . $curlErr;
                continue;
            }

            $lastHttpCode = $httpCode;
            $data = json_decode($response, true);

            if ($httpCode === 200 && !empty($data['choices'][0]['message']['content'])) {
                $text = $data['choices'][0]['message']['content'];
                return [
                    'success' => true,
                    'text' => $text,
                    'used_model' => "OpenRouter ({$currentModel})"
                ];
            }

            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            $lastError = $errMsg;

            // Ücretsiz model meşgulse, rate limit veya endpoint yoksa diğer ücretsiz modele geç
            $isRecoverable = ($httpCode === 429 || $httpCode === 503 || $httpCode === 404 || $httpCode === 400 ||
                strpos($errMsg, 'rate') !== false ||
                strpos($errMsg, 'limit') !== false ||
                strpos($errMsg, 'provider') !== false ||
                strpos($errMsg, 'No endpoints') !== false ||
                strpos($errMsg, 'busy') !== false ||
                strpos($errMsg, 'temporarily') !== false);

            if ($isRecoverable) {
                usleep(200000);
                continue;
            } else {
                break;
            }
        }

        if (strpos($lastError, 'No endpoints') !== false || strpos($lastError, 'busy') !== false || strpos($lastError, 'rate') !== false || $lastHttpCode === 429 || $lastHttpCode === 503) {
            return [
                'success' => false,
                'error' => "⚡ OpenRouter ücretsiz sunucuları şu anda yoğunluk/kuyruk yaşıyor. DeepSeek R1 modelini hiç beklemeden ve ışık hızında kullanmak için 'Ayarlar' sayfasından 'Groq Cloud' (DeepSeek R1 Distill 70B) veya 'Google Gemini' seçeneğini kullanabilirsiniz."
            ];
        }

        return [
            'success' => false,
            'error' => 'OpenRouter Hatası: ' . $lastError
        ];
    }
}
