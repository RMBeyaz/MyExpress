<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';

$error = '';
$config = [];
$panelError = '';
$notice = '';

try {
    $config = mx_config();
} catch (Throwable $exception) {
    $error = 'Config dosyasi okunamadi.';
    mx_log_error('panel config failed', $exception);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && mx_panel_is_logged_in() && ($_POST['action'] ?? '') === 'delete') {
    $deleteId = (int) ($_POST['id'] ?? 0);
    $deleteReason = mx_clean_text($_POST['delete_reason'] ?? '', 600);

    try {
        if ($deleteId <= 0 || $deleteReason === '') {
            throw new RuntimeException('Silme aciklamasi zorunludur.');
        }

        $stmt = mx_pdo()->prepare('SELECT tracking_code FROM courier_requests WHERE id = :id');
        $stmt->execute([':id' => $deleteId]);
        $trackingCode = (string) ($stmt->fetchColumn() ?: '');
        if ($trackingCode === '') {
            throw new RuntimeException('Talep bulunamadi.');
        }

        mx_audit_log($deleteId, 'request_delete', 'Talep silindi. Talep: ' . $trackingCode . ' Aciklama: ' . $deleteReason);
        mx_pdo()->prepare('DELETE FROM courier_requests WHERE id = :id')->execute([':id' => $deleteId]);
        header('Location: index.php?notice=deleted');
        exit;
    } catch (Throwable $exception) {
        $panelError = 'Talep silinemedi. Açıklama girildiğinden emin olun.';
        mx_log_error('request delete failed', $exception, ['id' => $deleteId]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !mx_panel_is_logged_in()) {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (mx_panel_login($user, $pass)) {
        header('Location: index.php');
        exit;
    }

    $error = 'Kullanici adi veya sifre hatali.';
}

$isReady = !empty($config['panel_user']) && (!empty($config['panel_pass_hash']) || !empty($config['panel_pass']));
$notice = mx_clean_string($_GET['notice'] ?? '', 40);
$requests = [];
$statuses = mx_statuses();
$filters = [
    'status' => mx_clean_string($_GET['status'] ?? '', 32),
    'date_from' => mx_clean_string($_GET['date_from'] ?? '', 10),
    'date_to' => mx_clean_string($_GET['date_to'] ?? '', 10),
    'tracking' => mx_clean_string($_GET['tracking'] ?? '', 40),
    'sender' => mx_clean_string($_GET['sender'] ?? '', 80),
    'recipient' => mx_clean_string($_GET['recipient'] ?? '', 80),
    'phone' => mx_clean_string($_GET['phone'] ?? '', 40),
    'address' => mx_clean_string($_GET['address'] ?? '', 100),
];
$sortKey = $_GET['sort'] ?? 'date';
$dirParam = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

$sortUrl = static function (string $key) use ($sortKey, $dirParam): string {
    $query = $_GET;
    $query['sort'] = $key;
    $query['dir'] = ($sortKey === $key && $dirParam === 'asc') ? 'desc' : 'asc';
    return '?' . http_build_query($query);
};

$sortMark = static function (string $key) use ($sortKey, $dirParam): string {
    if ($sortKey !== $key) {
        return '';
    }
    return $dirParam === 'asc' ? ' ↑' : ' ↓';
};

if (mx_panel_is_logged_in()) {
    try {
        $pdo = mx_pdo();
        $hasDistance = mx_column_exists('courier_requests', 'distance_km');
        $sortMap = [
            'date' => 'created_at',
            'status' => 'status',
            'price' => "CAST(REPLACE(REPLACE(price, '.', ''), ' TL', '') AS UNSIGNED)",
            'distance' => $hasDistance ? 'distance_km' : 'created_at',
            'tracking' => 'id',
            'sender' => 'sender_name',
            'recipient' => 'recipient_name',
        ];
        $sort = $sortMap[$sortKey] ?? 'created_at';
        $dir = $dirParam === 'asc' ? 'ASC' : 'DESC';
        $where = [];
        $params = [];

        if ($filters['status'] !== '' && isset($statuses[$filters['status']])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if ($filters['tracking'] !== '') {
            $where[] = 'tracking_code LIKE :tracking';
            $params[':tracking'] = '%' . $filters['tracking'] . '%';
        }
        if ($filters['sender'] !== '') {
            $where[] = 'sender_name LIKE :sender';
            $params[':sender'] = '%' . $filters['sender'] . '%';
        }
        if ($filters['recipient'] !== '') {
            $where[] = 'recipient_name LIKE :recipient';
            $params[':recipient'] = '%' . $filters['recipient'] . '%';
        }
        if ($filters['phone'] !== '') {
            $where[] = '(sender_phone LIKE :phone OR recipient_phone LIKE :phone)';
            $params[':phone'] = '%' . $filters['phone'] . '%';
        }
        if ($filters['address'] !== '') {
            $where[] = '(pickup LIKE :address OR dropoff LIKE :address OR pickup_street LIKE :address OR dropoff_street LIKE :address)';
            $params[':address'] = '%' . $filters['address'] . '%';
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $distanceSelect = $hasDistance ? 'distance_km,' : 'NULL AS distance_km,';
        $sql = "SELECT id, tracking_code, status, pickup, dropoff, price, {$distanceSelect}
                   sender_name, sender_phone, recipient_name, recipient_phone, created_at
                FROM courier_requests{$whereSql}
                ORDER BY {$sort} {$dir}
                LIMIT 120";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll();
    } catch (Throwable $exception) {
        $panelError = 'Talepler su an listelenemiyor. Detay icin server error_log kontrol edilmeli.';
        mx_log_error('panel request list failed', $exception);
    }
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260519-panel-stable">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">MyExpress</p>
          <h1>Talep Paneli</h1>
        </div>
        <?php if (mx_panel_is_logged_in()): ?>
          <div class="panel-header-actions">
            <?php if (mx_panel_can_manage_pricing()): ?>
              <a class="btn btn-secondary" href="fiyatlandirma.php">Fiyatlandırma</a>
            <?php endif; ?>
            <?php if (mx_panel_can_manage_users()): ?>
              <a class="btn btn-secondary" href="kullanicilar.php">Kullanıcılar</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="logout.php">Çıkış Yap</a>
          </div>
        <?php endif; ?>
      </section>

      <?php if (!mx_panel_is_logged_in()): ?>
        <form class="panel-login" method="post">
          <h2>Panel Girişi</h2>
          <?php if (!$isReady): ?>
            <p class="panel-alert">Config dosyasına <code>panel_user</code> ve <code>panel_pass</code> eklenmeden giriş açılamaz.</p>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <p class="panel-alert"><?= mx_h($error) ?></p>
          <?php endif; ?>
          <label>Kullanıcı adı <input name="username" autocomplete="username" required></label>
          <label>Şifre <input type="password" name="password" autocomplete="current-password" required></label>
          <button class="btn btn-primary btn-full" type="submit">Giriş Yap</button>
        </form>
      <?php else: ?>
        <section class="panel-card">
          <div class="panel-card-heading">
            <h2>Son Talepler</h2>
            <span><?= count($requests) ?> kayıt</span>
          </div>
          <?php if ($notice === 'deleted'): ?>
            <div class="panel-toast is-visible">Talep silindi.</div>
          <?php endif; ?>
          <form class="panel-filters" method="get">
            <label>Durum
              <select name="status">
                <option value="">Tümü</option>
                <?php foreach ($statuses as $key => $label): ?>
                  <option value="<?= mx_h($key) ?>" <?= ($_GET['status'] ?? '') === $key ? 'selected' : '' ?>><?= mx_h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Başlangıç <input type="date" name="date_from" value="<?= mx_h($filters['date_from']) ?>"></label>
            <label>Bitiş <input type="date" name="date_to" value="<?= mx_h($filters['date_to']) ?>"></label>
            <label>Talep no <input name="tracking" value="<?= mx_h($filters['tracking']) ?>" placeholder="MX..."></label>
            <label>Gönderici <input name="sender" value="<?= mx_h($filters['sender']) ?>" placeholder="Ad soyad"></label>
            <label>Alıcı <input name="recipient" value="<?= mx_h($filters['recipient']) ?>" placeholder="Ad soyad"></label>
            <label>Telefon <input name="phone" value="<?= mx_h($filters['phone']) ?>" placeholder="05..."></label>
            <label>Adres <input name="address" value="<?= mx_h($filters['address']) ?>" placeholder="Mahalle, sokak"></label>
            <button class="btn btn-primary" type="submit">Filtrele</button>
            <a class="btn btn-secondary" href="index.php">Temizle</a>
          </form>
          <?php if ($panelError !== ''): ?>
            <p class="panel-alert"><?= mx_h($panelError) ?></p>
          <?php endif; ?>
          <div class="panel-table-wrap">
            <table class="panel-table">
              <thead>
                <tr>
                  <th><a class="sort-link" href="<?= mx_h($sortUrl('tracking')) ?>">Talep<?= mx_h($sortMark('tracking')) ?></a></th>
                  <th><a class="sort-link" href="<?= mx_h($sortUrl('status')) ?>">Durum<?= mx_h($sortMark('status')) ?></a></th>
                  <th><a class="sort-link" href="<?= mx_h($sortUrl('sender')) ?>">Gönderici<?= mx_h($sortMark('sender')) ?></a></th>
                  <th><a class="sort-link" href="<?= mx_h($sortUrl('recipient')) ?>">Alıcı<?= mx_h($sortMark('recipient')) ?></a></th>
                  <th>Adres</th>
                  <th><a class="sort-link" href="<?= mx_h($sortUrl('distance')) ?>">Mesafe<?= mx_h($sortMark('distance')) ?></a></th>
                  <th><a class="sort-link" href="<?= mx_h($sortUrl('price')) ?>">Ücret<?= mx_h($sortMark('price')) ?></a></th>
                  <th><a class="sort-link" href="<?= mx_h($sortUrl('date')) ?>">Tarih<?= mx_h($sortMark('date')) ?></a></th>
                  <th>İşlem</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($requests as $request): ?>
                  <tr>
                    <td><a class="tracking-link" href="talep.php?id=<?= (int) $request['id'] ?>"><?= mx_h($request['tracking_code']) ?></a></td>
                    <td><span class="panel-status panel-status-<?= mx_h($request['status']) ?>"><?= mx_h(mx_status_label($request['status'])) ?></span></td>
                    <td><span class="person-name"><?= mx_h($request['sender_name']) ?></span> <a class="wa-icon" href="<?= mx_h(mx_whatsapp_url($request['sender_phone'])) ?>" target="_blank" rel="noopener" aria-label="Gönderici WhatsApp"><svg width="14" height="14" viewBox="0 0 32 32" aria-hidden="true"><path d="M16.04 3.2A12.6 12.6 0 0 0 5.3 22.4L4 29l6.8-1.8A12.58 12.58 0 1 0 16.04 3.2Zm0 22.9c-2.1 0-4.05-.62-5.7-1.7l-.4-.25-4 .98.98-3.9-.26-.42a10.05 10.05 0 1 1 9.38 5.29Zm5.8-7.52c-.32-.16-1.88-.93-2.17-1.03-.29-.11-.5-.16-.71.16-.21.32-.82 1.03-1 1.24-.19.21-.37.24-.69.08-.32-.16-1.35-.5-2.57-1.59-.95-.85-1.59-1.9-1.78-2.22-.19-.32-.02-.49.14-.65.15-.15.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.71-1.72-.98-2.35-.26-.62-.52-.53-.71-.54h-.61c-.21 0-.56.08-.85.4-.29.32-1.11 1.09-1.11 2.65 0 1.56 1.14 3.07 1.3 3.28.16.21 2.24 3.42 5.43 4.8.76.33 1.35.52 1.81.67.76.24 1.45.21 2 .13.61-.09 1.88-.77 2.15-1.51.27-.74.27-1.38.19-1.51-.08-.13-.29-.21-.61-.37Z"/></svg></a><br><a href="tel:<?= mx_h($request['sender_phone']) ?>"><small><?= mx_h($request['sender_phone']) ?></small></a></td>
                    <td><span class="person-name"><?= mx_h($request['recipient_name']) ?></span> <a class="wa-icon" href="<?= mx_h(mx_whatsapp_url($request['recipient_phone'])) ?>" target="_blank" rel="noopener" aria-label="Alıcı WhatsApp"><svg width="14" height="14" viewBox="0 0 32 32" aria-hidden="true"><path d="M16.04 3.2A12.6 12.6 0 0 0 5.3 22.4L4 29l6.8-1.8A12.58 12.58 0 1 0 16.04 3.2Zm0 22.9c-2.1 0-4.05-.62-5.7-1.7l-.4-.25-4 .98.98-3.9-.26-.42a10.05 10.05 0 1 1 9.38 5.29Zm5.8-7.52c-.32-.16-1.88-.93-2.17-1.03-.29-.11-.5-.16-.71.16-.21.32-.82 1.03-1 1.24-.19.21-.37.24-.69.08-.32-.16-1.35-.5-2.57-1.59-.95-.85-1.59-1.9-1.78-2.22-.19-.32-.02-.49.14-.65.15-.15.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.71-1.72-.98-2.35-.26-.62-.52-.53-.71-.54h-.61c-.21 0-.56.08-.85.4-.29.32-1.11 1.09-1.11 2.65 0 1.56 1.14 3.07 1.3 3.28.16.21 2.24 3.42 5.43 4.8.76.33 1.35.52 1.81.67.76.24 1.45.21 2 .13.61-.09 1.88-.77 2.15-1.51.27-.74.27-1.38.19-1.51-.08-.13-.29-.21-.61-.37Z"/></svg></a><br><a href="tel:<?= mx_h($request['recipient_phone']) ?>"><small><?= mx_h($request['recipient_phone']) ?></small></a></td>
                    <td><span class="route-line"><span class="route-label">Alım</span> <?= mx_h($request['pickup']) ?></span><span class="route-line"><span class="route-label">Teslim</span> <?= mx_h($request['dropoff']) ?></span></td>
                    <td><?= $request['distance_km'] !== null ? mx_h(number_format((float) $request['distance_km'], 1, ',', '.')) . ' km' : '-' ?></td>
                    <td><?= mx_h($request['price']) ?></td>
                    <td><strong><?= mx_h(date('H:i', strtotime($request['created_at']))) ?></strong><br><small><?= mx_h(date('d.m.Y', strtotime($request['created_at']))) ?></small></td>
                    <td><button class="panel-icon-btn danger" type="button" data-delete-open data-id="<?= (int) $request['id'] ?>" data-code="<?= mx_h($request['tracking_code']) ?>">Sil</button></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?>
                  <tr><td colspan="9">Henüz talep yok.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
        <div class="panel-modal" data-delete-modal hidden>
          <form class="panel-modal-card" method="post">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" data-delete-id>
            <h2>Talebi Sil</h2>
            <p><strong data-delete-code></strong> numaralı talep kalıcı olarak silinecek.</p>
            <label>Silme açıklaması
              <textarea name="delete_reason" required placeholder="Neden silindi?"></textarea>
            </label>
            <div class="panel-modal-actions">
              <button class="btn btn-secondary" type="button" data-delete-close>Vazgeç</button>
              <button class="btn btn-primary" type="submit">Evet, Sil</button>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </main>
    <script>
      const deleteModal = document.querySelector('[data-delete-modal]');
      const deleteId = document.querySelector('[data-delete-id]');
      const deleteCode = document.querySelector('[data-delete-code]');
      document.querySelectorAll('[data-delete-open]').forEach((button) => {
        button.addEventListener('click', () => {
          if (deleteId) deleteId.value = button.dataset.id || '';
          if (deleteCode) deleteCode.textContent = button.dataset.code || '';
          deleteModal.hidden = false;
        });
      });
      document.querySelector('[data-delete-close]')?.addEventListener('click', () => {
        deleteModal.hidden = true;
      });
      deleteModal?.addEventListener('click', (event) => {
        if (event.target === deleteModal) deleteModal.hidden = true;
      });
    </script>
  </body>
</html>
