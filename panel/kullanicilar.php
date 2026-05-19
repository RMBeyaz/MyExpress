<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_user_manager();

$pdo = mx_pdo();
$message = '';
$error = '';
$roles = mx_roles();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = mx_clean_string($_POST['action'] ?? 'create', 32);

    try {
        if (!mx_table_exists('panel_users')) {
            throw new RuntimeException('panel_users tablosu bulunamadi.');
        }

        if ($action === 'create') {
            $username = mx_clean_string($_POST['username'] ?? '', 80);
            $fullName = mx_clean_string($_POST['full_name'] ?? '', 120);
            $role = mx_clean_string($_POST['role'] ?? 'staff', 20);
            $password = (string) ($_POST['password'] ?? '');

            if (!mx_panel_is_admin() && $role !== 'staff') {
                throw new RuntimeException('Yonetici sadece calisan ekleyebilir.');
            }
            if ($username === '' || $fullName === '' || strlen($password) < 8 || !isset($roles[$role])) {
                throw new RuntimeException('Kullanici bilgileri eksik veya gecersiz.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO panel_users (username, full_name, role, password_hash, is_active, created_by)
                 VALUES (:username, :full_name, :role, :password_hash, 1, :created_by)'
            );
            $stmt->execute([
                ':username' => $username,
                ':full_name' => $fullName,
                ':role' => $role,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':created_by' => mx_panel_user(),
            ]);
            mx_audit_log(null, 'panel_user_create', $username . ' kullanicisi olusturuldu. Rol: ' . $role);
            $message = 'Kullanıcı oluşturuldu.';
        }

        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $target = $pdo->prepare('SELECT username, role FROM panel_users WHERE id = :id');
            $target->execute([':id' => $id]);
            $targetUser = $target->fetch();

            if (!$targetUser) {
                throw new RuntimeException('Kullanici bulunamadi.');
            }
            if (!mx_panel_is_admin() && $targetUser['role'] !== 'staff') {
                throw new RuntimeException('Yonetici sadece calisanlari guncelleyebilir.');
            }

            $pdo->prepare('UPDATE panel_users SET is_active = :is_active WHERE id = :id')->execute([
                ':is_active' => $isActive,
                ':id' => $id,
            ]);
            mx_audit_log(null, 'panel_user_toggle', $targetUser['username'] . ' aktiflik durumu: ' . $isActive);
            $message = 'Kullanıcı durumu güncellendi.';
        }

        if ($action === 'password') {
            $id = (int) ($_POST['id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 8) {
                throw new RuntimeException('Sifre en az 8 karakter olmali.');
            }

            $target = $pdo->prepare('SELECT username, role FROM panel_users WHERE id = :id');
            $target->execute([':id' => $id]);
            $targetUser = $target->fetch();
            if (!$targetUser) {
                throw new RuntimeException('Kullanici bulunamadi.');
            }
            if (!mx_panel_is_admin() && $targetUser['role'] !== 'staff') {
                throw new RuntimeException('Yonetici sadece calisan sifresi degistirebilir.');
            }

            $pdo->prepare('UPDATE panel_users SET password_hash = :password_hash WHERE id = :id')->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $id,
            ]);
            mx_audit_log(null, 'panel_user_password', $targetUser['username'] . ' sifresi guncellendi.');
            $message = 'Şifre güncellendi.';
        }
    } catch (Throwable $exception) {
        $error = 'İşlem tamamlanamadı: ' . $exception->getMessage();
        mx_log_error('panel user operation failed', $exception);
    }
}

$users = [];
if (!mx_table_exists('panel_users')) {
    $error = 'panel_users tablosu yok. Önce migrations/004_panel_users.sql dosyasını phpMyAdmin üzerinden çalıştırın.';
} else {
    $users = $pdo->query('SELECT id, username, full_name, role, is_active, last_login_at, created_at FROM panel_users ORDER BY role, username')->fetchAll();
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kullanıcılar | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260519-filters-v2">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">MyExpress</p>
          <h1>Kullanıcılar</h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="index.php">Talepler</a>
          <?php if (mx_panel_can_manage_pricing()): ?>
            <a class="btn btn-secondary" href="fiyatlandirma.php">Fiyatlandırma</a>
          <?php endif; ?>
          <a class="btn btn-secondary" href="logout.php">Çıkış Yap</a>
        </div>
      </section>

      <section class="panel-detail-grid">
        <form class="panel-card user-create-card" method="post">
          <h2>Yeni Kullanıcı</h2>
          <input type="hidden" name="action" value="create">
          <div class="panel-edit-grid panel-edit-grid-compact">
            <label>Kullanıcı adı <input name="username" autocomplete="off" required></label>
            <label>Ad soyad <input name="full_name" required></label>
            <label>Rol
              <select name="role">
                <?php foreach ($roles as $key => $label): ?>
                  <?php if (mx_panel_is_admin() || $key === 'staff'): ?>
                    <option value="<?= mx_h($key) ?>"><?= mx_h($label) ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Geçici şifre <input type="password" name="password" minlength="8" required></label>
          </div>
          <button class="btn btn-primary" type="submit">Kullanıcı Oluştur</button>
        </form>

        <article class="panel-card">
          <h2>Rol Mantığı</h2>
          <dl class="panel-detail-list">
            <dt>Admin</dt><dd>Tüm sayfalar, kullanıcı ve fiyat yönetimi.</dd>
            <dt>Yönetici</dt><dd>Talep operasyonu, fiyat yönetimi, çalışan oluşturma.</dd>
            <dt>Çalışan</dt><dd>Talep listesi ve talep operasyon işlemleri.</dd>
          </dl>
        </article>
      </section>

      <section class="panel-card">
        <div class="panel-card-heading">
          <h2>Panel Kullanıcıları</h2>
          <span><?= count($users) ?> kayıt</span>
        </div>
        <?php if ($message !== ''): ?><p class="panel-success"><?= mx_h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="panel-alert"><?= mx_h($error) ?></p><?php endif; ?>
        <div class="panel-table-wrap">
          <table class="panel-table user-table">
            <thead><tr><th>Kullanıcı</th><th>Rol</th><th>Durum</th><th>Son giriş</th><th>İşlem</th></tr></thead>
            <tbody>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td><strong><?= mx_h($user['full_name']) ?></strong><br><small><?= mx_h($user['username']) ?></small></td>
                  <td><?= mx_h(mx_role_label($user['role'])) ?></td>
                  <td><span class="panel-status"><?= (int) $user['is_active'] === 1 ? 'Aktif' : 'Pasif' ?></span></td>
                  <td><?= $user['last_login_at'] ? mx_h($user['last_login_at']) : '-' ?></td>
                  <td>
                    <div class="user-actions">
                      <form method="post">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <input type="hidden" name="is_active" value="<?= (int) $user['is_active'] === 1 ? 0 : 1 ?>">
                        <button class="btn btn-secondary" type="submit"><?= (int) $user['is_active'] === 1 ? 'Pasifleştir' : 'Aktifleştir' ?></button>
                      </form>
                      <form method="post" class="password-inline">
                        <input type="hidden" name="action" value="password">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <input type="password" name="password" minlength="8" placeholder="Yeni şifre" required>
                        <button class="btn btn-primary" type="submit">Şifrele</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$users): ?><tr><td colspan="5">Henüz veritabanı kullanıcısı yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
