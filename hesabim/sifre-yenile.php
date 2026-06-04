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

$token = mx_clean_string($_GET['token'] ?? $_POST['token'] ?? '', 128);
$message = '';
$error = '';
$tokenValid = false;
$customer = null;

try {
    if (!mx_customer_password_reset_ready()) {
        throw new RuntimeException('Şifre yenileme sistemi henüz hazır değil.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('Şifre yenileme bağlantısı geçersiz veya süresi dolmuş.');
    }

    $stmt = mx_pdo()->prepare(
        'SELECT id, email, full_name
         FROM customers
         WHERE password_reset_token = :token
           AND password_reset_expires_at >= NOW()
           AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':token' => hash('sha256', $token)]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Şifre yenileme bağlantısı geçersiz veya süresi dolmuş.');
    }
    $tokenValid = true;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8) {
            throw new RuntimeException('Şifre en az 8 karakter olmalı.');
        }
        if ($password !== $passwordConfirm) {
            throw new RuntimeException('Şifreler birbiriyle eşleşmiyor.');
        }

        mx_pdo()->prepare(
            'UPDATE customers
             SET password_hash = :password_hash,
                 password_reset_token = NULL,
                 password_reset_expires_at = NULL
             WHERE id = :id'
        )->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => (int) $customer['id'],
        ]);
        mx_record_login_attempt('customer', (string) $customer['email'], true);
        $tokenValid = false;
        $message = 'Şifreniz yenilendi. Yeni şifrenizle giriş yapabilirsiniz.';
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    mx_log_error('customer password reset failed', $exception);
}

mx_account_header('Yeni Şifre Belirle', 'login');
?>
<section class="account-auth">
  <div>
    <p class="eyebrow">MyExpress Hesabım</p>
    <h1>Yeni şifre belirleyin</h1>
    <p>Hesabınız için güçlü ve daha önce kullanmadığınız bir şifre seçin. Bağlantı yalnızca bir kez kullanılabilir.</p>
  </div>
  <form class="account-card account-form" method="post">
    <?= mx_csrf_field() ?>
    <input type="hidden" name="token" value="<?= mx_h($token) ?>">
    <h2>Şifreyi yenile</h2>
    <?php mx_account_flash($message, $error); ?>
    <?php if ($tokenValid): ?>
      <label>Yeni şifre<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label>Yeni şifre tekrar<input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
      <button class="btn btn-primary" type="submit">Şifreyi Kaydet</button>
    <?php endif; ?>
    <p class="account-muted"><a href="giris.php">Giriş sayfasına dönün</a> veya yeni bağlantı için <a href="sifremi-unuttum.php">şifre yenileme isteği gönderin</a>.</p>
  </form>
</section>
<?php mx_account_footer(); ?>
