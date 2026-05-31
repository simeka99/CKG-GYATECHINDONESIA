<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/db.php';


if (!function_exists('h')) {
    function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

function ensure_csrf_token(): void
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '')
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string
{
    return (string) ($_SESSION['csrf_token'] ?? '');
}

function is_valid_csrf_token(?string $token): bool
{
    $session_token = csrf_token();
    if ($session_token === '')
        return false;
    if (!is_string($token) || $token === '')
        return false;
    return hash_equals($session_token, $token);
}


function auth_check(?string $role = null): void
{
    $area = ($role === 'admin') ? 'admin' : 'operator';
    start_session_for($area);
    ensure_csrf_token();

    $idle_limit = 3600;
    if (!empty($_SESSION['last_activity'])) {
        if ((time() - (int) $_SESSION['last_activity']) > $idle_limit) {
            $_SESSION['flash_success'] = 'Sesi berakhir karena tidak aktif selama 1 jam.';
            session_unset();
            session_destroy();
            header('Location: ' . APP_URL . '/auth/login.php');
            exit;
        }
    }

    $_SESSION['last_activity'] = time();

    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/auth/login.php');
        exit;
    }

    if ($_SESSION['role'] !== 'admin' && !is_access_valid((int) $_SESSION['user_id'])) {
        session_destroy();
        header('Location: ' . APP_URL . '/auth/login.php?expired=1');
        exit;
    }

    if ($role && $_SESSION['role'] !== $role) {
        if ($_SESSION['role'] === 'operator')
            header('Location: ' . APP_URL . '/user/');
        else {
            http_response_code(403);
            die('<h3 style="font-family:sans-serif;color:#dc2626;padding:2rem">403 — Akses Ditolak</h3>');
        }
        exit;
    }
}


function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function get_scope_mode(): string
{
    $input_scope_mode = strtolower(trim((string) ($_GET['scope'] ?? '')));
    if (in_array($input_scope_mode, ['umum', 'sekolah'], true))
        $_SESSION['ckg_scope_mode'] = $input_scope_mode;

    $session_scope_mode = strtolower(trim((string) ($_SESSION['ckg_scope_mode'] ?? '')));
    if (!in_array($session_scope_mode, ['umum', 'sekolah'], true))
        $session_scope_mode = 'umum';
    $_SESSION['ckg_scope_mode'] = $session_scope_mode;
    return $session_scope_mode;
}

function scope_label(string $scope_mode): string
{
    return $scope_mode === 'sekolah' ? 'Sekolah' : 'Umum';
}

function ensure_scope_column(PDO $db, string $table_name): void
{
    $stmt = $db->prepare("SHOW COLUMNS FROM {$table_name} LIKE 'ckg_scope'");
    $stmt->execute();
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exists)
        $db->exec("ALTER TABLE {$table_name} ADD COLUMN ckg_scope VARCHAR(20) NOT NULL DEFAULT 'umum' AFTER user_id");
}

function ensure_table_index(PDO $db, string $table_name, string $index_name, array $columns): void
{
    $stmt = $db->prepare("SHOW INDEX FROM `{$table_name}` WHERE Key_name = ?");
    $stmt->execute([$index_name]);
    if ($stmt->fetch(PDO::FETCH_ASSOC))
        return;

    $column_sql = implode(',', array_map(function ($column_name) {
        return '`' . str_replace('`', '', (string)$column_name) . '`';
    }, $columns));

    try {
        $db->exec("CREATE INDEX `{$index_name}` ON `{$table_name}` ({$column_sql})");
    } catch (Throwable $e) {
    }
}

