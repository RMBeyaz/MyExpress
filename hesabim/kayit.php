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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mx_table_exists('customers')) {
            throw new RuntimeException('Üyelik tabloları henüz kurulmamış. migrations/007_customer_portal.sql çalıştırılmalı.');
        }

        $fullName = mx_clean_string($_POST['full_name'] ?? '', 120);
        $email = mx_clean_string($_POST['email'] ?? '', 160);
        $phone = mx_clean_string($_POST['phone'] ?? '', 40);
        $password = (string) ($_POST['password'] ?? '');
        $kvkk = isset($_POST['kvkk']);

        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
            throw new RuntimeException('Ad soyad, telefon ve geçerli e-posta zorunludur.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Şifre en az 8 karakter olmalı.');
        }
        if (!$kvkk) {
            throw new RuntimeException('KVKK bilgilendirmesini onaylamalısınız.');
        }

        $stmt = mx_pdo()->prepare(
            'INSERT INTO customers (full_name, email, phone, password_hash, is_active)
             VALUES (:full_name, :email, :phone, :password_hash, 1)'
        );
        $stmt->execute([
            ':full_name' => $fullName,
            ':email' => $email,
            ':phone' => $phone,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        mx_customer_login($email, $password);
        header('Location: index.php');
        exit;
    } catch (Throwable $errorObject) {
        $error = $errorObject instanceof PDOException ? 'Bu e-posta ile kayıtlı bir hesap olabilir.' : $errorObject->getMessage();
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
    <?php mx_account_flash('', $error); ?>
    <label>Ad soyad<input name="full_name" required autocomplete="name"></label>
    <label>E-posta<input type="email" name="email" required autocomplete="email"></label>
    <label>Telefon<input type="tel" name="phone" required autocomplete="tel"></label>
    <label>Şifre<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label class="account-check"><input type="checkbox" name="kvkk" required><span><a href="../kvkk-politikasi.html" target="_blank">KVKK Aydınlatma Metni</a>'ni okudum ve onaylıyorum.</span></label>
    <button class="btn btn-primary" type="submit">Üye Ol</button>
    <p class="account-muted">Zaten hesabınız var mı? <a href="giris.php">Giriş yapın</a>.</p>
  </form>
</section>
<?php mx_account_footer(); ?>
