<?php

/**
 * JOBS — UI Card per PC
 * Variabel wajib: $pc, $id, $tt, $mode, $ac, $ac_hex, $bar_grad
 *   $online, $is_running, $is_dft
 *   $pend, $run, $fail, $fail_x, $avail
 *   $batch_total, $bar_total, $bar_done, $bar_pct
 *   $can_start, $can_stop, $can_retry, $can_clear, $can_selesai
 *   $list
 */

// Fallback aman kalau variabel tidak di-set dari jobs.php
$id = $id ?? 0;
$tt = $tt ?? '';
$mode = $mode ?? '';
$pc = $pc ?? ['pc_label' => 'Unknown PC'];
$is_dft = $is_dft ?? false;
$is_sekolah = $is_sekolah ?? (strpos(strtolower($mode), 'sekolah') !== false);
$online = $online ?? false;
$is_running = $is_running ?? false;
$ac = $ac ?? 'blue';
$ac_hex = $ac_hex ?? '#2563eb';
$bar_grad = $bar_grad ?? 'from-blue-500 to-blue-400';
$pend = $pend ?? 0;
$run = $run ?? 0;
$fail = $fail ?? 0;
$retry = $retry ?? 0;
$fail_x = $fail_x ?? 0;
$avail = $avail ?? 0;
$batch_total = $batch_total ?? 0;
$bar_total = $bar_total ?? 0;
$bar_done = $bar_done ?? 0;
$bar_pct = $bar_pct ?? 0;
$can_start = $can_start ?? false;
$can_stop = $can_stop ?? false;
$can_retry = $can_retry ?? false;
$can_clear = $can_clear ?? false;
$can_selesai = $can_selesai ?? false;
$list = $list ?? [];

$st_cls = [
    'running' => 'bg-blue-100 text-blue-700',
    'pending' => 'bg-amber-50 text-amber-700',
    'done'    => 'bg-emerald-50 text-emerald-700',
    'failed'  => 'bg-rose-50 text-rose-600',
];
$st_lbl = [
    'running' => 'Run',
    'pending' => 'Wait',
    'done'    => 'OK',
    'failed'  => 'Err',
];
$retry_count = (int)($retry ?? $fail ?? 0);
?>

