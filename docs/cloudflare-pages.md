# Tanıtım sitesini Cloudflare Pages'te yayımlama

Bu depodaki `site/` klasörü mevcut statik tanıtım sayfasıdır. Cloudflare Pages ücretsiz planında proje için bir `pages.dev` alt alanı sağlar. Ad uygunsa örneğin `betternotebook.pages.dev` kullanabilirsin. PHP ve MariaDB kullanan BetterNotebook uygulamasını ayrı bir PHP sunucusunda barındır.

## GitHub ile otomatik yayın

1. Cloudflare panelinde **Workers & Pages** bölümüne git. Yeni bir **Pages** projesi oluştur ve GitHub deposunu bağla. Yetkiyi yalnızca BetterNotebook deposuyla sınırlandırabilirsin.
2. Üretim dalı olarak `main` seç. Framework preset için **None** kullan. Build command alanını boş bırak. Build output directory olarak `site` gir ve kök dizini varsayılanda bırak.
3. Deploy seçeneğiyle ilk yayını yap. Sonraki `main` güncellemeleri otomatik yayımlanır.
4. Site düğmelerinin açtığı uygulama adresi değişirse `site/script.js` içindeki `APP_URL` değerini ve `site/index.html` içindeki uygulama bağlantılarını güncelle.

Kaynaklar: [Cloudflare GitHub entegrasyonu](https://developers.cloudflare.com/pages/configuration/git-integration/github-integration/) · [Pages ücretsiz plan sınırları](https://developers.cloudflare.com/pages/platform/limits/).

## Elle yükleme

GitHub bağlamak istemiyorsan yeni bir Direct Upload projesine doğrudan `site/` klasörünü yükle. Direct Upload ile başlayan bir projeyi sonradan Git entegrasyonuna çevirmek için yeni proje açmak gerekir. [Cloudflare Direct Upload](https://developers.cloudflare.com/pages/get-started/direct-upload/)
