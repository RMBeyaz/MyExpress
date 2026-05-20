<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_user_manager();

$pdo = mx_pdo();
$message = '';
$error = '';
$roles = mx_roles();
$editableRoles = array_filter(
    $roles,
    static fn (string $key): bool => $key !== 'admin',
    ARRAY_FILTER_USE_KEY
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = mx_clean_string($_POST['action'] ?? 'create', 32);

    try {
        if (!mx_table_exists('panel_users')) {
            throw new RuntimeException('panel_users tablosu bulunamadi.');
        }

        if ($action === 'create_courier') {
            if (!mx_table_exists('couriers')) {
                throw new RuntimeException('couriers tablosu bulunamadi. migrations/006_couriers.sql calistirilmali.');
            }

            $fullName = mx_clean_string($_POST['courier_full_name'] ?? '', 120);
            $phone = mx_clean_string($_POST['courier_phone'] ?? '', 40);
            $vehicleType = mx_clean_string($_POST['vehicle_type'] ?? '', 80);
            $plate = mx_clean_string($_POST['plate'] ?? '', 40);

            if ($fullName === '' || $phone === '') {
                throw new RuntimeException('Kurye adi ve telefonu zorunludur.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO couriers (full_name, phone, vehicle_type, plate, is_active, created_by)
                 VALUES (:full_name, :phone, :vehicle_type, :plate, 1, :created_by)'
            );
            $stmt->execute([
                ':full_name' => $fullName,
                ':phone' => $phone,
                ':vehicle_type' => $vehicleType,
                ':plate' => $plate,
                ':created_by' => mx_panel_user(),
            ]);
            mx_audit_log(null, 'courier_create', $fullName . ' kuryesi olusturuldu. Telefon: ' . $phone);
            $message = 'Kurye eklendi.';
        }

        if ($action === 'delete_courier') {
            if (!mx_table_exists('couriers')) {
                throw new RuntimeException('couriers tablosu bulunamadi.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $target = $pdo->prepare('SELECT full_name, phone FROM couriers WHERE id = :id');
            $target->execute([':id' => $id]);
            $courier = $target->fetch();

            if (!$courier) {
                throw new RuntimeException('Kurye bulunamadi.');
            }

            $pdo->prepare('DELETE FROM couriers WHERE id = :id')->execute([':id' => $id]);
            mx_audit_log(null, 'courier_delete', $courier['full_name'] . ' kuryesi silindi. Telefon: ' . $courier['phone']);
            $message = 'Kurye silindi.';
        }

        if ($action === 'create') {
            $username = mx_clean_string($_POST['username'] ?? '', 80);
            $fullName = mx_clean_string($_POST['full_name'] ?? '', 120);
            $role = mx_clean_string($_POST['role'] ?? 'staff', 20);
            $password = (string) ($_POST['password'] ?? '');

            if (!mx_panel_is_admin() && $role !== 'staff') {
                throw new RuntimeException('Yonetici sadece calisan ekleyebilir.');
            }
            if ($username === '' || $fullName === '' || strlen($password) < 8 || !isset($editableRoles[$role])) {
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

        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $username = mx_clean_string($_POST['username'] ?? '', 80);
            $fullName = mx_clean_string($_POST['full_name'] ?? '', 120);
            $role = mx_clean_string($_POST['role'] ?? 'staff', 20);
            $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $password = (string) ($_POST['password'] ?? '');

            $target = $pdo->prepare('SELECT username, role FROM panel_users WHERE id = :id');
            $target->execute([':id' => $id]);
            $targetUser = $target->fetch();

            if (!$targetUser) {
                throw new RuntimeException('Kullanici bulunamadi.');
            }
            if ($username === '' || $fullName === '' || !isset($editableRoles[$role])) {
                throw new RuntimeException('Kullanici bilgileri eksik veya gecersiz.');
            }
            if (!mx_panel_is_admin() && ($targetUser['role'] !== 'staff' || $role !== 'staff')) {
                throw new RuntimeException('Yonetici sadece calisanlari guncelleyebilir.');
            }
            if ($password !== '' && strlen($password) < 8) {
                throw new RuntimeException('Sifre en az 8 karakter olmali.');
            }

            $params = [
                ':username' => $username,
                ':full_name' => $fullName,
                ':role' => $role,
                ':is_active' => $isActive,
                ':id' => $id,
            ];
            $passwordSql = '';
            if ($password !== '') {
                $passwordSql = ', password_hash = :password_hash';
                $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $stmt = $pdo->prepare(
                "UPDATE panel_users
                 SET username = :username, full_name = :full_name, role = :role, is_active = :is_active{$passwordSql}
                 WHERE id = :id"
            );
            $stmt->execute($params);
            mx_audit_log(null, 'panel_user_update', $targetUser['username'] . ' kullanicisi guncellendi. Yeni kullanici: ' . $username . ' Rol: ' . $role);
            $message = 'Kullanıcı güncellendi.';
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $target = $pdo->prepare('SELECT username, role FROM panel_users WHERE id = :id');
            $target->execute([':id' => $id]);
            $targetUser = $target->fetch();

            if (!$targetUser) {
                throw new RuntimeException('Kullanici bulunamadi.');
            }
            if ((int) ($_SESSION['mx_panel_user_id'] ?? 0) === $id) {
                throw new RuntimeException('Aktif oturumdaki kullanici silinemez.');
            }
            if (!mx_panel_is_admin() && $targetUser['role'] !== 'staff') {
                throw new RuntimeException('Yonetici sadece calisanlari silebilir.');
            }

            $pdo->prepare('DELETE FROM panel_users WHERE id = :id')->execute([':id' => $id]);
            mx_audit_log(null, 'panel_user_delete', $targetUser['username'] . ' kullanicisi silindi.');
            $message = 'Kullanıcı silindi.';
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
$couriers = [];
if (!mx_table_exists('panel_users')) {
    $error = 'panel_users tablosu yok. Önce migrations/004_panel_users.sql dosyasını phpMyAdmin üzerinden çalıştırın.';
} else {
    $users = $pdo->query("SELECT id, username, full_name, role, is_active, last_login_at, created_at FROM panel_users WHERE role <> 'admin' ORDER BY role, username")->fetchAll();
}
if (mx_table_exists('couriers')) {
    $couriers = $pdo->query('SELECT id, full_name, phone, vehicle_type, plate, is_active, created_at FROM couriers ORDER BY is_active DESC, full_name')->fetchAll();
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kullanıcılar | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260520-courier-dispatch">
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
                <?php foreach ($editableRoles as $key => $label): ?>
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
            <dt>Yönetici</dt><dd>Talep operasyonu, fiyat yönetimi, çalışan oluşturma.</dd>
            <dt>Çalışan</dt><dd>Talep listesi ve talep operasyon işlemleri.</dd>
          </dl>
        </article>
      </section>

      <section class="panel-detail-grid courier-management-grid">
        <form class="panel-card user-create-card courier-create-card" method="post">
          <h2>Yeni Kurye</h2>
          <input type="hidden" name="action" value="create_courier">
          <div class="panel-edit-grid panel-edit-grid-compact">
            <label>Ad soyad <input name="courier_full_name" required></label>
            <label>Telefon <input name="courier_phone" inputmode="tel" placeholder="05..." required></label>
            <label>Araç tipi <input name="vehicle_type" placeholder="Motor, araç..."></label>
            <label>Plaka <input name="plate" placeholder="Opsiyonel"></label>
          </div>
          <button class="btn btn-primary" type="submit">Kurye Ekle</button>
          <?php if (!mx_table_exists('couriers')): ?>
            <p class="panel-alert">Kurye yönetimi için önce <code>migrations/006_couriers.sql</code> çalıştırılmalı.</p>
          <?php endif; ?>
        </form>

        <section class="panel-card">
          <div class="panel-card-heading">
            <h2>Kuryeler</h2>
            <span><?= count($couriers) ?> kayıt</span>
          </div>
          <div class="panel-table-wrap compact-table-wrap">
            <table class="panel-table courier-table">
              <thead><tr><th>Kurye</th><th>Telefon</th><th>Araç</th><th>İşlem</th></tr></thead>
              <tbody>
                <?php foreach ($couriers as $courier): ?>
                  <tr>
                    <td><strong><?= mx_h($courier['full_name']) ?></strong></td>
                    <td><a href="tel:<?= mx_h($courier['phone']) ?>"><?= mx_h($courier['phone']) ?></a></td>
                    <td><?= mx_h(trim((string) $courier['vehicle_type'] . ' ' . (string) $courier['plate'])) ?: '-' ?></td>
                    <td>
                      <form method="post" onsubmit="return confirm('Bu kurye silinsin mi?');">
                        <input type="hidden" name="action" value="delete_courier">
                        <input type="hidden" name="id" value="<?= (int) $courier['id'] ?>">
                        <button class="panel-icon-btn danger" type="submit" aria-label="Kuryeyi sil"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-.7 11H7.7L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z"/></svg></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$couriers): ?><tr><td colspan="4">Henüz kurye tanımı yok.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
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
                  <td>
                    <label class="table-edit-label">Ad soyad
                      <input form="user-update-<?= (int) $user['id'] ?>" name="full_name" value="<?= mx_h($user['full_name']) ?>" required>
                    </label>
                    <label class="table-edit-label">Kullanıcı adı
                      <input form="user-update-<?= (int) $user['id'] ?>" name="username" value="<?= mx_h($user['username']) ?>" required>
                    </label>
                  </td>
                  <td>
                    <select form="user-update-<?= (int) $user['id'] ?>" name="role">
                      <?php foreach ($editableRoles as $key => $label): ?>
                        <?php if (mx_panel_is_admin() || $key === 'staff'): ?>
                          <option value="<?= mx_h($key) ?>" <?= $user['role'] === $key ? 'selected' : '' ?>><?= mx_h($label) ?></option>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <select form="user-update-<?= (int) $user['id'] ?>" name="is_active">
                      <option value="1" <?= (int) $user['is_active'] === 1 ? 'selected' : '' ?>>Aktif</option>
                      <option value="0" <?= (int) $user['is_active'] === 0 ? 'selected' : '' ?>>Pasif</option>
                    </select>
                  </td>
                  <td><?= $user['last_login_at'] ? mx_h($user['last_login_at']) : '-' ?></td>
                  <td>
                    <div class="user-actions">
                      <form id="user-update-<?= (int) $user['id'] ?>" method="post" class="password-inline">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <input type="password" name="password" minlength="8" placeholder="Yeni şifre (opsiyonel)">
                        <button class="btn btn-primary" type="submit">Kaydet</button>
                      </form>
                      <form method="post" onsubmit="return confirm('Bu kullanıcı silinsin mi?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <button class="btn btn-danger" type="submit">Sil</button>
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
