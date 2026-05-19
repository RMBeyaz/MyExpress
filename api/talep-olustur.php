<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mx_json(['ok' => false, 'message' => 'Bu endpoint sadece POST kabul eder.'], 405);
}

try {
    $stage = 'payload';
    $payload = mx_post_json();

    $required = [
        'pickup' => 'Alim mahallesi',
        'dropoff' => 'Teslim mahallesi',
        'pickupStreet' => 'Alim acik adresi',
        'dropoffStreet' => 'Teslim acik adresi',
        'senderName' => 'Gonderici ad soyad',
        'senderPhone' => 'Gonderici telefon',
        'senderTckn' => 'Gonderici T.C. kimlik no',
        'recipientName' => 'Alici ad soyad',
        'recipientPhone' => 'Alici telefon',
        'service' => 'Hizmet tipi',
        'packageType' => 'Paket tipi',
        'price' => 'Gonderi ucreti',
    ];

    foreach ($required as $key => $label) {
        if (!isset($payload[$key]) || trim((string) $payload[$key]) === '') {
            mx_json(['ok' => false, 'message' => $label . ' zorunludur.'], 422);
        }
    }

    if (empty($payload['serviceAgreement']) || empty($payload['kvkkConsent'])) {
        mx_json(['ok' => false, 'message' => 'Sozlesme ve KVKK onaylari zorunludur.'], 422);
    }

    $senderTckn = preg_replace('/\D/', '', (string) $payload['senderTckn']);
    $recipientTckn = preg_replace('/\D/', '', (string) ($payload['recipientTckn'] ?? ''));

    if (!mx_valid_tckn($senderTckn)) {
        mx_json(['ok' => false, 'message' => 'Gecerli gonderici T.C. kimlik numarasi girin.'], 422);
    }

    if ($recipientTckn !== '' && !mx_valid_tckn($recipientTckn)) {
        mx_json(['ok' => false, 'message' => 'Alici T.C. kimlik numarasi girildiyse gecerli olmali.'], 422);
    }

    $deliveryTime = mx_clean_string($payload['deliveryTime'] ?? '', 80);
    $deliveryDate = mx_clean_string($payload['deliveryDate'] ?? '', 10);
    $deliveryStartTime = mx_clean_string($payload['deliveryStartTime'] ?? '', 5);
    $deliveryEndTime = mx_clean_string($payload['deliveryEndTime'] ?? '', 5);

    if (in_array($deliveryTime, ['Belirli saat aralığı', 'İleri tarihli teslimat'], true)) {
        if (!preg_match('/^\d{2}:\d{2}$/', $deliveryStartTime) || !preg_match('/^\d{2}:\d{2}$/', $deliveryEndTime)) {
            mx_json(['ok' => false, 'message' => 'Teslimat saat araligi zorunludur.'], 422);
        }
        if ($deliveryStartTime >= $deliveryEndTime) {
            mx_json(['ok' => false, 'message' => 'Teslimat bitis saati baslangictan sonra olmali.'], 422);
        }
    }

    if ($deliveryTime === 'İleri tarihli teslimat') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            mx_json(['ok' => false, 'message' => 'Teslimat tarihi zorunludur.'], 422);
        }
        if ($deliveryDate < date('Y-m-d')) {
            mx_json(['ok' => false, 'message' => 'Teslimat tarihi bugunden eski olamaz.'], 422);
        }
        $deliveryTime = $deliveryTime . ': ' . $deliveryDate . ' ' . $deliveryStartTime . ' - ' . $deliveryEndTime;
    } elseif ($deliveryTime === 'Belirli saat aralığı') {
        $deliveryTime = $deliveryTime . ': ' . $deliveryStartTime . ' - ' . $deliveryEndTime;
    }

    $stage = 'db-connect';
    $trackingCode = 'MXTMP' . strtoupper(bin2hex(random_bytes(5)));
    $priceResult = mx_calculate_price($payload);
    $pdo = mx_pdo();
    $pdo->beginTransaction();

    $stage = 'insert-request';
    $stmt = $pdo->prepare(
        'INSERT INTO courier_requests (
            tracking_code, status, pickup, pickup_lat, pickup_lng, pickup_street,
            dropoff, dropoff_lat, dropoff_lng, dropoff_street,
            service, service_label, package_type, package_label, delivery_time, note, price, distance_km,
            sender_name, sender_phone, sender_email, sender_tckn,
            recipient_name, recipient_phone, recipient_email, recipient_tckn,
            service_agreement_accepted, kvkk_accepted, ip_address, user_agent
        ) VALUES (
            :tracking_code, :status, :pickup, :pickup_lat, :pickup_lng, :pickup_street,
            :dropoff, :dropoff_lat, :dropoff_lng, :dropoff_street,
            :service, :service_label, :package_type, :package_label, :delivery_time, :note, :price, :distance_km,
            :sender_name, :sender_phone, :sender_email, :sender_tckn,
            :recipient_name, :recipient_phone, :recipient_email, :recipient_tckn,
            :service_agreement_accepted, :kvkk_accepted, :ip_address, :user_agent
        )'
    );

    $stmt->execute([
        ':tracking_code' => $trackingCode,
        ':status' => 'new',
        ':pickup' => mx_clean_string($payload['pickup'], 255),
        ':pickup_lat' => is_numeric($payload['pickupLat'] ?? null) ? (float) $payload['pickupLat'] : null,
        ':pickup_lng' => is_numeric($payload['pickupLng'] ?? null) ? (float) $payload['pickupLng'] : null,
        ':pickup_street' => mx_clean_text($payload['pickupStreet'], 1000),
        ':dropoff' => mx_clean_string($payload['dropoff'], 255),
        ':dropoff_lat' => is_numeric($payload['dropoffLat'] ?? null) ? (float) $payload['dropoffLat'] : null,
        ':dropoff_lng' => is_numeric($payload['dropoffLng'] ?? null) ? (float) $payload['dropoffLng'] : null,
        ':dropoff_street' => mx_clean_text($payload['dropoffStreet'], 1000),
        ':service' => mx_clean_string($payload['service'], 40),
        ':service_label' => mx_clean_string($payload['serviceLabel'] ?? $payload['service'], 80),
        ':package_type' => mx_clean_string($payload['packageType'], 40),
        ':package_label' => mx_clean_string($payload['packageLabel'] ?? $payload['packageType'], 80),
        ':delivery_time' => $deliveryTime,
        ':note' => mx_clean_text($payload['note'] ?? '', 1000),
        ':price' => $priceResult['price'],
        ':distance_km' => $priceResult['distance_km'],
        ':sender_name' => mx_clean_string($payload['senderName'], 120),
        ':sender_phone' => mx_clean_string($payload['senderPhone'], 40),
        ':sender_email' => mx_clean_string($payload['senderEmail'] ?? '', 160),
        ':sender_tckn' => $senderTckn,
        ':recipient_name' => mx_clean_string($payload['recipientName'], 120),
        ':recipient_phone' => mx_clean_string($payload['recipientPhone'], 40),
        ':recipient_email' => mx_clean_string($payload['recipientEmail'] ?? '', 160),
        ':recipient_tckn' => $recipientTckn,
        ':service_agreement_accepted' => 1,
        ':kvkk_accepted' => 1,
        ':ip_address' => mx_clean_string($_SERVER['REMOTE_ADDR'] ?? '', 45),
        ':user_agent' => mx_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
    ]);

    $requestId = (int) $pdo->lastInsertId();
    $trackingCode = mx_tracking_code_for_id($requestId);
    $pdo->prepare('UPDATE courier_requests SET tracking_code = :tracking_code WHERE id = :id')->execute([
        ':tracking_code' => $trackingCode,
        ':id' => $requestId,
    ]);

    $stage = 'insert-status-log';
    $logStmt = $pdo->prepare(
        'INSERT INTO request_status_logs (request_id, status, note) VALUES (:request_id, :status, :note)'
    );
    $logStmt->execute([
        ':request_id' => $requestId,
        ':status' => 'new',
        ':note' => 'Talep web formundan olusturuldu.',
    ]);

    $pdo->commit();

    try {
        $config = mx_config();
        $mailTo = $config['mail_to'] ?? 'info@myexpress.com.tr';
        $subject = 'Yeni MyExpress kurye talebi: ' . $trackingCode;
        $message = implode("\n", [
            'Yeni kurye talebi olusturuldu.',
            'Talep No: ' . $trackingCode,
            'Gonderi ucreti: ' . $priceResult['price'],
            'Alim: ' . mx_clean_string($payload['pickup'], 255),
            'Teslim: ' . mx_clean_string($payload['dropoff'], 255),
            'Gonderici: ' . mx_clean_string($payload['senderName'], 120) . ' - ' . mx_clean_string($payload['senderPhone'], 40),
            'Alici: ' . mx_clean_string($payload['recipientName'], 120) . ' - ' . mx_clean_string($payload['recipientPhone'], 40),
            'Panel: https://myexpress.com.tr/kurye/panel/',
        ]);

        if (function_exists('mail')) {
            $sent = @mail($mailTo, $subject, $message, 'From: MyExpress <info@myexpress.com.tr>');
            if (!$sent) {
                error_log('[MyExpress] talep mail gonderilemedi | tracking_code=' . $trackingCode);
            }
        } else {
            error_log('[MyExpress] PHP mail fonksiyonu kapali | tracking_code=' . $trackingCode);
        }
    } catch (Throwable $mailError) {
        mx_log_error('talep mail failed', $mailError, ['tracking_code' => $trackingCode]);
    }

    mx_json([
        'ok' => true,
        'trackingCode' => $trackingCode,
        'redirect' => 'talep-basarili.html?no=' . rawurlencode($trackingCode),
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errorCode = 'REQUEST_FAILED';
    if (isset($stage)) {
        $errorCode = strtoupper(str_replace('-', '_', $stage)) . '_FAILED';
    }

    $extra = [
        'stage' => $stage ?? 'unknown',
        'payload_keys' => isset($payload) && is_array($payload) ? array_keys($payload) : [],
        'pickup' => $payload['pickup'] ?? null,
        'dropoff' => $payload['dropoff'] ?? null,
        'service' => $payload['service'] ?? null,
        'packageType' => $payload['packageType'] ?? null,
    ];

    if ($error instanceof PDOException) {
        $extra['pdo_code'] = (string) $error->getCode();
        $extra['pdo_error_info'] = $error->errorInfo ?? [];
        if (isset($error->errorInfo[1])) {
            $errorCode .= '_' . (string) $error->errorInfo[1];
        }
    }

    mx_log_error('talep olustur failed', $error, $extra);
    mx_json([
        'ok' => false,
        'message' => 'Talep olusturulurken teknik bir sorun olustu. Lutfen tekrar deneyin.',
        'code' => $errorCode,
    ], 500);
}
