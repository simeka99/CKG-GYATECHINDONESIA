<?php


if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
if (!is_valid_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
    $csrf_input = trim((string) ($_POST['csrf_token'] ?? ''));
    if (ob_get_level()) ob_end_clean();
    if ($csrf_input === '') {
        ensure_csrf_token();
        header('Location: jobs.php?scope=' . urlencode(get_scope_mode()) . '&csrf_refreshed=1');
        exit;
    }
    $_SESSION['flash_error'] = 'Aksi tidak valid (CSRF).';
    header('Location: jobs.php?scope=' . urlencode(get_scope_mode()));
    exit;
}

$act   = $_POST['action'] ?? '';
$lk_id = (int)($_POST['license_key_id'] ?? 0);
$scope_mode = get_scope_mode();
$scope_mode_sql = $scope_mode === 'sekolah'
    ? "LOWER(COALESCE(mode,''))='sekolah'"
    : "LOWER(COALESCE(mode,'umum'))='umum'";
$scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope=" . $db->quote($scope_mode);

$lk = null;
if ($lk_id) {
    $s = $db->prepare("SELECT * FROM license_keys WHERE id=? AND user_id=? AND is_active=1 AND {$scope_mode_sql} LIMIT 1");
    $s->execute([$lk_id, $uid]);
    $lk = $s->fetch();
}

function redirect(string $url = 'jobs.php'): void
{
    if (ob_get_level()) ob_end_clean();
    if (strpos($url, 'scope=') === false) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'scope=' . urlencode(get_scope_mode());
    }
    header('Location: ' . $url);
    exit;
}

function insert_failed_x(PDO $db, int $uid, int $patient_id, int $lk_id, string $task_type, string $error_msg, string $reg_code): void
{
    $db->prepare("
        INSERT INTO job_failed_x
            (user_id, patient_id, license_key_id, task_type, error_msg, reg_code, attempt, failed_at)
        VALUES
            (?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            attempt   = attempt + 1,
            error_msg = VALUES(error_msg),
            reg_code  = VALUES(reg_code),
            failed_at = NOW()
    ")->execute([$uid, $patient_id, $lk_id, $task_type, $error_msg, $reg_code]);
}


if ($act === 'pc_save_fetch_filter') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
        redirect();
    }

    $upload_id = max(0, (int) ($_POST['fetch_upload_id'] ?? 0));
    if ($upload_id > 0) {
        $s = $db->prepare("SELECT id FROM patient_uploads WHERE id=? AND user_id=? AND ckg_scope=? LIMIT 1");
        $s->execute([$upload_id, $uid, $scope_mode]);
        if (!$s->fetch()) {
            $upload_id = 0;
        }
    }

    $saved_filter = save_job_fetch_filter_settings($uid, $lk_id, [
        'upload_id' => $upload_id,
        'gender' => (string) ($_POST['fetch_gender'] ?? ''),
        'age_min' => (string) ($_POST['fetch_age_min'] ?? ''),
        'age_max' => (string) ($_POST['fetch_age_max'] ?? ''),
    ]);

    $has_custom_filter = ((int) $saved_filter['use_filter'] === 1) || ((int) $saved_filter['upload_id'] > 0);
    $_SESSION['flash_success'] = $has_custom_filter
        ? 'Pengaturan filter antrian untuk ' . $lk['pc_label'] . ' berhasil disimpan.'
        : 'Pengaturan filter direset ke mode umum untuk ' . $lk['pc_label'] . '.';
    redirect();
}

