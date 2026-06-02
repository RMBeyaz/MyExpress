<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mx_require_csrf();
}
require __DIR__ . '/_layout.php';
mx_customer_require_login();

$pdo = mx_pdo();
$customerId = mx_customer_id();
$hasRequests = mx_table_exists('courier_requests') && mx_column_exists('courier_requests', 'customer_id');
$addressCount = mx_table_exists('customer_addresses')
    ? (int) $pdo->query('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ' . (int) $customerId)->fetchColumn()
    : 0;
$requestCount = $hasRequests
    ? (int) $pdo->query('SELECT COUNT(*) FROM courier_requests WHERE customer_id = ' . (int) $customerId)->fetchColumn()
    : 0;
$invoiceCount = mx_table_exists('customer_invoices')
    ? (int) $pdo->query('SELECT COUNT(*) FROM customer_invoices WHERE customer_id = ' . (int) $customerId)->fetchColumn()
    : 0;
$latestRequests = [];
if ($hasRequests) {
    $stmt = $pdo->prepare('SELECT id, tracking_code, status, pickup, dropoff, price, created_at FROM courier_requests WHERE customer_id = :customer_id ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([':customer_id' => $customerId]);
    $latestRequests = $stmt->fetchAll();
}

mx_account_header('Hesap Özeti', 'dashboard');
?>
<section class="account-hero">
  <div>
    <p class="eyebrow">Hoş geldiniz</p>
    <h1><?= mx_h(mx_customer_name()) ?></h1>
    <p>Kayıtlı adreslerinizle talep oluşturabilir, geçmiş gönderilerinizi ve size tanımlanan faturaları buradan takip edebilirsiniz.</p>
  </div>
  <a class="btn btn-primary" href="yeni-talep.php">Kayıtlı Adresle Talep Aç</a>
</section>

<section class="account-stats">
  <a class="account-stat" href="adresler.php"><span>Kayıtlı adres</span><strong><?= $addressCount ?></strong></a>
  <a class="account-stat" href="talepler.php"><span>Geçmiş talep</span><strong><?= $requestCount ?></strong></a>
  <a class="account-stat" href="faturalar.php"><span>Fatura</span><strong><?= $invoiceCount ?></strong></a>
</section>

<section class="account-card">
  <div class="account-card-head">
    <h2>Son talepler</h2>
    <a href="talepler.php">Tümünü gör</a>
  </div>
  <div class="account-list">
    <?php if (!$latestRequests): ?>
      <p class="account-empty">Henüz hesabınıza bağlı kurye talebi yok.</p>
    <?php endif; ?>
    <?php foreach ($latestRequests as $request): ?>
      <a class="account-list-row" href="talep.php?id=<?= (int) $request['id'] ?>">
        <strong><?= mx_h($request['tracking_code']) ?></strong>
        <span><?= mx_h(mx_status_label((string) $request['status'])) ?></span>
        <small><?= mx_h($request['pickup']) ?> → <?= mx_h($request['dropoff']) ?></small>
        <em><?= mx_h($request['price']) ?></em>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php mx_account_footer(); ?>
