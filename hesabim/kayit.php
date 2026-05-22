<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
require __DIR__ . '/_layout.php';

if (mx_customer_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mx_table_exists('customers')) {
            throw new RuntimeException('Üyelik tabloları henüz kurulmamış. migrations/007_customer_portal.sql çalıştırılmalı.');
        }

        $fullName = mx_clean_string($_POST['full_name'] ?? '', 120);
        $email = mx_clean_string($_POST['email'] ?? '', 160);
        $phone = mx_clean_string($_POST['phone'] ?? '', 40);
        $tckn = preg_replace('/\D/', '', (string) ($_POST['tckn'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $kvkk = isset($_POST['kvkk']);

        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
            throw new RuntimeException('Ad soyad, telefon ve geçerli e-posta zorunludur.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Şifre en az 8 karakter olmalı.');
        }
        if (!mx_valid_tckn($tckn)) {
            throw new RuntimeException('Geçerli T.C. kimlik numarası girin.');
        }
        if (!$kvkk) {
            throw new RuntimeException('KVKK bilgilendirmesini onaylamalısınız.');
        }

        $pdo = mx_pdo();
        $verificationCode = mx_customer_verification_code();
        $verificationToken = bin2hex(random_bytes(32));
        $hasVerifyColumns = mx_customer_verification_ready();
        $columns = 'full_name, email, phone, tckn, password_hash, is_active';
        $values = ':full_name, :email, :phone, :tckn, :password_hash, :is_active';
        $params = [
            ':full_name' => $fullName,
            ':email' => $email,
            ':phone' => $phone,
            ':tckn' => $tckn,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':is_active' => $hasVerifyColumns ? 0 : 1,
        ];
        if ($hasVerifyColumns) {
            $columns .= ', email_verification_code, email_verification_token, email_verification_expires_at';
            $values .= ', :email_verification_code, :email_verification_token, DATE_ADD(NOW(), INTERVAL 30 MINUTE)';
            $params[':email_verification_code'] = $verificationCode;
            $params[':email_verification_token'] = hash('sha256', $verificationToken);
        }

        $stmt = $pdo->prepare("INSERT INTO customers ({$columns}) VALUES ({$values})");
        $stmt->execute($params);

        if ($hasVerifyColumns) {
            $sent = mx_send_customer_verification_mail($email, $fullName, $verificationCode, $verificationToken);
            if (!$sent) {
                mx_log_error('customer register verification mail failed', new RuntimeException('verification mail send returned false'), ['email' => $email]);
            }
            header('Location: onay.php?email=' . rawurlencode($email));
            exit;
        }

        mx_customer_login($email, $password);
        header('Location: index.php');
        exit;
    } catch (Throwable $errorObject) {
        if ($errorObject instanceof PDOException && $errorObject->getCode() === '23000') {
            try {
                $email = mx_clean_string($_POST['email'] ?? '', 160);
                $stmt = mx_pdo()->prepare('SELECT id, is_active FROM customers WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $existing = $stmt->fetch();
                if ($existing && (int) $existing['is_active'] === 0 && mx_customer_verification_ready()) {
                    if (mx_refresh_customer_verification((int) $existing['id'])) {
                        header('Location: onay.php?email=' . rawurlencode($email) . '&resent=1');
                        exit;
                    }
                    $error = 'Hesabınız için yeni onay kodu üretildi ancak mail gönderilemedi. Lütfen panelde Mail Testi ve server error_log kontrol edin.';
                } else {
                    $error = 'Bu e-posta ile kayıtlı aktif bir hesap var. Giriş yapmayı deneyin.';
                }
            } catch (Throwable $resendError) {
                $error = 'Bu e-posta ile kayıtlı bir hesap olabilir.';
                mx_log_error('customer duplicate verification resend failed', $resendError, ['email' => $_POST['email'] ?? '']);
            }
        } else {
            $error = $errorObject->getMessage();
        }
        mx_log_error('customer register failed', $errorObject, ['email' => $_POST['email'] ?? '']);
    }
}

mx_account_header('Üye Ol', 'login');
?>
<section class="account-auth">
  <div>
    <p class="eyebrow">MyExpress Hesabım</p>
    <h1>Üye olun</h1>
    <p>Adreslerinizi kaydedin, kurye taleplerinizi hesabınızla ilişkilendirin ve geçmiş gönderilerinizi tek yerden görüntüleyin.</p>
  </div>
  <form class="account-card account-form" method="post">
    <h2>Hesap oluştur</h2>
    <?php mx_account_flash($message, $error); ?>
    <label>Ad soyad<input name="full_name" required autocomplete="name"></label>
    <label>E-posta<input type="email" name="email" required autocomplete="email"></label>
    <label>Telefon<input type="tel" name="phone" required autocomplete="tel"></label>
    <label>T.C. kimlik no<input name="tckn" required inputmode="numeric" maxlength="11" autocomplete="off"></label>
    <label>Şifre<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label class="account-check"><input type="checkbox" name="kvkk" required><span><a href="../kvkk-politikasi.html" target="_blank">KVKK Aydınlatma Metni</a>'ni okudum ve onaylıyorum.</span></label>
    <button class="btn btn-primary" type="submit">Üye Ol</button>
    <p class="account-muted">Zaten hesabınız var mı? <a href="giris.php">Giriş yapın</a>.</p>
  </form>
</section>
<?php mx_account_footer(); ?>
