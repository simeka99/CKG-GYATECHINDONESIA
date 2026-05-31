<?php

/**
 * @var int $selected_upload_id
 * @var string $view
 * @var int $page
 * @var int $per_page
 * @var string $search
 * @var array $headers
 * @var string|null $data_web_base
 */
?>
<div id="edit_modal" class="fixed inset-0 z-50 hidden items-end sm:items-center justify-center bg-slate-900/50 transition-all duration-300">
    <div class="bg-white w-full sm:max-w-3xl sm:mx-4 sm:rounded-2xl rounded-t-2xl shadow-xl ring-1 ring-slate-200 flex flex-col max-h-[95vh] sm:max-h-[88vh] overflow-hidden" style="animation:modal_in .24s ease-out">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-slate-100 text-slate-600 border border-slate-200 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 2c-4.418 0-8 1.79-8 4v2h16v-2c0-2.21-3.582-4-8-4z" />
                    </svg>
                </div>
                <div>
                    <p class="text-base font-bold text-slate-800 leading-tight">Informasi Peserta</p>
                    <p class="text-xs text-slate-500 mt-0.5">ID: <span id="edit_row_label" class="font-semibold text-slate-700">-</span></p>
                </div>
            </div>
            <button type="button" onclick="close_edit_modal()" class="w-9 h-9 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all active:scale-90">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.6" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form method="POST" id="edit_form" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="action" value="edit_row">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="row_id" id="edit_row_id">
            <input type="hidden" name="upload_id" value="<?= $selected_upload_id ?>">
            <input type="hidden" name="view" value="<?= $view ?>">
            <input type="hidden" name="p" value="<?= $page ?>">
            <input type="hidden" name="limit" value="<?= $per_page ?>">
            <input type="hidden" name="q" value="<?= h($search) ?>">

            <div class="overflow-y-auto flex-1 px-5 py-5 no-scrollbar">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3.5">
                    <?php foreach ($headers as $hdr):
                        $hdr_low = strtolower(trim($hdr));
                        if (in_array($hdr_low, ['no', 'no.', 'no ', '#', 'nomor', 'number'], true)) continue;
                        $is_nik = preg_match('/\bnik\b/i', $hdr) || preg_match('/^(no_ktp|no_nik|nomor_nik|patient_nik|pasien_nik)$/i', trim($hdr));
                    ?>
                        <div class="space-y-1 <?= $is_nik ? 'sm:col-span-2' : '' ?>">
                            <label class="block text-[11px] font-semibold text-slate-600"><?= $is_nik ? 'NIK/No. BPJS' : h($hdr) ?></label>
                            <?php if ($is_nik): ?>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <input type="text" name="fields[<?= h($hdr) ?>]" data-field-key="<?= h($hdr) ?>" id="modal_nik_field" autocomplete="off" spellcheck="false" class="modal-field flex-1 px-3.5 py-2.5 text-sm rounded-xl border border-slate-300 bg-white outline-none transition-all focus:border-slate-500 focus:ring-2 focus:ring-slate-100 font-mono font-semibold text-slate-800">
                                    <button type="button" id="btn_bpjs_sync" onclick="sync_bpjs()" class="flex-shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-slate-800 hover:bg-slate-700 active:scale-95 text-white text-xs font-semibold rounded-xl transition-all whitespace-nowrap">
                                        <svg id="bpjs_sync_icon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        <span id="bpjs_sync_text">Sync BPJS</span>
                                    </button>
                                </div>
                                <p id="bpjs_sync_status" class="text-[11px] font-semibold hidden mt-1"></p>
                            <?php else: ?>
                                <input type="text" name="fields[<?= h($hdr) ?>]" data-field-key="<?= h($hdr) ?>" autocomplete="off" spellcheck="false" class="modal-field w-full px-3.5 py-2.5 text-sm rounded-xl border border-slate-300 bg-white outline-none transition-all focus:border-slate-500 focus:ring-2 focus:ring-slate-100 text-slate-800">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-4 border-t border-slate-200 flex-shrink-0">
                <p class="text-[11px] text-slate-500 hidden sm:flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                    Perubahan disimpan saat klik Simpan
                </p>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <button type="button" onclick="close_edit_modal()" class="flex-1 sm:flex-none px-5 py-2.5 text-sm font-semibold text-slate-600 hover:text-slate-800 bg-white border border-slate-300 rounded-xl transition-all active:scale-95">Batal</button>
                    <button type="submit" class="flex-1 sm:flex-none px-6 py-2.5 text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 active:scale-95 rounded-xl transition-all flex items-center justify-center gap-2">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                        Simpan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
    .no-scrollbar::-webkit-scrollbar {
        display: none;
    }

    .no-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    @keyframes modal_in {
        from {
            opacity: 0;
            transform: translateY(18px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (max-width:639px) {
        @keyframes modal_in {
            from {
                opacity: 0;
                transform: translateY(100%);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    }

    @keyframes spin {
        from {
            transform: rotate(0deg)
        }

        to {
            transform: rotate(360deg)
        }
    }
</style>

<script>
    window.DATA_WEB_BASE = "<?= h($data_web_base ?? '/user/data') ?>";
</script>
<script src="<?= h($data_web_base ?? '/user/data') ?>/data.js?v=<?= filemtime(__DIR__ . '/data.js') ?>"></script>