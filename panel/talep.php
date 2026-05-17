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

    if ($id > 0 && $action === 'details') {
        $hasDistance = mx_column_exists('courier_requests', 'distance_km');
        $setDistance = $hasDistance ? ', distance_km = :distance_km' : '';
        $stmt = $pdo->prepare(
            "UPDATE courier_requests SET
                pickup = :pickup, pickup_street = :pickup_street,
                dropoff = :dropoff, dropoff_street = :dropoff_street,
                service_label = :service_label, package_label = :package_label,
                delivery_time = :delivery_time, note = :note, price = :price{$setDistance},
                sender_name = :sender_name, sender_phone = :sender_phone, sender_email = :sender_email, sender_tckn = :sender_tckn,
                recipient_name = :recipient_name, recipient_phone = :recipient_phone, recipient_email = :recipient_email, recipient_tckn = :recipient_tckn
             WHERE id = :id"
        );
        $params = [
            ':pickup' => mx_clean_string($_POST['pickup'] ?? '', 255),
            ':pickup_street' => mx_clean_text($_POST['pickup_street'] ?? '', 1000),
            ':dropoff' => mx_clean_string($_POST['dropoff'] ?? '', 255),
            ':dropoff_street' => mx_clean_text($_POST['dropoff_street'] ?? '', 1000),
            ':service_label' => mx_clean_string($_POST['service_label'] ?? '', 80),
            ':package_label' => mx_clean_string($_POST['package_label'] ?? '', 80),
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
        ];
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
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mx_h($request['tracking_code']) ?> | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">Talep detayı</p>
          <h1><?= mx_h($request['tracking_code']) ?></h1>
        </div>
        <div class="panel-header-actions">
          <span class="panel-status panel-status-<?= mx_h($request['status']) ?>"><?= mx_h(mx_status_label($request['status'])) ?></span>
          <a class="btn btn-secondary" href="index.php">Listeye Dön</a>
          <a class="btn btn-secondary" href="fiyatlandirma.php">Fiyatlandırma</a>
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
            <button class="btn btn-secondary" type="button" data-edit-toggle>Düzenle</button>
            <button class="btn btn-primary" type="submit" data-save-edit hidden>Değişiklikleri Kaydet</button>
          </div>
        </div>
        <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
        <input type="hidden" name="action" value="details">
        <div class="panel-edit-grid">
          <label>Alım bölgesi <input name="pickup" value="<?= mx_h($request['pickup']) ?>" required readonly></label>
          <label>Teslim bölgesi <input name="dropoff" value="<?= mx_h($request['dropoff']) ?>" required readonly></label>
          <label>Alım açık adres <textarea name="pickup_street" required readonly><?= mx_h($request['pickup_street']) ?></textarea></label>
          <label>Teslim açık adres <textarea name="dropoff_street" required readonly><?= mx_h($request['dropoff_street']) ?></textarea></label>
          <label>Hizmet <input name="service_label" value="<?= mx_h($request['service_label']) ?>" readonly></label>
          <label>Paket <input name="package_label" value="<?= mx_h($request['package_label']) ?>" readonly></label>
          <label>Teslim zamanı <input name="delivery_time" value="<?= mx_h($request['delivery_time']) ?>" readonly></label>
          <label>Ücret <input name="price" value="<?= mx_h($request['price']) ?>" required readonly></label>
          <label>Mesafe km <input name="distance_km" value="<?= isset($request['distance_km']) ? mx_h($request['distance_km']) : '' ?>" inputmode="decimal" readonly></label>
          <label>Not <textarea name="note" readonly><?= mx_h($request['note']) ?></textarea></label>
          <label>Gönderici ad soyad <input name="sender_name" value="<?= mx_h($request['sender_name']) ?>" required readonly></label>
          <label>Gönderici telefon <input name="sender_phone" value="<?= mx_h($request['sender_phone']) ?>" required readonly></label>
          <label>Gönderici e-posta <input name="sender_email" value="<?= mx_h($request['sender_email']) ?>" readonly></label>
          <label>Gönderici TCKN <input name="sender_tckn" value="<?= mx_h($request['sender_tckn']) ?>" maxlength="11" required readonly></label>
          <label>Alıcı ad soyad <input name="recipient_name" value="<?= mx_h($request['recipient_name']) ?>" required readonly></label>
          <label>Alıcı telefon <input name="recipient_phone" value="<?= mx_h($request['recipient_phone']) ?>" required readonly></label>
          <label>Alıcı e-posta <input name="recipient_email" value="<?= mx_h($request['recipient_email']) ?>" readonly></label>
          <label>Alıcı TCKN <input name="recipient_tckn" value="<?= mx_h($request['recipient_tckn']) ?>" maxlength="11" required readonly></label>
          <label>Değişiklik notu <textarea name="change_note" placeholder="Adres düzeltildi, telefon güncellendi..." readonly></textarea></label>
        </div>
      </form>

      <?php if ($auditLogs): ?>
        <section class="panel-card">
          <h2>İşlem Kayıtları</h2>
          <div class="panel-log">
            <?php foreach ($auditLogs as $auditLog): ?>
              <p><strong><?= mx_h($auditLog['action']) ?></strong> · <?= mx_h($auditLog['admin_user']) ?><br><?= mx_h($auditLog['created_at']) ?><br><span><?= mx_h($auditLog['details']) ?></span></p>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </main>
    <script>
      const editForm = document.querySelector('[data-panel-edit-form]');
      const editToggle = document.querySelector('[data-edit-toggle]');
      const saveButton = document.querySelector('[data-save-edit]');
      editToggle?.addEventListener('click', () => {
        editForm?.classList.remove('is-readonly');
        editForm?.querySelectorAll('input[readonly], textarea[readonly]').forEach((field) => field.removeAttribute('readonly'));
        editToggle.hidden = true;
        if (saveButton) saveButton.hidden = false;
      });
    </script>
  </body>
</html>
