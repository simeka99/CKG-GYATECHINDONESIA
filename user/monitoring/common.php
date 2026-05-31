<?php

function monitoring_no_retry_codes(): array
{
    return [
        'SISTEM_MENOLAK',
        'DUKCAPIL_UPDATE',
        'DUKCAPIL',
        'DATA_TIDAK_DITEMUKAN',
        'VALIDASI_TIDAK_VALID',
        'VALIDASI_PESERTA_WALI_TIDAK_VALID',
        'SUDAH_TERDAFTAR',
        'SUDAH_MENERIMA_LAYANAN',
        'BATAS_KIRIM_RAPOR_HABIS',
        'NOT_IN_LIST',
    ];
}

function monitoring_known_error_codes(): array
{
    return [
        'VALIDASI_PESERTA_WALI_TIDAK_VALID',
        'VALIDASI_TIDAK_VALID',
        'SUDAH_MENERIMA_LAYANAN',
        'BATAS_KIRIM_RAPOR_HABIS',
        'DATA_TIDAK_DITEMUKAN',
        'SUDAH_TERDAFTAR',
        'DUKCAPIL_UPDATE',
        'SISTEM_MENOLAK',
        'NOT_IN_LIST',
        'DATA_TIDAK_VALID',
        'DUKCAPIL',
    ];
}

function monitoring_normalize_error_code(?string $code, ?string $msg): string
{
    $c = strtoupper(trim((string) $code));
    if ($c !== '') return $c;

    $m = strtoupper((string) $msg);
    if (str_contains($m, 'BATAS KIRIM RAPOR HABIS') || str_contains($m, '3 KALI MENGIRIMKAN RAPOR KESEHATAN'))
        return 'BATAS_KIRIM_RAPOR_HABIS';
    foreach (monitoring_known_error_codes() as $known) {
        if ($known !== '' && str_contains($m, $known)) return $known;
    }

    return '';
}

function monitoring_normalize_success_status(array $result): string
{
    $direct = strtoupper(trim((string)($result['status_reg'] ?? $result['status'] ?? '')));
    if ($direct !== '') return $direct;

    $txt = strtoupper(trim((string)($result['status_text'] ?? '')));
    if (str_contains($txt, 'SUDAH MENERIMA LAYANAN') || str_contains($txt, 'SUDAH_MENERIMA_LAYANAN')) return 'SUDAH_MENERIMA_LAYANAN';
    if (str_contains($txt, 'VALIDASI TIDAK VALID') || str_contains($txt, 'VALIDASI_TIDAK_VALID')) return 'VALIDASI_TIDAK_VALID';
    if (str_contains($txt, 'DILAYANI')) return 'DILAYANI';
    if (str_contains($txt, 'TERDAFTAR BARU')) return 'TERDAFTAR_BARU';
    if (str_contains($txt, 'TERDAFTAR')) return 'TERDAFTAR';

    $absen = strtoupper(trim((string)($result['status_absen'] ?? '')));
    if (str_contains($absen, 'DILAYANI')) return 'DILAYANI';

    return '';
}

