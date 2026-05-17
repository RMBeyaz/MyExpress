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
        mx_audit_log($id, 'details_update', 'Talep detaylari panelden guncellendi.');
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
        </div>
      </section>

      <section class="panel-detail-grid">
        <article class="panel-card">
          <h2>Gönderi</h2>
          <dl class="panel-detail-list">
            <dt>Durum</dt><dd><?= mx_h(mx_status_label($request['status'])) ?></dd>
            <dt>Ücret</dt><dd><?= mx_h($request['price']) ?></dd>
            <dt>Mesafe</dt><dd><?= isset($request['distance_km']) && $request['distance_km'] !== null ? mx_h(number_format((float) $request['distance_km'], 1, ',', '.')) . ' km' : '-' ?></dd>
            <dt>Hizmet</dt><dd><?= mx_h($request['service_label']) ?></dd>
            <dt>Paket</dt><dd><?= mx_h($request['package_label']) ?></dd>
            <dt>Teslim zamanı</dt><dd><?= mx_h($request['delivery_time']) ?></dd>
            <dt>Oluşturma</dt><dd><?= mx_h($request['created_at']) ?></dd>
            <dt>Not</dt><dd><?= mx_h($request['note']) ?></dd>
          </dl>
        </article>

        <article class="panel-card">
          <h2>Adresler</h2>
          <h3>Alım</h3>
          <p><strong><?= mx_h($request['pickup']) ?></strong><br><?= nl2br(mx_h($request['pickup_street'])) ?></p>
          <h3>Teslim</h3>
          <p><strong><?= mx_h($request['dropoff']) ?></strong><br><?= nl2br(mx_h($request['dropoff_street'])) ?></p>
        </article>

        <article class="panel-card">
          <h2>Gönderici</h2>
          <dl class="panel-detail-list panel-detail-list-compact">
            <dt>Ad soyad</dt><dd><?= mx_h($request['sender_name']) ?></dd>
            <dt>Telefon</dt><dd><a href="tel:<?= mx_h($request['sender_phone']) ?>"><?= mx_h($request['sender_phone']) ?></a></dd>
            <dt>E-posta</dt><dd><?= mx_h($request['sender_email']) ?></dd>
            <dt>TCKN</dt><dd><?= mx_h($request['sender_tckn']) ?></dd>
          </dl>
        </article>

        <article class="panel-card">
          <h2>Alıcı</h2>
          <dl class="panel-detail-list panel-detail-list-compact">
            <dt>Ad soyad</dt><dd><?= mx_h($request['recipient_name']) ?></dd>
            <dt>Telefon</dt><dd><a href="tel:<?= mx_h($request['recipient_phone']) ?>"><?= mx_h($request['recipient_phone']) ?></a></dd>
            <dt>E-posta</dt><dd><?= mx_h($request['recipient_email']) ?></dd>
            <dt>TCKN</dt><dd><?= mx_h($request['recipient_tckn']) ?></dd>
          </dl>
        </article>
      </section>

      <section class="panel-detail-grid">
        <form class="panel-card" method="post">
          <h2>Durum Güncelle</h2>
          <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
          <input type="hidden" name="action" value="status">
          <div class="status-choice-grid">
            <?php foreach ($statuses as $key => $label): ?>
              <label class="status-choice <?= $request['status'] === $key ? 'is-selected' : '' ?>">
                <input type="radio" name="status" value="<?= mx_h($key) ?>" <?= $request['status'] === $key ? 'checked' : '' ?>>
                <span><?= mx_h($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <label>
            İç not
            <textarea name="note" placeholder="Arandı, ulaşılamadı, kurye atandı..."></textarea>
          </label>
          <button class="btn btn-primary" type="submit">Güncelle</button>
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

      <form class="panel-card panel-edit-form" method="post">
        <h2>Talep Bilgilerini Düzenle</h2>
        <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
        <input type="hidden" name="action" value="details">
        <div class="panel-edit-grid">
          <label>Alım bölgesi <input name="pickup" value="<?= mx_h($request['pickup']) ?>" required></label>
          <label>Teslim bölgesi <input name="dropoff" value="<?= mx_h($request['dropoff']) ?>" required></label>
          <label>Alım açık adres <textarea name="pickup_street" required><?= mx_h($request['pickup_street']) ?></textarea></label>
          <label>Teslim açık adres <textarea name="dropoff_street" required><?= mx_h($request['dropoff_street']) ?></textarea></label>
          <label>Hizmet <input name="service_label" value="<?= mx_h($request['service_label']) ?>"></label>
          <label>Paket <input name="package_label" value="<?= mx_h($request['package_label']) ?>"></label>
          <label>Teslim zamanı <input name="delivery_time" value="<?= mx_h($request['delivery_time']) ?>"></label>
          <label>Ücret <input name="price" value="<?= mx_h($request['price']) ?>" required></label>
          <label>Mesafe km <input name="distance_km" value="<?= isset($request['distance_km']) ? mx_h($request['distance_km']) : '' ?>" inputmode="decimal"></label>
          <label>Not <textarea name="note"><?= mx_h($request['note']) ?></textarea></label>
          <label>Gönderici ad soyad <input name="sender_name" value="<?= mx_h($request['sender_name']) ?>" required></label>
          <label>Gönderici telefon <input name="sender_phone" value="<?= mx_h($request['sender_phone']) ?>" required></label>
          <label>Gönderici e-posta <input name="sender_email" value="<?= mx_h($request['sender_email']) ?>"></label>
          <label>Gönderici TCKN <input name="sender_tckn" value="<?= mx_h($request['sender_tckn']) ?>" maxlength="11" required></label>
          <label>Alıcı ad soyad <input name="recipient_name" value="<?= mx_h($request['recipient_name']) ?>" required></label>
          <label>Alıcı telefon <input name="recipient_phone" value="<?= mx_h($request['recipient_phone']) ?>" required></label>
          <label>Alıcı e-posta <input name="recipient_email" value="<?= mx_h($request['recipient_email']) ?>"></label>
          <label>Alıcı TCKN <input name="recipient_tckn" value="<?= mx_h($request['recipient_tckn']) ?>" maxlength="11" required></label>
        </div>
        <button class="btn btn-primary" type="submit">Bilgileri Kaydet</button>
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
  </body>
</html>
