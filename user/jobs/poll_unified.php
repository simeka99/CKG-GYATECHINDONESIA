<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        if (function_exists('system_log_error')) {
            system_log_error("Fatal Error: {$e['message']} di {$e['file']}:{$e['line']}", 'Polling API');
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'   => false,
            'msg'  => $e['message'],
            'file' => basename($e['file']),
            'line' => $e['line'],
        ]);
    }
});

try {
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/helpers.php';
} catch (Throwable $e) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'load_error: ' . $e->getMessage()]);
    exit;
}

start_session_for('operator');

ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Accel-Buffering: no');

$db  = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$scope_mode = get_scope_mode();
session_write_close(); // Prevent session locking during heavy polling

if (!$uid) {
    echo json_encode(['ok' => false, 'msg' => 'not_logged_in']);
    exit;
}

if (!is_access_valid($uid)) {
    echo json_encode(['ok' => false, 'msg' => 'access_expired']);
    exit;
}

// $scope_mode is already fetched above

$scope_q = $db->quote($scope_mode);
$scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope={$scope_q}";
$scope_mode_sql = $scope_mode === 'sekolah'
    ? "LOWER(COALESCE(lk.mode,''))='sekolah'"
    : "LOWER(COALESCE(lk.mode,'umum'))='umum'";

$with_avail = (int)($_GET['with_avail'] ?? 0) === 1;
$now = time();