if ($act === 'pc_fetch') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
        redirect();
    }

    $lim  = max(1, min(9999, (int)($_POST['fetch_limit'] ?? 50)));
    $tt   = $lk['task_type'];
    $has_request_filter = isset($_POST['fetch_upload_id']) || isset($_POST['fetch_gender']) || isset($_POST['fetch_age_min']) || isset($_POST['fetch_age_max']) || isset($_POST['fetch_use_filter']);
    $saved_filter = get_job_fetch_filter_settings($uid, $lk_id);
    $active_filter = $has_request_filter
        ? normalize_job_fetch_filter_settings([
            'upload_id' => (string) ($_POST['fetch_upload_id'] ?? 0),
            'gender' => (string) ($_POST['fetch_gender'] ?? ''),
            'age_min' => (string) ($_POST['fetch_age_min'] ?? ''),
            'age_max' => (string) ($_POST['fetch_age_max'] ?? ''),
        ])
        : $saved_filter;

    $upload_id = max(0, (int) ($active_filter['upload_id'] ?? 0));
    $upload_label = 'Semua File';
    if ($upload_id > 0) {
        $s = $db->prepare("SELECT file_name FROM patient_uploads WHERE id=? AND user_id=? AND ckg_scope=? LIMIT 1");
        $s->execute([$upload_id, $uid, $scope_mode]);
        $up = $s->fetch();
        if (!$up) {
            $upload_id = 0;
        } else {
            $upload_label = (string) ($up['file_name'] ?? 'Semua File');
        }
    }

    $use_filter = ((int) ($active_filter['use_filter'] ?? 0) === 1);
    $gender = (string) ($active_filter['gender'] ?? '');
    $age_min = $active_filter['age_min'];
    $age_max = $active_filter['age_max'];

    $filters = [
        'use_filter' => $use_filter,
        'gender' => $gender,
        'age_min' => $age_min,
        'age_max' => $age_max,
    ];

    $rows = fetch_rows($db, $uid, $tt, $lim, $filters, $upload_id);
    $is_custom_take = $use_filter || $upload_id > 0;

    if (empty($rows)) {
        if ($is_custom_take) {
            $gender_label = $gender === 'L' ? 'Laki-laki' : ($gender === 'P' ? 'Perempuan' : 'Semua');
            $usia_label = ($age_min !== null || $age_max !== null)
                ? trim(($age_min !== null ? $age_min : '0') . ' - ' . ($age_max !== null ? $age_max : '150') . ' tahun')
                : 'Semua usia';
            $_SESSION['flash_error'] = "Tidak ada data cocok untuk filter ({$upload_label}, {$gender_label}, {$usia_label}).";
        } else {
            $_SESSION['flash_error'] = 'Tidak ada data tersedia untuk diambil.';
        }
        redirect();
    }

    $db->beginTransaction();
    try {
        $ins = $db->prepare("
            INSERT IGNORE INTO job_queue
                (user_id, patient_id, license_key_id, task_type, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $n = 0;
        foreach ($rows as $r) {
            $ins->execute([$uid, $r['patient_id'], $lk_id, $tt]);
            if ($ins->rowCount()) $n++;
        }

        $s = $db->prepare("
            SELECT COUNT(*) FROM job_queue
            WHERE user_id=? AND license_key_id=? AND status IN ('pending','running')
        ");
        $s->execute([$uid, $lk_id]);
        $queue_before = (int)$s->fetchColumn() - $n;

        $s = $db->prepare("SELECT COUNT(*) FROM job_failed WHERE user_id=? AND license_key_id=?");
        $s->execute([$uid, $lk_id]);
        $failed_before = (int)$s->fetchColumn();

        $is_new_batch = ($queue_before <= 0 && $failed_before === 0);

        if ($is_new_batch) {
            $db->prepare("UPDATE license_keys SET batch_total=? WHERE id=? AND user_id=?")
                ->execute([$n, $lk_id, $uid]);
        } else {
            $db->prepare("UPDATE license_keys SET batch_total=batch_total+? WHERE id=? AND user_id=?")
                ->execute([$n, $lk_id, $uid]);
        }

        $db->commit();
        if ($is_custom_take) {
            $gender_label = $gender === 'L' ? 'Laki-laki' : ($gender === 'P' ? 'Perempuan' : 'Semua');
            $usia_label = ($age_min !== null || $age_max !== null)
                ? trim(($age_min !== null ? $age_min : '0') . ' - ' . ($age_max !== null ? $age_max : '150') . ' tahun')
                : 'Semua usia';
            $_SESSION['flash_success'] = "{$n} data (filter: {$upload_label}, {$gender_label}, {$usia_label}) berhasil diambil ke antrian {$lk['pc_label']}.";
        } else {
            $_SESSION['flash_success'] = "{$n} data berhasil diambil ke antrian {$lk['pc_label']}.";
        }
    } catch (Throwable $e) {
        $db->rollBack();
        $_SESSION['flash_error'] = 'Gagal ambil data: ' . $e->getMessage();
    }
    redirect();
}

if ($act === 'pc_start') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
    } else {
        $ok = do_pc_start($db, $uid, $lk_id);
        $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok
            ? 'Sinyal START dikirim ke ' . $lk['pc_label'] . '.'
            : 'Tidak ada antrian pending di ' . $lk['pc_label'] . '.';
    }
    redirect();
}

