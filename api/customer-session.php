<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/bootstrap.php';

if (!mx_customer_is_logged_in()) {
    mx_json(['ok' => true, 'loggedIn' => false]);
}

mx_json([
    'ok' => true,
    'loggedIn' => true,
    'customer' => [
        'name' => mx_customer_name(),
        'email' => mx_customer_email(),
        'phone' => mx_customer_phone(),
        'tckn' => mx_customer_tckn(),
    ],
]);
