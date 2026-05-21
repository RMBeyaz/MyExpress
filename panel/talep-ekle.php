<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_login();

$pdo = mx_pdo();
$message = '';
$error = '';
$pricing = mx_pricing_settings();
$statuses = mx_statuses();

$deriveDistrict = static function (string $area): string {
    $parts = array_values(array_filter(array_map('trim', preg_split('/[,\/]+/', $area) ?: [])));
    $filtered = [];
    foreach ($parts as $part) {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
        if (preg_match('/\b(sokak|sok\.|sk\.|cadde|cad\.|cd\.|bulvar|blv\.|no|apt|daire|kat)\b/u', $lower)) {
            continue;
        }
        if (preg_match('/\d/u', $lower)) {
            continue;
        }
        $filtered[] = $part;
    }
    if (count($filtered) >= 2) {
        return (string) end($filtered);
    }
    return $filtered[0] ?? '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mx_table_exists('courier_requests')) {
            throw new RuntimeException('courier_requests tablosu bulunamadı.');
        }

        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $status = mx_clean_string($_POST['status'] ?? 'new', 32);
        if (!isset($statuses[$status])) {
            $status = 'new';
        }
        $service = mx_clean_string($_POST['service'] ?? 'normal', 40);
        if (!isset($pricing['services'][$service])) {
            $service = 'normal';
        }
        $packageType = mx_clean_string($_POST['package_type'] ?? 'evrak', 40);
        if (!isset($pricing['packages'][$packageType])) {
            $packageType = 'evrak';
        }

        $pickup = mx_clean_string($_POST['pickup'] ?? 'Manuel alım adresi', 255);
        $dropoff = mx_clean_string($_POST['dropoff'] ?? 'Manuel teslim adresi', 255);
        $pickupStreet = mx_clean_text($_POST['pickup_street'] ?? '', 1000);
        $dropoffStreet = mx_clean_text($_POST['dropoff_street'] ?? '', 1000);
        $distanceRaw = str_replace(',', '.', (string) ($_POST['distance_km'] ?? ''));
        $distanceKm = is_numeric($distanceRaw) ? (float) $distanceRaw : null;
        $price = mx_clean_string($_POST['price'] ?? '', 80);
        if ($price === '') {
            $price = '0 TL';
        }

        $customerColumnSql = '';
        $customerValueSql = '';
        $customerParams = [];
        if (mx_column_exists('courier_requests', 'customer_id')) {
            $customerColumnSql = 'customer_id, ';
            $customerValueSql = ':customer_id, ';
            $customerParams[':customer_id'] = $customerId > 0 ? $customerId : null;
        }

        $extraColumns = [];
        $extraParams = [];
        $addressValues = [
            'pickup_city' => mx_clean_string($_POST['pickup_city'] ?? 'İstanbul', 80),
            'pickup_district' => mx_clean_string($_POST['pickup_district'] ?? $deriveDistrict($pickup), 80),
            'pickup_road' => mx_clean_string($_POST['pickup_road'] ?? '', 160),
            'pickup_building_no' => mx_clean_string($_POST['pickup_building_no'] ?? '', 80),
            'dropoff_city' => mx_clean_string($_POST['dropoff_city'] ?? 'İstanbul', 80),
            'dropoff_district' => mx_clean_string($_POST['dropoff_district'] ?? $deriveDistrict($dropoff), 80),
            'dropoff_road' => mx_clean_string($_POST['dropoff_road'] ?? '', 160),
            'dropoff_building_no' => mx_clean_string($_POST['dropoff_building_no'] ?? '', 80),
        ];
        foreach ($addressValues as $column => $value) {
            if (mx_column_exists('courier_requests', $column)) {
                $extraColumns[] = $column;
                $extraParams[':' . $column] = $value;
            }
        }
        $extraColumnsSql = $extraColumns ? ', ' . implode(', ', $extraColumns) : '';
        $extraValuesSql = $extraColumns ? ', :' . implode(', :', $extraColumns) : '';

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO courier_requests (
                ' . $customerColumnSql . 'tracking_code, status, pickup, pickup_lat, pickup_lng, pickup_street,
                dropoff, dropoff_lat, dropoff_lng, dropoff_street' . $extraColumnsSql . ',
                service, service_label, package_type, package_label, delivery_time, note, price, distance_km,
                sender_name, sender_phone, sender_email, sender_tckn,
                recipient_name, recipient_phone, recipient_email, recipient_tckn,
                service_agreement_accepted, kvkk_accepted, ip_address, user_agent
            ) VALUES (
                ' . $customerValueSql . ':tracking_code, :status, :pickup, :pickup_lat, :pickup_lng, :pickup_street,
                :dropoff, :dropoff_lat, :dropoff_lng, :dropoff_street' . $extraValuesSql . ',
                :service, :service_label, :package_type, :package_label, :delivery_time, :note, :price, :distance_km,
                :sender_name, :sender_phone, :sender_email, :sender_tckn,
                :recipient_name, :recipient_phone, :recipient_email, :recipient_tckn,
                :service_agreement_accepted, :kvkk_accepted, :ip_address, :user_agent
            )'
        );
        $stmt->execute([
            ':tracking_code' => mx_tracking_code(),
            ':status' => $status,
            ':pickup' => $pickup,
            ':pickup_lat' => is_numeric($_POST['pickup_lat'] ?? null) ? (float) $_POST['pickup_lat'] : null,
            ':pickup_lng' => is_numeric($_POST['pickup_lng'] ?? null) ? (float) $_POST['pickup_lng'] : null,
            ':pickup_street' => $pickupStreet,
            ':dropoff' => $dropoff,
            ':dropoff_lat' => is_numeric($_POST['dropoff_lat'] ?? null) ? (float) $_POST['dropoff_lat'] : null,
            ':dropoff_lng' => is_numeric($_POST['dropoff_lng'] ?? null) ? (float) $_POST['dropoff_lng'] : null,
            ':dropoff_street' => $dropoffStreet,
            ':service' => $service,
            ':service_label' => $pricing['services'][$service]['label'] ?? $service,
            ':package_type' => $packageType,
            ':package_label' => $pricing['packages'][$packageType]['label'] ?? $packageType,
            ':delivery_time' => mx_clean_string($_POST['delivery_time'] ?? 'Panel manuel kayıt', 120),
            ':note' => mx_clean_text($_POST['note'] ?? 'Panelden manuel oluşturuldu.', 1000),
            ':price' => $price,
            ':distance_km' => $distanceKm,
            ':sender_name' => mx_clean_string($_POST['sender_name'] ?? '', 120),
            ':sender_phone' => mx_clean_string($_POST['sender_phone'] ?? '', 40),
            ':sender_email' => mx_clean_string($_POST['sender_email'] ?? '', 160),
            ':sender_tckn' => preg_replace('/\D+/', '', (string) ($_POST['sender_tckn'] ?? '')),
            ':recipient_name' => mx_clean_string($_POST['recipient_name'] ?? '', 120),
            ':recipient_phone' => mx_clean_string($_POST['recipient_phone'] ?? '', 40),
            ':recipient_email' => mx_clean_string($_POST['recipient_email'] ?? '', 160),
            ':recipient_tckn' => preg_replace('/\D+/', '', (string) ($_POST['recipient_tckn'] ?? '')),
            ':service_agreement_accepted' => 1,
            ':kvkk_accepted' => 1,
            ':ip_address' => mx_clean_string($_SERVER['REMOTE_ADDR'] ?? '', 45),
            ':user_agent' => 'panel-manual:' . mx_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 220),
        ] + $customerParams + $extraParams);

        $requestId = (int) $pdo->lastInsertId();
        $trackingCode = mx_tracking_code_for_id($requestId);
        $pdo->prepare('UPDATE courier_requests SET tracking_code = :tracking_code WHERE id = :id')->execute([
            ':tracking_code' => $trackingCode,
            ':id' => $requestId,
        ]);
        if (mx_table_exists('request_status_logs')) {
            $pdo->prepare('INSERT INTO request_status_logs (request_id, status, note) VALUES (:id, :status, :note)')->execute([
                ':id' => $requestId,
                ':status' => $status,
                ':note' => 'Talep panelden manuel oluşturuldu.',
            ]);
        }
        $pdo->commit();
        mx_audit_log($requestId, 'request_manual_create', 'Panelden manuel talep oluşturuldu. Talep: ' . $trackingCode);
        header('Location: talep.php?id=' . $requestId);
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Talep oluşturulamadı: ' . $exception->getMessage();
        mx_log_error('panel manual request failed', $exception);
    }
}

