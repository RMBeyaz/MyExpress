<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
mx_panel_require_login();

$requestId = (int) ($_GET['id'] ?? 0);
if ($requestId <= 0 || !mx_table_exists('couriers') || !mx_column_exists('courier_requests', 'assigned_courier_id')) {
    header('Location: index.php?notice=courier_missing');
    exit;
}

$stmt = mx_pdo()->prepare(
    'SELECT cr.*, c.id AS courier_id, c.full_name AS courier_name, c.phone AS courier_phone'
    . ' FROM courier_requests cr'
    . ' INNER JOIN couriers c ON c.id = cr.assigned_courier_id AND c.is_active = 1'
    . ' WHERE cr.id = :id LIMIT 1'
);
$stmt->execute([':id' => $requestId]);
$request = $stmt->fetch();

if (!$request || trim((string) ($request['courier_phone'] ?? '')) === '') {
    header('Location: index.php?notice=courier_missing');
    exit;
}

try {
    $pickupAddress = trim(implode(', ', array_filter([
        (string) ($request['pickup'] ?? ''),
        (string) ($request['pickup_street'] ?? ''),
    ])));
    $dropoffAddress = trim(implode(', ', array_filter([
        (string) ($request['dropoff'] ?? ''),
        (string) ($request['dropoff_street'] ?? ''),
    ])));
    $taskUrl = mx_courier_task_url((int) $request['id'], (int) $request['courier_id']);
    $message = "MyExpress kurye görevi\n"
        . 'Talep No: ' . $request['tracking_code'] . "\n"
        . 'Gönderici: ' . $request['sender_name'] . ' - ' . $request['sender_phone'] . "\n"
        . 'Alım adresi: ' . $pickupAddress . "\n"
        . 'Alıcı: ' . $request['recipient_name'] . ' - ' . $request['recipient_phone'] . "\n"
        . 'Teslim adresi: ' . $dropoffAddress . "\n\n"
        . "Teslim alma ve teslim işlemleri için görev bağlantısı:\n"
        . $taskUrl;

    mx_audit_log($requestId, 'courier_dispatch_link', $request['courier_name'] . ' kuryesine görev bağlantısı hazırlandı.');
    header('Location: ' . mx_whatsapp_url((string) $request['courier_phone'], $message));
    exit;
} catch (Throwable $exception) {
    mx_log_error('courier dispatch link failed', $exception, ['request_id' => $requestId]);
    header('Location: index.php?notice=courier_link_failed');
    exit;
}
