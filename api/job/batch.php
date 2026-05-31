<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$lic = api_auth();
$db = db();

$preview_mode = (string) ($_POST['preview_mode'] ?? '') === '1';

if ($preview_mode) {
    $scope_mode = resolve_scope_mode_from_license($lic);
    $owner_user_id = isset($lic['user_id']) ? (int) $lic['user_id'] : 0;

    if ($owner_user_id <= 0) {
        try {
            $owner_stmt = $db->prepare("SELECT user_id FROM license_keys WHERE id = ? LIMIT 1");
            $owner_stmt->execute([(int) ($lic['id'] ?? 0)]);
            $owner_user_id = (int) ($owner_stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {
            $owner_user_id = 0;
        }
    }

    $posted_package_key = trim((string) ($_POST['package_key'] ?? ''));
    $posted_jenis_kelamin = parse_jk((string) ($_POST['jenis_kelamin'] ?? ''));
    $posted_tanggal_lahir = normalize_birth_date((string) ($_POST['tanggal_lahir'] ?? ''));
    $posted_usia_tahun = (int) ($_POST['usia_tahun'] ?? 0);

    $age_year = $posted_usia_tahun > 0
        ? $posted_usia_tahun
        : calculate_age_years_from_birth_date($posted_tanggal_lahir);

    $package_key = $posted_package_key !== ''
        ? $posted_package_key
        : resolve_package_key_from_demography($posted_jenis_kelamin, $age_year);

    $pemeriksaan_payload_pair = build_pemeriksaan_payload_pair(
        $db,
        $owner_user_id,
        $scope_mode,
        $package_key
    );
    $pemeriksaan_mandiri_payload = $pemeriksaan_payload_pair['pemeriksaan_mandiri'] ?? [];
    $pemeriksaan_nakes_payload = $pemeriksaan_payload_pair['pemeriksaan_nakes'] ?? [];

    json_response([
        'ok' => true,
        'preview_mode' => true,
        'preview' => [
            'user_id' => $owner_user_id,
            'scope_mode' => $scope_mode,
            'jenis_kelamin' => $posted_jenis_kelamin,
            'tanggal_lahir' => $posted_tanggal_lahir,
            'usia_tahun' => $age_year,
            'package_key' => $package_key,
        ],
        'pemeriksaan_mandiri' => $pemeriksaan_mandiri_payload,
        'pemeriksaan_nakes' => $pemeriksaan_nakes_payload
    ]);
}

$limit = max(1, min((int)($_POST['limit'] ?? 50), 1000));

if (!(int)$lic['is_running']) {
    json_response(['ok' => true, 'jobs' => [], 'count' => 0, 'reason' => 'is_running=0']);
}

$db->beginTransaction();

try {
    $limit_int = (int)$limit;

    $stmt = $db->prepare("\n        SELECT jq.id AS job_id,\n               jq.user_id,\n               jq.patient_id,\n               jq.task_type,\n               pd.data\n        FROM job_queue jq\n        JOIN patients_data pd ON pd.id = jq.patient_id\n        WHERE jq.license_key_id = ?\n          AND jq.status = 'pending'\n        ORDER BY jq.id ASC\n        LIMIT $limit_int\n        FOR UPDATE\n    ");
    $stmt->execute([$lic['id']]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $db->commit();
        $db->prepare('UPDATE license_keys SET is_running = 0 WHERE id = ?')->execute([$lic['id']]);
        json_response(['ok' => true, 'jobs' => [], 'count' => 0]);
    }

    $ids = implode(',', array_map('intval', array_column($rows, 'job_id')));
    $db->exec("UPDATE job_queue SET status='running', started_at=NOW() WHERE id IN ($ids)");
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    json_response(['error' => 'DB error'], 500);
}

function clean_nik(string $val): string
{
    return preg_replace('/\D+/', '', $val);
}

function clean_phone(string $val): string
{
    $p = preg_replace('/\D+/', '', $val);
    if (strlen($p) > 1 && $p[0] === '0') {
        $p = substr($p, 1);
    }
    return substr($p, 0, 13);
}

function clean_nama(string $val): string
{
    return trim($val);
}

function is_valid_nik(string $nik): bool
{
    $len = strlen($nik);
    return $len === 16 || $len === 13;
}

function is_valid_nama(string $nama): bool
{
    return $nama !== '' && (bool)preg_match('/^[\p{L}\s\'\-\.,]+$/u', $nama);
}

function parse_jk(string $val): string
{
    if (stripos($val, 'perem') !== false) return 'Perempuan';
    if (stripos($val, 'laki') !== false) return 'Laki-laki';
    return '';
}

function normalize_lookup_text(string $value): string
{
    $value = strtolower(trim($value));
    return preg_replace('/[^a-z0-9]+/', ' ', $value);
}

function contains_any_token(string $value, array $tokens): bool
{
    foreach ($tokens as $token) {
        if ($token !== '' && str_contains($value, $token)) {
            return true;
        }
    }

    return false;
}

function normalize_status_pernikahan(string $value): string
{
    $clean_value = trim($value);
    if ($clean_value === '') {
        return '';
    }

    $lookup_value = normalize_lookup_text($clean_value);
    $status_rules = [
        'Cerai Mati' => ['cerai mati', 'janda mati', 'duda mati'],
        'Cerai Hidup' => ['cerai hidup', 'cerai'],
        'Belum Menikah' => ['belum menikah', 'belum kawin', 'tidak kawin', 'single'],
        'Menikah' => ['menikah', 'kawin', 'nikah'],
    ];

    foreach ($status_rules as $status_label => $status_tokens) {
        if (contains_any_token($lookup_value, $status_tokens)) {
            return $status_label;
        }
    }

    return $clean_value;
}

function normalize_disability_status(string $value): string
{
    $clean_value = trim($value);
    if ($clean_value === '') {
        return '';
    }

    $lookup_value = normalize_lookup_text($clean_value);
    $status_rules = [
        'Tidak memiliki disabilitas' => ['tidak', 'none', 'normal', 'sehat'],
        'Memiliki disabilitas' => ['disabil', 'difabel', 'cacat', 'ya', 'memiliki'],
    ];

    foreach ($status_rules as $status_label => $status_tokens) {
        if (contains_any_token($lookup_value, $status_tokens)) {
            return $status_label;
        }
    }

    return $clean_value;
}

function normalize_col_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9]+/', '', $key);
    return $key;
}

