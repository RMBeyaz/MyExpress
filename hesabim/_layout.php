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
    <link rel="stylesheet" href="../styles.css?v=20260521-mobile-menu-float">
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
    <footer class="account-footer">
      <span>MyExpress Kurye</span>
      <a href="tel:+905467691904">Hemen Ara</a>
      <a href="mailto:info@myexpress.com.tr">E-posta Gönder</a>
      <a href="https://wa.me/905467691904" target="_blank" rel="noopener">WhatsApp'tan Yaz</a>
    </footer>
    <script src="../script.js?v=20260521-mobile-menu-float"></script>
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
        'dropoff' => $dropoff['area'],
        'dropoffLat' => $dropoff['lat'],
        'dropoffLng' => $dropoff['lng'],
        'dropoffStreet' => $dropoff['address_text'],
    ]);
}
