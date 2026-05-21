<?php
declare(strict_types=1);

const MYEXPRESS_CONFIG_PATH = '/home/myexpresscom/myexpress-config.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function mx_log_error(string $context, Throwable $error, array $extra = [])
{
    $safeExtra = $extra;
    foreach (['db_pass', 'password', 'panel_pass', 'panel_pass_hash', 'senderTckn', 'recipientTckn', 'sender_tckn', 'recipient_tckn'] as $key) {
        if (isset($safeExtra[$key])) {
            $safeExtra[$key] = '[redacted]';
        }
    }

    error_log(sprintf(
        '[MyExpress] %s | %s in %s:%d | extra=%s | trace=%s',
        $context,
        $error->getMessage(),
        $error->getFile(),
        $error->getLine(),
        json_encode($safeExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $error->getTraceAsString()
    ));
}

function mx_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    if (!is_file(MYEXPRESS_CONFIG_PATH)) {
        throw new RuntimeException('Config dosyasi bulunamadi.');
    }

    $loaded = require MYEXPRESS_CONFIG_PATH;
    if (!is_array($loaded)) {
        throw new RuntimeException('Config dosyasi gecersiz.');
    }

    $config = $loaded;
    return $config;
}

function mx_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = mx_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'] ?? 'localhost',
        $config['db_name'] ?? ''
    );

    $pdo = new PDO($dsn, $config['db_user'] ?? '', $config['db_pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function mx_json(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mx_post_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        mx_json(['ok' => false, 'message' => 'Gecersiz istek.'], 400);
    }

    return $payload;
}