function pick_col_value(array $raw, array $preferred_keys = [], array $contains_tokens = []): string
{
    $normalized_map = [];

    foreach ($raw as $k => $v) {
        $nk = normalize_col_key((string)$k);
        if (!array_key_exists($nk, $normalized_map)) {
            $normalized_map[$nk] = is_scalar($v) ? trim((string)$v) : '';
        }
    }

    foreach ($preferred_keys as $k) {
        if (isset($normalized_map[$k]) && $normalized_map[$k] !== '') {
            return $normalized_map[$k];
        }
    }

    if (!empty($contains_tokens)) {
        foreach ($normalized_map as $nk => $val) {
            if ($val === '') {
                continue;
            }

            $match = true;
            foreach ($contains_tokens as $token) {
                if (!str_contains($nk, $token)) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                return $val;
            }
        }
    }

    return '';
}

function normalize_birth_date(string $value): string
{
    $s = trim($value);
    if ($s === '') {
        return '';
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : '';
    }

    if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})(?:\s+.*)?$/', $s, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }

    if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})[-\/\.](\d{4})(?:\s+.*)?$/', $s, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }

    if (preg_match('/^\d{4,6}$/', $s)) {
        $serial = (int)$s;
        if ($serial >= 20000 && $serial <= 90000) {
            $base = new DateTimeImmutable('1899-12-30');
            $date = $base->modify('+' . $serial . ' days');
            return $date->format('Y-m-d');
        }
    }

    return '';
}

function calculate_age_years_from_birth_date(string $birth_date): ?int
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birth_date, $m))
        return null;

    $birth_year = (int) $m[1];
    $birth_month = (int) $m[2];
    $birth_day = (int) $m[3];
    if (!checkdate($birth_month, $birth_day, $birth_year))
        return null;

    $today = new DateTimeImmutable('today');
    $birth = DateTimeImmutable::createFromFormat('Y-m-d', $birth_date);
    if (!$birth)
        return null;

    return (int) $birth->diff($today)->y;
}

function normalize_operational_age_text(string $value): string
{
    $clean_value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($clean_value === '')
        return '';
    return $clean_value;
}

