<?php
// api/job/retry.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/session.php';

header('Content-Type: application/json');

start_session_for('operator');
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$uid  = (int)$_SESSION['user_id'];
$body = json_decode(file_get_contents('php://input'), true);
$id   = (int)($body['job_id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'job_id required']);
    exit;
}

$db = db();

// Ambil job — pastikan milik user ini
$stmt = $db->prepare("
    SELECT id, error_msg, is_no_retry, reg_code, patient_id, task_type, license_key_id, source_job_id, attempt, user_id
    FROM job_failed
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$id, $uid]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Job tidak ditemukan']);
    exit;
}

// Cek is_no_retry / reg_code no-retry — kalau true, tolak
$no_retry_codes = [
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

$reg_code = strtoupper(trim((string)($job['reg_code'] ?? '')));
$error_msg_norm = strtoupper(trim((string)($job['error_msg'] ?? '')));
$is_no_retry_from_msg =
    str_contains($error_msg_norm, 'SUDAH MENERIMA LAYANAN') ||
    str_contains($error_msg_norm, 'SUDAH TERDAFTAR') ||
    str_contains($error_msg_norm, 'VALIDASI TIDAK VALID') ||
    str_contains($error_msg_norm, 'VALIDASI_PESERTA_WALI_TIDAK_VALID') ||
    str_contains($error_msg_norm, 'BATAS KIRIM RAPOR HABIS') ||
    str_contains($error_msg_norm, '3 KALI MENGIRIMKAN RAPOR KESEHATAN');

if ($job['is_no_retry'] || ($reg_code !== '' && in_array($reg_code, $no_retry_codes, true)) || $is_no_retry_from_msg) {
    http_response_code(422);
    echo json_encode([
        'ok'      => false,
        'message' => 'Job ini tidak bisa di-retry: ' . ($job['error_msg'] ?? 'ditolak sistem'),
    ]);
    exit;
}

// Pindahkan ke job_queue
$db->beginTransaction();
try {
    // ✅ Fix: source_job_id ikut disalin
    $db->prepare("
        INSERT INTO job_queue
            (user_id, patient_id, task_type, license_key_id,
             source_job_id, status, attempt, created_at)
        SELECT
            user_id, patient_id, task_type, license_key_id,
            source_job_id,
            'pending', attempt + 1, NOW()
        FROM job_failed
        WHERE id = ?
    ")->execute([$id]);

    // Hapus dari failed
    $db->prepare("DELETE FROM job_failed WHERE id = ?")->execute([$id]);

    $db->commit();

    echo json_encode(['ok' => true, 'message' => 'Job berhasil dimasukkan ulang ke antrian']);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal retry: ' . $e->getMessage()]);
}
