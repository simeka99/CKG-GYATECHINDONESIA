<?php
$scope_mode = get_scope_mode();
ensure_scope_column($db, 'patient_uploads');
ensure_scope_column($db, 'patients_data');

function stop_download(string $message): never
{
    while (ob_get_level() > 0)
        ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function build_xlsx(array $headers, array $rows, string $dl_name): never
{
    if (!class_exists('ZipArchive'))
        stop_download('ZipArchive tidak tersedia di server.');

    if (function_exists('ini_set'))
        @ini_set('display_errors', '0');

    $to_xml_text = static function ($value): string {
        $text = (string) $value;
        if (function_exists('iconv')) {
            $utf8_text = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($utf8_text !== false)
                $text = $utf8_text;
        }
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text) ?? '';
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    $shared = [];
    $si_xml = '';
    $get_sid = function (string $v) use (&$shared, &$si_xml, $to_xml_text): int {
        if (!isset($shared[$v])) {
            $shared[$v] = count($shared);
            $si_xml .= '<si><t xml:space="preserve">' . $to_xml_text($v) . '</t></si>';
        }
        return $shared[$v];
    };

    $sheet_rows = '';
    $all = array_merge(
        [$headers],
        array_map(fn($r) => array_map(fn($h) => $r[$h] ?? '', $headers), $rows)
    );

    foreach ($all as $ri => $row) {
        $rn = $ri + 1;
        $is_hdr = $ri === 0;
        $cells = '';

        foreach ($row as $ci => $val) {
            $col = '';
            $tmp = $ci + 1;
            while ($tmp > 0) {
                $mod = ($tmp - 1) % 26;
                $col = chr(65 + $mod) . $col;
                $tmp = (int) (($tmp - $mod) / 26);
            }

            $sid = $get_sid((string) $val);
            $s = $is_hdr ? ' s="1"' : '';
            $cells .= '<c r="' . $col . $rn . '" t="s"' . $s . '><v>' . $sid . '</v></c>';
        }

        $sheet_rows .= '<row r="' . $rn . '">' . $cells . '</row>';
    }

    $ns_main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $ns_pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $ns_doc = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $ns_ct = 'http://schemas.openxmlformats.org/package/2006/content-types';

    $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . $ns_main . '"><sheetData>' . $sheet_rows . '</sheetData></worksheet>';

    $ss_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="' . $ns_main . '" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">'
        . $si_xml . '</sst>';

    $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="' . $ns_main . '">
  <fonts>
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills>
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD0E4F7"/></fgColor></fill>
  </fills>
  <borders><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
  </cellXfs>
</styleSheet>';

    $wb_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . $ns_main . '" xmlns:r="' . $ns_doc . '">'
        . '<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wb_rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="' . $ns_pkg . '">'
        . '<Relationship Id="rId1" Type="' . $ns_doc . '/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="' . $ns_doc . '/sharedStrings" Target="sharedStrings.xml"/>'
        . '<Relationship Id="rId3" Type="' . $ns_doc . '/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $pkg_rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="' . $ns_pkg . '">'
        . '<Relationship Id="rId1" Type="' . $ns_doc . '/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $ct_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="' . $ns_ct . '">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $tmp = false;
    $tmp_dirs = [
        sys_get_temp_dir(),
        __DIR__ . '/../../uploads/tmp',
        __DIR__ . '/../../uploads',
    ];
    foreach ($tmp_dirs as $tmp_dir) {
        if (!is_string($tmp_dir) || $tmp_dir === '')
            continue;
        if (!is_dir($tmp_dir))
            @mkdir($tmp_dir, 0755, true);
        if (!is_dir($tmp_dir) || !is_writable($tmp_dir))
            continue;
        $tmp = @tempnam($tmp_dir, 'xlsx_');
        if ($tmp !== false)
            break;
    }

    if ($tmp === false)
        stop_download('Gagal membuat file sementara untuk export Excel.');

    $zip = new ZipArchive();
    $zip_open_status = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($zip_open_status !== true) {
        @unlink($tmp);
        stop_download('Gagal membuat arsip Excel.');
    }

    $zip_ok = true;
    $zip_ok = $zip_ok && $zip->addFromString('[Content_Types].xml', $ct_xml);
    $zip_ok = $zip_ok && $zip->addFromString('_rels/.rels', $pkg_rel);
    $zip_ok = $zip_ok && $zip->addFromString('xl/workbook.xml', $wb_xml);
    $zip_ok = $zip_ok && $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rel);
    $zip_ok = $zip_ok && $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
    $zip_ok = $zip_ok && $zip->addFromString('xl/sharedStrings.xml', $ss_xml);
    $zip_ok = $zip_ok && $zip->addFromString('xl/styles.xml', $styles_xml);
    $zip->close();

    if (!$zip_ok) {
        @unlink($tmp);
        stop_download('Gagal menulis konten Excel.');
    }

    clearstatcache(true, $tmp);
    $tmp_size = (int) @filesize($tmp);
    if ($tmp_size <= 0) {
        @unlink($tmp);
        stop_download('File Excel gagal dibuat.');
    }

    $safe_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $dl_name);
    if (!is_string($safe_name) || $safe_name === '' || !str_ends_with(strtolower($safe_name), '.xlsx'))
        $safe_name = 'export_' . date('Ymd_His') . '.xlsx';

    while (ob_get_level() > 0)
        ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safe_name . '"');
    header('Content-Length: ' . $tmp_size);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $stream = @fopen($tmp, 'rb');
    if ($stream === false) {
        @unlink($tmp);
        stop_download('File Excel gagal dibaca untuk diunduh.');
    }

    fpassthru($stream);
    fclose($stream);
    @unlink($tmp);
    exit;
}

