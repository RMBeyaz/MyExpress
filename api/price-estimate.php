<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mx_json(['ok' => false, 'message' => 'Bu endpoint sadece POST kabul eder.'], 405);
}

try {
    $payload = mx_post_json();
    $price = mx_calculate_price($payload);
    mx_json(['ok' => true, 'price' => $price]);
} catch (Throwable $error) {
    mx_log_error('price estimate failed', $error);
    mx_json(['ok' => true, 'price' => mx_route_unavailable('route_failed', null)]);
}
