<?php
$page_title = 'Dashboard Admin — RMIK Medical Record';
require_once __DIR__ . '/../includes/header.php';

$db = db();

$q = function ($sql, $p = []) use ($db) {
    try {
        $s = $db->prepare($sql);
        $s->execute($p);
        return (int) $s->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
};

$total_users = $q("SELECT COUNT(*) FROM users WHERE role = 'operator'");
$active_users = $q("SELECT COUNT(*) FROM users WHERE role='operator' AND is_active=1");
$total_patients = $q("SELECT COUNT(*) FROM patients_data");

$jobs_done = $q("SELECT COUNT(*) FROM job_success");
$jobs_failed = $q("SELECT COUNT(*) FROM job_failed")
    + $q("SELECT COUNT(*) FROM job_failed_x");
$jobs_running = $q("SELECT COUNT(*) FROM job_queue WHERE status='running'");
$jobs_pending = $q("SELECT COUNT(*) FROM job_queue WHERE status='pending'");
$total_jobs = $jobs_done + $jobs_failed
    + $q("SELECT COUNT(*) FROM job_queue");
$pc_online = $q("SELECT COUNT(*) FROM license_keys WHERE is_active=1 AND last_seen >= NOW() - INTERVAL 60 SECOND");

$operators = [];
try {
    $operators = $db->query("
        SELECT u.*,
               COUNT(DISTINCT lk.id) AS pc_count,
               COUNT(DISTINCT pd.id) AS patient_count
        FROM users u
        LEFT JOIN license_keys lk ON lk.user_id = u.id AND lk.is_active = 1
        LEFT JOIN patients_data pd ON pd.user_id = u.id
        WHERE u.role = 'operator'
        GROUP BY u.id
        ORDER BY u.id DESC
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {
}

$now = time();
?>

<main class="flex-1 p-4 md:p-6 xl:p-8 bg-slate-50/50">
    <div class="w-full max-w-[1820px] mx-auto">

    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center shadow-sm flex-shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 tracking-tight leading-none">Dashboard Admin</h2>
                <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Overview sistem Administrator Developer</p>
            </div>
        </div>
    </div>


    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-5 mb-8 md:mb-10">
        <?php
        $stats = [
            [
                'label' => 'Total Operator',
                'value' => $total_users,
                'sub' => "$active_users aktif",
                'color' => 'teal',
                'grad' => 'from-teal-500 to-teal-700',
                'shadow' => 'shadow-teal-500/20',
                'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'
            ],
            [
                'label' => 'PC Online',
                'value' => $pc_online,
                'sub' => 'aktif < 1 menit',
                'color' => 'emerald',
                'grad' => 'from-emerald-500 to-emerald-700',
                'shadow' => 'shadow-emerald-500/20',
                'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'
            ],
            [
                'label' => 'Total Pasien',
                'value' => number_format($total_patients),
                'sub' => 'semua operator',
                'color' => 'blue',
                'grad' => 'from-blue-500 to-blue-700',
                'shadow' => 'shadow-blue-500/20',
                'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'
            ],
            [
                'label' => 'Job Selesai',
                'value' => number_format($jobs_done),
                'sub' => number_format($jobs_failed) . " gagal · " . $jobs_running . " running",
                'color' => 'violet',
                'grad' => 'from-violet-500 to-violet-700',
                'shadow' => 'shadow-violet-500/20',
                'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'
            ],
        ];

        foreach ($stats as $s): ?>
            <div
                class="relative bg-white border border-slate-100/60 rounded-[1.25rem] p-4 md:p-6 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 group overflow-hidden">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 md:gap-0 mb-4 md:mb-6">
                    <span class="text-[10px] md:text-xs font-black text-slate-400 uppercase tracking-widest leading-none">
                        <?= $s['label'] ?>
                    </span>
                    <div
                        class="w-10 h-10 md:w-12 md:h-12 bg-gradient-to-br <?= $s['grad'] ?> rounded-2xl flex items-center justify-center shadow-lg <?= $s['shadow'] ?> transform group-hover:scale-105 transition-transform">
                        <svg class="w-5 h-5 md:w-5 md:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="<?= $s['icon'] ?>" />
                        </svg>
                    </div>
                </div>
                <div>
                    <div class="text-3xl md:text-4xl font-black text-slate-800 tracking-tight leading-none mb-1.5">
                        <?= $s['value'] ?></div>
                    <div
                        class="text-[10px] md:text-xs text-slate-500 font-semibold flex items-center gap-1.5 opacity-80 group-hover:opacity-100 transition-opacity">
                        <span class="w-1.5 h-1.5 rounded-full bg-<?= $s['color'] ?>-400"></span>
                        <?= $s['sub'] ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="flex items-end justify-between mb-4 md:mb-6 px-1 md:px-0">
        <div>
            <h3 class="text-lg md:text-xl font-extrabold text-slate-800 tracking-tight">Daftar Operator</h3>
            <p class="text-xs text-slate-500 font-medium mt-0.5 hidden md:block">10 Operator terbaru yang terdaftar di
                sistem.</p>
        </div>
        <a href="<?= $base ?>/admin/users.php"
            class="inline-flex items-center gap-1 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-full text-xs font-bold transition-all hover:pr-3 group">
            Kelola Semua
            <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-slate-600 transition-colors" fill="none"
                stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
            </svg>
        </a>
    </div>

    <?php if (empty($operators)): ?>
        <div class="bg-white border text-center border-slate-100 rounded-[1.5rem] shadow-sm px-6 py-16">
            <div
                class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 border border-slate-100">
                <svg class="w-10 h-10 text-slate-300 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </div>
            <h4 class="text-slate-700 font-bold mb-1">Belum Ada Operator</h4>
            <p class="text-sm text-slate-400 font-medium max-w-sm mx-auto">Sistem Anda belum memiliki operator terdaftar.
                Mulailah dengan menambahkan pengguna pertama.</p>
            <a href="<?= $base ?>/admin/users.php"
                class="inline-block mt-6 px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold shadow-lg shadow-teal-600/30 transition-all hover:-translate-y-0.5">
                + Tambah Operator
            </a>
        </div>
    <?php else: ?>
        <div class="md:hidden space-y-4">
            <?php foreach ($operators as $op):
                $is_quota = ($op['subscription_type'] === 'quota');
                $days_left = (!$is_quota && $op['subscription_end']) ? (new DateTime(date('Y-m-d')) > new DateTime(date('Y-m-d', strtotime($op['subscription_end']))) ? 0 : (int) (new DateTime(date('Y-m-d')))->diff(new DateTime(date('Y-m-d', strtotime($op['subscription_end']))))->days) : null;
                $is_expired = (!$is_quota && $op['subscription_end'] && strtotime($op['subscription_end']) <= $now);
                $quota_ok = ($is_quota && (int) $op['quota_used'] < (int) $op['quota_total']);

                if (!$op['is_active']) {
                    $st = 'suspended';
                } elseif ($is_quota && !$quota_ok) {
                    $st = 'quota_habis';
                } elseif (!$is_quota && $is_expired) {
                    $st = 'expired';
                } elseif (!$is_quota && !$op['subscription_end']) {
                    $st = 'noset';
                } else {
                    $st = 'active';
                }

                $sbadge = ['active' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'expired' => 'bg-rose-50 text-rose-700 border-rose-200', 'quota_habis' => 'bg-rose-50 text-rose-700 border-rose-200', 'suspended' => 'bg-slate-100 text-slate-500 border-slate-200', 'noset' => 'bg-amber-50 text-amber-700 border-amber-200'][$st];
                $slabel = ['active' => 'Aktif', 'expired' => 'Expired', 'quota_habis' => 'Habis', 'suspended' => 'Suspend', 'noset' => 'Blm Set'][$st];
            ?>
                <div class="bg-white border border-slate-100 rounded-2xl p-4 shadow-sm relative overflow-hidden">
                    <div
                        class="absolute left-0 top-0 bottom-0 w-1 <?= str_replace(['bg-', '-50', '-100'], ['bg-', '-400', '-400'], explode(' ', $sbadge)[0]) ?>">
                    </div>

                    <div class="flex items-start justify-between mb-3 pl-2">
                        <div class="flex gap-3 items-center">
                            <div
                                class="w-10 h-10 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center flex-shrink-0">
                                <span
                                    class="text-slate-600 font-black text-sm"><?= strtoupper(substr($op['full_name'] ?: $op['username'], 0, 1)) ?></span>
                            </div>
                            <div>
                                <div class="font-bold text-slate-800 leading-tight"><?= h($op['full_name'] ?: '-') ?></div>
                                <div class="text-[10px] text-slate-500 font-mono mt-0.5">@<?= h($op['username']) ?></div>
                            </div>
                        </div>
                        <span
                            class="px-2 py-1 rounded border <?= $sbadge ?> text-[9px] font-black uppercase tracking-wider"><?= $slabel ?></span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 bg-slate-50 rounded-xl p-3 mb-3 border border-slate-100/60 ml-2">
                        <div class="text-center border-r border-slate-200/60">
                            <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">PC</div>
                            <div class="text-sm font-black text-slate-700"><?= $op['pc_count'] ?></div>
                        </div>
                        <div class="text-center border-r border-slate-200/60">
                            <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Pasien</div>
                            <div class="text-sm font-black text-slate-700"><?= number_format($op['patient_count']) ?></div>
                        </div>
                        <div class="text-center flex flex-col justify-center items-center">
                            <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Paket</div>
                            <div class="text-[10px] font-bold text-slate-600 truncate max-w-full px-1">
                                <?= h($op['subscription_note'] ?: '-') ?></div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between ml-2">
                        <div class="flex-1 mr-4">
                            <?php if ($is_quota): ?>
                                <div class="flex justify-between items-center mb-1.5">
                                    <span class="text-[9px] font-bold text-blue-500 uppercase tracking-widest">Sisa Kuota</span>
                                    <span
                                        class="text-[10px] font-black text-blue-700"><?= number_format($op['quota_used']) ?>/<?= number_format($op['quota_total']) ?></span>
                                </div>
                                <div class="h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-blue-500 rounded-full"
                                        style="width:<?= $op['quota_total'] > 0 ? min(100, round($op['quota_used'] / $op['quota_total'] * 100)) : 0 ?>%">
                                    </div>
                                </div>
                            <?php elseif ($op['subscription_end']): ?>
                                <div class="flex items-center gap-2">
                                    <div class="text-[11px] font-bold text-slate-600">s/d
                                        <?= date('d/m/Y', strtotime($op['subscription_end'])) ?></div>
                                    <?php if ($days_left !== null): ?>
                                        <span
                                            class="text-[9px] px-1.5 py-0.5 rounded-sm font-bold <?= $days_left <= 3 ? 'bg-rose-100 text-rose-700' : ($days_left <= 7 ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-600') ?>"><?= $days_left > 0 ? "$days_left Hari" : 'EXP' ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-[11px] text-amber-500 font-bold bg-amber-50 px-2 py-0.5 rounded">Belum
                                    diset</span>
                            <?php endif; ?>
                        </div>
                        <a href="<?= $base ?>/admin/users.php?edit=<?= $op['id'] ?>"
                            class="w-8 h-8 rounded-full bg-slate-100 hover:bg-teal-50 text-slate-500 hover:text-teal-600 flex items-center justify-center transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                            </svg>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="hidden md:block bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[800px] border-collapse">
                    <thead>
                        <tr
                            class="bg-slate-50/80 border-b border-slate-200 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            <th class="px-6 py-4 text-left font-bold">INFO OPERATOR</th>
                            <th class="px-6 py-4 text-left font-bold">PAKET / CATATAN</th>
                            <th class="px-6 py-4 text-left font-bold">MASA AKTIF / KUOTA</th>
                            <th class="px-6 py-4 text-left font-bold">PC AKTIF</th>
                            <th class="px-6 py-4 text-left font-bold">DATA PASIEN</th>
                            <th class="px-6 py-4 text-left font-bold">STATUS</th>
                            <th class="px-6 py-4 text-right font-bold w-24">AKSI</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($operators as $op):
                            $is_quota = ($op['subscription_type'] === 'quota');
                            $days_left = (!$is_quota && $op['subscription_end']) ? (new DateTime(date('Y-m-d')) > new DateTime(date('Y-m-d', strtotime($op['subscription_end']))) ? 0 : (int) (new DateTime(date('Y-m-d')))->diff(new DateTime(date('Y-m-d', strtotime($op['subscription_end']))))->days) : null;
                            $is_expired = (!$is_quota && $op['subscription_end'] && strtotime($op['subscription_end']) <= $now);
                            $quota_ok = ($is_quota && (int) $op['quota_used'] < (int) $op['quota_total']);

                            if (!$op['is_active']) {
                                $st = 'suspended';
                            } elseif ($is_quota && !$quota_ok) {
                                $st = 'quota_habis';
                            } elseif (!$is_quota && $is_expired) {
                                $st = 'expired';
                            } elseif (!$is_quota && !$op['subscription_end']) {
                                $st = 'noset';
                            } else {
                                $st = 'active';
                            }

                            $sbadge = ['active' => 'bg-emerald-50 text-emerald-700 border-emerald-200 shadow-sm shadow-emerald-500/10', 'expired' => 'bg-rose-50 text-rose-700 border-rose-200 shadow-sm shadow-rose-500/10', 'quota_habis' => 'bg-rose-50 text-rose-700 border-rose-200 shadow-sm shadow-rose-500/10', 'suspended' => 'bg-slate-100 text-slate-500 border-slate-200 shadow-sm', 'noset' => 'bg-amber-50 text-amber-700 border-amber-200 shadow-sm shadow-amber-500/10'][$st];
                            $slabel = ['active' => 'Aktif', 'expired' => 'Expired', 'quota_habis' => 'Kuota Habis', 'suspended' => 'Suspend', 'noset' => 'Blm Diset'][$st];
                        ?>
                            <tr class="hover:bg-slate-50/50 transition-colors group">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3.5">
                                        <div
                                            class="w-9 h-9 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center flex-shrink-0 group-hover:border-teal-200 group-hover:bg-teal-50 transition-colors">
                                            <span
                                                class="text-slate-500 font-black text-sm group-hover:text-teal-600"><?= strtoupper(substr($op['full_name'] ?: $op['username'], 0, 1)) ?></span>
                                        </div>
                                        <div>
                                            <div class="text-sm font-extrabold text-slate-800 leading-tight">
                                                <?= h($op['full_name'] ?: '-') ?></div>
                                            <div
                                                class="text-[11px] text-slate-400 font-medium font-mono mt-0.5 group-hover:text-slate-500 transition-colors">
                                                @<?= h($op['username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs font-bold text-slate-600">
                                    <?= h($op['subscription_note'] ?? '-') ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($is_quota): ?>
                                        <div class="flex items-center gap-2 mb-1.5">
                                            <div class="text-xs font-black text-blue-700"><?= number_format($op['quota_used']) ?>
                                                <span class="text-blue-400 font-semibold font-mono text-[10px]">/
                                                    <?= number_format($op['quota_total']) ?> NIK</span>
                                            </div>
                                        </div>
                                        <div class="h-1.5 w-28 bg-slate-100 rounded-full overflow-hidden shadow-inner">
                                            <div class="h-full bg-gradient-to-r from-blue-400 to-blue-600 rounded-full"
                                                style="width:<?= $op['quota_total'] > 0 ? min(100, round($op['quota_used'] / $op['quota_total'] * 100)) : 0 ?>%">
                                            </div>
                                        </div>
                                    <?php elseif ($op['subscription_end']): ?>
                                        <div class="text-xs font-bold text-slate-700">s/d
                                            <?= date('d M Y', strtotime($op['subscription_end'])) ?></div>
                                        <?php if ($days_left !== null): ?>
                                            <div
                                                class="text-[10px] font-black uppercase tracking-wider mt-1 <?= $days_left <= 3 ? 'text-rose-500' : ($days_left <= 7 ? 'text-amber-500' : 'text-emerald-500') ?>">
                                                <?= $days_left > 0 ? "SISA $days_left HARI" : 'EXPIRED' ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span
                                            class="text-[11px] text-amber-600 font-bold bg-amber-50 px-2.5 py-1 rounded-md border border-amber-200">Belum
                                            diset</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-end gap-1 font-bold text-slate-700">
                                        <?= $op['pc_count'] ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center justify-center min-w-[32px] h-7 px-2 rounded-lg bg-slate-100 text-slate-600 text-xs font-black border border-slate-200">
                                        <?= number_format($op['patient_count']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 rounded-md border <?= $sbadge ?> text-[10px] font-black uppercase tracking-widest leading-none">
                                        <?= $slabel ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="<?= $base ?>/admin/users.php?edit=<?= $op['id'] ?>"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-white border border-slate-200 text-slate-400 hover:text-teal-600 hover:bg-teal-50 hover:border-teal-200 hover:shadow-sm transition-all shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
