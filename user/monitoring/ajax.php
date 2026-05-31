<?php
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/common.php';

if (!isset($db)) {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/session.php';
    $db  = db();
    $uid = (int)($_SESSION['user_id'] ?? 0);
}

if (!isset($uid) || $uid < 1) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$scope_mode = get_scope_mode();

$out = ['stats' => [], 'ts' => date('H:i:s'), 'debug' => [], 'debug_retryable' => []];
$debug_mode = (int)($_GET['debug'] ?? 0) === 1;

$no_retry_codes = monitoring_no_retry_codes();
$placeholders   = implode(',', array_fill(0, count($no_retry_codes), '?'));

try {
    $last_maintenance_ts = (int)($_SESSION['monitor_maintenance_ts'] ?? 0);
    if ($last_maintenance_ts < 1 || (time() - $last_maintenance_ts) >= 600) {
        monitoring_sync_legacy_status($db, $uid);
        monitoring_cleanup_orphan_rows($db, $uid);
        $_SESSION['monitor_maintenance_ts'] = time();
    }

    $last_reclassify_ts = (int)($_SESSION['monitor_reclassify_ts'] ?? 0);
    if ($last_reclassify_ts < 1 || (time() - $last_reclassify_ts) >= 300) {
        monitoring_reclassify_invalid_success_rows($db, $uid);
        $_SESSION['monitor_reclassify_ts'] = time();
    }

    session_write_close();

    $lk_stmt = $db->prepare("
        SELECT id FROM license_keys
        WHERE user_id = ? AND is_active = 1
          AND (
              (? = 'sekolah' AND LOWER(COALESCE(mode, '')) = 'sekolah')
              OR (? = 'umum' AND LOWER(COALESCE(mode, 'umum')) = 'umum')
          )
    ");
    $lk_stmt->execute([$uid, $scope_mode, $scope_mode]);
    $lk_ids = array_column($lk_stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

    if (empty($lk_ids)) {
        $out['stats'] = [
            'pending' => 0,
            'running' => 0,
            'success' => 0,
            'failed' => 0,
            'retryable_count' => 0,
            'daftar_ok' => 0,
            'daftar_all' => 0,
            'layanan_ok' => 0,
            'layanan_all' => 0,
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lk_in = implode(',', $lk_ids);

    $st = $db->prepare("
        SELECT
            COUNT(DISTINCT jq.patient_id) AS pending,
            COUNT(DISTINCT CASE WHEN jq.status='running' THEN jq.patient_id END) AS running
        FROM job_queue jq
        WHERE jq.user_id = ? AND jq.license_key_id IN ($lk_in)
    ");
    $st->execute([$uid]);
    $queue_row = $st->fetch(PDO::FETCH_ASSOC);

    $st = $db->prepare("
        SELECT
            COUNT(DISTINCT js.patient_id) AS success,
            COUNT(DISTINCT CASE WHEN js.task_type='pendaftaran' THEN js.patient_id END) AS daftar_ok,
            COUNT(DISTINCT CASE WHEN js.task_type='pelayanan' THEN js.patient_id END) AS layanan_ok
        FROM job_success js
        WHERE js.user_id = ? AND js.license_key_id IN ($lk_in)
    ");
    $st->execute([$uid]);
    $success_row = $st->fetch(PDO::FETCH_ASSOC);

    $st1 = $db->prepare("
        SELECT COUNT(DISTINCT jf.patient_id)
        FROM job_failed jf
        WHERE jf.user_id = ? AND jf.license_key_id IN ($lk_in)
          AND NOT EXISTS (SELECT 1 FROM job_queue jq2 WHERE jq2.patient_id = jf.patient_id AND jq2.task_type = jf.task_type AND jq2.user_id = ?)
          AND NOT EXISTS (SELECT 1 FROM job_success js2 WHERE js2.patient_id = jf.patient_id AND js2.task_type = jf.task_type AND js2.user_id = ?)
    ");
    $st1->execute([$uid, $uid, $uid]);
    $failed_1 = (int)$st1->fetchColumn();

    $st2 = $db->prepare("
        SELECT COUNT(DISTINCT jfx.patient_id)
        FROM job_failed_x jfx
        WHERE jfx.user_id = ? AND jfx.license_key_id IN ($lk_in)
          AND NOT EXISTS (SELECT 1 FROM job_queue jq2 WHERE jq2.patient_id = jfx.patient_id AND jq2.task_type = jfx.task_type AND jq2.user_id = ?)
          AND NOT EXISTS (SELECT 1 FROM job_success js2 WHERE js2.patient_id = jfx.patient_id AND js2.task_type = jfx.task_type AND js2.user_id = ?)
    ");
    $st2->execute([$uid, $uid, $uid]);
    $failed_2 = (int)$st2->fetchColumn();

    $failed_count = $failed_1 + $failed_2;

    $st = $db->prepare("
        SELECT COUNT(DISTINCT f.patient_id) FROM (
            SELECT jf.patient_id
            FROM job_failed jf
            WHERE jf.user_id = ? AND jf.license_key_id IN ($lk_in)
              AND (jf.reg_code IS NULL OR jf.reg_code = '' OR jf.reg_code NOT IN ($placeholders))
            UNION
            SELECT jfx.patient_id
            FROM job_failed_x jfx
            WHERE jfx.user_id = ? AND jfx.license_key_id IN ($lk_in)
              AND (jfx.reg_code IS NULL OR jfx.reg_code = '' OR jfx.reg_code NOT IN ($placeholders))
        ) f
    ");
    $st->execute(array_merge([$uid], $no_retry_codes, [$uid], $no_retry_codes));
    $retryable_count = (int)$st->fetchColumn();

    if ($debug_mode) {
        $st = $db->prepare("
            SELECT t.src, t.id, t.reg_code, t.error_msg, t.task_type, t.attempt, t.failed_at, t.nik
            FROM (
                SELECT 'aktif' AS src, jf.id, jf.reg_code, jf.error_msg, jf.task_type, jf.attempt, jf.failed_at, pd.nik_index AS nik
                FROM job_failed jf
                LEFT JOIN patients_data pd ON pd.id = jf.patient_id
                WHERE jf.user_id = ?
                  AND pd.ckg_scope = ?
                  AND (jf.reg_code IS NULL OR jf.reg_code = '' OR jf.reg_code NOT IN ($placeholders))
                UNION ALL
                SELECT 'arsip' AS src, jfx.id, jfx.reg_code, jfx.error_msg, jfx.task_type, jfx.attempt, jfx.failed_at, pd2.nik_index AS nik
                FROM job_failed_x jfx
                LEFT JOIN patients_data pd2 ON pd2.id = jfx.patient_id
                WHERE jfx.user_id = ?
                  AND pd2.ckg_scope = ?
                  AND (jfx.reg_code IS NULL OR jfx.reg_code = '' OR jfx.reg_code NOT IN ($placeholders))
            ) t
            ORDER BY t.failed_at DESC
            LIMIT 10
        ");
        $st->execute(array_merge([$uid, $scope_mode], $no_retry_codes, [$uid, $scope_mode], $no_retry_codes));
        $out['debug_retryable'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $out['debug_retryable'] = [];
    }

    $st = $db->prepare("SELECT COUNT(*) AS daftar_all, SUM(daftar_done=1) AS layanan_all FROM patients_data WHERE user_id=? AND ckg_scope=?");
    $st->execute([$uid, $scope_mode]);
    $patient_row = $st->fetch(PDO::FETCH_ASSOC);

    $out['stats'] = [
        'pending'         => (int)($queue_row['pending']       ?? 0),
        'running'         => (int)($queue_row['running']       ?? 0),
        'success'         => (int)($success_row['success']     ?? 0),
        'failed'          => $failed_count,
        'retryable_count' => $retryable_count,
        'daftar_ok'       => (int)($success_row['daftar_ok']   ?? 0),
        'daftar_all'      => (int)($patient_row['daftar_all']  ?? 0),
        'layanan_ok'      => (int)($success_row['layanan_ok']  ?? 0),
        'layanan_all'     => (int)($patient_row['layanan_all'] ?? 0),
    ];
} catch (Exception $e) {
    $out['debug'][] = 'stats: ' . $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
