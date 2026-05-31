<?php

/**
 * JOBS - Helper Functions
 */

function pd(mixed $raw): array
{
    $d = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
    return [
        'nik'  => $d['NIK'] ?? $d['nik'] ?? '-',
        'nama' => $d['Nama Pasien'] ?? $d['Nama'] ?? $d['nama'] ?? $d['NAMA'] ?? '-',
    ];
}

function job_fetch_filter_setting_key(int $uid, int $license_key_id): string
{
    return 'job_fetch_filter_u' . $uid . '_lk' . $license_key_id;
}

function normalize_job_fetch_filter_settings(array $settings): array
{
    $gender_raw = strtoupper(trim((string) ($settings['gender'] ?? '')));
    $gender = in_array($gender_raw, ['L', 'P'], true) ? $gender_raw : '';

    $age_min_raw = trim((string) ($settings['age_min'] ?? ''));
    $age_max_raw = trim((string) ($settings['age_max'] ?? ''));
    $age_min = ($age_min_raw !== '' && is_numeric($age_min_raw)) ? max(0, min(150, (int) $age_min_raw)) : null;
    $age_max = ($age_max_raw !== '' && is_numeric($age_max_raw)) ? max(0, min(150, (int) $age_max_raw)) : null;

    if ($age_min !== null && $age_max !== null && $age_min > $age_max) {
        [$age_min, $age_max] = [$age_max, $age_min];
    }

    $upload_id_raw = trim((string) ($settings['upload_id'] ?? 0));
    $upload_id = ($upload_id_raw !== '' && is_numeric($upload_id_raw)) ? max(0, (int) $upload_id_raw) : 0;

    $use_filter = ($gender !== '' || $age_min !== null || $age_max !== null);

    return [
        'use_filter' => $use_filter ? 1 : 0,
        'gender' => $gender,
        'age_min' => $age_min,
        'age_max' => $age_max,
        'upload_id' => $upload_id,
    ];
}

function get_job_fetch_filter_settings(int $uid, int $license_key_id): array
{
    $raw = get_setting(job_fetch_filter_setting_key($uid, $license_key_id));
    if ($raw === '') {
        return normalize_job_fetch_filter_settings([]);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return normalize_job_fetch_filter_settings([]);
    }

    return normalize_job_fetch_filter_settings($decoded);
}

function save_job_fetch_filter_settings(int $uid, int $license_key_id, array $settings): array
{
    $normalized = normalize_job_fetch_filter_settings($settings);
    save_setting(
        job_fetch_filter_setting_key($uid, $license_key_id),
        json_encode($normalized, JSON_UNESCAPED_UNICODE)
    );
    return $normalized;
}

