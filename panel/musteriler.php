<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_user_manager();

$pdo = mx_pdo();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = mx_clean_string($_POST['action'] ?? 'create', 32);

    try {
        if (!mx_table_exists('customers')) {
            throw new RuntimeException('customers tablosu bulunamadı. Önce müşteri portalı migration dosyasını çalıştırın.');
        }

        if ($action === 'create' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $fullName = mx_clean_string($_POST['full_name'] ?? '', 120);
            $email = mx_clean_string($_POST['email'] ?? '', 160);
            $phone = mx_clean_string($_POST['phone'] ?? '', 40);
            $tckn = preg_replace('/\D+/', '', (string) ($_POST['tckn'] ?? ''));
            $isActive = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
            $password = (string) ($_POST['password'] ?? '');

            if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Müşteri adı ve geçerli e-posta zorunludur.');
            }
            if ($tckn !== '' && !mx_valid_tckn($tckn)) {
                throw new RuntimeException('T.C. kimlik numarası geçerli değil.');
            }

            $hasTckn = mx_column_exists('customers', 'tckn');
            if ($action === 'create') {
                if (strlen($password) < 8) {
                    throw new RuntimeException('Yeni müşteri için en az 8 karakterli geçici şifre girin.');
                }
                $columns = 'full_name, email, phone, password_hash, is_active';
                $values = ':full_name, :email, :phone, :password_hash, :is_active';
                $params = [
                    ':full_name' => $fullName,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':is_active' => $isActive,
                ];
                if ($hasTckn) {
                    $columns .= ', tckn';
                    $values .= ', :tckn';
                    $params[':tckn'] = $tckn;
                }
                $stmt = $pdo->prepare("INSERT INTO customers ({$columns}) VALUES ({$values})");
                $stmt->execute($params);
                mx_audit_log(null, 'customer_create', $email . ' müşterisi panelden oluşturuldu.');
                $message = 'Müşteri oluşturuldu.';
            } else {
                $target = $pdo->prepare('SELECT email FROM customers WHERE id = :id');
                $target->execute([':id' => $id]);
                $customer = $target->fetch();
                if (!$customer) {
                    throw new RuntimeException('Müşteri bulunamadı.');
                }

                $setTckn = $hasTckn ? ', tckn = :tckn' : '';
                $passwordSql = '';
                $params = [
                    ':full_name' => $fullName,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':is_active' => $isActive,
                    ':id' => $id,
                ];
                if ($hasTckn) {
                    $params[':tckn'] = $tckn;
                }
                if ($password !== '') {
                    if (strlen($password) < 8) {
                        throw new RuntimeException('Yeni şifre en az 8 karakter olmalı.');
                    }
                    $passwordSql = ', password_hash = :password_hash';
                    $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $stmt = $pdo->prepare(
                    "UPDATE customers
                     SET full_name = :full_name, email = :email, phone = :phone, is_active = :is_active{$setTckn}{$passwordSql}
                     WHERE id = :id"
                );
                $stmt->execute($params);
                mx_audit_log(null, 'customer_update', $customer['email'] . ' müşterisi güncellendi. Yeni e-posta: ' . $email);
                $message = 'Müşteri güncellendi.';
            }
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $target = $pdo->prepare('SELECT email FROM customers WHERE id = :id');
            $target->execute([':id' => $id]);
            $customer = $target->fetch();
            if (!$customer) {
                throw new RuntimeException('Müşteri bulunamadı.');
            }
            $pdo->prepare('DELETE FROM customers WHERE id = :id')->execute([':id' => $id]);
            mx_audit_log(null, 'customer_delete', $customer['email'] . ' müşterisi silindi.');
            $message = 'Müşteri silindi.';
        }
    } catch (Throwable $exception) {
        $error = 'İşlem tamamlanamadı: ' . $exception->getMessage();
        mx_log_error('panel customer operation failed', $exception);
    }
}

