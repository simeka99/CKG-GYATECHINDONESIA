<?php
ob_start();
$page_title = 'PC & License Keys — RMIK Medical Record';
require_once __DIR__ . '/../includes/header.php';

$db  = db();
$uid = (int) $_SESSION['user_id'];

$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
$error = '';

$urow = $db->prepare("SELECT license_quota FROM users WHERE id = ? LIMIT 1");
$urow->execute([$uid]);
$lq_raw = $urow->fetchColumn();
$lq = json_decode($lq_raw ?: '{}', true) ?: [];

$all_combos = [
    'pendaftaran_umum'    => [
        'label' => 'Pendaftaran Umum',
        'color' => 'blue',
        'svg' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>'
    ],
    'pelayanan_umum'      => [
        'label' => 'Pelayanan Umum',
        'color' => 'emerald',
        'svg' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>'
    ],
    'pendaftaran_sekolah' => [
        'label' => 'Pendaftaran Sekolah',
        'color' => 'indigo',
        'svg' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>'
    ],
    'pelayanan_sekolah'   => [
        'label' => 'Pelayanan Sekolah',
        'color' => 'violet',
        'svg' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"></path></svg>'
    ],
];

$used_combo = [];
$rows = $db->prepare("
    SELECT task_type, mode, COUNT(*) as cnt
    FROM license_keys WHERE user_id = ? AND is_active = 1 AND is_deleted = 0
    GROUP BY task_type, mode
");
$rows->execute([$uid]);
foreach ($rows->fetchAll() as $r)
    $used_combo[$r['task_type'] . '_' . $r['mode']] = (int)$r['cnt'];



$keys = $db->prepare("SELECT * FROM license_keys WHERE user_id = ? AND is_deleted = 0 ORDER BY is_active DESC, id DESC");
$keys->execute([$uid]);
$keys = $keys->fetchAll();
$now = time();
?>

<main class="flex-1 p-4 lg:p-8 bg-slate-50/50">

    <!-- Header removed per design update -->

    <!-- FLASH -->
    <?php if ($success): ?>
        <div class="mb-5 flex items-center gap-2.5 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700 text-sm font-semibold">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>
            <?= h($success) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-5 flex items-center gap-2.5 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-600 text-sm font-semibold">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <!-- SLOT QUOTA INFO -->
    <div class="mb-6 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <?php foreach ($all_combos as $key => $meta):
            $max  = (int)($lq[$key] ?? 0);
            $used = (int)($used_combo[$key] ?? 0);
            $pct  = $max > 0 ? min(100, round($used / $max * 100)) : 0;
            $color = $meta['color'];
            $full = $max > 0 && $used >= $max;
        ?>
            <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] <?= $max === 0 ? 'opacity-50 ring-1 ring-slate-100/50' : "ring-1 ring-{$color}-100/50" ?> p-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="p-2 rounded-lg bg-<?= $color ?>-50 text-<?= $color ?>-500"><?= $meta['svg'] ?></span>
                    <?php if ($max === 0): ?>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Tidak Dibeli</span>
                    <?php elseif ($full): ?>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-rose-600 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-200">Penuh</span>
                    <?php else: ?>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-<?= $color ?>-600 bg-<?= $color ?>-50 px-2 py-0.5 rounded-full border border-<?= $color ?>-200">Aktif</span>
                    <?php endif; ?>
                </div>
                <div class="text-xs font-bold text-slate-700 mb-1"><?= $meta['label'] ?></div>
                <?php if ($max > 0): ?>
                    <div class="flex items-end justify-between mb-1.5">
                        <span class="text-2xl font-bold text-slate-800"><?= $used ?></span>
                        <span class="text-[10px] text-slate-400 font-medium mb-1">/ <?= $max ?> slot</span>
                    </div>
                    <div class="h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full <?= $full ? 'bg-rose-400' : "bg-{$color}-400" ?> transition-all"
                            style="width:<?= $pct ?>%"></div>
                    </div>
                <?php else: ?>
                    <div class="text-[11px] text-slate-400 mt-2">Hubungi admin untuk mengaktifkan.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- EMPTY STATE -->
    <?php if (empty($keys)): ?>
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm px-6 py-16 text-center">
            <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0
                       002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
            </div>
            <p class="font-bold text-slate-600 text-lg">Belum ada PC terdaftar</p>
            <p class="text-sm text-slate-400 mt-1">Hubungi admin untuk mendapatkan license key.</p>
        </div>

    <?php else: ?>
        <!-- GRID KARTU PC -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php foreach ($keys as $lk):
                $online = $lk['last_seen'] && ($now - strtotime($lk['last_seen'])) < 60;
                $last_str = $lk['last_seen'] ? date('d/m/Y H:i', strtotime($lk['last_seen'])) : 'Belum pernah';
                $is_dft = $lk['task_type'] === 'pendaftaran';
                $ac = $is_dft ? 'blue' : 'green';
                $strip_from = $is_dft ? 'from-blue-400' : 'from-green-400';
                $strip_to   = $is_dft ? 'to-blue-500'  : 'to-emerald-500';
                $icon_bg    = $is_dft ? 'bg-blue-50'   : 'bg-green-50';
                $icon_color = $is_dft ? 'text-blue-500' : 'text-green-500';
                $badge_bg   = $is_dft ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700';
            ?>
                <div class="bg-white <?= $lk['is_active'] ? "ring-1 ring-{$ac}-100 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.06)]" : 'opacity-60 shadow-sm ring-1 ring-slate-100' ?>
                    rounded-2xl overflow-hidden transition-all hover:shadow-md group">

                    <!-- Strip warna atas -->
                    <div class="h-1 w-full bg-gradient-to-r
                    <?= $lk['is_active'] ? "{$strip_from} {$strip_to}" : 'from-slate-200 to-slate-300' ?>">
                    </div>

                    <div class="p-5">
                        <!-- Top row: icon + info + delete -->
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div class="flex items-center gap-3">
                                <!-- PC icon + online dot -->
                                <div class="relative flex-shrink-0">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center
                                    <?= $lk['is_active'] ? $icon_bg : 'bg-slate-100' ?>">
                                        <svg class="w-5 h-5 <?= $lk['is_active'] ? $icon_color : 'text-slate-400' ?>"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0
                                           002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <?php if ($lk['is_active']): ?>
                                        <span class="absolute -top-0.5 -right-0.5 w-3 h-3 rounded-full
                                         border-2 border-white
                                         <?= $online ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span>
                                    <?php endif; ?>
                                </div>

                                <!-- Nama + badges -->
                                <div class="min-w-0">
                                    <div class="font-bold text-slate-800 truncate max-w-[140px] sm:max-w-[110px] lg:max-w-[150px]">
                                        <?= h($lk['pc_label']) ?>
                                    </div>
                                    <div class="flex items-center gap-1 mt-1 flex-wrap">
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $badge_bg ?>">
                                            <?= ucfirst(h($lk['task_type'])) ?>
                                        </span>
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">
                                            <?= ucfirst(h($lk['mode'])) ?>
                                        </span>
                                        <?php if (!$lk['is_active']): ?>
                                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-100 text-rose-600">
                                                Nonaktif
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>


                        </div>

                        <!-- License key box -->
                        <div class="bg-slate-50 border border-slate-100 rounded-xl px-3 py-2.5 flex items-center gap-2 mb-3">
                            <svg class="w-3.5 h-3.5 text-slate-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4
                               a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                            <code class="flex-1 text-[11px] text-slate-500 font-mono truncate" id="key-<?= $lk['id'] ?>">
                                <?= h($lk['license_key']) ?>
                            </code>
                            <button onclick="copyKey('key-<?= $lk['id'] ?>', this)" class="flex-shrink-0 px-2.5 py-1 bg-white border border-slate-200
                               hover:border-<?= $ac ?>-400 hover:text-<?= $ac ?>-600
                               text-slate-400 rounded-lg text-[10px] font-bold transition-colors">
                                Salin
                            </button>
                        </div>

                        <!-- Footer: last seen + status online -->
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div class="flex items-center gap-1.5 text-[10px] text-slate-400">
                                <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <?= $last_str ?>
                            </div>
                            <?php if ($lk['is_active']): ?>
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-lg
                                <?= $online ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
                                    <span class="w-1.5 h-1.5 rounded-full inline-block <?= $online ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span>
                                    <?= $online ? 'Online' : 'Offline' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<style>
    @keyframes fade-in {
        from {
            opacity: 0;
            transform: scale(.97) translateY(6px);
        }

        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }

    .animate-fade-in {
        animation: fade-in .18s ease-out;
    }
</style>

<script>
    function copyKey(id, btn) {
        var text = document.getElementById(id).textContent.trim();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                btn.textContent = 'Tersalin';
                setTimeout(function() {
                    btn.textContent = 'Salin';
                }, 2000);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.textContent = 'Tersalin';
            setTimeout(function() {
                btn.textContent = 'Salin';
            }, 2000);
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>