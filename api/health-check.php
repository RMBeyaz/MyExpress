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

mx_json($checks, $checks['ok'] ? 200 : 500);
