<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_user_manager();

$pdo = mx_pdo();
$id = (int) ($_GET['id'] ?? ($_POST['customer_id'] ?? 0));
$message = '';
$error = '';

if (!mx_table_exists('customers')) {
    http_response_code(404);
    echo 'Müşteri tablosu bulunamadı.';
    exit;
}

$customerStmt = $pdo->prepare('SELECT id, full_name, email, phone FROM customers WHERE id = :id');
$customerStmt->execute([':id' => $id]);
$customer = $customerStmt->fetch();
if (!$customer) {
    http_response_code(404);
    echo 'Müşteri bulunamadı.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = mx_clean_string($_POST['action'] ?? 'create_invoice', 32);

    try {
        if (!mx_table_exists('customer_invoices')) {
            throw new RuntimeException('customer_invoices tablosu bulunamadı.');
        }

        if ($action === 'create_invoice') {
            $title = mx_clean_string($_POST['title'] ?? '', 160);
            $invoiceNo = mx_clean_string($_POST['invoice_no'] ?? '', 80);
            $filePath = mx_clean_string($_POST['file_path'] ?? '', 255);
            $invoiceDate = mx_clean_string($_POST['invoice_date'] ?? '', 20);
            $amountRaw = str_replace(',', '.', (string) ($_POST['amount'] ?? ''));
            $amount = is_numeric($amountRaw) ? (float) $amountRaw : null;

            if ($title === '' || $filePath === '') {
                throw new RuntimeException('Fatura başlığı ve dosya yolu/link zorunludur.');
            }
            if ($invoiceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
                throw new RuntimeException('Fatura tarihi geçerli değil.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO customer_invoices (customer_id, invoice_no, title, amount, invoice_date, file_path, status, uploaded_by)
                 VALUES (:customer_id, :invoice_no, :title, :amount, :invoice_date, :file_path, :status, :uploaded_by)'
            );
            $stmt->execute([
                ':customer_id' => (int) $customer['id'],
                ':invoice_no' => $invoiceNo,
                ':title' => $title,
                ':amount' => $amount,
                ':invoice_date' => $invoiceDate !== '' ? $invoiceDate : null,
                ':file_path' => $filePath,
                ':status' => mx_clean_string($_POST['status'] ?? 'available', 32),
                ':uploaded_by' => mx_panel_user(),
            ]);
            mx_audit_log(null, 'customer_invoice_create', $customer['email'] . ' müşterisine fatura tanımlandı: ' . $title);
            $message = 'Fatura tanımlandı.';
        }

        if ($action === 'delete_invoice') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $target = $pdo->prepare('SELECT title FROM customer_invoices WHERE id = :id AND customer_id = :customer_id');
            $target->execute([':id' => $invoiceId, ':customer_id' => (int) $customer['id']]);
            $invoice = $target->fetch();
            if (!$invoice) {
                throw new RuntimeException('Fatura bulunamadı.');
            }
            $pdo->prepare('DELETE FROM customer_invoices WHERE id = :id AND customer_id = :customer_id')->execute([
                ':id' => $invoiceId,
                ':customer_id' => (int) $customer['id'],
            ]);
            mx_audit_log(null, 'customer_invoice_delete', $customer['email'] . ' müşterisinin faturası silindi: ' . $invoice['title']);
            $message = 'Fatura silindi.';
        }
    } catch (Throwable $exception) {
        $error = 'İşlem tamamlanamadı: ' . $exception->getMessage();
        mx_log_error('panel customer invoice operation failed', $exception);
    }
}

