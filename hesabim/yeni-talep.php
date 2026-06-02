<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mx_require_csrf();
}
require __DIR__ . '/_layout.php';
mx_customer_require_login();

$pdo = mx_pdo();
$customerId = mx_customer_id();
$error = '';
$addresses = [];
$defaultAddressId = 0;

if (mx_table_exists('customer_addresses')) {
    $stmt = $pdo->prepare('SELECT * FROM customer_addresses WHERE customer_id = :customer_id ORDER BY is_default DESC, title ASC');
    $stmt->execute([':customer_id' => $customerId]);
    $addresses = $stmt->fetchAll();
    foreach ($addresses as $address) {
        if ((int) $address['is_default'] === 1) {
            $defaultAddressId = (int) $address['id'];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pickupId = (int) ($_POST['pickup_address_id'] ?? 0);
    $dropoffId = (int) ($_POST['dropoff_address_id'] ?? 0);
    $byId = [];
    foreach ($addresses as $address) {
        $byId[(int) $address['id']] = $address;
    }

    if (!isset($byId[$pickupId], $byId[$dropoffId])) {
        $error = 'Alım ve teslim adresini seçin.';
    } elseif ($pickupId === $dropoffId) {
        $error = 'Alım ve teslim adresi farklı olmalı.';
    } elseif (!$byId[$pickupId]['lat'] || !$byId[$pickupId]['lng'] || !$byId[$dropoffId]['lat'] || !$byId[$dropoffId]['lng']) {
        $error = 'Seçilen adreslerde konum bilgisi eksik. Adresi listeden seçerek yeniden kaydedin.';
    } else {
        header('Location: ../talep.html?' . mx_address_redirect_query($byId[$pickupId], $byId[$dropoffId]));
        exit;
    }
}

mx_account_header('Yeni Talep', 'new-request');
?>
<section class="account-page-head">
  <div><p class="eyebrow">Hızlı talep</p><h1>Kayıtlı adresle talep</h1></div>
  <a class="btn btn-secondary" href="adresler.php">Adres Ekle</a>
</section>
<?php mx_account_flash('', $error); ?>
<section class="account-card">
  <form class="account-form" method="post">
    <?= mx_csrf_field() ?>
    <?php if (count($addresses) < 2): ?>
      <p class="account-empty">Bu ekranı kullanmak için en az iki kayıtlı adres gerekir. Dilerseniz yine de <a href="../talep.html">normal talep formundan</a> talep oluşturabilirsiniz.</p>
    <?php endif; ?>
    <div class="account-form-grid">
      <label>Alım adresi
        <select name="pickup_address_id" required>
          <option value="">Seçin</option>
          <?php foreach ($addresses as $address): ?>
            <option value="<?= (int) $address['id'] ?>" <?= (int) $address['id'] === $defaultAddressId ? 'selected' : '' ?>><?= mx_h($address['title']) ?> - <?= mx_h($address['area']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Teslim adresi
        <select name="dropoff_address_id" required>
          <option value="">Seçin</option>
          <?php foreach ($addresses as $address): ?>
            <option value="<?= (int) $address['id'] ?>"><?= mx_h($address['title']) ?> - <?= mx_h($address['area']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <p class="account-muted">Seçtiğiniz adresler talep formuna aktarılır. Paket, hizmet tipi ve kişi bilgileri sonraki adımda doldurulur.</p>
    <button class="btn btn-primary" type="submit"<?= count($addresses) < 2 ? ' disabled' : '' ?>>Talep Formuna Devam Et</button>
  </form>
</section>
<?php mx_account_footer(); ?>
