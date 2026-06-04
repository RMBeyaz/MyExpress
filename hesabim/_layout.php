<?php
declare(strict_types=1);

function mx_account_header(string $title, string $active = ''): void
{
    $isLoggedIn = mx_customer_is_logged_in();
    ?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mx_h($title) ?> | MyExpress Hesabım</title>
    <meta name="description" content="MyExpress müşteri hesabı ile kayıtlı adreslerinizi, kurye taleplerinizi ve faturalarınızı görüntüleyin.">
    <link rel="icon" type="image/png" href="../assets/Logo.png">
    <link rel="stylesheet" href="../styles.css?v=20260604-password-reset">
  </head>
  <body class="account-body">
    <header class="account-header">
      <a class="brand" href="../index.html" aria-label="MyExpress ana sayfa">
        <img src="../assets/Logo.svg" alt="MyExpress Kurye" width="220" height="82">
      </a>
      <nav class="account-nav" aria-label="Hesap menüsü">
        <a<?= $active === 'dashboard' ? ' class="is-active"' : '' ?> href="index.php">Özet</a>
        <a<?= $active === 'new-request' ? ' class="is-active"' : '' ?> href="yeni-talep.php">Yeni Talep</a>
        <a<?= $active === 'addresses' ? ' class="is-active"' : '' ?> href="adresler.php">Adresler</a>
        <a<?= $active === 'requests' ? ' class="is-active"' : '' ?> href="talepler.php">Talepler</a>
        <a<?= $active === 'invoices' ? ' class="is-active"' : '' ?> href="faturalar.php">Faturalar</a>
        <?php if ($isLoggedIn): ?>
          <a href="cikis.php">Çıkış</a>
        <?php else: ?>
          <a<?= $active === 'login' ? ' class="is-active"' : '' ?> href="giris.php">Giriş</a>
        <?php endif; ?>
      </nav>
    </header>
    <main class="account-shell">
    <?php
}

function mx_account_footer(): void
{
    ?>
    </main>
    <footer class="site-footer account-site-footer">
      <div>
        <a class="brand footer-brand" href="../index.html"><img src="../assets/Logo_white.svg" alt="MyExpress İstanbul içi kurye hizmeti" width="220" height="82"></a>
        <p>İstanbul içi evrak, paket, numune, e-ticaret ve kurumsal dağıtım ihtiyaçları için motorlu ve araçlı kurye çözümleri.</p>
      </div>
      <address>
        <strong>İletişim</strong>
        <a href="tel:+905467691904">0546 769 19 04</a>
        <a href="mailto:info@myexpress.com.tr">info@myexpress.com.tr</a>
        <span>Küçüksu Mah. Adaş Sk. Gül No:39 İç Kapı No:5 Üsküdar / İSTANBUL</span>
        <a href="https://wa.me/905467691904" target="_blank" rel="noopener">WhatsApp'tan Yaz</a>
      </address>
      <div class="footer-links">
        <strong>Hesap</strong>
        <a href="index.php">Hesap Özeti</a>
        <a href="adresler.php">Adreslerim</a>
        <a href="talepler.php">Taleplerim</a>
        <a href="faturalar.php">Faturalarım</a>
      </div>
      <div class="footer-links">
        <strong>Bilgilendirme</strong>
        <a href="../takip.html">Gönderi Durumu Takibi</a>
        <a href="../gizlilik-politikasi.html">Gizlilik Politikası</a>
        <a href="../kvkk-politikasi.html">KVKK Aydınlatma Metni</a>
        <a href="../uyelik-sozlesmesi.html">Üyelik Sözleşmesi</a>
        <a href="../kurye-hizmet-sozlesmesi.html">Kurye Hizmet Sözleşmesi</a>
        <a href="../teslimat-sartlari.html">Teslimat Şartları</a>
        <a href="../yasakli-gonderiler.html">Yasaklı Gönderiler</a>
        <a href="../cerez-politikasi.html">Çerez Politikası</a>
        <a href="../ticari-elektronik-ileti-onayi.html">Ticari Elektronik İleti Onayı</a>
        <a href="../sss.html">Sıkça Sorulan Sorular</a>
      </div>
      <div class="footer-bottom">© 2026 MyExpress Kurye. Tüm hakları saklıdır.</div>
    </footer>
    <script src="../script.js?v=20260604-password-reset"></script>
  </body>
</html>
    <?php
}

function mx_account_flash(string $message = '', string $error = ''): void
{
    if ($message !== '') {
        echo '<div class="account-alert account-alert-success">' . mx_h($message) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="account-alert account-alert-error">' . mx_h($error) . '</div>';
    }
}

function mx_address_redirect_query(array $pickup, array $dropoff): string
{
    return http_build_query([
        'pickup' => $pickup['area'],
        'pickupLat' => $pickup['lat'],
        'pickupLng' => $pickup['lng'],
        'pickupStreet' => $pickup['address_text'],
        'recipientName' => $dropoff['contact_name'] ?? '',
        'recipientPhone' => $dropoff['contact_phone'] ?? '',
        'recipientEmail' => $dropoff['contact_email'] ?? '',
        'recipientTckn' => $dropoff['contact_tckn'] ?? '',
        'dropoff' => $dropoff['area'],
        'dropoffLat' => $dropoff['lat'],
        'dropoffLng' => $dropoff['lng'],
        'dropoffStreet' => $dropoff['address_text'],
    ]);
}