function ensure_jobs_performance_indexes(PDO $db): void
{
    $flag = sys_get_temp_dir() . '/rmik_jobs_idx_ok';
    if (file_exists($flag) && (time() - filemtime($flag)) < 86400)
        return;

    $index_map = [
        'patients_data' => [
            'idx_patients_user_scope_id' => ['user_id', 'ckg_scope', 'id'],
            'idx_patients_user_scope_upload' => ['user_id', 'ckg_scope', 'upload_id', 'id'],
            'idx_patients_user_scope_status' => ['user_id', 'ckg_scope', 'daftar_done', 'layanan_done', 'id'],
        ],
        'job_queue' => [
            'idx_job_queue_user_lk_status_patient' => ['user_id', 'license_key_id', 'status', 'patient_id', 'id'],
            'idx_job_queue_user_patient_status' => ['user_id', 'patient_id', 'status', 'id'],
            'idx_job_queue_user_task_patient_status' => ['user_id', 'task_type', 'patient_id', 'status', 'id'],
        ],
        'job_failed' => [
            'idx_job_failed_user_lk_patient' => ['user_id', 'license_key_id', 'patient_id', 'id'],
            'idx_job_failed_user_patient' => ['user_id', 'patient_id', 'id'],
            'idx_job_failed_user_lk_retry' => ['user_id', 'license_key_id', 'is_no_retry', 'patient_id', 'id'],
        ],
        'job_failed_x' => [
            'idx_job_failed_x_user_lk_patient' => ['user_id', 'license_key_id', 'patient_id', 'id'],
            'idx_job_failed_x_user_patient' => ['user_id', 'patient_id', 'id'],
            'idx_job_failed_x_user_reg_patient' => ['user_id', 'reg_code', 'patient_id', 'id'],
        ],
        'job_success' => [
            'idx_job_success_user_lk_patient' => ['user_id', 'license_key_id', 'patient_id', 'id'],
            'idx_job_success_user_task_patient' => ['user_id', 'task_type', 'patient_id', 'id'],
            'idx_job_success_user_finished' => ['user_id', 'finished_at', 'patient_id', 'id'],
        ],
        'license_keys' => [
            'idx_license_keys_user_active_mode' => ['user_id', 'is_active', 'mode', 'id'],
            'idx_license_keys_user_active_task' => ['user_id', 'is_active', 'task_type', 'id'],
        ],
    ];

    foreach ($index_map as $table_name => $table_indexes) {
        foreach ($table_indexes as $index_name => $column_names) {
            ensure_table_index($db, $table_name, $index_name, $column_names);
        }
    }

    @file_put_contents($flag, '1');
}


function current_user(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    if (empty($_SESSION['user_id'])) return $cache = [];
    $s = db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $s->execute([(int) $_SESSION['user_id']]);
    $cache = $s->fetch() ?: [];
    return $cache;
}


function is_access_valid(int $user_id): bool
{
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];

    $stmt = db()->prepare("
        SELECT is_active, subscription_type, subscription_end, quota_total, quota_used
        FROM users WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();

    if (!$u || !$u['is_active']) return $cache[$user_id] = false;

    if ($u['subscription_type'] === 'quota')
        return $cache[$user_id] = ((int) $u['quota_total'] > 0 && (int) $u['quota_used'] < (int) $u['quota_total']);

    if (empty($u['subscription_end'])) return $cache[$user_id] = false;
    $today = new DateTime(date('Y-m-d'));
    $end = new DateTime(date('Y-m-d', strtotime($u['subscription_end'])));
    return $cache[$user_id] = ($today <= $end);
}


function subscription_days_left(int $user_id): ?int
{
    static $cache = [];
    if (array_key_exists($user_id, $cache)) return $cache[$user_id];

    $user = current_user();
    if (empty($user) || (int)($user['id'] ?? 0) !== $user_id) {
        $s = db()->prepare("SELECT subscription_type, subscription_end FROM users WHERE id = ? LIMIT 1");
        $s->execute([$user_id]);
        $u = $s->fetch();
    } else {
        $u = $user;
    }

    if (!$u) return $cache[$user_id] = 0;
    if ($u['subscription_type'] === 'quota') return $cache[$user_id] = null;
    if (empty($u['subscription_end'])) return $cache[$user_id] = 0;

    $today = new DateTime(date('Y-m-d'));
    $end = new DateTime(date('Y-m-d', strtotime($u['subscription_end'])));
    if ($today > $end) return $cache[$user_id] = 0;

    return $cache[$user_id] = (int) $today->diff($end)->days;
}


function quota_remaining(int $user_id): int
{
    $u = db()->prepare("SELECT quota_total, quota_used FROM users WHERE id = ? LIMIT 1");
    $u->execute([$user_id]);
    $u = $u->fetch();
    return max(0, (int) ($u['quota_total'] ?? 0) - (int) ($u['quota_used'] ?? 0));
}


function consume_nik_quota(int $patient_id, int $user_id): void
{
    $db = db();
    $p = $db->prepare("SELECT quota_counted FROM patients_data WHERE id = ? AND user_id = ? LIMIT 1");
    $p->execute([$patient_id, $user_id]);
    $pat = $p->fetch();
    if (!$pat || $pat['quota_counted']) return;

    $db->prepare("UPDATE patients_data SET quota_counted = 1 WHERE id = ? AND user_id = ?")
        ->execute([$patient_id, $user_id]);
    $db->prepare("UPDATE users SET quota_used = quota_used + 1 WHERE id = ?")
        ->execute([$user_id]);
}


function can_run_job(int $user_id): bool
{
    $u = db()->prepare("SELECT subscription_type, quota_total, quota_used FROM users WHERE id = ? LIMIT 1");
    $u->execute([$user_id]);
    $u = $u->fetch();
    if (!$u) return false;
    if ($u['subscription_type'] === 'time') return true;
    return (int) $u['quota_used'] < (int) $u['quota_total'];
}


