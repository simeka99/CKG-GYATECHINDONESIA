<?php
ob_start();
$page_title = 'Upload Data Peserta';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$uid = (int) $_SESSION['user_id'];
$scope_mode = get_scope_mode();

ensure_scope_column($db, 'patient_uploads');
ensure_scope_column($db, 'patients_data');

$success = $error = '';
$epus_api_url = trim(get_setting('epus_api_url'));
$epus_referer = trim(get_setting('epus_referer'));
$epus_cookie = trim(get_setting('epus_cookie'));
$epus_ready = $epus_api_url !== '' && $epus_cookie !== '';
$epus_gender_input = strtolower(trim((string) ($_POST['epus_gender'] ?? 'all')));
$epus_age_min_input = trim((string) ($_POST['epus_age_min'] ?? ''));
$epus_age_max_input = trim((string) ($_POST['epus_age_max'] ?? ''));

/* 
   HELPERS: Parse File
 */
function col_to_num(string $col): int
{
    $n = 0;
    foreach (str_split(strtoupper($col)) as $c)
        $n = $n * 26 + ord($c) - 64;
    return $n;
}

function xlsx_to_rows(string $path): array
{
    if (!class_exists('ZipArchive'))
        return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true)
        return [];
    $strings = [];
    if (($raw = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = @simplexml_load_string($raw);
        if ($xml)
            foreach ($xml->si as $si) {
                if (isset($si->t))
                    $strings[] = (string) $si->t;
                else {
                    $t = '';
                    foreach ($si->r as $r)
                        $t .= (string) ($r->t ?? '');
                    $strings[] = $t;
                }
            }
    }
    $sheetRaw = false;
    foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/Sheet1.xml'] as $sn)
        if (($sheetRaw = $zip->getFromName($sn)) !== false)
            break;
    $zip->close();
    if (!$sheetRaw)
        return [];
    $xml = @simplexml_load_string($sheetRaw);
    if (!$xml || !isset($xml->sheetData))
        return [];
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $tmp = [];
        $prev = 0;
        foreach ($row->c as $c) {
            $r_attr = (string) ($c['r'] ?? '');
            if (preg_match('/^([A-Z]+)/', $r_attr, $m)) {
                $col = col_to_num($m[1]);
            } else {
                $col = $prev + 1;
            }
            while ($prev < $col - 1) {
                $tmp[] = '';
                $prev++;
            }
            $type = (string) ($c['t'] ?? '');
            $val = isset($c->v) ? (string) $c->v : '';
            if ($type === 's')
                $val = $strings[(int) $val] ?? '';
            elseif ($type === 'inlineStr')
                $val = (string) ($c->is->t ?? '');
            elseif ($type === 'b')
                $val = ($val === '1') ? 'TRUE' : 'FALSE';
            $tmp[] = $val;
            $prev = $col;
        }
        if (array_filter(array_map('trim', $tmp)))
            $rows[] = $tmp;
    }
    return $rows;
}

function csv_to_rows(string $path): array
{
    $rows = [];
    $delim = ',';
    $sample = file_get_contents($path, false, null, 0, 2048);
    if (substr_count($sample, ';') > substr_count($sample, ','))
        $delim = ';';
    $fh = @fopen($path, 'r');
    if (!$fh)
        return [];
    while (($row = fgetcsv($fh, 0, $delim)) !== false)
        if (array_filter(array_map('trim', $row)))
            $rows[] = $row;
    fclose($fh);
    return $rows;
}

function parse_file(string $path, string $ext): array
{
    $rows = $ext === 'csv' ? csv_to_rows($path) : xlsx_to_rows($path);
    if (count($rows) < 2)
        return [];

    $raw_h = array_map('trim', $rows[0]);
    $valid = [];
    foreach ($raw_h as $i => $h)
        if ($h !== '')
            $valid[$i] = $h;

    if (empty($valid))
        return [];

    $headers = [];
    $vidx = array_keys($valid);

    foreach ($vidx as $idx) {
        $canonical = canonical_header_name((string) $raw_h[$idx]);
        if ($canonical !== '' && !in_array($canonical, $headers, true))
            $headers[] = $canonical;
    }

    $data = [];
    foreach (array_slice($rows, 1) as $r) {
        $obj = [];
        foreach ($vidx as $idx)
            $obj[$raw_h[$idx]] = trim((string) ($r[$idx] ?? ''));

        $normalized = normalize_row_for_storage($obj);
        if (array_filter($normalized, fn($x) => (string) $x !== ''))
            $data[] = $normalized;
    }

    return ['headers' => $headers, 'data' => $data];
}

function app_root_dir(): string
{
    $root_dir = realpath(__DIR__ . '/..');
    if (!$root_dir)
        $root_dir = dirname(__DIR__);
    return rtrim((string) $root_dir, "\\/");
}

function upload_excel_dir(): string
{
    $dir = app_root_dir() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'excel';
    if (!is_dir($dir))
        @mkdir($dir, 0755, true);
    return $dir;
}

function legacy_upload_excel_dir(): string
{
    $legacy_dir = app_root_dir() . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'excel';
    $real_legacy_dir = realpath($legacy_dir);
    if ($real_legacy_dir)
        return rtrim($real_legacy_dir, "\\/");
    return rtrim($legacy_dir, "\\/");
}

function normalize_path_compare(string $path): string
{
    return str_replace('\\', '/', strtolower(trim($path)));
}

function is_path_inside_app_root(string $path): bool
{
    $path_now = normalize_path_compare($path);
    $root_now = rtrim(normalize_path_compare(app_root_dir()), '/') . '/';
    return str_starts_with($path_now, $root_now);
}

function build_unique_file_path(string $dir, string $file_name): string
{
    $dir_now = rtrim($dir, "\\/");
    $target = $dir_now . DIRECTORY_SEPARATOR . $file_name;
    if (!file_exists($target))
        return $target;

    $info = pathinfo($file_name);
    $name = trim((string) ($info['filename'] ?? 'upload'));
    $ext = trim((string) ($info['extension'] ?? ''));
    if ($name === '')
        $name = 'upload';

    $idx = 1;
    do {
        $next_name = $name . '_' . $idx;
        if ($ext !== '')
            $next_name .= '.' . $ext;
        $target = $dir_now . DIRECTORY_SEPARATOR . $next_name;
        $idx++;
    } while (file_exists($target));

    return $target;
}

function normalize_upload_storage_path(?string $file_path): ?string
{
    if (!is_string($file_path) || trim($file_path) === '')
        return $file_path;

    $source_path = trim($file_path);
    if (is_path_inside_app_root($source_path))
        return $source_path;
    if (!is_file($source_path))
        return $source_path;

    $safe_name = preg_replace('/[^a-z0-9._-]+/i', '_', basename($source_path));
    if (!is_string($safe_name) || $safe_name === '' || $safe_name === '.' || $safe_name === '..')
        $safe_name = 'upload_' . date('Ymd_His') . '_' . uniqid() . '.xlsx';

    $target_path = build_unique_file_path(upload_excel_dir(), $safe_name);
    if (@rename($source_path, $target_path))
        return $target_path;
    if (@copy($source_path, $target_path)) {
        @unlink($source_path);
        return $target_path;
    }

    return $source_path;
}

function migrate_user_upload_paths(PDO $db, int $uid): void
{
    $stmt = $db->prepare("SELECT id, file_path FROM patient_uploads WHERE user_id=? AND file_path IS NOT NULL AND file_path<>''");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows)
        return;

    $update_stmt = $db->prepare("UPDATE patient_uploads SET file_path=? WHERE id=? AND user_id=?");
    foreach ($rows as $row) {
        $old_path = (string) ($row['file_path'] ?? '');
        if ($old_path === '')
            continue;
        $new_path = normalize_upload_storage_path($old_path);
        if ($new_path !== $old_path)
            $update_stmt->execute([$new_path, (int) $row['id'], $uid]);
    }
}

function migrate_legacy_excel_files_for_user(int $uid): void
{
    $source_dir = legacy_upload_excel_dir();
    if (!is_dir($source_dir))
        return;

    $target_dir = upload_excel_dir();
    if (normalize_path_compare($source_dir) === normalize_path_compare($target_dir))
        return;

    $pattern = rtrim($source_dir, "\\/") . DIRECTORY_SEPARATOR . $uid . '_*';
    $source_list = glob($pattern);
    if (!$source_list)
        return;

    foreach ($source_list as $source_path) {
        if (!is_file($source_path))
            continue;
        $ext = strtolower((string) pathinfo($source_path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true))
            continue;
        $target_path = build_unique_file_path($target_dir, basename($source_path));
        if (@rename($source_path, $target_path))
            continue;
        if (@copy($source_path, $target_path))
            @unlink($source_path);
    }
}

function unlink_if_inside_app_root(?string $file_path): void
{
    if (!is_string($file_path) || trim($file_path) === '')
        return;
    $real_file_path = realpath($file_path);
    if (!$real_file_path)
        return;
    if (!is_path_inside_app_root($real_file_path))
        return;
    @unlink($real_file_path);
}

function check_quota(array $u, int $new_rows): ?string
{
    if (($u['subscription_type'] ?? '') !== 'quota')
        return null;
    $sisa = (int) $u['quota_total'] - (int) $u['quota_used'];
    if ($new_rows > $sisa)
        return 'Kuota tidak cukup. Sisa: ' . number_format($sisa) . ' NIK, butuh: ' . number_format($new_rows) . ' NIK.';
    return null;
}
function normalize_header_key(string $header): string
{
    $h = strtolower(trim($header));
    $h = preg_replace('/[^a-z0-9]+/i', '', $h);
    return $h;
}

function header_rule_match(string $key, array $rule): bool
{
    $equal_list = $rule['equal'] ?? [];
    if (in_array($key, $equal_list, true))
        return true;

    $contain_list = $rule['contain'] ?? [];
    foreach ($contain_list as $token)
        if ($token !== '' && str_contains($key, (string) $token))
            return true;

    return false;
}

function sanitize_supported_header_name(string $header): string
{
    $header = trim($header);
    $header = preg_replace('/\s+/u', ' ', $header);
    return trim((string) $header);
}

function parse_supported_headers_input(string $input): array
{
    $parts = preg_split('/[\r\n,;|]+/u', $input);
    if (!is_array($parts))
        return [];

    $headers = [];
    $seen = [];
    foreach ($parts as $part) {
        $header = sanitize_supported_header_name((string) $part);
        if ($header === '')
            continue;
        $normalized_key = normalize_header_key($header);
        if ($normalized_key === '' || isset($seen[$normalized_key]))
            continue;
        $seen[$normalized_key] = true;
        $headers[] = $header;
    }

    return $headers;
}

