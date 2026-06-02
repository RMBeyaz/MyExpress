<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mx_require_csrf();
}
mx_panel_require_admin();

$config = mx_config();
$message = '';
$error = '';
$to = mx_clean_string($_POST['to'] ?? '', 160);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Geçerli bir test e-posta adresi girin.');
        }

        $subject = 'MyExpress SMTP test';
        $body = implode("\n", [
            'Bu e-posta MyExpress panelinden gönderilen SMTP test mesajıdır.',
            '',
            'Tarih: ' . date('Y-m-d H:i:s'),
            'Gönderen kullanıcı: ' . mx_panel_user(),
            '',
            'Bu mesaj geldiyse mail gönderim altyapısı çalışıyor demektir.',
        ]);
        $htmlBody = '<div style="margin:0;padding:24px;background:#f4f7f9;font-family:Arial,Helvetica,sans-serif;color:#0b2238;">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #dce4ea;border-radius:14px;overflow:hidden;">'
            . '<div style="padding:22px 24px;background:#071d2f;color:#fff;font-size:22px;font-weight:800;">MyExpress</div>'
            . '<div style="padding:26px 24px;">'
            . '<p style="margin:0 0 8px;color:#ef4438;font-size:13px;font-weight:800;text-transform:uppercase;">SMTP testi</p>'
            . '<h1 style="margin:0 0 12px;font-size:26px;line-height:1.2;">Mail altyapısı çalışıyor</h1>'
            . '<p style="margin:0 0 12px;color:#536372;font-size:15px;line-height:1.55;">Bu e-posta MyExpress panelinden gönderilen HTML test mesajıdır.</p>'
            . '<p style="margin:0;color:#536372;font-size:14px;line-height:1.55;">Tarih: ' . mx_h(date('Y-m-d H:i:s')) . '<br>Gönderen kullanıcı: ' . mx_h(mx_panel_user()) . '</p>'
            . '</div></div></div>';

        if (!mx_send_html_mail($to, $subject, $body, $htmlBody)) {
            throw new RuntimeException('Mail fonksiyonu başarısız döndü. Detay için server error_log kontrol edilmeli.');
        }

        mx_audit_log(null, 'mail_test', 'SMTP test maili gönderildi. Alıcı: ' . $to);
        $message = 'Test maili gönderim komutu başarılı döndü. Gelen kutusu ve spam klasörünü kontrol edin.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        mx_log_error('panel mail test failed', $exception, ['to' => $to]);
    }
}

$smtpChecks = [
    'SMTP host' => !empty($config['smtp_host']),
    'SMTP kullanıcı' => !empty($config['smtp_user']),
    'SMTP şifre' => array_key_exists('smtp_pass', $config) && (string) $config['smtp_pass'] !== '',
    'Gönderen adres' => !empty($config['mail_from']) || !empty($config['smtp_user']),
];
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail Testi | MyExpress Panel</title>
    <link rel="stylesheet" href="../styles.css?v=20260521-panel-invoices">
  </head>
  <body class="panel-body">
    <main class="panel-shell">
      <section class="panel-header">
        <div>
          <p class="eyebrow">MyExpress</p>
          <h1>Mail Testi</h1>
        </div>
        <div class="panel-header-actions">
          <a class="btn btn-secondary" href="index.php">Talepler</a>
          <a class="btn btn-secondary" href="kullanicilar.php">Kullanıcılar</a>
          <a class="btn btn-secondary" href="logout.php">Çıkış Yap</a>
        </div>
      </section>

      <section class="panel-card">
        <div class="panel-card-heading">
          <div>
            <h2>SMTP kontrolü</h2>
            <p>Şifre ve hassas bilgiler ekranda gösterilmez; sadece ayarın var/yok durumu görünür.</p>
          </div>
        </div>
        <div class="panel-summary-grid">
          <?php foreach ($smtpChecks as $label => $ok): ?>
            <article>
              <span><?= mx_h($label) ?></span>
              <strong><?= $ok ? 'Var' : 'Eksik' ?></strong>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <form class="panel-card user-create-card" method="post">
    <?= mx_csrf_field() ?>
        <div class="panel-card-heading">
          <div>
            <h2>Test maili gönder</h2>
            <p>Önce kendi e-posta adresinize, sonra mümkünse farklı bir Gmail/Outlook adresine test gönderin.</p>
          </div>
        </div>
        <?php if ($message !== ''): ?><p class="panel-success"><?= mx_h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="panel-alert"><?= mx_h($error) ?></p><?php endif; ?>
        <label>Alıcı e-posta
          <input type="email" name="to" value="<?= mx_h($to) ?>" placeholder="ornek@mail.com" required>
        </label>
        <button class="btn btn-primary" type="submit">Test Maili Gönder</button>
      </form>
    </main>
  </body>
</html>
