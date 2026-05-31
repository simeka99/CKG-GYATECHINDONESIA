<?php
$page_title = 'Monitor PC & Mesin — RMIK Medical Record';
require_once __DIR__ . '/../includes/header.php';

$db = db();


$stmt = $db->query("
    SELECT 
        lk.id AS pc_id, 
        lk.pc_label, 
        lk.task_type AS pc_task_type, 
        lk.mode, 
        lk.last_seen, 
        lk.is_running,
        u.id AS user_id, 
        u.username, 
        u.full_name,
        (SELECT COUNT(*) FROM patients_data WHERE user_id = u.id) AS total_pasien_user,
        (SELECT COUNT(*) FROM job_queue WHERE license_key_id = lk.id) AS q_count,
        (SELECT COUNT(*) FROM job_success WHERE license_key_id = lk.id) AS s_count,
        (SELECT COUNT(*) FROM job_success WHERE license_key_id = lk.id AND task_type = 'pendaftaran') AS s_daftar,
        (SELECT COUNT(*) FROM job_success WHERE license_key_id = lk.id AND task_type = 'pelayanan') AS s_layanan,
        ((SELECT COUNT(*) FROM job_failed WHERE license_key_id = lk.id) + 
         (SELECT COUNT(*) FROM job_failed_x WHERE license_key_id = lk.id)) AS f_count
    FROM license_keys lk
    JOIN users u ON lk.user_id = u.id
    WHERE lk.is_active = 1
    ORDER BY u.username ASC, lk.pc_label ASC
");
$pcs_raw = $stmt->fetchAll();

$pcs_by_user = [];
foreach ($pcs_raw as $pc) {
    $uid = $pc['user_id'];
    if (!isset($pcs_by_user[$uid])) {
        $pcs_by_user[$uid] = [
            'user_id' => $uid,
            'username' => $pc['username'],
            'full_name' => $pc['full_name'] ?: $pc['username'],
            'total_pasien_user' => $pc['total_pasien_user'],
            'pcs' => []
        ];
    }
    $pcs_by_user[$uid]['pcs'][] = $pc;
}
$now = time();
?>

<main class="flex-1 p-4 md:p-6 xl:p-8 bg-slate-50/50">
    <div class="w-full max-w-[1820px] mx-auto">

    <div class="mb-7 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center shadow-sm flex-shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 tracking-tight leading-none">Monitor PC Aktif</h2>
                <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Pantau kinerja dan statistik beban kerja masing-masing PC operator</p>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full md:w-auto">
            <div class="relative w-full sm:w-72 2xl:w-80">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <input type="text" id="search_pc" onkeyup="filter_cards()" placeholder="Cari operator..." class="w-full bg-white border border-slate-200 text-slate-900 text-sm rounded-xl focus:ring-slate-500 focus:border-slate-500 block pl-10 p-2.5 outline-none transition-all">
            </div>
            <button onclick="window.location.reload()"
                class="px-4 py-2.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 shadow-sm shrink-0">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Refresh Data
            </button>
        </div>
    </div>


    <?php if (empty($pcs_by_user)): ?>
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm px-6 py-16 text-center">
            <svg class="w-12 h-12 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            <p class="font-bold text-slate-600">Belum ada PC yang aktif.</p>
            <p class="text-sm text-slate-400 mt-1">PC akan muncul di sini saat ditambahkan oleh operator.</p>
        </div>
        <?php else:
        foreach ($pcs_by_user as $u_id => $u_data): ?>

            <div class="user-group mb-12" data-group="<?= $u_id ?>">

                <div class="flex items-center gap-3 mb-4 pb-2 border-b border-slate-200/70">
                    <div
                        class="w-10 h-10 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center font-black text-lg shrink-0 shadow-[inset_0_2px_4px_rgba(0,0,0,0.06)]">
                        <?= strtoupper(substr($u_data['username'], 0, 1)) ?>
                    </div>
                    <div>
                        <h3 class="text-lg font-black text-slate-800 leading-tight flex items-center gap-2">
                            <?= h($u_data['full_name']) ?>
                            <span
                                class="px-1.5 py-0.5 rounded-md bg-white border border-slate-200 text-slate-600 text-[10px] font-bold shadow-sm"><?= count($u_data['pcs']) ?>
                                PC Aktif</span>
                        </h3>
                        <div class="text-[11px] text-slate-500 font-mono mt-0.5 flex items-center gap-3">
                            @<?= h($u_data['username']) ?>
                            <span class="flex items-center gap-1.5"><span
                                    class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Total Pasien: <strong
                                    class="text-slate-700 font-bold"><?= number_format($u_data['total_pasien_user']) ?></strong></span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4 xl:gap-5">
                    <?php foreach ($u_data['pcs'] as $pc):
                        $online = $pc['last_seen'] && ($now - strtotime($pc['last_seen'])) < 60;
                    ?>
                        <div class="pc-card bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden flex flex-col hover:shadow-md transition-shadow"
                            data-group="<?= $u_id ?>"
                            data-search="<?= strtolower(h($pc['pc_label'] . ' ' . $pc['pc_task_type'] . ' ' . $pc['mode'] . ' ' . $u_data['username'] . ' ' . $u_data['full_name'])) ?>">


                            <div class="p-4 border-b border-slate-50 bg-slate-50/50 flex flex-col gap-2.5">
                                <div class="flex items-center justify-between">
                                    <div class="font-extrabold text-[13px] text-slate-800 flex items-center gap-1.5 truncate" title="<?= h($pc['pc_label']) ?>">
                                        <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                        </svg>
                                        <?= h($pc['pc_label']) ?>
                                    </div>
                                    <?php if ($online && $pc['is_running']): ?>
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-100 border border-emerald-200 text-emerald-700 text-[10px] font-bold uppercase tracking-wider">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Running
                                        </span>
                                    <?php elseif ($online): ?>
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-blue-50 border border-blue-100 text-blue-600 text-[10px] font-bold uppercase tracking-wider">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Online
                                        </span>
                                    <?php else: ?>
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-slate-100 border border-slate-200 text-slate-500 text-[10px] font-bold uppercase tracking-wider">
                                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Offline
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center gap-1.5">
                                    <?php
                                    $task_chip = $pc['pc_task_type'] === 'pendaftaran'
                                        ? 'bg-blue-50 text-blue-700 border border-blue-200'
                                        : ($pc['pc_task_type'] === 'pelayanan'
                                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                            : 'bg-amber-50 text-amber-700 border border-amber-200');
                                    $task_text = str_replace('_', ' ', (string) $pc['pc_task_type']);
                                    ?>
                                    <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?= $task_chip ?>">
                                        <?= h($task_text) ?>
                                    </span>
                                    <span
                                        class="px-2 py-0.5 bg-white border border-slate-200 text-slate-500 rounded text-[9px] font-bold uppercase shadow-sm">
                                        <?= h($pc['mode']) ?>
                                    </span>
                                </div>
                            </div>


                            <div class="p-4 grid grid-cols-2 gap-y-4 gap-x-2 flex-1">
                                <div class="flex flex-col">
                                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Total Pasien
                                        (Akun)</span>
                                    <span
                                        class="text-sm font-black text-slate-700 mt-0.5"><?= number_format($pc['total_pasien_user']) ?></span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Antrian
                                        (Queue)</span>
                                    <span
                                        class="text-sm font-black text-amber-600 mt-0.5"><?= number_format($pc['q_count']) ?></span>
                                </div>
                                <div class="col-span-2 border-t border-slate-50 pt-3">
                                    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mb-2">Pekerjaan
                                        Selesai (PC Ini)</div>
                                    <div
                                        class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 flex justify-between items-center">
                                        <div>
                                            <span
                                                class="block text-2xl font-black text-emerald-600 leading-none"><?= number_format($pc['s_count']) ?></span>
                                            <span class="block text-[10px] font-bold text-emerald-500 uppercase mt-1">Total
                                                Sukses</span>
                                        </div>
                                        <div class="text-right flex flex-col gap-1">
                                            <span class="text-[11px] font-bold text-slate-600 bg-white px-2 py-0.5 rounded-lg border border-slate-100 shadow-sm flex items-center gap-1">
                                                <svg class="w-3 h-3 text-blue-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                                                </svg>
                                                <span class="text-blue-600"><?= number_format($pc['s_daftar']) ?></span> Daftar
                                            </span>
                                            <span class="text-[11px] font-bold text-slate-600 bg-white px-2 py-0.5 rounded-lg border border-slate-100 shadow-sm flex items-center gap-1">
                                                <svg class="w-3 h-3 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                                </svg>
                                                <span class="text-emerald-600"><?= number_format($pc['s_layanan']) ?></span> Layanan
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-span-2">
                                    <div class="bg-rose-50 border border-rose-100 rounded-xl p-3 flex items-center justify-between">
                                        <div>
                                            <span class="text-[10px] text-rose-500 font-bold uppercase tracking-wider">Total
                                                Gagal</span>
                                            <div class="text-lg font-black text-rose-600 leading-none mt-0.5">
                                                <?= number_format($pc['f_count']) ?>
                                            </div>
                                        </div>
                                        <div class="w-8 h-8 rounded-full bg-rose-100 flex items-center justify-center">
                                            <svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                    d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
    <?php endforeach;
    endif; ?>

    </div>
</main>

<script>
    function filter_cards() {
        var input = document.getElementById('search_pc').value.toLowerCase();
        var groups = document.querySelectorAll('.user-group');

        groups.forEach(function(group) {
            var cards = group.querySelectorAll('.pc-card');
            var has_visible = false;

            cards.forEach(function(card) {
                var str = card.getAttribute('data-search') || '';
                if (str.indexOf(input) > -1) {
                    card.style.display = 'flex';
                    has_visible = true;
                } else {
                    card.style.display = 'none';
                }
            });

            if (has_visible) {
                group.style.display = 'block';
            } else {
                group.style.display = 'none';
            }
        });
    }


    var is_typing = false;
    document.getElementById('search_pc').addEventListener('focus', function() {
        is_typing = true;
    });
    document.getElementById('search_pc').addEventListener('blur', function() {
        is_typing = false;
    });

    setTimeout(function() {
        if (!is_typing && document.getElementById('search_pc').value === '') {
            window.location.reload();
        } else {

            setInterval(function() {
                if (!is_typing && document.getElementById('search_pc').value === '') {
                    window.location.reload();
                }
            }, 30000);
        }
    }, 30000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
