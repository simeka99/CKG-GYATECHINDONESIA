<?php
ob_start();
$page_title = 'Monitor Progress';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$uid = (int) ($_SESSION['user_id'] ?? 0);
ensure_jobs_performance_indexes($db);

// auth_check() sudah dipanggil di header.php — cukup pastikan uid valid
if (!$uid) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

if (!isset($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'render')
        require __DIR__ . '/monitoring/render.php';
    else
        require __DIR__ . '/monitoring/ajax.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/monitoring/actions.php';
    exit;
}

$flash_ok = $_SESSION['flash_success'] ?? '';
$flash_err = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$stmt = $db->prepare("SELECT notif_telegram, telegram_chat_id, notif_whatsapp, whatsapp_number FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$uid]);
$user_row = $stmt->fetch();
$has_tg = !empty($user_row['notif_telegram']) && !empty($user_row['telegram_chat_id']);
$has_wa = !empty($user_row['notif_whatsapp']) && !empty($user_row['whatsapp_number']);
?>

<div id="toast_wrap" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 pointer-events-none"></div>

<!-- Detail Modal -->
<div id="modal_detail"
    class="fixed inset-0 z-[100] hidden bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-50">
            <h3 id="modal_title" class="font-bold text-slate-800 text-base">Detail Job</h3>
            <button onclick="close_modal()"
                class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div id="modal_body" class="px-6 py-4 space-y-3 max-h-[70vh] overflow-y-auto"></div>
        <div class="flex justify-end px-6 py-4 border-t border-slate-100">
            <button onclick="close_modal()"
                class="px-4 py-2 text-sm font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors">
                Tutup
            </button>
        </div>
    </div>
</div>

<main id="main_wrap" class="flex flex-col gap-4 p-4 lg:p-6 overflow-hidden">
    <?php if ($flash_ok): ?>
        <div
            class="flex items-center gap-2.5 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700 text-sm font-semibold flex-shrink-0">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg><?= h($flash_ok) ?>
        </div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
        <div
            class="flex items-center gap-2.5 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-sm font-semibold flex-shrink-0">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg><?= h($flash_err) ?>
        </div>
    <?php endif; ?>

    <!-- Stats Card -->
    <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] p-4 lg:p-5 flex-shrink-0">
        <div class="flex flex-col lg:flex-row gap-4 lg:gap-5 items-center">

            <div class="flex flex-row gap-5 lg:gap-5 justify-around w-full lg:w-auto">
                <!-- Ring Daftar -->
                <div class="flex flex-col items-center gap-1.5 flex-shrink-0">
                    <div class="relative w-20 h-20 lg:w-24 lg:h-24">
                        <svg viewBox="0 0 36 36" class="w-20 h-20 lg:w-24 lg:h-24 -rotate-90">
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e2e8f0" stroke-width="3.2" />
                            <circle id="ring_daftar" cx="18" cy="18" r="15.9" fill="none" stroke="#3b82f6"
                                stroke-width="3.2" stroke-dasharray="0 100" stroke-linecap="round"
                                style="transition:stroke-dasharray .6s ease" />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span id="pct_daftar" class="text-[15px] lg:text-lg font-bold text-blue-700 leading-none">0%</span>
                            <span class="text-[9px] lg:text-[10px] font-bold text-slate-400 mt-0.5">Daftar</span>
                        </div>
                    </div>
                    <div class="text-center mt-1 lg:mt-0">
                        <div class="text-[10px] lg:text-xs font-bold text-blue-700">
                            <span id="ok_daftar">0</span><span class="text-slate-400 font-medium tracking-tighter">/</span><span
                                id="all_daftar">0</span>
                        </div>
                        <div class="text-[9px] lg:text-[10px] text-slate-500 font-semibold uppercase tracking-wider">Pendaftaran</div>
                    </div>
                </div>

                <!-- Ring Layan -->
                <div class="flex flex-col items-center gap-1.5 flex-shrink-0">
                    <div class="relative w-20 h-20 lg:w-24 lg:h-24">
                        <svg viewBox="0 0 36 36" class="w-20 h-20 lg:w-24 lg:h-24 -rotate-90">
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e2e8f0" stroke-width="3.2" />
                            <circle id="ring_layan" cx="18" cy="18" r="15.9" fill="none" stroke="#16a34a" stroke-width="3.2"
                                stroke-dasharray="0 100" stroke-linecap="round"
                                style="transition:stroke-dasharray .6s ease" />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span id="pct_layan" class="text-[15px] lg:text-lg font-bold text-green-700 leading-none">0%</span>
                            <span class="text-[9px] lg:text-[10px] font-bold text-slate-400 mt-0.5">Layan</span>
                        </div>
                    </div>
                    <div class="text-center mt-1 lg:mt-0">
                        <div class="text-[10px] lg:text-xs font-bold text-green-700">
                            <span id="ok_layan">0</span><span class="text-slate-400 font-medium tracking-tighter">/</span><span
                                id="all_layan">0</span>
                        </div>
                        <div class="text-[9px] lg:text-[10px] text-slate-500 font-semibold uppercase tracking-wider">Pelayanan</div>
                    </div>
                </div>
            </div>

            <div class="hidden lg:block w-px self-stretch bg-slate-100 mx-1"></div>
            <div class="block lg:hidden h-px w-full bg-slate-100 mt-1 mb-2"></div>

            <div class="flex-1 w-full space-y-3">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="bg-amber-50 rounded-xl p-3">
                        <div class="flex items-center gap-2 mb-1.5">
                            <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-[10px] font-semibold text-amber-700/60 uppercase tracking-wider">Pending</span>
                        </div>
                        <div id="stat_pending" class="text-2xl font-bold text-amber-700">—</div>
                    </div>
                    <div class="bg-blue-50 rounded-xl p-3">
                        <div class="flex items-center gap-2 mb-1.5">
                            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <span class="text-[10px] font-semibold text-blue-700/60 uppercase tracking-wider">Running</span>
                        </div>
                        <div id="stat_running" class="text-2xl font-bold text-blue-700">—</div>
                    </div>
                    <div class="bg-emerald-50 rounded-xl p-3">
                        <div class="flex items-center gap-2 mb-1.5">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-[10px] font-semibold text-emerald-700/60 uppercase tracking-wider">Sukses</span>
                        </div>
                        <div id="stat_success" class="text-2xl font-bold text-emerald-700">—</div>
                    </div>
                    <div class="bg-rose-50 rounded-xl p-3">
                        <div class="flex items-center gap-2 mb-1.5">
                            <svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-[10px] font-semibold text-rose-700/60 uppercase tracking-wider">Gagal</span>
                        </div>
                        <div id="stat_failed" class="text-2xl font-bold text-rose-700">—</div>
                    </div>
                </div>

                <?php if ($has_tg || $has_wa): ?>
                    <div
                        class="flex flex-wrap items-center gap-2 px-3.5 py-2.5 bg-emerald-50 border border-emerald-200 rounded-xl">
                        <span class="text-[11px] font-semibold text-emerald-700">Notifikasi aktif via</span>
                        <?php if ($has_wa): ?>
                            <span
                                class="inline-flex items-center gap-1 px-2 py-0.5 bg-white border border-emerald-200 rounded-full text-[10px] font-black text-green-700">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                                </svg>
                                WhatsApp
                            </span>
                        <?php endif; ?>
                        <?php if ($has_tg): ?>
                            <span
                                class="inline-flex items-center gap-1 px-2 py-0.5 bg-white border border-sky-200 rounded-full text-[10px] font-black text-sky-600">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L7.196 13.986l-2.937-.918c-.638-.203-.651-.638.136-.943l11.44-4.41c.532-.194.998.13.059.506z" />
                                </svg>
                                Telegram
                            </span>
                        <?php endif; ?>
                        <a href="settings.php" class="ml-auto text-[10px] font-black text-teal-600 hover:underline">Ubah
                            →</a>
                    </div>
                <?php else: ?>
                    <div
                        class="flex flex-wrap items-center gap-2 px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl">
                        <span class="text-[11px] text-slate-400 font-semibold">Notifikasi belum aktif —</span>
                        <a href="settings.php" class="text-[11px] font-black text-teal-600 hover:underline">Aktifkan di
                            Pengaturan →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tab Table Card -->
    <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] flex flex-col flex-1 min-h-0 overflow-hidden">

        <!-- Tabs -->
        <div class="flex border-b border-slate-100 flex-shrink-0">
            <?php foreach (
                [
                    ['sukses', 'Sukses', 'emerald', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ['gagal', 'Gagal', 'rose', 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'],
                ] as [$panel, $label, $color, $path]
            ): ?>
                <button onclick="switch_tab('<?= $panel ?>')" id="tab_<?= $panel ?>" data-panel="<?= $panel ?>"
                    data-color="<?= $color ?>" class="tab_btn flex-1 flex items-center justify-center gap-1.5 px-3 py-3.5
                           text-xs font-bold uppercase tracking-wide border-b-2 border-transparent
                           text-slate-400 hover:text-slate-600 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $path ?>" />
                    </svg>
                    <?= $label ?>
                    <span id="badge_<?= $panel ?>"
                        class="ml-0.5 px-1.5 py-0.5 rounded-full text-[9px] font-black bg-slate-100 text-slate-400"></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Retry toolbar -->
        <div id="toolbar_gagal"
            class="hidden items-center gap-2 px-4 py-2.5 bg-amber-50 border-b border-amber-100 flex-shrink-0">
            <span id="label_retry" class="text-[11px] font-semibold text-amber-700"></span>
            <button id="btn_retry_all" onclick="retry_all()" disabled
                class="ml-auto px-3 py-1.5 rounded-lg text-[10px] font-black border transition-colors bg-slate-50 text-slate-300 border-slate-100 cursor-not-allowed">
                Tidak Ada yang Bisa Di-retry
            </button>
        </div>

        <!-- ===== PANEL SUKSES ===== -->
        <div id="panel_sukses" class="tab_panel hidden flex flex-col flex-1 min-h-0">
            <div
                class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-slate-100 bg-slate-50/60 flex-shrink-0">
                <div class="relative">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input type="search" id="search_sukses" placeholder="Cari NIK / Nama…"
                        oninput="debounce_load('sukses')"
                        class="pl-8 pr-3 py-1.5 text-xs border border-slate-200 rounded-lg bg-white text-slate-700 placeholder-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-300 w-44">
                </div>
                <select id="filter_status_sukses" onchange="load_table('sukses')"
                    class="text-xs border border-slate-200 rounded-lg px-2.5 py-1.5 bg-white text-slate-600 focus:outline-none focus:ring-2 focus:ring-slate-300">
                    <option value="">Semua Status</option>
                    <option value="TERDAFTAR_BARU">Terdaftar Baru</option>
                    <option value="TERDAFTAR">Terdaftar</option>
                    <option value="DILAYANI">Dilayani</option>
                    <option value="SUDAH_MENERIMA_LAYANAN">Sudah Menerima Layanan</option>
                    <option value="VALIDASI_TIDAK_VALID">Validasi Tidak Valid</option>
                </select>
                <select id="per_page_sukses" onchange="change_per_page('sukses')"
                    class="text-xs border border-slate-200 rounded-lg px-2.5 py-1.5 bg-white text-slate-600 focus:outline-none focus:ring-2 focus:ring-slate-300">
                    <option value="25">25 baris</option>
                    <option value="50">50 baris</option>
                    <option value="100">100 baris</option>
                </select>
                <div class="flex gap-1.5 ml-auto flex-wrap">
                    <button onclick="send_wa('sukses')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black bg-green-600 hover:bg-green-700 text-white transition-colors">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                        </svg>WA
                    </button>
                    <button onclick="export_table('sukses','xlsx')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black bg-emerald-600 hover:bg-emerald-700 text-white transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>XLSX
                    </button>
                    <button onclick="export_table('sukses','pdf')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black bg-rose-600 hover:bg-rose-700 text-white transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>PDF
                    </button>
                    <button onclick="delete_all('sukses')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black bg-slate-700 hover:bg-red-600 text-white transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>Hapus Semua
                    </button>
                </div>
            </div>
            <div class="monitor_scroll_area overflow-x-auto overflow-y-auto flex-1 min-h-0">
                <table class="w-full text-sm table-fixed">
                    <thead class="sticky top-0 z-10">
                        <tr
                            class="bg-white border-b border-slate-50 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                            <th class="px-3 py-3 text-left w-8">NO</th>
                            <th class="px-3 py-3 text-left w-[160px]">Peserta</th>
                            <th class="px-3 py-3 text-left w-[90px]">PC</th>
                            <th class="px-3 py-3 text-left w-[72px]">Tipe</th>
                            <th class="px-3 py-3 text-left w-[110px]">Status</th>
                            <th class="px-3 py-3 text-left w-[120px]">Waktu</th>
                            <th class="px-3 py-3 text-center w-[76px]">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_sukses">
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-xs text-slate-300">Memuat…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div id="pagination_sukses"
                class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 bg-white flex-shrink-0">
                <span id="info_sukses" class="text-[10px] sm:text-xs text-slate-400 text-center sm:text-left w-full sm:w-auto break-words">—</span>
                <div id="btns_sukses" class="flex justify-center gap-1 flex-wrap w-full sm:w-auto"></div>
            </div>
        </div>

        <!-- ===== PANEL GAGAL ===== -->
        <div id="panel_gagal" class="tab_panel hidden flex flex-col flex-1 min-h-0">
            <div
                class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-slate-100 bg-slate-50/60 flex-shrink-0">
                <div class="relative">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input type="search" id="search_gagal" placeholder="Cari NIK / Nama…"
                        oninput="debounce_load('gagal')"
                        class="pl-8 pr-3 py-1.5 text-xs border border-slate-200 rounded-lg bg-white text-slate-700 placeholder-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-300 w-44">
                </div>
                <select id="filter_error_gagal" onchange="load_table('gagal')"
                    class="text-xs border border-slate-200 rounded-lg px-2.5 py-1.5 bg-white text-slate-600 focus:outline-none focus:ring-2 focus:ring-slate-300">
                    <option value="">Semua Error</option>
                    <option value="SISTEM_MENOLAK">Sistem Menolak</option>
                    <option value="DUKCAPIL_UPDATE">Dukcapil Update</option>
                    <option value="DUKCAPIL">Dukcapil Error</option>
                    <option value="DATA_TIDAK_DITEMUKAN">Tidak Ditemukan</option>
                    <option value="VALIDASI_TIDAK_VALID">Validasi Tidak Valid</option>
                    <option value="VALIDASI_PESERTA_WALI_TIDAK_VALID">Validasi Wali Tidak Valid</option>
                    <option value="SUDAH_TERDAFTAR">Sudah Terdaftar</option>
                    <option value="SUDAH_MENERIMA_LAYANAN">Sudah Menerima Layanan</option>
                    <option value="BATAS_KIRIM_RAPOR_HABIS">Batas Kirim Rapor Habis</option>
                    <option value="MANUAL_GAGAL">Manual Gagal</option>
                    <option value="DATA_TIDAK_VALID">Data Tidak Valid</option>
                    <option value="NOT_IN_LIST">Tidak di Daftar</option>
                </select>
                <select id="filter_src_gagal" onchange="load_table('gagal')"
                    class="text-xs border border-slate-200 rounded-lg px-2.5 py-1.5 bg-white text-slate-600 focus:outline-none focus:ring-2 focus:ring-slate-300">
                    <option value="" selected>Aktif + Arsip</option>
                    <option value="aktif">Aktif</option>
                    <option value="arsip">Arsip</option>
                </select>
                <select id="per_page_gagal" onchange="change_per_page('gagal')"
                    class="text-xs border border-slate-200 rounded-lg px-2.5 py-1.5 bg-white text-slate-600 focus:outline-none focus:ring-2 focus:ring-slate-300">
                    <option value="25">25 baris</option>
                    <option value="50">50 baris</option>
                    <option value="100">100 baris</option>
                </select>
                <div class="flex gap-1.5 ml-auto flex-wrap">
                    <button onclick="send_wa('gagal')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black bg-green-600 hover:bg-green-700 text-white transition-colors">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                        </svg>WA
                    </button>
                    <button onclick="export_table('gagal','xlsx')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black bg-emerald-600 hover:bg-emerald-700 text-white transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>XLSX
                    </button>
                    <button onclick="export_table('gagal','pdf')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black bg-rose-600 hover:bg-rose-700 text-white transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>PDF
                    </button>
                    <button onclick="delete_all('gagal')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black bg-slate-700 hover:bg-red-600 text-white transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>Hapus Semua
                    </button>
                </div>
            </div>
            <div class="monitor_scroll_area overflow-x-auto overflow-y-auto flex-1 min-h-0">
                <table class="w-full text-sm table-fixed">
                    <thead class="sticky top-0 z-10">
                        <tr
                            class="bg-white border-b border-slate-50 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                            <th class="px-3 py-3 text-left w-8">NO</th>
                            <th class="px-3 py-3 text-left w-[150px]">Peserta</th>
                            <th class="px-3 py-3 text-left w-[80px]">PC</th>
                            <th class="px-3 py-3 text-left w-[65px]">Tipe</th>
                            <th class="px-3 py-3 text-left w-[65px]">Sumber</th>
                            <th class="px-3 py-3 text-left w-[130px]">Keterangan</th>
                            <th class="px-3 py-3 text-left w-[110px]">Waktu</th>
                            <th class="px-3 py-3 text-center w-[80px]">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_gagal">
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-xs text-slate-300">Memuat…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div id="pagination_gagal"
                class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 bg-white flex-shrink-0">
                <span id="info_gagal" class="text-[10px] sm:text-xs text-slate-400 text-center sm:text-left w-full sm:w-auto break-words">—</span>
                <div id="btns_gagal" class="flex justify-center gap-1 flex-wrap w-full sm:w-auto"></div>
            </div>
        </div>

    </div>
</main>

<script>
    window.export_libs_loaded = false;
    window.export_libs_loading = false;
    window.export_libs_callbacks = [];
    window.ensure_export_libs = function(cb) {
        if (window.export_libs_loaded) {
            cb();
            return;
        }
        window.export_libs_callbacks.push(cb);
        if (window.export_libs_loading) return;
        window.export_libs_loading = true;
        var urls = [
            'https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js'
        ];
        var idx = 0;

        function load_next() {
            if (idx >= urls.length) {
                window.export_libs_loaded = true;
                window.export_libs_callbacks.forEach(function(fn) {
                    fn();
                });
                window.export_libs_callbacks = [];
                return;
            }
            var s = document.createElement('script');
            s.src = urls[idx];
            s.onload = function() {
                idx++;
                load_next();
            };
            document.head.appendChild(s);
        }
        load_next();
    };

    const HAS_WA = <?= json_encode((bool) $has_wa) ?>;
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
    const MONITOR_SCOPE_MODE = <?= json_encode(get_scope_mode()) ?>;
</script>
<script src="monitoring/script.js?v=<?= filemtime(__DIR__ . '/monitoring/script.js') ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
