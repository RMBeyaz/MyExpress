<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';

mx_customer_logout();
header('Location: giris.php');
exit;
