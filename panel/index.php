<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';

$error = '';
$config = [];
$panelError = '';

try {
    $config = mx_config();
} catch (Throwable $exception) {
    $error = 'Config dosyasi okunamadi.';
    mx_log_error('panel config failed', $exception);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $panelUser = (string) ($config['panel_user'] ?? '');
    $panelHash = (string) ($config['panel_pass_hash'] ?? '');
    $panelPass = (string) ($config['panel_pass'] ?? '');
    $passwordOk = $panelHash !== ''
        ? password_verify($pass, $panelHash)
        : ($panelPass !== '' && hash_equals($panelPass, $pass));

    if ($panelUser !== '' && hash_equals($panelUser, $user) && $passwordOk) {
        session_regenerate_id(true);
        $_SESSION['mx_panel_auth'] = true;
        $_SESSION['mx_panel_user'] = $panelUser;
        $_SESSION['mx_panel_last_activity'] = time();
        mx_audit_log(null, 'login', 'Panel girisi yapildi.');
        header('Location: index.php');
        exit;
    }

    $error = 'Kullanici adi veya sifre hatali.';
}

$isReady = !empty($config['panel_user']) && (!empty($config['panel_pass_hash']) || !empty($config['panel_pass']));
$requests = [];
$statuses = mx_statuses();

if (mx_panel_is_logged_in()) {
    try {
        $pdo = mx_pdo();
        $hasDistance = mx_column_exists('courier_requests', 'distance_km');
        $filters = [
            'status' => mx_clean_string($_GET['status'] ?? '', 32),
            'date_from' => mx_clean_string($_GET['date_from'] ?? '', 10),
            'date_to' => mx_clean_string($_GET['date_to'] ?? '', 10),
            'q' => mx_clean_string($_GET['q'] ?? '', 80),
        ];
        $sortMap = [
            'date' => 'created_at',
            'status' => 'status',
            'price' => 'price',
            'sender' => 'sender_name',
            'recipient' => 'recipient_name',
        ];
        $sortKey = $_GET['sort'] ?? 'date';
        $sort = $sortMap[$sortKey] ?? 'created_at';
        $dir = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
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
        if ($filters['q'] !== '') {
            $where[] = '(tracking_code LIKE :q OR pickup LIKE :q OR dropoff LIKE :q OR sender_name LIKE :q OR sender_phone LIKE :q OR recipient_name LIKE :q OR recipient_phone LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
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
    <link rel="stylesheet" href="../styles.css">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">MyExpress</p>
          <h1>Talep Paneli</h1>
        </div>
        <?php if (mx_panel_is_logged_in()): ?>
          <a class="btn btn-secondary" href="logout.php">Çıkış Yap</a>
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
          <form class="panel-filters" method="get">
            <label>Durum
              <select name="status">
                <option value="">Tümü</option>
                <?php foreach ($statuses as $key => $label): ?>
                  <option value="<?= mx_h($key) ?>" <?= ($_GET['status'] ?? '') === $key ? 'selected' : '' ?>><?= mx_h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Başlangıç <input type="date" name="date_from" value="<?= mx_h($_GET['date_from'] ?? '') ?>"></label>
            <label>Bitiş <input type="date" name="date_to" value="<?= mx_h($_GET['date_to'] ?? '') ?>"></label>
            <label>Arama <input name="q" value="<?= mx_h($_GET['q'] ?? '') ?>" placeholder="Talep no, ad, telefon, adres"></label>
            <label>Sıralama
              <select name="sort">
                <option value="date" <?= ($_GET['sort'] ?? 'date') === 'date' ? 'selected' : '' ?>>Tarih</option>
                <option value="status" <?= ($_GET['sort'] ?? '') === 'status' ? 'selected' : '' ?>>Durum</option>
                <option value="sender" <?= ($_GET['sort'] ?? '') === 'sender' ? 'selected' : '' ?>>Gönderici</option>
                <option value="recipient" <?= ($_GET['sort'] ?? '') === 'recipient' ? 'selected' : '' ?>>Alıcı</option>
                <option value="price" <?= ($_GET['sort'] ?? '') === 'price' ? 'selected' : '' ?>>Ücret</option>
              </select>
            </label>
            <label>Yön
              <select name="dir">
                <option value="desc" <?= ($_GET['dir'] ?? 'desc') === 'desc' ? 'selected' : '' ?>>Azalan</option>
                <option value="asc" <?= ($_GET['dir'] ?? '') === 'asc' ? 'selected' : '' ?>>Artan</option>
              </select>
            </label>
            <button class="btn btn-primary" type="submit">Filtrele</button>
          </form>
          <?php if ($panelError !== ''): ?>
            <p class="panel-alert"><?= mx_h($panelError) ?></p>
          <?php endif; ?>
          <div class="panel-table-wrap">
            <table class="panel-table">
              <thead>
                <tr>
                  <th>Talep</th>
                  <th>Durum</th>
                  <th>Gönderici</th>
                  <th>Alıcı</th>
                  <th>Adres</th>
                  <th>Mesafe</th>
                  <th>Ücret</th>
                  <th>Tarih</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($requests as $request): ?>
                  <tr>
                    <td><a href="talep.php?id=<?= (int) $request['id'] ?>"><?= mx_h($request['tracking_code']) ?></a></td>
                    <td><span class="panel-status panel-status-<?= mx_h($request['status']) ?>"><?= mx_h(mx_status_label($request['status'])) ?></span></td>
                    <td><?= mx_h($request['sender_name']) ?> <a class="wa-icon" href="<?= mx_h(mx_whatsapp_url($request['sender_phone'])) ?>" target="_blank" rel="noopener" aria-label="Gönderici WhatsApp">W</a><br><a href="tel:<?= mx_h($request['sender_phone']) ?>"><small><?= mx_h($request['sender_phone']) ?></small></a></td>
                    <td><?= mx_h($request['recipient_name']) ?> <a class="wa-icon" href="<?= mx_h(mx_whatsapp_url($request['recipient_phone'])) ?>" target="_blank" rel="noopener" aria-label="Alıcı WhatsApp">W</a><br><a href="tel:<?= mx_h($request['recipient_phone']) ?>"><small><?= mx_h($request['recipient_phone']) ?></small></a></td>
                    <td><strong>Alım:</strong> <?= mx_h($request['pickup']) ?><br><small><strong>Teslim:</strong> <?= mx_h($request['dropoff']) ?></small></td>
                    <td><?= $request['distance_km'] !== null ? mx_h(number_format((float) $request['distance_km'], 1, ',', '.')) . ' km' : '-' ?></td>
                    <td><?= mx_h($request['price']) ?></td>
                    <td><?= mx_h(date('d.m.Y', strtotime($request['created_at']))) ?><br><small><?= mx_h(date('H:i', strtotime($request['created_at']))) ?></small></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?>
                  <tr><td colspan="6">Henüz talep yok.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
    </main>
  </body>
</html>
