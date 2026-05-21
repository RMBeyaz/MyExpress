<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../api/bootstrap.php';
mx_panel_require_user_manager();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 || !mx_table_exists('customer_invoices')) {
    http_response_code(404);
    echo 'Fatura bulunamadı.';
    exit;
}

$stmt = mx_pdo()->prepare('SELECT * FROM customer_invoices WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    echo 'Fatura bulunamadı.';
    exit;
}

mx_stream_invoice_pdf($invoice);