try {
    $s = $db->prepare("
        SELECT lk.*
        FROM license_keys lk
        WHERE lk.user_id = ?
          AND lk.is_active = 1
          AND {$scope_mode_sql}
        ORDER BY lk.task_type, lk.pc_label
    ");
    $s->execute([$uid]);
    $all_pcs = $s->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_pcs)) {
        echo json_encode(['ok' => true, 'global' => null, 'pcs' => []]);
        exit;
    }

    $lk_ids = array_map(function ($pc) {
        return (int)$pc['id'];
    }, $all_pcs);
    $lk_ids_in = implode(',', $lk_ids);

    $s = $db->prepare("
        SELECT
            jq.license_key_id,
            SUM(jq.status='pending') AS pending,
            SUM(jq.status='running') AS running
        FROM job_queue jq
        WHERE jq.user_id = ?
          AND jq.license_key_id IN ({$lk_ids_in})
        GROUP BY jq.license_key_id
    ");
    $s->execute([$uid]);
    $queue_stats = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $queue_stats[(int)$row['license_key_id']] = $row;

    $s = $db->prepare("
        SELECT jf.license_key_id, COUNT(*) AS total_failed
        FROM job_failed jf
        WHERE jf.user_id = ?
          AND jf.license_key_id IN ({$lk_ids_in})
        GROUP BY jf.license_key_id
    ");
    $s->execute([$uid]);
    $fail_stats = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $fail_stats[(int)$row['license_key_id']] = (int)$row['total_failed'];

    $s = $db->prepare("
        SELECT jf.license_key_id, COUNT(*) AS retryable
        FROM job_failed jf
        WHERE jf.user_id = ?
          AND jf.license_key_id IN ({$lk_ids_in})
          AND COALESCE(jf.is_no_retry, 0) = 0
          AND (
                jf.reg_code IS NULL
             OR jf.reg_code = ''
             OR UPPER(jf.reg_code) NOT IN (
                'SISTEM_MENOLAK','DUKCAPIL_UPDATE','DUKCAPIL',
                'DATA_TIDAK_DITEMUKAN','VALIDASI_TIDAK_VALID',
                'VALIDASI_PESERTA_WALI_TIDAK_VALID','SUDAH_TERDAFTAR',
                'SUDAH_MENERIMA_LAYANAN','BATAS_KIRIM_RAPOR_HABIS','NOT_IN_LIST'
             )
          )
          AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%SUDAH MENERIMA LAYANAN%'
          AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%SUDAH TERDAFTAR%'
          AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%VALIDASI TIDAK VALID%'
          AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%VALIDASI_PESERTA_WALI_TIDAK_VALID%'
          AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%BATAS KIRIM RAPOR HABIS%'
          AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
        GROUP BY jf.license_key_id
    ");
    $s->execute([$uid]);
    $retry_stats = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $retry_stats[(int)$row['license_key_id']] = (int)$row['retryable'];

    $avail_cache = [];
    if ($with_avail) {
        $task_types_seen = [];
        foreach ($all_pcs as $pc) {
            $tt = $pc['task_type'];
            if (!isset($task_types_seen[$tt])) {
                $task_types_seen[$tt] = true;
                $avail_cache[$tt] = count_avail($db, $uid, $tt, 0, $scope_mode);
            }
        }
    }
    $worker_log_by_lk = [];
    try {
        $flag_path = sys_get_temp_dir() . '/rmik_wrl_table_ok';
        if (!file_exists($flag_path)) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS ckg_worker_runtime_log (
                    license_key_id INT(11) NOT NULL PRIMARY KEY,
                    worker_log_line TEXT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_worker_runtime_updated_at (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            @file_put_contents($flag_path, '1');
        }
        $worker_stmt = $db->prepare("
            SELECT license_key_id, worker_log_line, updated_at
            FROM ckg_worker_runtime_log
            WHERE license_key_id IN ({$lk_ids_in})
        ");
        $worker_stmt->execute();
        foreach ($worker_stmt->fetchAll(PDO::FETCH_ASSOC) as $worker_row) {
            $line_value = trim((string)($worker_row['worker_log_line'] ?? ''));
            if (stripos($line_value, 'heartbeat') !== false)
                $line_value = '';
            $worker_log_by_lk[(int)$worker_row['license_key_id']] = [
                'line' => $line_value,
                'updated_at' => (string)($worker_row['updated_at'] ?? '')
            ];
        }
    } catch (Exception $e) {
    }

    $items_by_lk = [];
    $s = $db->prepare("
        SELECT * FROM (
        (
            SELECT jq.id AS id,
                   jq.license_key_id AS lk_id,
                   jq.status AS st,
                   pd.data AS data,
                   NULL AS em,
                   0 AS grp,
                   jq.id AS ord
            FROM job_queue jq
            INNER JOIN patients_data pd ON pd.id = jq.patient_id
            WHERE jq.user_id = ?
              AND jq.license_key_id IN ({$lk_ids_in})
              AND jq.status IN ('running','pending')
            ORDER BY (jq.status = 'running') DESC, jq.id ASC
            LIMIT 400
        )
        UNION ALL
        (

            SELECT jf.id AS id,
                   jf.license_key_id AS lk_id,
                   'failed' AS st,
                   pd.data AS data,
                   jf.error_msg AS em,
                   1 AS grp,
                   jf.id AS ord
            FROM job_failed jf
            INNER JOIN patients_data pd ON pd.id = jf.patient_id
            WHERE jf.user_id = ?
              AND jf.license_key_id IN ({$lk_ids_in})
              AND COALESCE(jf.is_no_retry, 0) = 0
              AND (
                    jf.reg_code IS NULL
                 OR jf.reg_code = ''
                 OR UPPER(jf.reg_code) NOT IN (
                    'SISTEM_MENOLAK','DUKCAPIL_UPDATE','DUKCAPIL',
                    'DATA_TIDAK_DITEMUKAN','VALIDASI_TIDAK_VALID',
                    'VALIDASI_PESERTA_WALI_TIDAK_VALID','SUDAH_TERDAFTAR',
                    'SUDAH_MENERIMA_LAYANAN','BATAS_KIRIM_RAPOR_HABIS','NOT_IN_LIST'
                 )
              )
              AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%SUDAH MENERIMA LAYANAN%'
              AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%SUDAH TERDAFTAR%'
              AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%VALIDASI TIDAK VALID%'
              AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%VALIDASI_PESERTA_WALI_TIDAK_VALID%'
              AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%BATAS KIRIM RAPOR HABIS%'
              AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
              AND NOT EXISTS (SELECT 1 FROM job_success js WHERE js.patient_id = jf.patient_id AND js.task_type = jf.task_type AND js.user_id = ?)
            ORDER BY jf.id ASC
            LIMIT 400
        ) t
        ORDER BY lk_id ASC, grp ASC, (st = 'running') DESC, ord ASC
    ");
    $s->execute([$uid, $uid, $uid]);
    $all_items = $s->fetchAll(PDO::FETCH_ASSOC);

    $item_counts = [];
    foreach ($all_items as $r) {
        $lk_id = (int)$r['lk_id'];
        if (!isset($item_counts[$lk_id]))
            $item_counts[$lk_id] = 0;
        $item_counts[$lk_id]++;
        if ($item_counts[$lk_id] > 200)
            continue;
        $p = pd($r['data'] ?? '');
        $items_by_lk[$lk_id][] = [
            'id'   => (int)$r['id'],
            'st'   => $r['st'],
            'nik'  => format_nik((string)$p['nik']),
            'raw_nik' => (string)$p['nik'],
            'nama' => $p['nama'],
            'em'   => $r['em'] ?? '',
        ];
    }

    $done_by_lk = [];
    $count_needed = [];
    foreach ($all_pcs as $pc) {
        $lk_id = (int)$pc['id'];
        $batch_total = (int)($pc['batch_total'] ?? 0);
        $pend = (int)($queue_stats[$lk_id]['pending'] ?? 0);
        $run  = (int)($queue_stats[$lk_id]['running'] ?? 0);
        $fail = $fail_stats[$lk_id] ?? 0;

        if ($batch_total > 0) {
            $done_by_lk[$lk_id] = max(0, $batch_total - $pend - $run - $fail);
        } else {
            $started_at = $pc['started_at'] ?? null;
            if (!$started_at || $started_at === '0000-00-00 00:00:00')
                $started_at = date('Y-m-d H:i:s', strtotime('-1 day'));
            $count_needed[$lk_id] = $started_at;
        }
    }

    if ($count_needed) {
        $by_started = [];
        foreach ($count_needed as $lk_id => $started_at)
            $by_started[$started_at][] = $lk_id;

        foreach ($by_started as $started_at => $lk_ids_batch) {
            $in_str = implode(',', $lk_ids_batch);
            $s2 = $db->prepare("
                SELECT license_key_id, COUNT(*) AS cnt
                FROM job_success
                WHERE user_id = ? AND license_key_id IN ($in_str) AND finished_at >= ?
                GROUP BY license_key_id
            ");
            $s2->execute([$uid, $started_at]);
            foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r)
                $done_by_lk[(int)$r['license_key_id']] = (int)$r['cnt'];
        }

        foreach ($count_needed as $lk_id => $st)
            $done_by_lk[$lk_id] = $done_by_lk[$lk_id] ?? 0;
    }

    foreach ($all_pcs as $pc) {
        $lk_id = (int)$pc['id'];
        if ($pc['sched_enabled'] || $pc['retry_auto'])
            check_schedule($db, $uid, $lk_id, $pc, $scope_patient_sub);
    }

    $pc_results = [];
    foreach ($all_pcs as $pc) {
        $lk_id = (int)$pc['id'];
        $pend = (int)($queue_stats[$lk_id]['pending'] ?? 0);
        $run  = (int)($queue_stats[$lk_id]['running'] ?? 0);
        $fail = $fail_stats[$lk_id] ?? 0;
        $retryable = $retry_stats[$lk_id] ?? 0;
        $online = !empty($pc['last_seen']) && ($now - strtotime($pc['last_seen'])) < 60;
        $avail_val = $with_avail ? ($avail_cache[$pc['task_type']] ?? null) : null;
        $worker_row = $worker_log_by_lk[$lk_id] ?? ['line' => '', 'updated_at' => ''];

        $pc_results[] = [
            'lk_id'           => $lk_id,
            'ok'              => true,
            'online'          => $online,
            'is_running'      => (int)$pc['is_running'],
            'pending'         => $pend,
            'running'         => $run,
            'done'            => $done_by_lk[$lk_id] ?? 0,
            'batch_total'     => (int)($pc['batch_total'] ?? 0),
            'failed'          => $fail,
            'retryable_failed' => $retryable,
            'can_retry'       => $retryable > 0,
            'avail'           => $avail_val,
            'items'           => $items_by_lk[$lk_id] ?? [],
            'server_time'     => date('H:i:s'),
            'sched_enabled'   => (int)($pc['sched_enabled'] ?? 0),
            'sched_start'     => $pc['sched_start'] ? substr($pc['sched_start'], 0, 5) : '',
            'sched_stop_on'   => (int)($pc['sched_stop_on'] ?? 0),
            'sched_stop'      => $pc['sched_stop'] ? substr($pc['sched_stop'], 0, 5) : '',
            'retry_auto'      => (int)($pc['retry_auto'] ?? 0),
            'retry_interval'  => (int)($pc['retry_interval'] ?? 300),
            'retry_last'      => $pc['retry_last'] ?? '',
            'worker_log_line' => $worker_row['line'],
            'worker_log_at'   => $worker_row['updated_at'],
        ];
    }

    usort($pc_results, function ($a, $b) {
        $a_running = ($a['running'] > 0 || $a['is_running'] === 1) ? 1 : 0;
        $b_running = ($b['running'] > 0 || $b['is_running'] === 1) ? 1 : 0;
        if ($a_running !== $b_running) {
            return $b_running - $a_running;
        }

        $a_online = $a['online'] ? 1 : 0;
        $b_online = $b['online'] ? 1 : 0;
        if ($a_online !== $b_online) {
            return $b_online - $a_online;
        }

        return 0;
    });

    $g_pending = 0;
    $g_running = 0;
    foreach ($queue_stats as $qs) {
        $g_pending += (int)($qs['pending'] ?? 0);
        $g_running += (int)($qs['running'] ?? 0);
    }

    $s = $db->prepare("
        SELECT COUNT(DISTINCT js.patient_id)
        FROM job_success js
        WHERE js.user_id = ?
          AND js.license_key_id IN ({$lk_ids_in})
          AND NOT EXISTS (
              SELECT 1 FROM job_queue jq
              WHERE jq.patient_id = js.patient_id AND jq.user_id = ?
          )
    ");
    $s->execute([$uid, $uid]);
    $g_success = (int)$s->fetchColumn();

    $s = $db->prepare("
        SELECT COUNT(DISTINCT jf.patient_id)
        FROM job_failed jf
        WHERE jf.user_id = ?
          AND jf.license_key_id IN ({$lk_ids_in})
          AND NOT EXISTS (
              SELECT 1 FROM job_queue jq
              WHERE jq.patient_id = jf.patient_id AND jq.task_type = jf.task_type AND jq.user_id = ?
          )
          AND NOT EXISTS (
              SELECT 1 FROM job_success js
              WHERE js.patient_id = jf.patient_id AND js.task_type = jf.task_type AND js.user_id = ?
          )
    ");
    $s->execute([$uid, $uid, $uid]);
    $g_failed = (int)$s->fetchColumn();

    $s = $db->prepare("
        SELECT COUNT(DISTINCT jfx.patient_id)
        FROM job_failed_x jfx
        WHERE jfx.user_id = ?
          AND jfx.license_key_id IN ({$lk_ids_in})
          AND NOT EXISTS (
              SELECT 1 FROM job_queue jq
              WHERE jq.patient_id = jfx.patient_id AND jq.task_type = jfx.task_type AND jq.user_id = ?
          )
          AND NOT EXISTS (
              SELECT 1 FROM job_success js
              WHERE js.patient_id = jfx.patient_id AND js.task_type = jfx.task_type AND js.user_id = ?
          )
    ");
    $s->execute([$uid, $uid, $uid]);
    $g_failed_x = (int)$s->fetchColumn();

    $s = $db->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN js.task_type='pendaftaran' THEN js.patient_id END) AS daftar_ok,
            COUNT(DISTINCT CASE WHEN js.task_type='pelayanan' THEN js.patient_id END) AS layanan_ok,
            (SELECT COUNT(*) FROM patients_data pdx WHERE pdx.user_id=? AND pdx.ckg_scope=?) AS total
        FROM job_success js
        INNER JOIN license_keys lk ON lk.id = js.license_key_id
        WHERE js.user_id = ? AND (
            (? = 'sekolah' AND LOWER(COALESCE(lk.mode, '')) = 'sekolah')
            OR (? = 'umum' AND LOWER(COALESCE(lk.mode, 'umum')) = 'umum')
        )
    ");
    $s->execute([$uid, $scope_mode, $uid, $scope_mode, $scope_mode]);
    $pd = $s->fetch();

    $any_running  = false;
    $can_start    = false;
    $can_stop     = false;
    $can_retry    = false;
    $can_clear    = false;
    $start_count  = 0;
    $retry_count  = 0;
    $clear_count  = 0;
    $pending_jobs = 0;
    $running_jobs = 0;

    foreach ($pc_results as $pcr) {
        $is_running = $pcr['is_running'] === 1;
        $p = $pcr['pending'];
        $r = $pcr['running'];
        $rf = $pcr['retryable_failed'];

        $pending_jobs += $p;
        $running_jobs += $r;
        $clear_count  += $p;
        $retry_count  += $rf;

        if ($is_running) $any_running = true;
        if ($p > 0 && !$is_running) {
            $can_start = true;
            $start_count += $p;
        }
        if ($r > 0 || ($is_running && $p > 0))
            $can_stop = true;
        if ($rf > 0) $can_retry = true;
        if ($p > 0) $can_clear = true;
    }

    $can_selesai = ($pending_jobs === 0 && $running_jobs === 0);
    $can_stop    = ($running_jobs > 0) || ($any_running && $pending_jobs > 0);
    $stop_count  = $running_jobs;

    $global = [
        'ok'          => true,
        'queue'       => $g_pending + $g_running,
        'pending'     => $g_pending,
        'running'     => $g_running,
        'success'     => $g_success,
        'failed'      => $g_failed,
        'failed_x'    => $g_failed_x,
        'daftar_ok'   => (int)($pd['daftar_ok'] ?? 0),
        'layanan_ok'  => (int)($pd['layanan_ok'] ?? 0),
        'pasien'      => (int)($pd['total'] ?? 0),
        'any_running' => $any_running,
        'can_start'   => $can_start,
        'can_stop'    => $can_stop,
        'can_retry'   => $can_retry,
        'can_clear'   => $can_clear,
        'can_selesai' => $can_selesai,
        'start_count' => $start_count,
        'stop_count'  => $stop_count,
        'retry_count' => $retry_count,
        'clear_count' => $clear_count,
        'pending_jobs' => $pending_jobs,
        'running_jobs' => $running_jobs,
    ];

    echo json_encode([
        'ok'     => true,
        'global' => $global,
        'pcs'    => $pc_results,
    ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage(), 'line' => $e->getLine()]);
}
