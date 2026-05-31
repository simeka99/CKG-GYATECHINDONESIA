<?php
if (!defined('APP_URL')) {
    require_once __DIR__ . '/../../config/db.php';
}
if (!function_exists('auth_check')) {
    require_once __DIR__ . '/../../includes/functions.php';
}

$is_direct = !isset($db);

if ($is_direct) {
    auth_check('admin');
    $db  = db();
    $now = time();
}

if (!isset($now)) {
    $now = time();
}

if (!function_exists('tbl_days_left')) {
    function tbl_days_left(?string $end_str): ?int
    {
        if (!$end_str) return null;
        $today = new DateTime(date('Y-m-d'));
        $end   = new DateTime(date('Y-m-d', strtotime($end_str)));
        if ($today > $end) return 0;
        return (int)$today->diff($end)->days;
    }
}

if (!function_exists('table_default_wali_profile')) {
    function table_default_wali_profile(mixed $value, string $full_name = '', string $no_hp = ''): array
    {
        $profile = [
            'wali_nik' => '',
            'wali_nama' => '',
            'wali_no_hp' => trim($no_hp),
            'wali_instansi_puskesmas' => trim($full_name),
            'wali_tanggal_lahir' => '',
            'wali_jenis_kelamin' => 'Perempuan',
        ];
        if (!is_string($value) || trim($value) === '')
            return $profile;
        $decoded = json_decode($value, true);
        if (!is_array($decoded))
            return $profile;
        $merged = array_merge($profile, $decoded);
        $wali_name = strtolower(trim((string)($merged['wali_nama'] ?? '')));
        $operator_name = strtolower(trim((string)$full_name));
        $wali_nik = trim((string)($merged['wali_nik'] ?? ''));
        if ($wali_name !== '' && $operator_name !== '' && $wali_name === $operator_name && $wali_nik === '')
            $merged['wali_nama'] = '';
        return $merged;
    }
}

