<div class="flex flex-col gap-3 mb-1 mt-6 lg:mt-5">
    <?php
    $all_upload_row_count = 0;
    foreach ($all_uploads as $upload_item)
        $all_upload_row_count += (int) ($upload_item['row_count'] ?? 0);
    $bpjs_total_count = (int) $count_sisa + (int) $count_gagal;
    $bpjs_can_sync = $selected_upload_id > 0 && $bpjs_total_count > 0;
    ?>
    
    <!-- Top Row: Filters & Search -->
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-stretch">
        <!-- File Select -->
        <form method="GET" class="md:col-span-4 h-14 flex items-center gap-3 px-4 bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] min-w-0 transition-all focus-within:ring-2 focus-within:ring-teal-100 border border-slate-50">
            <input type="hidden" name="limit" value="<?= $per_page ?>">
            <input type="hidden" name="q" value="<?= h($search) ?>">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">File Terpilih</label>
            <div class="relative flex-1 min-w-0">
                <select name="upload_id" onchange="this.form.submit()"
                    class="w-full h-9 text-xs font-bold text-slate-700 bg-transparent border-0 outline-none cursor-pointer appearance-none pr-6 truncate">
                    <option value="0" <?= $selected_upload_id === 0 ? 'selected' : '' ?>>
                        Semua File (<?= number_format($all_upload_row_count) ?>)
                    </option>
                    <?php foreach($all_uploads as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (int)$u['id']===$selected_upload_id ? 'selected' : '' ?>>
                            <?= h($u['file_name']) ?> (<?= number_format((int)$u['row_count']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>
            </div>
        </form>

        <!-- Search Bar -->
        <form method="GET" class="relative md:col-span-6 h-14 min-w-0">
            <input type="hidden" name="upload_id" value="<?= $selected_upload_id ?>">
            <input type="hidden" name="limit" value="<?= $per_page ?>">
            <input type="hidden" name="view" value="<?= $view ?>">
            <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Cari nama, NIK, atau status data..."
                class="w-full h-14 pl-11 pr-10 text-sm font-semibold bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] border border-slate-50 outline-none text-slate-700 placeholder-slate-300 transition-all focus:ring-2 focus:ring-teal-100">
            <?php if($search): ?>
                <a href="data.php?upload_id=<?= $selected_upload_id ?>&limit=<?= $per_page ?>&view=<?= $view ?>"
                    class="absolute inset-y-0 right-3 flex items-center justify-center w-6 h-6 my-auto rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 transition-colors">&times;</a>
            <?php endif; ?>
        </form>

        <!-- Tampil Select -->
        <form method="GET" class="md:col-span-2 h-14 flex items-center gap-3 px-4 bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] transition-all focus-within:ring-2 focus-within:ring-teal-100 border border-slate-50">
            <input type="hidden" name="upload_id" value="<?= $selected_upload_id ?>">
            <input type="hidden" name="q" value="<?= h($search) ?>">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">Tampil</label>
            <div class="relative flex-1 min-w-0">
                <select name="limit" onchange="this.form.submit()"
                    class="w-full h-9 text-xs font-bold text-slate-700 bg-transparent border-0 outline-none cursor-pointer appearance-none pr-6">
                    <?php foreach($allowed_limits as $lv): ?>
                        <option value="<?= $lv ?>" <?= $lv===$per_page ? 'selected' : '' ?>><?= $lv ?> baris</option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>
            </div>
        </form>
    </div>

    <!-- Bottom Row: Tabs & BPJS Button -->
    <div class="flex flex-col lg:flex-row items-center justify-between gap-3 p-1.5 bg-white border border-slate-50 rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)]">
        
        <div class="flex w-full lg:w-auto lg:flex-1 overflow-x-auto no-scrollbar gap-1 p-0.5">
            <?php
            $tabs = [
                ['all',     'Semua',   $count_all,     'slate'],
                ['sisa',    'Sisa',    $count_sisa,    'blue'],
                ['antrean', 'Antrean', $count_antrean, 'amber'],
                ['sukses',  'Sukses',  $count_sukses,  'emerald'],
                ['gagal',   'Gagal',   $count_gagal,   'rose'],
            ];
            $tab_colors = [
                'slate'   => ['active'=>'bg-slate-800 text-white shadow-md', 'badge'=>'bg-white/20 text-white'],
                'blue'    => ['active'=>'bg-blue-600 text-white shadow-md shadow-blue-500/20',   'badge'=>'bg-white/20 text-white'],
                'teal'    => ['active'=>'bg-teal-600 text-white shadow-md shadow-teal-500/20',   'badge'=>'bg-white/20 text-white'],
                'amber'   => ['active'=>'bg-amber-500 text-white shadow-md shadow-amber-500/20', 'badge'=>'bg-white/20 text-white'],
                'emerald' => ['active'=>'bg-emerald-600 text-white shadow-md shadow-emerald-500/20', 'badge'=>'bg-white/20 text-white'],
                'rose'    => ['active'=>'bg-rose-600 text-white shadow-md shadow-rose-500/20',   'badge'=>'bg-white/20 text-white'],
            ];
            foreach($tabs as [$t_view, $t_label, $t_count, $t_col]):
                $active = $view === $t_view;
                $cls = $tab_colors[$t_col];
            ?>
                <a href="data.php?upload_id=<?= $selected_upload_id ?>&q=<?= urlencode($search) ?>&limit=<?= $per_page ?>&view=<?= $t_view ?>"
                    class="flex-none sm:flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-[10px] sm:text-[11px] font-bold uppercase tracking-wider rounded-xl transition-all whitespace-nowrap
                           <?= $active ? $cls['active'] : 'text-slate-400 hover:text-slate-600 hover:bg-slate-50' ?>">
                    <?= $t_label ?>
                    <span class="px-1.5 py-0.5 rounded text-[9px] font-black <?= $active ? $cls['badge'] : 'bg-slate-100 text-slate-400' ?>">
                        <?= number_format($t_count) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="flex items-center gap-2 p-1.5 lg:p-0 w-full lg:w-auto justify-end border-t border-slate-50 lg:border-0 pt-3 lg:pt-0">
            <button type="button"
                id="btn_bpjs_bulk_sync"
                data-upload-id="<?= (int) $selected_upload_id ?>"
                data-count-sisa="<?= (int) $count_sisa ?>"
                data-count-gagal="<?= (int) $count_gagal ?>"
                title="<?= $selected_upload_id > 0 ? 'Sinkron BPJS untuk file terpilih' : 'Pilih file tertentu untuk sinkron BPJS' ?>"
                class="inline-flex items-center justify-center w-full sm:w-auto gap-1.5 px-4 py-2 rounded-xl text-[11px] font-bold uppercase tracking-wider transition-all
                       <?= $bpjs_can_sync
                            ? 'bg-gradient-to-br from-sky-500 to-sky-600 hover:from-sky-600 hover:to-sky-700 text-white shadow-md shadow-sky-500/20'
                            : 'bg-slate-50 text-slate-300 border border-slate-100 cursor-not-allowed' ?>">
                <svg id="bpjs_bulk_sync_icon" class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <span id="bpjs_bulk_sync_text">Sinkron BPJS</span>
                <span id="bpjs_bulk_sync_badge"
                    class="px-1.5 py-0.5 rounded text-[9px] font-black <?= $bpjs_can_sync ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-400' ?>">
                    <?= number_format($bpjs_total_count) ?>
                </span>
            </button>
        </div>
    </div>

    <div id="bpjs_bulk_scope_modal" class="hidden fixed inset-0 z-50 items-end sm:items-center justify-center bg-slate-900/55 backdrop-blur-sm p-0 sm:p-4">
        <div class="w-full sm:max-w-xl bg-white rounded-t-3xl sm:rounded-3xl border border-slate-100 shadow-2xl overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Sinkronisasi Global</p>
                    <h3 class="text-base font-black text-slate-800">Pilih Target Sinkronisasi BPJS</h3>
                </div>
                <button type="button" id="btn_bpjs_bulk_scope_close" class="w-9 h-9 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all">
                    <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="p-5 space-y-3">
                <button type="button" id="bpjs_bulk_scope_option_sisa" data-scope="sisa"
                    class="w-full text-left px-4 py-3 rounded-2xl border border-slate-200 bg-white hover:border-blue-300 transition-all">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Semua Data Sisa</div>
                            <div class="text-[11px] text-slate-500 mt-1">Data yang belum pernah masuk antrean, sukses, atau gagal.</div>
                        </div>
                        <span class="px-2 py-1 rounded-lg bg-blue-50 text-blue-700 text-[10px] font-black"><?= number_format((int) $count_sisa) ?></span>
                    </div>
                </button>

                <button type="button" id="bpjs_bulk_scope_option_gagal" data-scope="gagal"
                    class="w-full text-left px-4 py-3 rounded-2xl border border-slate-200 bg-white hover:border-rose-300 transition-all">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Data Gagal</div>
                            <div class="text-[11px] text-slate-500 mt-1">Hanya data yang punya riwayat gagal sinkronisasi.</div>
                        </div>
                        <span class="px-2 py-1 rounded-lg bg-rose-50 text-rose-700 text-[10px] font-black"><?= number_format((int) $count_gagal) ?></span>
                    </div>
                </button>

                <div class="px-1 pt-1">
                    <label for="bpjs_bulk_target_mode" class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Jumlah Sinkronisasi</label>
                    <select id="bpjs_bulk_target_mode" class="mt-1 w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-white text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-sky-100 focus:border-sky-300">
                        <option value="10">10 data</option>
                        <option value="50">50 data</option>
                        <option value="100" selected>100 data</option>
                        <option value="all">Semua data</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>

                <div id="bpjs_bulk_target_custom_wrap" class="px-1 hidden">
                    <input
                        id="bpjs_bulk_target_custom"
                        type="number"
                        min="1"
                        step="1"
                        placeholder="Isi jumlah custom, contoh: 250"
                        class="w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-white text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-sky-100 focus:border-sky-300">
                </div>

                <p id="bpjs_bulk_scope_desc" class="text-[11px] text-slate-500 px-1 pt-1"></p>
            </div>

            <div class="px-5 py-4 border-t border-slate-100 flex flex-col sm:flex-row gap-2">
                <button type="button" id="btn_bpjs_bulk_scope_cancel" class="w-full sm:w-auto px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider hover:bg-slate-50 transition-all">Batal</button>
                <button type="button" id="btn_bpjs_bulk_scope_start" class="w-full sm:flex-1 px-4 py-2.5 rounded-xl bg-slate-800 text-white text-xs font-bold uppercase tracking-wider hover:bg-slate-700 transition-all">Mulai Sinkronisasi</button>
            </div>
        </div>
    </div>

    <div id="bpjs_bulk_progress_modal" class="hidden fixed inset-0 z-[60] items-center justify-center bg-slate-900/55 backdrop-blur-sm p-4">
        <div class="w-full max-w-md bg-white rounded-3xl border border-slate-100 shadow-2xl overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Sinkronisasi BPJS</p>
                <h3 id="bpjs_bulk_progress_title" class="text-base font-black text-slate-800">Memproses data...</h3>
            </div>
            <div class="p-5 space-y-4">
                <div class="w-full h-2.5 rounded-full bg-slate-100 overflow-hidden">
                    <div id="bpjs_bulk_progress_fill" class="h-full w-0 bg-gradient-to-r from-sky-500 to-sky-600 transition-all duration-200"></div>
                </div>
                <div class="flex items-center justify-between">
                    <p id="bpjs_bulk_progress_count" class="text-sm font-black text-slate-800">0/0</p>
                    <p id="bpjs_bulk_progress_percent" class="text-xs font-bold text-slate-500">0%</p>
                </div>
                <p id="bpjs_bulk_progress_subtitle" class="text-xs text-slate-500">Menyiapkan sinkronisasi...</p>
            </div>
            <div class="px-5 py-4 border-t border-slate-100">
                <button type="button"
                    id="btn_bpjs_bulk_cancel"
                    class="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-xs font-bold bg-rose-50 text-rose-600 hover:bg-rose-100 transition-all border border-rose-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    <span>Stop Sinkronisasi</span>
                </button>
            </div>
        </div>
    </div>
</div>
