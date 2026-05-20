<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_user_manager();

$pdo = mx_pdo();
$id = (int) ($_GET['id'] ?? 0);

if (!mx_table_exists('panel_users')) {
    http_response_code(500);
    echo 'panel_users tablosu bulunamadi.';
    exit;
}

$stmt = $pdo->prepare('SELECT id, username, full_name, role, is_active, last_login_at, created_at FROM panel_users WHERE id = :id');
$stmt->execute([':id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo 'Kullanici bulunamadi.';
    exit;
}

$logs = [];
if (mx_table_exists('request_audit_logs')) {
    $logStmt = $pdo->prepare(
        'SELECT request_id, admin_user, action, details, ip_address, created_at
         FROM request_audit_logs
         WHERE admin_user = :username
         ORDER BY created_at DESC
         LIMIT 250'
    );
    $logStmt->execute([':username' => $user['username']]);
    $logs = $logStmt->fetchAll();
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mx_h($user['username']) ?> Hareketleri | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260520-mobile-menu-minimal">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">Kullanıcı hareketleri</p>
          <h1><?= mx_h($user['full_name']) ?></h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="kullanicilar.php">Kullanıcılara Dön</a>
          <a class="btn btn-secondary" href="index.php">Talepler</a>
          <a class="btn btn-secondary" href="logout.php">Çıkış Yap</a>
        </div>
      </section>

      <section class="panel-detail-grid">
        <article class="panel-card">
          <h2>Kullanıcı</h2>
          <dl class="panel-detail-list">
            <dt>Kullanıcı</dt><dd><?= mx_h($user['username']) ?></dd>
            <dt>Rol</dt><dd><?= mx_h(mx_role_label($user['role'])) ?></dd>
            <dt>Durum</dt><dd><?= (int) $user['is_active'] === 1 ? 'Aktif' : 'Pasif' ?></dd>
            <dt>Son giriş</dt><dd><?= $user['last_login_at'] ? mx_h($user['last_login_at']) : '-' ?></dd>
          </dl>
        </article>
        <article class="panel-card">
          <h2>Log Kapsamı</h2>
          <p class="panel-help-text">Bu sayfa kullanıcının panelde kendi oturumu ile yaptığı işlemleri gösterir. Kullanıcı adı değişirse eski kullanıcı adıyla tutulmuş kayıtlar ayrı kalabilir.</p>
        </article>
      </section>

      <section class="panel-card">
        <div class="panel-card-heading">
          <h2>İşlem Geçmişi</h2>
          <span><?= count($logs) ?> kayıt</span>
        </div>
        <?php if (!mx_table_exists('request_audit_logs')): ?>
          <p class="panel-alert">request_audit_logs tablosu bulunamadı.</p>
        <?php endif; ?>
        <div class="panel-table-wrap">
          <table class="panel-table audit-table">
            <thead><tr><th>Tarih</th><th>İşlem</th><th>Detay</th><th>Talep</th><th>IP</th></tr></thead>
            <tbody>
              <?php foreach ($logs as $log): ?>
                <tr>
                  <td><strong><?= mx_h(date('H:i', strtotime($log['created_at']))) ?></strong><br><small><?= mx_h(date('d.m.Y', strtotime($log['created_at']))) ?></small></td>
                  <td><span class="panel-status"><?= mx_h($log['action']) ?></span></td>
                  <td><?= mx_h($log['details']) ?></td>
                  <td>
                    <?php if (!empty($log['request_id'])): ?>
                      <a class="tracking-link" href="talep.php?id=<?= (int) $log['request_id'] ?>">#<?= (int) $log['request_id'] ?></a>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <td><small><?= mx_h($log['ip_address'] ?: '-') ?></small></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$logs): ?><tr><td colspan="5">Bu kullanıcı için işlem kaydı yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
