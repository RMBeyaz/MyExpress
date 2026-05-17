<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    mx_json([
        'ok' => true,
        'pricing' => mx_pricing_settings(),
    ]);
} catch (Throwable $error) {
    mx_log_error('pricing config failed', $error);
    mx_json(['ok' => false, 'message' => 'Fiyatlandirma bilgisi alinamadi.'], 500);
}