if (isset($_GET['delete_row']) && is_numeric($_GET['delete_row'])) {
    if (!is_valid_csrf_token((string) ($_GET['csrf_token'] ?? ''))) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(403);
        die('Aksi tidak valid (CSRF)');
    }

    $row_id = (int) $_GET['delete_row'];

    $db->prepare('DELETE FROM patients_data WHERE id=? AND user_id=? AND ckg_scope=?')
        ->execute([$row_id, $uid, $scope_mode]);

    $upload_id = (int) ($_GET['upload_id'] ?? 0);
    $page = (int) ($_GET['p'] ?? 1);
    $search_q = urlencode($_GET['q'] ?? '');
    $limit_q = (int) ($_GET['limit'] ?? 25);
    $view_q = $_GET['view'] ?? 'all';

    while (ob_get_level() > 0) ob_end_clean();
    header('Location: data.php?scope=' . urlencode($scope_mode) . '&upload_id=' . $upload_id . '&p=' . $page . '&q=' . $search_q . '&limit=' . $limit_q . '&view=' . $view_q . '&msg=row_deleted');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    // Validasi CSRF token
    if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(403);
        die('Aksi tidak valid (CSRF)');
    }

    $ids = array_filter(array_map('intval', (array) ($_POST['selected'] ?? [])));
    $upload_id = (int) ($_POST['upload_id'] ?? 0);
    $view_q = $_POST['view'] ?? 'all';

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $args = array_merge($ids, [$uid, $scope_mode]);

        $db->prepare('DELETE FROM patients_data WHERE id IN (' . $placeholders . ') AND user_id=? AND ckg_scope=?')
            ->execute($args);

        while (ob_get_level() > 0) ob_end_clean();
        header('Location: data.php?scope=' . urlencode($scope_mode) . '&upload_id=' . $upload_id . '&view=' . $view_q . '&msg=bulk_deleted&count=' . count($ids));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_row') {
    if (!is_valid_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(403);
        die('Aksi tidak valid (CSRF)');
    }

    $row_id = (int) ($_POST['row_id'] ?? 0);
    $upload_id = (int) ($_POST['upload_id'] ?? 0);
    $view_q = $_POST['view'] ?? 'all';
    $page = (int) ($_POST['p'] ?? 1);
    $limit_q = (int) ($_POST['limit'] ?? 25);
    $search_q = urlencode($_POST['q'] ?? '');
    $fields = (array) ($_POST['fields'] ?? []);
    $msg_key = 'row_updated';

    if ($row_id && $fields) {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT data FROM patients_data WHERE id=? AND user_id=? AND ckg_scope=? LIMIT 1');
            $stmt->execute([$row_id, $uid, $scope_mode]);
            $existing = $stmt->fetchColumn();

            $data_arr = json_decode($existing, true) ?: [];

            foreach ($fields as $key => $val) {
                $val = trim((string) $val);
                if (is_nik_bpjs_header($key) && strpos($val, '*') !== false) {
                    continue;
                }
                $data_arr[$key] = $val;
            }

            $json_new = json_encode($data_arr, JSON_UNESCAPED_UNICODE);

            $db->prepare('UPDATE patients_data SET data=? WHERE id=? AND user_id=? AND ckg_scope=?')
                ->execute([$json_new, $row_id, $uid, $scope_mode]);

            // Jika data peserta sudah diperbaiki/sinkron BPJS, keluarkan dari list gagal
            // agar kembali tersedia untuk diproses ulang di Jobdesk.
            $db->prepare('DELETE FROM job_failed WHERE user_id=? AND patient_id=?')
                ->execute([$uid, $row_id]);
            $db->prepare('DELETE FROM job_failed_x WHERE user_id=? AND patient_id=?')
                ->execute([$uid, $row_id]);

            $db->prepare("
                UPDATE patients_data
                SET status='pending',
                    error_message=NULL,
                    processed_at=NULL,
                    job_id=NULL,
                    retry_count=0
                WHERE id=? AND user_id=? AND ckg_scope=?
            ")->execute([$row_id, $uid, $scope_mode]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $msg_key = 'row_update_failed';
        }
    } else {
        $msg_key = 'row_update_failed';
    }

    while (ob_get_level() > 0) ob_end_clean();
    header('Location: data.php?scope=' . urlencode($scope_mode) . '&upload_id=' . $upload_id . '&p=' . $page . '&q=' . $search_q . '&limit=' . $limit_q . '&view=' . $view_q . '&msg=' . $msg_key);
    exit;
}

