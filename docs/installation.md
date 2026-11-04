# Kurulum ve yükseltme

## PHP sunucusunda ilk kurulum

BetterNotebook için PHP 8.1+, MariaDB veya MySQL, PDO MySQL, mbstring, cURL, OpenSSL ve fileinfo gerekir. Uygulamayı kendi alan adının ya da alt alan adının köküne yerleştir.

1. Web sunucusunun belge kökünü `app/` dizinine ayarla veya bu dizinin içeriğini mevcut belge köküne yükle. `uploads/notes` klasörü PHP tarafından yazılabilir olmalı.
2. Boş bir veritabanı ve o veritabanına yetkili bir kullanıcı oluştur. `config/database.example.php` dosyasını `config/database.custom.php` olarak kopyala ve bağlantı bilgilerini ekle. Ortam değişkenleri kullanıyorsan örnek dosyayı kopyalamadan `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` ve iki BetterNotebook anahtarını PHP'nin çalışma ortamına ver.
3. `setup.php` dosyasının bulunduğu dizinde terminal aç ve [kurulum kodunu üret](#kurulum-kodu-nereden-gelir).
4. HTTPS üzerinden `/setup.php` sayfasını aç. **Kurulum kodu** alanına `BETTERNOTEBOOK_SETUP_TOKEN` değerini gir ve ilk hesabını oluştur. Giriş ekranına yönlendirildikten sonra yalnızca kurulum kodunu yapılandırmadan kaldır.
5. Giriş yap. İstersen Ayarlar bölümünden Gemini bağlantını yapılandır.

Kurulum ve yükseltme bağlantısına tablo oluşturma ve değiştirme yetkileri gerekir. Yerel geliştirme için PHP'nin yerleşik sunucusunu [README'deki komutla](../README.tr.md#hızlı-kurulum) kullan.

### Kurulum kodu nereden gelir?

Kurulum kodunu sunucuda kendin üretirsin. `setup.php` dosyasının bulunduğu dizinde şu iki satırı çalıştır. Deponun ana dizinindeysen yol `app/scripts/database.php` olur.

```sh
php scripts/database.php secret
php scripts/database.php secret
```

Her çağrı 64 karakterlik rastgele bir değer üretir. `config/database.custom.php` içindeki mevcut satırların değerlerini şöyle doldur:

```php
define('BETTERNOTEBOOK_ENCRYPTION_KEY', 'BURAYA_ILK_CIKTI');
define('BETTERNOTEBOOK_SETUP_TOKEN', 'BURAYA_IKINCI_CIKTI');
```

- **İlk çıktı** Gemini anahtarlarını şifrelemek için kullanılır. Bu değeri sakla ve güvenli bir yerde yedekle.
- **İkinci çıktı** kurulum kodudur. Tırnakları kopyalamadan `/setup.php` sayfasındaki **Kurulum kodu** alanına yapıştır. İlk hesap oluşturulduktan sonra yalnızca bu ayarı kaldır.

Ortam değişkenleri kullanıyorsan aynı iki değeri belirtilen isimlerle hem web PHP sürecine hem de CLI komutlarına ver. **Kurulum kilitli** mesajı görürsen web PHP sürecinin 64 karakterlik kurulum kodunu okuyabildiğini kontrol et. Özel yapılandırmayı, şifreleme anahtarını ve kurulum kodunu GitHub'a yükleme.

### Apache

`app/.htaccess` temiz adresleri yönlendirir ve özel dizinleri kapatır. Apache 2.4'te `mod_rewrite` etkin olmalı. Sanal sunucu ayarlarında uygulama dizini için `AllowOverride Options FileInfo AuthConfig` izni ver. TLS sertifikasını sunucunda yapılandır.

### Nginx ve PHP-FPM

[Nginx örneğini](nginx.conf.example) mevcut HTTPS sanal sunucunun `server` bloğuna uyarlayabilirsin. `root` ve PHP-FPM soketini değiştir. Örnek, temiz adresleri PHP dosyalarına yönlendirir ve özel dizinlere doğrudan erişimi engeller. Yapılandırmayı uygulamadan önce `nginx -t` çalıştır.

### cPanel ve benzeri paneller

Alan adının Document Root değerini panelden bul ve `app/` içeriğini o dizine yükle. Dosya yöneticisinde gizli dosyaları göstererek `.htaccess` dosyasını dahil et. Veritabanını panelden oluştur. Kurulum komutlarında sunucunun seçili PHP sürümüne ait CLI yolunu kullan.

## Mevcut kurulumu yükseltme

1. Veritabanı ve dosyaları yedekle. Güncelleme sırasında site erişimini geçici olarak sınırla.
2. Güncel `app/` içeriğini mevcut uygulamaya uygula. Özel veritabanı yapılandırmasını, yüklemeleri ve hosting'e özgü `.htaccess` kurallarını koru.
3. Mevcut `BETTERNOTEBOOK_ENCRYPTION_KEY` değerini kullan. Bu sunucuda ilk şifreli sürüme geçiyorsan `php scripts/database.php secret` ile yeni bir değer üret.
4. Uygulama dizininden `php scripts/database.php upgrade` çalıştır. Komut tablo geçişlerini uygular ve eski Gemini anahtarlarını şifreler.
5. Giriş yapıp notları, dosyaları ve AI ayarlarını kontrol et.

Özel yapılandırmayı ve şifreleme anahtarını güvenli şekilde yedekle. Eski veritabanı yedeklerinde düz metin API anahtarları bulunabilir.

## Çöp kutusu temizliği

Kalıcı silmenin zamanında çalışması için crontab, cPanel Cron Jobs veya sunucunun görev zamanlayıcısında saatlik bir iş ekle. PHP ve uygulama yollarını sunucuna göre değiştir.

```sh
/usr/local/bin/php /home/KULLANICI/UYGULAMA/scripts/database.php purge-trash
```

Öğe 30 gün sonunda geri yüklemeye kapanır. Kalıcı temizleme sonraki cron çalışmasında yapılır.
