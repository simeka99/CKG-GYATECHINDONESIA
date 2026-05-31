<?php
ob_start();

$page_title = 'Pengaturan Admin — RMIK Medical Record';
require_once __DIR__ . '/../includes/header.php';

$db = db();

$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
$error = '';

$admin_id = (int) $_SESSION['user_id'];

function mask_secret_value(string $value): string
{
    $value = trim($value);
    if ($value === '')
        return '';
    $len = strlen($value);
    if ($len <= 4)
        return '***';
    if ($len <= 8)
        return substr($value, 0, 2) . '***' . substr($value, -2);
    return substr($value, 0, 4) . '***' . substr($value, -4);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'Aksi tidak valid (CSRF). Silakan muat ulang halaman.';
        ob_end_clean();
        header('Location: settings.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'account') {
    $new_username = trim($_POST['new_username'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $current_pass = $_POST['current_password'] ?? '';

    $row = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $row->execute([$admin_id]);
    $row = $row->fetch();

    if (!$row || !password_verify($current_pass, $row['password'])) {
        $error = 'Password saat ini salah.';
    } elseif (!$new_username) {
        $error = 'Username baru tidak boleh kosong.';
    } else {
        $chk = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $chk->execute([$new_username, $admin_id]);
        if ($chk->fetch()) {
            $error = 'Username sudah dipakai.';
        } elseif ($new_password && $new_password !== $confirm) {
            $error = 'Konfirmasi password tidak cocok.';
        } else {
            if ($new_password) {
                $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?")
                    ->execute([$new_username, $hash, $admin_id]);
            } else {
                $db->prepare("UPDATE users SET username = ? WHERE id = ?")
                    ->execute([$new_username, $admin_id]);
            }
            $_SESSION['username'] = $new_username;
            $_SESSION['flash_success'] = 'Akun admin berhasil diperbarui.';
            ob_end_clean();
            header('Location: settings.php');
            exit;
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apikeys') {
    $keys = [
        'telegram_bot_token',
        'telegram_chat_id',
        'wa_api_url',
        'wa_api_key',
        'bpjs_api_url',
        'bpjs_api_key',
        'epus_api_url',
        'epus_referer',
        'epus_cookie',
        'epus_integration_token',
        'epus_integration_secret',
        'epus_integration_allowed_ips',
    ];
    $masked_fields = [
        'epus_cookie' => 'Cookie EPUS',
        'epus_integration_token' => 'Token integrasi',
        'epus_integration_secret' => 'Secret integrasi',
    ];
    foreach ($masked_fields as $field => $label) {
        if (!array_key_exists($field, $_POST))
            continue;
        $value = trim((string) $_POST[$field]);
        if ($value !== '' && str_contains($value, '*')) {
            $error = $label . ' terdeteksi masih format mask (***). Gunakan nilai asli.';
            break;
        }
    }

    if ($error === '') {
        $keep_if_empty = ['epus_cookie' => true, 'epus_integration_secret' => true, 'epus_integration_token' => true];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $_POST))
                continue;
            $value = trim((string) $_POST[$k]);
            if ($value === '' && isset($keep_if_empty[$k]))
                continue;
            save_setting($k, $value);
        }

        $_SESSION['flash_success'] = 'API Key berhasil disimpan.';
        ob_end_clean();
        header('Location: settings.php');
        exit;
    }
}

$tg_token = get_setting('telegram_bot_token');
$tg_chatid = get_setting('telegram_chat_id');
$wa_url = get_setting('wa_api_url');
$wa_key = get_setting('wa_api_key');
$bpjs_url = get_setting('bpjs_api_url');
$bpjs_key = get_setting('bpjs_api_key');
$epus_url = get_setting('epus_api_url');
$epus_referer = get_setting('epus_referer');
$epus_cookie = get_setting('epus_cookie');
$epus_integration_token = get_setting('epus_integration_token');
$epus_integration_secret = get_setting('epus_integration_secret');
$epus_integration_allowed_ips = get_setting('epus_integration_allowed_ips');
$epus_cookie_mask = mask_secret_value($epus_cookie);
$epus_integration_secret_mask = mask_secret_value($epus_integration_secret);
$epus_integration_token_mask = mask_secret_value($epus_integration_token);

$admin_data = $db->prepare("SELECT username, full_name FROM users WHERE id = ?");
$admin_data->execute([$admin_id]);
$admin_data = $admin_data->fetch();
?>

<main class="flex-1 p-4 md:p-6 xl:p-8 bg-slate-50/50">
    <div class="w-full max-w-[1820px] mx-auto">

        <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center shadow-sm flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-black text-slate-900 tracking-tight leading-none">Pengaturan Developer</h2>
                    <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Kelola akun admin dan konfigurasi API sistem</p>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
            <div
                class="mb-5 flex items-center gap-3 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700 text-sm font-medium">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <?= h($success) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div
                class="mb-5 flex items-center gap-3 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-600 text-sm font-medium">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <div class="space-y-6">

            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center gap-3">
                    <div class="w-8 h-8 bg-teal-100 rounded-xl flex items-center justify-center">
                        <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-800">Akun Developer</h3>
                        <p class="text-xs text-slate-400">Ubah username atau password login admin</p>
                    </div>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="account">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">
                                Username Baru <span class="text-rose-400">*</span>
                            </label>
                            <input type="text" name="new_username" required value="<?= h($admin_data['username']) ?>"
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-teal-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">
                                Password Saat Ini <span class="text-rose-400">*</span>
                            </label>
                            <div class="relative">
                                <input type="password" name="current_password" id="curPass" required
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm focus:outline-none focus:border-teal-500 transition-colors"
                                    placeholder="Verifikasi password lama">
                                <button type="button" onclick="tp('curPass','curPassIcon')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-teal-600 transition-colors">
                                    <svg id="curPassIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">
                                Password Baru <span class="text-slate-400 font-normal normal-case">(kosong = tidak
                                    berubah)</span>
                            </label>
                            <div class="relative">
                                <input type="password" name="new_password" id="newPass"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm focus:outline-none focus:border-teal-500 transition-colors"
                                    placeholder="Password baru">
                                <button type="button" onclick="tp('newPass','newPassIcon')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-teal-600 transition-colors">
                                    <svg id="newPassIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">
                                Konfirmasi Password Baru
                            </label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="confPass"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm focus:outline-none focus:border-teal-500 transition-colors"
                                    placeholder="Ulangi password baru">
                                <button type="button" onclick="tp('confPass','confPassIcon')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-teal-600 transition-colors">
                                    <svg id="confPassIcon" class="w-4 h-4" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit"
                            class="px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold transition-colors shadow-sm">
                            Simpan Perubahan Akun
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center gap-3">
                    <div class="w-8 h-8 bg-violet-100 rounded-xl flex items-center justify-center">
                        <svg class="w-4 h-4 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-800">Notifikasi</h3>
                        <p class="text-xs text-slate-400">Konfigurasi Telegram Bot dan WhatsApp</p>
                    </div>
                </div>
                <form method="POST" class="p-6 space-y-6">
                    <input type="hidden" name="action" value="apikeys">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div>
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-sky-500" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L7.43 13.617l-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.72.942z" />
                            </svg>
                            <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">Telegram Bot</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">Bot Token</label>
                                <div class="relative">
                                    <input type="password" name="telegram_bot_token" id="tgToken" value="<?= h($tg_token) ?>"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm font-mono focus:outline-none focus:border-violet-400 transition-colors"
                                        placeholder="1234567890:ABCDEF...">
                                    <button type="button" onclick="tp('tgToken','tgTokenIcon')"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-violet-500 transition-colors">
                                        <svg id="tgTokenIcon" class="w-4 h-4" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>
                                </div>
                                <p class="text-[10px] text-slate-400 mt-1">Dari @BotFather di Telegram</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">
                                    Default Chat ID <span class="text-slate-400 font-normal">(opsional)</span>
                                </label>
                                <input type="text" name="telegram_chat_id" value="<?= h($tg_chatid) ?>"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-violet-400 transition-colors"
                                    placeholder="-100xxxxxxxxxx atau @channel">
                                <p class="text-[10px] text-slate-400 mt-1">Untuk notifikasi ke admin sendiri</p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-100"></div>

                    <div>
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-green-500" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" />
                                <path
                                    d="M12 0C5.373 0 0 5.373 0 12c0 2.123.555 4.116 1.528 5.845L.057 23.998l6.305-1.654A11.954 11.954 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.667-.52-5.187-1.424l-.371-.221-3.844 1.008 1.026-3.742-.242-.385A9.955 9.955 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z" />
                            </svg>
                            <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">WhatsApp API</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">API URL</label>
                                <input type="text" name="wa_api_url" value="<?= h($wa_url) ?>"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-violet-400 transition-colors"
                                    placeholder="https://api.fonnte.com/send">
                                <p class="text-[10px] text-slate-400 mt-1">Fonnte / WA-Gateway / dll</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">API
                                    Key</label>
                                <div class="relative">
                                    <input type="password" name="wa_api_key" id="waKey" value="<?= h($wa_key) ?>"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm font-mono focus:outline-none focus:border-violet-400 transition-colors"
                                        placeholder="API Key dari provider WA">
                                    <button type="button" onclick="tp('waKey','waKeyIcon')"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-violet-500 transition-colors">
                                        <svg id="waKeyIcon" class="w-4 h-4" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit"
                            class="px-6 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-xl text-sm font-bold transition-colors shadow-sm">
                            Simpan Notifikasi
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center gap-3">
                    <div class="w-8 h-8 bg-blue-100 rounded-xl flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-800">Integrasi BPJS & EPUS</h3>
                        <p class="text-xs text-slate-400">Konfigurasi endpoint BPJS, EPUS, dan API sinkron server lain</p>
                    </div>
                </div>
                <form method="POST" class="p-6 space-y-6">
                    <input type="hidden" name="action" value="apikeys">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div>
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                            <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">BPJS Kesehatan API</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">API URL</label>
                                <input type="text" name="bpjs_api_url" value="<?= h($bpjs_url) ?>"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-blue-400 transition-colors"
                                    placeholder="https://apijkn.bpjs-kesehatan.go.id/...">
                                <p class="text-[10px] text-slate-400 mt-1">Endpoint BPJS Kesehatan / bridging RS</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">API Key</label>
                                <div class="relative">
                                    <input type="password" name="bpjs_api_key" id="bpjsKey" value="<?= h($bpjs_key) ?>"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm font-mono focus:outline-none focus:border-blue-400 transition-colors"
                                        placeholder="API Key dari BPJS / bridging">
                                    <button type="button" onclick="tp('bpjsKey','bpjsKeyIcon')"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-600 transition-colors">
                                        <svg id="bpjsKeyIcon" class="w-4 h-4" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>
                                </div>
                                <p class="text-[10px] text-slate-400 mt-1">Dari portal developer BPJS Kesehatan</p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-100"></div>

                    <div>
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-4 h-4 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">EPUS API</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">API URL</label>
                                <input type="text" name="epus_api_url" value="<?= h($epus_url) ?>"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-cyan-400 transition-colors"
                                    placeholder="https://tasik.epuskesmas.id/pasien">
                                <p class="text-[10px] text-slate-400 mt-1">Endpoint data pasien EPUS</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">Referer
                                    <span class="text-slate-400 font-normal">(opsional)</span>
                                </label>
                                <input type="text" name="epus_referer" value="<?= h($epus_referer) ?>"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-cyan-400 transition-colors"
                                    placeholder="https://tasik.epuskesmas.id/pasien?broadcastNotif=1">
                                <p class="text-[10px] text-slate-400 mt-1">Jika EPUS butuh header referer</p>
                            </div>
                        </div>
                        <div class="mt-5">
                            <label class="block text-xs font-semibold text-slate-500 mb-1.5">Cookie Baru</label>
                            <textarea name="epus_cookie" rows="4"
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-mono focus:outline-none focus:border-cyan-400 transition-colors"
                                placeholder="Kosongkan jika tidak ganti cookie"></textarea>
                            <p class="text-[10px] text-slate-400 mt-1">
                                Cookie saat ini:
                                <span class="font-mono text-slate-500"><?= $epus_cookie_mask !== '' ? h($epus_cookie_mask) : 'belum ada' ?></span>
                            </p>
                        </div>

                        <div class="border-t border-slate-100 mt-5 pt-5 space-y-4">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 11c0 .552-.448 1-1 1H8a1 1 0 01-1-1V8c0-.552.448-1 1-1h3c.552 0 1 .448 1 1v3zm5 5c0 .552-.448 1-1 1h-3a1 1 0 01-1-1v-3c0-.552.448-1 1-1h3c.552 0 1 .448 1 1v3zM7 13h4m2-2v4" />
                                </svg>
                                <span class="text-xs font-bold text-slate-600 uppercase tracking-widest">API Sinkron Server Lain</span>
                            </div>
                            <p class="text-[11px] text-slate-500">
                                Endpoint:
                                <span class="font-mono"><?= h(rtrim((string) APP_URL, '/') . '/api/integration/epus_push.php') ?></span>
                            </p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Token Baru</label>
                                    <input type="text" name="epus_integration_token"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-cyan-400 transition-colors"
                                        placeholder="Kosongkan jika tidak ganti token">
                                    <p class="text-[10px] text-slate-400 mt-1">
                                        Token saat ini:
                                        <span class="font-mono text-slate-500"><?= $epus_integration_token_mask !== '' ? h($epus_integration_token_mask) : 'belum ada' ?></span>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Secret Baru</label>
                                    <input type="password" name="epus_integration_secret"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-cyan-400 transition-colors"
                                        placeholder="Kosongkan jika tidak ganti secret">
                                    <p class="text-[10px] text-slate-400 mt-1">
                                        Secret saat ini:
                                        <span class="font-mono text-slate-500"><?= $epus_integration_secret_mask !== '' ? h($epus_integration_secret_mask) : 'belum ada' ?></span>
                                    </p>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1.5">Allowed IP
                                    <span class="text-slate-400 font-normal">(opsional)</span>
                                </label>
                                <input type="text" name="epus_integration_allowed_ips" value="<?= h($epus_integration_allowed_ips) ?>"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-cyan-400 transition-colors"
                                    placeholder="1.2.3.4,5.6.7.8">
                                <p class="text-[10px] text-slate-400 mt-1">Pisahkan dengan koma jika lebih dari satu IP</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit"
                            class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition-colors shadow-sm">
                            Simpan BPJS & EPUS
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
    function tp(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.innerHTML = show ?
            `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>` :
            `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>