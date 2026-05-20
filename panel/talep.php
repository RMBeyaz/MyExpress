<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_login();

$pdo = mx_pdo();
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = mx_clean_string($_POST['action'] ?? 'status', 32);

    if ($id > 0 && $action === 'delete') {
        $deleteReason = mx_clean_text($_POST['delete_reason'] ?? '', 600);
        if ($deleteReason === '') {
            header('Location: talep.php?id=' . $id);
            exit;
        }

        $stmt = $pdo->prepare('SELECT tracking_code FROM courier_requests WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $trackingCode = (string) ($stmt->fetchColumn() ?: '');
        if ($trackingCode !== '') {
            mx_audit_log($id, 'request_delete', 'Talep detayindan silindi. Talep: ' . $trackingCode . ' Aciklama: ' . $deleteReason);
            $pdo->prepare('DELETE FROM courier_requests WHERE id = :id')->execute([':id' => $id]);
        }

        header('Location: index.php?notice=deleted');
        exit;
    }

    if ($id > 0 && $action === 'status') {
        $status = mx_clean_string($_POST['status'] ?? '', 32);
        $note = mx_clean_text($_POST['note'] ?? '', 1000);
        $allowedStatuses = array_keys(mx_statuses());

        if (!in_array($status, $allowedStatuses, true)) {
            header('Location: talep.php?id=' . $id);
            exit;
        }

        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE courier_requests SET status = :status WHERE id = :id');
        $update->execute([':status' => $status, ':id' => $id]);

        $log = $pdo->prepare('INSERT INTO request_status_logs (request_id, status, note) VALUES (:id, :status, :note)');
        $log->execute([':id' => $id, ':status' => $status, ':note' => $note]);
        $pdo->commit();
        mx_audit_log($id, 'status_update', 'Durum ' . mx_status_label($status) . ' olarak guncellendi. Not: ' . $note);
    }

    if ($id > 0 && $action === 'assign_courier') {
        if (!mx_table_exists('couriers') || !mx_column_exists('courier_requests', 'assigned_courier_id')) {
            header('Location: talep.php?id=' . $id);
            exit;
        }

        $courierId = (int) ($_POST['courier_id'] ?? 0);
        if ($courierId > 0) {
            $courierStmt = $pdo->prepare('SELECT full_name, phone FROM couriers WHERE id = :id AND is_active = 1');
            $courierStmt->execute([':id' => $courierId]);
            $courier = $courierStmt->fetch();
            if ($courier) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE courier_requests SET assigned_courier_id = :courier_id, status = 'assigned' WHERE id = :id")->execute([
                    ':courier_id' => $courierId,
                    ':id' => $id,
                ]);
                $pdo->prepare('INSERT INTO request_status_logs (request_id, status, note) VALUES (:id, :status, :note)')->execute([
                    ':id' => $id,
                    ':status' => 'assigned',
                    ':note' => 'Kurye atandı: ' . $courier['full_name'] . ' (' . $courier['phone'] . ')',
                ]);
                $pdo->commit();
                mx_audit_log($id, 'courier_assign', $courier['full_name'] . ' kuryesi atandi. Telefon: ' . $courier['phone']);
            }
        } else {
            $pdo->prepare('UPDATE courier_requests SET assigned_courier_id = NULL WHERE id = :id')->execute([':id' => $id]);
            mx_audit_log($id, 'courier_unassign', 'Kurye atamasi kaldirildi.');
        }
    }

    if ($id > 0 && $action === 'details') {
        $hasDistance = mx_column_exists('courier_requests', 'distance_km');
        $setDistance = $hasDistance ? ', distance_km = :distance_km' : '';
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
        $addressColumns = [
            'pickup_city' => ['post' => 'pickup_city', 'max' => 80],
            'pickup_district' => ['post' => 'pickup_district', 'max' => 80],
            'pickup_road' => ['post' => 'pickup_road', 'max' => 160],
            'pickup_building_no' => ['post' => 'pickup_building_no', 'max' => 80],
            'dropoff_city' => ['post' => 'dropoff_city', 'max' => 80],
            'dropoff_district' => ['post' => 'dropoff_district', 'max' => 80],
            'dropoff_road' => ['post' => 'dropoff_road', 'max' => 160],
            'dropoff_building_no' => ['post' => 'dropoff_building_no', 'max' => 80],
        ];
        $setAddressColumns = '';
        $addressParams = [];
        $addressFallbacks = [
            'pickup_city' => 'İstanbul',
            'pickup_district' => $deriveDistrict((string) ($_POST['pickup'] ?? '')),
            'pickup_road' => '',
            'pickup_building_no' => '',
            'dropoff_city' => 'İstanbul',
            'dropoff_district' => $deriveDistrict((string) ($_POST['dropoff'] ?? '')),
            'dropoff_road' => '',
            'dropoff_building_no' => '',
        ];
        foreach ($addressColumns as $column => $meta) {
            if (mx_column_exists('courier_requests', $column)) {
                $setAddressColumns .= ', ' . $column . ' = :' . $column;
                $addressParams[':' . $column] = mx_clean_string($_POST[$meta['post']] ?? $addressFallbacks[$column] ?? '', (int) $meta['max']);
            }
        }
        $pricing = mx_pricing_settings();
        $service = mx_clean_string($_POST['service'] ?? 'normal', 40);
        $packageType = mx_clean_string($_POST['package_type'] ?? 'evrak', 40);
        if (!isset($pricing['services'][$service])) {
            $service = 'normal';
        }
        if (!isset($pricing['packages'][$packageType])) {
            $packageType = 'evrak';
        }
        $serviceLabel = $pricing['services'][$service]['label'] ?? $service;
        $packageLabel = $pricing['packages'][$packageType]['label'] ?? $packageType;
        $stmt = $pdo->prepare(
            "UPDATE courier_requests SET
                pickup = :pickup, pickup_lat = :pickup_lat, pickup_lng = :pickup_lng, pickup_street = :pickup_street,
                dropoff = :dropoff, dropoff_lat = :dropoff_lat, dropoff_lng = :dropoff_lng, dropoff_street = :dropoff_street{$setAddressColumns},
                service = :service, service_label = :service_label, package_type = :package_type, package_label = :package_label,
                delivery_time = :delivery_time, note = :note, price = :price{$setDistance},
                sender_name = :sender_name, sender_phone = :sender_phone, sender_email = :sender_email, sender_tckn = :sender_tckn,
                recipient_name = :recipient_name, recipient_phone = :recipient_phone, recipient_email = :recipient_email, recipient_tckn = :recipient_tckn
             WHERE id = :id"
        );
        $params = [
            ':pickup' => mx_clean_string($_POST['pickup'] ?? '', 255),
            ':pickup_lat' => is_numeric($_POST['pickup_lat'] ?? null) ? (float) $_POST['pickup_lat'] : null,
            ':pickup_lng' => is_numeric($_POST['pickup_lng'] ?? null) ? (float) $_POST['pickup_lng'] : null,
            ':pickup_street' => mx_clean_text($_POST['pickup_street'] ?? '', 1000),
            ':dropoff' => mx_clean_string($_POST['dropoff'] ?? '', 255),
            ':dropoff_lat' => is_numeric($_POST['dropoff_lat'] ?? null) ? (float) $_POST['dropoff_lat'] : null,
            ':dropoff_lng' => is_numeric($_POST['dropoff_lng'] ?? null) ? (float) $_POST['dropoff_lng'] : null,
            ':dropoff_street' => mx_clean_text($_POST['dropoff_street'] ?? '', 1000),
            ':service' => $service,
            ':service_label' => mx_clean_string($serviceLabel, 80),
            ':package_type' => $packageType,
            ':package_label' => mx_clean_string($packageLabel, 80),
            ':delivery_time' => mx_clean_string($_POST['delivery_time'] ?? '', 80),
            ':note' => mx_clean_text($_POST['note'] ?? '', 1000),
            ':price' => mx_clean_string($_POST['price'] ?? '', 40),
            ':sender_name' => mx_clean_string($_POST['sender_name'] ?? '', 120),
            ':sender_phone' => mx_clean_string($_POST['sender_phone'] ?? '', 40),
            ':sender_email' => mx_clean_string($_POST['sender_email'] ?? '', 160),
            ':sender_tckn' => preg_replace('/\D/', '', (string) ($_POST['sender_tckn'] ?? '')),
            ':recipient_name' => mx_clean_string($_POST['recipient_name'] ?? '', 120),
            ':recipient_phone' => mx_clean_string($_POST['recipient_phone'] ?? '', 40),
            ':recipient_email' => mx_clean_string($_POST['recipient_email'] ?? '', 160),
            ':recipient_tckn' => preg_replace('/\D/', '', (string) ($_POST['recipient_tckn'] ?? '')),
            ':id' => $id,
        ] + $addressParams;
        if ($hasDistance) {
            $params[':distance_km'] = is_numeric($_POST['distance_km'] ?? null) ? (float) $_POST['distance_km'] : null;
        }
        $stmt->execute($params);
        $changeNote = mx_clean_text($_POST['change_note'] ?? '', 1000);
        mx_audit_log($id, 'details_update', 'Talep detaylari panelden guncellendi.' . ($changeNote !== '' ? ' Not: ' . $changeNote : ''));
    }

    header('Location: talep.php?id=' . $id);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM courier_requests WHERE id = :id');