function upload_base_header_rules(): array
{
    return [
        'NIK' => [
            'equal' => ['nik', 'nobpjs', 'nonik'],
            'contain' => ['nomornik', 'nikpeserta', 'noidentitas', 'noktp', 'nomorktp', 'nopeserta', 'nokartu', 'noasuransi'],
        ],
        'Nama Pasien' => [
            'equal' => ['nama', 'namapasien'],
            'contain' => ['namalengkap', 'namapeserta'],
        ],
        'Jenis Kelamin' => [
            'equal' => ['jk', 'gender'],
            'contain' => ['jeniskelamin', 'kelamin'],
        ],
        'Tgl.Lahir' => [
            'equal' => ['dob'],
            'contain' => ['tgllahir', 'tanggallahir', 'birth'],
        ],
        'No Telp' => [
            'equal' => [],
            'contain' => ['notelp', 'notelepon', 'nohp', 'nowa', 'whatsapp', 'phone'],
        ],
        'Status Pernikahan' => [
            'equal' => ['statusnikah', 'statuskawin'],
            'contain' => ['statuspernikahan', 'statusperkawinan', 'pernikahan', 'perkawinan'],
        ],
        'Penyandang Disabilitas' => [
            'equal' => ['difabel'],
            'contain' => ['penyandangdisabilitas', 'statusdisabilitas', 'disabilitas'],
        ],
        'Training' => [
            'equal' => ['training', 'pelatihan'],
            'contain' => ['training', 'pelatihan'],
        ],
        'Pekerjaan' => [
            'equal' => [],
            'contain' => ['pekerjaan', 'job'],
        ],
        'Alamat' => [
            'equal' => ['alamat'],
            'contain' => ['jalan', 'detaildomisili'],
        ],
        'RT' => [
            'equal' => ['rt'],
            'contain' => [],
        ],
        'RW' => [
            'equal' => ['rw'],
            'contain' => [],
        ],
        'Kecamatan' => [
            'equal' => ['kecamatan', 'kec'],
            'contain' => [],
        ],
        'Kelurahan' => [
            'equal' => ['kel'],
            'contain' => ['kelurahan', 'desa'],
        ],
        'Kabupaten/Kota' => [
            'equal' => ['kota'],
            'contain' => ['kabupaten', 'kabkot'],
        ],
        'Provinsi' => [
            'equal' => [],
            'contain' => ['provinsi'],
        ],
    ];
}

function default_supported_headers(): array
{
    return array_keys(upload_base_header_rules());
}

function load_supported_headers_setting(): array
{
    $default_headers = default_supported_headers();
    $session_headers = $_SESSION['upload_supported_headers'] ?? null;
    if (!is_array($session_headers))
        return $default_headers;

    $headers = [];
    $seen = [];
    foreach ($session_headers as $session_header) {
        $header = sanitize_supported_header_name((string) $session_header);
        if ($header === '')
            continue;
        $normalized_key = normalize_header_key($header);
        if ($normalized_key === '' || isset($seen[$normalized_key]))
            continue;
        $seen[$normalized_key] = true;
        $headers[] = $header;
    }

    return $headers ?: $default_headers;
}

function save_supported_headers_setting(array $headers): void
{
    $_SESSION['upload_supported_headers'] = array_values($headers);
}

function reset_supported_headers_setting(): void
{
    unset($_SESSION['upload_supported_headers']);
}

function upload_header_rules(): array
{
    $base_rules = upload_base_header_rules();
    $supported_headers = load_supported_headers_setting();
    $rules = [];

    foreach ($supported_headers as $supported_header) {
        if (isset($base_rules[$supported_header])) {
            $rules[$supported_header] = $base_rules[$supported_header];
            continue;
        }

        $normalized_key = normalize_header_key($supported_header);
        if ($normalized_key === '')
            continue;
        $rules[$supported_header] = [
            'equal' => [$normalized_key],
            'contain' => [],
        ];
    }

    return $rules;
}

function upload_supported_headers(): array
{
    return array_keys(upload_header_rules());
}

function canonical_header_name(string $header): string
{
    $orig = trim($header);
    if ($orig === '') return '';

    $k = normalize_header_key($orig);

    foreach (upload_header_rules() as $canonical => $rule)
        if (header_rule_match($k, $rule))
            return $canonical;

    return $orig;
}

function normalize_text_value(string $value): string
{
    $v = trim($value);
    $v = preg_replace('/\s+/u', ' ', $v);
    return $v;
}

function normalize_gender_value(string $value): string
{
    $v = strtolower(normalize_text_value($value));
    if ($v === '') return '';

    if (in_array($v, ['l', 'lk', 'laki', 'laki-laki', 'lakilaki', 'male', 'pria'], true) || str_contains($v, 'laki'))
        return 'Laki-laki';

    if (in_array($v, ['p', 'pr', 'perempuan', 'female', 'wanita'], true) || str_contains($v, 'perem'))
        return 'Perempuan';

    return $value;
}

function normalize_birth_date_value(string $value): string
{
    $v = normalize_text_value($value);
    if ($v === '') return '';

    if (preg_match('/^\d{4,6}$/', $v)) {
        $serial = (int) $v;
        if ($serial >= 20000 && $serial <= 90000) {
            $dt = (new DateTimeImmutable('1899-12-30'))->modify("+{$serial} days");
            return $dt->format('Y-m-d');
        }
    }

    $compact = str_replace(['.', '/'], '-', $v);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $compact, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $compact, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    $upper = str_replace('.', '', strtoupper($v));
    $month_map = [
        'JANUARI' => 1,
        'JAN' => 1,
        'FEBRUARI' => 2,
        'FEB' => 2,
        'PEBRUARI' => 2,
        'PEB' => 2,
        'MARET' => 3,
        'MAR' => 3,
        'APRIL' => 4,
        'APR' => 4,
        'MEI' => 5,
        'JUNI' => 6,
        'JUN' => 6,
        'JULI' => 7,
        'JUL' => 7,
        'AGUSTUS' => 8,
        'AGT' => 8,
        'AGS' => 8,
        'AUG' => 8,
        'SEPTEMBER' => 9,
        'SEP' => 9,
        'OKTOBER' => 10,
        'OKT' => 10,
        'OCT' => 10,
        'NOVEMBER' => 11,
        'NOV' => 11,
        'DESEMBER' => 12,
        'DES' => 12,
        'DEC' => 12,
    ];

    if (preg_match('/^(\d{1,2})\s+([A-Z]+)\s+(\d{4})$/', $upper, $m)) {
        $d = (int) $m[1];
        $mo_word = $m[2];
        $y = (int) $m[3];

        if (isset($month_map[$mo_word])) {
            $mo = (int) $month_map[$mo_word];
            if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    return $v;
}

function normalize_nik_value(string $value): string
{
    $raw = normalize_text_value($value);
    if ($raw === '')
        return '';

    $sci = str_replace(',', '.', preg_replace('/\s+/', '', $raw));
    if (preg_match('/^([0-9]+)(?:\.([0-9]+))?[eE]\+?([0-9]+)$/', $sci, $m)) {
        $left = (string) ($m[1] ?? '');
        $right = (string) ($m[2] ?? '');
        $exp = (int) ($m[3] ?? 0);
        $mantissa = $left . $right;
        $shift = $exp - strlen($right);

        if ($shift >= 0)
            $raw = $mantissa . str_repeat('0', $shift);
        else
            $raw = substr($mantissa, 0, max(0, strlen($mantissa) + $shift));
    }

    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '')
        return '';

    $len = strlen($digits);
    if ($len === 16 || $len === 13)
        return $digits;

    if ($len >= 10 && $len <= 12)
        return str_pad($digits, 13, '0', STR_PAD_LEFT);

    return '';
}

function parse_gender_for_job(string $value): string
{
    $v = strtolower(normalize_text_value($value));
    if ($v === '')
        return '';
    if (str_contains($v, 'perem') || in_array($v, ['p', 'pr', 'female', 'wanita'], true))
        return 'Perempuan';
    if (str_contains($v, 'laki') || in_array($v, ['l', 'lk', 'male', 'pria'], true))
        return 'Laki-laki';
    return '';
}

function is_valid_name_for_job(string $value): bool
{
    $nama = trim($value);
    if ($nama === '')
        return false;
    return (bool) preg_match('/^[\p{L}\s\'\-\.,]+$/u', $nama);
}

function normalize_birth_date_for_job(string $value): string
{
    $tanggal = normalize_birth_date_value($value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggal, $match))
        return '';
    $year = (int) ($match[1] ?? 0);
    $month = (int) ($match[2] ?? 0);
    $day = (int) ($match[3] ?? 0);
    if (!checkdate($month, $day, $year))
        return '';
    return $tanggal;
}

function normalize_row_for_job(array $row): ?array
{
    $validated = validate_row_for_job($row);
    if (!$validated['ok'])
        return null;
    return $validated['row'];
}

function validate_row_for_job(array $row): array
{
    $normalized = normalize_row_for_storage($row);
    $errors = [];

    $nik_value = normalize_nik_value((string) ($normalized['NIK'] ?? ''));
    if ($nik_value === '')
        $errors[] = 'NIK tidak valid (harus 13 atau 16 digit)';

    $nama_pasien = normalize_text_value((string) ($normalized['Nama Pasien'] ?? ''));
    if (!is_valid_name_for_job($nama_pasien))
        $errors[] = 'Nama Pasien tidak valid';

    $jenis_kelamin = parse_gender_for_job((string) ($normalized['Jenis Kelamin'] ?? ''));
    if ($jenis_kelamin === '')
        $errors[] = 'Jenis Kelamin tidak valid';

    $tanggal_lahir = normalize_birth_date_for_job((string) ($normalized['Tgl.Lahir'] ?? ''));
    if ($tanggal_lahir === '')
        $errors[] = 'Tgl.Lahir tidak valid';

    $normalized['NIK'] = $nik_value;
    $normalized['Nama Pasien'] = $nama_pasien;
    $normalized['Jenis Kelamin'] = $jenis_kelamin;
    $normalized['Tgl.Lahir'] = $tanggal_lahir;

    return [
        'ok' => count($errors) === 0,
        'errors' => $errors,
        'row' => $normalized,
    ];
}

function build_invalid_rows_preview(array $rows): array
{
    $invalid_rows = [];
    $valid_count = 0;

    foreach ($rows as $idx => $row) {
        $validated = validate_row_for_job($row);
        if ($validated['ok']) {
            $valid_count++;
            continue;
        }

        $normalized_row = $validated['row'];
        $invalid_rows[] = [
            'row_number' => $idx + 2,
            'reason' => implode('; ', $validated['errors']),
            'nik' => (string) ($normalized_row['NIK'] ?? ''),
            'nama_pasien' => (string) ($normalized_row['Nama Pasien'] ?? ''),
            'jenis_kelamin' => (string) ($normalized_row['Jenis Kelamin'] ?? ''),
            'tgl_lahir' => (string) ($normalized_row['Tgl.Lahir'] ?? ''),
            'no_telp' => (string) ($normalized_row['No Telp'] ?? ''),
        ];
    }

    return [
        'valid_count' => $valid_count,
        'invalid_count' => count($invalid_rows),
        'invalid_rows' => $invalid_rows,
    ];
}

