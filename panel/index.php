<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';

$error = '';
$config = [];

try {
    $config = mx_config();
} catch (Throwable $exception) {
    $error = 'Config dosyasi okunamadi.';
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
        header('Location: index.php');
        exit;
    }

    $error = 'Kullanici adi veya sifre hatali.';
}

$isReady = !empty($config['panel_user']) && (!empty($config['panel_pass_hash']) || !empty($config['panel_pass']));
$requests = [];

if (mx_panel_is_logged_in()) {
    $stmt = mx_pdo()->query(
        'SELECT id, tracking_code, status, pickup, dropoff, price, sender_name, sender_phone, created_at
         FROM courier_requests
         ORDER BY created_at DESC
         LIMIT 80'
    );
    $requests = $stmt->fetchAll();
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
          <div class="panel-table-wrap">
            <table class="panel-table">
              <thead>
                <tr>
                  <th>Talep</th>
                  <th>Durum</th>
                  <th>Rota</th>
                  <th>Gönderici</th>
                  <th>Ücret</th>
                  <th>Tarih</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($requests as $request): ?>
                  <tr>
                    <td><a href="talep.php?id=<?= (int) $request['id'] ?>"><?= mx_h($request['tracking_code']) ?></a></td>
                    <td><span class="panel-status"><?= mx_h($request['status']) ?></span></td>
                    <td><?= mx_h($request['pickup']) ?><br><small><?= mx_h($request['dropoff']) ?></small></td>
                    <td><?= mx_h($request['sender_name']) ?><br><small><?= mx_h($request['sender_phone']) ?></small></td>
                    <td><?= mx_h($request['price']) ?></td>
                    <td><?= mx_h($request['created_at']) ?></td>
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
