<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_user_manager();

$pdo = mx_pdo();
$id = (int) ($_GET['id'] ?? 0);

if (!mx_table_exists('couriers')) {
    http_response_code(404);
    echo 'Kurye tablosu bulunamadi.';
    exit;
}

$stmt = $pdo->prepare('SELECT id, full_name, phone, vehicle_type, plate, is_active, created_at FROM couriers WHERE id = :id');
$stmt->execute([':id' => $id]);
$courier = $stmt->fetch();

if (!$courier) {
    http_response_code(404);
    echo 'Kurye bulunamadi.';
    exit;
}

$requests = [];
if (mx_column_exists('courier_requests', 'assigned_courier_id')) {
    $query = $pdo->prepare(
        'SELECT cr.id, cr.tracking_code, cr.status, cr.pickup, cr.dropoff, cr.price, cr.created_at,
                MAX(rsl.created_at) AS last_status_at
         FROM courier_requests cr
         LEFT JOIN request_status_logs rsl ON rsl.request_id = cr.id
         WHERE cr.assigned_courier_id = :id
         GROUP BY cr.id, cr.tracking_code, cr.status, cr.pickup, cr.dropoff, cr.price, cr.created_at
         ORDER BY COALESCE(MAX(rsl.created_at), cr.created_at) DESC
         LIMIT 120'
    );
    $query->execute([':id' => $id]);
    $requests = $query->fetchAll();
}

$statuses = mx_statuses();
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mx_h($courier['full_name']) ?> | Kurye Hareketleri</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-customer-portal">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">Kurye hareketleri</p>
          <h1><?= mx_h($courier['full_name']) ?></h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="kullanicilar.php">Kullanıcılara Dön</a>
          <a class="btn btn-secondary" href="index.php">Talepler</a>
        </div>
      </section>

      <section class="panel-detail-grid">
        <article class="panel-card">
          <h2>Kurye Bilgisi</h2>
          <dl class="panel-detail-list">
            <dt>Telefon</dt><dd><a href="tel:<?= mx_h($courier['phone']) ?>"><?= mx_h($courier['phone']) ?></a></dd>
            <dt>Araç</dt><dd><?= mx_h(trim((string) $courier['vehicle_type'] . ' ' . (string) $courier['plate'])) ?: '-' ?></dd>
            <dt>Durum</dt><dd><?= (int) $courier['is_active'] === 1 ? 'Aktif' : 'Pasif' ?></dd>
            <dt>Tanım tarihi</dt><dd><?= mx_h($courier['created_at']) ?></dd>
          </dl>
        </article>
        <article class="panel-card">
          <h2>Özet</h2>
          <p class="panel-help-text">Bu ekran kuryeye atanmış talepleri, güncel durumlarını ve son işlem zamanını gösterir.</p>
          <p><strong><?= count($requests) ?></strong> atanmış talep listeleniyor.</p>
        </article>
      </section>

      <section class="panel-card">
        <div class="panel-card-heading">
          <h2>Atanan Talepler</h2>
          <span><?= count($requests) ?> kayıt</span>
        </div>
        <div class="panel-table-wrap">
          <table class="panel-table user-table-singleline">
            <thead><tr><th>Talep</th><th>Durum</th><th>Rota</th><th>Ücret</th><th>Talep tarihi</th><th>Son işlem</th></tr></thead>
            <tbody>
              <?php foreach ($requests as $request): ?>
                <tr>
                  <td><a class="tracking-link" href="talep.php?id=<?= (int) $request['id'] ?>"><?= mx_h($request['tracking_code']) ?></a></td>
                  <td><span class="panel-status panel-status-<?= mx_h($request['status']) ?>"><?= mx_h($statuses[$request['status']] ?? $request['status']) ?></span></td>
                  <td><?= mx_h($request['pickup']) ?><br><small><?= mx_h($request['dropoff']) ?></small></td>
                  <td><?= mx_h($request['price']) ?></td>
                  <td><span class="nowrap"><?= mx_h($request['created_at']) ?></span></td>
                  <td><span class="nowrap"><?= mx_h($request['last_status_at'] ?: '-') ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$requests): ?><tr><td colspan="6">Bu kuryeye atanmış talep yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
