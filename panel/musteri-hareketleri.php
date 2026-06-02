<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mx_require_csrf();
}
mx_panel_require_user_manager();

$pdo = mx_pdo();
$id = (int) ($_GET['id'] ?? 0);

if (!mx_table_exists('customers')) {
    http_response_code(404);
    echo 'Müşteri tablosu bulunamadı.';
    exit;
}

$selectTckn = mx_column_exists('customers', 'tckn') ? ', tckn' : ", '' AS tckn";
$stmt = $pdo->prepare("SELECT id, full_name, email, phone, is_active, created_at{$selectTckn} FROM customers WHERE id = :id");
$stmt->execute([':id' => $id]);
$customer = $stmt->fetch();

if (!$customer) {
    http_response_code(404);
    echo 'Müşteri bulunamadı.';
    exit;
}

$requests = [];
if (mx_column_exists('courier_requests', 'customer_id')) {
    $query = $pdo->prepare(
        'SELECT id, tracking_code, status, pickup, dropoff, price, distance_km, created_at
         FROM courier_requests
         WHERE customer_id = :id
         ORDER BY created_at DESC
         LIMIT 250'
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
    <title><?= mx_h($customer['full_name']) ?> | Müşteri Talepleri</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-panel-invoices">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">Müşteri işlem geçmişi</p>
          <h1><?= mx_h($customer['full_name']) ?></h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="musteriler.php">Müşterilere Dön</a>
          <a class="btn btn-secondary" href="musteri-faturalari.php?id=<?= (int) $customer['id'] ?>">Faturalar</a>
          <a class="btn btn-secondary" href="index.php">Talepler</a>
        </div>
      </section>

      <section class="panel-detail-grid">
        <article class="panel-card">
          <h2>Müşteri Bilgisi</h2>
          <dl class="panel-detail-list">
            <dt>E-posta</dt><dd><a href="mailto:<?= mx_h($customer['email']) ?>"><?= mx_h($customer['email']) ?></a></dd>
            <dt>Telefon</dt><dd><?= $customer['phone'] ? '<a href="tel:' . mx_h($customer['phone']) . '">' . mx_h($customer['phone']) . '</a>' : '-' ?></dd>
            <dt>Durum</dt><dd><?= (int) $customer['is_active'] === 1 ? 'Aktif' : 'Pasif' ?></dd>
            <dt>Kayıt tarihi</dt><dd><?= mx_h($customer['created_at']) ?></dd>
          </dl>
        </article>
        <article class="panel-card">
          <h2>Özet</h2>
          <p class="panel-help-text">Bu ekranda müşteriye bağlı olarak açılmış talepler listelenir. Talep detayına giderek operasyon adımlarını görebilirsiniz.</p>
          <p><strong><?= count($requests) ?></strong> talep listeleniyor.</p>
        </article>
      </section>

      <section class="panel-card">
        <div class="panel-card-heading">
          <h2>Talep Geçmişi</h2>
          <span><?= count($requests) ?> kayıt</span>
        </div>
        <div class="panel-table-wrap">
          <table class="panel-table audit-table customer-history-table">
            <thead><tr><th>Talep</th><th>Durum</th><th>Rota</th><th>Mesafe</th><th>Ücret</th><th>Tarih</th></tr></thead>
            <tbody>
              <?php foreach ($requests as $request): ?>
                <tr>
                  <td><a class="tracking-link" href="talep.php?id=<?= (int) $request['id'] ?>"><?= mx_h($request['tracking_code']) ?></a></td>
                  <td><span class="panel-status panel-status-<?= mx_h($request['status']) ?>"><?= mx_h($statuses[$request['status']] ?? $request['status']) ?></span></td>
                  <td><?= mx_h($request['pickup']) ?><br><small><?= mx_h($request['dropoff']) ?></small></td>
                  <td><?= $request['distance_km'] !== null ? mx_h(number_format((float) $request['distance_km'], 1, ',', '.')) . ' km' : '-' ?></td>
                  <td><?= mx_h($request['price']) ?></td>
                  <td><strong><?= mx_h(date('H:i', strtotime($request['created_at']))) ?></strong><br><small><?= mx_h(date('d.m.Y', strtotime($request['created_at']))) ?></small></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$requests): ?><tr><td colspan="6">Bu müşteriye bağlı talep yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