$customers = [];
if (mx_table_exists('customers')) {
    $hasTcknColumn = mx_column_exists('customers', 'tckn');
    $selectTckn = $hasTcknColumn ? ', c.tckn' : ", '' AS tckn";
    $groupTckn = $hasTcknColumn ? ', c.tckn' : '';
    $requestJoin = mx_column_exists('courier_requests', 'customer_id')
        ? 'LEFT JOIN courier_requests cr ON cr.customer_id = c.id'
        : '';
    $requestCount = mx_column_exists('courier_requests', 'customer_id')
        ? 'COUNT(DISTINCT cr.id) AS request_count'
        : '0 AS request_count';
    $invoiceJoin = mx_table_exists('customer_invoices')
        ? 'LEFT JOIN customer_invoices ci ON ci.customer_id = c.id'
        : '';
    $invoiceCount = mx_table_exists('customer_invoices')
        ? 'COUNT(DISTINCT ci.id) AS invoice_count'
        : '0 AS invoice_count';
    $stmt = $pdo->query(
        "SELECT c.id, c.full_name, c.email, c.phone, c.is_active, c.last_login_at, c.created_at{$selectTckn},
                {$requestCount},
                {$invoiceCount}
         FROM customers c
         {$requestJoin}
         {$invoiceJoin}
         GROUP BY c.id, c.full_name, c.email, c.phone, c.is_active, c.last_login_at, c.created_at{$groupTckn}
         ORDER BY c.created_at DESC"
    );
    $customers = $stmt->fetchAll();
} else {
    $error = 'customers tablosu yok. Önce migrations/007_customer_portal.sql dosyasını çalıştırın.';
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Müşteriler | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-panel-invoices">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">MyExpress</p>
          <h1>Müşteriler</h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="index.php">Talepler</a>
          <a class="btn btn-secondary" href="talep-ekle.php">Manuel Talep</a>
          <?php if (mx_panel_can_manage_pricing()): ?><a class="btn btn-secondary" href="fiyatlandirma.php">Fiyatlandırma</a><?php endif; ?>
          <a class="btn btn-secondary" href="kullanicilar.php">Kullanıcılar</a>
          <a class="btn btn-secondary" href="logout.php">Çıkış Yap</a>
        </div>
      </section>

      <?php if ($message !== ''): ?><p class="panel-success"><?= mx_h($message) ?></p><?php endif; ?>
      <?php if ($error !== ''): ?><p class="panel-alert"><?= mx_h($error) ?></p><?php endif; ?>

      <form class="panel-card user-create-card customer-create-card" method="post">
        <h2>Manuel Müşteri Ekle</h2>
        <input type="hidden" name="action" value="create">
        <div class="panel-edit-grid customer-form-grid">
          <label>Ad soyad / firma <input name="full_name" required></label>
          <label>E-posta <input type="email" name="email" required></label>
          <label>Telefon <input name="phone" inputmode="tel" placeholder="05..."></label>
          <label>T.C. kimlik no <input name="tckn" inputmode="numeric" maxlength="11"></label>
          <label>Durum
            <select name="is_active">
              <option value="1">Aktif</option>
              <option value="0">Pasif</option>
            </select>
          </label>
          <label>Geçici şifre <input type="password" name="password" minlength="8" required></label>
        </div>
        <button class="btn btn-primary" type="submit">Müşteri Oluştur</button>
      </form>

      <section class="panel-card">
        <div class="panel-card-heading">
          <h2>Müşteri Listesi</h2>
          <span><?= count($customers) ?> kayıt</span>
        </div>
        <div class="panel-table-wrap">
          <table class="panel-table user-table customer-table">
            <thead><tr><th>Müşteri</th><th>İletişim</th><th>TCKN</th><th>Durum</th><th>Kayıtlar</th><th>Son giriş</th><th>İşlem</th></tr></thead>
            <tbody>
              <?php foreach ($customers as $customer): ?>
                <tr>
                  <td><input form="customer-update-<?= (int) $customer['id'] ?>" name="full_name" value="<?= mx_h($customer['full_name']) ?>" required aria-label="Müşteri adı"></td>
                  <td>
                    <input form="customer-update-<?= (int) $customer['id'] ?>" type="email" name="email" value="<?= mx_h($customer['email']) ?>" required aria-label="E-posta">
                    <input form="customer-update-<?= (int) $customer['id'] ?>" name="phone" value="<?= mx_h($customer['phone']) ?>" aria-label="Telefon">
                  </td>
                  <td><input form="customer-update-<?= (int) $customer['id'] ?>" name="tckn" value="<?= mx_h($customer['tckn']) ?>" maxlength="11" inputmode="numeric" aria-label="TCKN"></td>
                  <td>
                    <select form="customer-update-<?= (int) $customer['id'] ?>" name="is_active" aria-label="Durum">
                      <option value="1" <?= (int) $customer['is_active'] === 1 ? 'selected' : '' ?>>Aktif</option>
                      <option value="0" <?= (int) $customer['is_active'] === 0 ? 'selected' : '' ?>>Pasif</option>
                    </select>
                  </td>
                  <td><strong><?= (int) $customer['request_count'] ?></strong> talep<br><small><?= (int) $customer['invoice_count'] ?> fatura</small></td>
                  <td><span class="nowrap"><?= $customer['last_login_at'] ? mx_h($customer['last_login_at']) : '-' ?></span></td>
                  <td>
                    <div class="user-actions">
                      <form id="customer-update-<?= (int) $customer['id'] ?>" method="post">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
                        <input type="hidden" name="password" value="">
                        <button class="panel-icon-btn save" type="submit" aria-label="Müşteriyi kaydet" title="Müşteriyi kaydet"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 3h12l2 2v16H5V3Zm2 2v14h10V7.8L14.2 5H7Zm2 1h5v5H9V6Zm0 8h6v2H9v-2Z"/></svg></button>
                      </form>
                      <a class="panel-icon-btn" href="musteri-hareketleri.php?id=<?= (int) $customer['id'] ?>" aria-label="Müşteri talepleri" title="Müşteri talepleri"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M4 5h16v4H4V5Zm0 6h16v8H4v-8Zm2 2v4h12v-4H6Z"/></svg></a>
                      <a class="panel-icon-btn" href="musteri-adresleri.php?id=<?= (int) $customer['id'] ?>" aria-label="Müşteri adresleri" title="Müşteri adresleri"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5Z"/></svg></a>
                      <a class="panel-icon-btn" href="musteri-faturalari.php?id=<?= (int) $customer['id'] ?>" aria-label="Müşteri faturaları" title="Müşteri faturaları"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 2h9l3 3v17H6V2Zm2 2v16h8V7h-3V4H8Zm1 6h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg></a>
                      <form method="post" onsubmit="return confirm('Bu müşteri silinsin mi? Kayıtlı adres ve faturaları da silinir.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
                        <button class="panel-icon-btn danger" type="submit" aria-label="Müşteriyi sil" title="Müşteriyi sil"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-.7 11H7.7L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z"/></svg></button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$customers): ?><tr><td colspan="7">Henüz müşteri kaydı yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