function mx_clean_string($value, int $max = 255): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function mx_clean_text($value, int $max = 2000): string
{
    $value = trim((string) $value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function mx_valid_tckn(string $value): bool
{
    if (!preg_match('/^[1-9][0-9]{10}$/', $value)) {
        return false;
    }

    $digits = array_map('intval', str_split($value));
    $oddSum = $digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8];
    $evenSum = $digits[1] + $digits[3] + $digits[5] + $digits[7];
    $digit10 = (($oddSum * 7) - $evenSum) % 10;
    $digit11 = array_sum(array_slice($digits, 0, 10)) % 10;

    return $digit10 === $digits[9] && $digit11 === $digits[10];
}

function mx_tracking_code(): string
{
    return 'MX' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
}

function mx_tracking_code_for_id(int $id): string
{
    return sprintf('MX%s-%05d', date('ymd'), $id);
}

function mx_roles(): array
{
    return [
        'admin' => 'Admin',
        'manager' => 'Yönetici',
        'staff' => 'Çalışan',
    ];
}

function mx_role_label(string $role): string
{
    $roles = mx_roles();
    return $roles[$role] ?? $role;
}

function mx_panel_login(string $username, string $password): bool
{
    try {
        if (mx_table_exists('panel_users')) {
            $stmt = mx_pdo()->prepare('SELECT id, username, full_name, role, password_hash, is_active FROM panel_users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            if ($user && (int) $user['is_active'] === 1 && password_verify($password, (string) $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['mx_panel_auth'] = true;
                $_SESSION['mx_panel_user'] = (string) $user['username'];
                $_SESSION['mx_panel_user_id'] = (int) $user['id'];
                $_SESSION['mx_panel_full_name'] = (string) $user['full_name'];
                $_SESSION['mx_panel_role'] = (string) $user['role'];
                $_SESSION['mx_panel_last_activity'] = time();

                mx_pdo()->prepare('UPDATE panel_users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => (int) $user['id']]);
                mx_audit_log(null, 'login', 'Panel girisi yapildi.');
                return true;
            }
        }
    } catch (Throwable $error) {
        mx_log_error('panel db login failed', $error, ['username' => $username]);
    }

    $config = mx_config();
    $panelUser = (string) ($config['panel_user'] ?? '');
    $panelHash = (string) ($config['panel_pass_hash'] ?? '');
    $panelPass = (string) ($config['panel_pass'] ?? '');
    $passwordOk = $panelHash !== ''
        ? password_verify($password, $panelHash)
        : ($panelPass !== '' && hash_equals($panelPass, $password));

    if ($panelUser !== '' && hash_equals($panelUser, $username) && $passwordOk) {
        session_regenerate_id(true);
        $_SESSION['mx_panel_auth'] = true;
        $_SESSION['mx_panel_user'] = $panelUser;
        $_SESSION['mx_panel_user_id'] = null;
        $_SESSION['mx_panel_full_name'] = 'Sistem Admin';
        $_SESSION['mx_panel_role'] = 'admin';
        $_SESSION['mx_panel_last_activity'] = time();
        mx_audit_log(null, 'login', 'Config admin ile panel girisi yapildi.');
        return true;
    }

    return false;
}

function mx_panel_is_logged_in(): bool
{
    if (!isset($_SESSION['mx_panel_auth']) || $_SESSION['mx_panel_auth'] !== true) {
        return false;
    }

    $ttl = (int) (mx_config()['panel_session_ttl'] ?? 7200);
    $lastActivity = (int) ($_SESSION['mx_panel_last_activity'] ?? 0);

    if ($lastActivity > 0 && time() - $lastActivity > $ttl) {
        $_SESSION = [];
        session_destroy();
        return false;
    }

    $_SESSION['mx_panel_last_activity'] = time();
    return true;
}

function mx_panel_require_login()
{
    if (!mx_panel_is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function mx_customer_login(string $email, string $password): bool
{
    try {
        if (!mx_table_exists('customers')) {
            return false;
        }

        $selectColumns = 'id, full_name, email, phone, password_hash, is_active';
        if (mx_column_exists('customers', 'tckn')) {
            $selectColumns .= ', tckn';
        }

        $stmt = mx_pdo()->prepare(
            "SELECT {$selectColumns} FROM customers WHERE email = :email LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $customer = $stmt->fetch();

        if (!$customer || (int) $customer['is_active'] !== 1 || !password_verify($password, (string) $customer['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['mx_customer_auth'] = true;
        $_SESSION['mx_customer_id'] = (int) $customer['id'];
        $_SESSION['mx_customer_email'] = (string) $customer['email'];
        $_SESSION['mx_customer_name'] = (string) $customer['full_name'];
        $_SESSION['mx_customer_phone'] = (string) ($customer['phone'] ?? '');
        $_SESSION['mx_customer_tckn'] = (string) ($customer['tckn'] ?? '');
        $_SESSION['mx_customer_last_activity'] = time();

        mx_pdo()->prepare('UPDATE customers SET last_login_at = NOW() WHERE id = :id')->execute([
            ':id' => (int) $customer['id'],
        ]);

        return true;
    } catch (Throwable $error) {
        mx_log_error('customer login failed', $error, ['email' => $email]);
        return false;
    }
}

function mx_customer_is_logged_in(): bool
{
    if (!isset($_SESSION['mx_customer_auth']) || $_SESSION['mx_customer_auth'] !== true) {
        return false;
    }

    $ttl = (int) (mx_config()['customer_session_ttl'] ?? 86400);
    $lastActivity = (int) ($_SESSION['mx_customer_last_activity'] ?? 0);

    if ($lastActivity > 0 && time() - $lastActivity > $ttl) {
        mx_customer_logout();
        return false;
    }

    $_SESSION['mx_customer_last_activity'] = time();
    return true;
}

function mx_customer_require_login()
{
    if (!mx_customer_is_logged_in()) {
        header('Location: giris.php');
        exit;
    }
}

function mx_customer_logout(): void
{
    foreach ([
        'mx_customer_auth',
        'mx_customer_id',
        'mx_customer_email',
        'mx_customer_name',
        'mx_customer_phone',
        'mx_customer_tckn',
        'mx_customer_last_activity',
    ] as $key) {
        unset($_SESSION[$key]);
    }
}

function mx_customer_id(): ?int
{
    return mx_customer_is_logged_in() ? (int) $_SESSION['mx_customer_id'] : null;
}

function mx_customer_name(): string
{
    return (string) ($_SESSION['mx_customer_name'] ?? '');
}

function mx_customer_email(): string
{
    return (string) ($_SESSION['mx_customer_email'] ?? '');
}

function mx_customer_phone(): string
{
    return (string) ($_SESSION['mx_customer_phone'] ?? '');
}

function mx_customer_tckn(): string
{
    return (string) ($_SESSION['mx_customer_tckn'] ?? '');
}

function mx_statuses(): array
{
    return [
        'new' => 'Yeni',
        'called' => 'İşleme Alındı',
        'assigned' => 'Kurye Atandı',
        'picked_up' => 'Teslim Alındı',
        'delivered' => 'Teslim Edildi',
        'cancelled' => 'İptal',
    ];
}

function mx_status_label(string $status): string
{
    $statuses = mx_statuses();
    return $statuses[$status] ?? $status;
}

function mx_panel_user(): string
{
    return (string) ($_SESSION['mx_panel_user'] ?? 'panel');
}

function mx_panel_role(): string
{
    if (isset($_SESSION['mx_panel_role'])) {
        return (string) $_SESSION['mx_panel_role'];
    }

    $configUser = (string) (mx_config()['panel_user'] ?? '');
    if ($configUser !== '' && isset($_SESSION['mx_panel_user']) && hash_equals($configUser, (string) $_SESSION['mx_panel_user'])) {
        $_SESSION['mx_panel_role'] = 'admin';
        return 'admin';
    }

    return 'staff';
}

function mx_panel_is_admin(): bool
{
    return mx_panel_role() === 'admin';
}

function mx_panel_can_manage_users(): bool
{
    return in_array(mx_panel_role(), ['admin', 'manager'], true);
}

function mx_panel_can_manage_pricing(): bool
{
    return in_array(mx_panel_role(), ['admin', 'manager'], true);
}

function mx_panel_require_admin()
{
    mx_panel_require_login();
    if (!mx_panel_is_admin()) {
        http_response_code(403);
        echo 'Bu sayfa icin admin yetkisi gerekir.';
        exit;
    }
}

function mx_panel_require_user_manager()
{
    mx_panel_require_login();
    if (!mx_panel_can_manage_users()) {
        http_response_code(403);
        echo 'Bu sayfa icin yonetici yetkisi gerekir.';
        exit;
    }
}

function mx_panel_require_pricing_manager()
{
    mx_panel_require_login();
    if (!mx_panel_can_manage_pricing()) {
        http_response_code(403);
        echo 'Bu sayfa icin fiyatlandirma yetkisi gerekir.';
        exit;
    }
}

function mx_table_exists(string $table): bool
{
    $stmt = mx_pdo()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() === 1;
}

function mx_column_exists(string $table, string $column): bool
{
    $stmt = mx_pdo()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int) $stmt->fetchColumn() === 1;
}

function mx_audit_log(?int $requestId, string $action, string $details = ''): void
{
    try {
        if (!mx_table_exists('request_audit_logs')) {
            return;
        }

        $stmt = mx_pdo()->prepare(
            'INSERT INTO request_audit_logs (request_id, admin_user, action, details, ip_address, user_agent)
             VALUES (:request_id, :admin_user, :action, :details, :ip_address, :user_agent)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':admin_user' => mx_panel_user(),
            ':action' => $action,
            ':details' => $details,
            ':ip_address' => mx_clean_string($_SERVER['REMOTE_ADDR'] ?? '', 45),
            ':user_agent' => mx_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
        ]);
    } catch (Throwable $error) {
        mx_log_error('audit log failed', $error, ['request_id' => $requestId, 'action' => $action]);
    }
}

function mx_whatsapp_url(string $phone, string $message = ''): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10 && str_starts_with($digits, '5')) {
        $digits = '90' . $digits;
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = '9' . $digits;
    }

    return 'https://wa.me/' . $digits . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

function mx_invoice_absolute_path(string $filePath): ?string
{
    $filePath = trim($filePath);
    if ($filePath === '') {
        return null;
    }

    $root = dirname(__DIR__);
    $base = realpath($root . '/uploads/faturalar');
    if ($base === false) {
        return null;
    }

    $relative = preg_replace('#^https?://[^/]+/kurye/#i', '', $filePath) ?? $filePath;
    $relative = preg_replace('#^/kurye/#', '', $relative) ?? $relative;
    $relative = preg_replace('#^/+#', '', $relative) ?? $relative;
    $relative = preg_replace('#^uploads/faturalar/#', '', $relative) ?? $relative;
    $relative = str_replace('\\', '/', $relative);

    if ($relative === '' || str_contains($relative, '..')) {
        return null;
    }

    $target = realpath($base . '/' . $relative);
    if ($target === false || !str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return is_file($target) ? $target : null;
}

function mx_stream_invoice_pdf(array $invoice): void
{
    $path = mx_invoice_absolute_path((string) ($invoice['file_path'] ?? ''));
    if ($path === null) {
        http_response_code(404);
        echo 'Fatura dosyası bulunamadı.';
        exit;
    }

    $downloadName = mx_clean_string($invoice['original_file_name'] ?? '', 180);
    if ($downloadName === '') {
        $downloadName = mx_clean_string($invoice['title'] ?? 'myexpress-fatura.pdf', 180);
    }
    if (!str_ends_with(strtolower($downloadName), '.pdf')) {
        $downloadName .= '.pdf';
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function mx_default_pricing_settings(): array
{
    return [
        'services' => [
            'normal' => ['base' => 240.0, 'km' => 14.0, 'multiplier' => 1.0, 'label' => 'Motorlu Kurye'],
            'express' => ['base' => 320.0, 'km' => 17.0, 'multiplier' => 1.25, 'label' => 'Express Kurye'],
            'vip' => ['base' => 420.0, 'km' => 20.0, 'multiplier' => 1.55, 'label' => 'VIP Kurye'],
            'aracli' => ['base' => 650.0, 'km' => 28.0, 'multiplier' => 1.75, 'label' => 'Arabalı Kurye'],
            'eticaret' => ['base' => 260.0, 'km' => 13.0, 'multiplier' => 0.95, 'label' => 'E-Ticaret Teslimatı'],
        ],
        'packages' => [
            'evrak' => ['fee' => 0.0, 'label' => 'Evrak'],
            'zarf' => ['fee' => 0.0, 'label' => 'Zarf'],
            'kucuk' => ['fee' => 60.0, 'label' => 'Küçük paket'],
            'orta' => ['fee' => 120.0, 'label' => 'Orta paket'],
            'buyuk' => ['fee' => 220.0, 'label' => 'Büyük paket'],
            'hacimli' => ['fee' => 240.0, 'label' => 'Hacimli paket'],
            'motorDisi' => ['fee' => 430.0, 'label' => 'Motor çantasına sığmayan'],
        ],
        'rules' => [
            'routeMultiplier' => 1.28,
            'minSameAreaKm' => 4.0,
            'minDefaultKm' => 7.0,
            'bridgeFee' => 90.0,
            'roundTo' => 10.0,
            'homeMinFactor' => 0.92,
            'homeMaxFactor' => 1.08,
        ],
    ];
}

function mx_pricing_settings(): array
{
    $settings = mx_default_pricing_settings();

    try {
        if (!mx_table_exists('pricing_settings')) {
            return $settings;
        }

        $rows = mx_pdo()->query('SELECT setting_key, label, numeric_value FROM pricing_settings')->fetchAll();
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $value = (float) $row['numeric_value'];
            $label = (string) $row['label'];

            if (preg_match('/^service\.([^.]+)\.(base|km|multiplier)$/', $key, $matches)) {
                $service = $matches[1];
                $field = $matches[2];
                if (!isset($settings['services'][$service])) {
                    $settings['services'][$service] = ['base' => 0.0, 'km' => 0.0, 'multiplier' => 1.0, 'label' => $label];
                }
                $settings['services'][$service][$field] = $value;
                continue;
            }

            if (preg_match('/^package\.([^.]+)\.fee$/', $key, $matches)) {
                $package = $matches[1];
                if (!isset($settings['packages'][$package])) {
                    $settings['packages'][$package] = ['fee' => 0.0, 'label' => $label];
                }
                $settings['packages'][$package]['fee'] = $value;
                continue;
            }

            $ruleMap = [
                'rule.route_multiplier' => 'routeMultiplier',
                'rule.min_same_area_km' => 'minSameAreaKm',
                'rule.min_default_km' => 'minDefaultKm',
                'rule.bridge_fee' => 'bridgeFee',
                'rule.round_to' => 'roundTo',
                'rule.home_min_factor' => 'homeMinFactor',
                'rule.home_max_factor' => 'homeMaxFactor',
            ];

            if (isset($ruleMap[$key])) {
                $settings['rules'][$ruleMap[$key]] = $value;
            } elseif (isset($settings['rules'][$key])) {
                $settings['rules'][$key] = $value;
            }
        }
    } catch (Throwable $error) {
        mx_log_error('pricing settings fallback', $error);
    }

    return $settings;
}

function mx_distance_km(float $fromLat, float $fromLng, float $toLat, float $toLng): float
{
    $earthRadius = 6371;
    $latDelta = deg2rad($toLat - $fromLat);
    $lngDelta = deg2rad($toLng - $fromLng);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function mx_round_price(float $value, float $roundTo): float
{
    $roundTo = $roundTo > 0 ? $roundTo : 10.0;
    return round($value / $roundTo) * $roundTo;
}

function mx_route_unavailable(string $status = 'manual_required', ?string $provider = null): array
{
    return [
        'success' => false,
        'status' => 'manual_required',
        'price' => 'Operasyon teyidi gerekli',
        'distance_km' => null,
        'distance_type' => $status,
        'route_distance_km' => null,
        'route_duration_min' => null,
        'route_provider' => $provider,
        'route_status' => $status,
        'fallback_reason' => $status,
        'api_key_present' => null,
        'pickup_geocode_status' => null,
        'dropoff_geocode_status' => null,
    ];
}

function mx_route_unavailable_with_key_status(string $status, ?string $provider, bool $apiKeyPresent): array
{
    $fallback = mx_route_unavailable($status, $provider);
    $fallback['api_key_present'] = $apiKeyPresent;
    return $fallback;
}

function mx_geocode_istanbul_address_result(string $query): array
{
    $query = mx_clean_string($query, 500);
    if ($query === '') {
        return ['ok' => false, 'status' => 'empty_query'];
    }

    $searchQuery = preg_match('/istanbul/iu', $query) ? $query : $query . ', İstanbul';
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'format' => 'jsonv2',
        'limit' => '1',
        'countrycodes' => 'tr',
        'accept-language' => 'tr',
        'viewbox' => '28.01,41.65,29.95,40.72',
        'bounded' => '1',
        'q' => $searchQuery,
    ]);

    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'header' => 'User-Agent: MyExpressKurye/1.0 (info@myexpress.com.tr)' . "\r\n",
            ],
        ]);
        error_log('[MyExpress] geocode request | query=' . $query);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw)) {
            error_log('[MyExpress] geocode network_error | query=' . $query);
            return ['ok' => false, 'status' => 'geocode_network_error', 'query' => $query];
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            error_log('[MyExpress] geocode invalid_response | query=' . $query);
            return ['ok' => false, 'status' => 'geocode_invalid_response', 'query' => $query];
        }
        if (empty($data[0]['lat']) || empty($data[0]['lon'])) {
            error_log('[MyExpress] geocode no_result | query=' . $query);
            return ['ok' => false, 'status' => 'geocode_no_result', 'query' => $query];
        }
        error_log('[MyExpress] geocode ok | query=' . $query . ' | lat=' . $data[0]['lat'] . ' | lng=' . $data[0]['lon']);
        return [
            'ok' => true,
            'status' => 'ok',
            'query' => $query,
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
        ];
    } catch (Throwable $error) {
        mx_log_error('geocode failed', $error, ['query' => $query]);
        return ['ok' => false, 'status' => 'geocode_exception', 'query' => $query];
    }
}

