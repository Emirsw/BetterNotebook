<p align="center">
  <img src="docs/brand/logo.svg" alt="BetterNotebook" width="420">
</p>

<h1 align="center">BetterNotebook</h1>

<p align="center"><strong>PDF, notlar ve yapay zekâ tek çalışma alanında.</strong></p>

<p align="center">
  <a href="#ekran-görüntüleri">Ekran görüntüleri</a> ·
  <a href="#hızlı-kurulum">Kurulum</a> ·
  <a href="docs/installation.md">Sunucu kurulumu ve yükseltme</a> ·
  <a href="docs/cloudflare-pages.md">Tanıtım sitesi</a>
</p>

BetterNotebook, PDF okurken kaynakla ilgili soru sormayı, yanıtları kendi notlarına eklemeyi ve öğrendiklerini düzenlemeyi kolaylaştırır. Bir devreyi veya formülü PDF üzerinde seçebilir, adım adım açıklamayı takip edebilir ve notuna dönebilirsin.

## Neler yapabilirsin?

- **Kaynağı, notu ve AI sohbetini birlikte aç.** Panellerin sırasını ve genişliğini değiştir.
- **PDF üzerinde çalış.** Belgeyi sayfa aralıklarına böl, seçili bir alanı AI'a sor ve kaynak üzerinde üretilen açıklamaları incele.
- **Kendi notunu oluştur.** Dosyasız başlayabilir, matematiksel ifadeler ve vurgular ekleyebilir, AI yanıtlarını notuna aktarabilirsin.
- **Dosyalarını ve derslerini düzenle.** Kaynakları farklı notlarda kullan, çalışma oturumlarını ders bazında takip et. Not, dosya ve dersleri çöp kutusundan 30 gün içinde geri yükle.

## Ekran görüntüleri

| Çalışma alanı | PDF üzerinde adım adım açıklama |
| --- | --- |
| ![PDF, not ve AI araçları panelleri](docs/screenshots/workspace.png) | ![Örnek devre üzerinde açıklama ve formül](docs/screenshots/pdf-study.png) |

<details>
<summary>Dosya kütüphanesi</summary>

![Dosya kütüphanesi ve not bağlantıları](docs/screenshots/library.png)

</details>

Görüntüler örnek çalışma verileriyle çekilmiştir.

## Hızlı kurulum

Gereksinimler: PHP 8.1 veya üstü, MariaDB veya MySQL, `pdo_mysql`, `mbstring`, `curl`, `openssl` ve `fileinfo`. Kurulumda kullanılacak boş bir veritabanı ve o veritabanına yetkili bir kullanıcı oluştur.

1. Depoyu yereline al ve `app/config/database.example.php` dosyasını aynı dizinde `database.custom.php` olarak kopyala. Veritabanı bilgilerini özel dosyaya yaz.
2. Depo kökünde aşağıdaki komutu iki kez çalıştır. İlk çıktıyı `BETTERNOTEBOOK_ENCRYPTION_KEY`, ikinci çıktıyı `BETTERNOTEBOOK_SETUP_TOKEN` olarak özel yapılandırmaya gir.

   ```sh
   php app/scripts/database.php secret
   ```

3. Yerel sunucuyu başlat.

   ```sh
   php -S 127.0.0.1:8080 -t app dev/router.php
   ```

4. [İlk kurulum ekranını](http://127.0.0.1:8080/setup.php) aç, kurulum kodunu gir ve ilk hesabını oluştur. İşlem tamamlanınca kurulum kodunu yapılandırmadan kaldır.

AI özelliklerini kullanmak için **Ayarlar** üzerinden kendi Gemini API anahtarını gir. Anahtar veritabanında şifreli saklanır. Sunucu taşımasında şifreleme anahtarını güvenli biçimde ayrıca taşı.

Apache, Nginx ve cPanel üzerinde kurulum, mevcut sürümü yükseltme ve çöp kutusunun otomatik temizliği için [sunucu kurulum kılavuzuna](docs/installation.md) bak.

## Depo düzeni

| Dizin | İçerik |
| --- | --- |
| `app/` | PHP uygulaması ve çalışma dosyaları |
| `dev/` | Yerel geliştirme sunucusunun yönlendiricisi |
| `editor-source/` | Zengin metin editörünün kaynak kodu ve paket kilidi |
| `site/` | Cloudflare Pages'e yüklenebilen statik tanıtım sitesi |
| `docs/` | Kurulum, yayın talimatları ve örnek ekran görüntüleri |

## Lisans

Proje lisansı ilk kamuya açık sürümden önce belirlenecek. Dağıtılan üçüncü taraf bileşenlerinin lisans bildirimleri [app/assets/vendor](app/assets/vendor) dizininde yer alır.
