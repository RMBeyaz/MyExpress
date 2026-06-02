<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mx_require_csrf();
}
require __DIR__ . '/_layout.php';
mx_customer_require_login();

$id = (int) ($_GET['id'] ?? 0);
$request = null;
$logs = [];

if ($id > 0 && mx_table_exists('courier_requests') && mx_column_exists('courier_requests', 'customer_id')) {
    $stmt = mx_pdo()->prepare('SELECT * FROM courier_requests WHERE id = :id AND customer_id = :customer_id LIMIT 1');
    $stmt->execute([':id' => $id, ':customer_id' => mx_customer_id()]);
    $request = $stmt->fetch() ?: null;

    if ($request && mx_table_exists('request_status_logs')) {
        $logStmt = mx_pdo()->prepare('SELECT status, note, created_at FROM request_status_logs WHERE request_id = :id ORDER BY created_at DESC');
        $logStmt->execute([':id' => $id]);
        $logs = $logStmt->fetchAll();
    }
}
[$logs, $logsPagination] = mx_paginate_array($logs, 'logs', 10);

mx_account_header($request ? (string) $request['tracking_code'] : 'Talep Detayı', 'requests');
?>
<?php if (!$request): ?>
  <section class="account-card">
    <h1>Talep bulunamadı</h1>
    <p class="account-muted">Bu talep hesabınıza bağlı olmayabilir veya silinmiş olabilir.</p>
    <a class="btn btn-secondary" href="talepler.php">Taleplere Dön</a>
  </section>
<?php else: ?>
  <section class="account-page-head">
    <div>
      <p class="eyebrow">Talep detayı</p>
      <h1><?= mx_h($request['tracking_code']) ?></h1>
      <p><?= mx_h(mx_status_label((string) $request['status'])) ?> • <?= mx_h(date('d.m.Y H:i', strtotime((string) $request['created_at']))) ?></p>
    </div>
    <a class="btn btn-secondary" href="../takip.html?no=<?= rawurlencode((string) $request['tracking_code']) ?>">Takip Sayfasında Aç</a>
  </section>
  <section class="account-grid-2">
    <article class="account-card">
      <h2>Gönderi özeti</h2>
      <dl class="account-detail-list">
        <dt>Alım</dt><dd><?= mx_h($request['pickup']) ?><br><small><?= nl2br(mx_h($request['pickup_street'])) ?></small></dd>
        <dt>Teslim</dt><dd><?= mx_h($request['dropoff']) ?><br><small><?= nl2br(mx_h($request['dropoff_street'])) ?></small></dd>
        <dt>Hizmet</dt><dd><?= mx_h($request['service_label']) ?> / <?= mx_h($request['package_label']) ?></dd>
        <dt>Ücret</dt><dd><?= mx_h($request['price']) ?></dd>
        <dt>Mesafe</dt><dd><?= $request['distance_km'] !== null ? mx_h($request['distance_km']) . ' km' : 'Hesaplanmadı' ?></dd>
      </dl>
    </article>
    <article class="account-card">
      <h2>İşlem geçmişi</h2>
      <div class="account-timeline">
        <?php if (!$logs): ?><p class="account-empty">Henüz işlem geçmişi yok.</p><?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <div>
            <strong><?= mx_h(mx_status_label((string) $log['status'])) ?></strong>
            <span><?= mx_h(date('d.m.Y H:i', strtotime((string) $log['created_at']))) ?></span>
            <?php if ($log['note']): ?><p><?= mx_h($log['note']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?= mx_render_pagination($logsPagination, 'logs', 'İşlem geçmişi') ?>
    </article>
  </section>
<?php endif; ?>
<?php mx_account_footer(); ?>
