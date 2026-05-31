<?php $partial_base = __DIR__; ?>
<main id="main_wrap" class="flex flex-col gap-3 px-4 lg:px-6 pb-4 lg:pb-6 pt-0 overflow-hidden">
<style>
    @media (min-width: 1024px) {
        #main_wrap {
            overflow: visible !important;
            height: auto !important;
            padding-top: 0.25rem;
        }
    }
    @media (max-width: 1023px) {
        #main_wrap {
            height: auto !important;
            overflow: visible !important;
        }
        #table_card {
            min-height: 400px;
        }
    }
    #table_scroll_wrap::-webkit-scrollbar { width: 4px; height: 4px; }
    #table_scroll_wrap::-webkit-scrollbar-track { background: transparent; }
    #table_scroll_wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    #table_scroll_wrap::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>

    <?php require $partial_base . '/ui_header.php'; ?>

    <?php if (empty($all_uploads)): ?>
        <div class="flex-1 flex items-center justify-center">
            <div class="bg-white border border-slate-200 rounded-2xl p-12 md:p-16 text-center shadow-sm max-w-lg w-full">
                <div class="w-16 h-16 bg-teal-50 border border-teal-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <h3 class="text-xl font-black text-slate-800 mb-2 tracking-tight">Belum ada data peserta</h3>
                <p class="text-slate-500 text-sm mb-8 leading-relaxed">Mulai dengan mengunggah file Excel atau CSV yang berisi data peserta.</p>
                <a href="upload.php"
                    class="inline-flex items-center gap-2 px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-sm font-black transition-all shadow-sm active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                    </svg>
                    Upload Sekarang
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php require $partial_base . '/ui_toolbar.php'; ?>
        <?php require $partial_base . '/ui_table.php'; ?>
    <?php endif; ?>

</main>
<?php require $partial_base . '/ui_modal.php'; ?>
