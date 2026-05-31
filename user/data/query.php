<?php
$headers = [];
$rows = [];
$total_rows = 0;
$total_pages = 1;
$count_all = 0;
$count_sisa = 0;
$count_antrean = 0;
$count_sukses = 0;
$count_gagal = 0;

$allowed_limits = [10, 25, 50, 100];
$limit_raw = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
$per_page = in_array($limit_raw, $allowed_limits, true) ? $limit_raw : 25;
$page = max(1, (int) ($_GET['p'] ?? 1));
$search = trim($_GET['q'] ?? '');
$view_param = $_GET['view'] ?? 'all';
$view = in_array($view_param, ['all', 'sisa', 'antrean', 'sukses', 'gagal'], true) ? $view_param : 'all';
$offset = ($page - 1) * $per_page;
$scope_mode = get_scope_mode();

$usia_options = [];

function normalize_month_id(string $value): string
{
    $map = [
        'januari' => '01',
        'jan' => '01',
        'februari' => '02',
        'feb' => '02',
        'maret' => '03',
        'mar' => '03',
        'april' => '04',
        'apr' => '04',
        'mei' => '05',
        'juni' => '06',
        'jun' => '06',
        'juli' => '07',
        'jul' => '07',
        'agustus' => '08',
        'agu' => '08',
        'agt' => '08',
        'september' => '09',
        'sep' => '09',
        'oktober' => '10',
        'okt' => '10',
        'november' => '11',
        'nov' => '11',
        'desember' => '12',
        'des' => '12',
    ];
    $key = strtolower(trim($value));
    return $map[$key] ?? '';
}

function parse_birth_for_age(mixed $value): ?DateTimeImmutable
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return null;
    }

    $formats = ['Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'Y-m-d H:i:s', 'd-m-Y H:i:s', 'd/m/Y H:i:s'];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }

    if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/u', $raw, $m)) {
        $mm = normalize_month_id($m[2]);
        if ($mm !== '') {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $mm, (int) $m[1]);
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }
    }

    if (is_numeric($raw)) {
        $serial = (float) $raw;
        if ($serial > 15000 && $serial < 100000) {
            $ts = (int) round(($serial - 25569) * 86400);
            if ($ts > 0) {
                return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'Asia/Jakarta'));
            }
        }
    }

    return null;
}

function calc_age_years(mixed $birth_value): ?int
{
    $birth = parse_birth_for_age($birth_value);
    if (!$birth) {
        return null;
    }

    $today = new DateTimeImmutable('today');
    if ($birth > $today) {
        return null;
    }

    return (int) $birth->diff($today)->y;
}

