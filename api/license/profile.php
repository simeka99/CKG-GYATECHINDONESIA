<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function ensure_license_profiles_table(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS license_profiles (
            license_key_id INT(11) NOT NULL,
            account_email VARCHAR(255) NOT NULL DEFAULT '',
            account_password VARCHAR(255) NOT NULL DEFAULT '',
            wali_nik VARCHAR(32) NOT NULL DEFAULT '',
            wali_nama VARCHAR(150) NOT NULL DEFAULT '',
            wali_no_hp VARCHAR(32) NOT NULL DEFAULT '',
            wali_instansi_puskesmas VARCHAR(160) NOT NULL DEFAULT '',
            wali_tanggal_lahir VARCHAR(10) NOT NULL DEFAULT '',
            wali_jenis_kelamin VARCHAR(20) NOT NULL DEFAULT 'Perempuan',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
            PRIMARY KEY (license_key_id),
            CONSTRAINT license_profiles_ibfk_1
                FOREIGN KEY (license_key_id) REFERENCES license_keys(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $column_map = [
        'account_email' => "ALTER TABLE license_profiles ADD COLUMN account_email VARCHAR(255) NOT NULL DEFAULT ''",
        'account_password' => "ALTER TABLE license_profiles ADD COLUMN account_password VARCHAR(255) NOT NULL DEFAULT ''",
        'wali_nik' => "ALTER TABLE license_profiles ADD COLUMN wali_nik VARCHAR(32) NOT NULL DEFAULT ''",
        'wali_nama' => "ALTER TABLE license_profiles ADD COLUMN wali_nama VARCHAR(150) NOT NULL DEFAULT ''",
        'wali_no_hp' => "ALTER TABLE license_profiles ADD COLUMN wali_no_hp VARCHAR(32) NOT NULL DEFAULT ''",
        'wali_instansi_puskesmas' => "ALTER TABLE license_profiles ADD COLUMN wali_instansi_puskesmas VARCHAR(160) NOT NULL DEFAULT ''",
        'wali_tanggal_lahir' => "ALTER TABLE license_profiles ADD COLUMN wali_tanggal_lahir VARCHAR(10) NOT NULL DEFAULT ''",
        'wali_jenis_kelamin' => "ALTER TABLE license_profiles ADD COLUMN wali_jenis_kelamin VARCHAR(20) NOT NULL DEFAULT 'Perempuan'",
    ];

    foreach ($column_map as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $ignore) {
        }
    }

    try {
        $db->exec("ALTER TABLE users ADD COLUMN default_wali_profile LONGTEXT NULL");
    } catch (Throwable $ignore) {
    }
}

function normalize_text_value($value, int $max_len): string
{
    return substr(trim((string)$value), 0, $max_len);
}

function to_upper_text($value, int $max_len): string
{
    $text_value = trim((string)$value);
    if ($text_value === '')
        return '';
    if (function_exists('mb_strtoupper'))
        $text_value = mb_strtoupper($text_value, 'UTF-8');
    else
        $text_value = strtoupper($text_value);
    return substr($text_value, 0, $max_len);
}

function default_wali_profile_template_profile(): array
{
    return [
        'wali_nik' => '',
        'wali_nama' => '',
        'wali_no_hp' => '',
        'wali_instansi_puskesmas' => '',
        'wali_tanggal_lahir' => '',
        'wali_jenis_kelamin' => 'Perempuan',
    ];
}

function decode_default_wali_profile_profile($value): array
{
    $template = default_wali_profile_template_profile();
    if (!is_string($value) || trim($value) === '')
        return $template;
    $decoded = json_decode($value, true);
    if (!is_array($decoded))
        return $template;
    return array_merge($template, $decoded);
}

function ensure_profile_row(PDO $db, int $license_key_id): void
{
    $insert = $db->prepare("
        INSERT IGNORE INTO license_profiles (
            license_key_id,
            wali_nama,
            wali_no_hp,
            wali_instansi_puskesmas
        )
        SELECT
            lk.id,
            '',
            COALESCE(NULLIF(TRIM(u.no_hp), ''), ''),
            COALESCE(NULLIF(TRIM(u.full_name), ''), '')
        FROM license_keys lk
        JOIN users u ON u.id = lk.user_id
        WHERE lk.id = ?
        LIMIT 1
    ");
    $insert->execute([$license_key_id]);

    $sync = $db->prepare("
        UPDATE license_profiles lp
        JOIN license_keys lk ON lk.id = lp.license_key_id
        JOIN users u ON u.id = lk.user_id
        SET
            lp.wali_no_hp = CASE
                WHEN TRIM(lp.wali_no_hp) = '' THEN COALESCE(NULLIF(TRIM(u.no_hp), ''), '')
                ELSE lp.wali_no_hp
            END,
            lp.wali_instansi_puskesmas = CASE
                WHEN TRIM(lp.wali_instansi_puskesmas) = '' THEN COALESCE(NULLIF(TRIM(u.full_name), ''), '')
                ELSE lp.wali_instansi_puskesmas
            END
        WHERE lp.license_key_id = ?
    ");
    $sync->execute([$license_key_id]);

    $row_query = $db->prepare("
        SELECT u.full_name, u.no_hp, u.default_wali_profile
        FROM license_keys lk
        JOIN users u ON u.id = lk.user_id
        WHERE lk.id = ?
        LIMIT 1
    ");
    $row_query->execute([$license_key_id]);
    $row = $row_query->fetch() ?: [];
    $default_profile = decode_default_wali_profile_profile($row['default_wali_profile'] ?? '');
    $operator_full_name = normalize_text_value($row['full_name'] ?? '', 160);

    $wali_nama = to_upper_text($default_profile['wali_nama'] ?? '', 150);
    $wali_nik = normalize_text_value($default_profile['wali_nik'] ?? '', 32);
    $wali_no_hp = normalize_text_value($default_profile['wali_no_hp'] ?: ($row['no_hp'] ?? ''), 32);
    $wali_instansi = normalize_text_value($default_profile['wali_instansi_puskesmas'] ?: $operator_full_name, 160);
    $wali_tanggal_lahir = normalize_date_string($default_profile['wali_tanggal_lahir'] ?? '');
    $wali_jenis_kelamin = normalize_text_value($default_profile['wali_jenis_kelamin'] ?? 'Perempuan', 20) ?: 'Perempuan';

    $profile_sync_query = $db->prepare("
        UPDATE license_profiles
        SET
            wali_nik = CASE WHEN TRIM(wali_nik) = '' THEN ? ELSE wali_nik END,
            wali_nama = CASE
                WHEN TRIM(wali_nama) = '' THEN ?
                WHEN ? <> '' AND (
                    LOWER(TRIM(wali_nama)) = LOWER(TRIM(?)) OR
                    LOWER(TRIM(wali_nama)) = LOWER(TRIM(?)) OR
                    LOWER(TRIM(wali_nama)) LIKE 'puskesmas %' OR
                    LOWER(TRIM(wali_nama)) LIKE 'pkm %'
                ) THEN ?
                ELSE UPPER(TRIM(wali_nama))
            END,
            wali_no_hp = CASE WHEN TRIM(wali_no_hp) = '' THEN ? ELSE wali_no_hp END,
            wali_instansi_puskesmas = CASE WHEN TRIM(wali_instansi_puskesmas) = '' THEN ? ELSE wali_instansi_puskesmas END,
            wali_tanggal_lahir = CASE WHEN TRIM(wali_tanggal_lahir) = '' THEN ? ELSE wali_tanggal_lahir END,
            wali_jenis_kelamin = CASE WHEN TRIM(wali_jenis_kelamin) = '' THEN ? ELSE wali_jenis_kelamin END
        WHERE license_key_id = ?
    ");
    $profile_sync_query->execute([
        $wali_nik,
        $wali_nama,
        $wali_nama,
        $operator_full_name,
        $wali_instansi,
        $wali_nama,
        $wali_no_hp,
        $wali_instansi,
        $wali_tanggal_lahir,
        $wali_jenis_kelamin,
        $license_key_id,
    ]);
}

function trim_limit($value, int $max_len): string
{
    return substr(trim((string) $value), 0, $max_len);
}

function normalize_date_string($value): string
{
    $value = trim((string) $value);
    if ($value === '') return '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function profile_secret_key(): string
{
    return trim((string) getenv('RMIK_PROFILE_SECRET'));
}

function encrypt_profile_value(string $value): string
{
    if ($value === '')
        return '';

    $secret = profile_secret_key();
    if ($secret === '')
        return $value;

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false || $tag === '')
        return '';

    return 'enc:v1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
}

function decrypt_profile_value(string $value): string
{
    $raw = trim($value);
    if ($raw === '')
        return '';

    if (!str_starts_with($raw, 'enc:v1:'))
        return $raw;

    $secret = profile_secret_key();
    if ($secret === '')
        return '';

    $parts = explode(':', $raw, 5);
    if (count($parts) !== 5)
        return '';

    $iv = base64_decode($parts[2], true);
    $tag = base64_decode($parts[3], true);
    $cipher = base64_decode($parts[4], true);
    if ($iv === false || $tag === false || $cipher === false)
        return '';

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : (string) $plain;
}

function empty_profile_payload(): array
{
    return [
        'account_email' => '',
        'account_password' => '',
        'wali_nik' => '',
        'wali_nama' => '',
        'wali_no_hp' => '',
        'wali_instansi_puskesmas' => '',
        'wali_tanggal_lahir' => '',
        'wali_jenis_kelamin' => 'Perempuan',
    ];
}

try {
    $db = db();
    ensure_license_profiles_table($db);
    $lic = api_auth_no_quota();
    ensure_profile_row($db, (int)$lic['id']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("
            SELECT
                account_email,
                account_password,
                wali_nik,
                wali_nama,
                wali_no_hp,
                wali_instansi_puskesmas,
                wali_tanggal_lahir,
                wali_jenis_kelamin
            FROM license_profiles
            WHERE license_key_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $lic['id']]);
        $row = $stmt->fetch() ?: [];
        if (isset($row['account_password']))
            $row['account_password'] = decrypt_profile_value((string) $row['account_password']);
        $row['wali_nama'] = to_upper_text((string)($row['wali_nama'] ?? ''), 150);

        json_response([
            'ok' => true,
            'data' => array_merge(empty_profile_payload(), $row),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        json_response(['error' => 'Method not allowed'], 405);

    $payload = [
        'account_email' => trim_limit($_POST['account_email'] ?? '', 255),
        'account_password' => trim_limit($_POST['account_password'] ?? '', 255),
        'wali_nik' => trim_limit($_POST['wali_nik'] ?? '', 32),
        'wali_nama' => to_upper_text($_POST['wali_nama'] ?? '', 150),
        'wali_no_hp' => trim_limit($_POST['wali_no_hp'] ?? '', 32),
        'wali_instansi_puskesmas' => trim_limit($_POST['wali_instansi_puskesmas'] ?? '', 160),
        'wali_tanggal_lahir' => normalize_date_string($_POST['wali_tanggal_lahir'] ?? ''),
        'wali_jenis_kelamin' => trim_limit($_POST['wali_jenis_kelamin'] ?? 'Perempuan', 20),
    ];

    if ($payload['wali_no_hp'] === '')
        $payload['wali_no_hp'] = normalize_text_value($lic['no_hp'] ?? '', 32);
    if ($payload['wali_instansi_puskesmas'] === '')
        $payload['wali_instansi_puskesmas'] = normalize_text_value($lic['full_name'] ?? '', 160);

    if ($payload['wali_jenis_kelamin'] === '') {
        $payload['wali_jenis_kelamin'] = 'Perempuan';
    }

    $db->prepare("
        INSERT INTO license_profiles (
            license_key_id,
            account_email,
            account_password,
            wali_nik,
            wali_nama,
            wali_no_hp,
            wali_instansi_puskesmas,
            wali_tanggal_lahir,
            wali_jenis_kelamin
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            account_email = VALUES(account_email),
            account_password = VALUES(account_password),
            wali_nik = VALUES(wali_nik),
            wali_nama = VALUES(wali_nama),
            wali_no_hp = VALUES(wali_no_hp),
            wali_instansi_puskesmas = VALUES(wali_instansi_puskesmas),
            wali_tanggal_lahir = VALUES(wali_tanggal_lahir),
            wali_jenis_kelamin = VALUES(wali_jenis_kelamin)
    ")->execute([
        (int) $lic['id'],
        $payload['account_email'],
        encrypt_profile_value((string) $payload['account_password']),
        $payload['wali_nik'],
        $payload['wali_nama'],
        $payload['wali_no_hp'],
        $payload['wali_instansi_puskesmas'],
        $payload['wali_tanggal_lahir'],
        $payload['wali_jenis_kelamin'],
    ]);

    json_response([
        'ok' => true,
        'data' => $payload,
    ]);
} catch (Throwable $t) {
    json_response(['error' => 'Server error'], 500);
}
