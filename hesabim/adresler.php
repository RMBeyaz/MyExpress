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
$editAddress = null;
if (isset($_GET['edit']) && mx_table_exists('customer_addresses')) {
    $editStmt = $pdo->prepare('SELECT * FROM customer_addresses WHERE id = :id AND customer_id = :customer_id LIMIT 1');
    $editStmt->execute([':id' => (int) $_GET['edit'], ':customer_id' => $customerId]);
    $editAddress = $editStmt->fetch() ?: null;
}

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
            $id = (int) ($_POST['id'] ?? 0);
            $title = mx_clean_string($_POST['title'] ?? '', 80);
            $contactName = mx_clean_string($_POST['contact_name'] ?? '', 120);
            $contactPhone = mx_clean_string($_POST['contact_phone'] ?? '', 40);
            $contactEmail = mx_clean_string($_POST['contact_email'] ?? '', 160);
            $contactTckn = preg_replace('/\D/', '', (string) ($_POST['contact_tckn'] ?? ''));
            $area = mx_clean_string($_POST['area'] ?? '', 255);
            $addressText = mx_clean_text($_POST['address_text'] ?? '', 1000);
            $lat = is_numeric($_POST['lat'] ?? null) ? (float) $_POST['lat'] : null;
            $lng = is_numeric($_POST['lng'] ?? null) ? (float) $_POST['lng'] : null;
            $isDefault = isset($_POST['is_default']) ? 1 : 0;

            if ($title === '' || $area === '' || $addressText === '' || $lat === null || $lng === null) {
                throw new RuntimeException('Adres adı, bölge seçimi ve açık adres zorunludur. Bölgeyi listeden seçmelisiniz.');
            }
            if ($contactTckn !== '' && !mx_valid_tckn($contactTckn)) {
                throw new RuntimeException('Yetkili T.C. kimlik numarası geçerli değil.');
            }
            if ($isDefault) {
                $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :customer_id')->execute([':customer_id' => $customerId]);
            }

            $params = [
                ':customer_id' => $customerId,
                ':title' => $title,
                ':contact_name' => $contactName,
                ':contact_phone' => $contactPhone,
                ':contact_email' => $contactEmail,
                ':contact_tckn' => $contactTckn,
                ':area' => $area,
                ':lat' => $lat,
                ':lng' => $lng,
                ':address_text' => $addressText,
                ':is_default' => $isDefault,
            ];

            if ($action === 'update') {
                $params[':id'] = $id;
                $stmt = $pdo->prepare(
                    'UPDATE customer_addresses
                     SET title = :title, contact_name = :contact_name, contact_phone = :contact_phone, contact_email = :contact_email, contact_tckn = :contact_tckn,
                         area = :area, lat = :lat, lng = :lng, address_text = :address_text, is_default = :is_default
                     WHERE id = :id AND customer_id = :customer_id'
                );
                $stmt->execute($params);
                $message = 'Adres güncellendi.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO customer_addresses (customer_id, title, contact_name, contact_phone, contact_email, contact_tckn, area, lat, lng, address_text, is_default)
                     VALUES (:customer_id, :title, :contact_name, :contact_phone, :contact_email, :contact_tckn, :area, :lat, :lng, :address_text, :is_default)'
                );
                $stmt->execute($params);
                $message = 'Adres kaydedildi.';
            }
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
    <h2><?= $editAddress ? 'Adresi düzenle' : 'Yeni adres' ?></h2>
    <input type="hidden" name="action" value="<?= $editAddress ? 'update' : 'create' ?>">
    <input type="hidden" name="id" value="<?= $editAddress ? (int) $editAddress['id'] : 0 ?>">
    <input type="hidden" name="lat" value="<?= mx_h($editAddress['lat'] ?? '') ?>">
    <input type="hidden" name="lng" value="<?= mx_h($editAddress['lng'] ?? '') ?>">
    <div class="account-form-grid">
      <label>Adres adı<input name="title" placeholder="Ofis, depo, ev" value="<?= mx_h($editAddress['title'] ?? '') ?>" required></label>
      <label>Yetkili adı<input name="contact_name" placeholder="Teslim/alım kişisi" value="<?= mx_h($editAddress['contact_name'] ?? '') ?>"></label>
      <label>Yetkili telefon<input type="tel" name="contact_phone" placeholder="05..." value="<?= mx_h($editAddress['contact_phone'] ?? '') ?>"></label>
      <label>Yetkili e-posta<input type="email" name="contact_email" placeholder="İsteğe bağlı" value="<?= mx_h($editAddress['contact_email'] ?? '') ?>"></label>
      <label>Yetkili T.C. kimlik no<input name="contact_tckn" inputmode="numeric" maxlength="11" placeholder="İsteğe bağlı" value="<?= mx_h($editAddress['contact_tckn'] ?? '') ?>"></label>
      <label>Bölge / mahalle
        <div class="autocomplete-field">
          <input name="area" placeholder="Listeden mahalle veya semt seçin" autocomplete="off" data-address-input data-address-scope="neighborhood" value="<?= mx_h($editAddress['area'] ?? '') ?>" data-selected-label="<?= mx_h($editAddress['area'] ?? '') ?>" data-selected-lat="<?= mx_h($editAddress['lat'] ?? '') ?>" data-selected-lng="<?= mx_h($editAddress['lng'] ?? '') ?>" data-selected-type="Mahalle/Semt" required>
          <div class="autocomplete-list" data-autocomplete-list></div>
        </div>
      </label>
    </div>
    <label>Açık adres / tarif<textarea name="address_text" required placeholder="Cadde, sokak, bina no, kat, daire, firma adı ve teslim tarifi"><?= mx_h($editAddress['address_text'] ?? '') ?></textarea></label>
    <label class="account-check"><input type="checkbox" name="is_default" <?= $editAddress && (int) $editAddress['is_default'] === 1 ? 'checked' : '' ?>><span>Varsayılan adres olarak işaretle</span></label>
    <p class="account-muted">Varsayılan adres, hesabınızdan yeni talep açarken alım adresi olarak otomatik seçilir.</p>
    <div class="account-form-actions">
      <button class="btn btn-primary" type="submit"><?= $editAddress ? 'Değişiklikleri Kaydet' : 'Adresi Kaydet' ?></button>
      <?php if ($editAddress): ?><a class="btn btn-secondary" href="adresler.php">Vazgeç</a><?php endif; ?>
    </div>
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
          <div class="account-row-actions">
            <a class="panel-icon-btn" href="adresler.php?edit=<?= (int) $address['id'] ?>" aria-label="Adresi düzenle">✎</a>
            <form method="post" onsubmit="return confirm('Bu adres silinsin mi?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $address['id'] ?>">
              <button class="panel-icon-btn" type="submit" aria-label="Adresi sil">🗑</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php mx_account_footer(); ?>
