<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mx_require_csrf();
}

if (mx_panel_is_logged_in()) {
    mx_audit_log(null, 'logout', 'Panel cikisi yapildi.');
}
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
