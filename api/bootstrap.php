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
    return isset($_SESSION['mx_panel_auth']) && $_SESSION['mx_panel_auth'] === true;
}

function mx_panel_require_login()
{
    if (!mx_panel_is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function mx_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
