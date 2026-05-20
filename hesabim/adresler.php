<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
require __DIR__ . '/_layout.php';
mx_customer_require_login();

$pdo = mx_pdo();
$customerId = mx_customer_id();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mx_table_exists('customer_addresses')) {
            throw new RuntimeException('Adres tabloları henüz kurulmamış.');
        }

        $action = mx_clean_string($_POST['action'] ?? 'create', 32);
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM customer_addresses WHERE id = :id AND customer_id = :customer_id')->execute([
                ':id' => $id,
                ':customer_id' => $customerId,
            ]);
            $message = 'Adres silindi.';
        } else {
            $title = mx_clean_string($_POST['title'] ?? '', 80);
            $contactName = mx_clean_string($_POST['contact_name'] ?? '', 120);
            $contactPhone = mx_clean_string($_POST['contact_phone'] ?? '', 40);
            $area = mx_clean_string($_POST['area'] ?? '', 255);
            $addressText = mx_clean_text($_POST['address_text'] ?? '', 1000);
            $lat = is_numeric($_POST['lat'] ?? null) ? (float) $_POST['lat'] : null;
            $lng = is_numeric($_POST['lng'] ?? null) ? (float) $_POST['lng'] : null;
            $isDefault = isset($_POST['is_default']) ? 1 : 0;

            if ($title === '' || $area === '' || $addressText === '' || $lat === null || $lng === null) {
                throw new RuntimeException('Adres adı, bölge seçimi ve açık adres zorunludur. Bölgeyi listeden seçmelisiniz.');
            }
            if ($isDefault) {
                $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :customer_id')->execute([':customer_id' => $customerId]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO customer_addresses (customer_id, title, contact_name, contact_phone, area, lat, lng, address_text, is_default)
                 VALUES (:customer_id, :title, :contact_name, :contact_phone, :area, :lat, :lng, :address_text, :is_default)'
            );
            $stmt->execute([
                ':customer_id' => $customerId,
                ':title' => $title,
                ':contact_name' => $contactName,
                ':contact_phone' => $contactPhone,
                ':area' => $area,
                ':lat' => $lat,
                ':lng' => $lng,
                ':address_text' => $addressText,
                ':is_default' => $isDefault,
            ]);
            $message = 'Adres kaydedildi.';
        }
    } catch (Throwable $errorObject) {
        $error = $errorObject->getMessage();
        mx_log_error('customer address action failed', $errorObject, ['customer_id' => $customerId]);
    }
}

$addresses = [];
if (mx_table_exists('customer_addresses')) {
    $stmt = $pdo->prepare('SELECT * FROM customer_addresses WHERE customer_id = :customer_id ORDER BY is_default DESC, title ASC');
    $stmt->execute([':customer_id' => $customerId]);
    $addresses = $stmt->fetchAll();
}

mx_account_header('Adreslerim', 'addresses');
?>
<section class="account-page-head">
  <div><p class="eyebrow">Adres defteri</p><h1>Kayıtlı adresler</h1></div>
  <a class="btn btn-secondary" href="yeni-talep.php">Bu adreslerle talep aç</a>
</section>
<?php mx_account_flash($message, $error); ?>
<section class="account-grid-2">
  <form class="account-card account-form" method="post" data-account-address-form>
    <h2>Yeni adres</h2>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="lat">
    <input type="hidden" name="lng">
    <div class="account-form-grid">
      <label>Adres adı<input name="title" placeholder="Ofis, depo, ev" required></label>
      <label>Yetkili adı<input name="contact_name" placeholder="İsteğe bağlı"></label>
      <label>Yetkili telefon<input type="tel" name="contact_phone" placeholder="İsteğe bağlı"></label>
      <label>Bölge / mahalle
        <div class="autocomplete-field">
          <input name="area" placeholder="Listeden mahalle veya semt seçin" autocomplete="off" data-address-input data-address-scope="neighborhood" required>
          <div class="autocomplete-list" data-autocomplete-list></div>
        </div>
      </label>
    </div>
    <label>Açık adres / tarif<textarea name="address_text" required placeholder="Cadde, sokak, bina no, kat, daire, firma adı ve teslim tarifi"></textarea></label>
    <label class="account-check"><input type="checkbox" name="is_default"><span>Varsayılan adres olarak işaretle</span></label>
    <button class="btn btn-primary" type="submit">Adresi Kaydet</button>
  </form>
  <div class="account-card">
    <h2>Adres listesi</h2>
    <div class="account-list">
      <?php if (!$addresses): ?><p class="account-empty">Henüz kayıtlı adres yok.</p><?php endif; ?>
      <?php foreach ($addresses as $address): ?>
        <div class="account-list-row account-address-row">
          <strong><?= mx_h($address['title']) ?><?= (int) $address['is_default'] === 1 ? ' • Varsayılan' : '' ?></strong>
          <span><?= mx_h($address['area']) ?></span>
          <small><?= nl2br(mx_h($address['address_text'])) ?></small>
          <form method="post" onsubmit="return confirm('Bu adres silinsin mi?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $address['id'] ?>">
            <button class="panel-icon-btn" type="submit" aria-label="Adresi sil">🗑</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php mx_account_footer(); ?>
