<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_pricing_manager();

$pdo = mx_pdo();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mx_table_exists('pricing_settings')) {
            throw new RuntimeException('pricing_settings tablosu bulunamadi.');
        }

        $values = $_POST['values'] ?? [];
        if (!is_array($values)) {
            throw new RuntimeException('Gecersiz fiyatlandirma verisi.');
        }

        $stmt = $pdo->prepare('UPDATE pricing_settings SET numeric_value = :value WHERE setting_key = :key');
        $changed = 0;
        foreach ($values as $key => $value) {
            $key = mx_clean_string($key, 80);
            $normalized = str_replace(',', '.', (string) $value);
            if ($key === '' || !is_numeric($normalized)) {
                continue;
            }
            $stmt->execute([':value' => (float) $normalized, ':key' => $key]);
            $changed += $stmt->rowCount();
        }

        mx_audit_log(null, 'pricing_update', 'Fiyatlandirma ayarlari guncellendi. Degisen satir: ' . $changed);
        $message = 'Fiyatlandırma ayarları kaydedildi.';
    } catch (Throwable $exception) {
        $error = 'Fiyatlandırma ayarları kaydedilemedi. Migration ve server log kontrol edilmeli.';
        mx_log_error('pricing update failed', $exception);
    }
}

$rows = [];
$groups = [
    'service' => 'Hizmet Ücretleri',
    'package' => 'Paket Etkileri',
    'rule' => 'Genel Kurallar',
];

try {
    if (!mx_table_exists('pricing_settings')) {
        $error = 'pricing_settings tablosu yok. Önce migrations/003_pricing_settings.sql dosyasını phpMyAdmin üzerinden çalıştırın.';
    } else {
        $rows = $pdo
            ->query('SELECT setting_key, label, setting_group, numeric_value, unit, description, updated_at FROM pricing_settings ORDER BY setting_group, setting_key')
            ->fetchAll();
    }
} catch (Throwable $exception) {
    $error = 'Fiyatlandırma listesi alınamadı.';
    mx_log_error('pricing list failed', $exception);
}

$grouped = [];
foreach ($rows as $row) {
    $grouped[$row['setting_group']][] = $row;
}
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fiyatlandırma | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260519-font-pass">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">MyExpress</p>
          <h1>Fiyatlandırma</h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="index.php">Talepler</a>
          <?php if (mx_panel_can_manage_users()): ?>
            <a class="btn btn-secondary" href="kullanicilar.php">Kullanıcılar</a>
          <?php endif; ?>
          <a class="btn btn-secondary" href="logout.php">Çıkış Yap</a>
        </div>
      </section>

      <form class="panel-card pricing-panel" method="post">
        <div class="panel-card-heading">
          <div>
            <h2>Fiyat Kuralları</h2>
            <p>Buradaki değerler anasayfa tahminini ve talep kaydındaki sunucu fiyatını belirler.</p>
          </div>
          <?php if ($rows): ?>
            <button class="btn btn-primary" type="submit">Kaydet</button>
          <?php endif; ?>
        </div>

        <?php if ($message !== ''): ?>
          <p class="panel-success"><?= mx_h($message) ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <p class="panel-alert"><?= mx_h($error) ?></p>
        <?php endif; ?>

        <?php foreach ($groups as $groupKey => $groupLabel): ?>
          <?php if (!empty($grouped[$groupKey])): ?>
            <section class="pricing-group">
              <h3><?= mx_h($groupLabel) ?></h3>
              <div class="pricing-grid">
                <?php foreach ($grouped[$groupKey] as $row): ?>
                  <label class="pricing-item">
                    <span>
                      <strong><?= mx_h($row['label']) ?></strong>
                      <small><?= mx_h($row['description']) ?></small>
                    </span>
                    <span class="pricing-input">
                      <input name="values[<?= mx_h($row['setting_key']) ?>]" value="<?= mx_h(rtrim(rtrim((string) $row['numeric_value'], '0'), '.')) ?>" inputmode="decimal" required>
                      <em><?= mx_h($row['unit']) ?></em>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>
        <?php endforeach; ?>
      </form>
    </main>
  </body>
</html>
