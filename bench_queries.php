<?php
require 'config/db.php';
$db = db();
$uid = 1;
$scope_mode = 'umum';
$upload_id = 0;

function bench($name, $closure) {
    $start = microtime(true);
    $res = $closure();
    $end = microtime(true);
    printf("%-20s: %.4f seconds (result: %s)\n", $name, $end - $start, json_encode($res));
}

bench("g_success", function() use ($db, $uid, $scope_mode) {
    $s = $db->prepare("
        SELECT COUNT(DISTINCT js.patient_id)
        FROM job_success js
        INNER JOIN license_keys lk ON lk.id = js.license_key_id
        LEFT JOIN job_queue jq ON jq.patient_id = js.patient_id AND jq.user_id = js.user_id
        WHERE js.user_id = ? AND (
            (? = 'sekolah' AND LOWER(COALESCE(lk.mode, '')) = 'sekolah')
            OR (? = 'umum' AND LOWER(COALESCE(lk.mode, 'umum')) = 'umum')
        )
          AND jq.id IS NULL
    ");
    $s->execute([$uid, $scope_mode, $scope_mode]);
    return $s->fetchColumn();
});

bench("g_failed", function() use ($db, $uid, $scope_mode) {
    $s = $db->prepare("
        SELECT COUNT(DISTINCT jf.patient_id)
        FROM job_failed jf
        INNER JOIN license_keys lk ON lk.id = jf.license_key_id
        LEFT JOIN job_queue jq ON jq.patient_id = jf.patient_id AND jq.task_type = jf.task_type AND jq.user_id = jf.user_id
        LEFT JOIN job_success js ON js.patient_id = jf.patient_id AND js.task_type = jf.task_type AND js.user_id = jf.user_id
        WHERE jf.user_id = ? AND (
            (? = 'sekolah' AND LOWER(COALESCE(lk.mode, '')) = 'sekolah')
            OR (? = 'umum' AND LOWER(COALESCE(lk.mode, 'umum')) = 'umum')
        )
          AND jq.id IS NULL
          AND js.id IS NULL
    ");
    $s->execute([$uid, $scope_mode, $scope_mode]);
    return $s->fetchColumn();
});

bench("g_failed_x", function() use ($db, $uid, $scope_mode) {
    $s = $db->prepare("
        SELECT COUNT(DISTINCT jfx.patient_id)
        FROM job_failed_x jfx
        INNER JOIN license_keys lk ON lk.id = jfx.license_key_id
        LEFT JOIN job_queue jq ON jq.patient_id = jfx.patient_id AND jq.task_type = jfx.task_type AND jq.user_id = jfx.user_id
        LEFT JOIN job_success js ON js.patient_id = jfx.patient_id AND js.task_type = jfx.task_type AND js.user_id = jfx.user_id
        WHERE jfx.user_id = ? AND (
            (? = 'sekolah' AND LOWER(COALESCE(lk.mode, '')) = 'sekolah')
            OR (? = 'umum' AND LOWER(COALESCE(lk.mode, 'umum')) = 'umum')
        )
          AND jq.id IS NULL
          AND js.id IS NULL
    ");
    $s->execute([$uid, $scope_mode, $scope_mode]);
    return $s->fetchColumn();
});

bench("pendaftaran_avail", function() use ($db, $uid, $scope_mode, $upload_id) {
    $s = $db->prepare("
        SELECT COUNT(*) FROM patients_data pd
        LEFT JOIN job_queue jq 
          ON jq.patient_id = pd.id AND jq.user_id = pd.user_id AND jq.task_type = 'pendaftaran' AND jq.status IN ('pending','running')
        LEFT JOIN job_failed_x jfx 
          ON jfx.patient_id = pd.id AND jfx.user_id = pd.user_id AND jfx.task_type = 'pendaftaran'
        WHERE pd.user_id     = ?
          AND pd.ckg_scope   = ?
          AND pd.daftar_done = 0
          AND (? = 0 OR pd.upload_id = ?)
          AND jq.id IS NULL
          AND jfx.id IS NULL
    ");
    $s->execute([$uid, $scope_mode, $upload_id, $upload_id]);
    return $s->fetchColumn();
});

bench("pelayanan_avail", function() use ($db, $uid, $scope_mode, $upload_id) {
    $s = $db->prepare("
        SELECT COUNT(*) FROM patients_data pd
        LEFT JOIN job_queue jq 
          ON jq.patient_id = pd.id AND jq.user_id = pd.user_id AND jq.task_type = 'pelayanan' AND jq.status IN ('pending','running')
        LEFT JOIN job_failed_x jfx 
          ON jfx.patient_id = pd.id AND jfx.user_id = pd.user_id AND jfx.task_type = 'pelayanan'
        WHERE pd.user_id      = ?
          AND pd.ckg_scope    = ?
          AND pd.daftar_done  = 1
          AND pd.layanan_done = 0
          AND (? = 0 OR pd.upload_id = ?)
          AND jq.id IS NULL
          AND jfx.id IS NULL
    ");
    $s->execute([$uid, $scope_mode, $upload_id, $upload_id]);
    return $s->fetchColumn();
});

bench("sync_pendaftaran", function() use ($db, $uid, $scope_mode) {
    $s = $db->prepare("
        UPDATE patients_data pd
        LEFT JOIN (
            SELECT DISTINCT patient_id
            FROM job_success
            WHERE user_id = ?
              AND task_type = 'pendaftaran'
              AND patient_id IS NOT NULL
        ) js ON js.patient_id = pd.id
        SET pd.daftar_done = IF(js.patient_id IS NULL, 0, 1)
        WHERE pd.user_id = ? AND pd.ckg_scope = ?
    ");
    $s->execute([$uid, $uid, $scope_mode]);
    return $s->rowCount();
});

bench("sync_pelayanan", function() use ($db, $uid, $scope_mode) {
    $s = $db->prepare("
        UPDATE patients_data pd
        LEFT JOIN (
            SELECT DISTINCT patient_id
            FROM job_success
            WHERE user_id = ?
              AND task_type = 'pelayanan'
              AND patient_id IS NOT NULL
        ) js ON js.patient_id = pd.id
        SET pd.layanan_done = IF(js.patient_id IS NULL, 0, 1)
        WHERE pd.user_id = ? AND pd.ckg_scope = ?
    ");
    $s->execute([$uid, $uid, $scope_mode]);
    return $s->rowCount();
});

