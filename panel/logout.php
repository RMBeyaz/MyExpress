<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';

if (mx_panel_is_logged_in()) {
    mx_audit_log(null, 'logout', 'Panel cikisi yapildi.');
}
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
