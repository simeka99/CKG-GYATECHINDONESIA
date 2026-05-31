<?php
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/common.php';

if (!isset($db)) {
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/session.php';
    $db  = db();
    $uid = (int)($_SESSION['user_id'] ?? 0);
}

if (!$uid) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$scope_mode = get_scope_mode();

$render_last_ts = (int)($_SESSION['monitor_maintenance_ts'] ?? 0);
if ($render_last_ts < 1 || (time() - $render_last_ts) >= 600) {
    monitoring_sync_legacy_status($db, $uid);
    monitoring_cleanup_orphan_rows($db, $uid);
    $_SESSION['monitor_maintenance_ts'] = time();
}
monitoring_reclassify_invalid_success_rows($db, $uid);
session_write_close();

$tab      = $_GET['tab'] ?? 'sukses';
$page     = max(1, (int)($_GET['page'] ?? 1));
$allowed  = [25, 50, 100, 9999];
$per_page = in_array((int)($_GET['per_page'] ?? 25), $allowed) ? (int)$_GET['per_page'] : 25;
$search   = trim($_GET['q'] ?? '');
$offset   = ($page - 1) * $per_page;

$name_expr = "JSON_UNQUOTE(COALESCE(
    NULLIF(JSON_EXTRACT(pd.data, '$.\"Nama Pasien\"'), 'null'),
    NULLIF(JSON_EXTRACT(pd.data, '$.Nama'), 'null'),
    NULLIF(JSON_EXTRACT(pd.data, '$.nama'), 'null'),
    NULLIF(JSON_EXTRACT(pd.data, '$.NAMA'), 'null'),
    '\"-\"'
))";

$rows  = [];
$total = 0;
$err   = '';

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmt_ts_r(?string $ts): string
{
    if (!$ts) return '-';
    return substr($ts, 0, 16);
}

function type_chip_r(string $type): string
{
    $map = [
        'pendaftaran' => ['bg-blue-100 text-blue-700',   'Daftar'],
        'pelayanan'   => ['bg-green-100 text-green-700', 'Layan'],
    ];
    [$cls, $lbl] = $map[$type] ?? ['bg-slate-100 text-slate-500', $type ?: '-'];
    return "<span class=\"px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {$cls}\">{$lbl}</span>";
}

