<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function normalize_update_channel(string $value): string
{
    $raw = strtolower(trim($value));
    $clean = preg_replace('/[^a-z0-9._-]/', '-', $raw);
    $clean = preg_replace('/-+/', '-', (string) $clean);
    $clean = trim((string) $clean, '-._');
    return $clean !== '' ? $clean : 'public';
}

function resolve_update_channel(string $license_key): string
{
    $default = 'public';
    $config_path = __DIR__ . '/../../config/update_channels.json';
    if (!is_file($config_path))
        return $default;

    $raw = @file_get_contents($config_path);
    if ($raw === false)
        return $default;

    $cfg = json_decode($raw, true);
    if (!is_array($cfg))
        return $default;

    $default = normalize_update_channel((string) ($cfg['default'] ?? 'public'));
    $target_key = strtoupper(trim($license_key));
    if ($target_key === '')
        return $default;

    $license_map = is_array($cfg['licenses'] ?? null) ? $cfg['licenses'] : [];
    foreach ($license_map as $key => $channel) {
        if (strtoupper(trim((string) $key)) === $target_key)
            return normalize_update_channel((string) $channel);
    }

    $prefix_map = is_array($cfg['prefixes'] ?? null) ? $cfg['prefixes'] : [];
    foreach ($prefix_map as $prefix => $channel) {
        $prefix_value = strtoupper(trim((string) $prefix));
        if ($prefix_value === '')
            continue;
        if (str_starts_with($target_key, $prefix_value))
            return normalize_update_channel((string) $channel);
    }

    return $default;
}

function ensure_license_security_columns(PDO $db): void
{
    try {
        $db->exec("ALTER TABLE license_keys ADD COLUMN device_auth_token VARCHAR(128) NULL DEFAULT NULL AFTER device_bound_at");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE license_keys ADD COLUMN token_bound_at DATETIME NULL DEFAULT NULL AFTER device_auth_token");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE license_keys ADD COLUMN last_ip VARCHAR(64) NULL DEFAULT NULL AFTER token_bound_at");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE license_keys ADD COLUMN last_user_agent VARCHAR(255) NULL DEFAULT NULL AFTER last_ip");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE license_keys ADD COLUMN device_profile_hash VARCHAR(64) NULL DEFAULT NULL AFTER device_id");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE license_keys ADD COLUMN device_profile_updated_at DATETIME NULL DEFAULT NULL AFTER device_profile_hash");
    } catch (Exception $e) {
    }
}

function normalize_device_profile_hash(string $raw): string
{
    $value = strtolower(trim($raw));
    return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
}

