<?php
$page_title = 'Pengaturan — RMIK Medical Record';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
auth_check('operator');
$user = current_user();

$db = db();
$uid = (int) $user['id'];
$success = $error = '';
$flash_data = $_SESSION['user_settings_flash'] ?? null;
if (is_array($flash_data)) {
    $success = (string) ($flash_data['success'] ?? '');
    $error = (string) ($flash_data['error'] ?? '');
    unset($_SESSION['user_settings_flash']);
}
$bpjs_sync_field_labels = [
    'nik' => 'NIK',
    'nama' => 'Nama Pasien',
    'jenis_kelamin' => 'Jenis Kelamin',
    'tgl_lahir' => 'Tanggal Lahir',
    'no_hp' => 'No HP',
];
$bpjs_sync_setting = get_user_bpjs_sync_settings($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['user_settings_flash'] = [
            'success' => '',
            'error' => 'Aksi tidak valid (CSRF). Silakan muat ulang halaman.',
        ];
        header('Location: settings.php');
        exit;
    }
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_notif') {
        $notif_tg = isset($_POST['notif_telegram']) ? 1 : 0;
        $tg_id = trim((string) ($_POST['telegram_chat_id'] ?? ''));
        $notif_wa = isset($_POST['notif_whatsapp']) ? 1 : 0;
        $wa_no = trim((string) ($_POST['whatsapp_number'] ?? ''));

        try {
            $db->prepare("
                UPDATE users SET notif_telegram=?, telegram_chat_id=?,
                notif_whatsapp=?, whatsapp_number=? WHERE id=?
            ")->execute([$notif_tg, $tg_id, $notif_wa, $wa_no, $uid]);
            $success = 'Pengaturan notifikasi berhasil disimpan.';
        } catch (Throwable $e) {
            $error = 'Pengaturan notifikasi gagal disimpan.';
        }
    } elseif ($action === 'save_bpjs_sync') {
        $sync_fields_post = array_values(array_unique(array_map('strtolower', (array) ($_POST['bpjs_sync_fields'] ?? []))));
        $phone_auto_fallback = isset($_POST['bpjs_phone_auto_fallback']) ? 1 : 0;
        $phone_fallback_number_raw = trim((string) ($_POST['bpjs_phone_fallback_number'] ?? ''));
        $phone_fallback_number = normalize_phone_number_value($phone_fallback_number_raw);
        $no_hp_enabled = in_array('no_hp', $sync_fields_post, true);
        if (!$no_hp_enabled)
            $phone_auto_fallback = 0;
        if ($phone_fallback_number_raw !== '' && $phone_fallback_number === '') {
            $error = 'No HP Cadangan tidak valid. Gunakan format 628xxxx atau 08xxxx.';
        }

        if ($error === '' && !$sync_fields_post) {
            $error = 'Pilih minimal 1 field sinkronisasi BPJS.';
        } elseif ($error === '') {
            $bpjs_sync_setting = normalize_user_bpjs_sync_settings([
                'sync_fields' => $sync_fields_post,
                'phone_auto_fallback' => $phone_auto_fallback,
                'phone_fallback_number' => $phone_fallback_number,
            ]);

            try {
                save_user_bpjs_sync_settings($uid, $bpjs_sync_setting);
                $success = 'Pengaturan sinkronisasi BPJS berhasil disimpan.';
            } catch (Throwable $e) {
                $error = 'Pengaturan sinkronisasi BPJS gagal disimpan.';
            }
        }
    } elseif ($action === 'save_display') {
        $hide_nik = isset($_POST['hide_nik']) ? '1' : '0';
        try {
            save_setting('hide_nik_user_' . $uid, $hide_nik);
            $success = 'Pengaturan tampilan berhasil disimpan.';
        } catch (Throwable $e) {
            $error = 'Pengaturan tampilan gagal disimpan.';
        }
    } else {
        $error = 'Aksi tidak valid.';
    }

    $_SESSION['user_settings_flash'] = [
        'success' => $success,
        'error' => $error,
    ];
    header('Location: settings.php');
    exit;
}

