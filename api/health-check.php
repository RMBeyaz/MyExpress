<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$checks = [
    'ok' => true,
    'php_version' => PHP_VERSION,
    'config_path' => MYEXPRESS_CONFIG_PATH,
    'config' => [
        'exists' => is_file(MYEXPRESS_CONFIG_PATH),
        'readable' => is_readable(MYEXPRESS_CONFIG_PATH),
        'loaded' => false,
        'keys' => [
            'db_host' => false,
            'db_name' => false,
            'db_user' => false,
            'db_pass' => false,
            'mail_to' => false,
            'panel_user' => false,
            'panel_pass_or_hash' => false,
        ],
    ],
    'extensions' => [
        'pdo' => extension_loaded('PDO'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
    ],
    'database' => [
        'connected' => false,
        'driver' => null,
        'error_code' => null,
        'error_hint' => null,
    ],
    'tables' => [
        'courier_requests' => false,
        'request_status_logs' => false,
        'request_audit_logs' => false,
        'pricing_settings' => false,
        'panel_users' => false,
    ],
    'columns' => [
        'courier_requests.distance_km' => false,
        'courier_requests.pickup_city' => false,
        'courier_requests.pickup_district' => false,
        'courier_requests.pickup_road' => false,
        'courier_requests.pickup_building_no' => false,
        'courier_requests.dropoff_city' => false,
        'courier_requests.dropoff_district' => false,
        'courier_requests.dropoff_road' => false,
        'courier_requests.dropoff_building_no' => false,
    ],
    'write_test' => [
        'requested' => isset($_GET['write']) && $_GET['write'] === '1',
        'ok' => null,
        'error_code' => null,
        'error_hint' => null,
    ],
    'pricing' => [
        'settings_loaded' => false,
        'service_count' => 0,
        'package_count' => 0,
    ],
];

try {
    $config = mx_config();
    $checks['config']['loaded'] = true;
    $checks['config']['keys']['db_host'] = !empty($config['db_host']);
    $checks['config']['keys']['db_name'] = !empty($config['db_name']);
    $checks['config']['keys']['db_user'] = !empty($config['db_user']);
    $checks['config']['keys']['db_pass'] = array_key_exists('db_pass', $config) && (string) $config['db_pass'] !== '';
    $checks['config']['keys']['mail_to'] = !empty($config['mail_to']);
    $checks['config']['keys']['panel_user'] = !empty($config['panel_user']);
    $checks['config']['keys']['panel_pass_or_hash'] = !empty($config['panel_pass']) || !empty($config['panel_pass_hash']);

    $pdo = mx_pdo();
    $checks['database']['connected'] = true;
    $checks['database']['driver'] = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    foreach (array_keys($checks['tables']) as $table) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);
        $checks['tables'][$table] = (int) $stmt->fetchColumn() === 1;
    }
    $checks['columns']['courier_requests.distance_km'] = mx_column_exists('courier_requests', 'distance_km');
    foreach (['pickup_city', 'pickup_district', 'pickup_road', 'pickup_building_no', 'dropoff_city', 'dropoff_district', 'dropoff_road', 'dropoff_building_no'] as $column) {
        $checks['columns']['courier_requests.' . $column] = mx_column_exists('courier_requests', $column);
    }
    if ($checks['tables']['pricing_settings']) {
        $pricing = mx_pricing_settings();
        $checks['pricing']['settings_loaded'] = true;
        $checks['pricing']['service_count'] = count($pricing['services']);
        $checks['pricing']['package_count'] = count($pricing['packages']);
    }

    if ($checks['write_test']['requested']) {
        try {
            $pdo->beginTransaction();
            $trackingCode = 'MXTEST' . date('His') . strtoupper(bin2hex(random_bytes(2)));
            $stmt = $pdo->prepare(
                'INSERT INTO courier_requests (
                    tracking_code, status, pickup, pickup_lat, pickup_lng, pickup_street,
                    dropoff, dropoff_lat, dropoff_lng, dropoff_street,
                    service, service_label, package_type, package_label, delivery_time, note, price,
                    sender_name, sender_phone, sender_email, sender_tckn,
                    recipient_name, recipient_phone, recipient_email, recipient_tckn,
                    service_agreement_accepted, kvkk_accepted, ip_address, user_agent
                ) VALUES (
                    :tracking_code, :status, :pickup, :pickup_lat, :pickup_lng, :pickup_street,
                    :dropoff, :dropoff_lat, :dropoff_lng, :dropoff_street,
                    :service, :service_label, :package_type, :package_label, :delivery_time, :note, :price,
                    :sender_name, :sender_phone, :sender_email, :sender_tckn,
                    :recipient_name, :recipient_phone, :recipient_email, :recipient_tckn,
                    :service_agreement_accepted, :kvkk_accepted, :ip_address, :user_agent
                )'
            );
            $stmt->execute([
                ':tracking_code' => $trackingCode,
                ':status' => 'new',
                ':pickup' => 'Test Alim',
                ':pickup_lat' => 41.0000000,
                ':pickup_lng' => 29.0000000,
                ':pickup_street' => 'Test alim adresi',
                ':dropoff' => 'Test Teslim',
                ':dropoff_lat' => 41.0100000,
                ':dropoff_lng' => 29.0100000,
                ':dropoff_street' => 'Test teslim adresi',
                ':service' => 'normal',
                ':service_label' => 'Motorlu',
                ':package_type' => 'evrak',
                ':package_label' => 'Evrak',
                ':delivery_time' => 'En kisa surede',
                ':note' => 'Health-check rollback test',
                ':price' => '250 TL',
                ':sender_name' => 'Test Gonderici',
                ':sender_phone' => '05000000000',
                ':sender_email' => 'test@example.com',
                ':sender_tckn' => '31966836068',
                ':recipient_name' => 'Test Alici',
                ':recipient_phone' => '05000000001',
                ':recipient_email' => 'test@example.com',
                ':recipient_tckn' => '31966836068',
                ':service_agreement_accepted' => 1,
                ':kvkk_accepted' => 1,
                ':ip_address' => '127.0.0.1',
                ':user_agent' => 'health-check',
            ]);

            $requestId = (int) $pdo->lastInsertId();
            $logStmt = $pdo->prepare('INSERT INTO request_status_logs (request_id, status, note) VALUES (:id, :status, :note)');
            $logStmt->execute([
                ':id' => $requestId,
                ':status' => 'new',
                ':note' => 'Health-check rollback test',
            ]);
            $pdo->rollBack();
            $checks['write_test']['ok'] = true;
        } catch (Throwable $writeError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $checks['ok'] = false;
            $checks['write_test']['ok'] = false;
            $checks['write_test']['error_hint'] = 'Test kaydi yazilamadi. Server error_log detay verir.';
            if ($writeError instanceof PDOException) {
                $errorInfo = $writeError->errorInfo ?? [];
                $checks['write_test']['error_code'] = isset($errorInfo[1]) ? (string) $errorInfo[1] : (string) $writeError->getCode();
            }
            mx_log_error('health-check write test failed', $writeError);
        }
    }
} catch (Throwable $error) {
    $checks['ok'] = false;
    $checks['error'] = 'Health-check tamamlanamadi. Detay icin server error_log kontrol edilmeli.';

    if ($error instanceof PDOException) {
        $errorInfo = $error->errorInfo ?? [];
        $driverCode = isset($errorInfo[1]) ? (string) $errorInfo[1] : (string) $error->getCode();
        $checks['database']['error_code'] = $driverCode;
        switch ($driverCode) {
            case '1044':
                $checks['database']['error_hint'] = 'DB kullanicisinin veritabanina yetkisi yok. cPanel MySQL Databases > Add User To Database > ALL PRIVILEGES kontrol edilmeli.';
                break;
            case '1045':
                $checks['database']['error_hint'] = 'DB kullanici adi veya sifresi hatali olabilir. Config db_user/db_pass ve cPanel kullanici sifresi kontrol edilmeli.';
                break;
            case '1049':
                $checks['database']['error_hint'] = 'Veritabani adi bulunamadi. Config db_name cPanelde gorunen tam adla ayni olmali.';
                break;
            case '2002':
            case '2003':
                $checks['database']['error_hint'] = 'DB hosta ulasilamiyor. cPanel ortaminda db_host genelde localhost olmali.';
                break;
            default:
                $checks['database']['error_hint'] = 'DB baglantisi kurulamadi. Server error_log detay verir.';
                break;
        }
    }

    mx_log_error('health-check failed', $error);
}

foreach ($checks['config']['keys'] as $key => $value) {
    if ($value === false && in_array($key, ['db_host', 'db_name', 'db_user', 'db_pass'], true)) {
        $checks['ok'] = false;
    }
}

foreach ($checks['extensions'] as $enabled) {
    if (!$enabled) {
        $checks['ok'] = false;
    }
}

if (!$checks['database']['connected'] || in_array(false, $checks['tables'], true)) {
    $checks['ok'] = false;
}

if (in_array(false, $checks['columns'], true)) {
    $checks['ok'] = false;
}

mx_json($checks, $checks['ok'] ? 200 : 500);
