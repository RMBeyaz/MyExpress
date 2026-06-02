<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mx_require_csrf();
}
require __DIR__ . '/_layout.php';
mx_customer_require_login();

$requests = [];
if (mx_table_exists('courier_requests') && mx_column_exists('courier_requests', 'customer_id')) {
    $stmt = mx_pdo()->prepare(
        'SELECT id, tracking_code, status, pickup, dropoff, price, created_at
         FROM courier_requests
         WHERE customer_id = :customer_id
         ORDER BY created_at DESC'
    );
    $stmt->execute([':customer_id' => mx_customer_id()]);
    $requests = $stmt->fetchAll();
}
[$requests, $requestsPagination] = mx_paginate_array($requests, 'requests', 10);

mx_account_header('Taleplerim', 'requests');
?>
<section class="account-page-head">
  <div><p class="eyebrow">Gönderi geçmişi</p><h1>Taleplerim</h1></div>
  <a class="btn btn-primary" href="yeni-talep.php">Yeni Talep</a>
</section>
<section class="account-card">
  <div class="account-list">
    <?php if (!$requests): ?><p class="account-empty">Henüz hesabınıza bağlı talep yok.</p><?php endif; ?>
    <?php foreach ($requests as $request): ?>
      <a class="account-list-row" href="talep.php?id=<?= (int) $request['id'] ?>">
        <strong><?= mx_h($request['tracking_code']) ?></strong>
        <span><?= mx_h(mx_status_label((string) $request['status'])) ?></span>
        <small><?= mx_h($request['pickup']) ?> → <?= mx_h($request['dropoff']) ?></small>
        <em><?= mx_h($request['price']) ?> • <?= mx_h(date('d.m.Y H:i', strtotime((string) $request['created_at']))) ?></em>
      </a>
    <?php endforeach; ?>
  </div>
  <?= mx_render_pagination($requestsPagination, 'requests', 'Taleplerim') ?>
</section>
<?php mx_account_footer(); ?>