<div data-card-lk="<?= $id ?>"
    class="bg-white <?= $online ? "ring-1 ring-{$ac}-100 shadow-[0_2px_12px_-3px_rgba(0,0,0,0.06)]" : 'shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)]' ?>
            rounded-2xl overflow-hidden flex flex-col">

    <!-- ══ CARD HEADER ══ -->
    <div class="px-4 sm:px-5 py-4 border-b border-slate-50">

        <!-- Baris 1: dot + info + tombol -->
        <div class="flex items-start gap-3">

            <!-- Online dot -->
            <div class="relative mt-1.5 flex-shrink-0">
                <span id="dot-<?= $id ?>"
                    class="block w-2.5 h-2.5 rounded-full
                             <?= $online ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span>
                <span id="ping-<?= $id ?>"
                    class="absolute inset-0 rounded-full bg-emerald-400 animate-ping opacity-60"
                    <?= ($online && $is_running) ? '' : 'style="display:none"' ?>></span>
            </div>

            <!-- PC info -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5 flex-wrap">

                    <h3 class="text-base sm:text-lg font-bold text-slate-800
                               truncate max-w-[140px] sm:max-w-none">
                        <?= h($pc['pc_label']) ?>
                    </h3>

                    <!-- Task type badge -->
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold
                                 px-2 py-0.5 rounded-full bg-<?= $ac ?>-100 text-<?= $ac ?>-700">
                        <?php if ($is_dft): ?>
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0
                                   014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42
                                   3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806
                                   1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42
                                   3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0
                                   01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438
                                   3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                            </svg>
                        <?php else: ?>
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5
                                   0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                            </svg>
                        <?php endif; ?>
                        <?= ucfirst($tt) ?>
                    </span>

                    <!-- Mode badge -->
                    <?php if ($mode): ?>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold
                                 px-2 py-0.5 rounded-full
                                 <?= $is_sekolah ? 'bg-sky-100 text-sky-700' : 'bg-orange-100 text-orange-700' ?>">
                            <?php if ($is_sekolah): ?>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                        d="M12 14l9-5-9-5-9 5 9 5zM12 14l6.16-3.422a12.083 12.083 0
                                   01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0
                                   00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                                </svg>
                            <?php else: ?>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283
                                   -.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283
                                   .356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            <?php endif; ?>
                            <?= ucfirst(h($mode)) ?>
                        </span>
                    <?php endif; ?>

                    <!-- Online label -->
                    <span id="online-lbl-<?= $id ?>"
                        class="text-[11px] font-semibold <?= $online ? 'text-emerald-600' : 'text-slate-400' ?>">
                        <?= $online ? '● Online' : '○ Offline' ?>
                        <?php if ($is_running && $run > 0): ?>
                            <span class="ml-1 text-blue-600 font-bold animate-pulse">(<?= $run ?> berjalan)</span>
                        <?php elseif ($is_running): ?>
                            <span class="ml-1 text-emerald-600 font-bold">(aktif)</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Progress bar -->
                <div class="mt-2.5">
                    <div class="flex justify-between text-[10px] text-slate-400 mb-1">
                        <span id="bar-label-<?= $id ?>">
                            <?= number_format($bar_done) ?> dari <?= number_format($bar_total) ?> selesai
                        </span>
                        <span id="bar-pct-<?= $id ?>"
                            class="font-bold text-<?= $ac ?>-700"><?= $bar_pct ?>%</span>
                    </div>
                    <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div id="bar-fill-<?= $id ?>"
                            class="h-full rounded-full bg-gradient-to-r <?= $bar_grad ?> transition-all duration-700"
                            style="width:<?= $bar_pct ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- ══ TOMBOL KONTROL DESKTOP ══ -->
            <div class="hidden sm:flex flex-shrink-0 items-center gap-1 flex-wrap justify-end">
                <?php
                $btn_base = "flex flex-col items-center gap-0.5 px-2 sm:px-3 py-2 rounded-xl border transition-all text-center min-w-[40px]";
                $btn_on   = fn($c) => "{$btn_base} bg-{$c}-50 text-{$c}-700 border-{$c}-200 hover:bg-{$c}-100";
                $btn_off  = "{$btn_base} bg-slate-50 text-slate-300 border-slate-100 cursor-not-allowed opacity-40";
                ?>

                <!-- START -->
                <form method="POST" onsubmit="return confirm('Start antrian di <?= addslashes($pc['pc_label']) ?>?')">
                    <input type="hidden" name="action" value="pc_start">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="license_key_id" value="<?= $id ?>">
                    <button type="submit"
                        id="btn-start-<?= $id ?>"
                        <?= !$can_start ? 'disabled' : '' ?>
                        class="<?= $can_start ? $btn_on($ac) : $btn_off ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0
                                   001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-[9px] font-bold uppercase">Start</span>
                        <span id="btn-start-count-<?= $id ?>" class="text-[8px] opacity-70">
                            <?= $pend > 0 ? $pend : '' ?>
                        </span>
                    </button>
                </form>

                <!-- STOP -->
                <form method="POST"
                    onsubmit="return confirm('Stop proses di <?= addslashes($pc['pc_label']) ?>?\nPending tetap tersimpan.')">
                    <input type="hidden" name="action" value="pc_stop">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="license_key_id" value="<?= $id ?>">
                    <button type="submit"
                        id="btn-stop-<?= $id ?>"
                        <?= !$can_stop ? 'disabled' : '' ?>
                        class="<?= $can_stop ? "{$btn_base} bg-rose-50 text-rose-600 border-rose-200 hover:bg-rose-100" : $btn_off ?>">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                            <rect x="5" y="5" width="14" height="14" rx="2" />
                        </svg>
                        <span class="text-[9px] font-bold uppercase">Stop</span>
                        <span id="btn-stop-count-<?= $id ?>" class="text-[8px] opacity-70">
                            <?= $run > 0 ? $run : '' ?>
                        </span>
                    </button>
                </form>

                <!-- RETRY -->
                <form method="POST"
                    onsubmit="return confirm('Retry <?= $retry_count ?> job gagal di <?= addslashes($pc['pc_label']) ?>?\nPC di-start otomatis.')">
                    <input type="hidden" name="action" value="pc_retry">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="license_key_id" value="<?= $id ?>">
                    <button type="submit"
                        id="btn-retry-<?= $id ?>"
                        <?= !$can_retry ? 'disabled' : '' ?>
                        class="<?= $can_retry ? "{$btn_base} bg-amber-50 text-amber-600 border-amber-200 hover:bg-amber-100" : $btn_off ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M1 4v6h6M3.51 15a9 9 0 102.13-9.36L1 10" />
                        </svg>
                        <span class="text-[9px] font-bold uppercase">Retry</span>
                        <span id="btn-retry-count-<?= $id ?>" class="text-[8px] opacity-70">
                            <?= $retry_count > 0 ? $retry_count : '' ?>
                        </span>
                    </button>
                </form>

                <!-- HAPUS ANTRIAN -->
                <form method="POST"
                    onsubmit="return confirm('Hapus antrian pending di <?= addslashes($pc['pc_label']) ?>?\nData sukses & arsip tetap tersimpan.')">
                    <input type="hidden" name="action" value="pc_clear_queue">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="license_key_id" value="<?= $id ?>">
                    <button type="submit"
                        id="btn-clear-<?= $id ?>"
                        <?= !$can_clear ? 'disabled' : '' ?>
                        class="<?= $can_clear ? "{$btn_base} bg-red-50 text-red-700 border-red-200 hover:bg-red-100" : $btn_off ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0
                                   01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1
                                   1 0 00-1 1v3M4 7h16" />
                        </svg>
                        <span class="text-[9px] font-bold uppercase">Hapus</span>
                        <span id="btn-clear-count-<?= $id ?>" class="text-[8px] opacity-70">
                            <?= $pend > 0 ? $pend : '' ?>
                        </span>
                    </button>
                </form>

                <!-- SELESAIKAN -->
                <form method="POST"
                    onsubmit="return confirm('Selesaikan antrian <?= addslashes($pc['pc_label']) ?>?\nSukses & arsip permanen tetap tersimpan.')">
                    <input type="hidden" name="action" value="pc_selesaikan">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="license_key_id" value="<?= $id ?>">
                    <button type="submit"
                        id="btn-selesai-<?= $id ?>"
                        <?= !$can_selesai ? 'disabled' : '' ?>
                        title="<?= !$can_selesai ? 'Tunggu semua proses selesai dulu' : 'Bersihkan antrian selesai' ?>"
                        class="<?= $can_selesai ? "{$btn_base} bg-slate-100 text-slate-600 border-slate-300 hover:bg-slate-200" : $btn_off ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0
                                   00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2
                                   2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                        <span class="text-[9px] font-bold uppercase">Selesai</span>
                        <span id="btn-selesai-info-<?= $id ?>" class="text-[8px] opacity-50">
                            <?php if (!$can_selesai && ($pend > 0 || $run > 0)): ?>
                                (<?= implode('/', array_filter([$run > 0 ? $run . 'r' : '', $pend > 0 ? $pend . 'p' : ''])) ?>)
                            <?php endif; ?>
                        </span>
                    </button>
                </form>

            </div><!-- /tombol desktop -->
        </div><!-- /baris 1 -->

        <!-- ══ TOMBOL KONTROL MOBILE ══ -->
        <div class="mt-3 grid grid-cols-5 gap-1.5 sm:hidden">
            <?php
            $mbtn     = "w-full flex flex-col items-center justify-center gap-0.5 py-2.5 rounded-xl border transition-all";
            $mbtn_off = "{$mbtn} bg-slate-50 text-slate-300 border-slate-100 cursor-not-allowed opacity-40";
            $mbtn_on  = fn($c) => "{$mbtn} bg-{$c}-50 text-{$c}-700 border-{$c}-200";
            ?>

            <!-- START mobile -->
            <form method="POST" onsubmit="return confirm('Start antrian di <?= addslashes($pc['pc_label']) ?>?')">
                <input type="hidden" name="action" value="pc_start">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="license_key_id" value="<?= $id ?>">
                <button type="submit"
                    id="btn-start-m-<?= $id ?>"
                    <?= !$can_start ? 'disabled' : '' ?>
                    class="<?= $can_start ? $mbtn_on($ac) : $mbtn_off ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0
                               001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="text-[9px] font-bold uppercase leading-none">Start</span>
                    <span id="btn-start-m-count-<?= $id ?>" class="text-[8px] opacity-70 leading-none">
                        <?= $pend > 0 ? $pend : '' ?>
                    </span>
                </button>
            </form>

            <!-- STOP mobile -->
            <form method="POST"
                onsubmit="return confirm('Stop proses di <?= addslashes($pc['pc_label']) ?>?\nPending tetap tersimpan.')">
                <input type="hidden" name="action" value="pc_stop">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="license_key_id" value="<?= $id ?>">
                <button type="submit"
                    id="btn-stop-m-<?= $id ?>"
                    <?= !$can_stop ? 'disabled' : '' ?>
                    class="<?= $can_stop ? "{$mbtn} bg-rose-50 text-rose-600 border-rose-200" : $mbtn_off ?>">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <rect x="5" y="5" width="14" height="14" rx="2" />
                    </svg>
                    <span class="text-[9px] font-bold uppercase leading-none">Stop</span>
                    <span id="btn-stop-m-count-<?= $id ?>" class="text-[8px] opacity-70 leading-none">
                        <?= $run > 0 ? $run : '' ?>
                    </span>
                </button>
            </form>

            <!-- RETRY mobile -->
            <form method="POST"
                onsubmit="return confirm('Retry <?= $retry_count ?> job gagal?\nPC di-start otomatis.')">
                <input type="hidden" name="action" value="pc_retry">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="license_key_id" value="<?= $id ?>">
                <button type="submit"
                    id="btn-retry-m-<?= $id ?>"
                    <?= !$can_retry ? 'disabled' : '' ?>
                    class="<?= $can_retry ? "{$mbtn} bg-amber-50 text-amber-600 border-amber-200" : $mbtn_off ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M1 4v6h6M3.51 15a9 9 0 102.13-9.36L1 10" />
                    </svg>
                    <span class="text-[9px] font-bold uppercase leading-none">Retry</span>
                    <span id="btn-retry-m-count-<?= $id ?>" class="text-[8px] opacity-70 leading-none">
                        <?= $retry_count > 0 ? $retry_count : '' ?>
                    </span>
                </button>
            </form>

            <!-- HAPUS mobile -->
            <form method="POST"
                onsubmit="return confirm('Hapus antrian pending?\nSukses & arsip tetap tersimpan.')">
                <input type="hidden" name="action" value="pc_clear_queue">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="license_key_id" value="<?= $id ?>">
                <button type="submit"
                    id="btn-clear-m-<?= $id ?>"
                    <?= !$can_clear ? 'disabled' : '' ?>
                    class="<?= $can_clear ? "{$mbtn} bg-red-50 text-red-700 border-red-200" : $mbtn_off ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0
                               01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1
                               1 0 00-1 1v3M4 7h16" />
                    </svg>
                    <span class="text-[9px] font-bold uppercase leading-none">Hapus</span>
                    <span id="btn-clear-m-count-<?= $id ?>" class="text-[8px] opacity-70 leading-none">
                        <?= $pend > 0 ? $pend : '' ?>
                    </span>
                </button>
            </form>

            <!-- SELESAIKAN mobile -->
            <form method="POST"
                onsubmit="return confirm('Selesaikan antrian?\nSukses & arsip permanen tetap tersimpan.')">
                <input type="hidden" name="action" value="pc_selesaikan">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="license_key_id" value="<?= $id ?>">
                <button type="submit"
                    id="btn-selesai-m-<?= $id ?>"
                    <?= !$can_selesai ? 'disabled' : '' ?>
                    class="<?= $can_selesai ? "{$mbtn} bg-slate-100 text-slate-600 border-slate-300" : $mbtn_off ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0
                               00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2
                               2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    <span class="text-[9px] font-bold uppercase leading-none">Selesai</span>
                    <span id="btn-selesai-m-info-<?= $id ?>" class="text-[8px] opacity-40 leading-none">
                        <?php if (!$can_selesai && ($pend > 0 || $run > 0)): ?>
                            (<?= implode('/', array_filter([$run > 0 ? $run . 'r' : '', $pend > 0 ? $pend . 'p' : ''])) ?>)
                        <?php endif; ?>
                    </span>
                </button>
            </form>

        </div><!-- /tombol mobile -->

        <!-- Stats 4 box -->
        <div class="grid grid-cols-4 gap-2 mt-3">
            <div class="bg-amber-50 rounded-xl py-2 text-center">
                <div id="stat-pend-<?= $id ?>"
                    class="text-base sm:text-xl font-bold text-amber-700 leading-tight">
                    <?= number_format($pend) ?>
                </div>
                <div class="text-[8px] sm:text-[9px] font-bold text-amber-700/60 uppercase tracking-wide">Pending</div>
            </div>
            <div class="bg-blue-50 rounded-xl py-2 text-center">
                <div id="stat-run-<?= $id ?>"
                    class="text-base sm:text-xl font-bold text-blue-700 leading-tight">
                    <?= number_format($run) ?>
                </div>
                <div class="text-[8px] sm:text-[9px] font-bold text-blue-700/60 uppercase tracking-wide">Running</div>
            </div>
            <div class="bg-emerald-50 rounded-xl py-2 text-center">
                <div id="stat-done-<?= $id ?>"
                    class="text-base sm:text-xl font-bold text-emerald-700 leading-tight">
                    <?= number_format($bar_done) ?>
                </div>
                <div class="text-[8px] sm:text-[9px] font-bold text-emerald-700/60 uppercase tracking-wide">Berhasil</div>
            </div>
            <div class="bg-rose-50 rounded-xl py-2 text-center">
                <div id="stat-fail-<?= $id ?>"
                    class="text-base sm:text-xl font-bold text-rose-700 leading-tight">
                    <?= number_format($fail) ?>
                </div>
                <div class="text-[8px] sm:text-[9px] font-bold text-rose-700/60 uppercase tracking-wide">Gagal</div>
            </div>
        </div>

    </div><!-- /card header -->

    <!-- ══ CARD BODY ══ -->
    <div class="flex flex-col md:flex-row">

        <!-- KIRI: Form ambil data -->
        <div class="md:w-[260px] lg:w-[280px] flex-shrink-0
                    border-b md:border-b-0 md:border-r border-slate-100
                    p-4 space-y-3">

            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Ambil Data ke Antrian
            </p>

            <div class="flex items-center justify-between text-xs">
                <span class="text-slate-500">Tersedia</span>
                <span id="avail-<?= $id ?>" class="font-extrabold" style="color:<?= $ac_hex ?>">
                    <?= number_format($avail) ?> data
                </span>
            </div>

            <form method="POST" onsubmit="return confirm_fetch(this, <?= $id ?>)">
                <input type="hidden" name="action" value="pc_fetch">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="license_key_id" value="<?= $id ?>">
                <input type="hidden" name="fetch_limit" id="lim_<?= $id ?>" value="50">

                <div class="flex gap-1 mb-2 items-center">
                    <?php foreach ([10, 50, 100] as $lv): ?>
                        <button type="button"
                            class="chip-<?= $id ?> flex-shrink-0 px-3 py-2 rounded-lg
                           text-[11px] font-bold border transition-all"
                            style="<?= $lv === 50
                                        ? "background:{$ac_hex};color:#fff;border-color:{$ac_hex};"
                                        : 'background:#fff;color:#475569;border-color:#e2e8f0;' ?>"
                            data-val="<?= $lv ?>"
                            onclick="set_lim(<?= $id ?>, <?= $lv ?>, '<?= $ac_hex ?>')">
                            <?= $lv ?>
                        </button>
                    <?php endforeach; ?>
                    <input type="number" placeholder="Other Queues"
                        id="cust_<?= $id ?>"
                        min="1" max="9999"
                        class="flex-1 min-w-0 px-2 py-2 text-xs font-mono
                           bg-white border rounded-lg outline-none transition-colors"
                        style="border-color:#e2e8f0"
                        onfocus="this.style.borderColor='<?= $ac_hex ?>'"
                        onblur="this.style.borderColor='#e2e8f0'"
                        oninput="set_lim_custom(<?= $id ?>, this.value)">
                </div>

                <div class="flex items-stretch gap-2">
                    <button type="submit"
                        id="fetch-btn-<?= $id ?>"
                        <?= $avail === 0 ? 'disabled' : '' ?>
                        class="flex-1 inline-flex items-center justify-center gap-2 py-2.5
                               text-sm font-bold rounded-xl transition-all active:scale-95"
                        style="background:<?= $avail > 0 ? $ac_hex : '#e2e8f0' ?>;
                               color:<?= $avail > 0 ? '#fff' : '#94a3b8' ?>">
                        <?php if ($avail === 0): ?>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0
                                   015.636 5.636m12.728 12.728L5.636 5.636" />
                            </svg>
                            Tidak Ada Data
                        <?php else: ?>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Simpan ke Antrian
                        <?php endif; ?>
                    </button>

                    <button type="button"
                        id="fetch-filter-btn-<?= $id ?>"
                        <?= $avail === 0 ? 'disabled' : '' ?>
                        onclick='open_fetch_filter_modal(<?= $id ?>, <?= json_encode((string) ($pc["pc_label"] ?? "")) ?>, <?= json_encode((string) $tt) ?>, <?= json_encode($job_fetch_filter_setting ?? [], JSON_UNESCAPED_UNICODE) ?>)'
                        title="Pengaturan filter antrian"
                        class="w-11 inline-flex items-center justify-center rounded-xl border transition-all active:scale-95
                               <?= $avail > 0 ? 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' : 'bg-slate-100 text-slate-300 border-slate-200 cursor-not-allowed' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </button>
                </div>
                <p class="text-[10px] text-slate-400">
                    Klik ikon setting untuk filter sumber file, jenis kelamin, dan usia
                </p>
            </form>

            <!-- Info trigger .exe -->
            <div class="bg-slate-50 border border-slate-100 rounded-xl p-3 space-y-1.5">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">
                    Trigger .exe
                </p>
                <div class="flex justify-between text-[11px] items-center">
                    <span class="text-slate-400 font-bold uppercase tracking-wide">Task Type</span>
                    <span class="font-bold uppercase text-xs" style="color:<?= $ac_hex ?>">
                        <?= h($tt) ?>
                    </span>
                </div>
                <?php if ($mode): ?>
                    <div class="flex justify-between text-[11px] items-center">
                        <span class="text-slate-400 font-bold uppercase tracking-wide">Mode</span>
                        <span class="font-black uppercase text-xs text-slate-700"><?= h($mode) ?></span>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /kiri -->
        <!-- KANAN: List peserta -->
        <div class="flex-1 p-4 flex flex-col min-h-0">

            <div class="flex items-start sm:items-center justify-between mb-2 gap-2 flex-wrap">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                    Daftar Peserta
                    <span id="list-count-<?= $id ?>"
                        class="ml-1" style="color:<?= $ac_hex ?>">
                        (<?= count($list) ?>)
                    </span>
                </p>
                <div class="flex flex-wrap items-center gap-2 sm:gap-3 text-[10px] text-slate-400">
                    <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span>Pending
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>Proses
                    </span>
                    <span class="flex items-center gap-1">
                        <svg class="w-3 h-3 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>Sukses
                    </span>
                    <span class="flex items-center gap-1">
                        <svg class="w-3 h-3 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                        </svg>Gagal
                    </span>
                </div>
            </div>

            <!-- Wrapper flex-1 agar selalu mengisi sisa tinggi kolom kanan -->
            <div id="list-wrap-<?= $id ?>" class="flex-1 flex flex-col min-h-0">
                <?php if (empty($list)): ?>

                    <!-- Empty state: flex-1 + border dashed mengisi penuh ke bawah -->
                    <div class="flex-1 flex flex-col items-center justify-center
                            border-2 border-dashed border-slate-200 rounded-xl gap-2 py-8
                            min-h-[160px]">
                        <svg class="w-9 h-9 text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0
                               00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        <p class="text-xs font-semibold text-slate-400">Antrian kosong</p>
                        <p class="text-[11px] text-slate-300 text-center px-4">
                            Pilih jumlah lalu klik Simpan ke Antrian
                        </p>
                    </div>

                <?php else: ?>

                    <div class="overflow-y-auto border border-slate-100 rounded-xl bg-slate-50/30 flex-1"
                        style="max-height:320px; -webkit-overflow-scrolling:touch;">
                        <ul class="divide-y divide-slate-100">
                            <?php foreach ($list as $item):
                                $p  = pd($item['data'] ?? '');
                                $st = $item['st']  ?? 'pending';
                                $em = $item['em']  ?? '';
                                $status_text = $st === 'running' ? 'proses berjalan' : ($st === 'done' ? 'proses selesai' : ($st === 'failed' ? 'proses gagal' : 'menunggu proses'));
                                $status_class = $st === 'running' ? 'text-blue-500' : ($st === 'done' ? 'text-emerald-500' : ($st === 'failed' ? 'text-rose-400' : 'text-slate-400'));
                                $status_line = '[' . date('H:i:s') . '] - ' . $status_text;
                            ?>
                                <li class="group flex items-center gap-2 px-3 py-2 hover:bg-white transition-colors"
                                    data-jqid="<?= (int)$item['jqid'] ?>">
                                    <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
                                        <?php if ($st === 'running'): ?>
                                            <svg class="w-4 h-4 text-blue-500 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                            </svg>
                                        <?php elseif ($st === 'pending'): ?>
                                            <span class="w-2.5 h-2.5 rounded-full bg-amber-400 block"></span>
                                        <?php elseif ($st === 'done'): ?>
                                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                            </svg>
                                        <?php else: ?>
                                            <svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-mono text-[10px] text-slate-400 truncate leading-tight">
                                            <?= h(format_nik((string)$p['nik'])) ?>
                                        </div>
                                        <div class="text-[11px] font-semibold text-slate-700 truncate leading-tight">
                                            <?= h($p['nama']) ?>
                                        </div>
                                        <div class="text-[9px] leading-tight font-mono <?= $status_class ?>">
                                            <?= h($status_line) ?>
                                        </div>
                                        <?php if ($em): ?>
                                            <div class="text-[9px] text-rose-400 truncate" title="<?= h($em) ?>">
                                                <?= h($em) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-shrink-0 flex items-center gap-1">
                                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-md
                                    <?= $st_cls[$st] ?? 'bg-slate-100 text-slate-400' ?>">
                                            <?= $st_lbl[$st] ?? '—' ?>
                                        </span>
                                        <?php if ($st === 'pending'): ?>
                                            <form method="POST" class="inline sm:hidden">
                                                <input type="hidden" name="action" value="pc_delete_item">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="license_key_id" value="<?= $id ?>">
                                                <input type="hidden" name="jq_id" value="<?= (int)$item['jqid'] ?>">
                                                <button type="submit"
                                                    onclick="return confirm('Hapus 1 item dari antrian?')"
                                                    class="p-1.5 text-slate-300 hover:text-rose-500 active:text-rose-500
                                               rounded-lg touch-manipulation">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </form>
                                            <form method="POST" class="hidden sm:inline group-hover:inline">
                                                <input type="hidden" name="action" value="pc_delete_item">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="license_key_id" value="<?= $id ?>">
                                                <input type="hidden" name="jq_id" value="<?= (int)$item['jqid'] ?>">
                                                <button type="submit"
                                                    onclick="return confirm('Hapus 1 item dari antrian?')"
                                                    class="p-1 text-slate-300 hover:text-rose-500 transition-colors rounded">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if (count($list) >= 200): ?>
                        <p class="text-center text-[10px] text-slate-400 mt-1.5">Menampilkan maks 200 item</p>
                    <?php endif; ?>

                <?php endif; ?>
            </div><!-- /list-wrap -->


        </div><!-- /kanan -->


    </div><!-- /card body -->

    <!-- ══ SCHEDULE PANEL ══ -->
    <div class="border-t border-slate-100">

        <!-- Toggle header -->
        <button type="button"
            onclick="toggle_sched_panel(<?= $id ?>)"
            class="w-full flex items-center justify-between px-4 sm:px-5 py-3
                   hover:bg-slate-50 transition-colors group">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-slate-400 group-hover:text-slate-600 transition-colors"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                    Jadwal & Auto-Retry
                </span>
                <!-- Status badge -->
                <span id="sched-badge-<?= $id ?>"
                    class="text-[9px] font-bold px-2 py-0.5 rounded-full
                             <?= ($pc['sched_enabled'] ?? 0) || ($pc['retry_auto'] ?? 0)
                                    ? 'bg-violet-100 text-violet-700'
                                    : 'bg-slate-100 text-slate-400' ?>">
                    <?= ($pc['sched_enabled'] ?? 0) || ($pc['retry_auto'] ?? 0) ? 'AKTIF' : 'OFF' ?>
                </span>
            </div>
            <svg id="sched-chevron-<?= $id ?>"
                class="w-4 h-4 text-slate-400 transition-transform duration-200"
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Panel body (collapsed by default) -->
        <div id="sched-panel-<?= $id ?>"
            class="hidden px-4 sm:px-5 pb-4 pt-1 space-y-4">

            <!-- Countdown info -->
            <div id="sched-countdown-<?= $id ?>"
                class="text-[11px] text-slate-400 font-semibold hidden">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <!-- KIRI: Jadwal Start/Stop -->
                <div class="space-y-3">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Jadwal Otomatis</p>

                    <!-- Sched enabled toggle -->
                    <label class="flex items-center gap-3 cursor-pointer">
                        <div class="relative">
                            <input type="checkbox"
                                id="sched-en-<?= $id ?>"
                                class="sr-only peer"
                                <?= ($pc['sched_enabled'] ?? 0) ? 'checked' : '' ?>
                                onchange="on_sched_toggle(<?= $id ?>)">
                            <div class="w-9 h-5 bg-slate-200 rounded-full peer
                                        peer-checked:bg-violet-500 transition-colors"></div>
                            <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow
                                        transition-transform peer-checked:translate-x-4"></div>
                        </div>
                        <span class="text-xs font-bold text-slate-600">Aktifkan Jadwal</span>
                    </label>

                    <!-- Start time -->
                    <div id="sched-start-row-<?= $id ?>"
                        class="<?= ($pc['sched_enabled'] ?? 0) ? '' : 'opacity-40 pointer-events-none' ?>
                                flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 text-emerald-500 flex-shrink-0"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0
                                   001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-[11px] font-bold text-slate-500 w-14">Start</span>
                        <input type="time"
                            id="sched-start-<?= $id ?>"
                            value="<?= h($pc['sched_start'] ? substr($pc['sched_start'], 0, 5) : '') ?>"
                            class="flex-1 px-2.5 py-1.5 text-sm font-mono border border-slate-200
                                   rounded-xl outline-none focus:border-violet-400 transition-colors bg-white">
                    </div>

                    <!-- Stop time + toggle -->
                    <div id="sched-stop-row-<?= $id ?>"
                        class="<?= ($pc['sched_enabled'] ?? 0) ? '' : 'opacity-40 pointer-events-none' ?>
                                space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <div class="relative">
                                <input type="checkbox"
                                    id="sched-stop-on-<?= $id ?>"
                                    class="sr-only peer"
                                    <?= ($pc['sched_stop_on'] ?? 0) ? 'checked' : '' ?>
                                    onchange="on_stop_toggle(<?= $id ?>)">
                                <div class="w-7 h-4 bg-slate-200 rounded-full peer
                                            peer-checked:bg-rose-400 transition-colors"></div>
                                <div class="absolute top-0.5 left-0.5 w-3 h-3 bg-white rounded-full shadow
                                            transition-transform peer-checked:translate-x-3"></div>
                            </div>
                            <span class="text-[11px] font-bold text-slate-500">Auto Stop</span>
                        </label>
                        <div id="sched-stop-time-row-<?= $id ?>"
                            class="<?= ($pc['sched_stop_on'] ?? 0) ? '' : 'opacity-40 pointer-events-none' ?>
                                    flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 text-rose-400 flex-shrink-0"
                                viewBox="0 0 24 24" fill="currentColor">
                                <rect x="5" y="5" width="14" height="14" rx="2" />
                            </svg>
                            <span class="text-[11px] font-bold text-slate-500 w-14">Stop</span>
                            <input type="time"
                                id="sched-stop-<?= $id ?>"
                                value="<?= h($pc['sched_stop'] ? substr($pc['sched_stop'], 0, 5) : '') ?>"
                                class="flex-1 px-2.5 py-1.5 text-sm font-mono border border-slate-200
                                       rounded-xl outline-none focus:border-rose-400 transition-colors bg-white">
                        </div>
                        <p class="text-[9px] text-slate-300 leading-tight pl-1">
                            * Lintas tengah malam didukung (mis: start 23:00, stop 02:00)
                        </p>
                    </div>
                </div>

                <!-- KANAN: Auto Retry -->
                <div class="space-y-3">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Auto Retry</p>

                    <label class="flex items-center gap-3 cursor-pointer">
                        <div class="relative">
                            <input type="checkbox"
                                id="retry-auto-<?= $id ?>"
                                class="sr-only peer"
                                <?= ($pc['retry_auto'] ?? 0) ? 'checked' : '' ?>
                                onchange="on_retry_toggle(<?= $id ?>)">
                            <div class="w-9 h-5 bg-slate-200 rounded-full peer
                                        peer-checked:bg-amber-400 transition-colors"></div>
                            <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow
                                        transition-transform peer-checked:translate-x-4"></div>
                        </div>
                        <span class="text-xs font-bold text-slate-600">Retry Otomatis</span>
                    </label>

                    <div id="retry-opts-<?= $id ?>"
                        class="<?= ($pc['retry_auto'] ?? 0) ? '' : 'opacity-40 pointer-events-none' ?>
                                space-y-2">
                        <p class="text-[10px] text-slate-400 leading-snug">
                            Job gagal dipindah kembali ke antrian secara otomatis selama proses berjalan.
                        </p>
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-bold text-slate-500 whitespace-nowrap">
                                Interval
                            </span>
                            <input type="number"
                                id="retry-interval-<?= $id ?>"
                                min="30" max="3600"
                                value="<?= (int)($pc['retry_interval'] ?? 300) ?>"
                                class="w-20 px-2.5 py-1.5 text-sm font-mono border border-slate-200
                                       rounded-xl outline-none focus:border-amber-400 transition-colors bg-white">
                            <span class="text-[11px] text-slate-400">detik</span>
                        </div>
                        <div id="retry-last-<?= $id ?>"
                            class="text-[10px] text-slate-400">
                            <?php if ($pc['retry_last'] ?? ''): ?>
                                Terakhir retry: <?= date('H:i:s', strtotime($pc['retry_last'])) ?>
                            <?php else: ?>
                                Belum pernah retry otomatis
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Save button -->
            <div class="flex items-center gap-3 pt-1">
                <button type="button"
                    onclick="save_schedule(<?= $id ?>)"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-violet-600 hover:bg-violet-700
                           text-white rounded-xl text-xs font-bold transition-colors active:scale-95 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M5 13l4 4L19 7" />
                    </svg>
                    Simpan Jadwal
                </button>
                <span id="sched-save-msg-<?= $id ?>"
                    class="text-[11px] font-semibold text-emerald-600 hidden">
                    ✓ Tersimpan
                </span>
            </div>

        </div><!-- /panel body -->
    </div><!-- /schedule panel -->

</div>

<script>
    init_job_card(<?= $id ?>, '<?= $ac_hex ?>');
</script>