function resolve_operational_age(array $raw, string $birth_date): array
{
    $age_year = calculate_age_years_from_birth_date($birth_date);
    if ($age_year !== null)
        return ['umur_operasional' => $age_year . ' Tahun', 'age_year' => $age_year];

    $age_from_server = normalize_operational_age_text((string) pick_col_value(
        $raw,
        ['umuroperasional', 'umur', 'usia', 'age'],
        ['umur']
    ));
    if ($age_from_server !== '') {
        $fallback_age_year = null;
        if (preg_match('/(\d{1,3})/', $age_from_server, $m))
            $fallback_age_year = (int) $m[1];
        return ['umur_operasional' => $age_from_server, 'age_year' => $fallback_age_year];
    }

    return ['umur_operasional' => '', 'age_year' => null];
}

function resolve_scope_mode_from_license(array $license): string
{
    $mode = strtolower(trim((string) ($license['mode'] ?? 'umum')));
    return $mode === 'sekolah' ? 'sekolah' : 'umum';
}

function resolve_package_key_from_demography(string $jenis_kelamin, ?int $age_year): string
{
    if ($age_year === null)
        return '';

    if ($age_year < 1)
        return '0lp_lt1tahun';
    if ($age_year < 2)
        return '1lp_lt2tahun';
    if ($age_year < 3)
        return '2lp_lt3tahun';
    if ($age_year <= 6)
        return '3lp_3_6_tahun';

    $jk = strtolower(trim($jenis_kelamin));
    $is_perempuan = str_contains($jk, 'perempuan');
    $is_laki_laki = str_contains($jk, 'laki');

    if ($is_laki_laki) {
        if ($age_year >= 60)
            return '8l_gt60';
        if ($age_year >= 45)
            return '8l_45_59';
        if ($age_year >= 40)
            return '6l_lt45_gt40';
        return '6l_lt45_lt40';
    }

    if ($is_perempuan) {
        if ($age_year >= 60)
            return '9p_gt60';
        if ($age_year >= 45)
            return '9p_45_59';
        if ($age_year >= 40)
            return '7p_40_44';
        if ($age_year >= 30)
            return '8p_30_39';
        if ($age_year >= 18)
            return '7p_18_29';
        return '7p_18_29';
    }

    return '';
}

function normalize_package_token(string $value): string
{
    $token = strtolower(trim($value));
    $token = str_replace(['<=', '>=', '≤', '≥', '<', '>'], [' lte ', ' gte ', ' lte ', ' gte ', ' lt ', ' gt '], $token);
    $token = preg_replace('/[^a-z0-9]+/', '', $token) ?? '';
    return trim($token);
}

function resolve_package_label_from_key(string $package_key): string
{
    $package_map = [
        '6l_lt45_lt40' => '6L 18-45 THN <40 THN',
        '6l_lt45_gt40' => '6L 18-45 THN >40 THN',
        '8l_45_59' => '8L 45-59 TAHUN',
        '8l_gt60' => '8L >60 TAHUN',
        '7p_18_29' => '7P 18-29 TAHUN',
        '7p_40_44' => '7P 40-44 TAHUN',
        '8p_30_39' => '8P 30-39 TAHUN',
        '9p_45_59' => '9P 45-59 TAHUN',
        '9p_gt60' => '9P >60 TAHUN'
    ];
    return (string) ($package_map[$package_key] ?? '');
}

function resolve_service_scope_key(string $jenis_pemeriksaan, string $service_name): string
{
    $jenis_lookup = normalize_lookup_text($jenis_pemeriksaan);
    $service_lookup = normalize_lookup_text($service_name);
    $combined_lookup = trim($jenis_lookup . ' ' . $service_lookup);

    if (str_contains($combined_lookup, 'mandiri'))
        return 'mandiri';
    if (str_contains($combined_lookup, 'nakes'))
        return 'nakes';

    $mandiri_tokens = [
        'demografi lansia',
        'faktor risiko kanker usus',
        'faktor risiko tb',
        'hati',
        'kesehatan jiwa',
        'penapisan risiko kanker paru',
        'perilaku merokok',
        'tingkat aktivitas fisik'
    ];

    foreach ($mandiri_tokens as $token) {
        if (str_contains($service_lookup, $token))
            return 'mandiri';
    }

    return 'nakes';
}

