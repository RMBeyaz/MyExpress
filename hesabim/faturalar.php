<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
require __DIR__ . '/_layout.php';
mx_customer_require_login();

$invoices = [];
if (mx_table_exists('customer_invoices')) {
    $hasRequestColumn = mx_column_exists('customer_invoices', 'request_id');
    $requestSelect = $hasRequestColumn ? ', ci.request_id, cr.tracking_code' : ', NULL AS request_id, NULL AS tracking_code';
    $requestJoin = $hasRequestColumn ? ' LEFT JOIN courier_requests cr ON cr.id = ci.request_id' : '';
    $stmt = mx_pdo()->prepare(
        'SELECT ci.*' . $requestSelect . '
         FROM customer_invoices ci' . $requestJoin . '
         WHERE ci.customer_id = :customer_id AND ci.status = :status
         ORDER BY COALESCE(ci.invoice_date, ci.created_at) DESC'
    );
    $stmt->execute([':customer_id' => mx_customer_id(), ':status' => 'available']);
    $invoices = $stmt->fetchAll();
}

mx_account_header('Faturalarım', 'invoices');
?>
<section class="account-page-head">
  <div><p class="eyebrow">Cari belgeler</p><h1>Faturalarım</h1></div>
</section>
<section class="account-card">
  <div class="account-list">
    <?php if (!$invoices): ?><p class="account-empty">Henüz hesabınıza tanımlı fatura yok.</p><?php endif; ?>
    <?php foreach ($invoices as $invoice): ?>
      <a class="account-list-row" href="<?= mx_h($invoice['file_path']) ?>" target="_blank" rel="noopener">
        <strong><?= mx_h($invoice['title']) ?></strong>
        <span><?= mx_h($invoice['invoice_no'] ?: 'Fatura') ?><?= !empty($invoice['tracking_code']) ? ' · ' . mx_h($invoice['tracking_code']) : '' ?></span>
        <small><?= $invoice['invoice_date'] ? mx_h(date('d.m.Y', strtotime((string) $invoice['invoice_date']))) : 'Tarih belirtilmedi' ?></small>
        <em><?= $invoice['amount'] !== null ? mx_h(number_format((float) $invoice['amount'], 2, ',', '.')) . ' TL' : 'Tutar belirtilmedi' ?></em>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php mx_account_footer(); ?>
