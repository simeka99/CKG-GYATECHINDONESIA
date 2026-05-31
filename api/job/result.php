<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$lic = api_auth_no_quota();
$db  = db();

$job_id      = (int)($_POST['job_id']      ?? 0);
$status      = $_POST['status']            ?? '';
$reg_code    = strtoupper(trim($_POST['reg_code']    ?? ''));
$error       = trim($_POST['error_msg']    ?? '');
$duration_ms = (int)($_POST['duration_ms'] ?? 0);
$result_raw  = $_POST['result_data']       ?? '';
$status_text = trim($_POST['status_text']  ?? '');
$result_json = json_decode((string)$result_raw, true);
$result_arr  = is_array($result_json) ? $result_json : [];

function normalize_upper_text($value): string
{
    return strtoupper(trim((string)$value));
}

function should_force_failed_status(string $status, string $reg_code, string $error_msg, string $status_text, array $result_arr): bool
{
    if ($status !== 'success')
        return false;

    $reg_code_upper = normalize_upper_text($reg_code);
    if ($reg_code_upper === '')
        $reg_code_upper = normalize_upper_text($result_arr['status_reg'] ?? ($result_arr['reg_code'] ?? ''));

    $status_text_upper = normalize_upper_text(($status_text . ' ' . (string)($result_arr['status_text'] ?? '')));
    $status_absen_upper = normalize_upper_text($result_arr['status_absen'] ?? '');
    $error_upper = normalize_upper_text($error_msg);

    $force_failed_codes = [
        'ERROR',
        'FAILED',
        'PELAYANAN_BELUM_SELESAI',
        'DATA_PEMERIKSAAN_DIPROSES',
        'BATAS_KIRIM_RAPOR_HABIS',
        'STOPPED_BY_SERVER',
        'USER_TIMEOUT_SKIP',
        'NETWORK_ERROR',
        'NETWORK_TIMEOUT',
    ];

    if (in_array($reg_code_upper, $force_failed_codes, true))
        return true;
    if ($status_absen_upper === 'SKIP' && $reg_code_upper !== 'SUDAH_MENERIMA_LAYANAN')
        return true;
    if ($status_text_upper !== '' && (
        str_contains($status_text_upper, 'BELUM SELESAI') ||
        str_contains($status_text_upper, 'SEDANG DIPROSES') ||
        str_contains($status_text_upper, 'PROSES GAGAL') ||
        str_contains($status_text_upper, 'BATAS KIRIM RAPOR HABIS') ||
        str_contains($status_text_upper, '3 KALI MENGIRIMKAN RAPOR KESEHATAN') ||
        str_contains($status_text_upper, 'GAGAL')
    ))
        return true;
    if ($error_upper !== '' && (
        str_contains($error_upper, 'BELUM SELESAI') ||
        str_contains($error_upper, 'SEDANG DIPROSES') ||
        str_contains($error_upper, 'PROSES GAGAL') ||
        str_contains($error_upper, 'BATAS KIRIM RAPOR HABIS') ||
        str_contains($error_upper, '3 KALI MENGIRIMKAN RAPOR KESEHATAN')
    ))
        return true;

    return false;
}

if (!$job_id || !in_array($status, ['success', 'failed'], true)) {
    json_response(['error' => 'Invalid input'], 422);
}

if (should_force_failed_status($status, $reg_code, $error, $status_text, $result_arr))
    $status = 'failed';

