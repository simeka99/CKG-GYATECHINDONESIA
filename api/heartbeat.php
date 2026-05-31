<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$lic = api_auth();
$db  = db();
$worker_log_line = trim((string)($_POST['worker_log_line'] ?? ''));
if ($worker_log_line !== '') {
    $worker_log_line = preg_replace('/\s+/', ' ', $worker_log_line);
    $worker_log_line = mb_substr($worker_log_line, 0, 1200);
    $is_heartbeat_line = stripos($worker_log_line, 'heartbeat') !== false;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS ckg_worker_runtime_log (
                license_key_id INT(11) NOT NULL PRIMARY KEY,
                worker_log_line TEXT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_worker_runtime_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if ($is_heartbeat_line) {
            $touch_stmt = $db->prepare("
                INSERT INTO ckg_worker_runtime_log (license_key_id, worker_log_line, updated_at)
                VALUES (?, '', NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            $touch_stmt->execute([(int)$lic['id']]);
        } else {
            $upsert_stmt = $db->prepare("
                INSERT INTO ckg_worker_runtime_log (license_key_id, worker_log_line, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE worker_log_line = VALUES(worker_log_line), updated_at = NOW()
            ");
            $upsert_stmt->execute([(int)$lic['id'], $worker_log_line]);
        }
    } catch (Exception $e) {
    }
}
// Catatan: last_seen sudah diupdate di dalam api_auth(), tidak perlu duplikat di sini.

// Reset job stuck running > 60 menit → pending
try {
    $db->prepare("
        UPDATE job_queue
        SET status     = 'pending',
            locked_at  = NULL,
            started_at = NULL
        WHERE license_key_id = ?
          AND status          = 'running'
          AND started_at      < DATE_SUB(NOW(), INTERVAL 60 MINUTE)
    ")->execute([$lic['id']]);
} catch (Exception $e) {
}


$pend = $db->prepare("SELECT COUNT(*) FROM job_queue WHERE license_key_id=? AND status='pending'");
$pend->execute([$lic['id']]);
$pending_count = (int)$pend->fetchColumn();

$run = $db->prepare("SELECT COUNT(*) FROM job_queue WHERE license_key_id=? AND status='running'");
$run->execute([$lic['id']]);
$running_count = (int)$run->fetchColumn();

$pending_other_keys = 0;
$owner_user_id = isset($lic['user_id']) ? (int)$lic['user_id'] : 0;
if ($owner_user_id <= 0) {
    try {
        $owner = $db->prepare("SELECT user_id FROM license_keys WHERE id = ? LIMIT 1");
        $owner->execute([(int)$lic['id']]);
        $owner_user_id = (int)$owner->fetchColumn();
    } catch (Exception $e) {
        $owner_user_id = 0;
    }
}
try {
    $other = $db->prepare("
        SELECT COUNT(*)
        FROM job_queue jq
        JOIN license_keys lk ON lk.id = jq.license_key_id
        WHERE lk.user_id = ?
          AND jq.status = 'pending'
          AND jq.license_key_id <> ?
    ");
    $other->execute([$owner_user_id, (int)$lic['id']]);
    $pending_other_keys = (int)$other->fetchColumn();
} catch (Exception $e) {
}

json_response([
    'ok'            => true,
    'should_run'    => (bool)$lic['is_running'],
    'pending_count' => $pending_count,
    'running_count' => $running_count,
    'pending_other_keys' => $pending_other_keys,
    'license_key_id' => (int)$lic['id'],
    'license_key' => (string)($lic['license_key'] ?? ''),
    'task_type'     => $lic['task_type'],
    'mode'          => $lic['mode'],
    'pc_label'      => $lic['pc_label'],
]);
