<?php
ob_start();
define('PROFILER_MODE', true);

$t = [];
$t['start'] = microtime(true);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/session.php';
start_session_for('operator');
$t['config_session'] = microtime(true);

require_once __DIR__ . '/includes/functions.php';
$t['functions'] = microtime(true);

require_once __DIR__ . '/includes/header.php';
$t['header_php'] = microtime(true);

ob_end_clean();
ob_start();

$db = db();
$uid = (int)($_SESSION['user_id'] ?? 0);

if (php_sapi_name() === 'cli') {
    $uid = 1; // Default to 1 for CLI testing
}

if (!$uid) {
    ob_end_clean();
    die("ERROR: Tidak ada session user_id.\nLogin dulu ke /user/jobs.php, lalu buka URL ini lagi.\n(Jika Anda sudah login, pastikan Anda login sebagai user operator, bukan admin.)\n");
}

$scope_mode = get_scope_mode();
$t['init_vars'] = microtime(true);

ensure_jobs_performance_indexes($db);
$t['ensure_indexes'] = microtime(true);

require_once __DIR__ . '/user/jobs/helpers.php';
require_once __DIR__ . '/user/jobs/actions.php';
$t['require_helpers_actions'] = microtime(true);

$pcs = [];
$s = $db->prepare("SELECT * FROM license_keys WHERE user_id=? AND is_active=1 AND (('umum'='sekolah' AND LOWER(COALESCE(mode,''))='sekolah') OR ('umum'='umum' AND LOWER(COALESCE(mode,'umum'))='umum')) ORDER BY task_type, pc_label");
$s->execute([$uid]);
$pcs = $s->fetchAll();
$t['fetch_pcs'] = microtime(true);

$pc_pend = $pc_run = $pc_fail = $pc_retry = $pc_fail_x = [];
$s = $db->prepare("SELECT jq.license_key_id, SUM(jq.status='pending') p, SUM(jq.status='running') r FROM job_queue jq WHERE jq.user_id=? GROUP BY jq.license_key_id");
$s->execute([$uid]);
foreach ($s->fetchAll() as $row) {
    $pc_pend[(int)$row['license_key_id']] = (int)$row['p'];
    $pc_run[(int)$row['license_key_id']] = (int)$row['r'];
}
$t['queue_counts'] = microtime(true);

$s = $db->prepare("SELECT jf.license_key_id, COUNT(*) c FROM job_failed jf WHERE jf.user_id=? GROUP BY jf.license_key_id");
$s->execute([$uid]);
foreach ($s->fetchAll() as $row) $pc_fail[(int)$row['license_key_id']] = (int)$row['c'];
$t['fail_counts'] = microtime(true);

$s = $db->prepare("SELECT jf.license_key_id, COUNT(*) c FROM job_failed jf WHERE jf.user_id=? AND COALESCE(is_no_retry,0)=0 AND (reg_code IS NULL OR reg_code='' OR UPPER(reg_code) NOT IN ('SISTEM_MENOLAK','DUKCAPIL_UPDATE','DUKCAPIL','DATA_TIDAK_DITEMUKAN','VALIDASI_TIDAK_VALID','VALIDASI_PESERTA_WALI_TIDAK_VALID','SUDAH_TERDAFTAR','SUDAH_MENERIMA_LAYANAN','BATAS_KIRIM_RAPOR_HABIS','NOT_IN_LIST')) AND UPPER(COALESCE(error_msg,'')) NOT LIKE '%SUDAH MENERIMA LAYANAN%' GROUP BY jf.license_key_id");
$s->execute([$uid]);
foreach ($s->fetchAll() as $row) $pc_retry[(int)$row['license_key_id']] = (int)$row['c'];
$t['retry_counts'] = microtime(true);

$s = $db->prepare("SELECT jfx.license_key_id, COUNT(*) c FROM job_failed_x jfx WHERE jfx.user_id=? GROUP BY jfx.license_key_id");
$s->execute([$uid]);
foreach ($s->fetchAll() as $row) $pc_fail_x[(int)$row['license_key_id']] = (int)$row['c'];
$t['fail_x_counts'] = microtime(true);

$lk_ids_list = array_map(fn($pc) => (int)$pc['id'], $pcs);
$lk_in_global = $lk_ids_list ? implode(',', $lk_ids_list) : '0';

