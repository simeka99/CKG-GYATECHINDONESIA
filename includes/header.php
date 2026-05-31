<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$_area = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? 'admin' : 'operator';
auth_check($_area);

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
}

$user = current_user();
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$current_uri = $_SERVER['REQUEST_URI'] ?? '';
$base = (string) APP_URL;

function asset_v($file)
{
    $path = $_SERVER['DOCUMENT_ROOT'] . $file;
    return file_exists($path) ? filemtime($path) : date('Ymd');
}
function nav_active($page = '', $uri = '')
{
    global $current_page, $current_dir, $current_uri;
    if ($page !== '' && $current_page === $page)
        return 'active';
    if ($uri !== '' && strpos($current_uri, $uri) !== false)
        return 'active';
    return '';
}
function nav_dashboard_active($dir)
{
    global $current_page, $current_dir;
    return ($current_page === 'index' && $current_dir === $dir) ? 'active' : '';
}
function user_scope_url(string $base, string $page, string $scope_mode): string
{
    $clean_scope_mode = in_array($scope_mode, ['umum', 'sekolah'], true) ? $scope_mode : 'umum';
    return rtrim($base, '/') . '/user/' . $page . '.php?scope=' . $clean_scope_mode;
}
function nav_scope_active(string $page, string $scope_mode): string
{
    global $current_page;
    $current_scope_mode = get_scope_mode();
    if ($current_page !== $page)
        return '';
    return $current_scope_mode === $scope_mode ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?= $page_title ?? 'RMIK Medical Record' ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $base ?>/favicon.svg?v=<?= asset_v('/favicon.svg') ?>">
    <link rel="shortcut icon" href="<?= $base ?>/favicon.svg?v=<?= asset_v('/favicon.svg') ?>">
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script src="https://cdn.tailwindcss.com?v=3.4.1"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
            overflow-x: hidden;
        }

        /* CSS Sidebar Desktop */
        .sl {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .75rem 1rem;
            border-radius: .75rem;
            font-size: .875rem;
            font-weight: 600;
            color: #64748b;
            transition: all .2s;
            text-decoration: none;
            margin-bottom: 0.25rem;
        }

        .sl:hover,
        .sl.active {
            background: #f0fdf9;
            color: #0d9488;
        }

        .sl.active {
            font-weight: 800;
        }

        .sl:hover svg,
        .sl.active svg {
            color: #0d9488;
        }

        .sl svg {
            width: 1.25rem;
            height: 1.25rem;
            color: #94a3b8;
            transition: color .2s;
        }

        .ss {
            display: block;
            font-size: .7rem;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .1em;
            padding: 0 1rem;
            margin: 1.5rem 0 .5rem;
        }

        .scope_menu summary {
            list-style: none;
            cursor: pointer;
        }

        .scope_menu summary::-webkit-details-marker {
            display: none;
        }

        .scope_submenu {
            margin: .25rem 0 .35rem 2rem;
            display: flex;
            flex-direction: column;
            gap: .25rem;
        }

        .scope_subitem {
            display: block;
            padding: .45rem .7rem;
            border-radius: .6rem;
            font-size: .8rem;
            font-weight: 700;
            color: #64748b;
            text-decoration: none;
            transition: all .2s;
        }

        .scope_subitem:hover,
        .scope_subitem.active {
            background: #f0fdf9;
            color: #0d9488;
        }

        .scope_chevron {
            margin-left: auto;
            transition: transform .2s;
            color: #94a3b8;
        }

        .scope_menu[open] .scope_chevron {
            transform: rotate(90deg);
        }

        #sidebar::-webkit-scrollbar {
            width: 4px;
        }

        #sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        #sidebar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        /* CSS Bottom Nav Mobile */
        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            transition: all .2s;
            position: relative;
            height: 100%;
        }

        .mobile-nav-item.active {
            color: #059669;
        }

        .mobile-nav-item svg {
            width: 1.5rem;
            height: 1.5rem;
            transition: transform .2s;
        }

        .mobile-nav-item:active svg {
            transform: scale(0.9);
        }

        .mobile-nav-item.active::after {
            content: "";
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 3px;
            background: #059669;
            border-radius: 0 0 4px 4px;
        }
    </style>