/** @var PDO $db */
try {
    $operators = $db->query(
        "SELECT id, username, full_name, no_hp, is_active,
                subscription_type, subscription_start, subscription_end,
                subscription_note, quota_total, quota_used, license_quota, portal_access, default_wali_profile
         FROM users WHERE role = 'operator' ORDER BY id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $operators = $db->query(
        "SELECT id, username, full_name, no_hp, is_active,
                subscription_type, subscription_start, subscription_end,
                subscription_note, quota_total, quota_used
         FROM users WHERE role = 'operator' ORDER BY id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($operators as &$row) {
        $row['license_quota'] = null;
        $row['portal_access'] = 1;
        $row['default_wali_profile'] = null;
    }
    unset($row);
}

if (empty($operators)) {
    echo '<tr><td colspan="5" class="px-6 py-16 text-center">';
    echo '<svg class="w-12 h-12 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
    echo '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>';
    echo '<p class="text-sm text-slate-400 font-semibold">Belum ada operator</p>';
    echo '</td></tr>';
    if ($is_direct) exit;
    return;
}

foreach ($operators as $op):
    $is_quota  = ($op['subscription_type'] === 'quota');
    $days_left = (!$is_quota && $op['subscription_end']) ? tbl_days_left($op['subscription_end']) : null;
    $expired   = !$is_quota && $op['subscription_end'] && strtotime($op['subscription_end']) <= $now;
    $quota_ok  = $is_quota && (int)$op['quota_used'] < (int)$op['quota_total'];

    if (!$op['is_active'])                         $st = 'suspended';
    elseif ($is_quota && !$quota_ok)               $st = 'habis';
    elseif (!$is_quota && $expired)                $st = 'expired';
    elseif (!$is_quota && !$op['subscription_end']) $st = 'noset';
    else                                           $st = 'active';

    $badge = [
        'active'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'expired'   => 'bg-rose-50 text-rose-600 border-rose-200',
        'habis'     => 'bg-rose-50 text-rose-600 border-rose-200',
        'suspended' => 'bg-slate-100 text-slate-500 border-slate-200',
        'noset'     => 'bg-amber-50 text-amber-600 border-amber-200',
    ][$st];
    $blabel = [
        'active' => 'Aktif',
        'expired' => 'Expired',
        'habis' => 'Kuota Habis',
        'suspended' => 'Suspended',
        'noset' => 'Belum Diset',
    ][$st];

    $op_json = htmlspecialchars(json_encode([
        'id'                => (int)$op['id'],
        'username'          => $op['username'],
        'full_name'         => $op['full_name'] ?? '',
        'no_hp'             => $op['no_hp'] ?? '',
        'is_active'         => (int)$op['is_active'],
        'subscription_type' => $op['subscription_type'],
        'subscription_end'  => $op['subscription_end'],
        'subscription_note' => $op['subscription_note'] ?? '',
        'quota_total'       => (int)$op['quota_total'],
        'quota_used'        => (int)$op['quota_used'],
        'portal_access'     => isset($op['portal_access']) ? (int)$op['portal_access'] : 1,
        'license_quota'     => json_decode($op['license_quota'] ?? '{}', true) ?: (object)[],
        'default_wali_profile' => table_default_wali_profile($op['default_wali_profile'] ?? '', (string)($op['full_name'] ?? ''), (string)($op['no_hp'] ?? '')),
    ]), ENT_QUOTES, 'UTF-8');
?>
    <tr class="hover:bg-slate-50 transition-colors" data-id="<?= (int)$op['id'] ?>">
        <td class="px-4 py-3">
            <div class="font-bold text-slate-800"><?= h($op['full_name'] ?: '—') ?></div>
            <div class="text-xs text-slate-400 font-mono">@<?= h($op['username']) ?></div>
            <?php if (!empty($op['no_hp'])): ?>
                <div class="text-[10.5px] font-medium text-slate-400 mt-1 flex items-center gap-1">
                    <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                    <?= h($op['no_hp']) ?>
                </div>
            <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-xs text-slate-500"><?= h($op['subscription_note'] ?: '—') ?></td>
        <td class="px-4 py-3">
            <?php if ($is_quota):
                $used  = (int)$op['quota_used'];
                $total = (int)$op['quota_total'];
                $pct   = $total > 0 ? min(100, (int)round($used / $total * 100)) : 0;
            ?>
                <div class="text-xs font-bold text-blue-700">
                    <?= number_format($total - $used) ?> sisa
                    <span class="font-normal text-slate-400">/ <?= number_format($total) ?> NIK</span>
                </div>
                <div class="mt-1 h-1 w-24 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-400 rounded-full" style="width:<?= $pct ?>%"></div>
                </div>
            <?php elseif ($op['subscription_end']): ?>
                <div class="text-xs text-slate-600">s/d <?= date('d/m/Y', strtotime($op['subscription_end'])) ?></div>
                <?php if ($days_left !== null): ?>
                    <div class="text-[11px] font-bold mt-0.5 <?= $days_left <= 3 ? 'text-rose-500' : ($days_left <= 7 ? 'text-amber-500' : 'text-slate-400') ?>">
                        <?= $days_left > 0 ? 'sisa ' . $days_left . ' hari' : 'EXPIRED' ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-xs text-amber-500 font-medium">Belum diset</span>
            <?php endif; ?>
        </td>
        <td class="px-4 py-3">
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase border <?= $badge ?>">
                <?= $blabel ?>
            </span>
        </td>
        <td class="px-4 py-3">
            <div class="flex items-center justify-end gap-1">
                <?php if ($is_quota): ?>
                    <button onclick='openAddQuota(<?= (int)$op['id'] ?>, <?= json_encode($op['full_name'] ?: $op['username']) ?>, <?= (int)$op['quota_total'] ?>, <?= (int)$op['quota_used'] ?>)'
                        class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Tambah Kuota">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>
                    <form method="POST" action="user/actions.php" class="inline" style="margin:0;" onsubmit="return confirm('Reset pemakaian NIK akun ini kembali menjadi 0?')">
                        <input type="hidden" name="action" value="reset_quota">
                        <input type="hidden" name="user_id" value="<?= (int)$op['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Reset Kuota">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    </form>
                <?php endif; ?>

                <button onclick='openEditModal(<?= $op_json ?>)'
                    class="p-1.5 text-slate-400 hover:text-teal-600 hover:bg-teal-50 rounded-lg transition-colors" title="Edit">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                </button>

                <a href="user/actions.php?toggle=<?= (int)$op['id'] ?>&csrf_token=<?= csrf_token() ?>"
                    onclick="return confirm('<?= $op['is_active'] ? 'Suspend' : 'Aktifkan' ?> operator ini?')"
                    class="p-1.5 rounded-lg transition-colors <?= $op['is_active'] ? 'text-slate-400 hover:text-amber-600 hover:bg-amber-50' : 'text-slate-400 hover:text-emerald-600 hover:bg-emerald-50' ?>"
                    title="<?= $op['is_active'] ? 'Suspend' : 'Aktifkan' ?>">
                    <?php if ($op['is_active']): ?>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                    <?php else: ?>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    <?php endif; ?>
                </a>

                <form method="POST" action="user/actions.php" class="inline" style="margin:0;" onsubmit="return confirm('Yakin hapus akun ini secara permanen?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= (int)$op['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Hapus Akun">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </form>
            </div>
        </td>
    </tr>
<?php endforeach;

if ($is_direct) exit;