function build_payload_from_question_map(array $question_map, string $batch_key, string $package_key): array
{
    $result = [];
    $service_no = 1;
    foreach ($question_map as $service_name => $question_list) {
        $formatted_questions = [];
        $question_no = 1;
        foreach ($question_list as $question_item) {
            $formatted_questions[] = [
                'nomor' => $question_no,
                'text' => $question_item['text'],
                'jawaban' => $question_item['jawaban'],
                'jawaban_default' => $question_item['jawaban_default'],
                'answer_mode' => $question_item['answer_mode'],
                'answer_type' => $question_item['answer_type']
            ];
            $question_no++;
        }

        $result[] = [
            'no' => $service_no,
            'nama' => $service_name,
            'pertanyaan' => $formatted_questions
        ];
        $service_no++;
    }

    return [
        'batch_key' => $batch_key,
        'package_key' => $package_key,
        'total_jenis_pemeriksaan' => count($result),
        'total_pertanyaan' => array_sum(array_map(static fn($i) => count($i['pertanyaan']), $result)),
        'items' => $result
    ];
}

function build_pemeriksaan_payload_pair(PDO $db, int $user_id, string $scope_mode, string $package_key): array
{
    static $latest_batch_cache = [];
    static $latest_batch_by_package_cache = [];
    static $question_cache = [];

    if ($user_id <= 0 || $package_key === '')
        return [
            'pemeriksaan_mandiri' => [],
            'pemeriksaan_nakes' => []
        ];

    $cache_key = $user_id . '|' . $scope_mode;
    if (!array_key_exists($cache_key, $latest_batch_cache)) {
        $latest_batch_stmt = $db->prepare("
            SELECT batch_key
            FROM pelayanan_question_bank
            WHERE user_id = ?
              AND ckg_scope = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $latest_batch_stmt->execute([$user_id, $scope_mode]);
        $latest_batch_cache[$cache_key] = (string) ($latest_batch_stmt->fetchColumn() ?: '');
    }

    $package_cache_key = $cache_key . '|' . $package_key;
    if (!array_key_exists($package_cache_key, $latest_batch_by_package_cache)) {
        $latest_package_batch_stmt = $db->prepare("
            SELECT batch_key
            FROM pelayanan_question_bank
            WHERE user_id = ?
              AND ckg_scope = ?
              AND package_key = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $latest_package_batch_stmt->execute([$user_id, $scope_mode, $package_key]);
        $exact_batch_key = (string) ($latest_package_batch_stmt->fetchColumn() ?: '');
        $resolved_package_key = $package_key;

        if ($exact_batch_key === '') {
            $label_hint = resolve_package_label_from_key($package_key);
            $request_token = normalize_package_token($package_key);
            $label_hint_token = normalize_package_token($label_hint);
            $candidate_stmt = $db->prepare("
                SELECT batch_key, package_key, package_label
                FROM pelayanan_question_bank
                WHERE user_id = ?
                  AND ckg_scope = ?
                ORDER BY id DESC
                LIMIT 2000
            ");
            $candidate_stmt->execute([$user_id, $scope_mode]);
            $candidate_rows = $candidate_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($candidate_rows as $candidate_row) {
                $candidate_package_key = trim((string) ($candidate_row['package_key'] ?? ''));
                $candidate_package_label = trim((string) ($candidate_row['package_label'] ?? ''));
                if ($candidate_package_key === '' && $candidate_package_label === '')
                    continue;
                $candidate_key_token = normalize_package_token($candidate_package_key);
                $candidate_label_token = normalize_package_token($candidate_package_label);
                $is_match = $candidate_key_token !== '' && $candidate_key_token === $request_token;
                if (!$is_match && $candidate_label_token !== '')
                    $is_match = $candidate_label_token === $request_token || ($label_hint_token !== '' && $candidate_label_token === $label_hint_token);
                if (!$is_match)
                    continue;
                $exact_batch_key = trim((string) ($candidate_row['batch_key'] ?? ''));
                if ($exact_batch_key === '')
                    continue;
                if ($candidate_package_key !== '')
                    $resolved_package_key = $candidate_package_key;
                break;
            }
        }

        $latest_batch_by_package_cache[$package_cache_key] = [
            'batch_key' => $exact_batch_key,
            'resolved_package_key' => $resolved_package_key
        ];
    }

    $package_context = is_array($latest_batch_by_package_cache[$package_cache_key] ?? null)
        ? $latest_batch_by_package_cache[$package_cache_key]
        : ['batch_key' => '', 'resolved_package_key' => $package_key];
    $resolved_package_key = trim((string) ($package_context['resolved_package_key'] ?? $package_key));
    if ($resolved_package_key === '')
        $resolved_package_key = $package_key;

    $batch_key = trim((string) ($package_context['batch_key'] ?? '')) !== ''
        ? trim((string) ($package_context['batch_key'] ?? ''))
        : $latest_batch_cache[$cache_key];
    if ($batch_key === '')
        return [
            'pemeriksaan_mandiri' => [],
            'pemeriksaan_nakes' => []
        ];

    $question_key = $cache_key . '|' . $batch_key . '|' . $resolved_package_key;
    if (!array_key_exists($question_key, $question_cache)) {
        $question_stmt = $db->prepare("
            SELECT
                jenis_pemeriksaan,
                kategori,
                pertanyaan,
                jawaban_list,
                jawaban_default,
                answer_mode,
                answer_type
            FROM pelayanan_question_bank
            WHERE user_id = ?
              AND ckg_scope = ?
              AND batch_key = ?
              AND package_key = ?
            ORDER BY id ASC
        ");
        $question_stmt->execute([$user_id, $scope_mode, $batch_key, $resolved_package_key]);
        $question_rows = $question_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mandiri_question_map = [];
        $nakes_question_map = [];
        foreach ($question_rows as $row) {
            $kategori = trim((string) ($row['kategori'] ?? ''));
            $jenis_pemeriksaan = trim((string) ($row['jenis_pemeriksaan'] ?? ''));
            $service_name = $kategori !== '' ? $kategori : $jenis_pemeriksaan;
            if ($service_name === '')
                $service_name = 'Umum';
            $scope_key = resolve_service_scope_key($jenis_pemeriksaan, $service_name);
            $target_map = $scope_key === 'mandiri'
                ? 'mandiri_question_map'
                : 'nakes_question_map';
            if (!isset(${$target_map}[$service_name]))
                ${$target_map}[$service_name] = [];

            $answers = json_decode((string) ($row['jawaban_list'] ?? '[]'), true);
            if (!is_array($answers))
                $answers = [];
            $answers = array_values(array_map(
                static fn($v) => trim((string) $v),
                array_filter($answers, static fn($v) => trim((string) $v) !== '')
            ));

            ${$target_map}[$service_name][] = [
                'text' => trim((string) ($row['pertanyaan'] ?? '')),
                'jawaban' => $answers,
                'jawaban_default' => trim((string) ($row['jawaban_default'] ?? '')),
                'answer_mode' => trim((string) ($row['answer_mode'] ?? 'fixed')),
                'answer_type' => trim((string) ($row['answer_type'] ?? 'radio'))
            ];
        }

        $question_cache[$question_key] = [
            'pemeriksaan_mandiri' => build_payload_from_question_map($mandiri_question_map, $batch_key, $resolved_package_key),
            'pemeriksaan_nakes' => build_payload_from_question_map($nakes_question_map, $batch_key, $resolved_package_key)
        ];
    }

    return $question_cache[$question_key];
}

function build_pemeriksaan_mandiri_payload(PDO $db, int $user_id, string $scope_mode, string $package_key): array
{
    $payload_pair = build_pemeriksaan_payload_pair($db, $user_id, $scope_mode, $package_key);
    return $payload_pair['pemeriksaan_mandiri'] ?? [];
}

$jobs = [];
$skipped_ids = [];
$scope_mode = resolve_scope_mode_from_license($lic);

foreach ($rows as $r) {
    $raw = json_decode($r['data'], true) ?: [];

    $nik = clean_nik($raw['NIK'] ?? '');
    $nama = clean_nama($raw['Nama Pasien'] ?? '');
    $jk = parse_jk($raw['Jenis Kelamin'] ?? '');

    $tanggal_lahir_raw = (string)($raw['Tgl.Lahir'] ?? pick_col_value(
        $raw,
        ['tgllahir', 'tgllahirpeserta', 'tanggallahir', 'tanggallahirpeserta', 'tgllahirpasien', 'tanggal_lahir'],
        ['lahir']
    ));
    $tanggal_lahir = normalize_birth_date($tanggal_lahir_raw);
    $operational_age = resolve_operational_age($raw, $tanggal_lahir);
    $age_year = $operational_age['age_year'];
    $package_key = resolve_package_key_from_demography($jk, $age_year);
    $pemeriksaan_payload_pair = build_pemeriksaan_payload_pair(
        $db,
        (int) ($r['user_id'] ?? 0),
        $scope_mode,
        $package_key
    );
    $pemeriksaan_mandiri_payload = $pemeriksaan_payload_pair['pemeriksaan_mandiri'] ?? [];
    $pemeriksaan_nakes_payload = $pemeriksaan_payload_pair['pemeriksaan_nakes'] ?? [];
    $status_pernikahan_raw = pick_col_value(
        $raw,
        ['statuspernikahan', 'statusperkawinan', 'statuskawin', 'statusnikah'],
        ['status', 'nikah']
    );
    $penyandang_disabilitas_raw = pick_col_value(
        $raw,
        ['penyandangdisabilitas', 'statusdisabilitas', 'disabilitas'],
        ['disabil']
    );
    $status_pernikahan = normalize_status_pernikahan($status_pernikahan_raw);
    $penyandang_disabilitas = normalize_disability_status($penyandang_disabilitas_raw);

    if (!is_valid_nik($nik) || !is_valid_nama($nama) || $jk === '' || $tanggal_lahir === '') {
        $skipped_ids[] = (int)$r['job_id'];
        continue;
    }

    $phone = clean_phone($raw['No Telp'] ?? '');

    $jobs[] = [
        'job_id' => (int)$r['job_id'],
        'patient_id' => (int)$r['patient_id'],
        'task_type' => $r['task_type'],
        'data' => [
            'data_pasien' => $raw,
            'nik' => $nik,
            'nama' => $nama,
            'nomor_whatsapp' => $phone,
            'jenis_kelamin' => $jk,
            'tanggal_lahir' => $tanggal_lahir,
            'umur_operasional' => (string) ($operational_age['umur_operasional'] ?? ''),
            'usia_tahun' => $age_year,
            'pekerjaan' => $raw['Pekerjaan'] ?? '',
            'status_pernikahan' => $status_pernikahan,
            'penyandang_disabilitas' => $penyandang_disabilitas,
            'pemeriksaan_mandiri' => $pemeriksaan_mandiri_payload,
            'pemeriksaan_nakes' => $pemeriksaan_nakes_payload,
            'domisili' => [
                'provinsi' => 'Jawa Barat',
                'kabupaten_kota' => 'Kab. Tasikmalaya',
                'kecamatan' => $raw['Kecamatan'] ?? '',
                'kelurahan' => $raw['Kelurahan'] ?? '',
            ],
            'detail_domisili' => trim(
                ($raw['Alamat'] ?? '') .
                (isset($raw['RT']) ? ', RT ' . str_pad((string)$raw['RT'], 3, '0', STR_PAD_LEFT) : '') .
                (isset($raw['RW']) ? ', RW ' . str_pad((string)$raw['RW'], 3, '0', STR_PAD_LEFT) : '')
            ),
        ],
    ];
}

if (!empty($skipped_ids)) {
    $skip_ids_str = implode(',', $skipped_ids);

    try {
        $info = $db->query("\n            SELECT jq.id, jq.patient_id, jq.task_type, jq.user_id, jq.license_key_id, jq.attempt\n            FROM job_queue jq\n            WHERE jq.id IN ($skip_ids_str)\n        ")->fetchAll();

        $ins = $db->prepare("\n            INSERT INTO job_failed_x\n                (user_id, license_key_id, patient_id, task_type,\n                 source_job_id, error_msg, reg_code, attempt, failed_at)\n            VALUES (?, ?, ?, ?, ?, 'DATA_TIDAK_VALID', 'DATA_TIDAK_VALID', ?, NOW())\n        ");

        foreach ($info as $ji) {
            $ins->execute([
                $ji['user_id'],
                $ji['license_key_id'],
                $ji['patient_id'],
                $ji['task_type'],
                $ji['id'],
                (int)$ji['attempt'],
            ]);
        }

        $db->exec("DELETE FROM job_queue WHERE id IN ($skip_ids_str)");
    } catch (Exception $e) {
        $db->exec("\n            UPDATE job_queue SET status='pending', started_at=NULL\n            WHERE id IN ($skip_ids_str)\n        ");
    }
}

if (empty($jobs)) {
    try {
        $s = $db->prepare("
            SELECT COUNT(*)
            FROM job_queue
            WHERE license_key_id = ?
              AND status IN ('pending', 'running')
        ");
        $s->execute([$lic['id']]);
        $remaining = (int) $s->fetchColumn();

        if ($remaining === 0) {
            $db->prepare("UPDATE license_keys SET is_running = 0 WHERE id = ?")
                ->execute([$lic['id']]);
        }
    } catch (Exception $e) {
    }
}

json_response(['ok' => true, 'jobs' => $jobs, 'count' => count($jobs)]);