$g_pasien_st = $db->prepare("SELECT COUNT(*) FROM patients_data WHERE user_id=? AND ckg_scope=?");
$g_pasien_st->execute([$uid, $scope_mode]);
$g_pasien = (int)$g_pasien_st->fetchColumn();
$t['count_patients'] = microtime(true);

$g_success_st = $db->prepare("SELECT COUNT(DISTINCT js.patient_id) AS g_success, COUNT(DISTINCT CASE WHEN js.task_type='pendaftaran' THEN js.patient_id END) AS g_daftar_ok, COUNT(DISTINCT CASE WHEN js.task_type='pelayanan' THEN js.patient_id END) AS g_layanan_ok FROM job_success js WHERE js.user_id=? AND js.license_key_id IN ($lk_in_global)");
$g_success_st->execute([$uid]);
$gs = $g_success_st->fetch();
$t['global_success'] = microtime(true);

$g_fail_st = $db->prepare("SELECT COUNT(DISTINCT jf.patient_id) FROM job_failed jf WHERE jf.user_id=? AND jf.license_key_id IN ($lk_in_global) AND NOT EXISTS (SELECT 1 FROM job_queue jq WHERE jq.patient_id=jf.patient_id AND jq.user_id=?) AND NOT EXISTS (SELECT 1 FROM job_success js WHERE js.patient_id=jf.patient_id AND js.user_id=?)");
$g_fail_st->execute([$uid, $uid, $uid]);
$g_failed = (int)$g_fail_st->fetchColumn();
$t['global_failed'] = microtime(true);

sync_done_flags($db, $uid, $scope_mode);
$t['sync_done_flags'] = microtime(true);

$avail_daftar = count_avail($db, $uid, 'pendaftaran', 0, $scope_mode);
$t['count_avail_daftar'] = microtime(true);

$avail_layan = count_avail($db, $uid, 'pelayanan', 0, $scope_mode);
$t['count_avail_layan'] = microtime(true);

if ($lk_ids_list) {
    $lk_in = implode(',', $lk_ids_list);
    $sq = $db->prepare("(SELECT jq.id, jq.license_key_id lk_id, jq.status, pd.data, NULL em FROM job_queue jq INNER JOIN patients_data pd ON pd.id=jq.patient_id WHERE jq.user_id=? AND jq.license_key_id IN ($lk_in) AND jq.status IN ('running','pending') ORDER BY (jq.status='running') DESC, jq.id ASC LIMIT 400) UNION ALL (SELECT jf.id, jf.license_key_id, 'failed', pd.data, jf.error_msg FROM job_failed jf INNER JOIN patients_data pd ON pd.id=jf.patient_id WHERE jf.user_id=? AND jf.license_key_id IN ($lk_in) AND COALESCE(jf.is_no_retry,0)=0 LIMIT 200)");
    $sq->execute([$uid, $uid]);
    $pc_list_rows = $sq->fetchAll();
}
$t['pc_lists_union'] = microtime(true);

$t['end'] = microtime(true);
ob_end_clean();

header('Content-Type: text/plain; charset=utf-8');
$prev = $t['start'];
echo "=== JOBS.PHP FULL TIMING PROFILER ===\n";
echo "User ID: $uid | Scope: $scope_mode\n\n";

$warnings = [];
foreach ($t as $key => $ts) {
    if ($key === 'start') {
        $prev = $ts;
        continue;
    }
    $ms = round(($ts - $prev) * 1000, 1);
    $total_ms = round(($ts - $t['start']) * 1000, 1);
    $flag = $ms > 500 ? ' <<< LAMBAT!' : ($ms > 100 ? ' << perhatian' : '');
    if ($ms > 500) $warnings[] = "$key: {$ms}ms";
    echo sprintf("%-35s %7.1fms  (kumulatif: %7.1fms)%s\n", $key, $ms, $total_ms, $flag);
    $prev = $ts;
}

$total = round(($t['end'] - $t['start']) * 1000, 1);
echo "\n=== TOTAL: {$total}ms ===\n";
if ($warnings) {
    echo "\n[!] BOTTLENECK DITEMUKAN:\n";
    foreach ($warnings as $w) echo "    - $w\n";
}
echo "\nData: uid={$uid}, pasien={$g_pasien}, success=" . ($gs['g_success'] ?? 0);
echo ", failed={$g_failed}, avail_daftar={$avail_daftar}, avail_layan={$avail_layan}\n";
echo "PCs: " . count($pcs) . " | LK IDs: {$lk_in_global}\n";
echo "PC List rows fetched: " . count($pc_list_rows ?? []) . "\n";
