<?php
declare(strict_types=1);

const MYEXPRESS_CONFIG_PATH = '/home/myexpresscom/myexpress-config.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function mx_is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function mx_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self), payment=()');
    if (mx_is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function mx_secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        mx_security_headers();
        return;
    }

    $secure = mx_is_https_request();
    session_name('mx_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
    mx_security_headers();
}

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

function mx_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        mx_secure_session_start();
    }

    if (empty($_SESSION['mx_csrf_token']) || !is_string($_SESSION['mx_csrf_token'])) {
        $_SESSION['mx_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['mx_csrf_token'];
}

function mx_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . mx_h(mx_csrf_token()) . '">';
}

function mx_csrf_valid(?string $token = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        mx_secure_session_start();
    }

    $expected = (string) ($_SESSION['mx_csrf_token'] ?? '');
    $given = (string) ($token ?? ($_POST['csrf_token'] ?? ''));

    return $expected !== '' && $given !== '' && hash_equals($expected, $given);
}

function mx_require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!mx_csrf_valid()) {
        http_response_code(403);
        echo 'Güvenlik doğrulaması başarısız. Lütfen sayfayı yenileyip tekrar deneyin.';
        exit;
    }
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

function mx_page_size_options(): array
{
    return [10, 25, 50, 100];
}

function mx_pagination_state(int $total, string $prefix = 'list', int $defaultPerPage = 10): array
{
    $options = mx_page_size_options();
    $perPageKey = $prefix . '_per_page';
    $pageKey = $prefix . '_page';
    $perPage = (int) ($_GET[$perPageKey] ?? $defaultPerPage);
    if (!in_array($perPage, $options, true)) {
        $perPage = in_array($defaultPerPage, $options, true) ? $defaultPerPage : 10;
    }

    $pageCount = max(1, (int) ceil(max(0, $total) / $perPage));
    $page = max(1, min($pageCount, (int) ($_GET[$pageKey] ?? 1)));

    return [
        'total' => max(0, $total),
        'page' => $page,
        'per_page' => $perPage,
        'page_count' => $pageCount,
        'offset' => ($page - 1) * $perPage,
        'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
        'to' => min($total, $page * $perPage),
    ];
}

function mx_paginate_array(array $rows, string $prefix = 'list', int $defaultPerPage = 10): array
{
    $pagination = mx_pagination_state(count($rows), $prefix, $defaultPerPage);

    return [
        array_slice($rows, $pagination['offset'], $pagination['per_page']),
        $pagination,
    ];
}

function mx_pagination_url(string $prefix, int $page, ?int $perPage = null): string
{
    $query = $_GET;
    $query[$prefix . '_page'] = max(1, $page);
    if ($perPage !== null) {
        $query[$prefix . '_per_page'] = $perPage;
    }

    $queryString = http_build_query($query);
    $path = (string) ($_SERVER['PHP_SELF'] ?? '');

    return $path . ($queryString !== '' ? '?' . $queryString : '');
}