function save_invalid_rows_report_file(int $uid, array $invalid_rows): ?string
{
    if (!$invalid_rows)
        return null;

    $dir = upload_excel_dir() . DIRECTORY_SEPARATOR . 'invalid_report';
    if (!is_dir($dir))
        @mkdir($dir, 0755, true);

    $file_name = $uid . '_invalid_' . date('Ymd_His') . '_' . uniqid() . '.json';
    $file_path = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $file_name;
    $encoded = json_encode($invalid_rows, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '')
        return null;
    if (@file_put_contents($file_path, $encoded) === false)
        return null;
    return $file_path;
}

function load_invalid_rows_report_file(?string $file_path): array
{
    if (!is_string($file_path) || trim($file_path) === '')
        return [];

    $source_path = trim($file_path);
    if (!is_file($source_path))
        return [];
    if (!is_path_inside_app_root($source_path))
        return [];

    $raw = @file_get_contents($source_path);
    if (!is_string($raw) || $raw === '')
        return [];

    $decoded = json_decode($raw, true);
    if (!is_array($decoded))
        return [];

    $result = [];
    foreach ($decoded as $row) {
        if (!is_array($row))
            continue;
        $result[] = [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'reason' => (string) ($row['reason'] ?? ''),
            'nik' => (string) ($row['nik'] ?? ''),
            'nama_pasien' => (string) ($row['nama_pasien'] ?? ''),
            'jenis_kelamin' => (string) ($row['jenis_kelamin'] ?? ''),
            'tgl_lahir' => (string) ($row['tgl_lahir'] ?? ''),
            'no_telp' => (string) ($row['no_telp'] ?? ''),
        ];
    }

    return $result;
}

function delete_invalid_rows_report_file(?string $file_path): void
{
    if (!is_string($file_path) || trim($file_path) === '')
        return;
    unlink_if_inside_app_root($file_path);
}