function mx_geocode_istanbul_address(string $query): ?array
{
    $result = mx_geocode_istanbul_address_result($query);
    if (!($result['ok'] ?? false)) {
        return null;
    }

    return ['lat' => (float) $result['lat'], 'lng' => (float) $result['lng']];
}

function mx_geocode_istanbul_address_candidates_result(array $candidates): array
{
    $lastStatus = 'not_attempted';
    foreach ($candidates as $candidate) {
        $query = mx_clean_string($candidate, 500);
        if ($query === '') {
            continue;
        }
        $result = mx_geocode_istanbul_address_result($query);
        $lastStatus = (string) ($result['status'] ?? 'geocode_failed');
        if ($result['ok'] ?? false) {
            return $result;
        }
    }

    return ['ok' => false, 'status' => $lastStatus];
}

function mx_geocode_istanbul_address_candidates(array $candidates): ?array
{
    $result = mx_geocode_istanbul_address_candidates_result($candidates);
    if (!($result['ok'] ?? false)) {
        return null;
    }

    return ['lat' => (float) $result['lat'], 'lng' => (float) $result['lng']];
}

function mx_route_distance_openroute(array $from, array $to): array
{
    $config = mx_config();
    $apiKey = trim((string) ($config['openroute_api_key'] ?? $config['ors_api_key'] ?? ''));
    if ($apiKey === '') {
        error_log('[MyExpress] routing skipped | provider=openrouteservice | reason=api_key_missing');
        $fallback = mx_route_unavailable('api_key_missing', 'openrouteservice');
        $fallback['api_key_present'] = false;
        return $fallback;
    }

    $payload = json_encode([
        'coordinates' => [
            [(float) $from['lng'], (float) $from['lat']],
            [(float) $to['lng'], (float) $to['lat']],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $url = 'https://api.openrouteservice.org/v2/directions/driving-car';
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $apiKey,
                    'Content-Type: application/json; charset=utf-8',
                    'Accept: application/json',
                ],
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (!is_string($raw)) {
                error_log('[MyExpress] routing route_network_error | provider=openrouteservice | curl_error=' . curl_error($ch));
            }
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'timeout' => 8,
                    'header' => "Authorization: {$apiKey}\r\nContent-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n",
                    'content' => $payload,
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            $status = is_string($raw) ? 200 : 0;
        }

        error_log('[MyExpress] route request started | provider=openrouteservice | api_key_present=true | from=' . $from['lat'] . ',' . $from['lng'] . ' | to=' . $to['lat'] . ',' . $to['lng']);
        if (!is_string($raw)) {
            return mx_route_unavailable_with_key_status('route_network_error', 'openrouteservice', true);
        }

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status === 401 || $status === 403) {
            error_log('[MyExpress] routing route_api_key_invalid | provider=openrouteservice | http_status=' . $status);
            return mx_route_unavailable_with_key_status('route_api_key_invalid', 'openrouteservice', true);
        }
        if ($status === 429) {
            error_log('[MyExpress] routing route_rate_limited | provider=openrouteservice | http_status=' . $status);
            return mx_route_unavailable_with_key_status('route_rate_limited', 'openrouteservice', true);
        }

        $summary = $data['features'][0]['properties']['summary'] ?? $data['routes'][0]['summary'] ?? null;
        if ($status < 200 || $status >= 300) {
            error_log('[MyExpress] routing route_failed | provider=openrouteservice | http_status=' . $status . ' | body=' . substr($raw, 0, 300));
            return mx_route_unavailable_with_key_status('route_failed', 'openrouteservice', true);
        }
        if (!is_array($data)) {
            error_log('[MyExpress] routing route_invalid_response | provider=openrouteservice | http_status=' . $status);
            return mx_route_unavailable_with_key_status('route_invalid_response', 'openrouteservice', true);
        }
        if (!is_array($summary) || !isset($summary['distance'])) {
            error_log('[MyExpress] routing route_no_path | provider=openrouteservice | http_status=' . $status . ' | body=' . substr($raw, 0, 300));
            return mx_route_unavailable_with_key_status('route_no_path', 'openrouteservice', true);
        }

        $distanceKm = round(((float) $summary['distance']) / 1000, 2);
        error_log('[MyExpress] route ok | provider=openrouteservice | distance_km=' . $distanceKm);
        return [
            'success' => true,
            'status' => 'priced',
            'distance_type' => 'route',
            'route_distance_km' => $distanceKm,
            'route_duration_min' => isset($summary['duration']) ? round(((float) $summary['duration']) / 60, 2) : null,
            'route_provider' => 'openrouteservice',
            'route_status' => 'ok',
            'fallback_reason' => null,
            'api_key_present' => true,
        ];
    } catch (Throwable $error) {
        mx_log_error('openroute routing failed', $error);
        return mx_route_unavailable_with_key_status('route_exception', 'openrouteservice', true);
    }
}