function mx_render_pagination(array $pagination, string $prefix = 'list', string $label = 'Liste'): string
{
    $total = (int) ($pagination['total'] ?? 0);
    $page = (int) ($pagination['page'] ?? 1);
    $pageCount = (int) ($pagination['page_count'] ?? 1);
    $perPage = (int) ($pagination['per_page'] ?? 10);
    $from = (int) ($pagination['from'] ?? 0);
    $to = (int) ($pagination['to'] ?? 0);
    $hidden = '';
    foreach ($_GET as $key => $value) {
        if ($key === $prefix . '_page' || $key === $prefix . '_per_page' || is_array($value)) {
            continue;
        }
        $hidden .= '<input type="hidden" name="' . mx_h($key) . '" value="' . mx_h((string) $value) . '">';
    }

    $options = '';
    foreach (mx_page_size_options() as $option) {
        $selected = $option === $perPage ? ' selected' : '';
        $options .= '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
    }

    $previousClass = $page <= 1 ? ' is-disabled' : '';
    $nextClass = $page >= $pageCount ? ' is-disabled' : '';
    $previousHref = $page > 1 ? mx_pagination_url($prefix, $page - 1, $perPage) : '#';
    $nextHref = $page < $pageCount ? mx_pagination_url($prefix, $page + 1, $perPage) : '#';

    return '<nav class="list-pagination" aria-label="' . mx_h($label) . ' sayfalama">'
        . '<div class="pagination-summary"><strong>' . mx_h($label) . '</strong><span>' . $from . '-' . $to . ' / ' . $total . ' kayıt</span></div>'
        . '<form class="pagination-size" method="get">' . $hidden . '<input type="hidden" name="' . mx_h($prefix . '_page') . '" value="1">'
        . '<label>Sayfa başına <select name="' . mx_h($prefix . '_per_page') . '" onchange="this.form.submit()">' . $options . '</select></label></form>'
        . '<div class="pagination-controls"><a class="pagination-link' . $previousClass . '" href="' . mx_h($previousHref) . '" aria-disabled="' . ($page <= 1 ? 'true' : 'false') . '">Önceki</a>'
        . '<span>Sayfa ' . $page . ' / ' . $pageCount . '</span>'
        . '<a class="pagination-link' . $nextClass . '" href="' . mx_h($nextHref) . '" aria-disabled="' . ($page >= $pageCount ? 'true' : 'false') . '">Sonraki</a></div>'
        . '</nav>';
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
    return 'MX' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function mx_tracking_code_for_id(int $id): string
{
    return mx_tracking_code();
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

function mx_client_ip(): string
{
    $candidate = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    if (str_contains($candidate, ',')) {
        $candidate = trim(explode(',', $candidate)[0]);
    }

    return mx_clean_string($candidate, 45);
}

function mx_login_attempts_table_ready(): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        if (mx_table_exists('login_attempts')) {
            $ready = true;
            return true;
        }

        mx_pdo()->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                scope VARCHAR(32) NOT NULL,
                identifier_hash CHAR(64) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_scope_identifier_time (scope, identifier_hash, created_at),
                INDEX idx_scope_ip_time (scope, ip_address, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $ready = true;
    } catch (Throwable $error) {
        $ready = false;
        mx_log_error('login attempts table unavailable', $error);
    }

    return $ready;
}

function mx_login_rate_limit_status(string $scope, string $identifier): array
{
    $config = mx_config();
    $maxAttempts = max(3, (int) ($config['login_max_attempts'] ?? 6));
    $windowSeconds = max(60, (int) ($config['login_window_seconds'] ?? 900));
    $scope = mx_clean_string($scope, 32);
    $identifierHash = hash('sha256', strtolower(mx_clean_string($identifier, 180)));
    $ip = mx_client_ip();

    if (!mx_login_attempts_table_ready()) {
        return ['allowed' => true, 'remaining' => $maxAttempts, 'retry_after' => 0];
    }

    try {
        $pdo = mx_pdo();
        $pdo->prepare('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)')->execute();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS attempts, MIN(created_at) AS first_attempt
             FROM login_attempts
             WHERE scope = :scope
               AND success = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowSeconds} SECOND)
               AND (identifier_hash = :identifier_hash OR ip_address = :ip_address)"
        );
        $stmt->bindValue(':scope', $scope);
        $stmt->bindValue(':identifier_hash', $identifierHash);
        $stmt->bindValue(':ip_address', $ip);
        $stmt->execute();
        $row = $stmt->fetch() ?: ['attempts' => 0, 'first_attempt' => null];
        $attempts = (int) ($row['attempts'] ?? 0);
        $remaining = max(0, $maxAttempts - $attempts);

        if ($attempts >= $maxAttempts) {
            $first = strtotime((string) ($row['first_attempt'] ?? 'now')) ?: time();
            $retryAfter = max(60, ($first + $windowSeconds) - time());
            return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
        }

        return ['allowed' => true, 'remaining' => $remaining, 'retry_after' => 0];
    } catch (Throwable $error) {
        mx_log_error('login rate limit check failed', $error, ['scope' => $scope]);
        return ['allowed' => true, 'remaining' => $maxAttempts, 'retry_after' => 0];
    }
}

function mx_record_login_attempt(string $scope, string $identifier, bool $success): void
{
    if (!mx_login_attempts_table_ready()) {
        return;
    }

    try {
        $scope = mx_clean_string($scope, 32);
        $identifierHash = hash('sha256', strtolower(mx_clean_string($identifier, 180)));
        $ip = mx_client_ip();
        $pdo = mx_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (scope, identifier_hash, ip_address, success, user_agent)
             VALUES (:scope, :identifier_hash, :ip_address, :success, :user_agent)'
        );
        $stmt->execute([
            ':scope' => $scope,
            ':identifier_hash' => $identifierHash,
            ':ip_address' => $ip,
            ':success' => $success ? 1 : 0,
            ':user_agent' => mx_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
        ]);

        if ($success) {
            $cleanup = $pdo->prepare(
                'DELETE FROM login_attempts WHERE scope = :scope AND (identifier_hash = :identifier_hash OR ip_address = :ip_address)'
            );
            $cleanup->execute([
                ':scope' => $scope,
                ':identifier_hash' => $identifierHash,
                ':ip_address' => $ip,
            ]);
        }
    } catch (Throwable $error) {
        mx_log_error('login rate limit record failed', $error, ['scope' => $scope]);
    }
}

function mx_login_block_message(int $retryAfter): string
{
    $minutes = max(1, (int) ceil($retryAfter / 60));
    return 'Çok fazla hatalı giriş denemesi yapıldı. Lütfen yaklaşık ' . $minutes . ' dakika sonra tekrar deneyin.';
}