$udata = $db->prepare("
    SELECT username, full_name, notif_telegram, telegram_chat_id,
           notif_whatsapp, whatsapp_number,
           subscription_type, subscription_end, quota_total, quota_used,
           subscription_note
    FROM users WHERE id = ? LIMIT 1
");
$udata->execute([$uid]);
$udata = $udata->fetch();
$is_quota = ($udata['subscription_type'] ?? 'time') === 'quota';

require_once __DIR__ . '/../includes/header.php';
?>

<main class="flex-1 p-4 lg:p-8">
    <div class="max-w-7xl w-full mx-auto space-y-6">
        <!-- Header removed per design update -->

        <?php if ($success): ?>
            <div class="flex items-center gap-2.5 px-4 py-3 bg-emerald-50 border border-emerald-200
                rounded-xl text-emerald-700 text-sm font-semibold">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
                <?= h($success) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="flex items-center gap-2.5 px-4 py-3 bg-rose-50 border border-rose-200
                rounded-xl text-rose-600 text-sm font-semibold">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <div class="space-y-6">
            <!-- ══ INFO AKUN ══ -->
            <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] ring-1 ring-slate-100/50 overflow-hidden h-full flex flex-col">
                <div class="px-5 py-4 border-b border-slate-50 flex items-center gap-2.5 bg-white">
                    <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <div>
                        <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Info Akun</div>
                        <div class="text-[10px] text-slate-400">Username/password hanya bisa diubah oleh admin</div>
                    </div>
                </div>

                <div class="divide-y divide-slate-50 flex-1">
                    <div class="flex items-center justify-between px-5 py-4">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Username</span>
                        <span class="text-sm font-bold text-slate-700 font-mono"><?= h($udata['username']) ?></span>
                    </div>
                    <div class="flex items-center justify-between px-5 py-4">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Nama</span>
                        <span class="text-sm font-semibold text-slate-700"><?= h($udata['full_name'] ?: '—') ?></span>
                    </div>
                    <div class="flex items-center justify-between px-5 py-4">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Paket</span>
                        <span class="text-sm font-semibold text-slate-700"><?= h($udata['subscription_note'] ?: '—') ?></span>
                    </div>
                    <div class="flex items-center justify-between px-5 py-4">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider"><?= $is_quota ? 'Sisa Kuota' : 'Masa Aktif' ?></span>
                        <?php if ($is_quota): ?>
                            <?php $sisa = max(0, (int) $udata['quota_total'] - (int) $udata['quota_used']); ?>
                            <div class="flex flex-col items-end gap-1">
                                <span class="text-base font-bold text-blue-700">
                                    <?= number_format($sisa) ?> <span class="text-[11px] font-semibold text-slate-400">/ <?= number_format($udata['quota_total']) ?> NIK</span>
                                </span>
                                <div class="w-24 h-1 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-blue-400 rounded-full transition-all" style="width:<?= $udata['quota_total'] > 0 ? min(100, round($sisa / $udata['quota_total'] * 100)) : 0 ?>%"></div>
                                </div>
                            </div>
                        <?php elseif ($udata['subscription_end']): ?>
                            <?php $dl = max(0, (int) ceil((strtotime($udata['subscription_end']) - time()) / 86400)); ?>
                            <div class="flex flex-col items-end gap-0.5">
                                <span class="text-base font-bold <?= $dl <= 3 ? 'text-rose-600' : ($dl <= 7 ? 'text-amber-600' : 'text-emerald-700') ?>"><?= $dl ?> hari</span>
                                <span class="text-[10px] text-slate-400">s/d <?= date('d/m/Y', strtotime($udata['subscription_end'])) ?></span>
                            </div>
                        <?php else: ?>
                            <span class="text-sm text-amber-500 font-semibold">Belum diset</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] ring-1 ring-slate-100/50 overflow-hidden flex flex-col">
                <div class="px-5 py-4 border-b border-slate-50 flex items-center gap-2.5 bg-white">
                    <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <div>
                        <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Notifikasi</div>
                        <div class="text-[10px] text-slate-400">Terima pemberitahuan saat job selesai atau error</div>
                    </div>
                </div>

                <form method="POST" class="p-5 space-y-6">
                    <input type="hidden" name="action" value="save_notif">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="space-y-4">
                        <label class="flex items-center gap-3 cursor-pointer select-none">
                            <div class="relative">
                                <input type="checkbox" name="notif_telegram" id="chkTg" class="sr-only peer" <?= $udata['notif_telegram'] ? 'checked' : '' ?> onchange="document.getElementById('tgBox').classList.toggle('hidden', !this.checked)">
                                <div class="w-9 h-5 bg-slate-200 rounded-full peer-checked:bg-slate-700 transition-colors"></div>
                                <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
                            </div>
                            <div>
                                <div class="text-sm font-bold text-slate-700">Telegram</div>
                                <div class="text-[10px] text-slate-400">Notifikasi via bot Telegram</div>
                            </div>
                            <svg class="w-5 h-5 ml-auto text-sky-500 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L7.196 13.986l-2.937-.918c-.638-.203-.651-.638.136-.943l11.44-4.41c.532-.194 .998.13.059.506z" />
                            </svg>
                        </label>

                        <div id="tgBox" class="<?= $udata['notif_telegram'] ? '' : 'hidden' ?> pl-12 space-y-2">
                            <label class="lbl">Chat ID Telegram</label>
                            <input type="text" name="telegram_chat_id" class="inp" value="<?= h($udata['telegram_chat_id'] ?? '') ?>" placeholder="Contoh: 123456789">
                            <p class="text-[10px] text-slate-400 leading-relaxed">Kirim <code class="bg-slate-100 px-1 rounded text-slate-600">/start</code> ke <strong>@userinfobot</strong> untuk mendapatkan Chat ID Anda</p>
                        </div>

                        <div class="border-t border-slate-50"></div>

                        <label class="flex items-center gap-3 cursor-pointer select-none">
                            <div class="relative">
                                <input type="checkbox" name="notif_whatsapp" id="chkWa" class="sr-only peer" <?= $udata['notif_whatsapp'] ? 'checked' : '' ?> onchange="document.getElementById('waBox').classList.toggle('hidden', !this.checked)">
                                <div class="w-9 h-5 bg-slate-200 rounded-full peer-checked:bg-slate-700 transition-colors"></div>
                                <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
                            </div>
                            <div>
                                <div class="text-sm font-bold text-slate-700">WhatsApp</div>
                                <div class="text-[10px] text-slate-400">Notifikasi via pesan WhatsApp</div>
                            </div>
                            <svg class="w-5 h-5 ml-auto text-green-500 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871 .118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45 -4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                            </svg>
                        </label>

                        <div id="waBox" class="<?= $udata['notif_whatsapp'] ? '' : 'hidden' ?> pl-12 space-y-2">
                            <label class="lbl">Nomor WhatsApp</label>
                            <input type="text" name="whatsapp_number" class="inp" value="<?= h($udata['whatsapp_number'] ?? '') ?>" placeholder="628xxxxxxxxxx">
                            <p class="text-[10px] text-slate-400">Format: <code class="bg-slate-100 px-1 rounded text-slate-600">628xxx</code> tanpa <code class="bg-slate-100 px-1 rounded text-slate-600">+</code> atau spasi</p>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-slate-50">
                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-sm font-bold transition-colors active:scale-95 shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            Simpan Notifikasi
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] ring-1 ring-slate-100/50 overflow-hidden flex flex-col">
                <div class="px-5 py-4 border-b border-slate-50 flex items-center gap-2.5 bg-white">
                    <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Sinkronisasi BPJS</div>
                        <div class="text-[10px] text-slate-400">Pengaturan field sinkronisasi dan fallback No HP</div>
                    </div>
                </div>

                <form method="POST" class="p-5 space-y-6">
                    <input type="hidden" name="action" value="save_bpjs_sync">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="space-y-3">
                        <div class="text-sm font-bold text-slate-700">Field Sinkronisasi</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <?php foreach ($bpjs_sync_field_labels as $field_key => $field_label): ?>
                                <label class="flex items-center gap-2.5 px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 cursor-pointer hover:bg-slate-100 transition-colors">
                                    <input type="checkbox" name="bpjs_sync_fields[]" value="<?= h($field_key) ?>" <?= $field_key === 'no_hp' ? 'id="bpjs_sync_no_hp"' : '' ?> class="w-4 h-4 rounded border-slate-300 text-slate-700 focus:ring-slate-400" <?= in_array($field_key, $bpjs_sync_setting['sync_fields'] ?? [], true) ? 'checked' : '' ?>>
                                    <span class="text-xs font-semibold text-slate-700"><?= h($field_label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-[10px] text-slate-400">
                            Field yang tidak dicentang akan diabaikan. Khusus No HP: jika No HP tidak dicentang maka sistem pakai No HP cadangan.
                        </p>
                    </div>

                    <div class="space-y-2">
                        <label class="lbl">No HP Cadangan</label>
                        <input type="text" id="bpjs_phone_fallback_number" name="bpjs_phone_fallback_number" class="inp" value="<?= h($bpjs_sync_setting['phone_fallback_number'] ?? '') ?>" placeholder="Contoh: 6281234567890 atau 081234567890">
                        <p id="bpjs_fallback_hint" class="text-[10px] text-slate-400">Prioritas No HP: BPJS -> No HP cadangan -> nomor lama data peserta.</p>
                    </div>

                    <label class="flex items-start gap-2.5 px-3 py-2 rounded-xl border border-slate-200 bg-white cursor-pointer hover:bg-slate-50 transition-colors">
                        <input type="checkbox" id="bpjs_phone_auto_fallback" name="bpjs_phone_auto_fallback" class="mt-0.5 w-4 h-4 rounded border-slate-300 text-slate-700 focus:ring-slate-400" <?= !empty($bpjs_sync_setting['phone_auto_fallback']) ? 'checked' : '' ?>>
                        <span class="text-[11px] text-slate-600 leading-relaxed">
                            Jika No HP BPJS kosong dan No HP cadangan kosong, pertahankan nomor yang sudah ada pada data peserta.
                        </span>
                    </label>

                    <div class="pt-2 border-t border-slate-50">
                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-sm font-bold transition-colors active:scale-95 shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            Simpan Sinkronisasi BPJS
                        </button>
                    </div>
                </form>
            </div>
            <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] ring-1 ring-slate-100/50 overflow-hidden flex flex-col">
                <div class="px-5 py-4 border-b border-slate-50 flex items-center gap-2.5 bg-white">
                    <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    <div>
                        <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Tampilan</div>
                        <div class="text-[10px] text-slate-400">Pengaturan privasi dan tampilan data</div>
                    </div>
                </div>

                <form method="POST" class="p-5 space-y-6">
                    <input type="hidden" name="action" value="save_display">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="space-y-4">
                        <label class="flex items-center gap-3 cursor-pointer select-none">
                            <div class="relative">
                                <input type="checkbox" name="hide_nik" class="sr-only peer" <?= get_setting('hide_nik_user_' . $uid) === '1' ? 'checked' : '' ?>>
                                <div class="w-9 h-5 bg-slate-200 rounded-full peer-checked:bg-slate-700 transition-colors"></div>
                                <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
                            </div>
                            <div>
                                <div class="text-sm font-bold text-slate-700">Sembunyikan NIK</div>
                                <div class="text-[10px] text-slate-400">Sensor nomor NIK pada semua halaman dengan tanda bintang (***)</div>
                            </div>
                        </label>
                    </div>

                    <div class="pt-2 border-t border-slate-50">
                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-sm font-bold transition-colors active:scale-95 shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            Simpan Tampilan
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="bg-white rounded-2xl shadow-[0_2px_10px_-3px_rgba(6,81,237,0.05)] ring-1 ring-slate-100/50 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-50 flex items-center gap-2.5 bg-white">
                <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636
                       5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118
                       0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <div>
                    <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">
                        Pusat Informasi
                    </div>
                    <div class="text-[10px] text-slate-400">
                        Butuh bantuan? Hubungi admin kami
                    </div>
                </div>
            </div>

            <div class="p-5 space-y-3">


                <p class="text-xs text-slate-500 leading-relaxed">
                    Jika mengalami kendala teknis, pertanyaan seputar lisensi, perpanjangan paket,
                    atau hal lainnya — jangan ragu untuk menghubungi admin via WhatsApp.
                    Kami siap membantu Anda.
                </p>


                <a href="https://wa.me/6282218865552?text=Halo+Admin,+saya+butuh+bantuan+mengenai+RMIK+Medical+Record."
                    target="_blank" rel="noopener" class="flex items-center gap-3 p-3.5 rounded-xl border border-slate-100
                      hover:border-green-200 hover:bg-green-50 transition-all group">
                    <div class="w-9 h-9 rounded-xl bg-green-50 group-hover:bg-green-100
                            flex items-center justify-center flex-shrink-0 transition-colors">
                        <svg class="w-4 h-4 text-green-500" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471
                                 -.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223
                                 -.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761
                                 -1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446
                                 -.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075
                                 -.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008
                                 -.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016
                                 -1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2
                                 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871
                                 .118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173
                                 -1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87
                                 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86
                                 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0
                                 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45
                                 -4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495
                                 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305
                                 -1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335
                                 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                            Admin 1
                        </div>
                        <div class="text-sm font-bold text-slate-800 tracking-wide">
                            0822-1886-5552
                        </div>
                    </div>
                    <div class="flex-shrink-0 flex items-center gap-1 text-[10px] font-bold
                            text-green-600 opacity-0 group-hover:opacity-100 transition-opacity">
                        Chat
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                        </svg>
                    </div>
                </a>


                <a href="https://wa.me/6285727248592?text=Halo+Admin,+saya+butuh+bantuan+mengenai+RMIK+Medical+Record."
                    target="_blank" rel="noopener" class="flex items-center gap-3 p-3.5 rounded-xl border border-slate-100
                      hover:border-green-200 hover:bg-green-50 transition-all group">
                    <div class="w-9 h-9 rounded-xl bg-green-50 group-hover:bg-green-100
                            flex items-center justify-center flex-shrink-0 transition-colors">
                        <svg class="w-4 h-4 text-green-500" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471
                                 -.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223
                                 -.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761
                                 -1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446
                                 -.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075
                                 -.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008
                                 -.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016
                                 -1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2
                                 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871
                                 .118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173
                                 -1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87
                                 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86
                                 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0
                                 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45
                                 -4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495
                                 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305
                                 -1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335
                                 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                            Admin 2
                        </div>
                        <div class="text-sm font-bold text-slate-800 tracking-wide">
                            0857-2724-8592
                        </div>
                    </div>
                    <div class="flex-shrink-0 flex items-center gap-1 text-[10px] font-bold
                            text-green-600 opacity-0 group-hover:opacity-100 transition-opacity">
                        Chat
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                        </svg>
                    </div>
                </a>


                <p class="text-[10px] text-slate-400 text-center pt-1">
                    Jam layanan admin: <span class="font-bold text-slate-500">Setiap Hari, 08.00–21.00 WIB</span>
                </p>

            </div>
        </div>

    </div>
