<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_login();

$pdo = mx_pdo();
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = mx_clean_string($_POST['status'] ?? '', 32);
    $note = mx_clean_text($_POST['note'] ?? '', 1000);
    $allowedStatuses = ['new', 'called', 'assigned', 'picked_up', 'delivered', 'cancelled'];

    if ($id > 0 && in_array($status, $allowedStatuses, true)) {
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE courier_requests SET status = :status WHERE id = :id');
        $update->execute([':status' => $status, ':id' => $id]);

        $log = $pdo->prepare('INSERT INTO request_status_logs (request_id, status, note) VALUES (:id, :status, :note)');
        $log->execute([':id' => $id, ':status' => $status, ':note' => $note]);
        $pdo->commit();
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

$statuses = [
    'new' => 'Yeni',
    'called' => 'Arandı',
    'assigned' => 'Kurye Atandı',
    'picked_up' => 'Teslim Alındı',
    'delivered' => 'Teslim Edildi',
    'cancelled' => 'İptal',
];
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
        <a class="btn btn-secondary" href="index.php">Listeye Dön</a>
      </section>

      <section class="panel-detail-grid">
        <article class="panel-card">
          <h2>Gönderi</h2>
          <dl class="panel-detail-list">
            <dt>Durum</dt><dd><?= mx_h($statuses[$request['status']] ?? $request['status']) ?></dd>
            <dt>Ücret</dt><dd><?= mx_h($request['price']) ?></dd>
            <dt>Hizmet</dt><dd><?= mx_h($request['service_label']) ?></dd>
            <dt>Paket</dt><dd><?= mx_h($request['package_label']) ?></dd>
            <dt>Teslim zamanı</dt><dd><?= mx_h($request['delivery_time']) ?></dd>
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
          <p><?= mx_h($request['sender_name']) ?><br><?= mx_h($request['sender_phone']) ?><br><?= mx_h($request['sender_email']) ?><br>TCKN: <?= mx_h($request['sender_tckn']) ?></p>
        </article>

        <article class="panel-card">
          <h2>Alıcı</h2>
          <p><?= mx_h($request['recipient_name']) ?><br><?= mx_h($request['recipient_phone']) ?><br><?= mx_h($request['recipient_email']) ?><br>TCKN: <?= mx_h($request['recipient_tckn']) ?></p>
        </article>
      </section>

      <section class="panel-detail-grid">
        <form class="panel-card" method="post">
          <h2>Durum Güncelle</h2>
          <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
          <label>
            Durum
            <select name="status">
              <?php foreach ($statuses as $key => $label): ?>
                <option value="<?= mx_h($key) ?>" <?= $request['status'] === $key ? 'selected' : '' ?>><?= mx_h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
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
    </main>
  </body>
</html>
