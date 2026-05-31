<?php
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function normalize_app_key(string $raw): string
{
    $value = strtolower(trim($raw));
    $value = preg_replace('/[^a-z0-9_]/', '', $value);
    return $value ?: 'bpjs_auto_screening';
}

function normalize_version(string $raw): string
{
    $value = trim($raw);
    if ($value === '')
        return '0.0.0';
    $value = ltrim($value, "vV");
    return preg_match('/^\d+\.\d+\.\d+$/', $value) ? $value : '0.0.0';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);

    $app = normalize_app_key((string) ($_GET['app'] ?? 'bpjs_auto_screening'));
    $current_version = normalize_version((string) ($_GET['current_version'] ?? '0.0.0'));
    $config_path = __DIR__ . '/../../config/extension_updates.json';

    if (!is_file($config_path))
        json_response([
            'ok' => true,
            'data' => [
                'app' => $app,
                'current_version' => $current_version,
                'latest_version' => $current_version,
                'has_update' => false,
                'download_url' => '',
                'notes' => 'Belum ada metadata update.',
            ],
        ]);

    $raw = @file_get_contents($config_path);
    $cfg = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cfg))
        json_response(['ok' => false, 'error' => 'Config update tidak valid'], 500);

    $app_data = is_array($cfg[$app] ?? null) ? $cfg[$app] : [];
    $latest_version = normalize_version((string) ($app_data['latest_version'] ?? $current_version));
    $has_update = version_compare($latest_version, $current_version, '>');
    $download_url = trim((string) ($app_data['download_url'] ?? ''));
    $notes = trim((string) ($app_data['notes'] ?? ''));
    $updated_at = trim((string) ($app_data['updated_at'] ?? ''));

    json_response([
        'ok' => true,
        'data' => [
            'app' => $app,
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'has_update' => $has_update,
            'download_url' => $download_url,
            'notes' => $notes,
            'updated_at' => $updated_at,
        ],
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