function export_invalid_rows_excel(array $invalid_rows, string $source_file_name): void
{
    $base_name = trim((string) pathinfo($source_file_name, PATHINFO_FILENAME));
    if ($base_name === '')
        $base_name = 'upload';
    $safe_name = preg_replace('/[^a-z0-9._-]+/i', '_', $base_name);
    if (!is_string($safe_name) || trim($safe_name) === '')
        $safe_name = 'upload';
    $download_name = 'data_tidak_sesuai_' . $safe_name . '_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>No</th>';
    echo '<th>Baris Excel</th>';
    echo '<th>Alasan</th>';
    echo '<th>NIK</th>';
    echo '<th>Nama Pasien</th>';
    echo '<th>Jenis Kelamin</th>';
    echo '<th>Tgl.Lahir</th>';
    echo '<th>No Telp</th>';
    echo '</tr></thead><tbody>';

    $row_no = 1;
    foreach ($invalid_rows as $row) {
        $row_number = (int) ($row['row_number'] ?? 0);
        $reason = htmlspecialchars((string) ($row['reason'] ?? ''), ENT_QUOTES, 'UTF-8');
        $nik = htmlspecialchars((string) ($row['nik'] ?? ''), ENT_QUOTES, 'UTF-8');
        $nama_pasien = htmlspecialchars((string) ($row['nama_pasien'] ?? ''), ENT_QUOTES, 'UTF-8');
        $jenis_kelamin = htmlspecialchars((string) ($row['jenis_kelamin'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tgl_lahir = htmlspecialchars((string) ($row['tgl_lahir'] ?? ''), ENT_QUOTES, 'UTF-8');
        $no_telp = htmlspecialchars((string) ($row['no_telp'] ?? ''), ENT_QUOTES, 'UTF-8');

        echo '<tr>';
        echo '<td>' . $row_no . '</td>';
        echo '<td>' . $row_number . '</td>';
        echo '<td>' . $reason . '</td>';
        echo '<td>' . $nik . '</td>';
        echo '<td>' . $nama_pasien . '</td>';
        echo '<td>' . $jenis_kelamin . '</td>';
        echo '<td>' . $tgl_lahir . '</td>';
        echo '<td>' . $no_telp . '</td>';
        echo '</tr>';
        $row_no++;
    }

    echo '</tbody></table>';
}

function normalize_row_field_value(string $header, string $value): string
{
    $v = normalize_text_value($value);

    return match ($header) {
        'NIK' => normalize_nik_value($v),
        'Nama Pasien' => $v,
        'Jenis Kelamin' => normalize_gender_value($v),
        'Tgl.Lahir' => normalize_birth_date_value($v),
        'No Telp' => preg_replace('/\D+/', '', $v),
        'RT', 'RW' => preg_replace('/\D+/', '', $v),
        'Alamat', 'Kelurahan', 'Kecamatan', 'Kabupaten/Kota', 'Provinsi', 'Pekerjaan', 'Status Pernikahan', 'Penyandang Disabilitas', 'Training' => $v,
        default => $v,
    };
}

function normalize_row_for_storage(array $row): array
{
    $normalized = [];

    foreach ($row as $raw_header => $raw_value) {
        $header = canonical_header_name((string) $raw_header);
        if ($header === '') continue;

        $value = normalize_row_field_value($header, (string)($raw_value ?? ''));

        if (!array_key_exists($header, $normalized)) {
            $normalized[$header] = $value;
            continue;
        }

        if (($normalized[$header] ?? '') === '' && $value !== '')
            $normalized[$header] = $value;
    }

    return $normalized;
}

function extract_nik_value(array $row): string
{
    return normalize_nik_value((string) ($row['NIK'] ?? ''));
}

function build_patient_bio_key(array $row): string
{
    $nama_pasien = strtoupper(normalize_text_value((string) ($row['Nama Pasien'] ?? '')));
    $tgl_lahir = normalize_birth_date_value((string) ($row['Tgl.Lahir'] ?? ''));
    $jenis_kelamin = normalize_gender_value((string) ($row['Jenis Kelamin'] ?? ''));
    $no_telp = preg_replace('/\D+/', '', normalize_text_value((string) ($row['No Telp'] ?? '')));

    if ($nama_pasien === '' && $tgl_lahir === '' && $jenis_kelamin === '' && $no_telp === '')
        return '';

    return 'bio:' . md5($nama_pasien . '|' . $tgl_lahir . '|' . $jenis_kelamin . '|' . $no_telp);
}

function build_row_unique_key(array $row): string
{
    $nik_value = extract_nik_value($row);
    if ($nik_value !== '')
        return 'nik:' . $nik_value;

    return build_patient_bio_key($row);
}

function rows_need_fallback_dedup(array $rows): bool
{
    foreach ($rows as $row)
        if (extract_nik_value($row) === '')
            return true;
    return false;
}

function load_existing_unique_map(PDO $db, int $uid, bool $with_fallback, string $scope_mode): array
{
    $unique_map = [];

    if (!$with_fallback) {
        $stmt = $db->prepare("SELECT nik_index FROM patients_data WHERE user_id=? AND ckg_scope=? AND nik_index IS NOT NULL AND nik_index<>''");
        $stmt->execute([$uid, $scope_mode]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nik_raw) {
            $nik_value = normalize_nik_value((string) $nik_raw);
            if ($nik_value !== '')
                $unique_map['nik:' . $nik_value] = true;
        }

        $stmt = $db->prepare("SELECT data FROM patients_data WHERE user_id=? AND ckg_scope=? AND (nik_index IS NULL OR nik_index='')");
        $stmt->execute([$uid, $scope_mode]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json_raw) {
            $decoded = json_decode((string) $json_raw, true);
            if (!is_array($decoded))
                continue;
            $normalized = normalize_row_for_storage($decoded);
            $nik_value = extract_nik_value($normalized);
            if ($nik_value !== '')
                $unique_map['nik:' . $nik_value] = true;
        }

        return $unique_map;
    }

    $stmt = $db->prepare("SELECT nik_index, data FROM patients_data WHERE user_id=? AND ckg_scope=?");
    $stmt->execute([$uid, $scope_mode]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $existing_row) {
        $nik_value = normalize_nik_value((string) ($existing_row['nik_index'] ?? ''));
        $normalized = [];

        if ($nik_value === '' || $with_fallback) {
            $decoded = json_decode((string) ($existing_row['data'] ?? ''), true);
            if (is_array($decoded))
                $normalized = normalize_row_for_storage($decoded);
        }

        if ($nik_value === '' && $normalized)
            $nik_value = extract_nik_value($normalized);

        if ($nik_value !== '')
            $unique_map['nik:' . $nik_value] = true;

        if (!$with_fallback || !$normalized)
            continue;

        $bio_key = build_patient_bio_key($normalized);
        if ($bio_key !== '')
            $unique_map[$bio_key] = true;
    }

    return $unique_map;
}

function save_upload_rows(PDO $db, int $uid, string $file_name, ?string $file_path, array $headers, array $rows, string $scope_mode, ?array $seed_unique_map = null): array
{
    $file_path = normalize_upload_storage_path($file_path);
    $with_fallback = rows_need_fallback_dedup($rows);
    $unique_map = is_array($seed_unique_map)
        ? $seed_unique_map
        : load_existing_unique_map($db, $uid, $with_fallback, $scope_mode);

    $rows_to_insert = [];
    $skipped_duplicate = 0;
    $skipped_invalid = 0;
    foreach ($rows as $row) {
        $normalized_row = normalize_row_for_job($row);
        if (!$normalized_row) {
            $skipped_invalid++;
            continue;
        }

        $unique_key = build_row_unique_key($normalized_row);

        if ($unique_key !== '' && isset($unique_map[$unique_key])) {
            $skipped_duplicate++;
            continue;
        }

        if ($unique_key !== '')
            $unique_map[$unique_key] = true;

        $rows_to_insert[] = $normalized_row;
    }

    if (!$rows_to_insert)
        return [
            'upload_id' => 0,
            'inserted' => 0,
            'skipped' => $skipped_duplicate + $skipped_invalid,
            'skipped_duplicate' => $skipped_duplicate,
            'skipped_invalid' => $skipped_invalid,
        ];

    $db->prepare("
        INSERT INTO patient_uploads (user_id, ckg_scope, file_name, file_path, total_rows, detected_fields, status)
        VALUES (?,?,?,?,?,?,'processed')
    ")->execute([
        $uid,
        $scope_mode,
        $file_name,
        $file_path,
        count($rows),
        json_encode($headers, JSON_UNESCAPED_UNICODE),
    ]);
    $upload_id = (int) $db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO patients_data (upload_id, user_id, ckg_scope, data) VALUES (?,?,?,?)");
    foreach ($rows_to_insert as $row)
        $stmt->execute([$upload_id, $uid, $scope_mode, json_encode($row, JSON_UNESCAPED_UNICODE)]);
    $inserted = count($rows_to_insert);

    return [
        'upload_id' => $upload_id,
        'inserted' => $inserted,
        'skipped' => $skipped_duplicate + $skipped_invalid,
        'skipped_duplicate' => $skipped_duplicate,
        'skipped_invalid' => $skipped_invalid,
    ];
}

function epus_headers(): array
{
    return [
        'NIK',
        'Nama Pasien',
        'No Telp',
        'Jenis Kelamin',
        'Tgl.Lahir',
        'Umur Tahun',
        'Pekerjaan',
        'Alamat',
        'RT',
        'RW',
        'Kelurahan',
        'Kecamatan',
        'Penyakit Kronis',
    ];
}

function epus_clean_value(mixed $value): string
{
    if (is_array($value))
        return '';
    $text = html_entity_decode((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    return normalize_text_value($text);
}

function epus_extract_name(mixed $value): string
{
    if (is_array($value))
        return '';
    $text = (string) ($value ?? '');
    $parts = preg_split('/<br\s*\/?>|<\/br>/i', $text);
    $name = $parts[0] ?? $text;
    return epus_clean_value($name);
}

function epus_extract_chronic_from_name(mixed $value): string
{
    if (is_array($value))
        return '';
    preg_match('/flex-sub-kronis[^>]*>(.*?)<\/span>/i', (string) ($value ?? ''), $match);
    return epus_clean_value($match[1] ?? '');
}

function epus_extract_kecamatan(array $row): string
{
    if (isset($row['kecamatan']['nama']))
        return epus_clean_value($row['kecamatan']['nama']);
    return epus_clean_value($row['kecamatan'] ?? '');
}

function epus_extract_chronic(array $row, mixed $name_source): string
{
    $from_name = epus_extract_chronic_from_name($name_source);
    if ($from_name !== '')
        return $from_name;

    if (isset($row['penyakit_kronis']['value']))
        return epus_clean_value($row['penyakit_kronis']['value']);
    return epus_clean_value($row['penyakit_kronis'] ?? '');
}

function map_epus_records(array $records): array
{
    $rows = [];
    foreach ($records as $record) {
        if (!is_array($record))
            continue;
        $nik_value = normalize_nik_value(epus_clean_value($record['nik'] ?? ''));
        if ($nik_value === '')
            continue;
        $nama_source = $record['nama_pasien'] ?? '';
        $umur_number = (int) ($record['umur_tahun'] ?? 0);
        $mapped = [
            'NIK' => $nik_value,
            'Nama Pasien' => epus_extract_name($nama_source),
            'No Telp' => preg_replace('/\D+/', '', epus_clean_value($record['no_telp'] ?? '')),
            'Jenis Kelamin' => epus_clean_value($record['jenis_kelamin'] ?? ''),
            'Tgl.Lahir' => epus_clean_value($record['tanggal_lahir'] ?? ''),
            'Umur Tahun' => $umur_number > 0 ? (string) $umur_number : '',
            'Pekerjaan' => epus_clean_value($record['pekerjaan'] ?? ''),
            'Alamat' => epus_clean_value($record['alamat'] ?? ''),
            'RT' => preg_replace('/\D+/', '', epus_clean_value($record['rt'] ?? '')),
            'RW' => preg_replace('/\D+/', '', epus_clean_value($record['rw'] ?? '')),
            'Kelurahan' => epus_clean_value($record['kelurahan'] ?? ''),
            'Kecamatan' => epus_extract_kecamatan($record),
            'Penyakit Kronis' => epus_extract_chronic($record, $nama_source),
        ];
        $normalized = normalize_row_for_storage($mapped);
        if (array_filter($normalized, fn($item) => (string) $item !== ''))
            $rows[] = $normalized;
    }
    return $rows;
}

function fetch_epus_page(string $base_url, string $cookie, string $referer, int $page, int $limit): array
{
    if (!function_exists('curl_init'))
        throw new RuntimeException('Ekstensi cURL tidak tersedia di server.');

    $url = $base_url . (str_contains($base_url, '?') ? '&' : '?') . 'page=' . $page . '&limit=' . $limit;
    $headers = ['x-requested-with: XMLHttpRequest', 'Cookie: ' . $cookie];
    if ($referer !== '')
        $headers[] = 'referer: ' . $referer;

    $curl = curl_init();
    if (!$curl)
        throw new RuntimeException('Gagal memulai request EPUS.');

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($curl);
    $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    unset($curl);

    if ($response === false)
        throw new RuntimeException('Gagal request EPUS: ' . ($curl_error ?: 'unknown error'));
    if ($http_code === 401)
        throw new RuntimeException('Cookie EPUS tidak valid atau sudah expired.');
    if ($http_code !== 200)
        throw new RuntimeException('EPUS mengembalikan HTTP ' . $http_code . '.');

    $decoded = json_decode($response, true);
    if (!is_array($decoded))
        throw new RuntimeException('Response EPUS bukan JSON valid.');
    return $decoded;
}

function extract_epus_records(array $payload): array
{
    $data = $payload['data'] ?? [];
    if (is_array($data) && isset($data['records']) && is_array($data['records']))
        return $data['records'];
    if (is_array($data)) {
        $expected_index = 0;
        foreach (array_keys($data) as $index) {
            if ($index !== $expected_index)
                return [];
            $expected_index++;
        }
        return $data;
    }
    return [];
}

function normalize_epus_gender_filter(string $value): string
{
    $v = strtolower(trim($value));

    if (in_array($v, ['l', 'lk', 'laki', 'laki-laki', 'lakilaki', 'male', 'pria'], true))
        return 'Laki-laki';

    if (in_array($v, ['p', 'pr', 'perempuan', 'female', 'wanita'], true))
        return 'Perempuan';

    return '';
}

function parse_optional_age(string $value): ?int
{
    $v = trim($value);
    if ($v === '')
        return null;

    if (!preg_match('/^\d{1,3}$/', $v))
        throw new InvalidArgumentException('Usia harus angka 0 sampai 150.');

    $age = (int) $v;
    if ($age < 0 || $age > 150)
        throw new InvalidArgumentException('Usia harus angka 0 sampai 150.');

    return $age;
}

function epus_cursor_setting_key(int $uid): string
{
    return 'epus_cursor_index_user_' . $uid;
}

function get_epus_cursor_index(int $uid): int
{
    $raw = trim(get_setting(epus_cursor_setting_key($uid)));
    if ($raw === '' || !preg_match('/^\d+$/', $raw))
        return 0;
    return (int) $raw;
}

function save_epus_cursor_index(int $uid, int $cursor): void
{
    $value = max(0, $cursor);
    save_setting(epus_cursor_setting_key($uid), (string) $value);
}

function epus_upload_cursor_setting_key(int $uid, int $upload_id): string
{
    return 'epus_upload_cursor_user_' . $uid . '_' . $upload_id;
}

function save_epus_upload_cursor_range(int $uid, int $upload_id, int $start_cursor, int $next_cursor): void
{
    if ($upload_id <= 0)
        return;

    $payload = [
        'start_cursor' => max(0, $start_cursor),
        'next_cursor' => max(0, $next_cursor),
    ];
    save_setting(epus_upload_cursor_setting_key($uid, $upload_id), json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function get_epus_upload_cursor_start(int $uid, int $upload_id): ?int
{
    if ($upload_id <= 0)
        return null;

    $raw = trim(get_setting(epus_upload_cursor_setting_key($uid, $upload_id)));
    if ($raw === '')
        return null;

    $decoded = json_decode($raw, true);
    if (!is_array($decoded))
        return null;

    $start_cursor = $decoded['start_cursor'] ?? null;
    if (!is_numeric($start_cursor))
        return null;

    return max(0, (int) $start_cursor);
}

function clear_epus_upload_cursor_range(int $uid, int $upload_id): void
{
    if ($upload_id <= 0)
        return;

    $stmt = db()->prepare("DELETE FROM admin_settings WHERE setting_key = ?");
    $stmt->execute([epus_upload_cursor_setting_key($uid, $upload_id)]);
}

function resolve_row_age_year(array $row): ?int
{
    $raw_age = trim((string) ($row['Umur Tahun'] ?? ''));
    if (preg_match('/\d{1,3}/', $raw_age, $m))
        return (int) $m[0];

    $tgl_lahir = normalize_birth_date_value((string) ($row['Tgl.Lahir'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_lahir))
        return null;

    $birth_date = DateTimeImmutable::createFromFormat('Y-m-d', $tgl_lahir);
    if (!$birth_date)
        return null;

    $today = new DateTimeImmutable('today');
    if ($birth_date > $today)
        return null;

    return (int) $birth_date->diff($today)->y;
}

function row_match_epus_filter(array $row, string $gender_filter, ?int $age_min, ?int $age_max): bool
{
    if ($gender_filter !== '') {
        $row_gender = normalize_gender_value((string) ($row['Jenis Kelamin'] ?? ''));
        if ($row_gender !== $gender_filter)
            return false;
    }

    if ($age_min === null && $age_max === null)
        return true;

    $row_age = resolve_row_age_year($row);
    if ($row_age === null)
        return false;

    if ($age_min !== null && $row_age < $age_min)
        return false;

    if ($age_max !== null && $row_age > $age_max)
        return false;

    return true;
}

function extract_epus_total_records(array $payload): int
{
    $data = $payload['data'] ?? [];
    if (!is_array($data))
        return 0;

    $candidate = $data['totalRecords'] ?? $data['recordsTotal'] ?? $data['total'] ?? 0;
    if (!is_numeric($candidate))
        return 0;

    $total = (int) $candidate;
    return $total > 0 ? $total : 0;
}

function map_epus_record(array $record): array
{
    $nik_value = normalize_nik_value(epus_clean_value($record['nik'] ?? ''));
    if ($nik_value === '')
        return [];

    $nama_source = $record['nama_pasien'] ?? '';
    $umur_number = (int) ($record['umur_tahun'] ?? 0);
    $mapped = [
        'NIK' => $nik_value,
        'Nama Pasien' => epus_extract_name($nama_source),
        'No Telp' => preg_replace('/\D+/', '', epus_clean_value($record['no_telp'] ?? '')),
        'Jenis Kelamin' => epus_clean_value($record['jenis_kelamin'] ?? ''),
        'Tgl.Lahir' => epus_clean_value($record['tanggal_lahir'] ?? ''),
        'Umur Tahun' => $umur_number > 0 ? (string) $umur_number : '',
        'Pekerjaan' => epus_clean_value($record['pekerjaan'] ?? ''),
        'Alamat' => epus_clean_value($record['alamat'] ?? ''),
        'RT' => preg_replace('/\D+/', '', epus_clean_value($record['rt'] ?? '')),
        'RW' => preg_replace('/\D+/', '', epus_clean_value($record['rw'] ?? '')),
        'Kelurahan' => epus_clean_value($record['kelurahan'] ?? ''),
        'Kecamatan' => epus_extract_kecamatan($record),
        'Penyakit Kronis' => epus_extract_chronic($record, $nama_source),
    ];
    $normalized = normalize_row_for_storage($mapped);
    if (!array_filter($normalized, fn($item) => (string) $item !== ''))
        return [];
    return $normalized;
}

function fetch_epus_rows(string $base_url, string $cookie, string $referer, int $take_total, string $gender_filter, ?int $age_min, ?int $age_max, array $seed_unique_map = [], int $start_cursor = 0): array
{
    $per_page = 100;
    $rows = [];
    $max_request = 350;
    $unique_map = $seed_unique_map;
    $cursor = max(0, $start_cursor);
    $request_count = 0;
    $total_records = 0;

    while (count($rows) < $take_total && $request_count < $max_request) {
        $page = (int) floor($cursor / $per_page) + 1;
        $skip_index = $cursor % $per_page;
        $payload = fetch_epus_page($base_url, $cookie, $referer, $page, $per_page);
        $page_records = extract_epus_records($payload);
        $request_count++;
        if (!$page_records)
            break;

        $payload_total = extract_epus_total_records($payload);
        if ($payload_total > 0)
            $total_records = $payload_total;

        $page_count = count($page_records);
        if ($skip_index >= $page_count) {
            $cursor = $page * $per_page;
            if ($page_count < $per_page)
                break;
            if ($total_records > 0 && $cursor >= $total_records)
                break;
            usleep(200000);
            continue;
        }

        for ($index = $skip_index; $index < $page_count; $index++) {
            $row = map_epus_record((array) $page_records[$index]);
            $cursor++;
            if (!$row)
                continue;
            if (!row_match_epus_filter($row, $gender_filter, $age_min, $age_max))
                continue;

            $unique_key = build_row_unique_key($row);
            if ($unique_key !== '' && isset($unique_map[$unique_key]))
                continue;

            if ($unique_key !== '')
                $unique_map[$unique_key] = true;

            $rows[] = $row;
            if (count($rows) >= $take_total)
                break;
        }

        if (count($rows) >= $take_total)
            break;

        if ($page_count < $per_page)
            break;

        if ($total_records > 0 && $cursor >= $total_records)
            break;

        usleep(80000);
    }

    return [
        'rows' => $rows,
        'next_cursor' => $cursor,
        'total_records' => $total_records,
    ];
}

try {
    migrate_user_upload_paths($db, $uid);
    migrate_legacy_excel_files_for_user($uid);
} catch (Throwable $e) {
}


/* 
   HAPUS UPLOAD
 */
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    if (!is_valid_csrf_token((string) ($_GET['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Aksi tidak valid (CSRF)');
    }

    $upid = (int) $_GET['delete'];
    $stmt = $db->prepare("SELECT file_path, file_name FROM patient_uploads WHERE id=? AND user_id=? AND ckg_scope=?");
    $stmt->execute([$upid, $uid, $scope_mode]);
    $up = $stmt->fetch();
    if ($up) {
        $cursor_start = get_epus_upload_cursor_start($uid, $upid);
        if ($cursor_start !== null)
            save_epus_cursor_index($uid, $cursor_start);
        clear_epus_upload_cursor_range($uid, $upid);

        unlink_if_inside_app_root((string) ($up['file_path'] ?? ''));
        $db->prepare("DELETE FROM patients_data WHERE upload_id=? AND user_id=? AND ckg_scope=?")->execute([$upid, $uid, $scope_mode]);
        $db->prepare("DELETE FROM patient_uploads WHERE id=? AND user_id=? AND ckg_scope=?")->execute([$upid, $uid, $scope_mode]);
        $msg = 'Upload dan seluruh data peserta terkait berhasil dihapus.';
        if ($cursor_start !== null)
            $msg .= ' Posisi EPUS otomatis kembali ke titik awal file yang dihapus.';
        $_SESSION['upload_msg'] = $msg;
    }
    ob_end_clean();
    header('Location: upload.php');
    exit;
}

/* 
   CANCEL PREVIEW
 */
if (isset($_GET['cancel'])) {
    if (!empty($_SESSION['excel_preview']['file_path']))
        unlink_if_inside_app_root((string) $_SESSION['excel_preview']['file_path']);
    if (!empty($_SESSION['excel_preview']['invalid_report_path']))
        delete_invalid_rows_report_file((string) $_SESSION['excel_preview']['invalid_report_path']);
    unset($_SESSION['excel_preview']);
    ob_end_clean();
    header('Location: upload.php');
    exit;
}

if (isset($_GET['download_invalid']) && (string) $_GET['download_invalid'] === '1') {
    $preview_data = $_SESSION['excel_preview'] ?? null;
    if (!is_array($preview_data)) {
        ob_end_clean();
        header('Location: upload.php');
        exit;
    }

    $invalid_rows = load_invalid_rows_report_file((string) ($preview_data['invalid_report_path'] ?? ''));
    if (!$invalid_rows && !empty($preview_data['invalid_preview']) && is_array($preview_data['invalid_preview']))
        $invalid_rows = $preview_data['invalid_preview'];
    if (!$invalid_rows) {
        $_SESSION['upload_msg'] = 'Tidak ada data tidak sesuai untuk diunduh.';
        ob_end_clean();
        header('Location: upload.php?preview=1');
        exit;
    }

    ob_end_clean();
    export_invalid_rows_excel($invalid_rows, (string) ($preview_data['file_name'] ?? 'upload'));
    exit;
}

if (isset($_SESSION['upload_msg'])) {
    $success = (string) ($_SESSION['upload_msg'] ?? '');
    unset($_SESSION['upload_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $_SESSION['upload_msg'] = 'Ukuran file melebihi batas maksimal server (' . ini_get('post_max_size') . ').';
        ob_end_clean();
        header('Location: upload.php');
        exit;
    }
    if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['upload_msg'] = 'Aksi tidak valid (CSRF). Silakan muat ulang halaman.';
        ob_end_clean();
        header('Location: upload.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_supported_headers') {
    $mode = (string) ($_POST['supported_headers_mode'] ?? 'save');
    if ($mode === 'reset') {
        reset_supported_headers_setting();
        $_SESSION['upload_msg'] = 'Daftar kolom support dikembalikan ke default.';
    } else {
        $input = (string) ($_POST['supported_headers_input'] ?? '');
        $headers = parse_supported_headers_input($input);
        if (!$headers) {
            $_SESSION['upload_msg'] = 'Daftar kolom support kosong. Gunakan minimal 1 kolom.';
        } else {
            save_supported_headers_setting($headers);
            $_SESSION['upload_msg'] = 'Daftar kolom support berhasil diperbarui.';
        }
    }
    ob_end_clean();
    header('Location: upload.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_epus_cursor') {
    save_epus_cursor_index($uid, 0);
    $_SESSION['upload_msg'] = 'Posisi ambil data EPUS direset ke awal.';
    ob_end_clean();
    header('Location: upload.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch_epus') {
    $take_total = (int) ($_POST['epus_total'] ?? 0);
    $gender_filter = normalize_epus_gender_filter((string) ($_POST['epus_gender'] ?? ''));
    $force_reset_cursor = ((int) ($_POST['epus_reset_cursor'] ?? 0)) === 1;
    $age_min = null;
    $age_max = null;

    try {
        $age_min = parse_optional_age((string) ($_POST['epus_age_min'] ?? ''));
        $age_max = parse_optional_age((string) ($_POST['epus_age_max'] ?? ''));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $age_min !== null && $age_max !== null && $age_min > $age_max)
        $error = 'Usia minimal tidak boleh lebih besar dari usia maksimal.';

    if ($error !== '') {
    } elseif ($take_total < 1 || $take_total > 1000) {
        $error = 'Jumlah data EPUS harus 1 sampai 1.000 per proses.';
    } elseif ($epus_api_url === '' || $epus_cookie === '') {
        $error = 'Pengaturan EPUS belum lengkap. Isi API URL dan Cookie di Pengaturan Admin.';
    } else {
        try {
            $seed_unique_map = load_existing_unique_map($db, $uid, false, $scope_mode);
            $cursor_index = $force_reset_cursor ? 0 : get_epus_cursor_index($uid);
            if ($force_reset_cursor)
                save_epus_cursor_index($uid, 0);
            $fetch_result = fetch_epus_rows($epus_api_url, $epus_cookie, $epus_referer, $take_total, $gender_filter, $age_min, $age_max, $seed_unique_map, $cursor_index);
            $rows = $fetch_result['rows'] ?? [];
            $next_cursor = (int) ($fetch_result['next_cursor'] ?? $cursor_index);
            $total_records = (int) ($fetch_result['total_records'] ?? 0);
            $is_end_reached = ($total_records > 0 && $next_cursor >= $total_records);

            if ($is_end_reached)
                $next_cursor = 0;

            if (count($rows) < $take_total && $total_records > 0 && $cursor_index > 0 && $next_cursor === 0) {
                $remaining = $take_total - count($rows);
                $wrap_unique_map = $seed_unique_map;
                foreach ($rows as $row_now) {
                    $key_now = build_row_unique_key($row_now);
                    if ($key_now !== '')
                        $wrap_unique_map[$key_now] = true;
                }

                $wrap_result = fetch_epus_rows(
                    $epus_api_url,
                    $epus_cookie,
                    $epus_referer,
                    $remaining,
                    $gender_filter,
                    $age_min,
                    $age_max,
                    $wrap_unique_map,
                    0
                );

                $wrap_rows = $wrap_result['rows'] ?? [];
                if ($wrap_rows)
                    $rows = array_merge($rows, $wrap_rows);

                $next_cursor = (int) ($wrap_result['next_cursor'] ?? $next_cursor);
                if ($total_records <= 0)
                    $total_records = (int) ($wrap_result['total_records'] ?? 0);

                if ($total_records > 0 && $next_cursor >= $total_records)
                    $next_cursor = 0;
            }

            if (!$rows) {
                $error = $is_end_reached
                    ? 'Tidak ada data EPUS baru. Posisi data sudah di akhir sumber.'
                    : 'Tidak ada data EPUS yang sesuai filter.';
            } else {
                $headers = epus_headers();
                $file_name = 'EPUS_' . date('Ymd_His') . '.json';
                $file_path = null;

                $db->beginTransaction();
                try {
                    $result = save_upload_rows($db, $uid, $file_name, $file_path, $headers, $rows, $scope_mode, $seed_unique_map);
                    $db->commit();

                    $meta_failed = false;
                    try {
                        $upload_id = (int) ($result['upload_id'] ?? 0);
                        if ($upload_id > 0)
                            save_epus_upload_cursor_range($uid, $upload_id, $cursor_index, $next_cursor);
                        if ($next_cursor !== $cursor_index)
                            save_epus_cursor_index($uid, $next_cursor);
                    } catch (Throwable $e) {
                        $meta_failed = true;
                    }

                    $skip_duplicate = (int) ($result['skipped_duplicate'] ?? 0);
                    $skip_invalid = (int) ($result['skipped_invalid'] ?? 0);
                    $msg = $result['inserted'] . ' data EPUS baru disimpan';
                    if ($skip_duplicate > 0)
                        $msg .= ', ' . $skip_duplicate . ' duplikat data dilewati';
                    if ($skip_invalid > 0)
                        $msg .= ', ' . $skip_invalid . ' data tidak valid dilewati';
                    if ($meta_failed)
                        $msg .= '. Catatan: update posisi EPUS belum tersimpan, silakan reset posisi bila perlu';
                    $_SESSION['upload_msg'] = $msg . '.';
                    ob_end_clean();
                    header('Location: upload.php');
                    exit;
                } catch (Throwable $e) {
                    if ($db->inTransaction())
                        $db->rollBack();
                    $error = 'Gagal simpan data EPUS: ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $error = 'Gagal ambil data EPUS: ' . $e->getMessage();
        }
    }
}

/*
   UPLOAD & PARSE -> SESSION
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $file = $_FILES['excel'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File gagal diupload (kode error: ' . ($file['error'] ?? '?') . ').';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'])) {
            $error = 'Format harus .xlsx atau .csv';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            unset($finfo);
            $allowed_mimes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip', // Some systems report xlsx as zip
                'application/octet-stream', // Some servers fallback to this
                'text/csv',
                'text/plain',
                'application/csv'
            ];

            if (!in_array($mime, $allowed_mimes, true)) {
                $error = 'Tipe file tidak valid (' . h($mime) . '). Pastikan file benar-benar berformat Excel (.xlsx) atau CSV.';
            } else {
                $dir = upload_excel_dir() . DIRECTORY_SEPARATOR;
                $fname = $uid . '_' . time() . '_' . uniqid() . '.' . $ext;
                $fpath = $dir . $fname;
                if (!move_uploaded_file($file['tmp_name'], $fpath)) {
                    $error = 'Gagal simpan file. Cek permission folder uploads/excel/';
                } else {
                    $parsed = parse_file($fpath, $ext);
                    if (empty($parsed)) {
                        unlink_if_inside_app_root($fpath);
                        $error = 'File kosong atau tidak terbaca. Pastikan baris pertama adalah header kolom.';
                    } else {
                        if (!empty($_SESSION['excel_preview']['file_path']))
                            unlink_if_inside_app_root((string) $_SESSION['excel_preview']['file_path']);
                        if (!empty($_SESSION['excel_preview']['invalid_report_path']))
                            delete_invalid_rows_report_file((string) $_SESSION['excel_preview']['invalid_report_path']);

                        $validation_info = build_invalid_rows_preview($parsed['data']);
                        $invalid_rows = $validation_info['invalid_rows'] ?? [];
                        $invalid_report_path = save_invalid_rows_report_file($uid, $invalid_rows);
                        $preview_total = count($parsed['data']);
                        $preview_valid_count = (int) ($validation_info['valid_count'] ?? 0);
                        $preview_invalid_count = (int) ($validation_info['invalid_count'] ?? 0);

                        $_SESSION['excel_preview'] = [
                            'headers'   => $parsed['headers'],
                            'preview5'  => array_slice($parsed['data'], 0, 5),
                            'total'     => $preview_total,
                            'valid_count' => $preview_valid_count,
                            'invalid_count' => $preview_invalid_count,
                            'invalid_preview' => array_slice($invalid_rows, 0, 200),
                            'invalid_report_path' => $invalid_report_path,
                            'file_name' => basename($file['name']),
                            'file_path' => $fpath,
                            'ext'       => $ext,
                        ];
                        ob_end_clean();
                        header('Location: upload.php?preview=1');
                        exit;
                    }
                }
            }
        }
    }
}

/*
   KONFIRMASI -> SIMPAN DB
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $prev = $_SESSION['excel_preview'] ?? null;
    if (!$prev) {
        ob_end_clean();
        header('Location: upload.php');
        exit;
    }

    $parsed = parse_file($prev['file_path'], $prev['ext']);
    if (empty($parsed)) {
        $error = 'File tidak bisa dibaca ulang.';
    } else {
        $db->beginTransaction();
        try {
            $result = save_upload_rows(
                $db,
                $uid,
                $prev['file_name'],
                $prev['file_path'],
                $parsed['headers'],
                $parsed['data'],
                $scope_mode
            );
            $db->commit();
            delete_invalid_rows_report_file((string) ($prev['invalid_report_path'] ?? ''));
            unset($_SESSION['excel_preview']);

            $skip_duplicate = (int) ($result['skipped_duplicate'] ?? 0);
            $skip_invalid = (int) ($result['skipped_invalid'] ?? 0);
            $msg = $result['inserted'] . ' data baru disimpan';
            if ($skip_duplicate > 0)
                $msg .= ', ' . $skip_duplicate . ' duplikat data dilewati';
            if ($skip_invalid > 0)
                $msg .= ', ' . $skip_invalid . ' data tidak valid dilewati';
            $_SESSION['upload_msg'] = $msg . '.';
            ob_end_clean();
            header('Location: upload.php');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction())
                $db->rollBack();
            $error = 'Gagal simpan ke database: ' . $e->getMessage();
        }
    }
}

/* 
   DATA UNTUK VIEW
 */
$preview_mode = isset($_GET['preview'], $_SESSION['excel_preview']);
$prev = $preview_mode ? $_SESSION['excel_preview'] : null;
$epus_cursor_now = get_epus_cursor_index($uid);
$epus_cursor_active = $epus_cursor_now > 0;
$supported_upload_headers = upload_supported_headers();
$supported_upload_headers_text = implode("\n", $supported_upload_headers);

$stmt = $db->prepare("
    SELECT p.id, p.file_name, p.detected_fields, p.total_rows, p.uploaded_at,
           COUNT(d.id) AS row_count
    FROM patient_uploads p
    LEFT JOIN patients_data d ON d.upload_id = p.id
    WHERE p.user_id = ? AND p.ckg_scope = ?
    GROUP BY p.id ORDER BY p.id DESC
");
$stmt->execute([$uid, $scope_mode]);
$uploads = $stmt->fetchAll();

/* Hitung sisa per upload */
$sisa_map = [];

// Gunakan rumus bucket yang persis sama dengan Dashboard & Monitor & Data Peserta
$scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope=" . $db->quote($scope_mode);
$antrean_sub = "SELECT patient_id FROM job_queue WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})";
$sukses_sub = "SELECT patient_id FROM job_success WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})";
$gagal_sub = "
    SELECT patient_id FROM job_failed   WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})
    UNION
    SELECT patient_id FROM job_failed_x WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})
";

$exclude_sub = "
    {$antrean_sub}
    UNION
    {$sukses_sub}
    UNION
    {$gagal_sub}
";

foreach ($uploads as $up) {
    try {
        $sisa_map[$up['id']] = (int) $db->query("
            SELECT COUNT(*) FROM patients_data
            WHERE upload_id={$up['id']} AND user_id={$uid} AND ckg_scope=" . $db->quote($scope_mode) . "
            AND id NOT IN ({$exclude_sub})
        ")->fetchColumn();
    } catch (Exception $e) {
        $sisa_map[$up['id']] = 0;
    }
}
?>

<main class="flex-1 p-4 lg:p-8 bg-slate-50/50">




    <?php if ($success): ?>
        <div
            class="flex items-center gap-2.5 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700 text-sm font-semibold">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg><?= h($success) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div
            class="flex items-center gap-2.5 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-600 text-sm font-semibold">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg><?= h($error) ?>
        </div>
    <?php endif; ?>


    <?php if ($preview_mode && $prev): ?>
        <div class="bg-white border border-blue-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-blue-50 border-b border-blue-200 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h3 class="font-bold text-blue-900">Preview: <?= h($prev['file_name']) ?></h3>
                    <p class="text-xs text-blue-600 mt-0.5">
                        Terdeteksi <strong><?= count($prev['headers']) ?> kolom</strong>,
                        <strong><?= number_format((int) ($prev['total'] ?? 0)) ?> baris</strong> data.
                    </p>
                </div>
                <div class="flex flex-wrap gap-1">
                    <?php foreach ($prev['headers'] as $hdr): ?>
                        <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-[11px] font-bold">
                            <?= h($hdr) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            $valid_count_preview = (int) ($prev['valid_count'] ?? (int) ($prev['total'] ?? 0));
            $invalid_count_preview = (int) ($prev['invalid_count'] ?? 0);
            ?>
            <div class="px-6 py-3 border-b border-blue-100 bg-white">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold">Valid: <?= number_format($valid_count_preview) ?></span>
                    <span class="px-2 py-1 rounded-lg bg-rose-50 text-rose-700 font-bold">Tidak Sesuai: <?= number_format($invalid_count_preview) ?></span>
                    <?php if ($invalid_count_preview > 0): ?>
                        <button type="button" onclick="open_invalid_rows_modal()" class="px-2.5 py-1 rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-bold">
                            Lihat Data Tidak Sesuai
                        </button>
                        <a href="upload.php?download_invalid=1" class="px-2.5 py-1 rounded-lg bg-slate-700 hover:bg-slate-800 text-white font-bold">
                            Download Excel Perbaikan
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase">
                            <th class="px-3 py-2 text-center w-8">#</th>
                            <?php foreach ($prev['headers'] as $hdr): ?>
                                <th class="px-3 py-2 text-left whitespace-nowrap"><?= h($hdr) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($prev['preview5'] as $i => $row): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-2 text-center text-slate-400"><?= $i + 1 ?></td>
                                <?php foreach ($prev['headers'] as $hdr): ?>
                                    <td class="px-3 py-2 text-slate-700 whitespace-nowrap"><?= h($row[$hdr] ?? '') ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($prev['total'] > 5): ?>
                            <tr>
                                <td colspan="<?= count($prev['headers']) + 1 ?>"
                                    class="px-3 py-3 text-center text-xs text-slate-400 italic">
                                    ... dan <?= number_format($prev['total'] - 5) ?> baris lainnya
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-100 flex items-center gap-3">
                <a href="upload.php?cancel=1"
                    class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-bold transition-colors">
                    Batal &amp; Hapus File
                </a>
                <form method="POST">
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit"
                        class="px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold transition-colors">
                        Simpan <?= number_format($valid_count_preview) ?> Data Valid ke Database
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($preview_mode && $prev && ((int) ($prev['invalid_count'] ?? 0)) > 0): ?>
        <?php
        $invalid_preview_rows = $prev['invalid_preview'] ?? [];
        if (!is_array($invalid_preview_rows)) $invalid_preview_rows = [];
        ?>
        <div id="invalid_rows_modal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="w-full max-w-6xl bg-white rounded-2xl shadow-[0_8px_30px_rgb(0,0,0,0.12)] overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-slate-800">Data Tidak Sesuai</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Menampilkan maksimal 200 baris dari total <?= number_format((int) ($prev['invalid_count'] ?? 0)) ?> baris tidak sesuai.</p>
                    </div>
                    <button type="button" onclick="close_invalid_rows_modal()" class="text-slate-400 hover:text-slate-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="p-4">
                    <div class="overflow-auto max-h-[65vh] rounded-xl border border-slate-200">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-slate-50 text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                                    <th class="px-3 py-2 text-center">No</th>
                                    <th class="px-3 py-2 text-center">Baris Excel</th>
                                    <th class="px-3 py-2 text-left">Alasan</th>
                                    <th class="px-3 py-2 text-left">NIK</th>
                                    <th class="px-3 py-2 text-left">Nama Pasien</th>
                                    <th class="px-3 py-2 text-left">Jenis Kelamin</th>
                                    <th class="px-3 py-2 text-left">Tgl.Lahir</th>
                                    <th class="px-3 py-2 text-left">No Telp</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($invalid_preview_rows as $idx => $invalid_row): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-3 py-2 text-center text-slate-500"><?= $idx + 1 ?></td>
                                        <td class="px-3 py-2 text-center font-semibold text-slate-700"><?= (int) ($invalid_row['row_number'] ?? 0) ?></td>
                                        <td class="px-3 py-2 text-rose-600 font-semibold"><?= h((string) ($invalid_row['reason'] ?? '')) ?></td>
                                        <td class="px-3 py-2 font-mono text-slate-700"><?= h(format_nik((string) ($invalid_row['nik'] ?? ''))) ?></td>
                                        <td class="px-3 py-2 text-slate-700"><?= h((string) ($invalid_row['nama_pasien'] ?? '')) ?></td>
                                        <td class="px-3 py-2 text-slate-700"><?= h((string) ($invalid_row['jenis_kelamin'] ?? '')) ?></td>
                                        <td class="px-3 py-2 text-slate-700"><?= h((string) ($invalid_row['tgl_lahir'] ?? '')) ?></td>
                                        <td class="px-3 py-2 text-slate-700"><?= h((string) ($invalid_row['no_telp'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="px-5 py-4 border-t border-slate-100 flex items-center justify-end gap-2">
                    <a href="upload.php?download_invalid=1" class="px-3 py-2 bg-slate-700 hover:bg-slate-800 text-white rounded-lg text-xs font-bold">
                        Download Excel Perbaikan
                    </a>
                    <button type="button" onclick="close_invalid_rows_modal()" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-bold">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-4">
        <div class="xl:col-span-4 bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] ring-1 ring-slate-100/50 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-50">
                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Pengaturan Kolom Support</h3>
            </div>
            <div class="p-4 space-y-4">
                <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500 mb-2">Kolom Aktif</div>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($supported_upload_headers as $supported_header): ?>
                            <span class="px-2 py-0.5 rounded-full bg-white border border-slate-200 text-slate-600 text-[11px] font-semibold">
                                <?= h($supported_header) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="action" value="save_supported_headers">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <textarea
                        name="supported_headers_input"
                        rows="10"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-cyan-500"
                        placeholder="Satu kolom per baris"><?= h($supported_upload_headers_text) ?></textarea>
                    <div class="text-[11px] text-slate-500">Isi satu kolom per baris. Contoh: NIK, Nama Pasien, Status Pernikahan, Training.</div>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit"
                            name="supported_headers_mode"
                            value="save"
                            class="px-3 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-xs font-bold">
                            Simpan Kolom Support
                        </button>
                        <button type="submit"
                            name="supported_headers_mode"
                            value="reset"
                            class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-bold">
                            Reset Default
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="xl:col-span-8 bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] ring-1 ring-slate-100/50 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-50 flex flex-col sm:flex-row items-center justify-between gap-4">
                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wide">Riwayat Upload</h3>
                <?php if (!$preview_mode): ?>
                    <button onclick="open_upload_modal()" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-gradient-to-br from-teal-500 to-teal-600 hover:from-teal-600 hover:to-teal-700 text-white rounded-xl text-xs font-bold transition-all shadow-md shadow-teal-500/20 active:scale-95 w-full sm:w-auto">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                        Upload Data Baru
                    </button>
                <?php endif; ?>
            </div>
            <?php if (empty($uploads)): ?>
                <div class="px-6 py-16 text-center">
                    <svg class="w-12 h-12 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0
                       012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <p class="text-sm text-slate-400 font-semibold">Belum ada upload</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                                <th class="px-4 py-3 text-left">File</th>
                                <th class="px-4 py-3 text-left hidden sm:table-cell">Kolom</th>
                                <th class="px-4 py-3 text-center">Total</th>
                                <th class="px-4 py-3 text-center">Sisa</th>
                                <th class="px-4 py-3 text-left hidden md:table-cell">Waktu Upload</th>
                                <th class="px-4 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach ($uploads as $up):
                                $fields = json_decode($up['detected_fields'] ?? '[]', true) ?: [];
                                $total = (int) $up['row_count'];
                                $sisa = $sisa_map[$up['id']] ?? 0;
                                $diproses = $total - $sisa;
                                $pct = $total > 0 ? min(100, (int) round($diproses / $total * 100)) : 0;
                            ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <!-- File -->
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-slate-800 text-sm truncate max-w-[180px]">
                                            <?= h($up['file_name']) ?>
                                        </div>
                                        <div class="text-[10px] text-slate-400 mt-0.5">
                                            <?= $up['total_rows'] ?> baris asli
                                        </div>
                                    </td>

                                    <!-- Kolom -->
                                    <td class="px-4 py-3 hidden sm:table-cell">
                                        <div class="flex flex-wrap gap-1 max-w-[220px]">
                                            <?php foreach (array_slice($fields, 0, 4) as $f): ?>
                                                <span class="px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-medium">
                                                    <?= h($f) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($fields) > 4): ?>
                                                <span class="text-[10px] text-slate-400">+<?= count($fields) - 4 ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3 text-center">
                                        <span class="font-bold text-slate-800 text-base"><?= number_format($total) ?></span>
                                        <div class="text-[9px] text-slate-400 uppercase font-bold">peserta</div>
                                    </td>

                                    <!-- Sisa + mini progress -->
                                    <td class="px-4 py-3 text-center min-w-[100px]">
                                        <span class="font-bold text-teal-700 text-base"><?= number_format($sisa) ?></span>
                                        <div class="mt-1 h-1 bg-slate-100 rounded-full overflow-hidden w-16 mx-auto">
                                            <div class="h-full bg-teal-500 rounded-full transition-all"
                                                style="width:<?= 100 - $pct ?>%"></div>
                                        </div>
                                        <div class="text-[9px] text-slate-400 mt-0.5"><?= $diproses ?> diproses</div>
                                    </td>

                                    <!-- Waktu -->
                                    <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap hidden md:table-cell">
                                        <?= date('d/m/Y H:i', strtotime($up['uploaded_at'])) ?>
                                    </td>

                                    <!-- Aksi -->
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-1">

                                            <!-- Lihat Data -->
                                            <a href="data.php?scope=<?= urlencode($scope_mode) ?>&upload_id=<?= $up['id'] ?>" title="Lihat Data Peserta"
                                                class="p-1.5 text-slate-400 hover:text-teal-600 hover:bg-teal-50 rounded-lg transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943
                                               9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </a>

                                            <!-- Hapus -->
                                            <a href="upload.php?delete=<?= $up['id'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>"
                                                title="Hapus Upload"
                                                data-file-name="<?= h((string) $up['file_name']) ?>"
                                                data-total="<?= (int) $total ?>"
                                                class="delete_upload_btn p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858
                                               L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </a>

                                        </div>
                                    </td>


                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>


<div id="modalUpload"
    class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-[0_8px_30px_rgb(0,0,0,0.12)] w-full max-w-xl relative">
        <div class="px-6 py-4 border-b border-slate-50 flex items-center justify-between">
            <h3 class="font-bold text-[15px] text-slate-800">Upload Data (Excel, CSV, EPUS)</h3>
            <button onclick="close_upload_modal()"
                class="text-slate-400 hover:text-slate-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="p-6 space-y-6">
            <div class="grid grid-cols-2 gap-2 p-1 rounded-xl bg-slate-100">
                <button type="button" data-upload-tab="local" class="upload_tab_btn py-2 rounded-lg text-xs font-bold bg-white text-slate-700 shadow-sm">
                    Upload File
                </button>
                <button type="button" data-upload-tab="epus" class="upload_tab_btn py-2 rounded-lg text-xs font-bold text-slate-500">
                    Ambil EPUS
                </button>
            </div>

            <div id="upload_tab_local" class="space-y-4">
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="text-xs font-bold uppercase tracking-widest text-slate-500">Upload File Lokal</div>
                    <label id="dropZone" class="block border-2 border-dashed border-slate-200 rounded-xl p-8 text-center cursor-pointer
                       hover:border-teal-400 hover:bg-teal-50 transition-all"
                        ondragover="event.preventDefault(); this.classList.add('border-teal-400','bg-teal-50')"
                        ondragleave="this.classList.remove('border-teal-400','bg-teal-50')" ondrop="handleDrop(event)">
                        <svg class="w-10 h-10 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0
                           011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <p id="dropText" class="text-sm font-semibold text-slate-500">
                            Drag &amp; drop, atau klik untuk pilih file
                        </p>
                        <p class="text-xs text-slate-400 mt-1">Format: .xlsx atau .csv </p>
                        <input type="file" name="excel" id="fileInput" accept=".xlsx,.csv" class="hidden"
                            onchange="document.getElementById('dropText').textContent = this.files[0]?.name || 'Pilih file'">
                    </label>
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-700">
                        <strong>Catatan:</strong> Baris <strong>pertama</strong> wajib berisi nama kolom
                        (NIK, Nama, dst). Data dimulai dari baris kedua.
                    </div>
                    <button type="submit"
                        class="w-full py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold">
                        Upload &amp; Preview
                    </button>
                </form>
            </div>

            <div id="upload_tab_epus" class="space-y-4 hidden">
                <form method="POST" class="space-y-4" id="epus_form">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="text-xs font-bold uppercase tracking-widest text-slate-500">Ambil Data Dari EPUS</div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <div class="flex-1 p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-600">
                            <?php if ($epus_cursor_active): ?>
                                Mode lanjutan aktif. Klik reset bila ingin mulai dari awal source EPUS.
                            <?php else: ?>
                                Posisi saat ini dari awal source EPUS.
                            <?php endif; ?>
                        </div>
                        <button type="submit"
                            name="action"
                            value="reset_epus_cursor"
                            onclick="set_epus_submit_mode('reset')"
                            class="sm:w-auto w-full px-4 py-2.5 bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-700 rounded-xl text-sm font-bold whitespace-nowrap">
                            Reset Posisi EPUS
                        </button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Jumlah Data</label>
                            <input type="number" min="1" max="1000" name="epus_total" value="<?= (int) ($_POST['epus_total'] ?? 100) ?>"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Jenis Kelamin</label>
                            <select name="epus_gender"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 bg-white">
                                <option value="all" <?= $epus_gender_input === 'all' ? 'selected' : '' ?>>Semua</option>
                                <option value="laki-laki" <?= $epus_gender_input === 'laki-laki' ? 'selected' : '' ?>>Laki-laki</option>
                                <option value="perempuan" <?= $epus_gender_input === 'perempuan' ? 'selected' : '' ?>>Perempuan</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Usia Minimal</label>
                            <input type="number" min="0" max="150" name="epus_age_min" value="<?= h($epus_age_min_input) ?>"
                                placeholder="Contoh: 18"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Usia Maksimal</label>
                            <input type="number" min="0" max="150" name="epus_age_max" value="<?= h($epus_age_max_input) ?>"
                                placeholder="Contoh: 60"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500">
                        </div>
                    </div>
                    <div class="text-[11px] text-slate-500">
                        Filter jenis kelamin dan usia bersifat opsional. Jika tidak diisi, sistem ambil data bebas sesuai jumlah data. Batas maksimal 1.000 data per proses.
                    </div>
                    <label class="flex items-center gap-2 p-3 border border-slate-200 rounded-xl text-xs text-slate-600">
                        <input type="checkbox" name="epus_reset_cursor" value="1" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">
                        Mulai dari awal source EPUS untuk pengambilan ini
                    </label>
                    <?php if ($epus_ready): ?>
                        <div class="p-3 bg-cyan-50 border border-cyan-200 rounded-xl text-xs text-cyan-700">
                            EPUS siap dipakai. Data baru akan masuk ke Data Peserta dan data duplikat otomatis dilewati.
                        </div>
                        <button type="submit"
                            name="action"
                            value="fetch_epus"
                            onclick="set_epus_submit_mode('fetch')"
                            class="w-full py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-xl text-sm font-bold">
                            Ambil Data EPUS
                        </button>
                    <?php else: ?>
                        <div class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700">
                            EPUS belum siap. Minta admin isi API URL dan Cookie EPUS di Pengaturan Admin.
                        </div>
                        <button type="button"
                            class="w-full py-2.5 bg-slate-200 text-slate-500 rounded-xl text-sm font-bold cursor-not-allowed">
                            Ambil Data EPUS
                        </button>
                    <?php endif; ?>
                    <input type="hidden" id="epus_submit_mode" value="">
                </form>
            </div>

            <button type="button" onclick="close_upload_modal()"
                class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-bold">
                Tutup
            </button>
        </div>
        <div id="epus_loading_layer" class="hidden absolute inset-0 bg-white/90 rounded-2xl z-20 items-center justify-center p-6">
            <div class="text-center">
                <div class="mx-auto mb-3 h-10 w-10 rounded-full border-4 border-cyan-200 border-t-cyan-600 animate-spin"></div>
                <div id="epus_loading_text" class="text-sm font-semibold text-slate-700">Memproses data EPUS...</div>
                <div id="epus_loading_progress_wrap" class="mt-3 hidden">
                    <div id="epus_loading_progress_label" class="text-xs font-semibold text-cyan-700">0/0</div>
                    <div class="mt-1 h-2 w-56 bg-slate-200 rounded-full overflow-hidden mx-auto">
                        <div id="epus_loading_progress_bar" class="h-full w-0 bg-cyan-600 rounded-full transition-all duration-500"></div>
                    </div>
                </div>
                <div id="epus_loading_subtext" class="text-xs text-slate-500 mt-2">Mohon tunggu, halaman akan diperbarui otomatis.</div>
            </div>
        </div>
    </div>
</div>

<div id="delete_confirm_modal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-[0_8px_30px_rgb(0,0,0,0.12)] p-5">
        <h3 class="text-base font-bold text-slate-800">Konfirmasi Hapus</h3>
        <p id="delete_confirm_text" class="mt-2 text-sm text-slate-600"></p>
        <div class="mt-5 grid grid-cols-2 gap-2">
            <button type="button" onclick="close_delete_confirm_modal()" class="py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-bold">
                Batal
            </button>
            <a id="delete_confirm_go" href="#" class="py-2.5 text-center bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-sm font-bold">
                Hapus
            </a>
        </div>
    </div>
</div>

<script>
    function set_upload_tab(tab_name) {
        const tab_local = document.getElementById('upload_tab_local');
        const tab_epus = document.getElementById('upload_tab_epus');
        const tab_buttons = document.querySelectorAll('.upload_tab_btn');
        const active_name = tab_name === 'epus' ? 'epus' : 'local';

        if (tab_local) tab_local.classList.toggle('hidden', active_name !== 'local');
        if (tab_epus) tab_epus.classList.toggle('hidden', active_name !== 'epus');

        tab_buttons.forEach(btn => {
            const is_active = btn.getAttribute('data-upload-tab') === active_name;
            btn.classList.toggle('bg-white', is_active);
            btn.classList.toggle('text-slate-700', is_active);
            btn.classList.toggle('shadow-sm', is_active);
            btn.classList.toggle('text-slate-500', !is_active);
        });
    }

    function open_upload_modal() {
        const modal = document.getElementById('modalUpload');
        if (!modal) return;
        modal.classList.remove('hidden');
        set_upload_tab('local');
    }

    function close_upload_modal() {
        const modal = document.getElementById('modalUpload');
        if (!modal) return;
        modal.classList.add('hidden');
    }

    function open_delete_confirm_modal(delete_url, delete_text) {
        const modal = document.getElementById('delete_confirm_modal');
        const text = document.getElementById('delete_confirm_text');
        const go = document.getElementById('delete_confirm_go');
        if (!modal || !text || !go) return false;
        text.textContent = delete_text || 'Hapus data ini?';
        go.setAttribute('href', delete_url || '#');
        modal.classList.remove('hidden');
        return false;
    }

    function close_delete_confirm_modal() {
        const modal = document.getElementById('delete_confirm_modal');
        if (!modal) return;
        modal.classList.add('hidden');
    }

    function open_invalid_rows_modal() {
        const modal = document.getElementById('invalid_rows_modal');
        if (!modal) return;
        modal.classList.remove('hidden');
    }

    function close_invalid_rows_modal() {
        const modal = document.getElementById('invalid_rows_modal');
        if (!modal) return;
        modal.classList.add('hidden');
    }

    function set_epus_submit_mode(mode_name) {
        const mode_input = document.getElementById('epus_submit_mode');
        if (mode_input)
            mode_input.value = mode_name;
    }

    function show_epus_loading(mode_name) {
        const modal = document.getElementById('modalUpload');
        const layer = document.getElementById('epus_loading_layer');
        const text = document.getElementById('epus_loading_text');
        const subtext = document.getElementById('epus_loading_subtext');
        const progress_wrap = document.getElementById('epus_loading_progress_wrap');
        const progress_label = document.getElementById('epus_loading_progress_label');
        const progress_bar = document.getElementById('epus_loading_progress_bar');
        if (!modal || !layer || !text || !subtext || !progress_wrap || !progress_label || !progress_bar)
            return;

        if (window.epus_progress_timer) {
            clearInterval(window.epus_progress_timer);
            window.epus_progress_timer = null;
        }

        if (mode_name === 'reset') {
            text.textContent = 'Mereset posisi EPUS...';
            subtext.textContent = 'Menyiapkan pengambilan dari awal source EPUS.';
            progress_wrap.classList.add('hidden');
            progress_label.textContent = '0/0';
            progress_bar.style.width = '0%';
        } else {
            const epus_total_input = document.querySelector('input[name="epus_total"]');
            const total_rows = Math.max(1, parseInt(epus_total_input?.value || '100', 10));
            const status_list = [
                'Mengambil data dari EPUS...',
                'Mencocokkan NIK dengan database...',
                'Menyaring data duplikat...',
                'Menyimpan hasil ke data peserta...',
            ];
            let processed_now = 0;

            text.textContent = 'Mengambil data EPUS...';
            subtext.textContent = 'Sistem sedang memproses data dan mencocokkan dengan database.';
            progress_wrap.classList.remove('hidden');

            const update_progress = () => {
                const progress_ratio = processed_now / total_rows;
                const status_index = Math.min(status_list.length - 1, Math.floor(progress_ratio * status_list.length));
                const status_now = status_list[status_index];
                text.textContent = status_now;
                progress_label.textContent = `${processed_now}/${total_rows}`;
                const progress_percent = Math.min(95, Math.max(1, Math.round((processed_now / total_rows) * 100)));
                progress_bar.style.width = `${progress_percent}%`;
                if (processed_now < total_rows)
                    processed_now++;
            };

            update_progress();
            window.epus_progress_timer = setInterval(update_progress, 35);
        }

        layer.classList.remove('hidden');
        layer.classList.add('flex');
    }

    document.querySelectorAll('.upload_tab_btn').forEach(btn => {
        btn.addEventListener('click', () => set_upload_tab(btn.getAttribute('data-upload-tab') || 'local'));
    });

    document.querySelectorAll('.delete_upload_btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const delete_url = this.getAttribute('href') || '#';
            const delete_file_name = this.getAttribute('data-file-name') || 'file ini';
            const delete_total_raw = parseInt(this.getAttribute('data-total') || '0', 10);
            const delete_total = Number.isFinite(delete_total_raw) ? delete_total_raw : 0;
            const delete_text = `Hapus file "${delete_file_name}"? ${delete_total.toLocaleString('id-ID')} data peserta terkait ikut terhapus.`;
            open_delete_confirm_modal(delete_url, delete_text);
        });
    });

    const delete_confirm_modal = document.getElementById('delete_confirm_modal');
    if (delete_confirm_modal) {
        delete_confirm_modal.addEventListener('click', function(e) {
            if (e.target === delete_confirm_modal)
                close_delete_confirm_modal();
        });
    }

    const invalid_rows_modal = document.getElementById('invalid_rows_modal');
    if (invalid_rows_modal) {
        invalid_rows_modal.addEventListener('click', function(e) {
            if (e.target === invalid_rows_modal)
                close_invalid_rows_modal();
        });
    }

    const epus_form = document.getElementById('epus_form');
    if (epus_form) {
        epus_form.addEventListener('submit', function() {
            const mode_input = document.getElementById('epus_submit_mode');
            const mode_name = (mode_input?.value || 'fetch').toLowerCase();
            show_epus_loading(mode_name);
        });
    }

    function handleDrop(e) {
        e.preventDefault();
        document.getElementById('dropZone').classList.remove('border-teal-400', 'bg-teal-50');
        const file = e.dataTransfer.files[0];
        if (!file) return;
        const dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('fileInput').files = dt.files;
        document.getElementById('dropText').textContent = file.name;
    }


    function toggleDl(id) {
        const el = document.getElementById('dl-' + id);

        document.querySelectorAll('[id^="dl-"]').forEach(d => {
            if (d.id !== 'dl-' + id) d.classList.add('hidden');
        });
        el.classList.toggle('hidden');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('[id^="dl-"]') && !e.target.closest('button[onclick^="toggleDl"]'))
            document.querySelectorAll('[id^="dl-"]').forEach(d => d.classList.add('hidden'));
    });

    set_upload_tab('local');
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>