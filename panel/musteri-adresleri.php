<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_user_manager();

$pdo = mx_pdo();
$id = (int) ($_GET['id'] ?? 0);

if (!mx_table_exists('customers')) {
    http_response_code(404);
    echo 'Müşteri tablosu bulunamadı.';
    exit;
}

$stmt = $pdo->prepare('SELECT id, full_name, email, phone FROM customers WHERE id = :id');
$stmt->execute([':id' => $id]);
$customer = $stmt->fetch();
if (!$customer) {
    http_response_code(404);
    echo 'Müşteri bulunamadı.';
    exit;
}

$addresses = [];
if (mx_table_exists('customer_addresses')) {
    $addressStmt = $pdo->prepare('SELECT * FROM customer_addresses WHERE customer_id = :id ORDER BY is_default DESC, title ASC');
    $addressStmt->execute([':id' => $id]);
    $addresses = $addressStmt->fetchAll();
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mx_h($customer['full_name']) ?> | Müşteri Adresleri</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-panel-invoices">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">Müşteri adresleri</p>
          <h1><?= mx_h($customer['full_name']) ?></h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="musteriler.php">Müşterilere Dön</a>
          <a class="btn btn-secondary" href="musteri-hareketleri.php?id=<?= (int) $customer['id'] ?>">Talep Geçmişi</a>
          <a class="btn btn-secondary" href="musteri-faturalari.php?id=<?= (int) $customer['id'] ?>">Faturalar</a>
        </div>
      </section>

      <section class="panel-detail-grid">
        <article class="panel-card">
          <h2>Müşteri</h2>
          <dl class="panel-detail-list">
            <dt>E-posta</dt><dd><a href="mailto:<?= mx_h($customer['email']) ?>"><?= mx_h($customer['email']) ?></a></dd>
            <dt>Telefon</dt><dd><?= $customer['phone'] ? '<a href="tel:' . mx_h($customer['phone']) . '">' . mx_h($customer['phone']) . '</a>' : '-' ?></dd>
            <dt>Adres</dt><dd><?= count($addresses) ?> kayıt</dd>
          </dl>
        </article>
        <article class="panel-card">
          <h2>Not</h2>
          <p class="panel-help-text">Adresler müşterinin kendi hesabındaki adres defterinden gelir. Düzenleme işlemi müşteri hesabı üzerinden yapılır; panel burada operasyon için görüntüler.</p>
        </article>
      </section>

      <section class="panel-card">
        <div class="panel-card-heading">
          <h2>Kayıtlı Adresler</h2>
          <span><?= count($addresses) ?> kayıt</span>
        </div>
        <div class="panel-table-wrap">
          <table class="panel-table customer-address-table">
            <thead><tr><th>Adres</th><th>Yetkili</th><th>Bölge</th><th>Açık adres</th><th>Durum</th></tr></thead>
            <tbody>
              <?php foreach ($addresses as $address): ?>
                <tr>
                  <td><strong><?= mx_h($address['title']) ?></strong></td>
                  <td><?= mx_h($address['contact_name'] ?: '-') ?><br><small><?= mx_h($address['contact_phone'] ?: '-') ?></small></td>
                  <td><?= mx_h($address['area']) ?></td>
                  <td><?= mx_h($address['address_text']) ?></td>
                  <td><?= (int) $address['is_default'] === 1 ? 'Varsayılan' : '-' ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$addresses): ?><tr><td colspan="5">Bu müşterinin kayıtlı adresi yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
