<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mx_require_csrf();
}
require __DIR__ . '/_layout.php';

if (mx_customer_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';
$email = mx_clean_string($_POST['email'] ?? '', 160);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Geçerli bir e-posta adresi girin.');
        }
        if (!mx_customer_password_reset_ready()) {
            throw new RuntimeException('Şifre yenileme sistemi henüz hazır değil.');
        }

        $limit = mx_public_rate_limit('password_reset', $email, 4, 1800);
        if (!$limit['allowed']) {
            throw new RuntimeException('Çok fazla şifre yenileme isteği gönderildi. Lütfen daha sonra tekrar deneyin.');
        }

        $stmt = mx_pdo()->prepare('SELECT id, full_name, email, is_active FROM customers WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $customer = $stmt->fetch();

        if ($customer && (int) $customer['is_active'] === 1) {
            $token = bin2hex(random_bytes(32));
            mx_pdo()->prepare(
                'UPDATE customers
                 SET password_reset_token = :token,
                     password_reset_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                 WHERE id = :id'
            )->execute([
                ':token' => hash('sha256', $token),
                ':id' => (int) $customer['id'],
            ]);

            if (!mx_send_customer_password_reset_mail((string) $customer['email'], (string) $customer['full_name'], $token)) {
                mx_log_error(
                    'customer password reset mail failed',
                    new RuntimeException('password reset mail send returned false'),
                    ['customer_id' => (int) $customer['id'], 'email' => $email]
                );
            }
        }

        $message = 'Bu e-posta ile aktif bir hesap varsa şifre yenileme bağlantısı gönderildi.';
        $email = '';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        mx_log_error('customer password reset request failed', $exception, ['email' => $email]);
    }
}

mx_account_header('Şifremi Unuttum', 'login');
?>
<section class="account-auth">
  <div>
    <p class="eyebrow">MyExpress Hesabım</p>
    <h1>Şifrenizi yenileyin</h1>
    <p>Hesabınızda kayıtlı e-posta adresini girin. Aktif bir hesabınız varsa tek kullanımlık şifre yenileme bağlantısını göndereceğiz.</p>
  </div>
  <form class="account-card account-form" method="post">
    <?= mx_csrf_field() ?>
    <h2>Yenileme bağlantısı isteyin</h2>
    <?php mx_account_flash($message, $error); ?>
    <label>E-posta<input type="email" name="email" value="<?= mx_h($email) ?>" required autocomplete="email"></label>
    <button class="btn btn-primary" type="submit">Bağlantı Gönder</button>
    <p class="account-muted">Şifrenizi hatırladınız mı? <a href="giris.php">Giriş yapın</a>.</p>
  </form>
</section>
<?php mx_account_footer(); ?>
