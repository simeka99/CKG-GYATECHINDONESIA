<?php
$filter_usia = $filter_usia ?? '';

function is_nik_bpjs_header(string $header_name): bool
{
    $header_text = strtolower(trim($header_name));
    if ($header_text === '')
        return false;

    if (preg_match('/(^|[^a-z0-9])nik([^a-z0-9]|$)/i', $header_text))
        return true;

    if (preg_match('/(^|[^a-z0-9])bpjs([^a-z0-9]|$)/i', $header_text))
        return true;

    return false;
}
?>
<form method="POST" id="bulk_form" class="flex flex-col flex-1 min-h-0">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="upload_id" value="<?= $selected_upload_id ?>">
    <input type="hidden" name="view" value="<?= $view ?>">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

    <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] overflow-hidden flex flex-col flex-1 min-h-0">

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 px-4 sm:px-6 py-4 border-b border-slate-50 flex-shrink-0 bg-white min-w-0 w-full">
            <div class="flex items-center justify-between sm:justify-start gap-4 flex-shrink-0">
                <label class="flex items-center gap-3 cursor-pointer select-none group">
                    <div class="relative flex-shrink-0">
                        <input type="checkbox" id="select_all"
                            class="w-4 h-4 appearance-none border-2 border-slate-300 rounded checked:bg-slate-800 checked:border-slate-800 cursor-pointer transition-all"
                            onchange="toggle_all(this)">
                        <svg id="select_all_icon"
                            class="w-3 h-3 text-white absolute inset-0 m-auto pointer-events-none opacity-0" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24" style="stroke-width:4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <span class="text-[11px] sm:text-xs font-semibold text-slate-500 group-hover:text-slate-800 transition-colors uppercase tracking-wider">Aksi</span>
                </label>

                <div id="bulk_actions" class="hidden">
                    <button type="button" onclick="confirm_bulk_delete()"
                        class="inline-flex items-center gap-2 px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-bold transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        <span class="hidden sm:inline">HAPUS</span>
                        <span id="selected_count"
                            class="min-w-[18px] h-4 flex items-center justify-center px-1 bg-rose-600 text-white rounded text-[10px] font-bold">0</span>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between sm:justify-end gap-2 w-full sm:w-auto min-w-0">
                <?php if ((int) $count_all > 0): ?>
                    <div class="flex items-center justify-start gap-1.5 flex-1 overflow-x-auto [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none] pr-2 sm:pr-0">
                        <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider whitespace-nowrap mr-1 hidden lg:block">Download:</span>
                        <?php
                        $dl_btns = [
                            ['all',    'Semua',  $count_all,    'bg-white text-slate-600 shadow-sm border border-slate-200 hover:bg-slate-50', 'bg-slate-100 text-slate-500'],
                            ['sisa',   'Sisa',   $count_sisa,   'bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-100', 'bg-white/50 text-blue-700'],
                            ['sukses', 'Sukses', $count_sukses, 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-100', 'bg-white/50 text-emerald-700'],
                            ['gagal',  'Gagal',  $count_gagal,  'bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-100', 'bg-white/50 text-rose-700'],
                        ];
                        foreach ($dl_btns as [$mode, $lbl, $cnt, $cls, $badge_cls]):
                            if ($cnt > 0):
                        ?>
                                <a href="data.php?upload_id=<?= $selected_upload_id ?>&dl=<?= $mode ?>" title="Download Excel Data <?= $lbl ?>"
                                    class="inline-flex items-center justify-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-bold transition-all active:scale-95 flex-shrink-0 <?= $cls ?>">
                                    <svg class="w-3 h-3 hidden md:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    <span class="whitespace-nowrap"><?= $lbl ?></span>
                                    <span class="px-1 py-0.5 rounded text-[9px] font-black <?= $badge_cls ?>"><?= number_format($cnt) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="inline-flex items-center justify-center gap-1.5 px-2.5 py-1.5 bg-slate-50 text-slate-300 rounded-lg text-[11px] font-bold cursor-not-allowed flex-shrink-0 border border-slate-100 whitespace-nowrap">
                                    <svg class="w-3 h-3 hidden md:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636" />
                                    </svg><?= $lbl ?>
                                </span>
                        <?php endif;
                        endforeach; ?>
                    </div>
                <?php endif; ?>

                <span class="text-[11px] font-semibold text-slate-500 bg-slate-50 px-3 py-1.5 rounded-xl whitespace-nowrap uppercase tracking-wider hidden sm:block flex-shrink-0 border border-slate-100">
                    Total: <span class="text-slate-800"><?= number_format($total_rows) ?></span>
                </span>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div class="flex-1 flex flex-col items-center justify-center py-20 text-center px-6">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-5 shadow-inner <?= $view === 'sisa' ? 'bg-emerald-50' : ($view === 'antrean' ? 'bg-amber-50' : 'bg-slate-50') ?>">
                    <svg class="w-8 h-8 <?= $view === 'sisa' ? 'text-emerald-400' : ($view === 'antrean' ? 'text-amber-400' : 'text-slate-300') ?>"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h4 class="text-base font-bold text-slate-800 mb-1.5">
                    <?php if (!empty($debug_err)): ?>
                        Query Error
                    <?php elseif ($view === 'sisa' && !$search): ?>
                        Semua Selesai!
                    <?php elseif ($view === 'antrean' && !$search): ?>
                        Antrean Kosong
                    <?php elseif ($search): ?>
                        Pencarian Nihil
                    <?php else: ?>
                        Data Kosong
                    <?php endif; ?>
                </h4>
                <p class="text-slate-400 text-sm max-w-xs mx-auto leading-relaxed">
                    <?php if (!empty($debug_err)): ?>
                        <?= h($debug_err) ?>
                    <?php elseif ($view === 'sisa' && !$search): ?>
                        Hebat! Semua data peserta sudah mulai dikerjakan.
                    <?php elseif ($view === 'antrean' && !$search): ?>
                        Saat ini tidak ada data dalam antrean sinkronisasi.
                    <?php elseif ($search): ?>
                        Tidak ditemukan data yang cocok dengan kata kunci "<?= h($search) ?>".
                    <?php else: ?>
                        Belum ada data yang tersedia untuk ditampilkan.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>
            <div class="data_scroll_area w-full overflow-x-auto" style="flex:1;min-height:0;overflow-y:auto;">
                <table class="text-sm border-separate border-spacing-0 w-full" style="min-width:max-content;">
                    <thead>
                        <tr>
                            <th style="position:sticky;top:0;left:0;z-index:30;background:#fff;"
                                class="w-12 px-4 py-3 border-b border-r border-slate-50 shadow-[4px_0_8px_rgba(0,0,0,0.02)]">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">#</span>
                            </th>
                            <?php foreach ($headers as $hdr): ?>
                                <th style="position:sticky;top:0;z-index:20;background:#fff;"
                                    class="px-4 py-3 border-b border-slate-50 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">
                                    <?= h($hdr) ?>
                                </th>
                            <?php endforeach; ?>
                            <?php if ($selected_upload_id === 0): ?>
                                <th style="position:sticky;top:0;z-index:20;background:#fff;"
                                    class="px-4 py-3 border-b border-slate-50 text-left text-[10px] font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">
                                    Sumber File
                                </th>
                            <?php endif; ?>
                            <th style="position:sticky;top:0;right:0;z-index:30;background:#fff;"
                                class="w-20 px-4 py-3 border-b border-l border-slate-50 text-center text-[10px] font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap shadow-[-4px_0_8px_rgba(0,0,0,0.02)]">
                                OPSI
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($rows as $row_idx => $row): ?>
                            <tr class="group transition-colors duration-150 hover:bg-slate-50">
                                <td style="position:sticky;left:0;z-index:10;background:inherit;"
                                    class="px-4 py-2.5 shadow-[4px_0_8px_rgba(0,0,0,0.02)]">
                                    <input type="checkbox" name="selected[]" value="<?= $row['_id'] ?>"
                                        class="w-3.5 h-3.5 accent-slate-800 row-check cursor-pointer rounded border border-slate-200"
                                        onchange="update_bulk_btn()">
                                </td>

                                <?php foreach ($headers as $hdr):
                                    $val = $row[$hdr] ?? '';
                                    $is_nik = is_nik_bpjs_header((string) $hdr);
                                ?>
                                    <td class="px-4 py-2.5 whitespace-nowrap max-w-[280px]">
                                        <?php if ($is_nik): ?>
                                            <div class="font-mono text-[10px] font-bold text-slate-500 bg-slate-100/70 border border-slate-200 px-1.5 py-0.5 rounded tracking-wider inline-block">
                                                <?= h(format_nik($val)) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-[11px] font-bold text-slate-700 uppercase tracking-wide truncate block"
                                                title="<?= h($val) ?>">
                                                <?= h($val) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>

                                <?php if ($selected_upload_id === 0): ?>
                                    <td class="px-4 py-2.5 whitespace-nowrap max-w-[240px]">
                                        <span class="text-[10px] font-bold text-slate-500 bg-slate-100/70 border border-slate-200 px-1.5 py-0.5 rounded tracking-wide truncate inline-block max-w-[220px]"
                                            title="<?= h($row['_file_name'] ?? '-') ?>">
                                            <?= h($row['_file_name'] ?? '-') ?>
                                        </span>
                                    </td>
                                <?php endif; ?>

                                <td style="position:sticky;right:0;z-index:10;background:inherit;"
                                    class="px-4 py-2.5 border-l border-slate-50 shadow-[-4px_0_8px_rgba(0,0,0,0.02)]">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php
                                        $row_for_js = $row;
                                        foreach ($headers as $hdr) {
                                            if (is_nik_bpjs_header((string) $hdr) && isset($row_for_js[$hdr])) {
                                                $row_for_js[$hdr] = format_nik((string) $row_for_js[$hdr]);
                                            }
                                        }
                                        ?>
                                        <button type="button" data-row-id="<?= $row['_id'] ?>"
                                            data-row="<?= htmlspecialchars(json_encode($row_for_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') ?>"
                                            onclick="open_edit_modal_from_btn(this)"
                                            class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 bg-slate-50 hover:bg-slate-800 hover:text-white transition-all active:scale-95">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                        </button>
                                        <a href="data.php?delete_row=<?= $row['_id'] ?>&upload_id=<?= $selected_upload_id ?>&p=<?= $page ?>&q=<?= urlencode($search) ?>&limit=<?= $per_page ?>&view=<?= $view ?>&csrf_token=<?= urlencode(csrf_token()) ?>"
                                            class="w-7 h-7 flex items-center justify-center rounded-lg text-rose-400 bg-rose-50 hover:bg-rose-500 hover:text-white transition-all active:scale-95">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php if ($total_rows > 0): ?>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-3 px-1">
        <p class="text-xs font-semibold text-slate-500 order-2 sm:order-1 tracking-wider">
            <?php
            $from = number_format(($page - 1) * $per_page + 1);
            $to = number_format(min($page * $per_page, $total_rows));
            $tot = number_format($total_rows);
            ?>
            Tampil <span class="text-slate-700"><?= $from ?>–<?= $to ?></span> dari <span class="text-slate-700"><?= $tot ?></span>
        </p>

        <?php if ($total_pages > 1):
            $s = max(1, $page - 2);
            $e = min($total_pages, $page + 2);
            $btn_cls = 'w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center rounded-xl text-xs font-bold transition-all active:scale-95';
            $btn = fn($p, $label, $disabled) =>
            '<a href="' . ($disabled ? '#' : $base_url . '&p=' . $p) . '"
                class="' . $btn_cls . ' '
                . ($disabled
                    ? 'bg-slate-50 text-slate-300 pointer-events-none'
                    : 'bg-white text-slate-600 hover:bg-slate-800 hover:text-white shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)]')
                . '">' . $label . '</a>';
        ?>
            <nav class="flex flex-wrap items-center justify-center gap-1.5 order-1 sm:order-2 w-full sm:w-auto">
                <?= $btn(1, '&laquo;', $page <= 1) ?>
                <?php if ($s > 1): ?><span class="w-5 sm:w-7 flex justify-center text-slate-300 font-bold">&hellip;</span><?php endif; ?>
                <?php for ($i = $s; $i <= $e; $i++): ?>
                    <a href="<?= $base_url ?>&p=<?= $i ?>"
                        class="<?= $btn_cls ?> <?= $i === $page ? 'bg-slate-800 text-white shadow-md' : 'bg-white text-slate-600 hover:bg-teal-600 hover:text-white shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)]' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <?php if ($e < $total_pages): ?><span class="w-5 sm:w-7 flex justify-center text-slate-300 font-bold">&hellip;</span><?php endif; ?>
                <?= $btn($total_pages, '&raquo;', $page >= $total_pages) ?>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>