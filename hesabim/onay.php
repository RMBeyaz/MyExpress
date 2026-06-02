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
$email = mx_clean_string($_GET['email'] ?? $_POST['email'] ?? '', 160);
$token = mx_clean_string($_GET['token'] ?? '', 128);
if (($_GET['resent'] ?? '') === '1') {
    $message = 'Yeni onay kodu e-posta adresinize gönderildi.';
}

try {
    if (!mx_table_exists('customers')) {
        throw new RuntimeException('Üyelik tabloları henüz kurulmamış.');
    }

    $hasVerifyColumns = mx_column_exists('customers', 'email_verification_code')
        && mx_column_exists('customers', 'email_verification_token')
        && mx_column_exists('customers', 'email_verification_expires_at');
    if (!$hasVerifyColumns) {
        throw new RuntimeException('E-posta doğrulama alanları henüz kurulmamış. migrations/011_customer_email_notifications.sql çalıştırılmalı.');
    }

    if ($token !== '') {
        $stmt = mx_pdo()->prepare(
            'SELECT id, email, full_name FROM customers
             WHERE email_verification_token = :token
               AND is_active = 0
               AND email_verification_expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->execute([':token' => hash('sha256', $token)]);
        $customer = $stmt->fetch();
        if (!$customer) {
            throw new RuntimeException('Onay bağlantısı geçersiz veya süresi dolmuş.');
        }

        mx_pdo()->prepare(
            'UPDATE customers
             SET is_active = 1, email_verified_at = NOW(), email_verification_code = NULL,
                 email_verification_token = NULL, email_verification_expires_at = NULL
             WHERE id = :id'
        )->execute([':id' => (int) $customer['id']]);
        $message = 'Hesabınız aktifleştirildi. Artık giriş yapabilirsiniz.';
        $email = (string) $customer['email'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Yeniden kod göndermek için geçerli e-posta adresi gerekir.');
        }

        $stmt = mx_pdo()->prepare('SELECT id, is_active FROM customers WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $customer = $stmt->fetch();
        if (!$customer) {
            throw new RuntimeException('Bu e-posta ile bekleyen hesap bulunamadı.');
        }
        if ((int) $customer['is_active'] === 1) {
            throw new RuntimeException('Bu hesap zaten aktif. Giriş yapabilirsiniz.');
        }
        if (!mx_refresh_customer_verification((int) $customer['id'])) {
            throw new RuntimeException('Onay kodu gönderilemedi. Lütfen daha sonra tekrar deneyin.');
        }
        $message = 'Yeni onay kodu e-posta adresinize gönderildi.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'resend') {
        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('E-posta ve 6 haneli onay kodunu kontrol edin.');
        }

        $stmt = mx_pdo()->prepare(
            'SELECT id FROM customers
             WHERE email = :email
               AND email_verification_code = :code
               AND is_active = 0
               AND email_verification_expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->execute([':email' => $email, ':code' => $code]);
        $customerId = (int) ($stmt->fetchColumn() ?: 0);
        if ($customerId <= 0) {
            throw new RuntimeException('Onay kodu geçersiz veya süresi dolmuş.');
        }

        mx_pdo()->prepare(
            'UPDATE customers
             SET is_active = 1, email_verified_at = NOW(), email_verification_code = NULL,
                 email_verification_token = NULL, email_verification_expires_at = NULL
             WHERE id = :id'
        )->execute([':id' => $customerId]);
        $message = 'Hesabınız aktifleştirildi. Artık giriş yapabilirsiniz.';
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    mx_log_error('customer verification failed', $exception, ['email' => $email]);
}

mx_account_header('Hesap Onayı', 'login');
?>
<section class="account-auth">
  <div>
    <p class="eyebrow">MyExpress Hesabım</p>
    <h1>E-posta onayı</h1>
    <p>Üyeliğinizi aktifleştirmek için e-posta adresinize gönderilen 6 haneli onay kodunu girin.</p>
  </div>
  <form class="account-card account-form" method="post">
    <?= mx_csrf_field() ?>
    <h2>Hesabı aktifleştir</h2>
    <?php mx_account_flash($message, $error); ?>
    <label>E-posta<input type="email" name="email" value="<?= mx_h($email) ?>" required autocomplete="email"></label>
    <label>Onay kodu<input name="code" required inputmode="numeric" maxlength="6" placeholder="6 haneli kod"></label>
    <button class="btn btn-primary" type="submit">Hesabı Aktifleştir</button>
    <button class="btn btn-secondary" type="submit" name="action" value="resend" formnovalidate>Onay Kodunu Tekrar Gönder</button>
    <p class="account-muted">Kod süresi dolduysa aynı e-posta için yeni kod gönderebilirsiniz.</p>
    <p class="account-muted">Hesabınız aktif mi? <a href="giris.php">Giriş yapın</a>.</p>
  </form>
</section>
<?php mx_account_footer(); ?>
