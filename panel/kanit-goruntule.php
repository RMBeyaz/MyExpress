<?php
declare(strict_types=1);

require __DIR__ . '/../api/bootstrap.php';
mx_secure_session_start();
mx_panel_require_login();

$proofId = (int) ($_GET['id'] ?? 0);
if ($proofId <= 0 || !mx_table_exists('courier_delivery_proofs')) {
    http_response_code(404);
    echo 'Kanıt bulunamadı.';
    exit;
}

$stmt = mx_pdo()->prepare(
    'SELECT id, request_id, courier_id, file_name, mime_type
     FROM courier_delivery_proofs
     WHERE id = :id LIMIT 1'
);
$stmt->execute([':id' => $proofId]);
$proof = $stmt->fetch();
if (!$proof) {
    http_response_code(404);
    echo 'Kanıt bulunamadı.';
    exit;
}

mx_stream_courier_proof($proof);