function mx_calculate_price(array $payload): array
{
    $config = mx_config();
    $hasRoutingKey = trim((string) ($config['openroute_api_key'] ?? $config['ors_api_key'] ?? '')) !== '';

    $pickupLat = is_numeric($payload['pickupLat'] ?? null) ? (float) $payload['pickupLat'] : null;
    $pickupLng = is_numeric($payload['pickupLng'] ?? null) ? (float) $payload['pickupLng'] : null;
    $dropoffLat = is_numeric($payload['dropoffLat'] ?? null) ? (float) $payload['dropoffLat'] : null;
    $dropoffLng = is_numeric($payload['dropoffLng'] ?? null) ? (float) $payload['dropoffLng'] : null;
    $pickupGeocodeStatus = ($pickupLat !== null && $pickupLng !== null) ? 'provided' : 'not_attempted';
    $dropoffGeocodeStatus = ($dropoffLat !== null && $dropoffLng !== null) ? 'provided' : 'not_attempted';

    error_log('[MyExpress] price estimate request received | api_key_present=' . ($hasRoutingKey ? 'true' : 'false'));

    if ($pickupLat === null || $pickupLng === null) {
        $pickupGeo = mx_geocode_istanbul_address_candidates_result([
            implode(', ', array_filter([$payload['pickupStreet'] ?? '', $payload['pickup'] ?? '', 'İstanbul'])),
            implode(', ', array_filter([$payload['pickup'] ?? '', 'İstanbul'])),
        ]);
        $pickupGeocodeStatus = (string) ($pickupGeo['status'] ?? 'geocode_failed');
        if ($pickupGeo['ok'] ?? false) {
            $pickupLat = $pickupGeo['lat'];
            $pickupLng = $pickupGeo['lng'];
        }
    }

    if ($dropoffLat === null || $dropoffLng === null) {
        $dropoffGeo = mx_geocode_istanbul_address_candidates_result([
            implode(', ', array_filter([$payload['dropoffStreet'] ?? '', $payload['dropoff'] ?? '', 'İstanbul'])),
            implode(', ', array_filter([$payload['dropoff'] ?? '', 'İstanbul'])),
        ]);
        $dropoffGeocodeStatus = (string) ($dropoffGeo['status'] ?? 'geocode_failed');
        if ($dropoffGeo['ok'] ?? false) {
            $dropoffLat = $dropoffGeo['lat'];
            $dropoffLng = $dropoffGeo['lng'];
        }
    }

    if ($pickupLat === null || $pickupLng === null || $dropoffLat === null || $dropoffLng === null) {
        $fallback = mx_route_unavailable('geocode_failed', null);
        $pickupMissing = $pickupLat === null || $pickupLng === null;
        $dropoffMissing = $dropoffLat === null || $dropoffLng === null;
        $fallback['geocode_status'] = $pickupMissing && $dropoffMissing ? 'both_failed' : ($pickupMissing ? 'pickup_failed' : 'dropoff_failed');
        $fallback['pickup_geocode_status'] = $pickupGeocodeStatus;
        $fallback['dropoff_geocode_status'] = $dropoffGeocodeStatus;
        $fallback['api_key_present'] = $hasRoutingKey;
        error_log('[MyExpress] price fallback | reason=geocode_failed | pickup_geocode_status=' . $pickupGeocodeStatus . ' | dropoff_geocode_status=' . $dropoffGeocodeStatus);
        return $fallback;
    }

    if (!$hasRoutingKey) {
        $fallback = mx_route_unavailable('api_key_missing', 'openrouteservice');
        $fallback['pickup_lat'] = $pickupLat;
        $fallback['pickup_lng'] = $pickupLng;
        $fallback['dropoff_lat'] = $dropoffLat;
        $fallback['dropoff_lng'] = $dropoffLng;
        $fallback['geocode_status'] = 'ok';
        $fallback['pickup_geocode_status'] = $pickupGeocodeStatus;
        $fallback['dropoff_geocode_status'] = $dropoffGeocodeStatus;
        $fallback['api_key_present'] = false;
        return $fallback;
    }

    $pricing = mx_pricing_settings();
    $serviceKey = mx_clean_string($payload['service'] ?? 'normal', 40);
    $packageKey = mx_clean_string($payload['packageType'] ?? 'evrak', 40);
    $service = $pricing['services'][$serviceKey] ?? $pricing['services']['normal'];
    $package = $pricing['packages'][$packageKey] ?? ['fee' => 0.0];
    $rules = $pricing['rules'];

    $route = mx_route_distance_openroute(
        ['lat' => $pickupLat, 'lng' => $pickupLng],
        ['lat' => $dropoffLat, 'lng' => $dropoffLng]
    );
    if (($route['distance_type'] ?? '') !== 'route' || empty($route['route_distance_km'])) {
        $route['pickup_lat'] = $pickupLat;
        $route['pickup_lng'] = $pickupLng;
        $route['dropoff_lat'] = $dropoffLat;
        $route['dropoff_lng'] = $dropoffLng;
        $route['geocode_status'] = 'ok';
        $route['pickup_geocode_status'] = $pickupGeocodeStatus;
        $route['dropoff_geocode_status'] = $dropoffGeocodeStatus;
        $route['api_key_present'] = $hasRoutingKey;
        error_log('[MyExpress] price fallback | reason=' . ($route['fallback_reason'] ?? $route['route_status'] ?? 'route_failed'));
        return $route;
    }

    $sameArea = mx_clean_string($payload['pickup'] ?? '', 255) === mx_clean_string($payload['dropoff'] ?? '', 255);
    $minimumKm = $sameArea ? (float) $rules['minSameAreaKm'] : (float) $rules['minDefaultKm'];
    $billableDistance = max((float) $route['route_distance_km'], $minimumKm);
    $bridgeFee = (($pickupLng < 29 && $dropoffLng >= 29) || ($pickupLng >= 29 && $dropoffLng < 29))
        ? (float) $rules['bridgeFee']
        : 0.0;
    $price = ((float) $service['base'] + ($billableDistance * (float) $service['km']) + (float) $package['fee'] + $bridgeFee)
        * (float) $service['multiplier'];
    $formattedPrice = number_format(mx_round_price($price, (float) $rules['roundTo']), 0, ',', '.') . ' TL';
    error_log('[MyExpress] price calculated | route_distance_km=' . $route['route_distance_km'] . ' | billable_distance_km=' . round($billableDistance, 2) . ' | price=' . $formattedPrice);

    return [
        'success' => true,
        'status' => 'priced',
        'price' => $formattedPrice,
        'distance_km' => round($billableDistance, 2),
        'distance_type' => 'route',
        'route_distance_km' => round((float) $route['route_distance_km'], 2),
        'route_duration_min' => $route['route_duration_min'],
        'route_provider' => $route['route_provider'],
        'route_status' => $route['route_status'],
        'geocode_status' => 'ok',
        'pickup_geocode_status' => $pickupGeocodeStatus,
        'dropoff_geocode_status' => $dropoffGeocodeStatus,
        'fallback_reason' => null,
        'api_key_present' => $hasRoutingKey,
        'pickup_lat' => $pickupLat,
        'pickup_lng' => $pickupLng,
        'dropoff_lat' => $dropoffLat,
        'dropoff_lng' => $dropoffLng,
    ];
}

function mx_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
