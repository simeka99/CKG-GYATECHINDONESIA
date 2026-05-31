<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
auth_check('admin');
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'Aksi tidak valid (CSRF). Silakan muat ulang halaman.';
        header('Location: ../users.php');
        exit;
    }
} elseif (isset($_GET['repair_profiles']) || isset($_GET['toggle']) || isset($_GET['delete'])) {
    if (!is_valid_csrf_token((string)($_GET['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'Aksi tidak valid (CSRF). Silakan muat ulang halaman.';
        header('Location: ../users.php');
        exit;
    }
}

try {
    $db->exec("ALTER TABLE users ADD COLUMN portal_access TINYINT(1) NOT NULL DEFAULT 1");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE users ADD COLUMN default_wali_profile LONGTEXT NULL");
} catch (Exception $e) {
}

if (!function_exists('default_wali_profile_template')) {
    function default_wali_profile_template(): array
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
}

if (!function_exists('sanitize_wali_date')) {
    function sanitize_wali_date(string $value): string
    {
        $raw = trim($value);
        if ($raw === '')
            return '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
    }
}

if (!function_exists('to_upper_text')) {
    function to_upper_text(string $value, int $max_len): string
    {
        $text_value = trim($value);
        if ($text_value === '')
            return '';
        if (function_exists('mb_strtoupper'))
            $text_value = mb_strtoupper($text_value, 'UTF-8');
        else
            $text_value = strtoupper($text_value);
        return substr($text_value, 0, $max_len);
    }
}

if (!function_exists('build_default_wali_profile')) {
    function build_default_wali_profile(array $post, string $prefix = '', string $full_name = '', string $no_hp = ''): array
    {
        $profile = default_wali_profile_template();
        $profile['wali_nik'] = substr(trim((string)($post[$prefix . 'wali_nik'] ?? '')), 0, 32);
        $profile['wali_nama'] = to_upper_text((string)($post[$prefix . 'wali_nama'] ?? ''), 150);
        $profile['wali_no_hp'] = substr(trim($no_hp), 0, 32);
        $profile['wali_instansi_puskesmas'] = substr(trim((string)($post[$prefix . 'wali_instansi_puskesmas'] ?? '')), 0, 160);
        $profile['wali_tanggal_lahir'] = sanitize_wali_date((string)($post[$prefix . 'wali_tanggal_lahir'] ?? ''));
        $profile['wali_jenis_kelamin'] = substr(trim((string)($post[$prefix . 'wali_jenis_kelamin'] ?? 'Perempuan')), 0, 20) ?: 'Perempuan';
        if ($profile['wali_instansi_puskesmas'] === '')
            $profile['wali_instansi_puskesmas'] = substr(trim($full_name), 0, 160);

        return $profile;
    }
}

if (!function_exists('decode_default_wali_profile')) {
    function decode_default_wali_profile(mixed $value): array
    {
        $template = default_wali_profile_template();
        if (!is_string($value) || trim($value) === '')
            return $template;
        $decoded = json_decode($value, true);
        if (!is_array($decoded))
            return $template;
        return array_merge($template, $decoded);
    }
}

if (!function_exists('encode_default_wali_profile')) {
    function encode_default_wali_profile(array $profile): string
    {
        return json_encode(array_merge(default_wali_profile_template(), $profile), JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('ensure_license_profiles_table_admin')) {
    function ensure_license_profiles_table_admin(PDO $db): void
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
                PRIMARY KEY (license_key_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

if (!function_exists('sync_wali_profile_to_operator_licenses')) {
    function sync_wali_profile_to_operator_licenses(PDO $db, int $user_id, array $profile, string $operator_full_name = ''): void
    {
        ensure_license_profiles_table_admin($db);

        $license_rows = $db->prepare("SELECT id FROM license_keys WHERE user_id = ?");
        $license_rows->execute([$user_id]);
        $license_ids = $license_rows->fetchAll(PDO::FETCH_COLUMN);
        if (!$license_ids)
            return;

        $insert_query = $db->prepare("INSERT IGNORE INTO license_profiles (license_key_id) VALUES (?)");
        $update_query = $db->prepare("
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
                    ELSE wali_nama
                END,
                wali_no_hp = CASE WHEN ? <> '' THEN ? WHEN TRIM(wali_no_hp) = '' THEN ? ELSE wali_no_hp END,
                wali_instansi_puskesmas = CASE WHEN TRIM(wali_instansi_puskesmas) = '' THEN ? ELSE wali_instansi_puskesmas END,
                wali_tanggal_lahir = CASE WHEN TRIM(wali_tanggal_lahir) = '' THEN ? ELSE wali_tanggal_lahir END,
                wali_jenis_kelamin = CASE WHEN TRIM(wali_jenis_kelamin) = '' THEN ? ELSE wali_jenis_kelamin END
            WHERE license_key_id = ?
        ");

        foreach ($license_ids as $license_id) {
            $license_id = (int)$license_id;
            $insert_query->execute([$license_id]);
            $update_query->execute([
                $profile['wali_nik'],
                $profile['wali_nama'],
                $profile['wali_nama'],
                $operator_full_name,
                $profile['wali_instansi_puskesmas'],
                $profile['wali_nama'],
                $profile['wali_no_hp'],
                $profile['wali_no_hp'],
                $profile['wali_no_hp'],
                $profile['wali_instansi_puskesmas'],
                $profile['wali_tanggal_lahir'],
                $profile['wali_jenis_kelamin'],
                $license_id,
            ]);
        }
    }
}

if (!function_exists('sync_all_operator_wali_profiles')) {
    function sync_all_operator_wali_profiles(PDO $db): int
    {
        $operator_rows = $db->query("SELECT id, full_name, no_hp, default_wali_profile FROM users WHERE role = 'operator'")->fetchAll(PDO::FETCH_ASSOC);
        if (!$operator_rows)
            return 0;
        $synced_total = 0;
        foreach ($operator_rows as $operator_row) {
            $profile = decode_default_wali_profile($operator_row['default_wali_profile'] ?? '');
            if (trim((string)$profile['wali_no_hp']) === '')
                $profile['wali_no_hp'] = substr(trim((string)($operator_row['no_hp'] ?? '')), 0, 32);
            if (trim((string)$profile['wali_instansi_puskesmas']) === '')
                $profile['wali_instansi_puskesmas'] = substr(trim((string)($operator_row['full_name'] ?? '')), 0, 160);
            sync_wali_profile_to_operator_licenses($db, (int)$operator_row['id'], $profile, (string)($operator_row['full_name'] ?? ''));
            $synced_total++;
        }
        return $synced_total;
    }
}

if (!function_exists('calc_subscription')) {
    function calc_subscription(array $post, string $prefix = '')
    {
        $type = $post[$prefix . 'sub_main'] ?? 'time';
        $pkg  = $post[$prefix . 'sub_pkg']  ?? '1bulan';

        $quota_key   = ($prefix === 'edit_') ? 'edit_new_quota_tot' : 'quota_total';

        $result = [
            'subscription_type'  => $type,
            'subscription_start' => date('Y-m-d H:i:s'),
            'subscription_end'   => null,
            'subscription_note'  => '',
            'quota_total'        => 0,
        ];

        if ($type === 'quota') {
            $q = max(1, (int)($post[$quota_key] ?? 0));
            $result['quota_total']       = $q;
            $result['subscription_note'] = 'Paket NIK: ' . number_format($q) . ' orang';
            return $result;
        }

        $map = [
            'trial1'  => [1,   'Trial 1 Hari'],
            '1minggu' => [7,   'Sewa 1 Minggu'],
            '1bulan'  => [30,  'Sewa 1 Bulan'],
            '3bulan'  => [90,  'Sewa 3 Bulan'],
            '6bulan'  => [180, 'Sewa 6 Bulan'],
            '1tahun'  => [365, 'Sewa 1 Tahun'],
            'custom'  => [(int)($post[$prefix . 'sub_days'] ?? 30), 'Custom ' . (int)($post[$prefix . 'sub_days'] ?? 30) . ' Hari'],
        ];

        $days = $map[$pkg][0] ?? 30;
        $note = $map[$pkg][1] ?? 'Custom';

        $result['subscription_end']  = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $result['subscription_note'] = $note;
        return $result;
    }
}

if (!function_exists('admin_days_left')) {
    function admin_days_left(?string $end_str): int
    {
        if (!$end_str) return 0;
        $today = new DateTime(date('Y-m-d'));
        $end   = new DateTime(date('Y-m-d', strtotime($end_str)));
        if ($today > $end) return 0;
        return (int)$today->diff($end)->days;
    }
}

if (!function_exists('build_license_quota_json')) {
    function build_license_quota_json(array $post, bool $extension_only = false): string
    {
        $quotas = [
            'pendaftaran_umum' => $extension_only ? 0 : max(0, (int)($post['lq_pendaftaran_umum'] ?? 0)),
            'pelayanan_umum' => $extension_only ? 0 : max(0, (int)($post['lq_pelayanan_umum'] ?? 0)),
            'pendaftaran_sekolah' => $extension_only ? 0 : max(0, (int)($post['lq_pendaftaran_sekolah'] ?? 0)),
            'pelayanan_sekolah' => $extension_only ? 0 : max(0, (int)($post['lq_pelayanan_sekolah'] ?? 0)),
            'extension_bpjs' => max(0, (int)($post['lq_extension_bpjs'] ?? 0)),
        ];

        return json_encode($quotas);
    }
}

if (!function_exists('normalize_operator_instansi')) {
    function normalize_operator_instansi(string $full_name): string
    {
        $base_name = strtolower(trim($full_name));
        $base_name = preg_replace('/[^a-z0-9]+/u', ' ', (string)$base_name);
        $base_name = preg_replace('/\s+/u', ' ', (string)$base_name);
        $base_name = trim((string)$base_name);
        if (!$base_name)
            return '';
        $base_name = preg_replace('/^pkm\b/u', 'puskesmas', (string)$base_name);
        if (!preg_match('/^puskesmas\b/u', (string)$base_name))
            $base_name = 'puskesmas ' . $base_name;
        return strtoupper((string)$base_name);
    }
}

if (!function_exists('generate_dashboard_username')) {
    function generate_dashboard_username(PDO $db, string $full_name): string
    {
        $normalized_name = normalize_operator_instansi($full_name);
        $normalized_name = preg_replace('/\bpuskesmas\b/iu', 'pkm', (string)$normalized_name);
        $slug_name = strtolower((string)preg_replace('/[^a-z0-9]+/', '_', $normalized_name));
        $slug_name = trim((string)preg_replace('/_+/', '_', $slug_name), '_');
        $slug_name = $slug_name ?: 'operator';
        $base_name = substr('gya_' . $slug_name, 0, 32);

        $check_query = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check_query->execute([$base_name]);
        if (!$check_query->fetch())
            return $base_name;

        for ($counter = 1; $counter <= 9999; $counter++) {
            $suffix = (string)$counter;
            $base_length = max(1, 32 - strlen($suffix));
            $candidate_name = substr($base_name, 0, $base_length) . $suffix;
            $check_query = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $check_query->execute([$candidate_name]);
            if (!$check_query->fetch())
                return $candidate_name;
        }

        return substr('gya_operator' . strtolower(bin2hex(random_bytes(3))), 0, 32);
    }
}

if (!function_exists('generate_dashboard_password')) {
    function generate_dashboard_password(string $full_name): string
    {
        $normalized_name = normalize_operator_instansi($full_name);
        $normalized_name = preg_replace('/\bpuskesmas\b/iu', 'pkm', (string)$normalized_name);
        $token_name = strtolower($normalized_name ?: 'pkm');
        $token_name = preg_replace('/\s+/', '_', (string)$token_name);
        $token_name = trim((string)preg_replace('/_+/', '_', (string)$token_name), '_');
        return substr('gya_' . ($token_name ?: 'pkm'), 0, 64);
    }
}

if (!function_exists('generate_internal_extension_username')) {
    function generate_internal_extension_username(PDO $db, string $full_name = '', string $no_hp = ''): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '', strtolower((string) $full_name));
        $base = $base ?: preg_replace('/\D+/', '', (string) $no_hp);
        $base = $base ?: 'operator';
        $base = substr($base, 0, 12);

        $chk = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->execute([$base]);
        if (!$chk->fetch())
            return $base;

        for ($i = 1; $i <= 9999; $i++) {
            $suffix = (string) $i;
            $base_len = max(1, 12 - strlen($suffix));
            $candidate = substr($base, 0, $base_len) . $suffix;
            $chk = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$candidate]);
            if (!$chk->fetch())
                return $candidate;
        }

        return substr('operator' . strtolower(bin2hex(random_bytes(4))), 0, 12);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $access_type = ($_POST['access_type'] ?? 'dashboard') === 'extension' ? 'extension' : 'dashboard';
    $username  = trim($_POST['username']  ?? '');
    $password  = $_POST['password']       ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $no_hp     = trim($_POST['no_hp']     ?? '');
    $portal_access = $access_type === 'extension' ? 0 : 1;
    $default_wali_profile = build_default_wali_profile($_POST, '', $full_name, $no_hp);

    if ($access_type === 'extension') {
        if (!$full_name || !$no_hp) {
            $_SESSION['flash_error'] = 'Untuk user BPJS Auto Fill Skrining, nama dan no handphone wajib diisi.';
            ob_end_clean();
            header('Location: ../users.php');
            exit;
        }

        if ((int)($_POST['lq_extension_bpjs'] ?? 0) <= 0) {
            $_SESSION['flash_error'] = 'Kuota license BPJS Auto Fill Skrining minimal 1.';
            ob_end_clean();
            header('Location: ../users.php');
            exit;
        }

        $username = generate_internal_extension_username($db, $full_name, $no_hp);
        $password = bin2hex(random_bytes(12));
    }

    if ($access_type === 'dashboard') {
        $full_name = normalize_operator_instansi($full_name);
        if (!$username) $username = generate_dashboard_username($db, $full_name);
        if (!$password) $password = generate_dashboard_password($full_name);
    }

    if (!$username || !$password) {
        $_SESSION['flash_error'] = 'Username dan password wajib diisi.';
        ob_end_clean();
        header('Location: ../users.php');
        exit;
    } else {
        $chk = $db->prepare("SELECT id FROM users WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $_SESSION['flash_error'] = 'Username sudah digunakan.';
            ob_end_clean();
            header('Location: ../users.php');
            exit;
        } else {
            $sub  = calc_subscription($_POST);
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);
            $lq = build_license_quota_json($_POST, $access_type === 'extension');
            $db->prepare("
                INSERT INTO users
                    (username, password, full_name, no_hp, role, is_active, portal_access,
                     subscription_type, subscription_start, subscription_end,
                     subscription_note, quota_total, license_quota, default_wali_profile)
                VALUES (?,?,?,?,'operator',1,?,?,?,?,?,?,?,?)
            ")->execute([
                $username,
                $hash,
                $full_name,
                $no_hp,
                $portal_access,
                $sub['subscription_type'],
                $sub['subscription_start'],
                $sub['subscription_end'],
                $sub['subscription_note'],
                $sub['quota_total'],
                $lq,
                encode_default_wali_profile($default_wali_profile),
            ]);
            $new_user_id = (int)$db->lastInsertId();
            sync_wali_profile_to_operator_licenses($db, $new_user_id, $default_wali_profile, $full_name);
            $_SESSION['flash_success'] = ($access_type === 'extension'
                ? 'User BPJS Auto Fill Skrining <b>' . h($full_name ?: $username) . '</b> berhasil ditambahkan.'
                : 'Operator <b>' . h($full_name ?: $username) . '</b> berhasil ditambahkan.');
            ob_end_clean();
            header('Location: ../users.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $access_type    = ($_POST['access_type'] ?? 'dashboard') === 'extension' ? 'extension' : 'dashboard';
    $uid           = (int)($_POST['user_id']      ?? 0);
    $edit_username = trim($_POST['edit_username'] ?? '');
    $full_name     = trim($_POST['full_name']     ?? '');
    $no_hp         = trim($_POST['no_hp']         ?? '');
    $new_pass      = trim($_POST['new_password']  ?? '');
    $is_active     = isset($_POST['is_active']) ? 1 : 0;
    $keep_sub      = ($_POST['keep_sub'] ?? '0') === '1';
    $portal_access = $access_type === 'extension' ? 0 : 1;
    $default_wali_profile = build_default_wali_profile($_POST, 'edit_', $full_name, $no_hp);
    $default_wali_profile_json = encode_default_wali_profile($default_wali_profile);

    if ($access_type === 'extension') {
        if (!$full_name || !$no_hp) {
            $_SESSION['flash_error'] = 'Untuk user BPJS Auto Fill Skrining, nama dan no handphone wajib diisi.';
            ob_end_clean();
            header('Location: ../users.php');
            exit;
        }
        if ((int)($_POST['lq_extension_bpjs'] ?? 0) <= 0) {
            $_SESSION['flash_error'] = 'Kuota license BPJS Auto Fill Skrining minimal 1.';
            ob_end_clean();
            header('Location: ../users.php');
            exit;
        }
        if (!$edit_username) {
            $edit_username = generate_internal_extension_username($db, $full_name, $no_hp);
        }
    }

    if (!$edit_username) {
        $_SESSION['flash_error'] = 'Username tidak boleh kosong.';
        ob_end_clean();
        header('Location: ../users.php');
        exit;
    }

    $chk = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $chk->execute([$edit_username, $uid]);
    if ($chk->fetch()) {
        $_SESSION['flash_error'] = 'Username sudah digunakan oleh akun lain.';
        ob_end_clean();
        header('Location: ../users.php');
        exit;
    }

    $lq_keep = build_license_quota_json($_POST, $access_type === 'extension');

    if ($keep_sub) {
        $edit_sub_end   = !empty($_POST['edit_sub_end']) ? $_POST['edit_sub_end'] : null;
        $edit_quota_tot = isset($_POST['edit_quota_tot']) && $_POST['edit_quota_tot'] !== '' ? (int)$_POST['edit_quota_tot'] : null;
        $edit_sub_pkg   = $_POST['edit_sub_pkg'] ?? '';

        $final_sub_end  = $edit_sub_end;
        $final_sub_note = null;

        if ($edit_sub_pkg) {
            $map = [
                '1minggu' => [7,   'Sewa 1 Minggu'],
                '1bulan'  => [30,  'Sewa 1 Bulan'],
                '3bulan'  => [90,  'Sewa 3 Bulan'],
                '6bulan'  => [180, 'Sewa 6 Bulan'],
                '1tahun'  => [365, 'Sewa 1 Tahun'],
            ];
            if (isset($map[$edit_sub_pkg])) {
                $days = $map[$edit_sub_pkg][0];
                $note = $map[$edit_sub_pkg][1];
                $stmt = $db->prepare("SELECT subscription_start FROM users WHERE id=?");
                $stmt->execute([$uid]);
                $start_date = $stmt->fetchColumn();
                if ($start_date) {
                    $ts = strtotime($start_date . " +{$days} days");
                    if ($ts !== false) {
                        $final_sub_end = date('Y-m-d 23:59:59', $ts);
                        $final_sub_note = $note;
                    }
                }
            }
        }

        try {
            if ($new_pass) {
                $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 11]);
                $db->prepare("
                    UPDATE users SET username=?, full_name=?, no_hp=?, is_active=?, portal_access=?, default_wali_profile=?, password=?,
                        subscription_end=COALESCE(?, subscription_end),
                        subscription_note=COALESCE(?, subscription_note),
                        license_quota=?,
                        quota_total=COALESCE(?, quota_total)
                    WHERE id=?
                ")->execute([$edit_username, $full_name, $no_hp, $is_active, $portal_access, $default_wali_profile_json, $hash, $final_sub_end, $final_sub_note, $lq_keep, $edit_quota_tot, $uid]);
            } else {
                $db->prepare("
                    UPDATE users SET username=?, full_name=?, no_hp=?, is_active=?, portal_access=?, default_wali_profile=?,
                        subscription_end=COALESCE(?, subscription_end),
                        subscription_note=COALESCE(?, subscription_note),
                        license_quota=?,
                        quota_total=COALESCE(?, quota_total)
                    WHERE id=?
                ")->execute([$edit_username, $full_name, $no_hp, $is_active, $portal_access, $default_wali_profile_json, $final_sub_end, $final_sub_note, $lq_keep, $edit_quota_tot, $uid]);
            }
        } catch (Exception $e) {
            die("Error UPDATE keep_sub: " . $e->getMessage());
        }
    } else {
        $sub = calc_subscription($_POST, 'edit_');
        if ($new_pass) {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 11]);
            $db->prepare("
                UPDATE users
                SET username=?, full_name=?, no_hp=?, is_active=?, portal_access=?, default_wali_profile=?, password=?,
                    subscription_type=?, subscription_start=?, subscription_end=?,
                    subscription_note=?, quota_total=?, quota_used=0, license_quota=?
                WHERE id=?
            ")->execute([
                $edit_username,
                $full_name,
                $no_hp,
                $is_active,
                $portal_access,
                $default_wali_profile_json,
                $hash,
                $sub['subscription_type'],
                $sub['subscription_start'],
                $sub['subscription_end'],
                $sub['subscription_note'],
                $sub['quota_total'],
                $lq_keep,
                $uid,
            ]);
        } else {
            $db->prepare("
                UPDATE users
                SET username=?, full_name=?, no_hp=?, is_active=?, portal_access=?, default_wali_profile=?,
                    subscription_type=?, subscription_start=?, subscription_end=?,
                    subscription_note=?, quota_total=?, quota_used=0, license_quota=?
                WHERE id=?
            ")->execute([
                $edit_username,
                $full_name,
                $no_hp,
                $is_active,
                $portal_access,
                $default_wali_profile_json,
                $sub['subscription_type'],
                $sub['subscription_start'],
                $sub['subscription_end'],
                $sub['subscription_note'],
                $sub['quota_total'],
                $lq_keep,
                $uid,
            ]);
        }
    }
    sync_wali_profile_to_operator_licenses($db, $uid, $default_wali_profile, $full_name);
    $_SESSION['flash_success'] = $access_type === 'extension'
        ? 'Data user BPJS Auto Fill Skrining berhasil diperbarui.'
        : 'Data operator berhasil diperbarui.';
    ob_end_clean();
    header('Location: ../users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_quota') {
    $uid = (int)($_POST['user_id']   ?? 0);
    $add = max(1, (int)($_POST['add_quota'] ?? 0));
    $db->prepare("UPDATE users SET quota_total = quota_total + ? WHERE id = ?")->execute([$add, $uid]);
    $_SESSION['flash_success'] = 'Berhasil menambah ' . number_format($add) . ' NIK ke kuota.';
    ob_end_clean();
    header('Location: ../users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_quota') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $db->prepare("UPDATE users SET quota_used = 0 WHERE id = ?")->execute([$uid]);
    $_SESSION['flash_success'] = 'Kuota pemakaian berhasil direset ke 0.';
    ob_end_clean();
    header('Location: ../users.php');
    exit;
}

if (isset($_GET['repair_profiles']) && $_GET['repair_profiles'] === '1') {
    $synced_total = sync_all_operator_wali_profiles($db);
    $_SESSION['flash_success'] = 'Sinkronisasi profil wali selesai. Operator diproses: ' . number_format($synced_total) . '.';
    ob_end_clean();
    header('Location: ../users.php');
    exit;
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $uid = (int)$_GET['toggle'];
    $db->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role = 'operator'")->execute([$uid]);
    ob_end_clean();
    header('Location: ../users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $role = $stmt->fetchColumn();

    if ($role === 'operator') {
        $db->prepare("DELETE FROM users WHERE id = ? AND role = 'operator'")->execute([$uid]);
        $_SESSION['flash_success'] = 'Akun operator berhasil dihapus selamanya.';
    } else {
        $_SESSION['flash_error'] = 'Operasi tidak diizinkan.';
    }
    ob_end_clean();
    header('Location: ../users.php');
    exit;
}
