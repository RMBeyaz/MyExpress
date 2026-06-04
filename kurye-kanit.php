<?php
declare(strict_types=1);

require __DIR__ . '/api/bootstrap.php';
mx_security_headers();

$proofId = (int) ($_GET['id'] ?? 0);
$requestId = (int) ($_GET['request'] ?? 0);
$token = mx_clean_string($_GET['token'] ?? '', 128);
$request = mx_courier_task_request($requestId, $token);

if (!$request || !mx_table_exists('courier_delivery_proofs')) {
    http_response_code(404);
    echo 'Kanıt bulunamadı.';
    exit;
}

$stmt = mx_pdo()->prepare(
    'SELECT id, request_id, courier_id, file_name, mime_type
     FROM courier_delivery_proofs
     WHERE id = :id AND request_id = :request_id AND courier_id = :courier_id
     LIMIT 1'
);
$stmt->execute([
    ':id' => $proofId,
    ':request_id' => (int) $request['id'],
    ':courier_id' => (int) $request['assigned_courier_id'],
]);
$proof = $stmt->fetch();
if (!$proof) {
    http_response_code(404);
    echo 'Kanıt bulunamadı.';
    exit;
}

mx_stream_courier_proof($proof);
