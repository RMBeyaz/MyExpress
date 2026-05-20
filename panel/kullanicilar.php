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
$courierVehicleOptions = [
    'motor' => 'Motorlu Kurye',
    'scooter' => 'Scooter',
    'otomobil' => 'Otomobil',
    'hafif_ticari' => 'Hafif Ticari',
    'panelvan' => 'Panelvan',
    'yaya' => 'Yaya Kurye',
];
$courierVehicleKey = static function (string $value) use ($courierVehicleOptions): string {
    $key = array_search($value, $courierVehicleOptions, true);
    return is_string($key) ? $key : '';
};

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
            $vehicleTypeKey = mx_clean_string($_POST['vehicle_type'] ?? '', 40);
            $plate = mx_clean_string($_POST['plate'] ?? '', 40);

            if ($fullName === '' || $phone === '' || !isset($courierVehicleOptions[$vehicleTypeKey])) {
                throw new RuntimeException('Kurye adi, telefonu ve arac tipi zorunludur.');
            }
            $vehicleType = $courierVehicleOptions[$vehicleTypeKey];

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

            if (mx_column_exists('courier_requests', 'assigned_courier_id')) {
                $pdo->prepare('UPDATE courier_requests SET assigned_courier_id = NULL WHERE assigned_courier_id = :id')->execute([':id' => $id]);
            }
            $pdo->prepare('DELETE FROM couriers WHERE id = :id')->execute([':id' => $id]);
            mx_audit_log(null, 'courier_delete', $courier['full_name'] . ' kuryesi silindi. Telefon: ' . $courier['phone']);
            $message = 'Kurye silindi.';
        }

        if ($action === 'update_courier') {
            if (!mx_table_exists('couriers')) {
                throw new RuntimeException('couriers tablosu bulunamadi.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $fullName = mx_clean_string($_POST['courier_full_name'] ?? '', 120);
            $phone = mx_clean_string($_POST['courier_phone'] ?? '', 40);
            $vehicleTypeKey = mx_clean_string($_POST['vehicle_type'] ?? '', 40);
            $plate = mx_clean_string($_POST['plate'] ?? '', 40);
            $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

            $target = $pdo->prepare('SELECT full_name, phone FROM couriers WHERE id = :id');
            $target->execute([':id' => $id]);
            $courier = $target->fetch();
            if (!$courier) {
                throw new RuntimeException('Kurye bulunamadi.');
            }
            if ($fullName === '' || $phone === '' || !isset($courierVehicleOptions[$vehicleTypeKey])) {
                throw new RuntimeException('Kurye adi, telefonu ve arac tipi zorunludur.');
            }

            $pdo->prepare(
                'UPDATE couriers
                 SET full_name = :full_name, phone = :phone, vehicle_type = :vehicle_type, plate = :plate, is_active = :is_active
                 WHERE id = :id'
            )->execute([
                ':full_name' => $fullName,
                ':phone' => $phone,
                ':vehicle_type' => $courierVehicleOptions[$vehicleTypeKey],
                ':plate' => $plate,
                ':is_active' => $isActive,
                ':id' => $id,
            ]);
            mx_audit_log(null, 'courier_update', $courier['full_name'] . ' kuryesi guncellendi. Yeni ad: ' . $fullName);
            $message = 'Kurye güncellendi.';
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
    <link rel="stylesheet" href="../styles.css?v=20260521-mobile-menu-float">
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

      <section class="panel-user-stack">
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
      </section>

      <section class="panel-user-stack courier-management-grid">
        <form class="panel-card user-create-card courier-create-card" method="post">
          <h2>Yeni Kurye</h2>
          <input type="hidden" name="action" value="create_courier">
          <div class="panel-edit-grid panel-edit-grid-compact">
            <label>Ad soyad <input name="courier_full_name" required></label>
            <label>Telefon <input name="courier_phone" inputmode="tel" placeholder="05..." required></label>
            <label>Araç tipi
              <select name="vehicle_type" required>
                <option value="">Seçiniz</option>
                <?php foreach ($courierVehicleOptions as $key => $label): ?>
                  <option value="<?= mx_h($key) ?>"><?= mx_h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
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
          <div class="panel-table-wrap">
            <table class="panel-table user-table user-table-singleline courier-table">
              <thead><tr><th>Kurye</th><th>Telefon</th><th>Araç tipi</th><th>Plaka</th><th>Durum</th><th>Oluşturma</th><th>İşlem</th></tr></thead>
              <tbody>
                <?php foreach ($couriers as $courier): ?>
                  <tr>
                    <td><input form="courier-update-<?= (int) $courier['id'] ?>" name="courier_full_name" value="<?= mx_h($courier['full_name']) ?>" required aria-label="Kurye ad soyad"></td>
                    <td><input form="courier-update-<?= (int) $courier['id'] ?>" name="courier_phone" value="<?= mx_h($courier['phone']) ?>" required inputmode="tel" aria-label="Kurye telefonu"></td>
                    <td>
                      <select form="courier-update-<?= (int) $courier['id'] ?>" name="vehicle_type" required aria-label="Araç tipi">
                        <?php foreach ($courierVehicleOptions as $key => $label): ?>
                          <option value="<?= mx_h($key) ?>" <?= $courierVehicleKey((string) $courier['vehicle_type']) === $key ? 'selected' : '' ?>><?= mx_h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input form="courier-update-<?= (int) $courier['id'] ?>" name="plate" value="<?= mx_h($courier['plate']) ?>" aria-label="Plaka"></td>
                    <td>
                      <select form="courier-update-<?= (int) $courier['id'] ?>" name="is_active" aria-label="Durum">
                        <option value="1" <?= (int) $courier['is_active'] === 1 ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= (int) $courier['is_active'] === 0 ? 'selected' : '' ?>>Pasif</option>
                      </select>
                    </td>
                    <td><span class="nowrap"><?= $courier['created_at'] ? mx_h($courier['created_at']) : '-' ?></span></td>
                    <td>
                      <div class="user-actions">
                        <form id="courier-update-<?= (int) $courier['id'] ?>" method="post" class="password-inline">
                          <input type="hidden" name="action" value="update_courier">
                          <input type="hidden" name="id" value="<?= (int) $courier['id'] ?>">
                          <button class="panel-icon-btn save" type="submit" aria-label="Kuryeyi kaydet"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 3h12l2 2v16H5V3Zm2 2v14h10V7.8L14.2 5H7Zm2 1h5v5H9V6Zm0 8h6v2H9v-2Z"/></svg></button>
                        </form>
                        <a class="panel-icon-btn" href="kurye-hareketleri.php?id=<?= (int) $courier['id'] ?>" aria-label="Kurye işlem geçmişi"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M13 3a9 9 0 1 0 8.95 10h-2A7 7 0 1 1 13 5v4l5-5-5-5v4Zm-1 5h2v5l4 2-.9 1.8-5.1-2.55V8Z"/></svg></a>
                        <form method="post" onsubmit="return confirm('Bu kurye silinsin mi?');">
                          <input type="hidden" name="action" value="delete_courier">
                          <input type="hidden" name="id" value="<?= (int) $courier['id'] ?>">
                          <button class="panel-icon-btn danger" type="submit" aria-label="Kuryeyi sil"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-.7 11H7.7L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z"/></svg></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$couriers): ?><tr><td colspan="7">Henüz kurye tanımı yok.</td></tr><?php endif; ?>
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
          <table class="panel-table user-table user-table-singleline">
            <thead><tr><th>Kullanıcı adı</th><th>Ad soyad</th><th>Rol</th><th>Durum</th><th>Son giriş</th><th>Şifre</th><th>İşlem</th></tr></thead>
            <tbody>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td>
                    <input form="user-update-<?= (int) $user['id'] ?>" name="username" value="<?= mx_h($user['username']) ?>" required aria-label="Kullanıcı adı">
                  </td>
                  <td>
                    <input form="user-update-<?= (int) $user['id'] ?>" name="full_name" value="<?= mx_h($user['full_name']) ?>" required aria-label="Ad soyad">
                  </td>
                  <td>
                    <select form="user-update-<?= (int) $user['id'] ?>" name="role" aria-label="Rol">
                      <?php foreach ($editableRoles as $key => $label): ?>
                        <?php if (mx_panel_is_admin() || $key === 'staff'): ?>
                          <option value="<?= mx_h($key) ?>" <?= $user['role'] === $key ? 'selected' : '' ?>><?= mx_h($label) ?></option>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <select form="user-update-<?= (int) $user['id'] ?>" name="is_active" aria-label="Durum">
                      <option value="1" <?= (int) $user['is_active'] === 1 ? 'selected' : '' ?>>Aktif</option>
                      <option value="0" <?= (int) $user['is_active'] === 0 ? 'selected' : '' ?>>Pasif</option>
                    </select>
                  </td>
                  <td><span class="nowrap"><?= $user['last_login_at'] ? mx_h($user['last_login_at']) : '-' ?></span></td>
                  <td>
                    <input form="user-update-<?= (int) $user['id'] ?>" type="password" name="password" minlength="8" placeholder="Yeni şifre" aria-label="Yeni şifre">
                  </td>
                  <td>
                    <div class="user-actions">
                      <form id="user-update-<?= (int) $user['id'] ?>" method="post" class="password-inline">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <button class="panel-icon-btn save" type="submit" aria-label="Kullanıcıyı kaydet"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 3h12l2 2v16H5V3Zm2 2v14h10V7.8L14.2 5H7Zm2 1h5v5H9V6Zm0 8h6v2H9v-2Z"/></svg></button>
                      </form>
                      <a class="panel-icon-btn" href="kullanici-hareketleri.php?id=<?= (int) $user['id'] ?>" aria-label="Kullanıcı işlem geçmişi"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M13 3a9 9 0 1 0 8.95 10h-2A7 7 0 1 1 13 5v4l5-5-5-5v4Zm-1 5h2v5l4 2-.9 1.8-5.1-2.55V8Z"/></svg></a>
                      <form method="post" onsubmit="return confirm('Bu kullanıcı silinsin mi?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <button class="panel-icon-btn danger" type="submit" aria-label="Kullanıcıyı sil"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-.7 11H7.7L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z"/></svg></button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$users): ?><tr><td colspan="7">Henüz veritabanı kullanıcısı yok.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