const NO_RETRY_CODES = [
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

if ($reg_code === '') {
    $candidate_text = strtoupper(trim(implode(' ', [
        $error,
        $status_text,
        (string)($result_arr['status_text'] ?? ''),
        (string)($result_arr['status_reg'] ?? ''),
        (string)($result_arr['reg_code'] ?? ''),
    ])));

    if (str_contains($candidate_text, 'SUDAH MENERIMA LAYANAN')) {
        $reg_code = 'SUDAH_MENERIMA_LAYANAN';
    } elseif (
        str_contains($candidate_text, 'VALIDASI_PESERTA_WALI_TIDAK_VALID') ||
        str_contains($candidate_text, 'PESERTA WALI TIDAK VALID')
    ) {
        $reg_code = 'VALIDASI_PESERTA_WALI_TIDAK_VALID';
    } elseif (
        str_contains($candidate_text, 'VALIDASI_TIDAK_VALID') ||
        str_contains($candidate_text, 'DATA PESERTA TIDAK VALID')
    ) {
        $reg_code = 'VALIDASI_TIDAK_VALID';
    } elseif (str_contains($candidate_text, 'SUDAH TERDAFTAR')) {
        $reg_code = 'SUDAH_TERDAFTAR';
    } elseif (str_contains($candidate_text, 'DATA TIDAK DITEMUKAN')) {
        $reg_code = 'DATA_TIDAK_DITEMUKAN';
    } elseif (str_contains($candidate_text, 'DUKCAPIL')) {
        $reg_code = 'DUKCAPIL';
    } elseif (str_contains($candidate_text, 'SISTEM MENOLAK')) {
        $reg_code = 'SISTEM_MENOLAK';
    } elseif (
        str_contains($candidate_text, 'BATAS KIRIM RAPOR HABIS') ||
        str_contains($candidate_text, '3 KALI MENGIRIMKAN RAPOR KESEHATAN')
    ) {
        $reg_code = 'BATAS_KIRIM_RAPOR_HABIS';
    } elseif (str_contains($candidate_text, 'NOT_IN_LIST')) {
        $reg_code = 'NOT_IN_LIST';
    }
}

$is_no_retry = in_array($reg_code, NO_RETRY_CODES, true);

function resolve_late_success(PDO $db, array $lic, int $job_id, array $result_arr, string $result_raw, int $duration_ms, string $reg_code): bool
{
    $patient_id = (int)($result_arr['patient_id'] ?? 0);
    $task_type_raw = strtolower(trim((string)($result_arr['task_type'] ?? '')));
    $task_type = in_array($task_type_raw, ['pendaftaran', 'pelayanan'], true) ? $task_type_raw : '';
    if ($patient_id <= 0 || $task_type === '')
        return false;

    $owner_stmt = $db->prepare("
        SELECT user_id
        FROM license_keys
        WHERE id = ?
        LIMIT 1
    ");
    $owner_stmt->execute([(int)$lic['id']]);
    $user_id = (int)$owner_stmt->fetchColumn();
    if ($user_id <= 0)
        return false;

    $exists_stmt = $db->prepare("
        SELECT id
        FROM job_success
        WHERE user_id = ?
          AND license_key_id = ?
          AND patient_id = ?
          AND task_type = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $exists_stmt->execute([$user_id, (int)$lic['id'], $patient_id, $task_type]);
    $existing_id = (int)$exists_stmt->fetchColumn();

    if ($existing_id <= 0) {
        $result_data = $result_raw ?: json_encode([
            'job_id'     => $job_id,
            'patient_id' => $patient_id,
            'task_type'  => $task_type,
            'status_reg' => $reg_code,
        ]);

        $db->prepare("
            INSERT INTO job_success
                (user_id, license_key_id, patient_id, task_type, source_job_id, result_data, duration_ms, finished_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$user_id, (int)$lic['id'], $patient_id, $task_type, $job_id, $result_data, $duration_ms]);
    }

    $db->prepare("
        UPDATE patients_data
        SET status = 'success',
            processed_at = NOW(),
            error_message = NULL,
            job_id = ?
        WHERE id = ?
          AND user_id = ?
    ")->execute([$job_id, $patient_id, $user_id]);

    $db->prepare("
        DELETE FROM job_failed
        WHERE user_id = ?
          AND license_key_id = ?
          AND patient_id = ?
          AND task_type = ?
    ")->execute([$user_id, (int)$lic['id'], $patient_id, $task_type]);

    $db->prepare("
        DELETE FROM job_failed_x
        WHERE user_id = ?
          AND license_key_id = ?
          AND patient_id = ?
          AND task_type = ?
    ")->execute([$user_id, (int)$lic['id'], $patient_id, $task_type]);

    return true;
}

$db->beginTransaction();

try {
    $info = $db->prepare("
        SELECT * FROM job_queue
        WHERE id = ? AND license_key_id = ?
        LIMIT 1
    ");
    $info->execute([$job_id, $lic['id']]);
    $job = $info->fetch();

    if (!$job) {
        if ($status === 'success' && resolve_late_success($db, $lic, $job_id, $result_arr, $result_raw, $duration_ms, $reg_code)) {
            $db->commit();
            json_response(['ok' => true, 'job_id' => $job_id, 'status' => 'success', 'note' => 'late success recovered']);
        }
        $db->rollBack();
        json_response(['ok' => true, 'note' => 'job not found']);
    }

    consume_nik_quota((int)$job['patient_id'], (int)$job['user_id']);

    $db->prepare("DELETE FROM job_queue WHERE id = ?")->execute([$job_id]);

    if ($status === 'success') {
        $result_data = $result_raw ?: json_encode([
            'job_id'     => $job_id,
            'patient_id' => (int)$job['patient_id'],
            'task_type'  => $job['task_type'],
            'status_reg' => $reg_code,
        ]);

        $db->prepare("
            INSERT INTO job_success
                (user_id, license_key_id, patient_id, task_type,
                 source_job_id, result_data, duration_ms, finished_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $job['user_id'],
            $job['license_key_id'],
            $job['patient_id'],
            $job['task_type'],
            $job['source_job_id'] ?? null,
            $result_data,
            $duration_ms,
        ]);

        $db->prepare("
            UPDATE patients_data
            SET status       = 'success',
                processed_at = NOW(),
                job_id       = ?
            WHERE id = ? AND user_id = ?
        ")->execute([$job_id, $job['patient_id'], $job['user_id']]);
    } elseif ($is_no_retry) {
        $db->prepare("
            INSERT INTO job_failed_x
                (user_id, license_key_id, patient_id, task_type,
                 source_job_id, error_msg, reg_code, attempt, failed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $job['user_id'],
            $job['license_key_id'],
            $job['patient_id'],
            $job['task_type'],
            $job['source_job_id'] ?? null,
            $error ?: $reg_code ?: 'Unknown error',
            $reg_code,
            (int)$job['attempt'],
        ]);

        $db->prepare("
            UPDATE patients_data
            SET status        = 'failed',
                processed_at  = NOW(),
                error_message = ?,
                job_id        = ?
            WHERE id = ? AND user_id = ?
        ")->execute([
            $error ?: $reg_code ?: 'Unknown error',
            $job_id,
            $job['patient_id'],
            $job['user_id'],
        ]);
    } else {
        $db->prepare("
            INSERT INTO job_failed
                (user_id, license_key_id, patient_id, task_type,
                 source_job_id, error_msg, reg_code, is_no_retry, attempt, failed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())
        ")->execute([
            $job['user_id'],
            $job['license_key_id'],
            $job['patient_id'],
            $job['task_type'],
            $job['source_job_id'] ?? null,
            $error ?: $reg_code ?: 'Unknown error',
            $reg_code,
            (int)$job['attempt'],
        ]);

        $db->prepare("
            UPDATE patients_data
            SET status        = 'retry',
                error_message = ?,
                retry_count   = retry_count + 1
            WHERE id = ? AND user_id = ?
        ")->execute([
            $error ?: $reg_code ?: 'Unknown error',
            $job['patient_id'],
            $job['user_id'],
        ]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    json_response(['error' => 'DB error'], 500);
}



json_response(['ok' => true, 'job_id' => $job_id, 'status' => $status]);
