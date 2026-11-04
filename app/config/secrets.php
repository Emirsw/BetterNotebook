<?php
if (is_file(__DIR__ . '/database.custom.php')) {
    require_once __DIR__ . '/database.custom.php';
}

const BN_GEMINI_CIPHER = 'aes-256-gcm';
const BN_GEMINI_PREFIX = 'bn:gcm:v1:';

function bnSecretSetting(string $name): string
{
    return defined($name) ? (string)constant($name) : (string)getenv($name);
}

function bnEncryptionKey(): string
{
    $hex = bnSecretSetting('BETTERNOTEBOOK_ENCRYPTION_KEY');
    if (!preg_match('/^[0-9a-fA-F]{64}$/D', $hex)
        || !extension_loaded('openssl')
        || !in_array(BN_GEMINI_CIPHER, openssl_get_cipher_methods(), true)) {
        throw new RuntimeException('Sunucuda geçerli bir BetterNotebook şifreleme anahtarı veya OpenSSL yapılandırması bulunamadı.');
    }
    return hex2bin($hex);
}

function bnGeminiAad(int $userId): string
{
    return 'BetterNotebook:settings:gemini:v1:' . $userId;
}

function bnEncryptGeminiKey(string $value, int $userId): string
{
    if ($value === '' || strlen($value) > 512) {
        throw new InvalidArgumentException('Gemini anahtarı uzunluğu geçersiz.');
    }

    $nonce = random_bytes(12);
    $ciphertext = openssl_encrypt(
        $value, BN_GEMINI_CIPHER, bnEncryptionKey(), OPENSSL_RAW_DATA,
        $nonce, $tag, bnGeminiAad($userId), 16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Gemini anahtarı şifrelenemedi.');
    }
    return BN_GEMINI_PREFIX . base64_encode($nonce . $tag . $ciphertext);
}

function bnDecryptGeminiKey(?string $value, int $userId): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!str_starts_with($value, BN_GEMINI_PREFIX)) {
        throw new RuntimeException('Kayıtlı Gemini anahtarı için veritabanı güncellemesini çalıştır.');
    }

    $payload = base64_decode(substr($value, strlen(BN_GEMINI_PREFIX)), true);
    if ($payload === false || strlen($payload) < 29) {
        throw new RuntimeException('Kayıtlı Gemini anahtarı okunamadı.');
    }
    $plaintext = openssl_decrypt(
        substr($payload, 28), BN_GEMINI_CIPHER, bnEncryptionKey(), OPENSSL_RAW_DATA,
        substr($payload, 0, 12), substr($payload, 12, 16), bnGeminiAad($userId)
    );
    if ($plaintext === false) {
        throw new RuntimeException('Kayıtlı Gemini anahtarı doğrulanamadı. Sunucu şifreleme anahtarını kontrol et.');
    }
    return $plaintext;
}

function bnAccountGeminiKey(?array $account): ?string
{
    return $account === null
        ? null
        : bnDecryptGeminiKey($account['gemini_api_key'] ?? null, (int)$account['id']);
}
