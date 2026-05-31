<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../../includes/functions.php';
} catch (Throwable $e) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'load_error: ' . $e->getMessage()]);
    exit;
}

start_session_for('operator');

ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db  = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) {
    echo json_encode(['ok' => false, 'msg' => 'auth']);
    exit;
}
$scope_mode = get_scope_mode();
$scope_mode_sql = $scope_mode === 'sekolah'
    ? "LOWER(COALESCE(mode,''))='sekolah'"
    : "LOWER(COALESCE(mode,'umum'))='umum'";
if (!is_valid_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['ok' => false, 'msg' => 'csrf']);
    exit;
}

$lk_id = (int)($_POST['lk_id'] ?? 0);
if (!$lk_id) {
    echo json_encode(['ok' => false, 'msg' => 'no_lk']);
    exit;
}

$s = $db->prepare("SELECT id FROM license_keys WHERE id=? AND user_id=? AND is_active=1 AND {$scope_mode_sql}");
$s->execute([$lk_id, $uid]);
if (!$s->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'not_found']);
    exit;
}

$sched_enabled  = (int)($_POST['sched_enabled']  ?? 0) ? 1 : 0;
$sched_start    = trim($_POST['sched_start']    ?? '');
$sched_stop_on  = (int)($_POST['sched_stop_on']  ?? 0) ? 1 : 0;
$sched_stop     = trim($_POST['sched_stop']     ?? '');
$retry_auto     = (int)($_POST['retry_auto']     ?? 0) ? 1 : 0;
$retry_interval = max(30, min(3600, (int)($_POST['retry_interval'] ?? 300)));

$rx = '/^\d{2}:\d{2}$/';
if (!preg_match($rx, $sched_start)) $sched_start = null;
if (!preg_match($rx, $sched_stop))  $sched_stop  = null;

if (!$sched_start) $sched_enabled = 0;

try {
    $db->prepare("
        UPDATE license_keys SET
            sched_enabled   = ?,
            sched_start     = ?,
            sched_stop_on   = ?,
            sched_stop      = ?,
            retry_auto      = ?,
            retry_interval  = ?,
            sched_last_date = NULL
        WHERE id = ? AND user_id = ?
    ")->execute([
        $sched_enabled,
        $sched_start,
        $sched_stop_on,
        $sched_stop,
        $retry_auto,
        $retry_interval,
        $lk_id,
        $uid,
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
