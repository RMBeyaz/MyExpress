<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
require __DIR__ . '/_layout.php';
mx_customer_require_login();

$invoices = [];
if (mx_table_exists('customer_invoices')) {
    $stmt = mx_pdo()->prepare(
        'SELECT * FROM customer_invoices WHERE customer_id = :customer_id ORDER BY COALESCE(invoice_date, created_at) DESC'
    );
    $stmt->execute([':customer_id' => mx_customer_id()]);
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
        <span><?= mx_h($invoice['invoice_no'] ?: 'Fatura') ?></span>
        <small><?= $invoice['invoice_date'] ? mx_h(date('d.m.Y', strtotime((string) $invoice['invoice_date']))) : 'Tarih belirtilmedi' ?></small>
        <em><?= $invoice['amount'] !== null ? mx_h(number_format((float) $invoice['amount'], 2, ',', '.')) . ' TL' : 'Tutar belirtilmedi' ?></em>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php mx_account_footer(); ?>
