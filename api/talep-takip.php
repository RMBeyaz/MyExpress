<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function mx_public_timeline_entry(string $type, string $label, string $createdAt, string $note = ''): array
{
    return [
        'type' => $type,
        'label' => $label,
        'note' => $note,
        'created_at' => $createdAt,
    ];
}

try {
    $trackingCode = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = mx_post_json();
        $trackingCode = mx_clean_string($payload['trackingCode'] ?? '', 40);
    } else {
        $trackingCode = mx_clean_string($_GET['no'] ?? '', 40);
    }

    $trackingCode = strtoupper((string) preg_replace('/\s+/u', '', $trackingCode));
    if (!preg_match('/^MX[0-9A-Z-]{6,32}$/', $trackingCode)) {
        mx_json([
            'ok' => false,
            'message' => 'Geçerli bir talep numarası girin.',
        ], 422);
    }
    mx_require_public_rate_limit('tracking_lookup', $trackingCode, 30, 300);

    $routeSelect = (mx_column_exists('courier_requests', 'distance_type') ? ', distance_type' : ', NULL AS distance_type')
        . (mx_column_exists('courier_requests', 'route_status') ? ', route_status' : ', NULL AS route_status');
    $stmt = mx_pdo()->prepare(
        'SELECT id, tracking_code, status, pickup, dropoff, service_label, package_label,
                delivery_time, price, distance_km, created_at, updated_at' . $routeSelect . '
         FROM courier_requests
         WHERE tracking_code = :tracking_code
         LIMIT 1'
    );
    $stmt->execute([':tracking_code' => $trackingCode]);
    $request = $stmt->fetch();

    if (!$request) {
        mx_json([
            'ok' => false,
            'message' => 'Bu talep numarasıyla kayıt bulunamadı.',
        ], 404);
    }

    $logsStmt = mx_pdo()->prepare(
        'SELECT status, note, created_at
         FROM request_status_logs
         WHERE request_id = :id
         ORDER BY created_at ASC'
    );
    $logsStmt->execute([':id' => (int) $request['id']]);

    $timeline = [];
    foreach ($logsStmt->fetchAll() as $log) {
        $timeline[] = mx_public_timeline_entry(
            'status',
            mx_status_label((string) $log['status']),
            (string) $log['created_at'],
            mx_clean_text($log['note'] ?? '', 220)
        );
    }

    if (mx_table_exists('request_audit_logs')) {
        $auditStmt = mx_pdo()->prepare(
            "SELECT action, created_at
             FROM request_audit_logs
             WHERE request_id = :id
               AND action IN ('details_update')
             ORDER BY created_at ASC"
        );
        $auditStmt->execute([':id' => (int) $request['id']]);
        foreach ($auditStmt->fetchAll() as $audit) {
            $timeline[] = mx_public_timeline_entry(
                'update',
                'Talep bilgileri güncellendi',
                (string) $audit['created_at']
            );
        }
    }

    usort($timeline, static function (array $a, array $b): int {
        return strcmp($a['created_at'], $b['created_at']);
    });

    mx_json([
        'ok' => true,
        'request' => [
            'tracking_code' => (string) $request['tracking_code'],
            'status' => (string) $request['status'],
            'status_label' => mx_status_label((string) $request['status']),
            'pickup' => (string) $request['pickup'],
            'dropoff' => (string) $request['dropoff'],
            'service_label' => (string) $request['service_label'],
            'package_label' => (string) $request['package_label'],
            'delivery_time' => (string) $request['delivery_time'],
            'price' => (string) $request['price'],
            'distance_km' => $request['distance_km'] !== null ? (string) $request['distance_km'] : '',
            'distance_type' => (string) ($request['distance_type'] ?? ''),
            'route_status' => (string) ($request['route_status'] ?? ''),
            'created_at' => (string) $request['created_at'],
            'updated_at' => (string) $request['updated_at'],
        ],
        'timeline' => $timeline,
    ]);
} catch (Throwable $error) {
    mx_log_error('public tracking failed', $error);
    mx_json([
        'ok' => false,
        'message' => 'Talep bilgisi alınamadı. Lütfen daha sonra tekrar deneyin.',
    ], 500);
}