function mx_public_rate_limit(string $scope, string $identifier = '', int $maxRequests = 60, int $windowSeconds = 300): array
{
    $maxRequests = max(3, $maxRequests);
    $windowSeconds = max(60, $windowSeconds);
    $scope = mx_clean_string('public_' . $scope, 32);
    $ip = mx_client_ip();
    $identifierHash = hash('sha256', strtolower(mx_clean_string($identifier !== '' ? $identifier : $ip, 220)));

    if (!mx_login_attempts_table_ready()) {
        return ['allowed' => true, 'retry_after' => 0];
    }

    try {
        $pdo = mx_pdo();
        $pdo->prepare('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)')->execute();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS attempts, MIN(created_at) AS first_attempt
             FROM login_attempts
             WHERE scope = :scope
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowSeconds} SECOND)
               AND (identifier_hash = :identifier_hash OR ip_address = :ip_address)"
        );
        $stmt->execute([
            ':scope' => $scope,
            ':identifier_hash' => $identifierHash,
            ':ip_address' => $ip,
        ]);
        $row = $stmt->fetch() ?: ['attempts' => 0, 'first_attempt' => null];
        $attempts = (int) ($row['attempts'] ?? 0);

        if ($attempts >= $maxRequests) {
            $first = strtotime((string) ($row['first_attempt'] ?? 'now')) ?: time();
            $retryAfter = max(60, ($first + $windowSeconds) - time());
            return ['allowed' => false, 'retry_after' => $retryAfter];
        }

        $insert = $pdo->prepare(
            'INSERT INTO login_attempts (scope, identifier_hash, ip_address, success, user_agent)
             VALUES (:scope, :identifier_hash, :ip_address, 0, :user_agent)'
        );
        $insert->execute([
            ':scope' => $scope,
            ':identifier_hash' => $identifierHash,
            ':ip_address' => $ip,
            ':user_agent' => mx_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
        ]);

        return ['allowed' => true, 'retry_after' => 0];
    } catch (Throwable $error) {
        mx_log_error('public rate limit failed', $error, ['scope' => $scope]);
        return ['allowed' => true, 'retry_after' => 0];
    }
}

function mx_require_public_rate_limit(string $scope, string $identifier = '', int $maxRequests = 60, int $windowSeconds = 300): void
{
    $status = mx_public_rate_limit($scope, $identifier, $maxRequests, $windowSeconds);
    if ($status['allowed']) {
        return;
    }

    if (!headers_sent()) {
        header('Retry-After: ' . (int) $status['retry_after']);
    }

    mx_json([
        'ok' => false,
        'message' => 'Çok fazla istek alındı. Lütfen kısa bir süre sonra tekrar deneyin.',
    ], 429);
}