function monitoring_sync_legacy_status(PDO $db, int $uid): void
{
    static $synced = [];
    if (isset($synced[$uid])) return;

    try {
        $upd_failed = $db->prepare("
            UPDATE job_failed
            SET reg_code = ?
            WHERE user_id = ?
              AND (reg_code IS NULL OR reg_code = '')
              AND UPPER(COALESCE(error_msg, '')) LIKE ?
        ");
        $upd_failed_x = $db->prepare("
            UPDATE job_failed_x
            SET reg_code = ?
            WHERE user_id = ?
              AND (reg_code IS NULL OR reg_code = '')
              AND UPPER(COALESCE(error_msg, '')) LIKE ?
        ");

        foreach (monitoring_known_error_codes() as $code) {
            $like = '%' . $code . '%';
            $upd_failed->execute([$code, $uid, $like]);
            $upd_failed_x->execute([$code, $uid, $like]);
        }

        $missing_status = "
            (JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_reg') IS NULL
             OR JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_reg')) = '')
        ";

        $db->prepare("
            UPDATE job_success js
            SET js.result_data = JSON_SET(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_reg', 'SUDAH_MENERIMA_LAYANAN')
            WHERE js.user_id = ?
              AND {$missing_status}
              AND (
                  UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_text'))) LIKE '%SUDAH MENERIMA LAYANAN%'
                  OR UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.reg_code'))) = 'SUDAH_MENERIMA_LAYANAN'
              )
        ")->execute([$uid]);

        $db->prepare("
            UPDATE job_success js
            SET js.result_data = JSON_SET(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_reg', 'VALIDASI_TIDAK_VALID')
            WHERE js.user_id = ?
              AND {$missing_status}
              AND (
                  UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_text'))) LIKE '%VALIDASI TIDAK VALID%'
                  OR UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.reg_code'))) = 'VALIDASI_TIDAK_VALID'
              )
        ")->execute([$uid]);

        $db->prepare("
            UPDATE job_success js
            SET js.result_data = JSON_SET(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_reg', 'TERDAFTAR_BARU')
            WHERE js.user_id = ?
              AND {$missing_status}
              AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_text'))) LIKE '%TERDAFTAR BARU%'
        ")->execute([$uid]);

        $db->prepare("
            UPDATE job_success js
            SET js.result_data = JSON_SET(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_reg', 'DILAYANI')
            WHERE js.user_id = ?
              AND {$missing_status}
              AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_text'))) LIKE '%DILAYANI%'
        ")->execute([$uid]);

        $db->prepare("
            UPDATE job_success js
            SET js.result_data = JSON_SET(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_reg', 'TERDAFTAR')
            WHERE js.user_id = ?
              AND {$missing_status}
              AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_text'))) LIKE '%TERDAFTAR%'
              AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_text'))) NOT LIKE '%TERDAFTAR BARU%'
        ")->execute([$uid]);
    } catch (Throwable $e) {
        // Jangan hentikan halaman monitoring jika sinkronisasi gagal.
    }

    $synced[$uid] = true;
}

function monitoring_cleanup_orphan_rows(PDO $db, int $uid): void
{
    static $cleaned = [];
    if (isset($cleaned[$uid])) return;

    try {
        $db->prepare("
            DELETE jq
            FROM job_queue jq
            LEFT JOIN patients_data pd
              ON pd.id = jq.patient_id
             AND pd.user_id = jq.user_id
            WHERE jq.user_id = ?
              AND jq.patient_id IS NOT NULL
              AND pd.id IS NULL
        ")->execute([$uid]);

        $db->prepare("
            DELETE jf
            FROM job_failed jf
            LEFT JOIN patients_data pd
              ON pd.id = jf.patient_id
             AND pd.user_id = jf.user_id
            WHERE jf.user_id = ?
              AND jf.patient_id IS NOT NULL
              AND pd.id IS NULL
        ")->execute([$uid]);

        $db->prepare("
            DELETE jfx
            FROM job_failed_x jfx
            LEFT JOIN patients_data pd
              ON pd.id = jfx.patient_id
             AND pd.user_id = jfx.user_id
            WHERE jfx.user_id = ?
              AND jfx.patient_id IS NOT NULL
              AND pd.id IS NULL
        ")->execute([$uid]);

        $db->prepare("
            DELETE js
            FROM job_success js
            LEFT JOIN patients_data pd
              ON pd.id = js.patient_id
             AND pd.user_id = js.user_id
            WHERE js.user_id = ?
              AND js.patient_id IS NOT NULL
              AND pd.id IS NULL
        ")->execute([$uid]);
    } catch (Throwable $e) {
    }

    $cleaned[$uid] = true;
}

function monitoring_reclassify_invalid_success_rows(PDO $db, int $uid): void
{
    static $done = [];
    if (isset($done[$uid])) return;

    try {
        $status_reg_expr = "UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_reg')))";
        $status_text_expr = "UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_text')))";
        $status_absen_expr = "UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(js.result_data, ''), '{}'), '$.status_absen')))";

        $select_sql = "
            SELECT
                js.id,
                js.user_id,
                js.license_key_id,
                js.patient_id,
                js.task_type,
                js.source_job_id,
                COALESCE(NULLIF({$status_reg_expr}, ''), '') AS status_reg,
                COALESCE(NULLIF({$status_text_expr}, ''), '') AS status_text
            FROM job_success js
            WHERE js.user_id = ?
              AND (
                    {$status_reg_expr} IN (
                        'ERROR',
                        'FAILED',
                        'PELAYANAN_BELUM_SELESAI',
                        'DATA_PEMERIKSAAN_DIPROSES',
                        'STOPPED_BY_SERVER',
                        'USER_TIMEOUT_SKIP',
                        'NETWORK_ERROR',
                        'NETWORK_TIMEOUT'
                    )
                 OR {$status_reg_expr} = 'BATAS_KIRIM_RAPOR_HABIS'
                 OR ({$status_absen_expr} = 'SKIP' AND COALESCE(NULLIF({$status_reg_expr}, ''), '') <> 'SUDAH_MENERIMA_LAYANAN')
                 OR {$status_text_expr} LIKE '%BELUM SELESAI%'
                 OR {$status_text_expr} LIKE '%SEDANG DIPROSES%'
                 OR {$status_text_expr} LIKE '%BATAS KIRIM RAPOR HABIS%'
                 OR {$status_text_expr} LIKE '%3 KALI MENGIRIMKAN RAPOR KESEHATAN%'
                 OR {$status_text_expr} LIKE '%PROSES GAGAL%'
                 OR {$status_text_expr} LIKE '%GAGAL%'
              )
            ORDER BY js.id ASC
            LIMIT 500
        ";

        $sel = $db->prepare($select_sql);
        $sel->execute([$uid]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $ins = $db->prepare("
                INSERT INTO job_failed_x
                    (user_id, license_key_id, patient_id, task_type, source_job_id, error_msg, reg_code, attempt, failed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $del = $db->prepare("DELETE FROM job_success WHERE id = ? AND user_id = ?");

            foreach ($rows as $row) {
                $reg_code = strtoupper(trim((string)($row['status_reg'] ?? '')));
                $error_msg = trim((string)($row['status_text'] ?? ''));
                if ($error_msg === '') $error_msg = $reg_code !== '' ? $reg_code : 'ERROR';
                if ($reg_code === '') $reg_code = 'ERROR';

                $ins->execute([
                    (int)$row['user_id'],
                    (int)$row['license_key_id'],
                    (int)$row['patient_id'],
                    (string)$row['task_type'],
                    $row['source_job_id'] ?? null,
                    $error_msg,
                    $reg_code
                ]);
                $del->execute([(int)$row['id'], (int)$uid]);
            }
        }
    } catch (Throwable $e) {
    }

    $done[$uid] = true;
}
