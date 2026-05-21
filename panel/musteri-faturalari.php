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
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $amountRaw = str_replace(',', '.', (string) ($_POST['amount'] ?? ''));
            $amount = is_numeric($amountRaw) ? (float) $amountRaw : null;

            if ($title === '') {
                throw new RuntimeException('Fatura başlığı zorunludur.');
            }
            if ($invoiceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
                throw new RuntimeException('Fatura tarihi geçerli değil.');
            }
            if ($requestId > 0) {
                $requestCheck = $pdo->prepare('SELECT id FROM courier_requests WHERE id = :id AND customer_id = :customer_id');
                $requestCheck->execute([':id' => $requestId, ':customer_id' => (int) $customer['id']]);
                if (!$requestCheck->fetchColumn()) {
                    throw new RuntimeException('Seçilen talep bu müşteriye ait değil.');
                }
            }

            if (!empty($_FILES['invoice_pdf']['name'] ?? '')) {
                $file = $_FILES['invoice_pdf'];
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('PDF yüklenemedi. Lütfen dosyayı kontrol edin.');
                }
                if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
                    throw new RuntimeException('PDF dosyası en fazla 8 MB olmalı.');
                }
                $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                $header = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 4);
                if ($extension !== 'pdf' || $header !== '%PDF') {
                    throw new RuntimeException('Sadece PDF fatura dosyası yüklenebilir.');
                }
                $uploadDir = dirname(__DIR__) . '/uploads/faturalar';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('Fatura yükleme klasörü oluşturulamadı.');
                }
                $safeName = 'fatura-' . (int) $customer['id'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.pdf';
                $targetPath = $uploadDir . '/' . $safeName;
                if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
                    throw new RuntimeException('PDF dosyası kaydedilemedi.');
                }
                $filePath = '/kurye/uploads/faturalar/' . $safeName;
            }

            if ($filePath === '') {
                throw new RuntimeException('PDF yükleyin veya dosya yolu/link girin.');
            }

            $hasRequestColumn = mx_column_exists('customer_invoices', 'request_id');
            $requestColumnSql = $hasRequestColumn ? 'request_id, ' : '';
            $requestValueSql = $hasRequestColumn ? ':request_id, ' : '';
            $requestParams = $hasRequestColumn ? [':request_id' => $requestId > 0 ? $requestId : null] : [];

            $stmt = $pdo->prepare(
                'INSERT INTO customer_invoices (customer_id, ' . $requestColumnSql . 'invoice_no, title, amount, invoice_date, file_path, status, uploaded_by)
                 VALUES (:customer_id, ' . $requestValueSql . ':invoice_no, :title, :amount, :invoice_date, :file_path, :status, :uploaded_by)'
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
            ] + $requestParams);
            mx_audit_log($requestId > 0 ? $requestId : null, 'customer_invoice_create', $customer['email'] . ' müşterisine fatura tanımlandı: ' . $title);
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
$requests = [];
if (mx_table_exists('customer_invoices')) {
    $hasRequestColumn = mx_column_exists('customer_invoices', 'request_id');
    $requestSelect = $hasRequestColumn ? ', ci.request_id, cr.tracking_code' : ', NULL AS request_id, NULL AS tracking_code';
    $requestJoin = $hasRequestColumn ? ' LEFT JOIN courier_requests cr ON cr.id = ci.request_id' : '';
    $invoiceStmt = $pdo->prepare(
        'SELECT ci.*' . $requestSelect . '
         FROM customer_invoices ci' . $requestJoin . '
         WHERE ci.customer_id = :customer_id
         ORDER BY COALESCE(ci.invoice_date, ci.created_at) DESC'
    );
    $invoiceStmt->execute([':customer_id' => (int) $customer['id']]);
    $invoices = $invoiceStmt->fetchAll();
}
if (mx_column_exists('courier_requests', 'customer_id')) {
    $requestStmt = $pdo->prepare('SELECT id, tracking_code, pickup, dropoff, price, created_at FROM courier_requests WHERE customer_id = :customer_id ORDER BY created_at DESC LIMIT 200');
    $requestStmt->execute([':customer_id' => (int) $customer['id']]);
    $requests = $requestStmt->fetchAll();
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mx_h($customer['full_name']) ?> | Faturalar</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-panel-invoices">
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
        <form class="panel-card user-create-card" method="post" enctype="multipart/form-data">
          <h2>PDF Fatura Yükle</h2>
          <input type="hidden" name="action" value="create_invoice">
          <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
          <div class="panel-edit-grid invoice-form-grid">
            <label>Başlık <input name="title" placeholder="Mayıs 2026 kurye faturası" required></label>
            <label>Fatura no <input name="invoice_no" placeholder="Opsiyonel"></label>
            <label>Tutar <input name="amount" inputmode="decimal" placeholder="0,00"></label>
            <label>Fatura tarihi <input type="date" name="invoice_date"></label>
            <label>İlgili talep
              <select name="request_id">
                <option value="0">Genel müşteri faturası</option>
                <?php foreach ($requests as $request): ?>
                  <option value="<?= (int) $request['id'] ?>"><?= mx_h($request['tracking_code'] . ' - ' . date('d.m.Y', strtotime($request['created_at'])) . ' - ' . $request['price']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Durum
              <select name="status">
                <option value="available">Yayında</option>
                <option value="draft">Taslak</option>
              </select>
            </label>
            <label>Fatura PDF <input type="file" name="invoice_pdf" accept="application/pdf"></label>
            <label>Dosya yolu / link <input name="file_path" placeholder="PDF yüklenmezse link girin"></label>
          </div>
          <button class="btn btn-primary" type="submit">Fatura Kaydet</button>
          <p class="panel-help-text">PDF yükleyebilir veya harici/daha önce yüklenmiş dosya linki tanımlayabilirsiniz. Talep seçilirse fatura müşteriyle birlikte ilgili talebe de bağlanır.</p>
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
            <thead><tr><th>Fatura</th><th>No</th><th>Talep</th><th>Tutar</th><th>Tarih</th><th>Dosya</th><th>İşlem</th></tr></thead>
            <tbody>
              <?php foreach ($invoices as $invoice): ?>
                <tr>
                  <td><?= mx_h($invoice['title']) ?><br><small><?= mx_h($invoice['status']) ?></small></td>
                  <td><?= mx_h($invoice['invoice_no'] ?: '-') ?></td>
                  <td>
                    <?php if (!empty($invoice['request_id'])): ?>
                      <a class="tracking-link" href="talep.php?id=<?= (int) $invoice['request_id'] ?>"><?= mx_h($invoice['tracking_code'] ?: '#' . $invoice['request_id']) ?></a>
                    <?php else: ?>
                      Genel
                    <?php endif; ?>
                  </td>
                  <td><?= $invoice['amount'] !== null ? mx_h(number_format((float) $invoice['amount'], 2, ',', '.')) . ' TL' : '-' ?></td>
                  <td><?= $invoice['invoice_date'] ? mx_h(date('d.m.Y', strtotime($invoice['invoice_date']))) : '-' ?></td>
                  <td><a href="<?= mx_h($invoice['file_path']) ?>" target="_blank" rel="noopener">Aç</a></td>
                  <td>
                    <form method="post" onsubmit="return confirm('Bu fatura tanımı silinsin mi?');">
                      <input type="hidden" name="action" value="delete_invoice">
                      <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                      <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                      <button class="panel-icon-btn danger" type="submit" aria-label="Faturayı sil" title="Faturayı sil"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-.7 11H7.7L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z"/></svg></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$invoices): ?><tr><td colspan="7">Bu müşteriye tanımlı fatura yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