$stmt->execute([':id' => $id]);
$request = $stmt->fetch();

if (!$request) {
    http_response_code(404);
    echo 'Talep bulunamadi.';
    exit;
}

$logs = $pdo->prepare('SELECT status, note, created_at FROM request_status_logs WHERE request_id = :id ORDER BY created_at DESC');
$logs->execute([':id' => $id]);
$statusLogs = $logs->fetchAll();
$auditLogs = [];
if (mx_table_exists('request_audit_logs')) {
    $audit = $pdo->prepare('SELECT admin_user, action, details, created_at FROM request_audit_logs WHERE request_id = :id ORDER BY created_at DESC LIMIT 50');
    $audit->execute([':id' => $id]);
    $auditLogs = $audit->fetchAll();
}

$statuses = mx_statuses();
$hasCourierAssignment = mx_table_exists('couriers') && mx_column_exists('courier_requests', 'assigned_courier_id');
$couriers = [];
$assignedCourier = null;
if ($hasCourierAssignment) {
    $couriers = $pdo->query('SELECT id, full_name, phone, vehicle_type, plate FROM couriers WHERE is_active = 1 ORDER BY full_name')->fetchAll();
    if (!empty($request['assigned_courier_id'])) {
        $assignedStmt = $pdo->prepare('SELECT id, full_name, phone, vehicle_type, plate FROM couriers WHERE id = :id');
        $assignedStmt->execute([':id' => (int) $request['assigned_courier_id']]);
        $assignedCourier = $assignedStmt->fetch() ?: null;
    }
}
$pricing = mx_pricing_settings();
$serviceOptions = $pricing['services'];
$packageOptions = array_intersect_key($pricing['packages'], array_flip(['evrak', 'kucuk', 'orta', 'buyuk', 'motorDisi']));
if (!isset($serviceOptions[$request['service']])) {
    $serviceOptions[$request['service']] = ['label' => $request['service_label'], 'base' => 0, 'km' => 0, 'multiplier' => 1];
}
if (!isset($packageOptions[$request['package_type']])) {
    $packageOptions[$request['package_type']] = ['label' => $request['package_label'], 'fee' => 0];
}
$deliveryOptions = ['En kısa sürede', 'Bugün içinde', 'Belirli saat aralığı', 'İleri tarihli teslimat', 'Gece teslimat'];
if ($request['delivery_time'] !== '' && !in_array($request['delivery_time'], $deliveryOptions, true)) {
    $deliveryOptions[] = $request['delivery_time'];
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mx_h($request['tracking_code']) ?> | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-mobile-menu-float">
  </head>
  <body class="panel-body request-detail-page request-detail-flow">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">Talep detayı</p>
          <h1><?= mx_h($request['tracking_code']) ?></h1>
        </div>
        <div class="panel-header-actions">
          <span class="panel-status panel-status-<?= mx_h($request['status']) ?>"><?= mx_h(mx_status_label($request['status'])) ?></span>
          <button class="btn btn-danger" type="button" data-delete-open data-id="<?= (int) $request['id'] ?>" data-code="<?= mx_h($request['tracking_code']) ?>">Talebi Sil</button>
          <a class="btn btn-secondary" href="index.php">Listeye Dön</a>
        </div>
      </section>

      <section class="panel-detail-grid">
        <form class="panel-card" method="post">
          <h2>Durum Güncelle</h2>
          <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
          <input type="hidden" name="action" value="status">
          <div class="status-update-row">
            <label>
              Durum
              <select name="status">
                <?php foreach ($statuses as $key => $label): ?>
                  <option value="<?= mx_h($key) ?>" <?= $request['status'] === $key ? 'selected' : '' ?>><?= mx_h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button class="btn btn-primary" type="submit">Durumu Güncelle</button>
          </div>
          <label>
            İç not
            <textarea name="note" placeholder="Arandı, ulaşılamadı, kurye atandı..."></textarea>
          </label>
        </form>

        <form class="panel-card courier-assignment-card" method="post">
          <h2>Kurye Atama</h2>
          <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
          <input type="hidden" name="action" value="assign_courier">
          <?php if ($hasCourierAssignment): ?>
            <div class="status-update-row">
              <label>
                Kurye
                <select name="courier_id">
                  <option value="0">Kurye seçilmedi</option>
                  <?php foreach ($couriers as $courier): ?>
                    <option value="<?= (int) $courier['id'] ?>" <?= (int) ($request['assigned_courier_id'] ?? 0) === (int) $courier['id'] ? 'selected' : '' ?>>
                      <?= mx_h($courier['full_name']) ?> · <?= mx_h($courier['phone']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button class="btn btn-primary" type="submit">Kuryeyi Ata</button>
            </div>
            <?php if ($assignedCourier): ?>
              <p class="courier-current"><strong>Atanan kurye:</strong> <?= mx_h($assignedCourier['full_name']) ?> · <a href="tel:<?= mx_h($assignedCourier['phone']) ?>"><?= mx_h($assignedCourier['phone']) ?></a></p>
            <?php endif; ?>
            <p class="panel-help-text">Kurye seçildiğinde talep durumu otomatik olarak “Kurye Atandı” olur.</p>
          <?php else: ?>
            <p class="panel-alert">Kurye ataması için önce <code>migrations/006_couriers.sql</code> çalıştırılmalı.</p>
          <?php endif; ?>
        </form>

        <article class="panel-card">
          <h2>Durum Geçmişi</h2>
          <div class="panel-log">
            <?php foreach ($statusLogs as $log): ?>
              <p><strong><?= mx_h($statuses[$log['status']] ?? $log['status']) ?></strong><br><?= mx_h($log['created_at']) ?><br><span><?= mx_h($log['note']) ?></span></p>
            <?php endforeach; ?>
          </div>
        </article>
      </section>

      <form class="panel-card panel-edit-form is-readonly" method="post" data-panel-edit-form>
        <div class="panel-card-heading">
          <h2>Talep Bilgileri</h2>
          <div class="panel-header-actions">
            <button class="panel-icon-btn edit-action" type="button" data-edit-toggle aria-label="Talep bilgilerini düzenle" title="Düzenle">
              <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M4 17.25V20h2.75L17.8 8.95l-2.75-2.75L4 17.25Zm15.7-10.1a1 1 0 0 0 0-1.42l-1.43-1.43a1 1 0 0 0-1.42 0l-1.12 1.12 2.75 2.75 1.22-1.02Z"/></svg>
            </button>
            <button class="panel-icon-btn save" type="submit" data-save-edit disabled aria-label="Değişiklikleri kaydet" title="Değişiklikleri kaydet">
              <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9.5 16.6 5.9 13l-1.4 1.4 5 5L20 8.9 18.6 7.5l-9.1 9.1Z"/></svg>
            </button>
            <button class="panel-icon-btn cancel" type="button" data-cancel-edit disabled aria-label="Vazgeç" title="Vazgeç">
              <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6.4 5 5 6.4l5.6 5.6L5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6 6.4 5Z"/></svg>
            </button>
          </div>
        </div>
        <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
        <input type="hidden" name="action" value="details">
        <input type="hidden" name="pickup_lat" value="<?= mx_h($request['pickup_lat']) ?>" data-pickup-lat>
        <input type="hidden" name="pickup_lng" value="<?= mx_h($request['pickup_lng']) ?>" data-pickup-lng>
        <input type="hidden" name="dropoff_lat" value="<?= mx_h($request['dropoff_lat']) ?>" data-dropoff-lat>
        <input type="hidden" name="dropoff_lng" value="<?= mx_h($request['dropoff_lng']) ?>" data-dropoff-lng>
        <div class="panel-edit-sections">
          <section class="panel-edit-section">
            <h3>Gönderi</h3>
            <div class="panel-edit-grid panel-edit-grid-compact">
              <label>Hizmet
                <select name="service" disabled data-priced-field>
                  <?php foreach ($serviceOptions as $key => $service): ?>
                    <option value="<?= mx_h($key) ?>" <?= $request['service'] === $key ? 'selected' : '' ?>><?= mx_h($service['label'] ?? $key) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Paket
                <select name="package_type" disabled data-priced-field>
                  <?php foreach ($packageOptions as $key => $package): ?>
                    <option value="<?= mx_h($key) ?>" <?= $request['package_type'] === $key ? 'selected' : '' ?>><?= mx_h($package['label'] ?? $key) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Teslim zamanı
                <select name="delivery_time" disabled>
                  <?php foreach ($deliveryOptions as $option): ?>
                    <option value="<?= mx_h($option) ?>" <?= $request['delivery_time'] === $option ? 'selected' : '' ?>><?= mx_h($option) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Mesafe km <input name="distance_km" value="<?= isset($request['distance_km']) ? mx_h($request['distance_km']) : '' ?>" inputmode="decimal" readonly data-distance-input></label>
              <label>Ücret <input name="price" value="<?= mx_h($request['price']) ?>" required readonly data-price-input></label>
              <div class="panel-inline-actions">
                <button class="btn btn-secondary" type="button" data-distance-calculate disabled>KM Hesapla</button>
              </div>
            </div>
          </section>
          <section class="panel-edit-section">
            <h3>Adresler</h3>
            <div class="panel-edit-grid panel-edit-grid-address">
              <label>Alım bölgesi <input name="pickup" value="<?= mx_h($request['pickup']) ?>" required readonly></label>
              <label>Teslim bölgesi <input name="dropoff" value="<?= mx_h($request['dropoff']) ?>" required readonly></label>
              <label>Alım açık adres / tarif <textarea name="pickup_street" required readonly><?= mx_h($request['pickup_street']) ?></textarea></label>
              <label>Teslim açık adres / tarif <textarea name="dropoff_street" required readonly><?= mx_h($request['dropoff_street']) ?></textarea></label>
            </div>
          </section>
          <section class="panel-edit-section">
            <h3>Taraf Bilgileri</h3>
            <div class="panel-edit-grid panel-edit-grid-compact">
              <label>Gönderici ad soyad <input name="sender_name" value="<?= mx_h($request['sender_name']) ?>" required readonly></label>
              <label>Gönderici telefon <input name="sender_phone" value="<?= mx_h($request['sender_phone']) ?>" required readonly></label>
              <label>Gönderici e-posta <input name="sender_email" value="<?= mx_h($request['sender_email']) ?>" readonly></label>
              <label>Gönderici TCKN <input name="sender_tckn" value="<?= mx_h($request['sender_tckn']) ?>" maxlength="11" required readonly></label>
              <label>Alıcı ad soyad <input name="recipient_name" value="<?= mx_h($request['recipient_name']) ?>" required readonly></label>
              <label>Alıcı telefon <input name="recipient_phone" value="<?= mx_h($request['recipient_phone']) ?>" required readonly></label>
              <label>Alıcı e-posta <input name="recipient_email" value="<?= mx_h($request['recipient_email']) ?>" readonly></label>
              <label>Alıcı TCKN <input name="recipient_tckn" value="<?= mx_h($request['recipient_tckn']) ?>" maxlength="11" readonly></label>
            </div>
          </section>
          <section class="panel-edit-section">
            <h3>Notlar</h3>
            <div class="panel-edit-grid panel-edit-grid-notes">
              <label>Talep notu <textarea name="note" readonly><?= mx_h($request['note']) ?></textarea></label>
              <label>Değişiklik notu <textarea name="change_note" placeholder="Adres düzeltildi, telefon güncellendi..." readonly></textarea></label>
            </div>
          </section>
        </div>
      </form>

      <?php if ($auditLogs): ?>
        <section class="panel-card panel-audit-card">
          <h2>İşlem Kayıtları</h2>
          <div class="panel-log">
            <?php foreach ($auditLogs as $auditLog): ?>
              <p><strong><?= mx_h($auditLog['action']) ?></strong> · <?= mx_h($auditLog['admin_user']) ?><br><?= mx_h($auditLog['created_at']) ?><br><span><?= mx_h($auditLog['details']) ?></span></p>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </main>
    <div class="panel-modal" data-delete-modal hidden>
      <form class="panel-modal-card" method="post">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" data-delete-id value="<?= (int) $request['id'] ?>">
        <h2>Talebi Sil</h2>
        <p><strong data-delete-code><?= mx_h($request['tracking_code']) ?></strong> numaralı talep kalıcı olarak silinecek.</p>
        <label>Silme açıklaması
          <textarea name="delete_reason" required placeholder="Neden silindi?"></textarea>
        </label>
        <div class="panel-modal-actions">
          <button class="btn btn-secondary" type="button" data-delete-close>Vazgeç</button>
          <button class="btn btn-primary" type="submit">Evet, Sil</button>
        </div>
      </form>
    </div>
    <div class="panel-modal" data-edit-confirm-modal hidden>
      <div class="panel-modal-card" role="dialog" aria-modal="true" aria-labelledby="edit-confirm-title">
        <h2 id="edit-confirm-title" data-edit-confirm-title>İşlemi Onayla</h2>
        <p data-edit-confirm-text>Bu işlem için onay gerekiyor.</p>
        <div class="panel-modal-actions">
          <button class="btn btn-secondary" type="button" data-edit-confirm-close>Vazgeç</button>
          <button class="btn btn-primary" type="button" data-edit-confirm-approve>Onayla</button>
        </div>
      </div>
    </div>
    <script>
      const pricingConfig = <?= json_encode($pricing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const editForm = document.querySelector('[data-panel-edit-form]');
      const editToggle = document.querySelector('[data-edit-toggle]');
      const saveButton = document.querySelector('[data-save-edit]');
      const cancelEditButton = document.querySelector('[data-cancel-edit]');
      const deleteModal = document.querySelector('[data-delete-modal]');
      const editConfirmModal = document.querySelector('[data-edit-confirm-modal]');
      const editConfirmTitle = document.querySelector('[data-edit-confirm-title]');
      const editConfirmText = document.querySelector('[data-edit-confirm-text]');
      const editConfirmApprove = document.querySelector('[data-edit-confirm-approve]');
      const editConfirmClose = document.querySelector('[data-edit-confirm-close]');
      const distanceButton = document.querySelector('[data-distance-calculate]');
      const distanceInput = document.querySelector('[data-distance-input]');
      const priceInput = document.querySelector('[data-price-input]');
      const pickupInput = editForm?.elements.pickup;
      const dropoffInput = editForm?.elements.dropoff;
      const pickupLat = document.querySelector('[data-pickup-lat]');
      const pickupLng = document.querySelector('[data-pickup-lng]');
      const dropoffLat = document.querySelector('[data-dropoff-lat]');
      const dropoffLng = document.querySelector('[data-dropoff-lng]');
      let editSubmitApproved = false;
      let pendingConfirmAction = null;
      const initialEditValues = new Map();
      editForm?.querySelectorAll('input, textarea, select').forEach((field) => {
        if (field.name) initialEditValues.set(field.name, field.value);
      });

      const setEditMode = (enabled) => {
        if (!editForm) return;
        editForm.classList.toggle('is-readonly', !enabled);
        editForm.querySelectorAll('input:not([type="hidden"]), textarea').forEach((field) => {
          if (enabled) {
            field.removeAttribute('readonly');
          } else {
            field.setAttribute('readonly', 'readonly');
          }
        });
        editForm.querySelectorAll('select').forEach((field) => {
          field.disabled = !enabled;
        });
        [saveButton, cancelEditButton, distanceButton].forEach((button) => {
          if (button) button.disabled = !enabled;
        });
        if (editToggle) editToggle.hidden = enabled;
      };

      const restoreEditValues = () => {
        editForm?.querySelectorAll('input, textarea, select').forEach((field) => {
          if (!field.name || !initialEditValues.has(field.name)) return;
          field.value = initialEditValues.get(field.name);
        });
        editSubmitApproved = false;
        setEditMode(false);
      };

      const openEditConfirm = ({ title, text, approveText, action }) => {
        pendingConfirmAction = action;
        if (editConfirmTitle) editConfirmTitle.textContent = title;
        if (editConfirmText) editConfirmText.textContent = text;
        if (editConfirmApprove) editConfirmApprove.textContent = approveText;
        if (editConfirmModal) editConfirmModal.hidden = false;
      };

      const closeEditConfirm = () => {
        pendingConfirmAction = null;
        if (editConfirmModal) editConfirmModal.hidden = true;
      };

      const earthDistance = (from, to) => {
        const radius = 6371;
        const latDelta = (to.lat - from.lat) * Math.PI / 180;
        const lngDelta = (to.lng - from.lng) * Math.PI / 180;
        const a = Math.sin(latDelta / 2) ** 2
          + Math.cos(from.lat * Math.PI / 180) * Math.cos(to.lat * Math.PI / 180)
          * Math.sin(lngDelta / 2) ** 2;
        return radius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
      };

      const roundPrice = (value) => {
        const roundTo = Number(pricingConfig.rules?.roundTo || 10);
        return `${Math.round(value / roundTo) * roundTo} TL`;
      };

      const formatDecimal = (value) => String(Math.round(value * 100) / 100).replace('.', ',');

      const parseDecimal = (value) => Number(String(value || '').replace(',', '.')) || 0;

      const priceForDistance = () => {
        const serviceKey = editForm?.elements.service?.value || 'normal';
        const packageKey = editForm?.elements.package_type?.value || 'evrak';
        const service = pricingConfig.services?.[serviceKey] || pricingConfig.services?.normal || {};
        const packageItem = pricingConfig.packages?.[packageKey] || {};
        const km = parseDecimal(distanceInput?.value);
        const pickupLngValue = Number(pickupLng?.value);
        const dropoffLngValue = Number(dropoffLng?.value);
        const bridgeFee = Number.isFinite(pickupLngValue) && Number.isFinite(dropoffLngValue)
          && ((pickupLngValue < 29 && dropoffLngValue >= 29) || (pickupLngValue >= 29 && dropoffLngValue < 29))
          ? Number(pricingConfig.rules?.bridgeFee || 0)
          : 0;
        const total = (Number(service.base || 0) + km * Number(service.km || 0) + Number(packageItem.fee || 0) + bridgeFee)
          * Number(service.multiplier || 1);
        return roundPrice(total);
      };

      const updateSuggestedPrice = (ask = false) => {
        if (!priceInput || !distanceInput?.value) return;
        const suggested = priceForDistance();
        if (!ask || window.confirm(`Fiyat ${suggested} olacak şekilde güncellensin mi?`)) {
          priceInput.value = suggested;
        }
      };

      const geocodePanelAddress = async (query) => {
        const url = new URL('https://nominatim.openstreetmap.org/search');
        url.searchParams.set('format', 'jsonv2');
        url.searchParams.set('limit', '1');
        url.searchParams.set('countrycodes', 'tr');
        url.searchParams.set('accept-language', 'tr');
        url.searchParams.set('viewbox', '28.01,41.65,29.95,40.72');
        url.searchParams.set('bounded', '1');
        url.searchParams.set('q', `${query}, İstanbul`);
        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
        const results = response.ok ? await response.json() : [];
        if (!results.length) throw new Error('Adres koordinatı bulunamadı.');
        return { lat: Number(results[0].lat), lng: Number(results[0].lon) };
      };

      const panelAddressQuery = (prefix) => [
        editForm?.elements[`${prefix}_street`]?.value,
        editForm?.elements[prefix]?.value,
      ].filter(Boolean).join(', ');

      const calculatePanelDistance = async () => {
        if (!pickupInput?.value || !dropoffInput?.value || !distanceInput) return;
        const originalText = distanceButton?.textContent || 'KM Hesapla';
        if (distanceButton) {
          distanceButton.disabled = true;
          distanceButton.textContent = 'Hesaplanıyor...';
        }
        try {
          const [pickupLocation, dropoffLocation] = await Promise.all([
            geocodePanelAddress(panelAddressQuery('pickup')),
            geocodePanelAddress(panelAddressQuery('dropoff')),
          ]);
          const raw = earthDistance(pickupLocation, dropoffLocation);
          const sameArea = pickupInput.value.trim() === dropoffInput.value.trim();
          const minKm = sameArea ? Number(pricingConfig.rules?.minSameAreaKm || 4) : Number(pricingConfig.rules?.minDefaultKm || 7);
          const billable = Math.max(raw * Number(pricingConfig.rules?.routeMultiplier || 1.28), minKm);
          pickupLat.value = pickupLocation.lat.toFixed(7);
          pickupLng.value = pickupLocation.lng.toFixed(7);
          dropoffLat.value = dropoffLocation.lat.toFixed(7);
          dropoffLng.value = dropoffLocation.lng.toFixed(7);
          distanceInput.value = formatDecimal(billable);
          updateSuggestedPrice(true);
        } catch (error) {
          window.alert(error.message || 'KM hesaplanamadı.');
        } finally {
          if (distanceButton) {
            distanceButton.disabled = false;
            distanceButton.textContent = originalText;
          }
        }
      };

      editToggle?.addEventListener('click', () => {
        setEditMode(true);
      });
      editForm?.addEventListener('submit', (event) => {
        if (!editSubmitApproved) {
          event.preventDefault();
          openEditConfirm({
            title: 'Değişiklikler kaydedilsin mi?',
            text: 'Talep üzerindeki düzenlemeler kaydedilecek ve işlem kayıtlarına yazılacak.',
            approveText: 'Evet, Kaydet',
            action: 'save',
          });
          return;
        }
        editForm.querySelectorAll('select[disabled], button[disabled]').forEach((field) => field.removeAttribute('disabled'));
      });
      cancelEditButton?.addEventListener('click', () => {
        openEditConfirm({
          title: 'Değişiklikler iptal edilsin mi?',
          text: 'Kaydedilmemiş değişiklikler silinecek ve talep eski haliyle gösterilecek.',
          approveText: 'Evet, İptal Et',
          action: 'cancel',
        });
      });
      editConfirmApprove?.addEventListener('click', () => {
        if (pendingConfirmAction === 'save') {
          editSubmitApproved = true;
          closeEditConfirm();
          editForm?.requestSubmit();
          return;
        }
        if (pendingConfirmAction === 'cancel') {
          closeEditConfirm();
          restoreEditValues();
        }
      });
      editConfirmClose?.addEventListener('click', closeEditConfirm);
      editConfirmModal?.addEventListener('click', (event) => {
        if (event.target === editConfirmModal) closeEditConfirm();
      });
      distanceInput?.addEventListener('change', () => updateSuggestedPrice(false));
      editForm?.querySelectorAll('[data-priced-field]').forEach((field) => {
        field.addEventListener('change', () => updateSuggestedPrice(false));
      });
      distanceButton?.addEventListener('click', calculatePanelDistance);
      document.querySelector('[data-delete-open]')?.addEventListener('click', () => {
        deleteModal.hidden = false;
      });
      document.querySelector('[data-delete-close]')?.addEventListener('click', () => {
        deleteModal.hidden = true;
      });
      deleteModal?.addEventListener('click', (event) => {
        if (event.target === deleteModal) deleteModal.hidden = true;
      });
    </script>
  </body>
</html>