function can_rebind_as_same_machine(array $license, string $device_profile_hash, string $request_ip, string $request_user_agent): bool
{
    if ($device_profile_hash === '')
        return false;

    $saved_profile_hash = normalize_device_profile_hash((string) ($license['device_profile_hash'] ?? ''));
    if ($saved_profile_hash !== '')
        return hash_equals($saved_profile_hash, $device_profile_hash);

    $saved_ip = trim((string) ($license['last_ip'] ?? ''));
    $saved_user_agent = trim((string) ($license['last_user_agent'] ?? ''));
    return $saved_ip !== '' &&
        $saved_user_agent !== '' &&
        $saved_ip === $request_ip &&
        $saved_user_agent === $request_user_agent;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        json_response(['error' => 'Method not allowed'], 405);

    $key = trim($_SERVER['HTTP_X_LICENSE_KEY'] ?? '');
    $device_id = trim((string) ($_POST['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? '')));
    $device_profile_hash = normalize_device_profile_hash((string) ($_POST['device_profile'] ?? ($_SERVER['HTTP_X_DEVICE_PROFILE'] ?? '')));
    $request_ip = substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    $request_user_agent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

    if (!$key)
        json_response(['error' => 'License key wajib diisi'], 401);

    if (!$device_id || strlen($device_id) < 16)
        json_response(['error' => 'Device ID tidak valid'], 400);

    $db = db();
    ensure_license_security_columns($db);

    $stmt = $db->prepare("
        SELECT lk.*, u.is_active AS user_active,
               u.subscription_type, u.subscription_end,
               u.quota_total, u.quota_used,
               u.username, u.full_name
        FROM license_keys lk
        JOIN users u ON lk.user_id = u.id
        WHERE lk.license_key = ? AND lk.is_active = 1 AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$key]);
    $lic = $stmt->fetch();

    if (!$lic)
        json_response(['error' => 'License key tidak valid atau tidak aktif'], 401);

    if ($lic['subscription_type'] === 'time') {
        if (empty($lic['subscription_end']))
            json_response(['error' => 'Paket belum diset oleh admin'], 403);

        $today = new DateTime(date('Y-m-d'));
        $end = new DateTime(date('Y-m-d', strtotime($lic['subscription_end'])));
        if ($today > $end)
            json_response([
                'error' => 'Masa berlangganan habis per ' . date('d/m/Y', strtotime($lic['subscription_end'])),
                'code' => 'EXPIRED'
            ], 403);
    } else {
        if ((int) $lic['quota_used'] >= (int) $lic['quota_total'])
            json_response([
                'error' => 'Kuota NIK sudah habis (' . $lic['quota_used'] . '/' . $lic['quota_total'] . ')',
                'code' => 'QUOTA_EMPTY'
            ], 403);
    }

    $bound = $lic['device_id'] ?? null;
    $active_token = trim((string) ($lic['device_auth_token'] ?? ''));
    $device_token_issued = false;
    $should_rebind_device = false;
    $saved_profile_hash = normalize_device_profile_hash((string) ($lic['device_profile_hash'] ?? ''));

    if ($bound) {
        if ($bound !== $device_id)
            if (can_rebind_as_same_machine($lic, $device_profile_hash, $request_ip, $request_user_agent))
                $should_rebind_device = true;
            else
                json_response([
                    'error' => 'License key ini sudah terikat ke perangkat lain. Hubungi admin untuk reset device.',
                    'code' => 'DEVICE_MISMATCH'
                ], 403);

        $active_token = bin2hex(random_bytes(32));
        $device_token_issued = true;

        if ($should_rebind_device) {
            $db->prepare("
                UPDATE license_keys
                SET device_id = ?,
                    device_bound_at = NOW(),
                    device_auth_token = ?,
                    token_bound_at = NOW(),
                    device_profile_hash = ?,
                    device_profile_updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $device_id,
                $active_token,
                $device_profile_hash !== '' ? $device_profile_hash : null,
                $lic['id']
            ]);
        } else {
            $should_update_profile_hash = $saved_profile_hash === '' && $device_profile_hash !== '';
            if ($should_update_profile_hash) {
                $db->prepare("
                    UPDATE license_keys
                    SET device_auth_token = ?,
                        token_bound_at = NOW(),
                        device_profile_hash = ?,
                        device_profile_updated_at = NOW()
                    WHERE id = ?
                ")->execute([$active_token, $device_profile_hash, $lic['id']]);
            } else {
                $db->prepare("UPDATE license_keys SET device_auth_token = ?, token_bound_at = NOW() WHERE id = ?")
                    ->execute([$active_token, $lic['id']]);
            }
        }
    } else {
        $active_token = bin2hex(random_bytes(32));
        $device_token_issued = true;
        try {
            $db->prepare("
                UPDATE license_keys
                SET device_id = ?,
                    device_bound_at = NOW(),
                    device_auth_token = ?,
                    token_bound_at = NOW(),
                    device_profile_hash = ?,
                    device_profile_updated_at = ?
                WHERE id = ?
            ")->execute([
                $device_id,
                $active_token,
                $device_profile_hash !== '' ? $device_profile_hash : null,
                $device_profile_hash !== '' ? date('Y-m-d H:i:s') : null,
                $lic['id']
            ]);
            try {
                $db->prepare("DELETE FROM license_profiles WHERE license_key_id = ?")
                    ->execute([(int)$lic['id']]);
            } catch (Throwable $ignore) {
            }
        } catch (Exception $ex) {
            json_response([
                'error' => 'Kolom device belum tersedia. Jalankan migrate_device.php di server terlebih dahulu.',
                'code' => 'MIGRATION_NEEDED'
            ], 500);
        }
    }

    $db->prepare("UPDATE license_keys SET last_seen = NOW(), last_ip = ?, last_user_agent = ? WHERE id = ?")
        ->execute([$request_ip, $request_user_agent, $lic['id']]);

    $response = [
        'ok' => true,
        'username' => $lic['username'] ?? '',
        'full_name' => $lic['full_name'] ?? '',
        'pc_label' => $lic['pc_label'] ?? '',
        'mode' => $lic['mode'] ?? 'umum',
        'task_type' => $lic['task_type'] ?? 'pendaftaran',
        'subscription_type' => $lic['subscription_type'],
        'update_channel' => resolve_update_channel($key),
    ];
    if ($device_token_issued) {
        $response['device_token'] = $active_token;
        $response['device_token_issued'] = true;
    } else {
        $response['device_token_issued'] = false;
        $response['device_token_masked'] = substr($active_token, 0, 6) . str_repeat('*', 10) . substr($active_token, -4);
    }

    json_response($response);
} catch (Throwable $t) {
    json_response(['error' => 'Server error'], 500);
}