function mx_panel_login(string $username, string $password): bool
{
    $rateLimit = mx_login_rate_limit_status('panel', $username);
    if (!$rateLimit['allowed']) {
        return false;
    }

    $success = false;
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
                $success = true;
                mx_record_login_attempt('panel', $username, true);
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
        $success = true;
        mx_record_login_attempt('panel', $username, true);
        return true;
    }

    if (!$success) {
        mx_record_login_attempt('panel', $username, false);
        usleep(250000);
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
    $rateLimit = mx_login_rate_limit_status('customer', $email);
    if (!$rateLimit['allowed']) {
        return false;
    }

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
            mx_record_login_attempt('customer', $email, false);
            usleep(250000);
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

        mx_record_login_attempt('customer', $email, true);
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

function mx_audit_log(?int $requestId, string $action, string $details = '', ?string $actor = null): void
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
            ':admin_user' => $actor !== null ? mx_clean_string($actor, 80) : mx_panel_user(),
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

function mx_courier_task_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function mx_courier_task_url(int $requestId, int $courierId): string
{
    if (
        !mx_column_exists('courier_requests', 'courier_access_token_hash')
        || !mx_column_exists('courier_requests', 'courier_access_token_expires_at')
    ) {
        throw new RuntimeException('Kurye görev bağlantısı için migration çalıştırılmalı.');
    }

    $token = bin2hex(random_bytes(32));
    $stmt = mx_pdo()->prepare(
        'UPDATE courier_requests
         SET courier_access_token_hash = :token_hash,
             courier_access_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
         WHERE id = :id AND assigned_courier_id = :courier_id'
    );
    $stmt->execute([
        ':token_hash' => mx_courier_task_token_hash($token),
        ':id' => $requestId,
        ':courier_id' => $courierId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Kurye görev bağlantısı oluşturulamadı.');
    }

    return mx_site_url('kurye-gorev.php?id=' . $requestId . '&token=' . rawurlencode($token));
}

function mx_courier_task_request(int $requestId, string $token): ?array
{
    if ($requestId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token) || !mx_table_exists('couriers')) {
        return null;
    }

    $hasTokenColumns = mx_column_exists('courier_requests', 'courier_access_token_hash')
        && mx_column_exists('courier_requests', 'courier_access_token_expires_at');
    $tokenSelect = $hasTokenColumns
        ? ', cr.courier_access_token_hash, cr.courier_access_token_expires_at'
        : ', NULL AS courier_access_token_hash, NULL AS courier_access_token_expires_at';
    $stmt = mx_pdo()->prepare(
        'SELECT cr.*, c.full_name AS courier_name, c.phone AS courier_phone, c.vehicle_type AS courier_vehicle_type, c.plate AS courier_plate'
        . $tokenSelect
        . ' FROM courier_requests cr'
        . ' INNER JOIN couriers c ON c.id = cr.assigned_courier_id AND c.is_active = 1'
        . ' WHERE cr.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return null;
    }

    $storedHash = trim((string) ($request['courier_access_token_hash'] ?? ''));
    $expiresAt = trim((string) ($request['courier_access_token_expires_at'] ?? ''));
    if (
        $storedHash === ''
        || $expiresAt === ''
        || strtotime($expiresAt) < time()
        || !hash_equals($storedHash, mx_courier_task_token_hash($token))
    ) {
        return null;
    }

    return $request;
}

function mx_courier_proof_directory(): string
{
    return dirname(__DIR__) . '/uploads/kurye-kanitlari';
}

function mx_courier_proof_absolute_path(string $fileName): ?string
{
    $fileName = trim($fileName);
    if ($fileName === '' || str_contains($fileName, '..') || str_contains($fileName, '/') || str_contains($fileName, '\\')) {
        return null;
    }

    $base = realpath(mx_courier_proof_directory());
    if ($base === false) {
        return null;
    }

    $target = realpath($base . DIRECTORY_SEPARATOR . $fileName);
    if ($target === false || !str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return is_file($target) ? $target : null;
}

function mx_stream_courier_proof(array $proof): void
{
    $path = mx_courier_proof_absolute_path((string) ($proof['file_name'] ?? ''));
    if ($path === null) {
        http_response_code(404);
        echo 'Kanıt dosyası bulunamadı.';
        exit;
    }

    $mime = (string) ($proof['mime_type'] ?? 'application/octet-stream');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $mime = 'application/octet-stream';
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="myexpress-kurye-kaniti"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function mx_delete_courier_proof_files_for_request(int $requestId): void
{
    if ($requestId <= 0 || !mx_table_exists('courier_delivery_proofs')) {
        return;
    }

    try {
        $stmt = mx_pdo()->prepare('SELECT file_name FROM courier_delivery_proofs WHERE request_id = :request_id');
        $stmt->execute([':request_id' => $requestId]);
        foreach ($stmt->fetchAll() as $proof) {
            $path = mx_courier_proof_absolute_path((string) ($proof['file_name'] ?? ''));
            if ($path !== null) {
                @unlink($path);
            }
        }
    } catch (Throwable $error) {
        mx_log_error('courier proof file cleanup failed', $error, ['request_id' => $requestId]);
    }
}

function mx_site_url(string $path = ''): string
{
    $base = rtrim((string) (mx_config()['site_url'] ?? 'https://myexpress.com.tr'), '/');
    $path = '/' . ltrim($path, '/');
    return $base . ($path === '/' ? '/' : $path);
}

function mx_mail_from_address(): string
{
    $config = mx_config();
    $from = trim((string) ($config['mail_from'] ?? $config['smtp_user'] ?? $config['mail_to'] ?? 'info@myexpress.com.tr'));
    return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'info@myexpress.com.tr';
}

function mx_mail_from_name(): string
{
    return mx_clean_string(mx_config()['mail_from_name'] ?? 'MyExpress', 80);
}

function mx_mail_header_encode(string $value): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function mx_mail_headers_have(array $headers, string $name): bool
{
    $prefix = strtolower($name) . ':';
    foreach ($headers as $header) {
        if (str_starts_with(strtolower(trim((string) $header)), $prefix)) {
            return true;
        }
    }

    return false;
}

function mx_mail_content_headers(array $headers): array
{
    $contentHeaders = [];
    if (!mx_mail_headers_have($headers, 'MIME-Version')) {
        $contentHeaders[] = 'MIME-Version: 1.0';
    }
    if (!mx_mail_headers_have($headers, 'Content-Type')) {
        $contentHeaders[] = 'Content-Type: text/plain; charset=UTF-8';
    }
    if (!mx_mail_headers_have($headers, 'Content-Type') && !mx_mail_headers_have($headers, 'Content-Transfer-Encoding')) {
        $contentHeaders[] = 'Content-Transfer-Encoding: 8bit';
    }

    return $contentHeaders;
}

function mx_smtp_read_response($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    return $response;
}

function mx_smtp_expect($socket, array $codes, string $context): string
{
    $response = mx_smtp_read_response($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        $safeResponse = preg_replace('/\s+/', ' ', trim($response)) ?? '';
        $safeResponse = function_exists('mb_substr') ? mb_substr($safeResponse, 0, 180, 'UTF-8') : substr($safeResponse, 0, 180);
        throw new RuntimeException($context . ' failed with SMTP code ' . ($code ?: 'unknown') . ($safeResponse !== '' ? ' response=' . $safeResponse : ''));
    }

    return $response;
}

function mx_smtp_command($socket, string $command, array $codes, string $context): string
{
    fwrite($socket, $command . "\r\n");
    return mx_smtp_expect($socket, $codes, $context);
}

function mx_smtp_send_mail(string $to, string $subject, string $message, array $headers = []): bool
{
    $config = mx_config();
    $host = trim((string) ($config['smtp_host'] ?? ''));
    $user = trim((string) ($config['smtp_user'] ?? ''));
    $pass = (string) ($config['smtp_pass'] ?? '');

    if ($host === '' || $user === '' || $pass === '') {
        error_log('[MyExpress] smtp config eksik | host=' . ($host !== '' ? 'present' : 'missing') . ' | user=' . ($user !== '' ? 'present' : 'missing') . ' | pass=' . ($pass !== '' ? 'present' : 'missing'));
        return false;
    }

    if (!function_exists('stream_socket_client')) {
        error_log('[MyExpress] smtp gonderimi yapilamadi | stream_socket_client kapali');
        return false;
    }

    $secure = strtolower(trim((string) ($config['smtp_secure'] ?? 'ssl')));
    $port = (int) ($config['smtp_port'] ?? ($secure === 'ssl' ? 465 : 587));
    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        error_log('[MyExpress] smtp baglanti hatasi | host=' . $host . ' | port=' . $port . ' | code=' . $errno . ' | error=' . $errstr);
        return false;
    }

    stream_set_timeout($socket, 15);
    $from = mx_mail_from_address();
    $fromName = mx_mail_from_name();
    $serverName = parse_url(mx_site_url(), PHP_URL_HOST) ?: 'myexpress.com.tr';

    try {
        mx_smtp_expect($socket, [220], 'greeting');
        mx_smtp_command($socket, 'EHLO ' . $serverName, [250], 'ehlo');

        if ($secure === 'tls') {
            mx_smtp_command($socket, 'STARTTLS', [220], 'starttls');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('starttls crypto failed');
            }
            mx_smtp_command($socket, 'EHLO ' . $serverName, [250], 'ehlo tls');
        }

        mx_smtp_command($socket, 'AUTH LOGIN', [334], 'auth login');
        mx_smtp_command($socket, base64_encode($user), [334], 'auth username');
        mx_smtp_command($socket, base64_encode($pass), [235], 'auth password');
        mx_smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250], 'mail from');
        mx_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'rcpt to');
        mx_smtp_command($socket, 'DATA', [354], 'data');

        $defaultHeaders = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . mx_mail_header_encode($fromName) . ' <' . $from . '>',
            'Reply-To: ' . mx_mail_header_encode($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . mx_mail_header_encode($subject),
        ];
        $defaultHeaders = array_merge($defaultHeaders, mx_mail_content_headers($headers));
        $body = str_replace(["\r\n", "\r"], "\n", $message);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        $payload = implode("\r\n", array_merge($defaultHeaders, $headers)) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.";

        mx_smtp_command($socket, $payload, [250], 'message body');
        mx_smtp_command($socket, 'QUIT', [221, 250], 'quit');
        fclose($socket);
        return true;
    } catch (Throwable $error) {
        fclose($socket);
        mx_log_error('smtp mail failed', $error, ['to' => $to, 'subject' => $subject, 'smtp_host' => $host, 'smtp_port' => $port]);
        return false;
    }
}

