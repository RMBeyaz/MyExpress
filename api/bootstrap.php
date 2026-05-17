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

function mx_statuses(): array
{
    return [
        'new' => 'Yeni',
        'called' => 'Arandı',
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

function mx_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