</main>

<script>
    (function() {
        const no_hp_checkbox = document.getElementById('bpjs_sync_no_hp');
        const fallback_input = document.getElementById('bpjs_phone_fallback_number');
        const auto_fallback_checkbox = document.getElementById('bpjs_phone_auto_fallback');
        const fallback_hint = document.getElementById('bpjs_fallback_hint');
        if (!no_hp_checkbox || !fallback_input || !auto_fallback_checkbox || !fallback_hint)
            return;

        const update_state = () => {
            const has_fallback_number = fallback_input.value.replace(/\D+/g, '') !== '';
            const no_hp_enabled = no_hp_checkbox.checked;
            fallback_input.disabled = false;
            auto_fallback_checkbox.disabled = !no_hp_enabled;
            fallback_input.classList.remove('opacity-60');

            if (!no_hp_enabled) {
                auto_fallback_checkbox.checked = false;
                fallback_hint.textContent = has_fallback_number ?
                    'Mode No HP Cadangan aktif. Saat sinkronisasi, No HP diisi dari nomor cadangan.' :
                    'No HP BPJS tidak aktif. Isi No HP cadangan agar No HP ikut terisi saat sinkronisasi.';
                return;
            }

            fallback_hint.textContent = 'Mode No HP BPJS aktif. No HP cadangan tidak dipakai pada mode ini.';
        };

        no_hp_checkbox.addEventListener('change', update_state);
        fallback_input.addEventListener('input', update_state);
        auto_fallback_checkbox.addEventListener('change', update_state);
        update_state();
    })();
</script>

<style>
    .inp {
        width: 100%;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: .75rem;
        padding: .65rem 1rem;
        font-size: .875rem;
        outline: none;
        transition: border-color .15s, background .15s;
        color: #0f172a;
    }

    .inp:focus {
        border-color: #334155;
        background: #fff;
    }

    .lbl {
        display: block;
        font-size: .68rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .07em;
        margin-bottom: .375rem;
    }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>