if ($act === 'pc_stop') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
    } else {
        do_pc_stop($db, $uid, $lk_id);
        $_SESSION['flash_success'] = 'Proses ' . $lk['pc_label'] . ' dihentikan. Antrian pending tetap tersimpan.';
    }
    redirect();
}

if ($act === 'pc_clear_queue') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
    } else {
        $db->prepare("UPDATE license_keys SET is_running=0, batch_total=0 WHERE id=? AND user_id=?")
            ->execute([$lk_id, $uid]);
        $s = $db->prepare("
            DELETE FROM job_queue
            WHERE user_id=? AND license_key_id=? AND status IN ('pending','running')
              AND patient_id IN ({$scope_patient_sub})
        ");
        $s->execute([$uid, $lk_id]);
        $_SESSION['flash_success'] = $s->rowCount() . ' antrian dihapus dari ' . $lk['pc_label'] . '.';
    }
    redirect();
}

if ($act === 'pc_retry') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
        redirect();
    }

    $db->beginTransaction();
    try {
        $s = $db->prepare("
            SELECT *
            FROM job_failed
            WHERE user_id=? AND license_key_id=?
              AND patient_id IN ({$scope_patient_sub})
              AND COALESCE(is_no_retry, 0) = 0
              AND (
                    reg_code IS NULL
                 OR reg_code = ''
                 OR UPPER(reg_code) NOT IN (
                    'SISTEM_MENOLAK',
                    'DUKCAPIL_UPDATE',
                    'DUKCAPIL',
                    'DATA_TIDAK_DITEMUKAN',
                    'VALIDASI_TIDAK_VALID',
                    'VALIDASI_PESERTA_WALI_TIDAK_VALID',
                    'SUDAH_TERDAFTAR',
                    'SUDAH_MENERIMA_LAYANAN',
                    'BATAS_KIRIM_RAPOR_HABIS',
                    'NOT_IN_LIST'
                 )
              )
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH MENERIMA LAYANAN%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH TERDAFTAR%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI TIDAK VALID%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI_PESERTA_WALI_TIDAK_VALID%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%BATAS KIRIM RAPOR HABIS%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
        ");
        $s->execute([$uid, $lk_id]);
        $failed_rows = $s->fetchAll();

        if (empty($failed_rows)) {
            $db->rollBack();
            $_SESSION['flash_error'] = 'Tidak ada job yang bisa di-retry.';
            redirect();
        }

        $ins = $db->prepare("
            INSERT INTO job_queue
                (user_id, patient_id, license_key_id, task_type, status, attempt, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
            ON DUPLICATE KEY UPDATE
                status    = 'pending',
                locked_at = NULL,
                attempt   = attempt + 1
        ");

        $ids = [];
        foreach ($failed_rows as $r) {
            $ins->execute([
                $r['user_id'],
                $r['patient_id'],
                $r['license_key_id'],
                $r['task_type'],
                (int)$r['attempt'] + 1,
            ]);
            $ids[] = (int)$r['id'];
        }

        $del_ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM job_failed WHERE id IN ({$del_ph})")->execute($ids);

        $db->commit();

        do_pc_start($db, $uid, $lk_id);
        $_SESSION['flash_success'] = count($ids) . " job di-retry & {$lk['pc_label']} langsung berjalan otomatis.";
    } catch (Throwable $e) {
        $db->rollBack();
        $_SESSION['flash_error'] = 'Gagal retry: ' . $e->getMessage();
    }
    redirect();
}

if ($act === 'pc_selesaikan') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
        redirect();
    }

    $s = $db->prepare("
        SELECT SUM(status='pending') p, SUM(status='running') r
        FROM job_queue WHERE user_id=? AND license_key_id=?
          AND patient_id IN ({$scope_patient_sub})
    ");
    $s->execute([$uid, $lk_id]);
    $row = $s->fetch();

    $still_pend = (int)($row['p'] ?? 0);
    $still_run  = (int)($row['r'] ?? 0);

    if ($still_pend > 0 || $still_run > 0) {
        $sisa = [];
        if ($still_run  > 0) $sisa[] = $still_run  . ' sedang berjalan';
        if ($still_pend > 0) $sisa[] = $still_pend . ' masih pending';
        $_SESSION['flash_error'] = 'Belum bisa diselesaikan. Masih ada ' . implode(' dan ', $sisa) . '.';
    } else {
        $db->prepare("DELETE FROM job_queue  WHERE user_id=? AND license_key_id=? AND patient_id IN ({$scope_patient_sub})")->execute([$uid, $lk_id]);
        $db->prepare("DELETE FROM job_failed WHERE user_id=? AND license_key_id=? AND patient_id IN ({$scope_patient_sub})")->execute([$uid, $lk_id]);
        $db->prepare("UPDATE license_keys SET is_running=0, batch_total=0 WHERE id=? AND user_id=?")
            ->execute([$lk_id, $uid]);
        $_SESSION['flash_success'] = 'Antrian ' . $lk['pc_label'] . ' diselesaikan & progress direset.';
    }
    redirect();
}

if ($act === 'pc_delete_item') {
    $jq_id = (int)($_POST['jq_id'] ?? 0);
    if ($jq_id) {
        $db->prepare("
            DELETE FROM job_queue
            WHERE id=? AND user_id=? AND status='pending'
              AND patient_id IN ({$scope_patient_sub})
        ")->execute([$jq_id, $uid]);
    }
    redirect();
}

if ($act === 'pc_mark_manual_success') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
        redirect();
    }

    $row_id = (int)($_POST['row_id'] ?? 0);
    $source_type = strtolower(trim((string)($_POST['source_type'] ?? 'queue')));
    $source_table = $source_type === 'failed' ? 'job_failed' : 'job_queue';
    if ($row_id <= 0) {
        $_SESSION['flash_error'] = 'Data peserta tidak valid.';
        redirect();
    }

    $st = $db->prepare("
        SELECT *
        FROM {$source_table}
        WHERE id = ? AND user_id = ? AND license_key_id = ?
          AND patient_id IN ({$scope_patient_sub})
        LIMIT 1
    ");
    $st->execute([$row_id, $uid, $lk_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $_SESSION['flash_error'] = 'Data tidak ditemukan.';
        redirect();
    }

    $db->beginTransaction();
    try {
        $task_type = (string)($row['task_type'] ?? '');
        $patient_id = (int)($row['patient_id'] ?? 0);
        $manual_result_data = json_encode([
            'status_reg' => 'MANUAL_SUKSES',
            'status_text' => 'Manual sukses dari jobdesk',
            'manual_action' => true,
            'source_table' => $source_table,
            'source_row_id' => $row_id,
        ], JSON_UNESCAPED_UNICODE);

        $ins = $db->prepare("
            INSERT INTO job_success
                (user_id, license_key_id, patient_id, task_type, source_job_id, result_data, duration_ms, finished_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        $ins->execute([
            $uid,
            $lk_id,
            $patient_id,
            $task_type,
            $row['source_job_id'] ?? null,
            $manual_result_data,
        ]);

        $db->prepare("DELETE FROM {$source_table} WHERE id = ? AND user_id = ?")->execute([$row_id, $uid]);
        $db->prepare("DELETE FROM job_failed WHERE user_id = ? AND patient_id = ? AND task_type = ?")->execute([$uid, $patient_id, $task_type]);
        $db->prepare("DELETE FROM job_failed_x WHERE user_id = ? AND patient_id = ? AND task_type = ?")->execute([$uid, $patient_id, $task_type]);

        if ($task_type === 'pendaftaran') {
            $db->prepare("UPDATE patients_data SET daftar_done = 1 WHERE id = ? AND user_id = ? AND ckg_scope = ?")
                ->execute([$patient_id, $uid, $scope_mode]);
        } elseif ($task_type === 'pelayanan') {
            $db->prepare("UPDATE patients_data SET layanan_done = 1 WHERE id = ? AND user_id = ? AND ckg_scope = ?")
                ->execute([$patient_id, $uid, $scope_mode]);
        }

        $db->prepare("
            UPDATE patients_data
            SET status = 'success',
                processed_at = NOW(),
                error_message = NULL
            WHERE id = ? AND user_id = ? AND ckg_scope = ?
        ")->execute([$patient_id, $uid, $scope_mode]);

        $db->commit();
        $_SESSION['flash_success'] = 'Peserta dipindah ke sukses manual.';
    } catch (Throwable $e) {
        $db->rollBack();
        $_SESSION['flash_error'] = 'Gagal set sukses manual: ' . $e->getMessage();
    }
    redirect();
}

if ($act === 'pc_mark_manual_failed') {
    if (!$lk) {
        $_SESSION['flash_error'] = 'PC tidak valid.';
        redirect();
    }

    $row_id = (int)($_POST['row_id'] ?? 0);
    $source_type = strtolower(trim((string)($_POST['source_type'] ?? 'queue')));
    if ($source_type !== 'queue' || $row_id <= 0) {
        $_SESSION['flash_error'] = 'Data peserta tidak valid.';
        redirect();
    }

    $st = $db->prepare("
        SELECT *
        FROM job_queue
        WHERE id = ? AND user_id = ? AND license_key_id = ?
          AND patient_id IN ({$scope_patient_sub})
        LIMIT 1
    ");
    $st->execute([$row_id, $uid, $lk_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $_SESSION['flash_error'] = 'Data antrian tidak ditemukan.';
        redirect();
    }

    $db->beginTransaction();
    try {
        $task_type = (string)($row['task_type'] ?? '');
        $patient_id = (int)($row['patient_id'] ?? 0);
        $error_msg = 'Manual gagal dari jobdesk';

        $db->prepare("
            INSERT INTO job_failed
                (user_id, license_key_id, patient_id, task_type, source_job_id, error_msg, reg_code, is_no_retry, attempt, failed_at)
            VALUES (?, ?, ?, ?, ?, ?, 'MANUAL_GAGAL', 0, ?, NOW())
            ON DUPLICATE KEY UPDATE
                error_msg = VALUES(error_msg),
                reg_code = VALUES(reg_code),
                attempt = attempt + 1,
                failed_at = NOW()
        ")->execute([
            $uid,
            $lk_id,
            $patient_id,
            $task_type,
            $row['source_job_id'] ?? null,
            $error_msg,
            (int)($row['attempt'] ?? 1),
        ]);

        $db->prepare("DELETE FROM job_queue WHERE id = ? AND user_id = ?")->execute([$row_id, $uid]);

        $db->prepare("
            UPDATE patients_data
            SET status = 'retry',
                retry_count = retry_count + 1,
                error_message = ?
            WHERE id = ? AND user_id = ? AND ckg_scope = ?
        ")->execute([$error_msg, $patient_id, $uid, $scope_mode]);

        $db->commit();
        $_SESSION['flash_success'] = 'Peserta dipindah ke gagal manual.';
    } catch (Throwable $e) {
        $db->rollBack();
        $_SESSION['flash_error'] = 'Gagal set gagal manual: ' . $e->getMessage();
    }
    redirect();
}


if ($act === 'global_start') {
    $db->prepare("
        UPDATE license_keys SET is_running=1, started_at=NOW()
        WHERE user_id=? AND is_active=1 AND {$scope_mode_sql}
          AND id IN (
              SELECT DISTINCT license_key_id
              FROM job_queue
              WHERE user_id=? AND status='pending'
                AND patient_id IN ({$scope_patient_sub})
          )
    ")->execute([$uid, $uid]);
    $_SESSION['flash_success'] = 'Sinyal START dikirim ke semua PC yang punya antrian.';
    redirect();
}

if ($act === 'global_stop') {
    $db->prepare("UPDATE license_keys SET is_running=0 WHERE user_id=? AND is_active=1 AND {$scope_mode_sql}")->execute([$uid]);
    $db->prepare("
        UPDATE job_queue SET status='pending', locked_at=NULL, started_at=NULL
        WHERE user_id=? AND status='running'
          AND patient_id IN ({$scope_patient_sub})
          AND license_key_id IN (SELECT id FROM license_keys WHERE user_id=? AND is_active=1 AND {$scope_mode_sql})
    ")->execute([$uid, $uid]);
    $_SESSION['flash_success'] = 'Semua proses dihentikan. Antrian pending tetap tersimpan.';
    redirect();
}

if ($act === 'global_retry') {
    $db->beginTransaction();
    try {
        $s = $db->prepare("
            SELECT *
            FROM job_failed
            WHERE user_id=?
              AND patient_id IN ({$scope_patient_sub})
              AND license_key_id IN (SELECT id FROM license_keys WHERE user_id=? AND is_active=1 AND {$scope_mode_sql})
              AND COALESCE(is_no_retry, 0) = 0
              AND (
                    reg_code IS NULL
                 OR reg_code = ''
                 OR UPPER(reg_code) NOT IN (
                    'SISTEM_MENOLAK',
                    'DUKCAPIL_UPDATE',
                    'DUKCAPIL',
                    'DATA_TIDAK_DITEMUKAN',
                    'VALIDASI_TIDAK_VALID',
                    'VALIDASI_PESERTA_WALI_TIDAK_VALID',
                    'SUDAH_TERDAFTAR',
                    'SUDAH_MENERIMA_LAYANAN',
                    'BATAS_KIRIM_RAPOR_HABIS',
                    'NOT_IN_LIST'
                 )
              )
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH MENERIMA LAYANAN%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH TERDAFTAR%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI TIDAK VALID%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI_PESERTA_WALI_TIDAK_VALID%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%BATAS KIRIM RAPOR HABIS%'
              AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
        ");
        $s->execute([$uid, $uid]);
        $all = $s->fetchAll();

        if (empty($all)) {
            $db->rollBack();
            $_SESSION['flash_error'] = 'Tidak ada job yang bisa di-retry.';
            redirect();
        }

        $ins = $db->prepare("
            INSERT INTO job_queue
                (user_id, patient_id, license_key_id, task_type, status, attempt, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
            ON DUPLICATE KEY UPDATE
                status    = 'pending',
                locked_at = NULL,
                attempt   = attempt + 1
        ");

        $ids     = [];
        $lk_ids  = [];
        foreach ($all as $r) {
            $ins->execute([
                $r['user_id'],
                $r['patient_id'],
                $r['license_key_id'],
                $r['task_type'],
                (int)$r['attempt'] + 1,
            ]);
            $ids[]    = (int)$r['id'];
            $lk_ids[] = (int)$r['license_key_id'];
        }

        $del_ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM job_failed WHERE id IN ({$del_ph})")->execute($ids);

        $db->commit();

        foreach (array_unique($lk_ids) as $lid) {
            do_pc_start($db, $uid, $lid);
        }

        $_SESSION['flash_success'] = count($ids) . ' job di-retry di semua PC & PC di-start otomatis.';
    } catch (Throwable $e) {
        $db->rollBack();
        $_SESSION['flash_error'] = 'Gagal global retry: ' . $e->getMessage();
    }
    redirect();
}

if ($act === 'global_clear_queue') {
    $db->prepare("UPDATE license_keys SET is_running=0, batch_total=0 WHERE user_id=? AND is_active=1 AND {$scope_mode_sql}")->execute([$uid]);
    $s = $db->prepare("
        DELETE FROM job_queue
        WHERE user_id=? AND status IN ('pending','running')
          AND patient_id IN ({$scope_patient_sub})
          AND license_key_id IN (SELECT id FROM license_keys WHERE user_id=? AND is_active=1 AND {$scope_mode_sql})
    ");
    $s->execute([$uid, $uid]);
    $_SESSION['flash_success'] = $s->rowCount() . ' antrian dihapus dari semua PC. Data sukses & arsip tetap tersimpan.';
    redirect();
}

if ($act === 'global_selesaikan') {
    $s = $db->prepare("
        SELECT SUM(status='pending') p, SUM(status='running') r
        FROM job_queue
        WHERE user_id=?
          AND patient_id IN ({$scope_patient_sub})
          AND license_key_id IN (SELECT id FROM license_keys WHERE user_id=? AND is_active=1 AND {$scope_mode_sql})
    ");
    $s->execute([$uid, $uid]);
    $row = $s->fetch();

    $still_pend = (int)($row['p'] ?? 0);
    $still_run  = (int)($row['r'] ?? 0);

    if ($still_pend > 0 || $still_run > 0) {
        $sisa = [];
        if ($still_run  > 0) $sisa[] = $still_run  . ' sedang berjalan';
        if ($still_pend > 0) $sisa[] = $still_pend . ' masih pending';
        $_SESSION['flash_error'] = 'Belum bisa diselesaikan. Masih ada ' . implode(' dan ', $sisa) . '.';
    } else {
        $db->prepare("DELETE FROM job_queue WHERE user_id=? AND patient_id IN ({$scope_patient_sub}) AND license_key_id IN (SELECT id FROM license_keys WHERE user_id=? AND is_active=1 AND {$scope_mode_sql})")->execute([$uid, $uid]);
        $db->prepare("DELETE FROM job_failed WHERE user_id=? AND patient_id IN ({$scope_patient_sub}) AND license_key_id IN (SELECT id FROM license_keys WHERE user_id=? AND is_active=1 AND {$scope_mode_sql})")->execute([$uid, $uid]);
        $db->prepare("UPDATE license_keys SET is_running=0, batch_total=0 WHERE user_id=? AND is_active=1 AND {$scope_mode_sql}")->execute([$uid]);
        $_SESSION['flash_success'] = 'Semua antrian diselesaikan & progress direset.';
    }
    redirect();
}
