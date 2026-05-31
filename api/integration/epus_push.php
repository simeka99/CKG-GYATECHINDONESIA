<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function mask_secret_value(string $value): string
{
    $value = trim($value);
    if ($value === '')
        return '';
    $len = strlen($value);
    if ($len <= 4)
        return '***';
    if ($len <= 8)
        return substr($value, 0, 2) . '***' . substr($value, -2);
    return substr($value, 0, 4) . '***' . substr($value, -4);
}

function parse_ip_allow_list(string $raw): array
{
    $ips = [];
    foreach (explode(',', $raw) as $ip) {
        $ip = trim($ip);
        if ($ip === '')
            continue;
        $ips[$ip] = true;
    }
    return array_keys($ips);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);

$token_saved = trim(get_setting('epus_integration_token'));
$secret_saved = trim(get_setting('epus_integration_secret'));
$allowed_ips_raw = trim(get_setting('epus_integration_allowed_ips'));

if ($token_saved === '' || $secret_saved === '')
    json_response(['ok' => false, 'error' => 'Integration API belum dikonfigurasi'], 503);

$token_request = trim((string) ($_SERVER['HTTP_X_INTEGRATION_TOKEN'] ?? ''));
if ($token_request === '' || !hash_equals($token_saved, $token_request))
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);

$raw_body = file_get_contents('php://input');
if (!is_string($raw_body))
    $raw_body = '';

$signature_request = strtolower(trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '')));
$auth_mode = 'simple';
if ($signature_request !== '') {
    $timestamp = (int) ($_SERVER['HTTP_X_TIMESTAMP'] ?? 0);
    if ($timestamp <= 0 || abs(time() - $timestamp) > 300)
        json_response(['ok' => false, 'error' => 'Timestamp tidak valid'], 401);

    $signature_expected = hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret_saved);
    if (!hash_equals($signature_expected, $signature_request))
        json_response(['ok' => false, 'error' => 'Signature tidak valid'], 401);
    $auth_mode = 'hmac';
} else {
    $secret_request = trim((string) ($_SERVER['HTTP_X_INTEGRATION_SECRET'] ?? ''));
    if ($secret_request === '' || !hash_equals($secret_saved, $secret_request))
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$allowed_ips = parse_ip_allow_list($allowed_ips_raw);
if ($allowed_ips) {
    $remote_ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote_ip === '' || !in_array($remote_ip, $allowed_ips, true))
        json_response(['ok' => false, 'error' => 'IP tidak diizinkan'], 403);
}

$payload = json_decode($raw_body, true);
if (!is_array($payload))
    json_response(['ok' => false, 'error' => 'Payload JSON tidak valid'], 400);

$has_cookie = array_key_exists('cookie', $payload);
$has_api_url = array_key_exists('api_url', $payload);
$has_referer = array_key_exists('referer', $payload);
if (!$has_cookie && !$has_api_url && !$has_referer)
    json_response(['ok' => false, 'error' => 'Tidak ada field yang dikirim'], 400);

$cookie = trim((string) ($payload['cookie'] ?? ''));
$api_url = trim((string) ($payload['api_url'] ?? ''));
$referer = trim((string) ($payload['referer'] ?? ''));

if ($has_cookie && $cookie !== '' && strlen($cookie) > 16000)
    json_response(['ok' => false, 'error' => 'Cookie terlalu panjang'], 400);
if ($has_cookie && $cookie !== '' && str_contains($cookie, '*'))
    json_response(['ok' => false, 'error' => 'Cookie terdeteksi format mask (***). Gunakan nilai asli'], 400);
if ($has_api_url && $api_url !== '' && !filter_var($api_url, FILTER_VALIDATE_URL))
    json_response(['ok' => false, 'error' => 'api_url tidak valid'], 400);
if ($has_referer && $referer !== '' && !filter_var($referer, FILTER_VALIDATE_URL))
    json_response(['ok' => false, 'error' => 'referer tidak valid'], 400);

if ($has_cookie && $cookie !== '')
    save_setting('epus_cookie', $cookie);
if ($has_api_url)
    save_setting('epus_api_url', $api_url);
if ($has_referer)
    save_setting('epus_referer', $referer);
save_setting('epus_cookie_updated_at', date('Y-m-d H:i:s'));

json_response([
    'ok' => true,
    'message' => 'Konfigurasi EPUS berhasil diperbarui',
    'auth_mode' => $auth_mode,
    'data' => [
        'epus_api_url' => get_setting('epus_api_url'),
        'epus_referer' => get_setting('epus_referer'),
        'epus_cookie_masked' => mask_secret_value(get_setting('epus_cookie')),
        'updated_at' => get_setting('epus_cookie_updated_at'),
    ],
]);