$up_stmt = $db->prepare('
    SELECT p.id, p.file_name, p.detected_fields, p.uploaded_at, COUNT(d.id) AS row_count
    FROM patient_uploads p
    LEFT JOIN patients_data d ON d.upload_id = p.id
    WHERE p.user_id = ? AND p.ckg_scope = ?
    GROUP BY p.id
    ORDER BY p.id DESC
');
$up_stmt->execute([$uid, $scope_mode]);
$all_uploads = $up_stmt->fetchAll();

$selected_upload_id = isset($_GET['upload_id'])
    ? max(0, (int) $_GET['upload_id'])
    : (int) ($all_uploads[0]['id'] ?? 0);
$is_all_upload_mode = $selected_upload_id === 0;
$current_upload = null;

if (!$is_all_upload_mode) {
    foreach ($all_uploads as $u) {
        if ((int) $u['id'] === $selected_upload_id) {
            $current_upload = $u;
            break;
        }
    }
    if (!$current_upload && !empty($all_uploads)) {
        $selected_upload_id = (int) $all_uploads[0]['id'];
        $current_upload = $all_uploads[0];
    }
}

$birth_field = null;
$age_fields = [];

if ($is_all_upload_mode) {
    $header_seen = [];
    foreach ($all_uploads as $upload_item) {
        $header_list = json_decode($upload_item['detected_fields'] ?? '[]', true) ?: [];
        foreach ($header_list as $header_name) {
            $header_name = trim((string) $header_name);
            if ($header_name === '' || isset($header_seen[$header_name]))
                continue;
            $header_seen[$header_name] = true;
            $headers[] = $header_name;
        }
    }
} elseif ($current_upload && $selected_upload_id) {
    $headers = json_decode($current_upload['detected_fields'] ?? '[]', true) ?: [];
}

$can_query_rows = $is_all_upload_mode ? !empty($all_uploads) : ($current_upload && $selected_upload_id);

if ($can_query_rows) {
    $upload_where_prefix = $is_all_upload_mode ? '' : 'd.upload_id=? AND ';
    $upload_where_args = $is_all_upload_mode ? [] : [$selected_upload_id];

    foreach ($headers as $h) {
        $hl = strtolower($h);
        if (str_contains($hl, 'lahir') || str_contains($hl, 'tgl_l') || $hl === 'tanggal_lahir') {
            if ($birth_field === null) {
                $birth_field = $h;
            }
        }
        if (str_contains($hl, 'umur') || str_contains($hl, 'usia')) {
            $age_fields[] = $h;
        }
    }

    $scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope=" . $db->quote($scope_mode);
    $antrean_sub = "SELECT patient_id FROM job_queue WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})";
    $sukses_sub = "SELECT patient_id FROM job_success WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})";
    $gagal_sub = "
        SELECT patient_id FROM job_failed   WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})
        UNION
        SELECT patient_id FROM job_failed_x WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})
    ";

    $exclude_sub = "
        SELECT patient_id FROM job_queue    WHERE user_id={$uid} AND patient_id IS NOT NULL
        UNION
        {$sukses_sub}
        UNION
        {$gagal_sub}
    ";

    try {
        $st = $db->prepare("
            SELECT
                COUNT(*) AS count_all,
                SUM(CASE WHEN q.patient_id IS NULL AND s.patient_id IS NULL AND g.patient_id IS NULL THEN 1 ELSE 0 END) AS count_sisa,
                SUM(CASE WHEN q.patient_id IS NOT NULL THEN 1 ELSE 0 END) AS count_antrean,
                SUM(CASE WHEN q.patient_id IS NULL AND s.patient_id IS NOT NULL THEN 1 ELSE 0 END) AS count_sukses,
                SUM(CASE WHEN q.patient_id IS NULL AND s.patient_id IS NULL AND g.patient_id IS NOT NULL THEN 1 ELSE 0 END) AS count_gagal
            FROM patients_data d
            LEFT JOIN (
                SELECT DISTINCT patient_id FROM job_queue
                WHERE user_id = {$uid} AND patient_id IS NOT NULL
                AND patient_id IN ({$scope_patient_sub})
            ) q ON q.patient_id = d.id
            LEFT JOIN (
                SELECT DISTINCT patient_id FROM job_success
                WHERE user_id = {$uid} AND patient_id IS NOT NULL
                AND patient_id IN ({$scope_patient_sub})
            ) s ON s.patient_id = d.id
            LEFT JOIN (
                SELECT patient_id FROM job_failed
                WHERE user_id = {$uid} AND patient_id IS NOT NULL
                AND patient_id IN ({$scope_patient_sub})
                UNION
                SELECT patient_id FROM job_failed_x
                WHERE user_id = {$uid} AND patient_id IS NOT NULL
                AND patient_id IN ({$scope_patient_sub})
            ) g ON g.patient_id = d.id
            WHERE {$upload_where_prefix} d.user_id = ? AND d.ckg_scope = ?
        ");
        $st->execute(array_merge($upload_where_args, [$uid, $scope_mode]));
        $counts = $st->fetch(PDO::FETCH_ASSOC);
        $count_all     = (int)($counts['count_all'] ?? 0);
        $count_sisa    = (int)($counts['count_sisa'] ?? 0);
        $count_antrean = (int)($counts['count_antrean'] ?? 0);
        $count_sukses  = (int)($counts['count_sukses'] ?? 0);
        $count_gagal   = (int)($counts['count_gagal'] ?? 0);
    } catch (Exception $e) {
        $count_all = $count_sisa = $count_antrean = $count_sukses = $count_gagal = 0;
    }

    $view_where = match ($view) {
        'sisa' => "AND d.id NOT IN ({$exclude_sub})",
        'antrean' => "AND d.id IN ({$antrean_sub})",
        'sukses' => "AND d.id NOT IN ({$antrean_sub}) AND d.id IN ({$sukses_sub})",
        'gagal' => "AND d.id NOT IN ({$antrean_sub}) AND d.id NOT IN ({$sukses_sub}) AND d.id IN ({$gagal_sub})",
        default => ''
    };

    $usia_where = '';

    $search_where = '';
    if ($search !== '') {
        $like = $db->quote('%' . $search . '%');
        $search_where = "AND d.data LIKE {$like}";
    }

    $base_where = "WHERE {$upload_where_prefix} d.user_id=? AND d.ckg_scope=? {$view_where} {$usia_where} {$search_where}";
    $base_args = array_merge($upload_where_args, [$uid, $scope_mode]);
    $debug_err = '';
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM patients_data d {$base_where}");
        $st->execute($base_args);
        $total_rows = (int) $st->fetchColumn();
        $total_pages = $total_rows > 0 ? (int) ceil($total_rows / $per_page) : 1;

        $page = min($page, $total_pages);
        $offset = ($page - 1) * $per_page;

        $st = $db->prepare("
            SELECT d.id, d.data, p.file_name
            FROM patients_data d
            LEFT JOIN patient_uploads p ON p.id = d.upload_id
            {$base_where}
            ORDER BY d.id ASC
            LIMIT {$per_page} OFFSET {$offset}
        ");
        $st->execute($base_args);
        foreach ($st->fetchAll() as $r) {
            $decoded = json_decode($r['data'], true) ?: [];
            if ($birth_field && !empty($age_fields)) {
                $age_now = calc_age_years($decoded[$birth_field] ?? '');
                if ($age_now !== null) {
                    foreach ($age_fields as $age_field) {
                        $decoded[$age_field] = (string) $age_now;
                    }
                }
            }
            $decoded['_id'] = (int) $r['id'];
            if ($is_all_upload_mode)
                $decoded['_file_name'] = (string) ($r['file_name'] ?? '-');
            $rows[] = $decoded;
        }
        if (empty($headers) && !empty($rows)) {
            $header_seen = [];
            foreach ($rows as $row_item) {
                foreach ($row_item as $header_name => $header_value) {
                    if ($header_name === '_id' || $header_name === '_file_name')
                        continue;
                    $header_name = trim((string) $header_name);
                    if ($header_name === '' || isset($header_seen[$header_name]))
                        continue;
                    $header_seen[$header_name] = true;
                    $headers[] = $header_name;
                }
            }
        }
    } catch (Exception $e) {
        $debug_err = $e->getMessage();
        $rows = [];
        $total_rows = 0;
        $total_pages = 1;
    }
}

$base_url = 'data.php?scope=' . urlencode($scope_mode) . '&upload_id=' . $selected_upload_id
    . '&q=' . urlencode($search)
    . '&limit=' . $per_page
    . '&view=' . $view;