$invoices = [];
if (mx_table_exists('customer_invoices')) {
    $invoiceStmt = $pdo->prepare(
        'SELECT * FROM customer_invoices WHERE customer_id = :customer_id ORDER BY COALESCE(invoice_date, created_at) DESC'
    );
    $invoiceStmt->execute([':customer_id' => (int) $customer['id']]);
    $invoices = $invoiceStmt->fetchAll();
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mx_h($customer['full_name']) ?> | Faturalar</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-panel-customers">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">Müşteri faturaları</p>
          <h1><?= mx_h($customer['full_name']) ?></h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="musteriler.php">Müşterilere Dön</a>
          <a class="btn btn-secondary" href="musteri-hareketleri.php?id=<?= (int) $customer['id'] ?>">Talep Geçmişi</a>
          <a class="btn btn-secondary" href="index.php">Talepler</a>
        </div>
      </section>

      <?php if ($message !== ''): ?><p class="panel-success"><?= mx_h($message) ?></p><?php endif; ?>
      <?php if ($error !== ''): ?><p class="panel-alert"><?= mx_h($error) ?></p><?php endif; ?>

      <section class="panel-detail-grid">
        <form class="panel-card user-create-card" method="post">
          <h2>Fatura Tanımla</h2>
          <input type="hidden" name="action" value="create_invoice">
          <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
          <div class="panel-edit-grid invoice-form-grid">
            <label>Başlık <input name="title" placeholder="Mayıs 2026 kurye faturası" required></label>
            <label>Fatura no <input name="invoice_no" placeholder="Opsiyonel"></label>
            <label>Tutar <input name="amount" inputmode="decimal" placeholder="0,00"></label>
            <label>Fatura tarihi <input type="date" name="invoice_date"></label>
            <label>Durum
              <select name="status">
                <option value="available">Yayında</option>
                <option value="draft">Taslak</option>
              </select>
            </label>
            <label>Dosya yolu / link <input name="file_path" placeholder="/kurye/assets/faturalar/..." required></label>
          </div>
          <button class="btn btn-primary" type="submit">Fatura Kaydet</button>
        </form>
        <article class="panel-card">
          <h2>Müşteri</h2>
          <dl class="panel-detail-list">
            <dt>E-posta</dt><dd><a href="mailto:<?= mx_h($customer['email']) ?>"><?= mx_h($customer['email']) ?></a></dd>
            <dt>Telefon</dt><dd><?= $customer['phone'] ? '<a href="tel:' . mx_h($customer['phone']) . '">' . mx_h($customer['phone']) . '</a>' : '-' ?></dd>
            <dt>Fatura</dt><dd><?= count($invoices) ?> kayıt</dd>
          </dl>
        </article>
      </section>

      <section class="panel-card">
        <div class="panel-card-heading">
          <h2>Tanımlı Faturalar</h2>
          <span><?= count($invoices) ?> kayıt</span>
        </div>
        <div class="panel-table-wrap">
          <table class="panel-table audit-table">
            <thead><tr><th>Fatura</th><th>No</th><th>Tutar</th><th>Tarih</th><th>Dosya</th><th>İşlem</th></tr></thead>
            <tbody>
              <?php foreach ($invoices as $invoice): ?>
                <tr>
                  <td><?= mx_h($invoice['title']) ?><br><small><?= mx_h($invoice['status']) ?></small></td>
                  <td><?= mx_h($invoice['invoice_no'] ?: '-') ?></td>
                  <td><?= $invoice['amount'] !== null ? mx_h(number_format((float) $invoice['amount'], 2, ',', '.')) . ' TL' : '-' ?></td>
                  <td><?= $invoice['invoice_date'] ? mx_h(date('d.m.Y', strtotime($invoice['invoice_date']))) : '-' ?></td>
                  <td><a href="<?= mx_h($invoice['file_path']) ?>" target="_blank" rel="noopener">Aç</a></td>
                  <td>
                    <form method="post" onsubmit="return confirm('Bu fatura tanımı silinsin mi?');">
                      <input type="hidden" name="action" value="delete_invoice">
                      <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                      <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                      <button class="panel-icon-btn danger" type="submit" aria-label="Faturayı sil"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-.7 11H7.7L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z"/></svg></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$invoices): ?><tr><td colspan="6">Bu müşteriye tanımlı fatura yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
