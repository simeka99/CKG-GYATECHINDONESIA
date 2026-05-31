<?php
ob_start();
$page_title = 'Jobdesk Control';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$uid = (int) $_SESSION['user_id'];
$scope_mode = get_scope_mode();
ensure_jobs_performance_indexes($db);
$jobs_csrf_token = csrf_token();
if ($jobs_csrf_token === '') {
    ensure_csrf_token();
    $jobs_csrf_token = csrf_token();
}

require_once __DIR__ . '/jobs/helpers.php';
require_once __DIR__ . '/jobs/actions.php';

$success = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);


session_write_close();

$jobs_cache_file = sys_get_temp_dir() . "/rmik_jobs_db_cache_{$uid}_{$scope_mode}.json";
$jobs_cache_valid = false;
$cached_data = [];

if (file_exists($jobs_cache_file) && (time() - filemtime($jobs_cache_file)) < 10) {
    $raw = @file_get_contents($jobs_cache_file);
    if ($raw) {
        $cached_data = json_decode($raw, true);
        if (is_array($cached_data) && isset($cached_data['pcs'])) {
            $jobs_cache_valid = true;
        }
    }
}

if ($jobs_cache_valid) {
    extract($cached_data);
} else {
    $pcs = [];
    try {
        $s = $db->prepare("
        SELECT *
        FROM license_keys
        WHERE user_id = ?
          AND is_active = 1
          AND (
                (? = 'sekolah' AND LOWER(COALESCE(mode, '')) = 'sekolah')
             OR (? = 'umum' AND LOWER(COALESCE(mode, 'umum')) = 'umum')
          )
        ORDER BY task_type, pc_label
    ");
        $s->execute([$uid, $scope_mode, $scope_mode]);
        $pcs = $s->fetchAll();
    } catch (Exception $e) {
    }

    $upload_filter_options = [];
    try {
        $s = $db->prepare("
        SELECT p.id, p.file_name, COUNT(pd.id) row_count
        FROM patient_uploads p
        LEFT JOIN patients_data pd ON pd.upload_id = p.id
        WHERE p.user_id = ? AND p.ckg_scope = ?
        GROUP BY p.id
        ORDER BY p.id DESC
    ");
        $s->execute([$uid, $scope_mode]);
        $upload_filter_options = $s->fetchAll();
    } catch (Exception $e) {
    }

    $now = time();
    $scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope=" . $db->quote($scope_mode);

    $pc_pend = [];
    $pc_run = [];
    $pc_fail = [];
    $pc_retry = [];
    $pc_fail_x = [];
    $pc_running_flag = [];

    try {
        $s = $db->prepare("
        SELECT jq.license_key_id,
               SUM(jq.status='pending') p,
               SUM(jq.status='running') r
        FROM job_queue jq
        WHERE jq.user_id = ?
        GROUP BY jq.license_key_id
    ");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row) {
            $pc_pend[(int) $row['license_key_id']] = (int) $row['p'];
            $pc_run[(int) $row['license_key_id']] = (int) $row['r'];
        }

        $s = $db->prepare("
        SELECT id, COALESCE(is_running,0) ir
        FROM license_keys
        WHERE user_id = ?
          AND is_active = 1
          AND (
                (? = 'sekolah' AND LOWER(COALESCE(mode, '')) = 'sekolah')
             OR (? = 'umum' AND LOWER(COALESCE(mode, 'umum')) = 'umum')
          )
    ");
        $s->execute([$uid, $scope_mode, $scope_mode]);
        foreach ($s->fetchAll() as $row)
            $pc_running_flag[(int) $row['id']] = (int) $row['ir'];

        $s = $db->prepare("
        SELECT jf.license_key_id, COUNT(*) c
        FROM job_failed jf
        WHERE jf.user_id = ?
        GROUP BY jf.license_key_id
    ");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row)
            $pc_fail[(int) $row['license_key_id']] = (int) $row['c'];

        $s = $db->prepare("
        SELECT jf.license_key_id, COUNT(*) c
        FROM job_failed jf
        WHERE jf.user_id = ?
          AND COALESCE(is_no_retry, 0) = 0
          AND (
                reg_code IS NULL
             OR reg_code = ''
             OR UPPER(reg_code) NOT IN (
                'SISTEM_MENOLAK',
                'DUKCAPIL_UPDATE',
                'DUKCAPIL',
                'DATA_TIDAK_DITEMUKAN',
                'VALIDASI_TIDAK_VALID',
                'VALIDASI_PESERTA_WALI_TIDAK_VALID',
                'SUDAH_TERDAFTAR',
                'SUDAH_MENERIMA_LAYANAN',
                'BATAS_KIRIM_RAPOR_HABIS',
                'NOT_IN_LIST'
             )
          )
          AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH MENERIMA LAYANAN%'
          AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH TERDAFTAR%'
          AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI TIDAK VALID%'
          AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI_PESERTA_WALI_TIDAK_VALID%'
          AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%BATAS KIRIM RAPOR HABIS%'
          AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
        GROUP BY jf.license_key_id
    ");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row)
            $pc_retry[(int) $row['license_key_id']] = (int) $row['c'];

        $s = $db->prepare("
        SELECT jfx.license_key_id, COUNT(*) c
        FROM job_failed_x jfx
        WHERE jfx.user_id = ?
        GROUP BY jfx.license_key_id
    ");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row)
            $pc_fail_x[(int) $row['license_key_id']] = (int) $row['c'];
    } catch (Exception $e) {
    }

    try {
        $g_pasien_st = $db->prepare("SELECT COUNT(*) FROM patients_data WHERE user_id=? AND ckg_scope=?");
        $g_pasien_st->execute([$uid, $scope_mode]);
        $g_pasien = (int)$g_pasien_st->fetchColumn();

        $lk_ids_list = array_map(fn($pc) => (int)$pc['id'], $pcs);
        $lk_in_global = $lk_ids_list ? implode(',', $lk_ids_list) : '0';

        $g_queue_st = $db->prepare("
        SELECT
            SUM(jq.status='pending') AS g_pend,
            SUM(jq.status='running') AS g_run
        FROM job_queue jq
        WHERE jq.user_id = ? AND jq.license_key_id IN ($lk_in_global)
    ");
        $g_queue_st->execute([$uid]);
        $gq = $g_queue_st->fetch();
        $g_pend = (int)($gq['g_pend'] ?? 0);
        $g_run = (int)($gq['g_run'] ?? 0);
        $g_queue = $g_pend + $g_run;

        $g_success_st = $db->prepare("
        SELECT
            COUNT(DISTINCT js.patient_id) AS g_success,
            COUNT(DISTINCT CASE WHEN js.task_type='pendaftaran' THEN js.patient_id END) AS g_daftar_ok,
            COUNT(DISTINCT CASE WHEN js.task_type='pelayanan' THEN js.patient_id END) AS g_layanan_ok
        FROM job_success js
        WHERE js.user_id = ? AND js.license_key_id IN ($lk_in_global)
    ");
        $g_success_st->execute([$uid]);
        $gs = $g_success_st->fetch();
        $g_success = (int)($gs['g_success'] ?? 0);
        $g_daftar_ok = (int)($gs['g_daftar_ok'] ?? 0);
        $g_layanan_ok = (int)($gs['g_layanan_ok'] ?? 0);

        $g_fail_st = $db->prepare("
        SELECT COUNT(DISTINCT jf.patient_id)
        FROM job_failed jf
        WHERE jf.user_id = ? AND jf.license_key_id IN ($lk_in_global)
          AND NOT EXISTS (SELECT 1 FROM job_queue jq WHERE jq.patient_id = jf.patient_id AND jq.user_id = ?)
          AND NOT EXISTS (SELECT 1 FROM job_success js WHERE js.patient_id = jf.patient_id AND js.user_id = ?)
    ");
        $g_fail_st->execute([$uid, $uid, $uid]);
        $g_failed = (int)$g_fail_st->fetchColumn();

        $g_fail_x_st = $db->prepare("
        SELECT COUNT(DISTINCT jfx.patient_id)
        FROM job_failed_x jfx
        WHERE jfx.user_id = ? AND jfx.license_key_id IN ($lk_in_global)
          AND NOT EXISTS (SELECT 1 FROM job_queue jq WHERE jq.patient_id = jfx.patient_id AND jq.user_id = ?)
          AND NOT EXISTS (SELECT 1 FROM job_success js WHERE js.patient_id = jfx.patient_id AND js.user_id = ?)
    ");
        $g_fail_x_st->execute([$uid, $uid, $uid]);
        $g_failed_x = (int)$g_fail_x_st->fetchColumn();
    } catch (Exception $e) {
        $g_pasien = $g_queue = $g_success = $g_failed = $g_failed_x =
            $g_daftar_ok = $g_layanan_ok = $g_pend = $g_run = 0;
    }

    $g_any_running  = false;
    $g_can_start    = false;
    $g_can_stop     = false;
    $g_can_retry    = false;
    $g_can_clear    = false;
    $g_start_count  = 0;
    $g_stop_count   = 0;
    $g_retry_count  = 0;
    $g_clear_count  = 0;
    $g_pending_jobs = 0;
    $g_running_jobs = 0;

    $now_ts = time();
    usort($pcs, function ($a, $b) use ($pc_running_flag, $pc_run, $now_ts) {
        $a_id = (int)$a['id'];
        $b_id = (int)$b['id'];

        $a_running = (($pc_run[$a_id] ?? 0) > 0 || ($pc_running_flag[$a_id] ?? 0) === 1) ? 1 : 0;
        $b_running = (($pc_run[$b_id] ?? 0) > 0 || ($pc_running_flag[$b_id] ?? 0) === 1) ? 1 : 0;
        if ($a_running !== $b_running) return $b_running - $a_running;

        $a_online = ($a['last_seen'] && ($now_ts - strtotime($a['last_seen'])) < 60) ? 1 : 0;
        $b_online = ($b['last_seen'] && ($now_ts - strtotime($b['last_seen'])) < 60) ? 1 : 0;
        if ($a_online !== $b_online) return $b_online - $a_online;

        return 0;
    });

    foreach ($pcs as $pc) {
        $id         = (int) $pc['id'];
        $is_running = (($pc_running_flag[$id] ?? 0) === 1);
        $pend       = (int) ($pc_pend[$id] ?? 0);
        $run        = (int) ($pc_run[$id] ?? 0);
        $fail       = (int) ($pc_fail[$id] ?? 0);
        $retry      = (int) ($pc_retry[$id] ?? 0);

        $g_pending_jobs += $pend;
        $g_running_jobs += $run;
        $g_clear_count  += $pend;
        $g_retry_count  += $retry;

        if ($is_running) $g_any_running = true;

        if ($pend > 0 && !$is_running) {
            $g_can_start   = true;
            $g_start_count += $pend;
        }
        if ($run > 0 || ($is_running && $pend > 0)) $g_can_stop = true;
        if ($retry > 0) $g_can_retry = true;
        if ($pend > 0) $g_can_clear = true;
    }

    $g_can_selesai = ($g_pending_jobs === 0 && $g_running_jobs === 0);
    $g_can_stop    = ($g_running_jobs > 0) || ($g_any_running && $g_pending_jobs > 0);
    $g_stop_count  = $g_running_jobs;

    sync_done_flags($db, $uid, $scope_mode);

    $avail_cache = [];
    foreach ($pcs as $pc) {
        $tt = $pc['task_type'];
        if (!isset($avail_cache[$tt]))
            $avail_cache[$tt] = count_avail($db, $uid, $tt);
    }

    $cached_data = compact(
        'upload_filter_options',
        'pcs',
        'pc_pend',
        'pc_run',
        'pc_fail',
        'pc_retry',
        'pc_fail_x',
        'pc_running_flag',
        'g_pasien',
        'g_queue',
        'g_success',
        'g_failed',
        'g_failed_x',
        'g_daftar_ok',
        'g_layanan_ok',
        'g_pend',
        'g_run',
        'avail_cache',
        'g_any_running',
        'g_can_start',
        'g_can_stop',
        'g_can_retry',
        'g_can_clear',
        'g_can_selesai',
        'g_start_count',
        'g_stop_count',
        'g_retry_count',
        'g_clear_count',
        'g_pending_jobs',
        'g_running_jobs'
    );
    @file_put_contents($jobs_cache_file, json_encode($cached_data));
}

// List per PC
$pc_lists = [];
$lk_ids_for_list = array_map(fn($pc) => (int) $pc['id'], $pcs);
if ($lk_ids_for_list) {
    $lk_in = implode(',', $lk_ids_for_list);
    try {
        $sq = $db->prepare("
            (SELECT jq.id jqid, jq.license_key_id lk_id, jq.status st, pd.data, NULL em
             FROM job_queue jq
             INNER JOIN patients_data pd ON pd.id = jq.patient_id
             WHERE jq.user_id = ? AND jq.license_key_id IN ({$lk_in})
               AND jq.status IN ('running','pending')
             ORDER BY (jq.status = 'running') DESC, jq.id ASC
             LIMIT 400)
            UNION ALL
            (SELECT jf.id, jf.license_key_id, 'failed', pd.data, jf.error_msg
             FROM job_failed jf
             INNER JOIN patients_data pd ON pd.id = jf.patient_id
             WHERE jf.user_id = ? AND jf.license_key_id IN ({$lk_in})
               AND COALESCE(jf.is_no_retry, 0) = 0
               AND (
                     jf.reg_code IS NULL
                  OR jf.reg_code = ''
                  OR UPPER(jf.reg_code) NOT IN (
                     'SISTEM_MENOLAK',
                     'DUKCAPIL_UPDATE',
                     'DUKCAPIL',
                     'DATA_TIDAK_DITEMUKAN',
                     'VALIDASI_TIDAK_VALID',
                     'VALIDASI_PESERTA_WALI_TIDAK_VALID',
                     'SUDAH_TERDAFTAR',
                     'SUDAH_MENERIMA_LAYANAN',
                     'BATAS_KIRIM_RAPOR_HABIS',
                     'NOT_IN_LIST'
                  )
               )
               AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%SUDAH MENERIMA LAYANAN%'
               AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%SUDAH TERDAFTAR%'
               AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%VALIDASI TIDAK VALID%'
               AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%VALIDASI_PESERTA_WALI_TIDAK_VALID%'
               AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%BATAS KIRIM RAPOR HABIS%'
               AND UPPER(COALESCE(jf.error_msg, '')) NOT LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
             ORDER BY jf.id DESC
             LIMIT 200)
        ");
        $sq->execute([$uid, $uid]);
        foreach ($sq->fetchAll() as $row) {
            $lk = (int) $row['lk_id'];
            if (!isset($pc_lists[$lk]))
                $pc_lists[$lk] = [];
            $pc_lists[$lk][] = $row;
        }
    } catch (Exception $e) {
    }
}
?>

<style>
    @media (min-width: 1024px) {
        #jobs-top {
            position: sticky;
            top: 0;
            z-index: 30;
            background: #f8fafc;
        }

        #jobs-cards {
            overflow-y: auto;
        }
    }

    @media (max-width: 1023px) {
        #jobs-top {
            position: static;
        }

        #jobs-cards {
            overflow-y: visible;
            height: auto !important;
        }
    }

    #jobs-cards::-webkit-scrollbar {
        width: 5px;
    }

    #jobs-cards::-webkit-scrollbar-track {
        background: transparent;
    }

    #jobs-cards::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }

    #jobs-cards::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .ctrl-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        border: 1px solid;
        transition: background .15s, color .15s;
        white-space: nowrap;
        cursor: pointer;
    }

    .ctrl-btn:disabled {
        cursor: not-allowed;
    }
</style>

<script>
    window.jobs_csrf_token = <?= json_encode($jobs_csrf_token) ?>;
    window.jobs_scope_mode = <?= json_encode($scope_mode) ?>;
    window.hide_nik_enabled = <?= json_encode(get_setting('hide_nik_user_' . $uid) === '1') ?>;
</script>
<script src="/user/jobs/ui_script.js?v=<?= filemtime(__DIR__ . '/jobs/ui_script.js') ?>"></script>
<script src="/user/jobs/ui_poll_unified.js?v=<?= filemtime(__DIR__ . '/jobs/ui_poll_unified.js') ?>"></script>

<div id="jobs-top" class="px-4 lg:px-8 pt-6 lg:pt-5 pb-3 space-y-3">

    <?php if ($success): ?>
        <div class="jobs_flash_message flex items-center gap-2.5 px-4 py-3 bg-emerald-50 border border-emerald-200
                rounded-xl text-emerald-700 text-sm font-semibold transition-all duration-500">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>
            <?= h($success) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="jobs_flash_message flex items-center gap-2.5 px-4 py-3 bg-rose-50 border border-rose-200
                rounded-xl text-rose-700 text-sm font-semibold transition-all duration-500">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-3 md:grid-cols-6 gap-2">

        <?php foreach (
            [
                [
                    'Pasien',
                    $g_pasien,
                    'slate',
                    'g-stat-pasien',
                    'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'
                ],
                [
                    'Queue',
                    $g_queue,
                    'amber',
                    'g-stat-queue',
                    'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'
                ],
                [
                    'Sukses',
                    $g_success,
                    'emerald',
                    'g-stat-success',
                    'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'
                ],
                [
                    'Daftar OK',
                    $g_daftar_ok,
                    'blue',
                    'g-stat-daftar',
                    'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'
                ],
                [
                    'Layan OK',
                    $g_layanan_ok,
                    'violet',
                    'g-stat-layanan',
                    'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z'
                ],
            ] as [$lbl, $val, $c, $el_id, $icon]
        ): ?>
            <div class="bg-white rounded-2xl p-3 text-center shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)]">
                <svg class="w-4 h-4 text-<?= $c ?>-400 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>" />
                </svg>
                <div id="<?= $el_id ?>" class="text-xl font-bold text-slate-800 leading-none">
                    <?= number_format($val) ?>
                </div>
                <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mt-0.5"><?= $lbl ?></div>
            </div>
        <?php endforeach; ?>


        <div class="bg-white rounded-2xl p-3 text-center shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)]">
            <svg class="w-4 h-4 text-rose-400 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div class="flex items-center justify-center gap-1.5">
                <span id="g-stat-failed" class="text-xl font-bold text-rose-600 leading-none">
                    <?= number_format($g_failed) ?>
                </span>
                <span class="text-[8px] font-bold text-rose-400 uppercase leading-none">Gagal</span>
            </div>
            <div class="border-t border-rose-100 my-1.5"></div>
            <div class="flex items-center justify-center gap-1.5">
                <span id="g-stat-failed-x" class="text-base font-bold text-slate-400 leading-none">
                    <?= number_format($g_failed_x) ?>
                </span>
                <span class="text-[8px] font-bold text-slate-300 uppercase leading-none">Arsip X</span>
            </div>
        </div>

    </div>


    <?php if (!empty($pcs)): ?>
        <div class="bg-white rounded-2xl px-4 py-3 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)]">
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mb-2.5">Kontrol Global</p>
            <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-2">

                <form method="POST" onsubmit="return confirm('Start semua PC yang punya antrian pending?')">
                    <input type="hidden" name="action" value="global_start">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" id="g-btn-start" <?= !$g_can_start ? 'disabled' : '' ?> class="ctrl-btn w-full
                    sm:w-auto justify-center <?= $g_can_start
                                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100'
                                                    : 'bg-slate-50 text-slate-300 border-slate-100' ?>">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Start Semua
                        <span id="g-btn-start-count" class="px-1.5 py-0.5 rounded-full text-[10px] font-bold
                              <?= $g_can_start ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-300' ?>">
                            <?= $g_start_count > 0 ? $g_start_count : '' ?>
                        </span>
                    </button>
                </form>

                <form method="POST" onsubmit="return confirm('Stop semua proses?\nAntrian pending TETAP tersimpan.')">
                    <input type="hidden" name="action" value="global_stop">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" id="g-btn-stop" <?= !$g_can_stop ? 'disabled' : '' ?> class="ctrl-btn w-full
                    sm:w-auto justify-center <?= $g_can_stop
                                                    ? 'bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-100'
                                                    : 'bg-slate-50 text-slate-300 border-slate-100' ?>">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
                            <rect x="5" y="5" width="14" height="14" rx="2" />
                        </svg>
                        Stop Semua
                        <span id="g-btn-stop-count" class="px-1.5 py-0.5 rounded-full text-[10px] font-bold
                              <?= $g_can_stop ? 'bg-rose-100 text-rose-600' : 'bg-slate-100 text-slate-300' ?>">
                            <?= $g_stop_count > 0 ? $g_stop_count : '' ?>
                        </span>
                    </button>
                </form>

                <form method="POST" onsubmit="return confirm('Retry job error teknis?')">
                    <input type="hidden" name="action" value="global_retry">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" id="g-btn-retry" <?= !$g_can_retry ? 'disabled' : '' ?> class="ctrl-btn w-full
                    sm:w-auto justify-center <?= $g_can_retry
                                                    ? 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100'
                                                    : 'bg-slate-50 text-slate-300 border-slate-100' ?>">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M1 4v6h6M3.51 15a9 9 0 102.13-9.36L1 10" />
                        </svg>
                        Retry Error Teknis
                        <span id="g-btn-retry-count" class="px-1.5 py-0.5 rounded-full text-[10px] font-bold
                              <?= $g_can_retry ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-300' ?>">
                            <?= $g_retry_count > 0 ? $g_retry_count : '' ?>
                        </span>
                    </button>
                </form>

                <form method="POST"
                    onsubmit="return confirm('Hapus semua antrian pending?\nData sukses &amp; arsip tetap tersimpan.')">
                    <input type="hidden" name="action" value="global_clear_queue">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" id="g-btn-clear" <?= !$g_can_clear ? 'disabled' : '' ?> class="ctrl-btn w-full
                    sm:w-auto justify-center <?= $g_can_clear
                                                    ? 'bg-red-50 text-red-700 border-red-200 hover:bg-red-100'
                                                    : 'bg-slate-50 text-slate-300 border-slate-100' ?>">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858
                               L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Hapus Antrian
                        <span id="g-btn-clear-count" class="px-1.5 py-0.5 rounded-full text-[10px] font-bold
                              <?= $g_can_clear ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-300' ?>">
                            <?= $g_clear_count > 0 ? $g_clear_count : '' ?>
                        </span>
                    </button>
                </form>

                <form method="POST"
                    onsubmit="return confirm('Selesaikan semua antrian?\nAntrian &amp; error teknis dibersihkan.')">
                    <input type="hidden" name="action" value="global_selesaikan">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" id="g-btn-selesai" <?= !$g_can_selesai ? 'disabled' : '' ?>
                        title="<?= !$g_can_selesai ? 'Tunggu semua proses selesai dulu' : 'Bersihkan antrian yang sudah selesai' ?>"
                        class="ctrl-btn w-full sm:w-auto justify-center col-span-2 sm:col-span-1
                        <?= $g_can_selesai
                            ? 'bg-slate-100 text-slate-700 border-slate-300 hover:bg-slate-200'
                            : 'bg-slate-50 text-slate-300 border-slate-100' ?>">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2
                               M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                        Selesaikan
                        <span id="g-btn-selesai-info" class="text-[9px] opacity-50">
                            <?php if (!$g_can_selesai && ($g_pending_jobs > 0 || $g_running_jobs > 0)): ?>
                                (<?= implode('/', array_filter([$g_running_jobs > 0 ? $g_running_jobs . 'r' : '', $g_pending_jobs > 0 ? $g_pending_jobs . 'p' : ''])) ?>)
                            <?php endif; ?>
                        </span>
                    </button>
                </form>

            </div>
        </div>
    <?php endif; ?>

</div>


<div id="jobs-cards" class="px-4 lg:px-8 pb-6">

    <?php if (empty($pcs)): ?>
        <div class="mt-4 bg-amber-50 border border-amber-200 rounded-2xl px-5 py-5
                flex items-center gap-3 text-sm text-amber-700">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Belum ada PC aktif.
            <a href="licenses.php" class="font-bold underline ml-1">Daftarkan PC -></a>
        </div>

    <?php else: ?>

        <div class="pt-2 lg:pt-1 space-y-4">
            <?php foreach ($pcs as $pc):
                $id = (int) $pc['id'];
                $tt = $pc['task_type'];
                $mode = $pc['mode'] ?? '';
                $online = $pc['last_seen'] && ($now - strtotime($pc['last_seen'])) < 60;
                $is_running = ($pc_running_flag[$id] ?? 0) === 1;
                $pend = $pc_pend[$id] ?? 0;
                $run = $pc_run[$id] ?? 0;
                $fail = $pc_fail[$id] ?? 0;
                $retry = $pc_retry[$id] ?? 0;
                $fail_x = $pc_fail_x[$id] ?? 0;
                $avail = $avail_cache[$tt] ?? 0;


                $batch_total = (int) ($pc['batch_total'] ?? 0);
                $bar_total = $batch_total > 0 ? $batch_total : ($pend + $run + $fail);
                $bar_done = $batch_total > 0 ? max(0, $batch_total - $pend - $run - $fail) : 0;
                $bar_pct = $bar_total > 0 ? min(100, round($bar_done / $bar_total * 100)) : 0;


                $can_start = $pend > 0 && !$is_running;
                $can_stop = ($run > 0) || ($is_running && $pend > 0);
                $can_retry = $retry > 0;
                $can_clear = $pend > 0;
                $can_selesai = ($pend === 0 && $run === 0);

                $is_dft = $tt === 'pendaftaran';
                $ac = $is_dft ? 'blue' : 'green';
                $ac_hex = $is_dft ? '#2563eb' : '#16a34a';
                $bar_grad = $is_dft ? 'from-blue-500 to-blue-400' : 'from-green-500 to-emerald-400';
                $job_fetch_filter_setting = get_job_fetch_filter_settings($uid, $id);
                $list = $pc_lists[$id] ?? [];

                include __DIR__ . '/jobs/ui_card.php';
            endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<div id="fetchFilterModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-slate-200 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-xl bg-slate-800 flex items-center justify-center">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-extrabold text-slate-800 leading-none">Filter Ambil Antrian</p>
                    <p class="text-[11px] text-slate-500 mt-1">
                        <span id="fetch_filter_pc_label">-</span> - <span id="fetch_filter_task_label">-</span>
                    </p>
                </div>
            </div>
            <button type="button" onclick="close_fetch_filter_modal()"
                class="w-8 h-8 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50">
                <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form id="fetch_filter_form" method="POST" class="px-5 py-4 space-y-4">
            <input type="hidden" name="action" value="pc_save_fetch_filter">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" id="fetch_filter_lk" name="license_key_id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Sumber File</label>
                    <select id="fetch_filter_upload_id" name="fetch_upload_id"
                        class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 bg-white outline-none focus:border-blue-400">
                        <option value="0">Semua File (default)</option>
                        <?php foreach ($upload_filter_options as $up): ?>
                            <option value="<?= (int) $up['id'] ?>"><?= h($up['file_name']) ?> (<?= number_format((int) $up['row_count']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Jenis Kelamin</label>
                    <select id="fetch_filter_gender" name="fetch_gender"
                        class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-700 bg-white outline-none focus:border-blue-400">
                        <option value="">Semua</option>
                        <option value="L">Laki-laki</option>
                        <option value="P">Perempuan</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-1">Usia Min (Tahun)</label>
                        <input id="fetch_filter_age_min" type="number" name="fetch_age_min" min="0" max="150" placeholder="0"
                            class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm outline-none focus:border-blue-400">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-1">Usia Max (Tahun)</label>
                        <input id="fetch_filter_age_max" type="number" name="fetch_age_max" min="0" max="150" placeholder="150"
                            class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm outline-none focus:border-blue-400">
                    </div>
                </div>
            </div>

            <p class="text-[11px] text-slate-400" id="fetch_age_hint">
                Klik Simpan untuk menyimpan filter. Jika tidak disetting, sistem ambil semua data pasien yang belum diproses.
            </p>

            <div class="flex items-center gap-2 pt-1">
                <button type="button" onclick="close_fetch_filter_modal()"
                    class="flex-1 px-3 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50">
                    Batal
                </button>
                <button type="button" onclick="save_fetch_filter()"
                    class="flex-1 px-3 py-2.5 rounded-xl bg-slate-800 text-white font-bold hover:bg-slate-700">
                    Simpan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    (function() {
        function recalc_jobs_height() {
            const cards = document.getElementById('jobs-cards');
            const top = document.getElementById('jobs-top');
            if (!cards || !top) return;

            const compact = window.innerWidth < 1200 || window.innerHeight < 820;
            if (compact) {
                cards.style.height = 'auto';
                cards.style.overflow = 'visible';
                top.style.position = 'static';
                top.style.top = '';
                top.style.zIndex = '';
                top.style.background = '';
                return;
            }

            const cards_top = cards.getBoundingClientRect().top;
            cards.style.height = Math.max(360, window.innerHeight - cards_top - 8) + 'px';
            cards.style.overflowY = 'auto';
            top.style.position = 'sticky';
            top.style.top = '0';
            top.style.zIndex = '30';
            top.style.background = '#f8fafc';
        }

        recalc_jobs_height();
        window.addEventListener('resize', recalc_jobs_height);
    })();

    (function() {
        const flash_list = document.querySelectorAll('.jobs_flash_message');
        if (!flash_list.length)
            return;
        setTimeout(() => {
            flash_list.forEach(flash_item => {
                flash_item.style.opacity = '0';
                flash_item.style.transform = 'translateY(-6px)';
                flash_item.style.maxHeight = '0px';
                flash_item.style.margin = '0';
                flash_item.style.paddingTop = '0';
                flash_item.style.paddingBottom = '0';
                flash_item.style.overflow = 'hidden';
                setTimeout(() => flash_item.remove(), 550);
            });
        }, 5000);
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>