</head>

<body class="text-slate-800 antialiased bg-slate-50">

    <div id="sideOverlay" onclick="closeSidebar()" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-40 transition-opacity">
    </div>

    <!-- Mobile Topbar -->
    <div id="topbar"
        class="lg:hidden sticky top-0 z-30 bg-white/95 backdrop-blur-md border-b border-slate-200 shadow-[0_2px_10px_-3px_rgba(0,0,0,0.05)] px-4 py-3 flex items-center justify-between">

        <div class="flex items-center gap-3.5 select-none text-left">
            <div class="w-10 h-10 bg-gradient-to-br from-teal-500 to-teal-600 rounded-xl flex items-center justify-center shadow-md shadow-teal-500/20 flex-shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
            </div>
            <div class="flex flex-col justify-center">
                <div class="flex items-baseline gap-1.5 mb-1">
                    <span class="text-xl font-black text-teal-600 tracking-tight leading-none">RMIK</span>
                    <span class="text-[11px] font-extrabold text-slate-500 uppercase tracking-widest leading-none">Medical Record</span>
                </div>
                <div class="text-[13px] font-bold text-slate-600 tracking-tight leading-none">
                    <?= htmlspecialchars(explode(' —', $page_title ?? 'Dashboard')[0]) ?>
                </div>
            </div>
        </div>

        <div class="relative flex-shrink-0">
            <button type="button" id="topAvatarBtn" onclick="toggleTopMenu()"
                class="w-9 h-9 bg-teal-50 border border-teal-100/50 rounded-full flex items-center justify-center hover:bg-teal-100 transition-colors shadow-sm">
                <span class="text-teal-700 font-bold text-sm select-none">
                    <?= strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1)) ?>
                </span>
            </button>

            <div id="topMenu"
                class="hidden absolute top-full right-0 mt-3 w-60 bg-white border border-slate-100 rounded-2xl shadow-[0_10px_40px_-10px_rgba(0,0,0,0.15)] overflow-hidden z-50 animate-[fadeIn_0.15s_ease-out]">
                <div class="px-5 py-4 bg-slate-50/80 border-b border-slate-100">
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider mb-1 uppercase">Masuk Sebagai</div>
                    <div class="text-sm font-bold text-slate-800 truncate">
                        <?= h($user['full_name'] ?: $user['username']) ?>
                    </div>
                    <div class="text-xs text-slate-500 font-medium truncate mt-0.5">@<?= h($user['username']) ?></div>
                    <?php if ($user['role'] === 'admin'): ?>
                        <span class="mt-2 text-[10px] font-bold px-2 py-0.5 rounded inline-block bg-teal-50 text-teal-600 border border-teal-100">Developer</span>
                    <?php else: ?>
                        <span class="mt-2 text-[10px] font-bold px-2 py-0.5 rounded inline-block bg-slate-100 text-slate-600 border border-slate-200">Operator</span>
                    <?php endif; ?>
                </div>

                <?php $settings_url = $user['role'] === 'admin' ? $base . '/admin/settings.php' : $base . '/user/settings.php'; ?>
                <a href="<?= $settings_url ?>"
                    class="flex items-center gap-3 px-5 py-3 text-sm font-bold text-slate-600 hover:bg-slate-50 hover:text-teal-600 transition-colors">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    Pengaturan
                </a>
                <a href="<?= $base ?>/auth/logout.php?area=<?= $_area ?>"
                    class="flex items-center gap-3 px-5 py-3.5 text-sm font-bold text-rose-600 hover:bg-rose-50 transition-colors border-t border-slate-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Keluar
                </a>
            </div>
        </div>
    </div>

    <div class="flex min-h-screen">

        <!-- Sidebar -->
        <aside id="sidebar" class="hidden lg:flex w-64 bg-white border-r border-slate-200 shadow-[2px_0_12px_rgba(0,0,0,0.02)] flex-col fixed h-full z-50 transition-transform lg:translate-x-0">
            <div class="px-6 py-6 flex-shrink-0 flex items-center gap-3.5">
                <div
                    class="w-9 h-9 bg-gradient-to-br from-teal-500 to-teal-600 rounded-xl flex items-center justify-center shadow-sm shadow-teal-500/20">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
                <div>
                    <div class="text-[17px] font-bold text-slate-800 tracking-tight leading-none mb-1">RMIK</div>
                    <div class="text-[11px] uppercase font-bold text-slate-400 tracking-widest leading-none">Medical Record</div>
                </div>
            </div>
            <nav id="sideNav" class="flex-1 px-4 py-5 overflow-y-auto space-y-1">
                <?php if (is_admin()): ?>
                    <span class="ss">Admin Panel</span>
                    <a href="<?= $base ?>/admin/" class="sl <?= nav_dashboard_active('admin') ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg> Dashboard
                    </a>
                    <a href="<?= $base ?>/admin/users.php" class="sl <?= nav_active('users') ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg> Kelola Operator
                    </a>
                    <a href="<?= $base ?>/admin/licenses.php" class="sl <?= nav_active('licenses') ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg> License Keys
                    </a>
                    <a href="<?= $base ?>/admin/monitor.php" class="sl <?= nav_active('monitor') ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg> Monitor Proses
                    </a>
                    <a href="<?= $base ?>/admin/settings.php" class="sl <?= nav_active('settings') ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg> Pengaturan
                    </a>
                <?php else: ?>
                    <?php
                    $upload_umum_url = user_scope_url($base, 'upload', 'umum');
                    $data_umum_url = user_scope_url($base, 'data', 'umum');
                    $pelayanan_umum_url = user_scope_url($base, 'pelayanan', 'umum');
                    $jobs_umum_url = user_scope_url($base, 'jobs', 'umum');
                    $monitor_umum_url = user_scope_url($base, 'monitor', 'umum');

                    $upload_sekolah_url = user_scope_url($base, 'upload', 'sekolah');
                    $data_sekolah_url = user_scope_url($base, 'data', 'sekolah');
                    $pelayanan_sekolah_url = user_scope_url($base, 'pelayanan', 'sekolah');
                    $jobs_sekolah_url = user_scope_url($base, 'jobs', 'sekolah');
                    $monitor_sekolah_url = user_scope_url($base, 'monitor', 'sekolah');

                    $upload_scope_group_open = $current_page === 'upload';
                    $data_scope_group_open = $current_page === 'data';
                    $pelayanan_scope_group_open = $current_page === 'pelayanan';
                    $jobs_scope_group_open = $current_page === 'jobs';
                    $monitor_scope_group_open = $current_page === 'monitor';
                    ?>
                    <span class="ss">Panel Saya</span>
                    <a href="<?= $base ?>/user/" class="sl <?= nav_dashboard_active('user') ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg> Dashboard
                    </a>
                    <span class="ss">Data Pasien</span>
                    <details class="scope_menu" <?= $upload_scope_group_open ? 'open' : '' ?>>
                        <summary class="sl <?= $upload_scope_group_open ? 'active' : '' ?>">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg> Upload Data
                            <svg class="scope_chevron w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </summary>
                        <div class="scope_submenu">
                            <a href="<?= $upload_umum_url ?>" class="scope_subitem <?= nav_scope_active('upload', 'umum') ?>">CKG Umum</a>
                            <a href="<?= $upload_sekolah_url ?>" class="scope_subitem <?= nav_scope_active('upload', 'sekolah') ?>">CKG Sekolah</a>
                        </div>
                    </details>
                    <details class="scope_menu" <?= $data_scope_group_open ? 'open' : '' ?>>
                        <summary class="sl <?= $data_scope_group_open ? 'active' : '' ?>">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 10h18M3 14h18M10 4v16M6 4h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2z" />
                            </svg> Data Peserta
                            <svg class="scope_chevron w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </summary>
                        <div class="scope_submenu">
                            <a href="<?= $data_umum_url ?>" class="scope_subitem <?= nav_scope_active('data', 'umum') ?>">CKG Umum</a>
                            <a href="<?= $data_sekolah_url ?>" class="scope_subitem <?= nav_scope_active('data', 'sekolah') ?>">CKG Sekolah</a>
                        </div>
                    </details>
                    <details class="scope_menu" <?= $pelayanan_scope_group_open ? 'open' : '' ?>>
                        <summary class="sl <?= $pelayanan_scope_group_open ? 'active' : '' ?>">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg> Pelayanan
                            <svg class="scope_chevron w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </summary>
                        <div class="scope_submenu">
                            <a href="<?= $pelayanan_umum_url ?>" class="scope_subitem <?= nav_scope_active('pelayanan', 'umum') ?>">CKG Umum</a>
                            <a href="<?= $pelayanan_sekolah_url ?>" class="scope_subitem <?= nav_scope_active('pelayanan', 'sekolah') ?>">CKG Sekolah</a>
                        </div>
                    </details>
                    <span class="ss">Automasi</span>
                    <details class="scope_menu" <?= $jobs_scope_group_open ? 'open' : '' ?>>
                        <summary class="sl <?= $jobs_scope_group_open ? 'active' : '' ?>">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg> Jobdesk Control
                            <svg class="scope_chevron w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </summary>
                        <div class="scope_submenu">
                            <a href="<?= $jobs_umum_url ?>" class="scope_subitem <?= nav_scope_active('jobs', 'umum') ?>">CKG Umum</a>
                            <a href="<?= $jobs_sekolah_url ?>" class="scope_subitem <?= nav_scope_active('jobs', 'sekolah') ?>">CKG Sekolah</a>
                        </div>
                    </details>
                    <details class="scope_menu" <?= $monitor_scope_group_open ? 'open' : '' ?>>
                        <summary class="sl <?= $monitor_scope_group_open ? 'active' : '' ?>">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg> Monitor Progress
                            <svg class="scope_chevron w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </summary>
                        <div class="scope_submenu">
                            <a href="<?= $monitor_umum_url ?>" class="scope_subitem <?= nav_scope_active('monitor', 'umum') ?>">CKG Umum</a>
                            <a href="<?= $monitor_sekolah_url ?>" class="scope_subitem <?= nav_scope_active('monitor', 'sekolah') ?>">CKG Sekolah</a>
                        </div>
                    </details>
                    <span class="ss">Perangkat</span>
                    <a href="<?= $base ?>/user/licenses.php" class="sl <?= nav_active('licenses') ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg> PC & Lisensi
                    </a>
                    <a href="<?= $base ?>/user/settings.php" class="sl <?= nav_active('settings') ?>">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg> Pengaturan
                    </a>
                <?php endif; ?>
            </nav>
        </aside>

        <div id="mainWrap" class="lg:ml-64 flex-1 flex flex-col min-h-screen min-w-0 pb-24 lg:pb-0">

            <!-- Desktop Appbar -->
            <header class="hidden lg:flex sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-slate-200 shadow-[0_2px_10px_-3px_rgba(0,0,0,0.05)] px-8 py-4 items-center justify-between">
                <div>
                    <h1 class="text-[19px] font-bold text-slate-800 tracking-tight">
                        <?= htmlspecialchars(explode(' —', $page_title ?? 'Dashboard')[0]) ?>
                    </h1>
                    <p class="text-[11px] text-slate-500 font-medium mt-0.5 uppercase tracking-wider">
                        <?php
                        if (is_admin()) {
                            echo 'Mode Administrator Developer';
                        } else {
                            $cp = basename($_SERVER['PHP_SELF']);
                            $subs = [
                                'index.php' => 'Panel kontrol automasi utama',
                                'upload.php' => 'Unggah data antrean pasien',
                                'data.php' => 'Kelola data hasil unggahan',
                                'pelayanan.php' => 'Kelola bank pertanyaan pelayanan dari file excel',
                                'monitor.php' => 'Pantau progres realtime',
                                'jobs.php' => 'Kelola eksekusi antrean per PC',
                                'licenses.php' => 'Manajemen lisensi perangkat',
                                'settings.php' => 'Konfigurasi profil & notifikasi'
                            ];
                            echo $page_subtitle ?? $subs[$cp] ?? 'Panel Akses Sistem';
                        }
                        ?>
                    </p>
                </div>
                <div class="flex items-center gap-5">
                    <?php if (!is_admin()):
                        $days = subscription_days_left($user['id']);
                        $is_quota = ($user['subscription_type'] ?? '') === 'quota';
                        $rem = $is_quota ? max(0, (int)($user['quota_total'] ?? 0) - (int)($user['quota_used'] ?? 0)) : 0;
                        if (!$is_quota && $days !== null && $days <= 3) {
                            $bw = 'bg-rose-50 border-rose-200';
                            $bl = 'text-rose-500';
                            $bv = 'text-rose-600';
                        } elseif (!$is_quota && $days !== null && $days <= 7) {
                            $bw = 'bg-amber-50 border-amber-200';
                            $bl = 'text-amber-500';
                            $bv = 'text-amber-600';
                        } else {
                            $bw = 'bg-teal-50 border-teal-100';
                            $bl = 'text-teal-500';
                            $bv = 'text-teal-700';
                        }
                    ?>
                        <div class="px-4 py-1.5 rounded-xl border <?= $bw ?> hidden xl:block">
                            <div class="flex flex-col">
                                <?php if ($is_quota): ?>
                                    <span class="text-[9px] font-black uppercase tracking-wider text-blue-500">Sisa Kuota
                                        NIK</span>
                                    <span
                                        class="text-sm font-extrabold text-blue-700 leading-none mt-0.5"><?= number_format($rem) ?>
                                        psc</span>
                                <?php else: ?>
                                    <span class="text-[9px] font-black uppercase tracking-wider <?= $bl ?>">Masa Aktif</span>
                                    <span class="text-sm font-extrabold <?= $bv ?> leading-none mt-0.5"><?= $days ?? 0 ?>
                                        Hari</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="h-6 w-px bg-slate-200"></div>

                    <div class="relative">
                        <button type="button" id="deskAvatarBtn" onclick="toggleDeskMenu()"
                            class="flex items-center gap-3 text-left focus:outline-none group">
                            <div
                                class="w-9 h-9 bg-slate-100 rounded-full flex items-center justify-center group-hover:bg-slate-200 transition-colors">
                                <span
                                    class="text-slate-600 font-bold text-sm select-none"><?= strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1)) ?></span>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-700 leading-tight group-hover:text-slate-900 transition-colors">
                                    <?= h($user['full_name'] ?: $user['username']) ?>
                                </div>
                                <div class="text-[11px] font-medium text-slate-400">@<?= h($user['username']) ?></div>
                            </div>
                        </button>
                        <div id="deskMenu"
                            class="hidden absolute top-full right-0 mt-3 w-48 bg-white/90 backdrop-blur-xl border border-white/20 rounded-2xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] overflow-hidden z-50 animate-[fadeIn_0.1s_ease-out]">
                            <a href="<?= $base ?>/auth/logout.php?area=<?= $_area ?>"
                                class="flex items-center gap-3 px-4 py-3.5 text-sm font-bold text-rose-600 hover:bg-rose-50 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg> Keluar
                            </a>
                        </div>
                    </div>
                </div>
            </header>