function get_setting(string $key): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $s = db()->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = ? LIMIT 1");
    $s->execute([$key]);
    $cache[$key] = (string) ($s->fetchColumn() ?? '');
    return $cache[$key];
}

function format_nik(string $nik, int $user_id = 0): string
{
    static $hide_cache = [];
    if ($nik === '') return '';
    if ($user_id === 0)
        $user_id = (int) ($_SESSION['user_id'] ?? 0);
    if ($user_id === 0) return $nik;

    if (!array_key_exists($user_id, $hide_cache))
        $hide_cache[$user_id] = get_setting('hide_nik_user_' . $user_id) === '1';

    if ($hide_cache[$user_id]) {
        $len = strlen($nik);
        if ($len <= 6) return str_repeat('*', $len);
        if ($len <= 10) return substr($nik, 0, 2) . str_repeat('*', $len - 4) . substr($nik, -2);
        return substr($nik, 0, 3) . str_repeat('*', $len - 6) . substr($nik, -3);
    }

    return $nik;
}


function save_setting(string $key, string $value): void
{
    $db = db();
    if ($db->inTransaction()) {
        $db->prepare("DELETE FROM admin_settings WHERE setting_key = ?")->execute([$key]);
        $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?)")
            ->execute([$key, $value]);
        return;
    }

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM admin_settings WHERE setting_key = ?")->execute([$key]);
        $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?)")
            ->execute([$key, $value]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction())
            $db->rollBack();
        throw $e;
    }
}


function ensure_user_bpjs_sync_settings_table(): void
{
    return;
}


function bpjs_sync_allowed_fields(): array
{
    return ['nik', 'nama', 'jenis_kelamin', 'tgl_lahir', 'no_hp'];
}

function normalize_phone_number_value(string $value): string
{
    $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
    if ($digits === '')
        return '';
    if (str_starts_with($digits, '0'))
        $digits = '62' . substr($digits, 1);
    elseif (str_starts_with($digits, '8'))
        $digits = '62' . $digits;

    $len = strlen($digits);
    if ($len < 10 || $len > 16)
        return '';
    return $digits;
}


function normalize_user_bpjs_sync_settings(array $settings): array
{
    $allowed = bpjs_sync_allowed_fields();
    $allowed_map = array_flip($allowed);

    $raw_fields = $settings['sync_fields'] ?? $allowed;
    if (!is_array($raw_fields))
        $raw_fields = explode(',', (string) $raw_fields);

    $sync_fields = [];
    foreach ($raw_fields as $field) {
        $field = strtolower(trim((string) $field));
        if ($field === '' || !isset($allowed_map[$field])) continue;
        if (!in_array($field, $sync_fields, true))
            $sync_fields[] = $field;
    }

    if (!$sync_fields)
        $sync_fields = $allowed;

    $phone_auto_fallback = !empty($settings['phone_auto_fallback']) ? 1 : 0;
    $phone_fallback_number = normalize_phone_number_value((string) ($settings['phone_fallback_number'] ?? ''));

    return [
        'sync_fields' => $sync_fields,
        'phone_auto_fallback' => $phone_auto_fallback,
        'phone_fallback_number' => $phone_fallback_number,
    ];
}


function get_user_bpjs_sync_settings(int $user_id): array
{
    return normalize_user_bpjs_sync_settings([
        'sync_fields' => get_setting('bpjs_sync_fields_user_' . $user_id),
        'phone_auto_fallback' => (int) get_setting('bpjs_phone_auto_fallback_user_' . $user_id),
        'phone_fallback_number' => get_setting('bpjs_phone_fallback_number_user_' . $user_id),
    ]);
}


function save_user_bpjs_sync_settings(int $user_id, array $settings): void
{
    $normalized = normalize_user_bpjs_sync_settings($settings);
    $sync_fields = implode(',', $normalized['sync_fields']);
    $phone_auto_fallback = (int) $normalized['phone_auto_fallback'];
    $phone_fallback_number = (string) ($normalized['phone_fallback_number'] ?? '');

    save_setting('bpjs_sync_fields_user_' . $user_id, $sync_fields);
    save_setting('bpjs_phone_auto_fallback_user_' . $user_id, (string) $phone_auto_fallback);
    save_setting('bpjs_phone_fallback_number_user_' . $user_id, $phone_fallback_number);
}


function generate_license_key(): string
{
    return 'GYA-'
        . strtoupper(bin2hex(random_bytes(4))) . '-'
        . strtoupper(bin2hex(random_bytes(4)));
}