function mx_send_mail(string $to, string $subject, string $message, array $headers = []): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $config = mx_config();
    if (!empty($config['smtp_host']) || !empty($config['smtp_user']) || !empty($config['smtp_pass'])) {
        return mx_smtp_send_mail($to, $subject, $message, $headers);
    }

    if (!function_exists('mail')) {
        error_log('[MyExpress] PHP mail fonksiyonu kapali | subject=' . $subject);
        return false;
    }

    $from = mx_mail_from_address();
    $fromName = mx_mail_from_name();
    $defaultHeaders = [
        'From: ' . mx_mail_header_encode($fromName) . ' <' . $from . '>',
        'Reply-To: ' . mx_mail_header_encode($fromName) . ' <' . $from . '>',
    ];
    $defaultHeaders = array_merge($defaultHeaders, mx_mail_content_headers($headers));
    $mailHeaders = implode("\r\n", array_merge($defaultHeaders, $headers));
    $sent = @mail($to, mx_mail_header_encode($subject), $message, $mailHeaders, '-f ' . $from);
    if (!$sent) {
        error_log('[MyExpress] mail gonderilemedi | to=' . $to . ' | subject=' . $subject);
    }
    return $sent;
}

function mx_send_html_mail(string $to, string $subject, string $textBody, string $htmlBody): bool
{
    $boundary = 'mx_' . bin2hex(random_bytes(16));
    $body = implode("\r\n", [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        str_replace(["\r\n", "\r"], "\n", $textBody),
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        str_replace(["\r\n", "\r"], "\n", $htmlBody),
        '--' . $boundary . '--',
        '',
    ]);

    return mx_send_mail($to, $subject, $body, [
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);
}

function mx_request_public_url(string $trackingCode): string
{
    return mx_site_url('takip.html?no=' . rawurlencode($trackingCode));
}

function mx_customer_verification_code(): string
{
    return (string) random_int(100000, 999999);
}

function mx_send_customer_verification_mail(string $email, string $fullName, string $code, string $token): bool
{
    $verifyUrl = mx_site_url('hesabim/onay.php?token=' . rawurlencode($token));
    $textMessage = implode("\n", [
        'Merhaba ' . $fullName . ',',
        '',
        'MyExpress hesabınızı aktifleştirmek için aşağıdaki onay kodunu kullanın:',
        '',
        $code,
        '',
        'Alternatif olarak bu bağlantıyı açabilirsiniz:',
        $verifyUrl,
        '',
        'Bu işlemi siz başlatmadıysanız bu e-postayı dikkate almayın.',
        '',
        'MyExpress',
    ]);
    $safeName = mx_h($fullName);
    $safeCode = mx_h($code);
    $safeVerifyUrl = mx_h($verifyUrl);
    $htmlMessage = <<<HTML
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MyExpress hesap onayı</title>
  </head>
  <body style="margin:0;padding:0;background:#f4f7f9;color:#0b2238;font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f9;margin:0;padding:28px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dce4ea;border-radius:14px;overflow:hidden;">
            <tr>
              <td style="padding:24px 28px;background:#071d2f;color:#ffffff;">
                <div style="font-size:22px;font-weight:800;letter-spacing:.2px;">MyExpress</div>
                <div style="margin-top:6px;color:#b9c8d3;font-size:14px;">İstanbul içi kurye ve teslimat operasyonu</div>
              </td>
            </tr>
            <tr>
              <td style="padding:30px 28px 26px;">
                <p style="margin:0 0 10px;color:#ef4438;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">Hesap onayı</p>
                <h1 style="margin:0 0 14px;color:#0b2238;font-size:28px;line-height:1.16;font-weight:800;">Hesabınızı aktifleştirin</h1>
                <p style="margin:0 0 22px;color:#536372;font-size:16px;line-height:1.55;">Merhaba {$safeName}, MyExpress hesabınızı güvenli şekilde kullanabilmeniz için aşağıdaki onay kodunu girmeniz yeterli.</p>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 22px;background:#fff2f0;border:1px solid #ffc7c1;border-radius:12px;">
                  <tr>
                    <td style="padding:20px 18px;text-align:center;">
                      <div style="margin-bottom:8px;color:#6b7785;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;">Onay kodunuz</div>
                      <div style="font-size:38px;line-height:1;font-weight:900;letter-spacing:8px;color:#0b2238;">{$safeCode}</div>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 22px;">
                  <tr>
                    <td style="background:#ef4438;border-radius:8px;">
                      <a href="{$safeVerifyUrl}" style="display:inline-block;padding:14px 20px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:800;">Hesabı Aktifleştir</a>
                    </td>
                  </tr>
                </table>

                <p style="margin:0 0 12px;color:#536372;font-size:14px;line-height:1.5;">Buton çalışmazsa aşağıdaki bağlantıyı tarayıcınıza yapıştırabilirsiniz:</p>
                <p style="margin:0;word-break:break-all;color:#0b2238;font-size:13px;line-height:1.5;"><a href="{$safeVerifyUrl}" style="color:#0b2238;">{$safeVerifyUrl}</a></p>
              </td>
            </tr>
            <tr>
              <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e4ebf0;color:#6b7785;font-size:13px;line-height:1.5;">
                Bu işlemi siz başlatmadıysanız bu e-postayı dikkate almayın. Kod güvenlik için kısa süreli geçerlidir.
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;

    return mx_send_html_mail($email, 'MyExpress hesap onay kodunuz', $textMessage, $htmlMessage);
}

function mx_customer_verification_ready(): bool
{
    return mx_table_exists('customers')
        && mx_column_exists('customers', 'email_verification_code')
        && mx_column_exists('customers', 'email_verification_token')
        && mx_column_exists('customers', 'email_verification_expires_at');
}

function mx_customer_password_reset_ready(): bool
{
    return mx_table_exists('customers')
        && mx_column_exists('customers', 'password_reset_token')
        && mx_column_exists('customers', 'password_reset_expires_at');
}

function mx_send_customer_password_reset_mail(string $email, string $fullName, string $token): bool
{
    $resetUrl = mx_site_url('hesabim/sifre-yenile.php?token=' . rawurlencode($token));
    $textMessage = implode("\n", [
        'Merhaba ' . $fullName . ',',
        '',
        'MyExpress hesabınız için şifre yenileme talebi aldık.',
        'Yeni şifrenizi belirlemek için aşağıdaki bağlantıyı açın:',
        '',
        $resetUrl,
        '',
        'Bu bağlantı 30 dakika boyunca ve yalnızca bir kez kullanılabilir.',
        'Bu işlemi siz başlatmadıysanız e-postayı dikkate almayın.',
        '',
        'MyExpress',
    ]);
    $safeName = mx_h($fullName);
    $safeResetUrl = mx_h($resetUrl);
    $htmlMessage = <<<HTML
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MyExpress şifre yenileme</title>
  </head>
  <body style="margin:0;padding:0;background:#f4f7f9;color:#0b2238;font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f9;margin:0;padding:28px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dce4ea;border-radius:14px;overflow:hidden;">
            <tr>
              <td style="padding:24px 28px;background:#071d2f;color:#ffffff;">
                <div style="font-size:22px;font-weight:800;letter-spacing:.2px;">MyExpress</div>
                <div style="margin-top:6px;color:#b9c8d3;font-size:14px;">İstanbul içi kurye ve teslimat operasyonu</div>
              </td>
            </tr>
            <tr>
              <td style="padding:30px 28px 26px;">
                <p style="margin:0 0 10px;color:#ef4438;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">Hesap güvenliği</p>
                <h1 style="margin:0 0 14px;color:#0b2238;font-size:28px;line-height:1.16;font-weight:800;">Şifrenizi yenileyin</h1>
                <p style="margin:0 0 22px;color:#536372;font-size:16px;line-height:1.55;">Merhaba {$safeName}, MyExpress hesabınız için şifre yenileme talebi aldık. Yeni şifrenizi güvenli şekilde belirlemek için aşağıdaki butonu kullanın.</p>

                <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 22px;">
                  <tr>
                    <td style="background:#ef4438;border-radius:8px;">
                      <a href="{$safeResetUrl}" style="display:inline-block;padding:14px 20px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:800;">Yeni Şifre Belirle</a>
                    </td>
                  </tr>
                </table>

                <p style="margin:0 0 12px;color:#536372;font-size:14px;line-height:1.5;">Buton çalışmazsa aşağıdaki bağlantıyı tarayıcınıza yapıştırabilirsiniz:</p>
                <p style="margin:0;word-break:break-all;color:#0b2238;font-size:13px;line-height:1.5;"><a href="{$safeResetUrl}" style="color:#0b2238;">{$safeResetUrl}</a></p>
              </td>
            </tr>
            <tr>
              <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e4ebf0;color:#6b7785;font-size:13px;line-height:1.5;">
                Bu bağlantı güvenlik için 30 dakika boyunca ve yalnızca bir kez kullanılabilir. Talebi siz oluşturmadıysanız e-postayı dikkate almayın.
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;

    return mx_send_html_mail($email, 'MyExpress şifre yenileme bağlantınız', $textMessage, $htmlMessage);
}

function mx_refresh_customer_verification(int $customerId): bool
{
    if ($customerId <= 0 || !mx_customer_verification_ready()) {
        return false;
    }

    $stmt = mx_pdo()->prepare('SELECT email, full_name, is_active FROM customers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch();
    if (!$customer || (int) $customer['is_active'] === 1) {
        return false;
    }

    $code = mx_customer_verification_code();
    $token = bin2hex(random_bytes(32));
    mx_pdo()->prepare(
        'UPDATE customers
         SET email_verification_code = :code,
             email_verification_token = :token,
             email_verification_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
         WHERE id = :id'
    )->execute([
        ':code' => $code,
        ':token' => hash('sha256', $token),
        ':id' => $customerId,
    ]);

    $sent = mx_send_customer_verification_mail((string) $customer['email'], (string) $customer['full_name'], $code, $token);
    if (!$sent) {
        error_log('[MyExpress] customer verification mail failed | customer_id=' . $customerId . ' | email=' . (string) $customer['email']);
    }
    return $sent;
}

function mx_request_mail_recipients(array $request): array
{
    $emails = [];
    foreach (['sender_email', 'recipient_email'] as $key) {
        $email = trim((string) ($request[$key] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    if (!empty($request['customer_id']) && mx_table_exists('customers')) {
        try {
            $stmt = mx_pdo()->prepare('SELECT email FROM customers WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) $request['customer_id']]);
            $email = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        } catch (Throwable $error) {
            mx_log_error('request customer email lookup failed', $error, ['request_id' => $request['id'] ?? null]);
        }
    }

    return array_values(array_unique($emails));
}

function mx_request_mail_message(array $request, string $title, string $body = ''): string
{
    $trackingCode = (string) ($request['tracking_code'] ?? '');
    $lines = [
        $title,
        '',
        'Talep No: ' . $trackingCode,
        'Durum: ' . mx_status_label((string) ($request['status'] ?? '')),
        'Alım: ' . (string) ($request['pickup'] ?? ''),
        'Teslim: ' . (string) ($request['dropoff'] ?? ''),
        'Ücret: ' . (string) ($request['price'] ?? ''),
    ];
    if ($body !== '') {
        $lines[] = '';
        $lines[] = $body;
    }
    $lines[] = '';
    $lines[] = 'Gönderi durumunu takip etmek için:';
    $lines[] = mx_request_public_url($trackingCode);
    $lines[] = '';
    $lines[] = 'MyExpress';
    return implode("\n", $lines);
}

function mx_money_mail_label($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 'Operasyon tarafından teyit edilecek';
    }
    if (preg_match('/^\d+(?:[.,]\d+)?$/', $raw)) {
        return number_format((float) str_replace(',', '.', $raw), 0, ',', '.') . ' TL';
    }
    return $raw;
}

function mx_request_mail_html(array $request, string $title, string $body = ''): string
{
    $trackingCode = (string) ($request['tracking_code'] ?? '');
    $statusLabel = mx_status_label((string) ($request['status'] ?? ''));
    $pickup = (string) ($request['pickup'] ?? '');
    $dropoff = (string) ($request['dropoff'] ?? '');
    $price = mx_money_mail_label($request['price'] ?? '');
    $trackingUrl = mx_request_public_url($trackingCode);
    $safeTitle = mx_h($title);
    $safeBody = mx_h($body);
    $safeTrackingCode = mx_h($trackingCode);
    $safeStatus = mx_h($statusLabel);
    $safePickup = mx_h($pickup);
    $safeDropoff = mx_h($dropoff);
    $safePrice = mx_h($price);
    $safeTrackingUrl = mx_h($trackingUrl);
    $bodyBlock = $body !== ''
        ? '<p style="margin:0 0 20px;color:#536372;font-size:15px;line-height:1.55;">' . nl2br($safeBody) . '</p>'
        : '';

    return <<<HTML
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
  </head>
  <body style="margin:0;padding:0;background:#f4f7f9;color:#0b2238;font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f9;margin:0;padding:28px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #dce4ea;border-radius:14px;overflow:hidden;">
            <tr>
              <td style="padding:24px 28px;background:#071d2f;color:#ffffff;">
                <div style="font-size:22px;font-weight:800;letter-spacing:.2px;">MyExpress</div>
                <div style="margin-top:6px;color:#b9c8d3;font-size:14px;">Talep ve teslimat durumu</div>
              </td>
            </tr>
            <tr>
              <td style="padding:30px 28px 26px;">
                <p style="margin:0 0 10px;color:#ef4438;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">Gönderi bilgilendirmesi</p>
                <h1 style="margin:0 0 14px;color:#0b2238;font-size:28px;line-height:1.16;font-weight:800;">{$safeTitle}</h1>
                {$bodyBlock}

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 22px;border:1px solid #e1e8ee;border-radius:12px;overflow:hidden;">
                  <tr>
                    <td style="padding:16px 18px;background:#f8fafc;border-bottom:1px solid #e1e8ee;">
                      <div style="color:#6b7785;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;">Talep no</div>
                      <div style="margin-top:4px;color:#0b2238;font-size:22px;font-weight:900;">{$safeTrackingCode}</div>
                    </td>
                    <td style="padding:16px 18px;background:#f8fafc;border-bottom:1px solid #e1e8ee;text-align:right;">
                      <span style="display:inline-block;padding:8px 12px;background:#eaf3ff;color:#174a7a;border-radius:999px;font-size:13px;font-weight:800;">{$safeStatus}</span>
                    </td>
                  </tr>
                  <tr>
                    <td colspan="2" style="padding:18px;">
                      <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                        <tr>
                          <td style="padding:0 0 14px;">
                            <div style="color:#6b7785;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;">Alım</div>
                            <div style="margin-top:5px;color:#0b2238;font-size:15px;line-height:1.45;font-weight:700;">{$safePickup}</div>
                          </td>
                        </tr>
                        <tr>
                          <td style="padding:0 0 14px;">
                            <div style="color:#6b7785;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;">Teslim</div>
                            <div style="margin-top:5px;color:#0b2238;font-size:15px;line-height:1.45;font-weight:700;">{$safeDropoff}</div>
                          </td>
                        </tr>
                        <tr>
                          <td>
                            <div style="color:#6b7785;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;">Ücret</div>
                            <div style="margin-top:5px;color:#0b2238;font-size:18px;font-weight:900;">{$safePrice}</div>
                          </td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 20px;">
                  <tr>
                    <td style="background:#ef4438;border-radius:8px;">
                      <a href="{$safeTrackingUrl}" style="display:inline-block;padding:14px 20px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:800;">Gönderi Durumunu Takip Et</a>
                    </td>
                  </tr>
                </table>

                <p style="margin:0;color:#536372;font-size:13px;line-height:1.5;">Buton çalışmazsa bu bağlantıyı kullanabilirsiniz:<br><a href="{$safeTrackingUrl}" style="color:#0b2238;word-break:break-all;">{$safeTrackingUrl}</a></p>
              </td>
            </tr>
            <tr>
              <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e4ebf0;color:#6b7785;font-size:13px;line-height:1.5;">
                Bu bilgilendirme MyExpress talep sistemindeki kayıtlı gönderi bilgileriniz için otomatik oluşturulmuştur.
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;
}

function mx_send_request_customer_mail(array $request, string $subject, string $title, string $body = ''): void
{
    foreach (mx_request_mail_recipients($request) as $email) {
        mx_send_html_mail(
            $email,
            $subject,
            mx_request_mail_message($request, $title, $body),
            mx_request_mail_html($request, $title, $body)
        );
    }
}

function mx_request_by_id(int $requestId): ?array
{
    if ($requestId <= 0 || !mx_table_exists('courier_requests')) {
        return null;
    }

    $stmt = mx_pdo()->prepare('SELECT * FROM courier_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch();
    return $request ?: null;
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
