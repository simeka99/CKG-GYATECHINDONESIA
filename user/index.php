<?php
$page_title = 'Dashboard — RMIK Medical Record';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$uid = (int) $user['id'];
$scope_mode = get_scope_mode();
ensure_scope_column($db, 'patients_data');

$udata = $db->prepare("
    SELECT subscription_type, subscription_end, quota_total, quota_used
    FROM users WHERE id=? LIMIT 1
");
$udata->execute([$uid]);
$udata = $udata->fetch();
$is_quota = ($udata['subscription_type'] ?? 'time') === 'quota';

/** @var string $base */

$q = function (string $sql, array $p = []) use ($db): int {
    try {
        $s = $db->prepare($sql);
        $s->execute($p);
        return (int) $s->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
};

/* ── Stat pakai tabel baru (Mutually Exclusive) ── */
$sys_uid = $db->quote($uid);
$sys_scope_mode = $db->quote($scope_mode);
$scope_patient_sub = "SELECT id FROM patients_data WHERE user_id=$sys_uid AND ckg_scope=$sys_scope_mode";
$q_sub = "SELECT patient_id FROM job_queue WHERE user_id=$sys_uid AND patient_id IS NOT NULL AND patient_id IN ($scope_patient_sub)";
$f_sub = "SELECT patient_id FROM job_failed WHERE user_id=$sys_uid AND patient_id IS NOT NULL AND patient_id IN ($scope_patient_sub) UNION SELECT patient_id FROM job_failed_x WHERE user_id=$sys_uid AND patient_id IS NOT NULL AND patient_id IN ($scope_patient_sub)";
$s_sub = "SELECT patient_id FROM job_success WHERE user_id=$sys_uid AND patient_id IS NOT NULL AND patient_id IN ($scope_patient_sub)";

$total_peserta = $q("SELECT COUNT(*) FROM patients_data WHERE user_id=? AND ckg_scope=?", [$uid, $scope_mode]);

// Prioritas 1: Sedang antre/berjalan (di job_queue)
$jobs_pending = $q("SELECT COUNT(DISTINCT patient_id) FROM job_queue WHERE user_id=? AND status='pending' AND patient_id IN ($scope_patient_sub)", [$uid]);
$jobs_running = $q("SELECT COUNT(DISTINCT patient_id) FROM job_queue WHERE user_id=? AND status='running' AND patient_id IN ($scope_patient_sub)", [$uid]);

// 2. SUKSES (Prioritas 2)
$jobs_success = $q("SELECT COUNT(DISTINCT patient_id) FROM job_success WHERE user_id=? AND patient_id IN ($scope_patient_sub) AND patient_id NOT IN ($q_sub)", [$uid]);
$jobs_pendaftaran_ok = $q("SELECT COUNT(DISTINCT patient_id) FROM job_success WHERE user_id=? AND task_type='pendaftaran' AND patient_id IN ($scope_patient_sub)", [$uid]);
$jobs_pelayanan_ok = $q("SELECT COUNT(DISTINCT patient_id) FROM job_success WHERE user_id=? AND task_type='pelayanan' AND patient_id IN ($scope_patient_sub)", [$uid]);

// 3. GAGAL (Prioritas 3 - Pernah gagal, tidak sedang antre, belum sukses)
$jobs_failed = $q("
        SELECT COUNT(*) FROM patients_data 
        WHERE user_id=? 
          AND ckg_scope=?
          AND id NOT IN ($q_sub) 
          AND id NOT IN ($s_sub)
          AND id IN ($f_sub)
    ", [$uid, $scope_mode]);

/* ── PC terdaftar ── */
$licenses = $db->prepare("
    SELECT * FROM license_keys WHERE user_id=? AND is_active=1 ORDER BY task_type, pc_label
");
$licenses->execute([$uid]);
$licenses = $licenses->fetchAll();

/* ── Aktivitas terbaru: gabung queue + success + failed ── */
$recent = [];
try {
    /* running & pending dari queue */
    $s1 = $db->prepare("
        SELECT jq.id, jq.status, jq.task_type, jq.created_at AS ts,
               lk.pc_label, pd.nik_index AS nik, 'Antre / Proses' AS error_msg
        FROM job_queue jq
        LEFT JOIN license_keys lk ON lk.id = jq.license_key_id
        LEFT JOIN patients_data pd ON pd.id = jq.patient_id
        WHERE jq.user_id = ?
          AND jq.patient_id IN ($scope_patient_sub)
        ORDER BY jq.id DESC LIMIT 5
    ");
    $s1->execute([$uid]);
    $r1 = $s1->fetchAll(PDO::FETCH_ASSOC);

    /* sukses terbaru */
    $s2 = $db->prepare("
        SELECT js.id, 'done' AS status, js.task_type, js.finished_at AS ts,
               lk.pc_label, pd.nik_index AS nik, 'Berhasil' AS error_msg
        FROM job_success js
        LEFT JOIN license_keys lk ON lk.id = js.license_key_id
        LEFT JOIN patients_data pd ON pd.id = js.patient_id
        WHERE js.user_id = ?
          AND js.patient_id IN ($scope_patient_sub)
        ORDER BY js.id DESC LIMIT 5
    ");
    $s2->execute([$uid]);
    $r2 = $s2->fetchAll(PDO::FETCH_ASSOC);

    /* gagal terbaru (aktif) */
    $s3 = $db->prepare("
        SELECT jf.id, 'error' AS status, jf.task_type, jf.failed_at AS ts,
               lk.pc_label, pd.nik_index AS nik, jf.error_msg
        FROM job_failed jf
        LEFT JOIN license_keys lk ON lk.id = jf.license_key_id
        LEFT JOIN patients_data pd ON pd.id = jf.patient_id
        WHERE jf.user_id = ?
          AND jf.patient_id IN ($scope_patient_sub)
        ORDER BY jf.id DESC LIMIT 5
    ");
    $s3->execute([$uid]);
    $r3 = $s3->fetchAll(PDO::FETCH_ASSOC);

    /* gagal terbaru (arsip / failed_x) */
    $s4 = $db->prepare("
        SELECT jfx.id, 'error' AS status, jfx.task_type, jfx.failed_at AS ts,
               lk.pc_label, pd.nik_index AS nik, jfx.error_msg
        FROM job_failed_x jfx
        LEFT JOIN license_keys lk ON lk.id = jfx.license_key_id
        LEFT JOIN patients_data pd ON pd.id = jfx.patient_id
        WHERE jfx.user_id = ?
          AND jfx.patient_id IN ($scope_patient_sub)
        ORDER BY jfx.id DESC LIMIT 5
    ");
    $s4->execute([$uid]);
    $r4 = $s4->fetchAll(PDO::FETCH_ASSOC);

    /* gabung, sort by ts desc, ambil 10 */
    $merged = array_merge($r1, $r2, $r3, $r4);
    usort($merged, fn($a, $b) => strtotime($b['ts'] ?: '0') - strtotime($a['ts'] ?: '0'));
    $recent = array_slice($merged, 0, 10);
} catch (Exception $e) {
}

/* ── Warna subscription badge ── */
if ($is_quota) {
    $quota_sisa = max(0, (int) $udata['quota_total'] - (int) $udata['quota_used']);
    $quota_pct = $udata['quota_total'] > 0
        ? min(100, (int) round($quota_sisa / $udata['quota_total'] * 100)) : 0;
    if ($quota_pct <= 10) {
        $bw = 'bg-rose-50 border-rose-200';
        $bt = 'text-rose-600';
    } elseif ($quota_pct <= 30) {
        $bw = 'bg-amber-50 border-amber-200';
        $bt = 'text-amber-600';
    } else {
        $bw = 'bg-blue-50 border-blue-200';
        $bt = 'text-blue-700';
    }
} else {
    $days_left = subscription_days_left($uid);
    if ($days_left <= 3) {
        $bw = 'bg-rose-50 border-rose-200';
        $bt = 'text-rose-600';
    } elseif ($days_left <= 7) {
        $bw = 'bg-amber-50 border-amber-200';
        $bt = 'text-amber-600';
    } else {
        $bw = 'bg-teal-50 border-teal-200';
        $bt = 'text-teal-700';
    }
}
?>

<main class="flex-1 p-4 lg:p-8 space-y-6">
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <?php
        $stats = [
            [
                'Peserta',
                $total_peserta,
                'slate',
                'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857
              M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857
              m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'
            ],
            // [
            //     'Pending',
            //     $jobs_pending,
            //     'amber',
            //     'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'
            // ],
            [
                'Running',
                $jobs_running,
                'blue',
                'M13 10V3L4 14h7v7l9-11h-7z'
            ],
            [
                'Sukses',
                $jobs_success,
                'emerald',
                'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'
            ],
            [
                'Gagal',
                $jobs_failed,
                'rose',
                'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'
            ],
        ];
        $cm = [
            'slate' => ['bg-slate-50', 'text-slate-400', 'text-slate-700'],
            'amber' => ['bg-amber-50', 'text-amber-500', 'text-amber-700'],
            'blue' => ['bg-blue-50', 'text-blue-500', 'text-blue-700'],
            'emerald' => ['bg-emerald-50', 'text-emerald-500', 'text-emerald-700'],
            'rose' => ['bg-rose-50', 'text-rose-500', 'text-rose-700'],
        ];
        foreach ($stats as [$lbl, $val, $clr, $path]):
            [$cbg, $cic, $cvl] = $cm[$clr];
        ?>
            <div class="bg-white rounded-2xl p-5 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] flex flex-col transition-transform hover:-translate-y-1">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold text-slate-400 tracking-wider"><?= $lbl ?></span>
                    <div class="w-8 h-8 <?= $cbg ?> rounded-xl flex items-center justify-center">
                        <svg class="w-4 h-4 <?= $cic ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="<?= $path ?>" />
                        </svg>
                    </div>
                </div>
                <div class="text-3xl font-bold <?= $cvl ?> tracking-tight"><?= number_format($val) ?></div>
            </div>
        <?php endforeach; ?>

        <div class="col-span-2 lg:col-span-1 bg-white rounded-2xl p-5 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] flex flex-col transition-transform hover:-translate-y-1">
            <div class="flex items-center justify-center mb-3">
                <div class="w-8 h-8 bg-teal-50 rounded-xl flex items-center justify-center">
                    <svg class="w-4 h-4 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-6 4h3" />
                    </svg>
                </div>
            </div>
            <div class="relative mt-1">
                <div class="absolute left-1/2 top-1 bottom-1 w-px bg-slate-200 -translate-x-1/2"></div>
                <div class="grid grid-cols-2">
                    <div class="flex flex-col items-center justify-center min-h-[62px] px-2 text-center -translate-x-2">
                        <div class="text-[11px] sm:text-xs font-bold text-blue-600 leading-tight">Pendaftaran</div>
                        <div class="text-xl sm:text-2xl font-bold text-blue-700 leading-none mt-1.5 tracking-tight"><?= number_format($jobs_pendaftaran_ok) ?></div>
                    </div>
                    <div class="flex flex-col items-center justify-center min-h-[62px] px-2 text-center translate-x-2">
                        <div class="text-[11px] sm:text-xs font-bold text-emerald-600 leading-tight">Pelayanan</div>
                        <div class="text-xl sm:text-2xl font-bold text-emerald-700 leading-none mt-1.5 tracking-tight"><?= number_format($jobs_pelayanan_ok) ?></div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] p-6">
            <h3 class="text-sm font-semibold text-slate-800 mb-4">Aksi Cepat</h3>
            <div class="space-y-2">
                <a href="<?= $base ?>/user/upload.php?scope=<?= urlencode($scope_mode) ?>" class="flex items-center gap-3 px-4 py-3 bg-teal-600 hover:bg-teal-700
                           text-white rounded-xl font-bold text-sm transition-colors">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0
                               011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                    Upload Data Excel
                </a>
                <a href="<?= $base ?>/user/jobs.php?scope=<?= urlencode($scope_mode) ?>" class="flex items-center gap-3 px-4 py-3 bg-blue-600 hover:bg-blue-700
                           text-white rounded-xl font-bold text-sm transition-colors">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0
                               001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Kelola &amp; Run Jobs
                </a>
                <a href="<?= $base ?>/user/monitor.php?scope=<?= urlencode($scope_mode) ?>" class="flex items-center gap-3 px-4 py-3 bg-slate-100 hover:bg-slate-200
                           text-slate-700 rounded-xl font-bold text-sm transition-colors">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0
                               002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    Monitor Progress
                </a>
            </div>
        </div>

        <div class="lg:col-span-2 bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] p-6">
            <h3 class="text-sm font-semibold text-slate-800 mb-4">PC Terdaftar</h3>
            <?php if (empty($licenses)): ?>
                <div class="flex flex-col items-center justify-center py-8 text-slate-300">
                    <svg class="w-10 h-10 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0
                           002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <p class="text-xs font-semibold text-slate-400">Belum ada PC terdaftar</p>
                    <a href="<?= $base ?>/user/licenses.php"
                        class="mt-3 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-xs font-bold">
                        Tambah PC Sekarang
                    </a>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php foreach ($licenses as $lk):
                        $online = $lk['last_seen'] && (time() - strtotime($lk['last_seen'])) < 60;
                        $last_str = $lk['last_seen']
                            ? date('d/m H:i', strtotime($lk['last_seen'])) : 'Belum pernah';
                        $isDft = $lk['task_type'] === 'pendaftaran';
                    ?>
                        <div class="flex items-center gap-3 px-4 py-3 bg-slate-50 border border-slate-100 rounded-xl">
                            <div class="relative flex-shrink-0">
                                <div class="w-9 h-9 bg-slate-200 rounded-xl flex items-center justify-center">
                                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0
                                       002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-white
                            <?= $online ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-bold text-slate-800 text-sm truncate"><?= h($lk['pc_label']) ?></div>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="text-[9px] font-black px-1.5 py-0.5 rounded
                                <?= $isDft ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' ?>">
                                        <?= ucfirst($lk['task_type']) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-medium">
                                        <?= $online ? 'Online' : $last_str ?>
                                    </span>
                                </div>
                            </div>
                            <span class="flex-shrink-0 text-[10px] font-bold px-2 py-1 rounded-lg
                        <?= $online ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
                                <?= $online ? 'Online' : 'Offline' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-50 flex items-center justify-between bg-slate-50/30">
            <h3 class="text-sm font-semibold text-slate-800">Aktivitas Terbaru</h3>
            <a href="<?= $base ?>/user/monitor.php?scope=<?= urlencode($scope_mode) ?>" class="text-xs text-teal-600 font-bold hover:text-teal-700 transition-colors">Monitor Progress →</a>
        </div>
        <?php if (empty($recent)): ?>
            <div class="px-6 py-10 text-center">
                <svg class="w-10 h-10 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0
                       00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <p class="text-sm text-slate-400 font-medium">Belum ada aktivitas. Upload data untuk memulai.</p>
            </div>
        <?php else: ?>
            <div class="w-full overflow-x-auto pb-2 -mx-1 px-1">
                <table class="w-full text-sm min-w-[700px] whitespace-nowrap">
                    <thead>
                        <tr class="bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            <th class="px-5 py-3 text-left">PC</th>
                            <th class="px-5 py-3 text-left">Tipe</th>
                            <th class="px-5 py-3 text-left">NIK Pasien</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Keterangan</th>
                            <th class="px-5 py-3 text-left">Waktu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php
                        $sm = [
                            'pending' => ['bg-amber-50', 'text-amber-600', 'Pending'],
                            'running' => ['bg-blue-50', 'text-blue-600', 'Running'],
                            'done' => ['bg-emerald-50', 'text-emerald-600', 'Sukses'],
                            'error' => ['bg-rose-50', 'text-rose-600', 'Gagal'],
                        ];
                        foreach ($recent as $j):
                            [$sbg, $stx, $slb] = $sm[$j['status']] ?? $sm['pending'];
                        ?>
                            <tr class="hover:bg-slate-50 transition-colors group">
                                <td class="px-5 py-3.5">
                                    <span class="px-2.5 py-1 bg-slate-100 text-slate-600 rounded-lg text-xs font-semibold">
                                        <?= h($j['pc_label'] ?? '—') ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span
                                        class="text-[11px] font-semibold px-2.5 py-1 rounded-full
                                <?= ($j['task_type'] === 'pendaftaran') ? 'bg-blue-50 text-blue-700' : 'bg-green-50 text-green-700' ?>">
                                        <?= ucfirst($j['task_type'] ?? '—') ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-xs font-mono font-medium text-slate-500">
                                    <?= h(format_nik((string) ($j['nik'] ?? ''))) ?: '—' ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 <?= $sbg ?> <?= $stx ?>
                                         rounded-xl text-[11px] font-bold">
                                        <?= $slb ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-slate-400 max-w-[200px] truncate"
                                    title="<?= h($j['error_msg'] ?? '') ?>">
                                    <?= h($j['error_msg'] ?? '—') ?>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-slate-400 whitespace-nowrap">
                                    <?= $j['ts'] ? date('d/m/y H:i', strtotime($j['ts'])) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>