function status_badge_r(string $status): string
{
    $status = strtoupper(trim($status));
    $map = [
        'TERDAFTAR_BARU' => ['bg-emerald-100 text-emerald-700', 'Terdaftar Baru'],
        'TERDAFTAR'      => ['bg-teal-100 text-teal-700',       'Terdaftar'],
        'DILAYANI'       => ['bg-blue-100 text-blue-700',       'Dilayani'],
        'SUDAH_MENERIMA_LAYANAN' => ['bg-cyan-100 text-cyan-700', 'Sudah Menerima Layanan'],
        'VALIDASI_TIDAK_VALID'   => ['bg-red-100 text-red-700',   'Validasi Tidak Valid'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['bg-slate-100 text-slate-500', $status ?: '-'];
    return "<span class=\"px-2 py-0.5 rounded-full text-[10px] font-bold {$cls}\">{$lbl}</span>";
}

function error_badge_r(string $code, string $msg): array
{
    $code = monitoring_normalize_error_code($code, $msg);
    $map  = [
        'SISTEM_MENOLAK'       => ['bg-orange-50 text-orange-700 border-orange-200',    'Sistem Menolak',  'Tidak memenuhi syarat',         false],
        'DUKCAPIL_UPDATE'      => ['bg-blue-50 text-blue-700 border-blue-200',          'Dukcapil Update', 'Data perlu diperbarui',         false],
        'DUKCAPIL'             => ['bg-blue-50 text-blue-700 border-blue-200',          'Dukcapil Error',  'Gagal verifikasi Dukcapil',     false],
        'DATA_TIDAK_DITEMUKAN' => ['bg-rose-50 text-rose-700 border-rose-200',          'Tidak Ditemukan', 'Peserta tidak ada di sistem',   false],
        'VALIDASI_TIDAK_VALID' => ['bg-red-50 text-red-700 border-red-200',             'Validasi Tidak Valid', 'Data validasi tidak memenuhi syarat', false],
        'VALIDASI_PESERTA_WALI_TIDAK_VALID' => ['bg-red-50 text-red-700 border-red-200', 'Validasi Wali Tidak Valid', 'Data peserta/wali tidak valid', false],
        'SUDAH_TERDAFTAR'      => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Sudah Terdaftar', 'Sudah terdaftar sebelumnya',    false],
        'SUDAH_MENERIMA_LAYANAN' => ['bg-teal-50 text-teal-700 border-teal-200',        'Sudah Menerima Layanan', 'Peserta sudah menerima layanan', false],
        'BATAS_KIRIM_RAPOR_HABIS' => ['bg-rose-50 text-rose-700 border-rose-200',       'Batas Kirim Rapor Habis', 'Batas kirim rapor peserta sudah habis', false],
        'MANUAL_GAGAL'         => ['bg-slate-100 text-slate-700 border-slate-200',      'Manual Gagal', 'Ditandai gagal manual oleh user', true],
        'NOT_IN_LIST'          => ['bg-purple-50 text-purple-700 border-purple-200',    'Tidak di Daftar', 'Tidak ada di antrian hari ini', false],
        'DATA_TIDAK_VALID'     => ['bg-slate-100 text-slate-700 border-slate-200',      'Data Tidak Valid', 'Format data peserta tidak valid', false],
    ];
    return $map[$code] ?? ['bg-amber-50 text-amber-700 border-amber-200', 'Error Teknis', $msg ?: '-', true];
}

function error_detail_r(string $code, string $msg): string
{
    $normalized_code = monitoring_normalize_error_code($code, $msg);
    $detail_text = trim((string)$msg);
    $map = [
        'VALIDASI_TIDAK_VALID' => 'Data peserta tidak valid - pastikan nama, NIK, dan tanggal lahir sesuai KTP/KK',
        'VALIDASI_PESERTA_WALI_TIDAK_VALID' => 'Data peserta atau wali tidak valid - pastikan data peserta dan wali sesuai KTP/KK',
        'SISTEM_MENOLAK' => 'Sistem menolak - peserta tidak memenuhi syarat CKG',
        'DUKCAPIL_UPDATE' => 'Data Dukcapil perlu diperbarui',
        'DUKCAPIL' => 'Verifikasi Dukcapil gagal - cek NIK dan nama peserta',
        'DATA_TIDAK_DITEMUKAN' => 'Data peserta tidak ditemukan di sistem',
        'SUDAH_TERDAFTAR' => 'Peserta sudah terdaftar sebelumnya',
        'SUDAH_MENERIMA_LAYANAN' => 'Peserta sudah menerima layanan',
        'BATAS_KIRIM_RAPOR_HABIS' => 'Batas kirim rapor habis - klik Selesaikan Pemeriksaan di web CKG lalu lanjut peserta berikutnya',
        'MANUAL_GAGAL' => 'Ditandai gagal manual oleh user',
        'NOT_IN_LIST' => 'Peserta tidak ada di daftar antrian',
        'DATA_TIDAK_VALID' => 'Format data peserta tidak valid',
    ];

    if ($detail_text === '') return $map[$normalized_code] ?? '-';

    $detail_upper = strtoupper($detail_text);
    if ($detail_upper === $normalized_code) return $map[$normalized_code] ?? $detail_text;
    if (preg_match('/^[A-Z0-9_ ]+$/', $detail_text)) return $map[$normalized_code] ?? $detail_text;

    return $detail_text;
}

if ($tab === 'sukses') {
    $filter_status = $_GET['filter_status'] ?? '';
    $where  = "js.user_id = ? AND pd.ckg_scope = ?";
    $params = [$uid, $scope_mode];

    if ($search !== '') {
        $like     = "%{$search}%";
        $where   .= " AND (pd.nik_index LIKE ? OR {$name_expr} LIKE ?)";
        $params[] = $like;
        $params[] = $like;
    }
    if ($filter_status !== '') {
        $where   .= " AND JSON_UNQUOTE(JSON_EXTRACT(js.result_data, '$.status_reg')) = ?";
        $params[] = $filter_status;
    }

    try {
        $st = $db->prepare("SELECT COUNT(*) FROM job_success js LEFT JOIN patients_data pd ON pd.id = js.patient_id WHERE {$where}");
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $st = $db->prepare("
            SELECT js.id, js.task_type, js.finished_at AS ts, js.result_data,
                   COALESCE(lk.pc_label, '<span class=\"text-rose-500 italic\">Dihapus</span>') AS pc_label, pd.nik_index AS nik, {$name_expr} AS nama
            FROM job_success js
            LEFT JOIN license_keys  lk ON lk.id = js.license_key_id
            LEFT JOIN patients_data pd ON pd.id  = js.patient_id
            WHERE {$where}
            ORDER BY js.id DESC
            LIMIT {$per_page} OFFSET {$offset}
        ");
        $st->execute($params);
        $rows = array_map(function ($row) {
            $res = !empty($row['result_data']) ? (json_decode($row['result_data'], true) ?: []) : [];
            $row['status_reg'] = monitoring_normalize_success_status($res);
            if ($row['status_reg'] === '') $row['status_reg'] = '-';
            return $row;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $ex) {
        $err = $ex->getMessage();
    }
} else {
    $filter_error = $_GET['filter_error'] ?? '';
    $filter_src   = $_GET['filter_src']   ?? '';
    $no_retry_codes = monitoring_no_retry_codes();
    $no_retry_sql = implode(',', array_map([$db, 'quote'], $no_retry_codes));
    $union = "
        SELECT jf.user_id, jf.id, jf.task_type, jf.error_msg, jf.reg_code,
               jf.attempt, jf.failed_at AS ts, COALESCE(lk.pc_label, '<span class=\"text-rose-500 italic\">Dihapus</span>') AS pc_label,
               pd.nik_index AS nik, {$name_expr} AS nama,
               'aktif' AS src,
               IF(jf.reg_code IS NULL OR jf.reg_code = '' OR UPPER(jf.reg_code) NOT IN ({$no_retry_sql}), 1, 0) AS is_retryable
        FROM job_failed jf
        LEFT JOIN license_keys  lk ON lk.id = jf.license_key_id
        LEFT JOIN patients_data pd ON pd.id  = jf.patient_id
        WHERE pd.ckg_scope = " . $db->quote($scope_mode) . "
          AND NOT EXISTS (SELECT 1 FROM job_success js WHERE js.patient_id = jf.patient_id AND js.task_type = jf.task_type AND js.user_id = {$uid})
          AND NOT EXISTS (SELECT 1 FROM job_queue jq WHERE jq.patient_id = jf.patient_id AND jq.task_type = jf.task_type AND jq.user_id = {$uid})

        UNION ALL

        SELECT jfx.user_id, jfx.id, jfx.task_type, jfx.error_msg, jfx.reg_code,
               jfx.attempt, jfx.failed_at AS ts, COALESCE(lk.pc_label, '<span class=\"text-rose-500 italic\">Dihapus</span>') AS pc_label,
               pd.nik_index AS nik, {$name_expr} AS nama,
               'arsip' AS src,
               IF(jfx.reg_code IS NULL OR jfx.reg_code = '' OR UPPER(jfx.reg_code) NOT IN ({$no_retry_sql}), 1, 0) AS is_retryable
        FROM job_failed_x jfx
        LEFT JOIN license_keys  lk ON lk.id = jfx.license_key_id
        LEFT JOIN patients_data pd ON pd.id  = jfx.patient_id
        WHERE pd.ckg_scope = " . $db->quote($scope_mode) . "
          AND NOT EXISTS (SELECT 1 FROM job_success js WHERE js.patient_id = jfx.patient_id AND js.task_type = jfx.task_type AND js.user_id = {$uid})
          AND NOT EXISTS (SELECT 1 FROM job_queue jq WHERE jq.patient_id = jfx.patient_id AND jq.task_type = jfx.task_type AND jq.user_id = {$uid})
    ";

    $where  = "t.user_id = ?";
    $params = [$uid];

    if ($search !== '') {
        $like     = "%{$search}%";
        $where   .= " AND (t.nik LIKE ? OR t.nama LIKE ?)";
        $params[] = $like;
        $params[] = $like;
    }
    if ($filter_error !== '') {
        $where   .= " AND t.reg_code = ?";
        $params[] = $filter_error;
    }
    if ($filter_src !== '') {
        $where   .= " AND t.src = ?";
        $params[] = $filter_src;
    }

    try {
        $st = $db->prepare("SELECT COUNT(*) FROM ({$union}) t WHERE {$where}");
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $st = $db->prepare("SELECT * FROM ({$union}) t WHERE {$where} ORDER BY t.ts DESC LIMIT {$per_page} OFFSET {$offset}");
        $st->execute($params);
        $rows = array_map(function ($row) use ($no_retry_codes) {
            $row['reg_code'] = monitoring_normalize_error_code($row['reg_code'] ?? '', $row['error_msg'] ?? '');
            $row['is_retryable'] = ($row['reg_code'] === '' || !in_array($row['reg_code'], $no_retry_codes, true)) ? 1 : 0;
            return $row;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $ex) {
        $err = $ex->getMessage();
    }
}

$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$from        = $total === 0 ? 0 : ($page - 1) * $per_page + 1;
$to          = min($page * $per_page, $total);

ob_start();
if ($err):
    echo "<tr><td colspan=\"8\" class=\"px-4 py-8 text-center text-xs text-rose-400\">Error: " . e($err) . "</td></tr>";
elseif (empty($rows)):
    $colspan = $tab === 'sukses' ? 7 : 8;
    echo "<tr><td colspan=\"{$colspan}\" class=\"px-4 py-12 text-center\">
        <div class=\"flex flex-col items-center gap-2\">
            <svg class=\"w-8 h-8 text-slate-200\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"1.5\"
                    d=\"M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2\"/>
            </svg>
            <span class=\"text-xs text-slate-300 font-semibold\">Tidak ada data</span>
        </div>
    </td></tr>";
elseif ($tab === 'sukses'):
    foreach ($rows as $i => $row):
        $no      = ($page - 1) * $per_page + $i + 1;
        $detail  = e(json_encode([
            'id' => $row['id'],
            'nik' => format_nik((string) ($row['nik'] ?? '')) ?: '-',
            'nama' => $row['nama'] ?? '-',
            'pc_label' => $row['pc_label'] ?? '-',
            'task_type' => $row['task_type'] ?? '',
            'status_reg' => $row['status_reg'],
            'ts' => $row['ts'] ?? ''
        ]));
?>
        <tr class="hover:bg-emerald-50/40 transition-colors border-b border-slate-50">
            <td class="px-3 py-2.5 text-[10px] text-slate-400 font-mono w-8"><?= $no ?></td>
            <td class="px-3 py-2.5">
                <div class="font-mono text-[10px] text-slate-400 leading-tight"><?= e(format_nik((string)($row['nik'] ?? '')) ?: '-') ?></div>
                <div class="text-xs font-semibold text-slate-700 leading-tight truncate max-w-[180px]"><?= e($row['nama'] ?? '-') ?></div>
            </td>
            <td class="px-3 py-2.5 text-xs text-slate-500 whitespace-nowrap"><?= $row['pc_label'] ?? '-' ?></td>
            <td class="px-3 py-2.5"><?= type_chip_r($row['task_type'] ?? '') ?></td>
            <td class="px-3 py-2.5"><?= status_badge_r($row['status_reg']) ?></td>
            <td class="px-3 py-2.5 text-[10px] text-slate-400 whitespace-nowrap"><?= fmt_ts_r($row['ts'] ?? null) ?></td>
            <td class="px-3 py-2.5">
                <div class="flex items-center justify-center gap-1">
                    <button onclick='show_detail(<?= $detail ?>)'
                        class="p-1.5 rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" title="Detail">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                    <button onclick="mark_failed_from_success(<?= $row['id'] ?>)"
                        class="p-1.5 rounded-lg text-amber-500 hover:bg-amber-50 hover:text-amber-700 transition-colors" title="Set Gagal Manual">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    <button onclick="delete_row('sukses', <?= $row['id'] ?>, '')"
                        class="p-1.5 rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-500 transition-colors" title="Hapus">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr>
    <?php endforeach;
else:
    foreach ($rows as $i => $row):
        $no        = ($page - 1) * $per_page + $i + 1;
        $is_arsip  = ($row['src'] ?? '') === 'arsip';
        $retryable = (int)($row['is_retryable'] ?? 0) === 1;
        [$bcls, $btitle, $bdesc, $can_retry] = error_badge_r($row['reg_code'] ?? '', $row['error_msg'] ?? '');
        $error_detail = error_detail_r($row['reg_code'] ?? '', $row['error_msg'] ?? '');
        $detail = e(json_encode([
            'id' => $row['id'],
            'src' => $row['src'],
            'nik' => format_nik((string) ($row['nik'] ?? '')) ?: '-',
            'nama' => $row['nama'] ?? '-',
            'pc_label' => $row['pc_label'] ?? '-',
            'task_type' => $row['task_type'] ?? '',
            'reg_code' => $row['reg_code'] ?? '',
            'error_msg' => $row['error_msg'] ?? '',
            'error_detail' => $error_detail,
            'attempt' => $row['attempt'] ?? 0,
            'ts' => $row['ts'] ?? ''
        ]));
    ?>
        <tr class="hover:bg-rose-50/20 transition-colors border-b border-slate-50 <?= $is_arsip ? 'opacity-60' : '' ?>">
            <td class="px-3 py-2.5 text-[10px] text-slate-400 font-mono w-8"><?= $no ?></td>
            <td class="px-3 py-2.5">
                <div class="font-mono text-[10px] text-slate-400 leading-tight"><?= e(format_nik((string)($row['nik'] ?? '')) ?: '-') ?></div>
                <div class="text-xs font-semibold text-slate-700 leading-tight truncate max-w-[180px]"><?= e($row['nama'] ?? '-') ?></div>
            </td>
            <td class="px-3 py-2.5 text-xs text-slate-500 whitespace-nowrap"><?= $row['pc_label'] ?? '-' ?></td>
            <td class="px-3 py-2.5"><?= type_chip_r($row['task_type'] ?? '') ?></td>
            <td class="px-3 py-2.5">
                <?php if ($is_arsip): ?>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500">Arsip</span>
                <?php else: ?>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-700">Aktif</span>
                <?php endif; ?>
            </td>
            <td class="px-3 py-2.5">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $bcls ?>"><?= e($btitle) ?></span>
                <div class="text-[10px] text-slate-400 mt-0.5 leading-tight truncate max-w-[140px]"><?= e($error_detail !== '-' ? $error_detail : $bdesc) ?></div>
            </td>
            <td class="px-3 py-2.5 text-[10px] text-slate-400 whitespace-nowrap"><?= fmt_ts_r($row['ts'] ?? null) ?></td>
            <td class="px-3 py-2.5">
                <div class="flex items-center justify-center gap-1">
                    <button onclick='show_detail(<?= $detail ?>)'
                        class="p-1.5 rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" title="Detail">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                    <button onclick="mark_success_from_failed(<?= $row['id'] ?>, '<?= $row['src'] ?>')"
                        class="p-1.5 rounded-lg text-emerald-500 hover:bg-emerald-50 hover:text-emerald-700 transition-colors" title="Set Sukses Manual">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                    </button>
                    <?php if ($retryable && $can_retry): ?>
                        <button onclick="retry_one(<?= $row['id'] ?>, '<?= $row['src'] ?>')"
                            class="p-1.5 rounded-lg text-amber-400 hover:bg-amber-50 hover:text-amber-600 transition-colors" title="Retry">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M1 4v6h6M3.51 15a9 9 0 102.13-9.36L1 10" />
                            </svg>
                        </button>
                    <?php endif; ?>
                    <button onclick="delete_row('gagal', <?= $row['id'] ?>, '<?= $row['src'] ?>')"
                        class="p-1.5 rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-500 transition-colors" title="Hapus">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr>
<?php endforeach;
endif;
$rows_html = ob_get_clean();

ob_start();
if ($total_pages > 1):
    $btn = fn($lbl, $p, $active, $dis) =>
    "<button " . ($dis ? 'disabled' : "onclick=\"goto_page('{$tab}',{$p})\"") . "
            class=\"px-2.5 py-1 rounded-xl text-[10px] font-bold transition-all active:scale-95 " .
        ($active ? 'bg-slate-800 text-white shadow-md' : ($dis    ? 'bg-slate-50 text-slate-300 cursor-not-allowed' :
            'bg-white text-slate-600 hover:bg-slate-800 hover:text-white shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)]')) . "\">{$lbl}</button>";

    echo $btn('<<', 1,          false, $page === 1);
    echo $btn('<', $page - 1,   false, $page === 1);

    $s = max(1, $page - 2);
    $e = min($total_pages, $page + 2);
    for ($i = $s; $i <= $e; $i++)
        echo $btn($i, $i, $i === $page, false);

    echo $btn('>', $page + 1,        false, $page === $total_pages);
    echo $btn('>>', $total_pages,    false, $page === $total_pages);
endif;
$pagination_html = ob_get_clean();

$info = $total === 0
    ? 'Tidak ada data'
    : "Menampilkan {$from}-{$to} dari " . number_format($total, 0, ',', '.') . " data";

$export_rows    = [];
$export_headers = [];

if ($tab === 'sukses') {
    $export_headers = ['NIK', 'Nama', 'PC', 'Tipe', 'Status Reg', 'Waktu'];
    foreach ($rows as $r)
        $export_rows[] = [$r['nik'] ?? '-', $r['nama'] ?? '-', $r['pc_label'] ?? '-', $r['task_type'] ?? '-', $r['status_reg'] ?? '-', fmt_ts_r($r['ts'] ?? null)];
} else {
    $export_headers = ['NIK', 'Nama', 'PC', 'Tipe', 'Sumber', 'Keterangan', 'Waktu'];
    foreach ($rows as $r) {
        [, $btitle] = error_badge_r($r['reg_code'] ?? '', $r['error_msg'] ?? '');
        $export_rows[] = [$r['nik'] ?? '-', $r['nama'] ?? '-', $r['pc_label'] ?? '-', $r['task_type'] ?? '-', $r['src'] ?? '-', $btitle, fmt_ts_r($r['ts'] ?? null)];
    }
}

echo json_encode([
    'rows'           => $rows_html,
    'pagination'     => $pagination_html,
    'info'           => $info,
    'total'          => $total,
    'export_rows'    => $export_rows,
    'export_headers' => $export_headers,
], JSON_UNESCAPED_UNICODE);