function get_device_token_request(): string
{
    return trim((string) ($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ''));
}

function validate_device_token(array $license): void
{
    $saved_token = trim((string) ($license['device_auth_token'] ?? ''));
    if ($saved_token === '')
        return;

    $request_token = get_device_token_request();
    if ($request_token === '')
        json_response(['error' => 'Device token wajib dikirim', 'code' => 'DEVICE_TOKEN_REQUIRED'], 401);

    if (!hash_equals($saved_token, $request_token))
        json_response(['error' => 'Device token tidak valid', 'code' => 'DEVICE_TOKEN_INVALID'], 403);
}


function api_auth(): array
{
    $key = $_SERVER['HTTP_X_LICENSE_KEY'] ?? '';
    $dev = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';

    if (!$key)
        json_response(['error' => 'License key required', 'code' => 'NO_KEY'], 401);

    if (!$dev)
        json_response(['error' => 'Device ID required', 'code' => 'NO_DEVICE'], 401);

    $stmt = db()->prepare("
        SELECT lk.*, u.id AS user_id, u.is_active AS user_active,
               u.subscription_type, u.subscription_end,
               u.quota_total, u.quota_used,
               u.username, u.full_name, u.no_hp
        FROM license_keys lk
        JOIN users u ON lk.user_id = u.id
        WHERE lk.license_key = ? AND lk.is_active = 1 AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$key]);
    $lic = $stmt->fetch();

    if (!$lic)
        json_response(['error' => 'License tidak valid atau telah dicabut', 'code' => 'LICENSE_REVOKED'], 401);

    if (!empty($lic['device_id']) && $lic['device_id'] !== $dev)
        json_response(['error' => 'License key ini sudah terikat ke perangkat lain', 'code' => 'DEVICE_MISMATCH'], 403);
    validate_device_token($lic);

    if ($lic['subscription_type'] === 'time') {
        if (empty($lic['subscription_end']))
            json_response(['error' => 'Subscription belum diset', 'code' => 'SUBSCRIPTION_NOT_SET'], 403);
        $today = new DateTime(date('Y-m-d'));
        $end = new DateTime(date('Y-m-d', strtotime($lic['subscription_end'])));
        if ($today > $end)
            json_response(['error' => 'Subscription expired', 'code' => 'SUBSCRIPTION_EXPIRED'], 403);
    } else {
        if ((int) $lic['quota_used'] >= (int) $lic['quota_total'])
            json_response(['error' => 'Quota NIK habis', 'code' => 'QUOTA_EMPTY'], 403);
    }

    db()->prepare("UPDATE license_keys SET last_seen = NOW() WHERE id = ?")
        ->execute([$lic['id']]);

    return $lic;
}


function api_auth_no_quota(): array
{
    $key = $_SERVER['HTTP_X_LICENSE_KEY'] ?? '';
    $dev = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';

    if (!$key)
        json_response(['error' => 'License key required', 'code' => 'NO_KEY'], 401);

    if (!$dev)
        json_response(['error' => 'Device ID required', 'code' => 'NO_DEVICE'], 401);

    $stmt = db()->prepare("
        SELECT lk.*, u.id AS user_id, u.is_active AS user_active,
               u.subscription_type, u.subscription_end,
               u.quota_total, u.quota_used,
               u.username, u.full_name, u.no_hp
        FROM license_keys lk
        JOIN users u ON lk.user_id = u.id
        WHERE lk.license_key = ? AND lk.is_active = 1 AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$key]);
    $lic = $stmt->fetch();

    if (!$lic)
        json_response(['error' => 'License tidak valid atau telah dicabut', 'code' => 'LICENSE_REVOKED'], 401);

    if (!empty($lic['device_id']) && $lic['device_id'] !== $dev)
        json_response(['error' => 'License key ini sudah terikat ke perangkat lain', 'code' => 'DEVICE_MISMATCH'], 403);
    validate_device_token($lic);

    db()->prepare("UPDATE license_keys SET last_seen = NOW() WHERE id = ?")
        ->execute([$lic['id']]);

    return $lic;
}


function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if (!function_exists('system_log_error')) {
    function system_log_error(string $message, string $context = 'System', ?Throwable $e = null): void
    {
        $log_dir = __DIR__ . '/../logs';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $date = date('Y-m-d');
        $time = date('Y-m-d H:i:s');
        $log_file = $log_dir . '/system_error_' . $date . '.log';

        $error_msg = "[{$time}] [{$context}] {$message}";
        if ($e) {
            $error_msg .= " | Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
        }
        $error_msg .= PHP_EOL;

        @file_put_contents($log_file, $error_msg, FILE_APPEND);
    }
}
