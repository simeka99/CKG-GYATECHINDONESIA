<?php
ob_start();

$page_title = 'License Keys — RMIK Medical Record';
require_once __DIR__ . '/../includes/header.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'Aksi tidak valid (CSRF). Silakan muat ulang halaman.';
        header('Location: licenses.php');
        exit;
    }
} elseif (isset($_GET['toggle']) || isset($_GET['delete']) || isset($_GET['reset_device']) || isset($_GET['reset_user_devices'])) {
    if (!is_valid_csrf_token((string)($_GET['csrf_token'] ?? ''))) {
        $_SESSION['flash_error'] = 'Aksi tidak valid (CSRF). Silakan muat ulang halaman.';
        header('Location: licenses.php');
        exit;
    }
}
try {
    $db->exec("ALTER TABLE license_keys ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE license_keys MODIFY task_type ENUM('pendaftaran','pelayanan','skrining_bpjs') NOT NULL DEFAULT 'pendaftaran'");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE license_keys MODIFY mode ENUM('umum','sekolah','bpjs') NOT NULL DEFAULT 'umum'");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE license_keys ADD COLUMN device_auth_token VARCHAR(128) NULL DEFAULT NULL AFTER device_bound_at");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE license_keys ADD COLUMN token_bound_at DATETIME NULL DEFAULT NULL AFTER device_auth_token");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE license_keys ADD COLUMN last_ip VARCHAR(64) NULL DEFAULT NULL AFTER token_bound_at");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE license_keys ADD COLUMN last_user_agent VARCHAR(255) NULL DEFAULT NULL AFTER last_ip");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE license_keys ADD COLUMN device_profile_hash VARCHAR(64) NULL DEFAULT NULL AFTER device_id");
} catch (Exception $e) {
}
try {
    $db->exec("ALTER TABLE license_keys ADD COLUMN device_profile_updated_at DATETIME NULL DEFAULT NULL AFTER device_profile_hash");
} catch (Exception $e) {
}
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS license_profiles (
            license_key_id INT(11) NOT NULL,
            account_email VARCHAR(255) NOT NULL DEFAULT '',
            account_password VARCHAR(255) NOT NULL DEFAULT '',
            wali_nik VARCHAR(32) NOT NULL DEFAULT '',
            wali_nama VARCHAR(150) NOT NULL DEFAULT '',
            wali_no_hp VARCHAR(32) NOT NULL DEFAULT '',
            wali_instansi_puskesmas VARCHAR(160) NOT NULL DEFAULT '',
            wali_tanggal_lahir VARCHAR(10) NOT NULL DEFAULT '',
            wali_jenis_kelamin VARCHAR(20) NOT NULL DEFAULT 'Perempuan',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
            PRIMARY KEY (license_key_id),
            CONSTRAINT license_profiles_ibfk_1
                FOREIGN KEY (license_key_id) REFERENCES license_keys(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (Exception $e) {
}

$error = '';


$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $user_id   = (int)($_POST['user_id'] ?? 0);
    $pc_label  = trim($_POST['pc_label'] ?? '');
    $task_type_raw = (string)($_POST['task_type'] ?? '');
    $mode_raw = (string)($_POST['mode'] ?? '');
    $task_type = in_array($task_type_raw, ['pendaftaran', 'pelayanan', 'skrining_bpjs'], true) ? $task_type_raw : 'pendaftaran';
    $mode = in_array($mode_raw, ['umum', 'sekolah', 'bpjs'], true) ? $mode_raw : 'umum';
    if ($task_type === 'skrining_bpjs')
        $mode = 'bpjs';
    elseif ($mode === 'bpjs')
        $mode = 'umum';
    $combo_key = $task_type === 'skrining_bpjs' ? 'extension_bpjs' : ($task_type . '_' . $mode);
    $combo_label_map = [
        'pendaftaran_umum' => 'pendaftaran umum',
        'pelayanan_umum' => 'pelayanan umum',
        'pendaftaran_sekolah' => 'pendaftaran sekolah',
        'pelayanan_sekolah' => 'pelayanan sekolah',
        'extension_bpjs' => 'skrining bpjs',
    ];
    $combo_label = $combo_label_map[$combo_key] ?? $combo_key;

    if (!$user_id || !$pc_label) {
        $error = 'User dan label PC wajib diisi.';
    } else {
        $user_row = $db->prepare("SELECT license_quota FROM users WHERE id = ? LIMIT 1");
        $user_row->execute([$user_id]);
        $udata = $user_row->fetch();
        $lq = json_decode($udata['license_quota'] ?? '{}', true) ?: [];
        $max_combo = (int)($lq[$combo_key] ?? 0);

        if ($max_combo === 0) {
            $error = 'Operator tidak memiliki slot license untuk jenis "' . h($combo_label) . '".';
        } else {
            $count_stmt = $db->prepare("SELECT COUNT(*) FROM license_keys WHERE user_id = ? AND task_type = ? AND mode = ? AND is_active = 1 AND is_deleted = 0");
            $count_stmt->execute([$user_id, $task_type, $mode]);
            $count_combo = (int) $count_stmt->fetchColumn();

            if ($count_combo >= $max_combo) {
                $error = 'Slot license "' . h($combo_label) . '" sudah penuh (' . $count_combo . '/' . $max_combo . ').';
            } else {
                $key = generate_license_key();
                $db->prepare("
                    INSERT INTO license_keys (user_id, license_key, pc_label, task_type, mode)
                    VALUES (?,?,?,?,?)
                ")->execute([$user_id, $key, $pc_label, $task_type, $mode]);
                $new_lk_id = $db->lastInsertId();

                // ✅ Otomatis tangkap dan pindahkan antrean job (beserta historisnya) dari PC lama yang sudah hilang (hard-delete) atau nonaktif
                $db->prepare("
                    UPDATE job_queue jq
                    LEFT JOIN license_keys old_lk ON jq.license_key_id = old_lk.id
                    SET jq.license_key_id = ?, jq.status = 'pending'
                    WHERE jq.user_id = ?
                      AND jq.status IN ('pending', 'running')
                      AND jq.task_type = ?
                      AND (old_lk.id IS NULL OR (old_lk.is_active = 0 AND old_lk.mode = ?))
                ")->execute([$new_lk_id, $user_id, $task_type, $mode]);

                $db->prepare("
                    UPDATE job_success js
                    LEFT JOIN license_keys old_lk ON js.license_key_id = old_lk.id
                    SET js.license_key_id = ?
                    WHERE js.user_id = ?
                      AND js.task_type = ?
                      AND (old_lk.id IS NULL OR (old_lk.is_active = 0 AND old_lk.mode = ?))
                ")->execute([$new_lk_id, $user_id, $task_type, $mode]);

                $db->prepare("
                    UPDATE job_failed jf
                    LEFT JOIN license_keys old_lk ON jf.license_key_id = old_lk.id
                    SET jf.license_key_id = ?
                    WHERE jf.user_id = ?
                      AND jf.task_type = ?
                      AND (old_lk.id IS NULL OR (old_lk.is_active = 0 AND old_lk.mode = ?))
                ")->execute([$new_lk_id, $user_id, $task_type, $mode]);

                $db->prepare("
                    UPDATE job_failed_x jfx
                    LEFT JOIN license_keys old_lk ON jfx.license_key_id = old_lk.id
                    SET jfx.license_key_id = ?
                    WHERE jfx.user_id = ?
                      AND jfx.task_type = ?
                      AND (old_lk.id IS NULL OR (old_lk.is_active = 0 AND old_lk.mode = ?))
                ")->execute([$new_lk_id, $user_id, $task_type, $mode]);

                $_SESSION['flash_success'] = 'License key berhasil dibuat: <code class="bg-teal-100 px-2 py-0.5 rounded font-mono">' . h($key) . '</code>';
                ob_end_clean();
                header('Location: licenses.php');
                exit;
            }
        }
    }
}


if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $db->prepare("UPDATE license_keys SET is_active = 1 - is_active WHERE id = ?")
        ->execute([(int)$_GET['toggle']]);
    ob_end_clean();
    header('Location: licenses.php');
    exit;
}


if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $license_id = (int)$_GET['delete'];
    $db->prepare("UPDATE license_keys SET is_active = 0, is_deleted = 1 WHERE id = ?")
        ->execute([$license_id]);
    $db->prepare("DELETE FROM license_profiles WHERE license_key_id = ?")
        ->execute([$license_id]);
    ob_end_clean();
    header('Location: licenses.php');
    exit;
}


if (isset($_GET['reset_device']) && is_numeric($_GET['reset_device'])) {
    $license_id = (int)$_GET['reset_device'];
    $db->prepare("UPDATE license_keys SET device_id = NULL, device_bound_at = NULL, device_profile_hash = NULL, device_profile_updated_at = NULL, device_auth_token = NULL, token_bound_at = NULL, last_ip = NULL, last_user_agent = NULL WHERE id = ?")
        ->execute([$license_id]);
    $db->prepare("DELETE FROM license_profiles WHERE license_key_id = ?")
        ->execute([$license_id]);
    $_SESSION['flash_success'] = 'Device binding berhasil direset. PC bisa login dari perangkat baru.';
    ob_end_clean();
    header('Location: licenses.php');
    exit;
}

if (isset($_GET['reset_user_devices']) && is_numeric($_GET['reset_user_devices'])) {
    $user_id = (int)$_GET['reset_user_devices'];
    $db->prepare("UPDATE license_keys SET device_id = NULL, device_bound_at = NULL, device_profile_hash = NULL, device_profile_updated_at = NULL, device_auth_token = NULL, token_bound_at = NULL, last_ip = NULL, last_user_agent = NULL WHERE user_id = ?")
        ->execute([$user_id]);
    $db->prepare("
        DELETE lp FROM license_profiles lp
        INNER JOIN license_keys lk ON lk.id = lp.license_key_id
        WHERE lk.user_id = ?
    ")->execute([$user_id]);
    $_SESSION['flash_success'] = 'Semua device binding untuk user ini berhasil direset.';
    ob_end_clean();
    header('Location: licenses.php');
    exit;
}

$licenses_raw = $db->query("
    SELECT lk.*, u.username, u.full_name
    FROM license_keys lk
    JOIN users u ON lk.user_id = u.id
    WHERE lk.is_deleted = 0
    ORDER BY u.username ASC, lk.id DESC
")->fetchAll();

$licenses_by_user = [];
foreach ($licenses_raw as $lk) {
    $uid = $lk['user_id'];
    if (!isset($licenses_by_user[$uid])) {
        $licenses_by_user[$uid] = [
            'user_id'   => $uid,
            'username'  => $lk['username'],
            'full_name' => $lk['full_name'] ?: $lk['username'],
            'keys'      => []
        ];
    }
    $licenses_by_user[$uid]['keys'][] = $lk;
}

$operators = $db->query("
    SELECT id, username, full_name, license_quota, portal_access FROM users
    WHERE is_active = 1 AND role = 'operator'
    ORDER BY username
")->fetchAll();

$pc_counts = [];
$rows = $db->query("
    SELECT user_id, task_type, mode, COUNT(*) as cnt
    FROM license_keys
    WHERE is_active = 1 AND is_deleted = 0
    GROUP BY user_id, task_type, mode
")->fetchAll();
foreach ($rows as $r) {
    $uid = (int) $r['user_id'];
    $count_val = (int) $r['cnt'];
    if ($r['task_type'] === 'skrining_bpjs')
        $pc_counts[$uid]['extension_bpjs'] = (int) ($pc_counts[$uid]['extension_bpjs'] ?? 0) + $count_val;
    else
        $pc_counts[$uid][$r['task_type'] . '_' . $r['mode']] = $count_val;
}
?>
<main class="flex-1 p-4 md:p-6 xl:p-8 bg-slate-50/50">
    <div class="w-full max-w-[1820px] mx-auto">
        <div class="mb-7 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center shadow-sm flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-black text-slate-900 tracking-tight leading-none">License Keys</h2>
                    <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Generate dan kelola kunci lisensi aktif untuk tiap PC operator</p>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full md:w-auto">
                <div class="relative w-full sm:w-64">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input type="text" id="search_license" onkeyup="filter_licenses()" placeholder="Cari operator atau key..." class="w-full bg-white border border-slate-200 text-slate-900 text-sm rounded-xl focus:ring-slate-500 focus:border-slate-500 block pl-10 p-2.5 outline-none transition-all">
                </div>
                <button onclick="document.getElementById('modalGen').classList.remove('hidden')" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-sm font-black transition-all shadow-sm active:scale-95 whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                    </svg>
                    Generate Key Baru
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="mb-5 flex items-center gap-3 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700 text-sm font-medium">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <?= $success ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-5 flex items-center gap-3 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-600 text-sm font-medium">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[800px]">
                    <thead>
                        <tr class="bg-slate-50 text-[11px] font-bold text-slate-400 uppercase tracking-widest">
                            <th class="px-5 py-3 text-left">License Key</th>
                            <th class="px-5 py-3 text-left">PC Label</th>
                            <th class="px-5 py-3 text-left">Task</th>
                            <th class="px-5 py-3 text-left">Mode</th>
                            <th class="px-5 py-3 text-left">Device</th>
                            <th class="px-5 py-3 text-left">Last Seen</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php if (empty($licenses_by_user)): ?>
                            <tr>
                                <td colspan="8" class="px-5 py-10 text-center text-slate-400">Belum ada license key.</td>
                            </tr>
                            <?php else: foreach ($licenses_by_user as $u_id => $u_data): ?>


                                <tr class="user-group bg-slate-50/70" data-group="<?= $u_id ?>">
                                    <td colspan="8" class="px-5 py-3 border-t border-b border-slate-100">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs font-bold shrink-0">
                                                    <?= strtoupper(substr($u_data['username'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="font-bold text-slate-800 text-sm leading-tight"><?= h($u_data['full_name']) ?></div>
                                                    <div class="text-[10px] text-slate-500 font-mono flex items-center gap-2 mt-0.5">
                                                        @<?= h($u_data['username']) ?>
                                                        <span class="px-1.5 py-0.5 rounded-md bg-white border border-slate-200 text-slate-600 font-bold"><?= count($u_data['keys']) ?> PC</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <a href="licenses.php?reset_user_devices=<?= $u_id ?>&csrf_token=<?= csrf_token() ?>"
                                                onclick="return confirm('Reset SEMUA device binding untuk user ini? Semua PC harus login ulang.')"
                                                class="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 text-slate-600 hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 rounded-lg text-[10px] font-bold transition-all shadow-sm">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                </svg>
                                                Reset Semua Device
                                            </a>
                                        </div>
                                    </td>
                                </tr>


                                <?php foreach ($u_data['keys'] as $lk): ?>
                                    <tr class="hover:bg-slate-50 transition-colors license-row" data-group="<?= $u_id ?>" data-search="<?= strtolower(h($lk['license_key'] . ' ' . $lk['pc_label'] . ' ' . $lk['task_type'] . ' ' . $lk['mode'] . ' ' . $u_data['username'] . ' ' . $u_data['full_name'])) ?>">
                                        <td class="px-5 py-3.5 pl-6 sm:pl-16">
                                            <div class="flex items-center gap-2">
                                                <code class="text-xs font-mono text-teal-700 bg-teal-50 px-2 py-1 rounded-lg tracking-widest border border-teal-100">
                                                    <?= h($lk['license_key']) ?>
                                                </code>
                                                <button onclick="copy_text('<?= h($lk['license_key']) ?>', this)"
                                                    class="text-slate-300 hover:text-teal-600 transition-colors" title="Copy">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                            d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <span class="px-2.5 py-1 bg-white border border-slate-200 text-slate-700 rounded-lg text-xs font-bold shadow-sm">
                                                <?= h($lk['pc_label']) ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <?php
                                            $task_type_chip = $lk['task_type'] === 'pendaftaran'
                                                ? 'bg-blue-50 text-blue-700 border border-blue-200'
                                                : ($lk['task_type'] === 'pelayanan'
                                                    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                                    : 'bg-amber-50 text-amber-700 border border-amber-200');
                                            $task_type_text = str_replace('_', ' ', (string) $lk['task_type']);
                                            ?>
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= $task_type_chip ?>">
                                                <?= h($task_type_text) ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-slate-100 text-slate-500 border border-slate-200">
                                                <?= h($lk['mode']) ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <?php if (!empty($lk['device_id'])): ?>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="w-2 h-2 rounded-full bg-emerald-400 flex-shrink-0"></span>
                                                    <span class="text-[10px] font-mono text-slate-500 truncate max-w-[80px]" title="<?= h($lk['device_id']) ?>">
                                                        <?= substr($lk['device_id'], 0, 8) ?>…
                                                    </span>
                                                </div>
                                                <?php if ($lk['device_bound_at']): ?>
                                                    <div class="text-[9px] text-slate-300 mt-0.5"><?= date('d/m/y', strtotime($lk['device_bound_at'])) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-[10px] text-slate-300 italic">Belum ada</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3.5 text-xs text-slate-400">
                                            <?= $lk['last_seen']
                                                ? date('d/m/Y H:i', strtotime($lk['last_seen']))
                                                : '<span class="text-slate-300">Belum pernah</span>' ?>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase
                                <?= $lk['is_active']
                                        ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                        : 'bg-slate-100 text-slate-400 border border-slate-200' ?>">
                                                <?= $lk['is_active'] ? 'Aktif' : 'Revoked' ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <div class="flex items-center justify-end gap-1">
                                                <?php if (!empty($lk['device_id'])): ?>
                                                    <a href="licenses.php?reset_device=<?= $lk['id'] ?>&csrf_token=<?= csrf_token() ?>"
                                                        onclick="return confirm('Reset device binding key ini? PC lama tidak bisa login lagi, PC baru bisa login.')"
                                                        class="p-1.5 text-slate-400 hover:text-sky-600 hover:bg-sky-50 rounded-lg transition-colors"
                                                        title="Reset Device">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                        </svg>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="licenses.php?toggle=<?= $lk['id'] ?>&csrf_token=<?= csrf_token() ?>"
                                                    class="p-1.5 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                                                    title="<?= $lk['is_active'] ? 'Revoke' : 'Aktifkan' ?>">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                            d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                    </svg>
                                                </a>
                                                <a href="licenses.php?delete=<?= $lk['id'] ?>&csrf_token=<?= csrf_token() ?>"
                                                    onclick="return confirm('Hapus license key ini?')"
                                                    class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                                                    title="Hapus">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                        <?php endforeach;
                            endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
</main>


<div id="modalGen" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-bold text-slate-800">Generate License Key</h3>
            <button onclick="document.getElementById('modalGen').classList.add('hidden')"
                class="text-slate-400 hover:text-slate-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">
                    User <span class="text-rose-400">*</span>
                </label>
                <select name="user_id" required id="select_user" onchange="update_pc_info(this)"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-teal-500 transition-colors">
                    <option value="">Pilih user...</option>
                    <?php foreach ($operators as $op):
                        $used_total = array_sum($pc_counts[$op['id']] ?? []);
                        $lq_raw = json_decode($op['license_quota'] ?? '{}', true) ?: [];
                        $access_type = ((int)($op['portal_access'] ?? 1) === 0) ? 'extension' : 'dashboard';
                        $lq_json = htmlspecialchars(json_encode($lq_raw), ENT_QUOTES);
                        $used_json = htmlspecialchars(json_encode($pc_counts[$op['id']] ?? []), ENT_QUOTES);
                        // Cek apakah semua kuota dari tiap jenis sudah penuh (opsional, untuk disabled)
                        $is_all_full = true;
                        if (!empty($lq_raw)) {
                            foreach ($lq_raw as $k => $v) {
                                if (($pc_counts[$op['id']][$k] ?? 0) < (int)$v) {
                                    $is_all_full = false;
                                    break;
                                }
                            }
                        } else {
                            $is_all_full = false;
                        }
                    ?>
                        <option value="<?= $op['id'] ?>"
                            data-used="<?= $used_total ?>"
                            data-access-type="<?= h($access_type) ?>"
                            data-lq="<?= $lq_json ?>"
                            data-used-combo="<?= $used_json ?>"
                            <?= $is_all_full && array_sum($lq_raw) > 0 ? 'disabled' : '' ?>>
                            <?= h($op['full_name'] ?: $op['username']) ?> (@<?= h($op['username']) ?>)
                            <?= $is_all_full && array_sum($lq_raw) > 0 ? ' [PENUH]' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <p id="pc_info_text" class="text-[10px] text-slate-400 mt-1 hidden"></p>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">
                    Label PC <span class="text-rose-400">*</span>
                </label>
                <input type="text" name="pc_label" required
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-teal-500 transition-colors"
                    placeholder="contoh: PC-A1, PC-B2">
            </div>
            <div id="task_mode_wrap" class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Task</label>
                    <select name="task_type" id="sel_task_type" onchange="update_mode_options()"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-teal-500 transition-colors">
                        <option value="pendaftaran">Pendaftaran</option>
                        <option value="pelayanan">Pelayanan</option>
                        <option value="skrining_bpjs">Skrining BPJS</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Mode</label>
                    <select name="mode" id="sel_mode" onchange="update_mode_options()"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-teal-500 transition-colors">
                        <option value="umum">Umum</option>
                        <option value="sekolah">Sekolah</option>
                        <option value="bpjs">BPJS</option>
                    </select>
                </div>
            </div>
            <p id="auto_task_hint" class="hidden text-[10px] text-slate-500 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2">Jenis lisensi otomatis: Skrining BPJS.</p>
            <div id="lq_status_info" class="text-[10px] text-slate-400 hidden"></div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="document.getElementById('modalGen').classList.add('hidden')"
                    class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-bold transition-colors">
                    Batal
                </button>
                <button type="submit"
                    class="flex-1 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold transition-colors">
                    Generate
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function copy_text(text, btn) {
        navigator.clipboard.writeText(text).then(() => {
            const orig = btn.innerHTML;
            btn.innerHTML = `<svg class="w-3.5 h-3.5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>`;
            setTimeout(() => btn.innerHTML = orig, 2000);
        });
    }


    function update_pc_info(sel) {
        const opt = sel.options[sel.selectedIndex];
        const info = document.getElementById('pc_info_text');
        const used = parseInt(opt.dataset.used) || 0;
        const max = parseInt(opt.dataset.max) || 0;
        if (!opt.value) {
            info.classList.add('hidden');
            return;
        }
        if (max > 0) {
            info.textContent = `PC aktif: ${used} / ${max} (sisa ${max - used} slot)`;
            info.className = `text-[10px] mt-1 ${used >= max ? 'text-rose-500 font-bold' : 'text-slate-400'}`;
        } else {
            info.textContent = `PC aktif: ${used} (tidak ada batas)`;
            info.className = 'text-[10px] mt-1 text-slate-400';
        }
        info.classList.remove('hidden');
        update_mode_options();
    }

    function update_mode_options() {
        const sel = document.getElementById('select_user');
        const opt = sel?.options[sel.selectedIndex];
        if (!opt || !opt.value) {
            const task_mode_wrap = document.getElementById('task_mode_wrap');
            const auto_task_hint = document.getElementById('auto_task_hint');
            if (task_mode_wrap) task_mode_wrap.classList.remove('hidden');
            if (auto_task_hint) auto_task_hint.classList.add('hidden');
            return;
        }
        const lq = JSON.parse(opt.dataset.lq || '{}');
        const usedCombo = JSON.parse(opt.dataset.usedCombo || '{}');
        const is_extension_user = (opt.dataset.accessType || 'dashboard') === 'extension';
        const task_mode_wrap = document.getElementById('task_mode_wrap');
        const auto_task_hint = document.getElementById('auto_task_hint');
        const task_sel = document.getElementById('sel_task_type');
        if (task_mode_wrap) task_mode_wrap.classList.toggle('hidden', is_extension_user);
        if (auto_task_hint) auto_task_hint.classList.toggle('hidden', !is_extension_user);
        if (is_extension_user && task_sel) task_sel.value = 'skrining_bpjs';
        const task_type = task_sel?.value || 'pendaftaran';
        const mode_sel = document.getElementById('sel_mode');
        let mode = mode_sel?.value || 'umum';
        const is_skrining_bpjs = task_type === 'skrining_bpjs';
        if (mode_sel) {
            Array.from(mode_sel.options).forEach((row) => {
                row.hidden = is_skrining_bpjs ? row.value !== 'bpjs' : row.value === 'bpjs';
            });
            if (is_skrining_bpjs) {
                mode_sel.value = 'bpjs';
                mode_sel.disabled = true;
                mode = 'bpjs';
            } else {
                if (mode_sel.value === 'bpjs')
                    mode_sel.value = 'umum';
                mode_sel.disabled = false;
                mode = mode_sel.value || 'umum';
            }
        }
        const key = is_skrining_bpjs ? 'extension_bpjs' : ((task_type || 'pendaftaran') + '_' + (mode || 'umum'));
        const max_slot = parseInt(lq[key]) || 0;
        const used_slot = parseInt(usedCombo[key]) || 0;
        const lq_info = document.getElementById('lq_status_info');

        const combo_labels = {
            pendaftaran_umum: 'Pendaftaran Umum',
            pelayanan_umum: 'Pelayanan Umum',
            pendaftaran_sekolah: 'Pendaftaran Sekolah',
            pelayanan_sekolah: 'Pelayanan Sekolah',
            extension_bpjs: 'Skrining BPJS',
        };
        const all_combos = is_extension_user ? ['extension_bpjs'] : Object.keys(combo_labels);
        let labels = all_combos.map(k => {
            const m = parseInt(lq[k]) || 0;
            const u = parseInt(usedCombo[k]) || 0;
            if (m === 0) return `<span class="text-rose-400">${combo_labels[k]}: tidak dibeli</span>`;
            return `<span class="${u>=m?'text-rose-400 font-bold':'text-emerald-600'}">${combo_labels[k]}: ${u}/${m}</span>`;
        });

        if (lq_info) {
            lq_info.innerHTML = 'Slot: ' + labels.join(' &nbsp;|&nbsp; ');
            lq_info.classList.remove('hidden');
        }

        const btn_gen = document.querySelector('#modalGen [type=submit]');
        if (btn_gen) {
            if (max_slot === 0) {
                btn_gen.disabled = true;
                btn_gen.className = 'flex-1 py-2.5 bg-slate-200 text-slate-400 rounded-xl text-sm font-bold cursor-not-allowed';
                btn_gen.textContent = is_extension_user ? 'Slot Skrining tidak dibeli' : 'Jenis ini tidak dibeli';
            } else if (used_slot >= max_slot) {
                btn_gen.disabled = true;
                btn_gen.className = 'flex-1 py-2.5 bg-rose-100 text-rose-500 rounded-xl text-sm font-bold cursor-not-allowed';
                btn_gen.textContent = `Slot Penuh (${used_slot}/${max_slot})`;
            } else {
                btn_gen.disabled = false;
                btn_gen.className = 'flex-1 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold transition-colors';
                btn_gen.textContent = is_extension_user ?
                    `Generate Skrining (sisa ${max_slot - used_slot} slot)` :
                    `Generate (sisa ${max_slot - used_slot} slot)`;
            }
        }
    }


    function filter_licenses() {
        var input = document.getElementById('search_license').value.toLowerCase();

        // Ambil semua grup
        var groups = document.querySelectorAll('tr.user-group');

        groups.forEach(function(group_row) {
            var group_id = group_row.getAttribute('data-group');
            var child_rows = document.querySelectorAll('tr.license-row[data-group="' + group_id + '"]');

            var has_visible_child = false;

            child_rows.forEach(function(row) {
                var text = row.getAttribute('data-search') || '';
                if (text.indexOf(input) > -1) {
                    row.style.display = '';
                    has_visible_child = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Sembunyikan Header Grup jika tidak ada satupun anaknya yang masuk filter pencarian
            if (has_visible_child) {
                group_row.style.display = '';
            } else {
                group_row.style.display = 'none';
            }
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>