function sync_done_flags(PDO $db, int $uid, ?string $scope_mode = null): void
{
    static $synced = [];
    $clean_scope_mode = strtolower(trim((string)($scope_mode ?? get_scope_mode())));
    if (!in_array($clean_scope_mode, ['umum', 'sekolah'], true))
        $clean_scope_mode = 'umum';

    $sync_key = $uid . ':' . $clean_scope_mode;
    if (isset($synced[$sync_key])) return;

    $flag = sys_get_temp_dir() . '/rmik_sync_' . md5($sync_key);
    if (file_exists($flag) && (time() - filemtime($flag)) < 300) {
        $synced[$sync_key] = true;
        return;
    }

    try {
        $db->prepare("
            UPDATE patients_data pd
            INNER JOIN job_success js ON js.patient_id = pd.id AND js.task_type = 'pendaftaran' AND js.user_id = pd.user_id
            SET pd.daftar_done = 1
            WHERE pd.user_id = ? AND pd.ckg_scope = ? AND pd.daftar_done = 0
        ")->execute([$uid, $clean_scope_mode]);

        $db->prepare("
            UPDATE patients_data pd
            LEFT JOIN job_success js ON js.patient_id = pd.id AND js.task_type = 'pendaftaran' AND js.user_id = pd.user_id
            SET pd.daftar_done = 0
            WHERE pd.user_id = ? AND pd.ckg_scope = ? AND pd.daftar_done = 1 AND js.id IS NULL
        ")->execute([$uid, $clean_scope_mode]);

        $db->prepare("
            UPDATE patients_data pd
            INNER JOIN job_success js ON js.patient_id = pd.id AND js.task_type = 'pelayanan' AND js.user_id = pd.user_id
            SET pd.layanan_done = 1
            WHERE pd.user_id = ? AND pd.ckg_scope = ? AND pd.layanan_done = 0
        ")->execute([$uid, $clean_scope_mode]);

        $db->prepare("
            UPDATE patients_data pd
            LEFT JOIN job_success js ON js.patient_id = pd.id AND js.task_type = 'pelayanan' AND js.user_id = pd.user_id
            SET pd.layanan_done = 0
            WHERE pd.user_id = ? AND pd.ckg_scope = ? AND pd.layanan_done = 1 AND js.id IS NULL
        ")->execute([$uid, $clean_scope_mode]);
    } catch (Throwable $e) {
    }

    $synced[$sync_key] = true;
    @file_put_contents($flag, '1');
}

function count_avail(PDO $db, int $uid, string $tt, int $upload_id = 0, ?string $scope_mode = null): int
{
    try {
        $clean_scope_mode = strtolower(trim((string)($scope_mode ?? get_scope_mode())));
        if (!in_array($clean_scope_mode, ['umum', 'sekolah'], true))
            $clean_scope_mode = 'umum';
        sync_done_flags($db, $uid, $clean_scope_mode);

        $done_col = $tt === 'pendaftaran' ? 'daftar_done' : 'layanan_done';
        $done_val = $tt === 'pendaftaran' ? 0 : 1;
        $layanan_filter = $tt === 'pelayanan' ? 'AND pd.layanan_done = 0' : '';

        $s = $db->prepare("
            SELECT COUNT(*) FROM patients_data pd
            WHERE pd.user_id   = ?
              AND pd.ckg_scope = ?
              AND pd.$done_col = $done_val
              $layanan_filter
              AND (? = 0 OR pd.upload_id = ?)
              AND NOT EXISTS (
                  SELECT 1 FROM job_queue jq
                  WHERE jq.patient_id = pd.id AND jq.user_id = pd.user_id
                    AND jq.task_type = ? AND jq.status IN ('pending','running')
              )
              AND NOT EXISTS (
                  SELECT 1 FROM job_failed_x jfx
                  WHERE jfx.patient_id = pd.id AND jfx.user_id = pd.user_id
                    AND jfx.task_type = ?
              )
        ");
        $s->execute([$uid, $clean_scope_mode, $upload_id, $upload_id, $tt, $tt]);
        return (int) $s->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function normalize_patient_text(mixed $v): string
{
    $s = trim((string) $v);
    $s = preg_replace('/\s+/u', ' ', $s);
    return strtolower($s ?? '');
}

function parse_patient_gender(array $data): ?string
{
    foreach ($data as $k => $v) {
        $kn = normalize_patient_text($k);
        if (
            strpos($kn, 'kelamin') === false
            && strpos($kn, 'gender') === false
            && $kn !== 'jk'
            && $kn !== 'jenis kelamin'
        ) {
            continue;
        }

        $val = normalize_patient_text($v);
        if ($val === '') {
            continue;
        }

        if ($val === 'l' || $val === 'lk' || strpos($val, 'laki') !== false || strpos($val, 'pria') !== false || strpos($val, 'male') !== false) {
            return 'L';
        }
        if ($val === 'p' || strpos($val, 'perempuan') !== false || strpos($val, 'wanita') !== false || strpos($val, 'female') !== false) {
            return 'P';
        }
    }
    return null;
}

function parse_patient_birth_date(array $data): ?DateTimeImmutable
{
    foreach ($data as $k => $v) {
        $kn = normalize_patient_text($k);
        if (
            strpos($kn, 'lahir') === false
            && strpos($kn, 'tanggal lahir') === false
            && strpos($kn, 'tgl lahir') === false
            && strpos($kn, 'tgl_lahir') === false
            && strpos($kn, 'birth') === false
            && strpos($kn, 'dob') === false
        ) {
            continue;
        }

        $raw = trim((string) $v);
        if ($raw === '') {
            continue;
        }
        $raw = preg_replace('/\s+/', ' ', $raw);

        $formats = [
            'Y-m-d',
            'Y/m/d',
            'd-m-Y',
            'd/m/Y',
            'd.m.Y',
            'Y-m-d H:i:s',
            'Y/m/d H:i:s',
            'd-m-Y H:i:s',
            'd/m/Y H:i:s',
        ];

        foreach ($formats as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', "{$m[1]}-{$m[2]}-{$m[3]}");
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $raw, $m)) {
            $dt = DateTimeImmutable::createFromFormat('d-m-Y', "{$m[1]}-{$m[2]}-{$m[3]}");
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }
    }

    return null;
}

function calc_patient_age(?DateTimeImmutable $birth_date): ?int
{
    if (!$birth_date) {
        return null;
    }

    $today = new DateTimeImmutable('today');
    if ($birth_date > $today) {
        return null;
    }

    return (int) $birth_date->diff($today)->y;
}

function patient_matches_filters(mixed $raw_data, array $filters): bool
{
    $use_filter = (bool) ($filters['use_filter'] ?? false);
    if (!$use_filter) {
        return true;
    }

    $arr = is_string($raw_data) ? (json_decode($raw_data, true) ?: []) : (is_array($raw_data) ? $raw_data : []);
    if (!is_array($arr) || empty($arr)) {
        return false;
    }

    $gender_filter = strtoupper(trim((string) ($filters['gender'] ?? '')));
    if ($gender_filter !== '') {
        $g = parse_patient_gender($arr);
        if ($g === null || $g !== $gender_filter) {
            return false;
        }
    }

    $age_min = isset($filters['age_min']) && $filters['age_min'] !== '' ? (int) $filters['age_min'] : null;
    $age_max = isset($filters['age_max']) && $filters['age_max'] !== '' ? (int) $filters['age_max'] : null;

    if ($age_min !== null || $age_max !== null) {
        $age = calc_patient_age(parse_patient_birth_date($arr));
        if ($age === null) {
            return false;
        }
        if ($age_min !== null && $age < $age_min) {
            return false;
        }
        if ($age_max !== null && $age > $age_max) {
            return false;
        }
    }

    return true;
}

function fetch_rows(PDO $db, int $uid, string $tt, int $limit, array $filters = [], int $upload_id = 0): array
{
    try {
        sync_done_flags($db, $uid);
        $scope_mode = get_scope_mode();

        $use_filter = (bool) ($filters['use_filter'] ?? false);
        if (!$use_filter) {
            if ($tt === 'pendaftaran') {
                $s = $db->prepare("
                    SELECT pd.id AS patient_id, NULL AS source_job_id
                    FROM patients_data pd
                    WHERE pd.user_id     = ?
                      AND pd.ckg_scope   = ?
                      AND pd.daftar_done = 0
                      AND (? = 0 OR pd.upload_id = ?)
                      AND NOT EXISTS (
                          SELECT 1 FROM job_queue jq
                          WHERE jq.patient_id = pd.id
                            AND jq.user_id    = ?
                            AND jq.task_type  = 'pendaftaran'
                            AND jq.status     IN ('pending','running')
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM job_failed_x jfx
                          WHERE jfx.patient_id = pd.id
                            AND jfx.user_id    = ?
                      )
                    ORDER BY pd.id ASC
                    LIMIT {$limit}
                ");
                $s->execute([$uid, $scope_mode, $upload_id, $upload_id, $uid, $uid]);
                return $s->fetchAll(PDO::FETCH_ASSOC);
            }

            $s = $db->prepare("
                SELECT pd.id AS patient_id, NULL AS source_job_id
                FROM patients_data pd
                WHERE pd.user_id      = ?
                  AND pd.ckg_scope    = ?
                  AND pd.daftar_done  = 1
                  AND pd.layanan_done = 0
                  AND (? = 0 OR pd.upload_id = ?)
                  AND NOT EXISTS (
                      SELECT 1 FROM job_queue jq
                      WHERE jq.patient_id = pd.id
                        AND jq.user_id    = ?
                        AND jq.task_type  = 'pelayanan'
                        AND jq.status     IN ('pending','running')
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM job_failed_x jfx
                      WHERE jfx.patient_id = pd.id
                        AND jfx.user_id    = ?
                  )
                ORDER BY pd.id ASC
                LIMIT {$limit}
            ");
            $s->execute([$uid, $scope_mode, $upload_id, $upload_id, $uid, $uid]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        }

        $base_where = $tt === 'pendaftaran'
            ? "pd.user_id = ? AND pd.ckg_scope = ? AND pd.daftar_done = 0 AND (? = 0 OR pd.upload_id = ?)"
            : "pd.user_id = ? AND pd.ckg_scope = ? AND pd.daftar_done = 1 AND pd.layanan_done = 0 AND (? = 0 OR pd.upload_id = ?)";

        $task_name = $tt === 'pendaftaran' ? 'pendaftaran' : 'pelayanan';
        $chunk_size = max(100, min(800, $limit * 10));
        $last_id = 0;
        $out = [];
        $loop_guard = 0;

        while (count($out) < $limit && $loop_guard < 200) {
            $loop_guard++;
            $s = $db->prepare("
                SELECT pd.id AS patient_id, pd.data AS raw_data
                FROM patients_data pd
                WHERE {$base_where}
                  AND pd.id > ?
                  AND NOT EXISTS (
                      SELECT 1 FROM job_queue jq
                      WHERE jq.patient_id = pd.id
                        AND jq.user_id    = ?
                        AND jq.task_type  = ?
                        AND jq.status     IN ('pending','running')
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM job_failed_x jfx
                      WHERE jfx.patient_id = pd.id
                        AND jfx.user_id    = ?
                  )
                ORDER BY pd.id ASC
                LIMIT {$chunk_size}
            ");
            $s->execute([$uid, $scope_mode, $upload_id, $upload_id, $last_id, $uid, $task_name, $uid]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $r) {
                $pid = (int) ($r['patient_id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $last_id = max($last_id, $pid);

                if (!patient_matches_filters($r['raw_data'] ?? '', $filters)) {
                    continue;
                }

                $out[] = [
                    'patient_id' => $pid,
                    'source_job_id' => null,
                ];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    } catch (Exception $e) {
        return [];
    }
}

/* ======================================================================
   SHARED START / STOP
   Used by: actions.php + poll.php check_schedule
   Keep behavior identical between manual and automatic flow
====================================================================== */

/**
 * Start PC - same behavior as manual Start button.
 * @return bool true if started, false if no pending job
 */
function do_pc_start(PDO $db, int $uid, int $lk_id): bool
{
    $license_scope_mode = 'umum';
    try {
        $scope_stmt = $db->prepare("SELECT LOWER(COALESCE(mode,'umum')) FROM license_keys WHERE id=? AND user_id=? LIMIT 1");
        $scope_stmt->execute([$lk_id, $uid]);
        $mode_value = (string) $scope_stmt->fetchColumn();
        $license_scope_mode = $mode_value === 'sekolah' ? 'sekolah' : 'umum';
    } catch (Throwable $e) {
        $license_scope_mode = 'umum';
    }
    $scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope=" . $db->quote($license_scope_mode);

    $s = $db->prepare("
        SELECT COUNT(*) FROM job_queue
        WHERE user_id=? AND license_key_id=? AND status='pending'
    ");
    $s->execute([$uid, $lk_id]);
    if ((int) $s->fetchColumn() === 0) {
        return false;
    }

    $db->prepare("
        UPDATE license_keys
        SET is_running  = 1,
            started_at  = NOW()
        WHERE id = ? AND user_id = ?
    ")->execute([$lk_id, $uid]);

    return true;
}

/**
 * Stop PC - same behavior as manual Stop button.
 * Reset running jobs back to pending.
 */
function do_pc_stop(PDO $db, int $uid, int $lk_id): void
{
    $license_scope_mode = 'umum';
    try {
        $scope_stmt = $db->prepare("SELECT LOWER(COALESCE(mode,'umum')) FROM license_keys WHERE id=? AND user_id=? LIMIT 1");
        $scope_stmt->execute([$lk_id, $uid]);
        $mode_value = (string) $scope_stmt->fetchColumn();
        $license_scope_mode = $mode_value === 'sekolah' ? 'sekolah' : 'umum';
    } catch (Throwable $e) {
        $license_scope_mode = 'umum';
    }
    $scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope=" . $db->quote($license_scope_mode);

    $db->prepare("
        UPDATE license_keys
        SET is_running = 0
        WHERE id = ? AND user_id = ?
    ")->execute([$lk_id, $uid]);

    $db->prepare("
        UPDATE job_queue
        SET status    = 'pending',
            locked_at = NULL,
            started_at = NULL
        WHERE user_id = ? AND license_key_id = ? AND status = 'running'
    ")->execute([$uid, $lk_id]);
}

function check_schedule(PDO $db, int $uid, int $lk_id, array $pc, string $scope_patient_sub): void
{
    if (!$pc['sched_enabled'] && !$pc['retry_auto']) return;

    $now_time = date('H:i');
    $now_date = date('Y-m-d');
    $now_dt   = date('Y-m-d H:i:s');
    $is_run   = (int)$pc['is_running'];

    if ($pc['sched_enabled'] && $pc['sched_start']) {
        $past_start  = $now_time >= substr($pc['sched_start'], 0, 5);
        $not_today   = ($pc['sched_last_date'] ?? '') !== $now_date;
        $not_running = $is_run === 0;

        if ($past_start && $not_today && $not_running) {
            $started = do_pc_start($db, $uid, $lk_id);
            if ($started) {
                $db->prepare("
                    UPDATE license_keys SET sched_last_date=? WHERE id=?
                ")->execute([$now_date, $lk_id]);
                $is_run = 1;
            }
        }
    }

    if ($pc['sched_enabled'] && $pc['sched_stop_on'] && $pc['sched_stop'] && $is_run === 1) {
        $stop_t      = substr($pc['sched_stop'],  0, 5);
        $start_t     = substr($pc['sched_start'] ?? '00:00', 0, 5);
        $cross_night = $stop_t < $start_t;

        $should_stop = $cross_night
            ? ($now_date !== ($pc['sched_last_date'] ?? '') && $now_time >= $stop_t)
            : ($now_time >= $stop_t);

        if ($should_stop) {
            do_pc_stop($db, $uid, $lk_id);
            $is_run = 0;
        }
    }

    if ($pc['retry_auto'] && $is_run === 1) {
        $last    = $pc['retry_last'] ? strtotime($pc['retry_last']) : 0;
        $elapsed = time() - $last;

        if ($elapsed >= (int)$pc['retry_interval']) {
            $s = $db->prepare("
                SELECT COUNT(*) FROM job_failed
                WHERE user_id=? AND license_key_id=?
                  AND COALESCE(is_no_retry, 0) = 0
                  AND (
                        reg_code IS NULL
                     OR reg_code = ''
                     OR UPPER(reg_code) NOT IN (
                        'SISTEM_MENOLAK','DUKCAPIL_UPDATE','DUKCAPIL',
                        'DATA_TIDAK_DITEMUKAN','VALIDASI_TIDAK_VALID',
                        'VALIDASI_PESERTA_WALI_TIDAK_VALID','SUDAH_TERDAFTAR',
                        'SUDAH_MENERIMA_LAYANAN','BATAS_KIRIM_RAPOR_HABIS','NOT_IN_LIST'
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

            if ((int)$s->fetchColumn() > 0) {
                $db->prepare("
                    INSERT INTO job_queue
                        (user_id, license_key_id, patient_id, task_type, status, created_at)
                    SELECT user_id, license_key_id, patient_id, task_type, 'pending', NOW()
                    FROM job_failed
                    WHERE user_id=? AND license_key_id=?
                      AND COALESCE(is_no_retry, 0) = 0
                      AND (
                            reg_code IS NULL
                         OR reg_code = ''
                         OR UPPER(reg_code) NOT IN (
                            'SISTEM_MENOLAK','DUKCAPIL_UPDATE','DUKCAPIL',
                            'DATA_TIDAK_DITEMUKAN','VALIDASI_TIDAK_VALID',
                            'VALIDASI_PESERTA_WALI_TIDAK_VALID','SUDAH_TERDAFTAR',
                            'SUDAH_MENERIMA_LAYANAN','BATAS_KIRIM_RAPOR_HABIS','NOT_IN_LIST'
                         )
                      )
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH MENERIMA LAYANAN%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH TERDAFTAR%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI TIDAK VALID%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI_PESERTA_WALI_TIDAK_VALID%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%BATAS KIRIM RAPOR HABIS%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
                ")->execute([$uid, $lk_id]);

                $db->prepare("
                    DELETE FROM job_failed
                    WHERE user_id=? AND license_key_id=?
                      AND COALESCE(is_no_retry, 0) = 0
                      AND (
                            reg_code IS NULL
                         OR reg_code = ''
                         OR UPPER(reg_code) NOT IN (
                            'SISTEM_MENOLAK','DUKCAPIL_UPDATE','DUKCAPIL',
                            'DATA_TIDAK_DITEMUKAN','VALIDASI_TIDAK_VALID',
                            'VALIDASI_PESERTA_WALI_TIDAK_VALID','SUDAH_TERDAFTAR',
                            'SUDAH_MENERIMA_LAYANAN','BATAS_KIRIM_RAPOR_HABIS','NOT_IN_LIST'
                         )
                      )
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH MENERIMA LAYANAN%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%SUDAH TERDAFTAR%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI TIDAK VALID%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%VALIDASI_PESERTA_WALI_TIDAK_VALID%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%BATAS KIRIM RAPOR HABIS%'
                      AND UPPER(COALESCE(error_msg, '')) NOT LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
                ")->execute([$uid, $lk_id]);

                $db->prepare("
                    UPDATE license_keys SET retry_last=? WHERE id=?
                ")->execute([$now_dt, $lk_id]);
            }
        }
    }
}
