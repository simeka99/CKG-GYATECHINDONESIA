<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

start_session_for('operator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, silakan login ulang']);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE)
    session_write_close();

$upload_id = (int)($_POST['upload_id'] ?? 0);
$last_id = (int)($_POST['last_id'] ?? 0);
$batch_size = 1;
$scope_input = strtolower(trim((string)($_POST['scope'] ?? 'gagal')));
$scope = in_array($scope_input, ['sisa', 'gagal'], true) ? $scope_input : 'gagal';
$batch_size = 1;
$shard_total = (int)($_POST['shard_total'] ?? 1);
$shard_index = (int)($_POST['shard_index'] ?? 0);
$shard_total = max(1, min(6, $shard_total));
$shard_index = max(0, min($shard_total - 1, $shard_index));

if ($upload_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Upload tidak valid']);
    exit;
}

$api_url = rtrim((string)get_setting('bpjs_api_url'), '/');
$api_key = (string)get_setting('bpjs_api_key');
if ($api_url === '' || $api_key === '') {
    echo json_encode(['ok' => false, 'message' => 'Konfigurasi BPJS belum diatur']);
    exit;
}

function norm_key(string $key): string
{
    return strtolower(trim($key));
}

function pick_first_key(array $data, callable $matcher): ?string
{
    foreach (array_keys($data) as $key) {
        if ($matcher(norm_key((string)$key))) return (string)$key;
    }
    return null;
}