if (isset($_GET['msg'])) {
    $msg_key = $_GET['msg'];
    $map = [
        'row_deleted' => '1 data berhasil dihapus.',
        'bulk_deleted' => number_format((int) ($_GET['count'] ?? 0)) . ' data berhasil dihapus.',
        'row_updated' => '1 data berhasil diperbarui.',
        'row_update_failed' => 'Data gagal diperbarui.',
    ];
    $success = $map[$msg_key] ?? '';
}

if (isset($_GET['dl'])) {
    $download_mode = $_GET['dl'];
    $upload_id = max(0, (int) ($_GET['upload_id'] ?? 0));
    $is_all_upload_mode = $upload_id === 0;

    $scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope=" . $db->quote($scope_mode);
    $sukses_sub = "SELECT patient_id FROM job_success WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})";
    $gagal_sub = "
        SELECT patient_id FROM job_failed   WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})
        UNION
        SELECT patient_id FROM job_failed_x WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})
    ";
    $exclude_sub = "
        SELECT patient_id FROM job_queue    WHERE user_id={$uid} AND patient_id IS NOT NULL AND patient_id IN ({$scope_patient_sub})
        UNION
        {$sukses_sub}
        UNION
        {$gagal_sub}
    ";

    $download_where = match ($download_mode) {
        'sisa' => "AND d.id NOT IN ({$exclude_sub})",
        'sukses' => "AND d.id IN ({$sukses_sub})",
        'gagal' => "AND d.id IN ({$gagal_sub})",
        default => ''
    };

    $dl_headers = [];
    $dl_rows = [];
    $base = 'semua_file_peserta';

    if ($is_all_upload_mode) {
        $header_stmt = $db->prepare("SELECT detected_fields FROM patient_uploads WHERE user_id=? AND ckg_scope=? ORDER BY id DESC");
        $header_stmt->execute([$uid, $scope_mode]);
        $header_seen = [];
        foreach ($header_stmt->fetchAll(PDO::FETCH_COLUMN) as $header_json) {
            $header_list = json_decode((string) $header_json, true) ?: [];
            foreach ($header_list as $header_name) {
                $header_name = trim((string) $header_name);
                if ($header_name === '' || isset($header_seen[$header_name]))
                    continue;
                $header_seen[$header_name] = true;
                $dl_headers[] = $header_name;
            }
        }

        $q = "SELECT d.data FROM patients_data d WHERE d.user_id=? AND d.ckg_scope=? {$download_where} ORDER BY d.id ASC";
        $ds = $db->prepare($q);
        $ds->execute([$uid, $scope_mode]);
    } else {
        $up_stmt = $db->prepare('
            SELECT file_name, detected_fields
            FROM patient_uploads
            WHERE id=? AND user_id=? AND ckg_scope=?
            LIMIT 1
        ');
        $up_stmt->execute([$upload_id, $uid, $scope_mode]);
        $upload = $up_stmt->fetch();

        if (!$upload)
            exit;

        $dl_headers = json_decode($upload['detected_fields'] ?? '[]', true) ?: [];
        $base = pathinfo($upload['file_name'], PATHINFO_FILENAME);

        $q = "SELECT d.data FROM patients_data d WHERE d.upload_id=? AND d.user_id=? AND d.ckg_scope=? {$download_where} ORDER BY d.id ASC";
        $ds = $db->prepare($q);
        $ds->execute([$upload_id, $uid, $scope_mode]);
    }

    foreach ($ds->fetchAll(PDO::FETCH_COLUMN) as $json)
        $dl_rows[] = json_decode($json, true) ?: [];

    if (empty($dl_headers) && !empty($dl_rows)) {
        $header_seen = [];
        foreach ($dl_rows as $row_item) {
            foreach (array_keys($row_item) as $header_name) {
                $header_name = trim((string) $header_name);
                if ($header_name === '' || isset($header_seen[$header_name]))
                    continue;
                $header_seen[$header_name] = true;
                $dl_headers[] = $header_name;
            }
        }
    }

    if (empty($dl_headers))
        stop_download('Tidak ada header data untuk diunduh.');

    $suffix_map = ['sisa' => '_sisa', 'sukses' => '_sukses', 'gagal' => '_gagal', 'all' => '_semua'];
    $suffix = $suffix_map[$download_mode] ?? '_semua';
    $dl_name = $base . $suffix . '_' . date('Ymd_His') . '.xlsx';

    build_xlsx($dl_headers, $dl_rows, $dl_name);

    exit;
}
