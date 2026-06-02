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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mx_clean_string($_POST['email'] ?? '', 160);
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'E-posta ve şifre girin.';
    } elseif (mx_customer_login($email, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Giriş bilgileri hatalı veya hesap pasif. Yeni üye olduysanız e-posta onay kodunu kontrol edin.';
    }
}

mx_account_header('Müşteri Girişi', 'login');
?>
<section class="account-auth">
  <div>
    <p class="eyebrow">MyExpress Hesabım</p>
    <h1>Müşteri girişi</h1>
    <p>Kayıtlı adreslerinizle daha hızlı talep oluşturabilir, geçmiş gönderilerinizi ve faturalarınızı tek ekrandan takip edebilirsiniz.</p>
  </div>
  <form class="account-card account-form" method="post">
    <?= mx_csrf_field() ?>
    <h2>Giriş yap</h2>
    <?php mx_account_flash('', $error); ?>
    <label>E-posta<input type="email" name="email" required autocomplete="email"></label>
    <label>Şifre<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary" type="submit">Giriş Yap</button>
    <p class="account-muted">Hesabınız yok mu? <a href="kayit.php">Üye olun</a>. Kodunuz varsa <a href="onay.php">hesabınızı aktifleştirin</a>.</p>
  </form>
</section>
<?php mx_account_footer(); ?>