function normalize_bpjs_date(string $val): string
{
    $val = trim($val);
    if ($val === '') return $val;

    if (preg_match('/^(\d{2})[-\/](\d{2})[-\/](\d{4})$/', $val, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/', $val, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return $val;
}

function is_phone_field_key(string $key): bool
{
    return str_contains($key, 'hp') || str_contains($key, 'telp') || str_contains($key, 'telepon') || str_contains($key, 'phone') || str_contains($key, 'no_hp');
}

function pick_phone_fallback_value(array $data_arr): string
{
    foreach ($data_arr as $k => $v) {
        if (!is_phone_field_key(norm_key((string) $k))) continue;
        $candidate = trim((string) $v);
        if ($candidate === '') continue;
        if (preg_replace('/\D+/', '', $candidate) === '') continue;
        return $candidate;
    }
    return '';
}

function fetch_bpjs_by_nik(string $api_url, string $api_key, string $nik): array
{
    $target = $api_url . '/' . rawurlencode($nik);
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $api_key,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if (!$response || $curl_err) {
        return ['ok' => false, 'message' => 'Koneksi BPJS gagal'];
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json) || empty($json['success']) || !is_array($json['data'] ?? null)) {
        return ['ok' => false, 'message' => (string)($json['message'] ?? 'Data BPJS tidak ditemukan')];
    }

    return ['ok' => true, 'data' => $json['data']];
}

$db = db();
$bpjs_sync_setting = get_user_bpjs_sync_settings($uid);
$sync_fields_map = array_flip($bpjs_sync_setting['sync_fields'] ?? []);
$sync_nik = isset($sync_fields_map['nik']);
$sync_nama = isset($sync_fields_map['nama']);
$sync_tgl_lahir = isset($sync_fields_map['tgl_lahir']);
$sync_jenis_kelamin = isset($sync_fields_map['jenis_kelamin']);
$sync_no_hp = isset($sync_fields_map['no_hp']);
$sync_phone_fallback = !empty($bpjs_sync_setting['phone_auto_fallback']);
$sync_phone_fallback_number = trim((string) ($bpjs_sync_setting['phone_fallback_number'] ?? ''));
$sync_no_hp_by_fallback_only = !$sync_no_hp && $sync_phone_fallback_number !== '';
$antrean_sub = "SELECT patient_id FROM job_queue WHERE user_id={$uid} AND patient_id IS NOT NULL";
$sukses_sub = "SELECT patient_id FROM job_success WHERE user_id={$uid} AND patient_id IS NOT NULL";
$gagal_sub = "
    SELECT patient_id FROM job_failed WHERE user_id={$uid} AND patient_id IS NOT NULL
    UNION
    SELECT patient_id FROM job_failed_x WHERE user_id={$uid} AND patient_id IS NOT NULL
";
$exclude_sub = "
    SELECT patient_id FROM job_queue    WHERE user_id={$uid} AND patient_id IS NOT NULL
    UNION
    {$sukses_sub}
    UNION
    {$gagal_sub}
";
$scope_where = $scope === 'sisa'
    ? "AND id NOT IN ({$exclude_sub})"
    : "AND id NOT IN ({$antrean_sub}) AND id NOT IN ({$sukses_sub}) AND id IN ({$gagal_sub})";

$sql = "
    SELECT id, data
    FROM patients_data
    WHERE upload_id = ?
      AND user_id = ?
      AND id > ?
      AND MOD(id, ?) = ?
      {$scope_where}
    ORDER BY id ASC
    LIMIT {$batch_size}
";

$st = $db->prepare($sql);
$st->execute([$upload_id, $uid, $last_id, $shard_total, $shard_index]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$synced = 0;
$failed = 0;
$max_id = $last_id;
$errors = [];

foreach ($rows as $row) {
    $processed++;
    $pid = (int)($row['id'] ?? 0);
    if ($pid > $max_id) $max_id = $pid;

    $data_arr = json_decode((string)($row['data'] ?? ''), true);
    if (!is_array($data_arr)) {
        $failed++;
        $errors[] = "ID {$pid}: format data tidak valid";
        continue;
    }

    $nik_key = pick_first_key($data_arr, fn($k) => str_contains($k, 'nik'));
    $nik = trim((string)($nik_key ? ($data_arr[$nik_key] ?? '') : ''));
    if ($nik === '') {
        $failed++;
        $errors[] = "ID {$pid}: NIK kosong";
        continue;
    }

    $bpjs = fetch_bpjs_by_nik($api_url, $api_key, $nik);
    if (!$bpjs['ok']) {
        $failed++;
        $errors[] = "ID {$pid}: " . ($bpjs['message'] ?? 'sync gagal');
        continue;
    }

    $bp = $bpjs['data'];
    $bpjs_nik = trim((string)($bp['nik'] ?? ''));
    $bpjs_nama = trim((string)($bp['nama'] ?? ''));
    $bpjs_tgl_lahir = trim((string)($bp['tglLahir'] ?? ''));
    $bpjs_jenis_kelamin = trim((string)($bp['jenisKelamin'] ?? ''));
    $bpjs_no_hp = trim((string)($bp['noHP'] ?? ''));

    if ($sync_no_hp && $bpjs_no_hp === '' && $sync_phone_fallback)
            $bpjs_no_hp = pick_phone_fallback_value($data_arr);
    if ($sync_no_hp_by_fallback_only)
            $bpjs_no_hp = $sync_phone_fallback_number;

    $has_target_field = false;
    foreach ($data_arr as $k => $v) {
        $kn = norm_key((string)$k);

        if ($sync_nik && str_contains($kn, 'nik') && $bpjs_nik !== '') {
            $data_arr[$k] = $bpjs_nik;
            $has_target_field = true;
            continue;
        }
        if ($sync_nama && str_contains($kn, 'nama') && $bpjs_nama !== '') {
            $data_arr[$k] = $bpjs_nama;
            $has_target_field = true;
            continue;
        }
        if ($sync_tgl_lahir && (str_contains($kn, 'lahir') || str_contains($kn, 'tanggal')) && $bpjs_tgl_lahir !== '') {
            $data_arr[$k] = normalize_bpjs_date($bpjs_tgl_lahir);
            $has_target_field = true;
            continue;
        }
        if ($sync_jenis_kelamin && (str_contains($kn, 'kelamin') || str_contains($kn, 'jenis')) && $bpjs_jenis_kelamin !== '') {
            $data_arr[$k] = $bpjs_jenis_kelamin;
            $has_target_field = true;
            continue;
        }
        if (($sync_no_hp || $sync_no_hp_by_fallback_only) && is_phone_field_key($kn) && $bpjs_no_hp !== '') {
            $data_arr[$k] = $bpjs_no_hp;
            $has_target_field = true;
            continue;
        }
    }

    if (!$has_target_field) {
        $failed++;
        $errors[] = "ID {$pid}: tidak ada field yang bisa diisi dari BPJS";
        continue;
    }

    if ($db->inTransaction())
        $db->rollBack();

    $db->beginTransaction();
    try {
        $json_new = json_encode($data_arr, JSON_UNESCAPED_UNICODE);
        $db->prepare('UPDATE patients_data SET data=? WHERE id=? AND user_id=?')
            ->execute([$json_new, $pid, $uid]);

        $db->prepare('DELETE FROM job_failed WHERE user_id=? AND patient_id=?')
            ->execute([$uid, $pid]);
        $db->prepare('DELETE FROM job_failed_x WHERE user_id=? AND patient_id=?')
            ->execute([$uid, $pid]);

        $db->prepare("
            UPDATE patients_data
            SET status='pending',
                error_message=NULL,
                processed_at=NULL,
                job_id=NULL,
                retry_count=0
            WHERE id=? AND user_id=?
        ")->execute([$pid, $uid]);

        $db->commit();
        $synced++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $failed++;
        $errors[] = "ID {$pid}: simpan gagal";
    }
}

$done = count($rows) < 1;
$unresolved = $done ? 0 : -1;

echo json_encode([
    'ok' => true,
    'processed' => $processed,
    'synced' => $synced,
    'failed' => $failed,
    'last_id' => $max_id,
    'done' => $done,
    'unresolved' => $unresolved,
    'scope' => $scope,
    'shard_total' => $shard_total,
    'shard_index' => $shard_index,
    'errors' => array_slice($errors, 0, 5),
], JSON_UNESCAPED_UNICODE);