$customers = [];
if (mx_table_exists('customers')) {
    $customers = $pdo->query('SELECT id, full_name, email, phone FROM customers WHERE is_active = 1 ORDER BY full_name')->fetchAll();
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manuel Talep | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-panel-invoices">
  </head>
  <body class="panel-body request-detail-page">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">Panel talep girişi</p>
          <h1>Manuel Talep Ekle</h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="index.php">Talepler</a>
          <a class="btn btn-secondary" href="musteriler.php">Müşteriler</a>
          <a class="btn btn-secondary" href="logout.php">Çıkış Yap</a>
        </div>
      </section>

      <?php if ($error !== ''): ?><p class="panel-alert"><?= mx_h($error) ?></p><?php endif; ?>

      <form class="panel-card manual-request-form" method="post">
        <div class="panel-card-heading">
          <h2>Talep Bilgileri</h2>
          <span>Operasyon panelinden hızlı kayıt</span>
        </div>

        <div class="panel-edit-grid manual-request-grid">
          <label>Müşteri
            <select name="customer_id">
              <option value="0">Müşteri bağlantısı yok</option>
              <?php foreach ($customers as $customer): ?>
                <option value="<?= (int) $customer['id'] ?>"><?= mx_h($customer['full_name'] . ' - ' . $customer['email']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Durum
            <select name="status">
              <?php foreach ($statuses as $key => $label): ?>
                <option value="<?= mx_h($key) ?>"><?= mx_h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Hizmet
            <select name="service">
              <?php foreach ($pricing['services'] as $key => $service): ?>
                <option value="<?= mx_h($key) ?>"><?= mx_h($service['label'] ?? $key) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Paket
            <select name="package_type">
              <?php foreach ($pricing['packages'] as $key => $package): ?>
                <option value="<?= mx_h($key) ?>"><?= mx_h($package['label'] ?? $key) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Mesafe km <input name="distance_km" inputmode="decimal" placeholder="Örn. 12,5"></label>
          <label>Ücret <input name="price" placeholder="Örn. 850 TL"></label>
          <label>Teslimat zamanı <input name="delivery_time" placeholder="En kısa sürede"></label>
        </div>

        <div class="panel-detail-grid">
          <section class="panel-subcard">
            <h3>Alım ve Gönderici</h3>
            <div class="panel-edit-grid panel-edit-grid-address">
              <label>Alım bölgesi / mahalle <input name="pickup" placeholder="Kadıköy / Caferağa Mahallesi"></label>
              <label>Açık adres <textarea name="pickup_street" placeholder="Cadde, sokak, bina, kat, daire"></textarea></label>
              <label>İlçe <input name="pickup_district"></label>
              <label>Şehir <input name="pickup_city" value="İstanbul"></label>
              <label>Gönderici ad soyad <input name="sender_name"></label>
              <label>Gönderici telefon <input name="sender_phone" inputmode="tel"></label>
              <label>Gönderici e-posta <input type="email" name="sender_email"></label>
              <label>Gönderici TCKN <input name="sender_tckn" inputmode="numeric" maxlength="11"></label>
            </div>
          </section>
          <section class="panel-subcard">
            <h3>Teslim ve Alıcı</h3>
            <div class="panel-edit-grid panel-edit-grid-address">
              <label>Teslim bölgesi / mahalle <input name="dropoff" placeholder="Şişli / Nişantaşı"></label>
              <label>Açık adres <textarea name="dropoff_street" placeholder="Cadde, sokak, bina, kat, daire"></textarea></label>
              <label>İlçe <input name="dropoff_district"></label>
              <label>Şehir <input name="dropoff_city" value="İstanbul"></label>
              <label>Alıcı ad soyad <input name="recipient_name"></label>
              <label>Alıcı telefon <input name="recipient_phone" inputmode="tel"></label>
              <label>Alıcı e-posta <input type="email" name="recipient_email"></label>
              <label>Alıcı TCKN <input name="recipient_tckn" inputmode="numeric" maxlength="11"></label>
            </div>
          </section>
        </div>

        <label>Operasyon notu<textarea name="note" placeholder="Panelden manuel oluşturuldu."></textarea></label>
        <div class="panel-form-actions">
          <button class="btn btn-primary" type="submit">Talep Oluştur</button>
          <a class="btn btn-secondary" href="index.php">Vazgeç</a>
        </div>
      </form>
    </main>
  </body>
</html>
