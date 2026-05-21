<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mx_json(['ok' => false, 'message' => 'Bu endpoint sadece POST kabul eder.'], 405);
}

try {
    $payload = mx_post_json();
    $price = mx_calculate_price($payload);
    mx_json([
        'ok' => true,
        'success' => (bool) ($price['success'] ?? false),
        'status' => (string) ($price['status'] ?? 'manual_required'),
        'distance_type' => $price['distance_type'] ?? null,
        'route_distance_km' => $price['route_distance_km'] ?? null,
        'distance_km' => $price['distance_km'] ?? null,
        'calculated_price' => ($price['distance_type'] ?? '') === 'route' ? ($price['price'] ?? null) : null,
        'fallback_reason' => $price['fallback_reason'] ?? null,
        'pickup_geocode_status' => $price['pickup_geocode_status'] ?? $price['geocode_status'] ?? null,
        'dropoff_geocode_status' => $price['dropoff_geocode_status'] ?? $price['geocode_status'] ?? null,
        'route_status' => $price['route_status'] ?? null,
        'route_provider' => $price['route_provider'] ?? null,
        'api_key_present' => $price['api_key_present'] ?? null,
        'price' => $price,
    ]);
} catch (Throwable $error) {
    mx_log_error('price estimate failed', $error);
    $fallback = mx_route_unavailable('route_exception', null);
    mx_json([
        'ok' => true,
        'success' => false,
        'status' => 'manual_required',
        'distance_type' => $fallback['distance_type'],
        'route_distance_km' => null,
        'distance_km' => null,
        'calculated_price' => null,
        'fallback_reason' => $fallback['fallback_reason'],
        'pickup_geocode_status' => null,
        'dropoff_geocode_status' => null,
        'route_status' => $fallback['route_status'],
        'route_provider' => null,
        'api_key_present' => null,
        'price' => $fallback,
    ]);
}
