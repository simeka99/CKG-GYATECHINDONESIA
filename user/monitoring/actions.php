<?php
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json');
require_once __DIR__ . '/common.php';

$action = $_POST['action'] ?? '';

function respond(bool $ok, string $msg): void
{
    echo json_encode(['ok' => $ok, 'msg' => $msg]);
    exit;
}

if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? ''))
    respond(false, 'Aksi tidak valid');

$no_retry_codes = monitoring_no_retry_codes();
$placeholders   = implode(',', array_fill(0, count($no_retry_codes), '?'));
$scope_mode = get_scope_mode();
$scope_patient_sub = "SELECT id FROM patients_data WHERE user_id={$uid} AND ckg_scope=" . $db->quote($scope_mode);

if ($action === 'retry_one') {
    $jf_id = (int)($_POST['jf_id'] ?? 0);
    $src = trim((string)($_POST['src'] ?? 'arsip'));
    if (!$jf_id) respond(false, 'ID tidak valid');

    $table_name = $src === 'aktif' ? 'job_failed' : 'job_failed_x';
    $st = $db->prepare("
        SELECT * FROM {$table_name}
        WHERE id = ? AND user_id = ?
          AND patient_id IN ({$scope_patient_sub})
          AND (reg_code IS NULL OR reg_code = '' OR reg_code NOT IN ({$placeholders}))
        LIMIT 1
    ");
    $st->execute(array_merge([$jf_id, $uid], $no_retry_codes));
    $row = $st->fetch();

    if (!$row) respond(false, 'Job tidak ditemukan atau tidak bisa di-retry');

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT IGNORE INTO job_queue (user_id, patient_id, license_key_id, task_type, status, attempt, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
        ")->execute([$uid, $row['patient_id'], $row['license_key_id'], $row['task_type'], (int)$row['attempt'] + 1]);
        $db->prepare("DELETE FROM {$table_name} WHERE id = ? AND user_id = ? AND patient_id IN ({$scope_patient_sub})")->execute([$jf_id, $uid]);
        $db->commit();
        respond(true, '1 job dipindah ke antrian');
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, 'Gagal: ' . $e->getMessage());
    }
}

if ($action === 'retry_all') {
    $st = $db->prepare("
        SELECT id, user_id, patient_id, license_key_id, task_type, attempt, 'aktif' AS src
        FROM job_failed
        WHERE user_id = ?
          AND patient_id IN ({$scope_patient_sub})
          AND (reg_code IS NULL OR reg_code = '' OR reg_code NOT IN ({$placeholders}))
        UNION ALL
        SELECT id, user_id, patient_id, license_key_id, task_type, attempt, 'arsip' AS src
        FROM job_failed_x
        WHERE user_id = ?
          AND patient_id IN ({$scope_patient_sub})
          AND (reg_code IS NULL OR reg_code = '' OR reg_code NOT IN ({$placeholders}))
    ");
    $st->execute(array_merge([$uid], $no_retry_codes, [$uid], $no_retry_codes));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) respond(false, 'Tidak ada job yang bisa di-retry');

    $db->beginTransaction();
    try {
        $ins = $db->prepare("
            INSERT IGNORE INTO job_queue (user_id, patient_id, license_key_id, task_type, status, attempt, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $del_aktif = $db->prepare("DELETE FROM job_failed WHERE id = ? AND user_id = ? AND patient_id IN ({$scope_patient_sub})");
        $del_arsip = $db->prepare("DELETE FROM job_failed_x WHERE id = ? AND user_id = ? AND patient_id IN ({$scope_patient_sub})");
        foreach ($rows as $row) {
            $ins->execute([$uid, $row['patient_id'], $row['license_key_id'], $row['task_type'], (int)$row['attempt'] + 1]);
            if (($row['src'] ?? '') === 'aktif')
                $del_aktif->execute([$row['id'], $uid]);
            else
                $del_arsip->execute([$row['id'], $uid]);
        }
        $db->commit();
        respond(true, count($rows) . ' job dipindah ke antrian');
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, 'Gagal: ' . $e->getMessage());
    }
}

if ($action === 'mark_success_from_failed') {
    $id = (int)($_POST['id'] ?? 0);
    $src = trim((string)($_POST['src'] ?? 'aktif'));
    if (!$id) respond(false, 'ID tidak valid');

    $table_name = $src === 'arsip' ? 'job_failed_x' : 'job_failed';
    $st = $db->prepare("
        SELECT *
        FROM {$table_name}
        WHERE id = ? AND user_id = ?
          AND patient_id IN ({$scope_patient_sub})
        LIMIT 1
    ");
    $st->execute([$id, $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(false, 'Data gagal tidak ditemukan');

    $db->beginTransaction();
    try {
        $result_data = json_encode([
            'status_reg' => 'MANUAL_SUKSES',
            'status_text' => 'Manual sukses dari monitoring',
            'manual_action' => true,
            'from_failed_table' => $table_name,
            'from_failed_id' => $id,
        ], JSON_UNESCAPED_UNICODE);

        $db->prepare("
            INSERT INTO job_success
                (user_id, license_key_id, patient_id, task_type, source_job_id, result_data, duration_ms, finished_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
        ")->execute([
            $uid,
            (int)$row['license_key_id'],
            (int)$row['patient_id'],
            (string)$row['task_type'],
            $row['source_job_id'] ?? null,
            $result_data,
        ]);

        $db->prepare("DELETE FROM {$table_name} WHERE id = ? AND user_id = ?")->execute([$id, $uid]);

        if ((string)$row['task_type'] === 'pendaftaran') {
            $db->prepare("
                UPDATE patients_data
                SET daftar_done = 1
                WHERE id = ? AND user_id = ? AND ckg_scope = ?
            ")->execute([(int)$row['patient_id'], $uid, $scope_mode]);
        } elseif ((string)$row['task_type'] === 'pelayanan') {
            $db->prepare("
                UPDATE patients_data
                SET layanan_done = 1
                WHERE id = ? AND user_id = ? AND ckg_scope = ?
            ")->execute([(int)$row['patient_id'], $uid, $scope_mode]);
        }

        $db->prepare("
            UPDATE patients_data
            SET status = 'success',
                processed_at = NOW(),
                error_message = NULL
            WHERE id = ? AND user_id = ? AND ckg_scope = ?
        ")->execute([(int)$row['patient_id'], $uid, $scope_mode]);

        $db->commit();
        respond(true, 'Data dipindah ke sukses manual');
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, 'Gagal: ' . $e->getMessage());
    }
}

if ($action === 'mark_failed_from_success') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) respond(false, 'ID tidak valid');

    $st = $db->prepare("
        SELECT *
        FROM job_success
        WHERE id = ? AND user_id = ?
          AND patient_id IN ({$scope_patient_sub})
        LIMIT 1
    ");
    $st->execute([$id, $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(false, 'Data sukses tidak ditemukan');

    $db->beginTransaction();
    try {
        $error_msg = 'Manual gagal dari monitoring';
        $db->prepare("
            INSERT INTO job_failed
                (user_id, license_key_id, patient_id, task_type, source_job_id, error_msg, reg_code, is_no_retry, attempt, failed_at)
            VALUES (?, ?, ?, ?, ?, ?, 'MANUAL_GAGAL', 0, 1, NOW())
            ON DUPLICATE KEY UPDATE
                error_msg = VALUES(error_msg),
                reg_code = VALUES(reg_code),
                attempt = attempt + 1,
                failed_at = NOW()
        ")->execute([
            $uid,
            (int)$row['license_key_id'],
            (int)$row['patient_id'],
            (string)$row['task_type'],
            $row['source_job_id'] ?? null,
            $error_msg,
        ]);

        $db->prepare("
            DELETE FROM job_success
            WHERE id = ? AND user_id = ?
        ")->execute([$id, $uid]);

        $left = $db->prepare("
            SELECT COUNT(*)
            FROM job_success
            WHERE user_id = ? AND patient_id = ? AND task_type = ?
              AND patient_id IN ({$scope_patient_sub})
        ");
        $left->execute([$uid, (int)$row['patient_id'], (string)$row['task_type']]);
        $remaining = (int)$left->fetchColumn();

        if ($remaining === 0) {
            if ((string)$row['task_type'] === 'pendaftaran') {
                $db->prepare("
                    UPDATE patients_data
                    SET daftar_done = 0
                    WHERE id = ? AND user_id = ? AND ckg_scope = ?
                ")->execute([(int)$row['patient_id'], $uid, $scope_mode]);
            } elseif ((string)$row['task_type'] === 'pelayanan') {
                $db->prepare("
                    UPDATE patients_data
                    SET layanan_done = 0
                    WHERE id = ? AND user_id = ? AND ckg_scope = ?
                ")->execute([(int)$row['patient_id'], $uid, $scope_mode]);
            }
        }

        $db->prepare("
            UPDATE patients_data
            SET status = 'retry',
                retry_count = retry_count + 1,
                error_message = ?
            WHERE id = ? AND user_id = ? AND ckg_scope = ?
        ")->execute([$error_msg, (int)$row['patient_id'], $uid, $scope_mode]);

        $db->commit();
        respond(true, 'Data dipindah ke gagal manual');
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, 'Gagal: ' . $e->getMessage());
    }
}

if ($action === 'delete_one_success') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) respond(false, 'ID tidak valid');

    $st = $db->prepare("
        SELECT id, patient_id, task_type
        FROM job_success
        WHERE id = ? AND user_id = ?
          AND patient_id IN ({$scope_patient_sub})
        LIMIT 1
    ");
    $st->execute([$id, $uid]);
    $row = $st->fetch();
    if (!$row) respond(false, 'Data sukses tidak ditemukan');

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM job_success WHERE id = ? AND user_id = ? AND patient_id IN ({$scope_patient_sub})")->execute([$id, $uid]);

        $left = $db->prepare("
            SELECT COUNT(*) FROM job_success
            WHERE user_id = ? AND patient_id = ? AND task_type = ? AND patient_id IN ({$scope_patient_sub})
        ");
        $left->execute([$uid, (int)$row['patient_id'], $row['task_type']]);
        $remaining = (int)$left->fetchColumn();

        if ($remaining === 0) {
            if ($row['task_type'] === 'pendaftaran') {
                $db->prepare("
                    UPDATE patients_data
                    SET daftar_done = 0
                    WHERE user_id = ? AND ckg_scope = ? AND id = ?
                ")->execute([$uid, $scope_mode, (int)$row['patient_id']]);
            } elseif ($row['task_type'] === 'pelayanan') {
                $db->prepare("
                    UPDATE patients_data
                    SET layanan_done = 0
                    WHERE user_id = ? AND ckg_scope = ? AND id = ?
                ")->execute([$uid, $scope_mode, (int)$row['patient_id']]);
            }
        }

        $db->commit();
        respond(true, 'Data sukses dihapus & dikembalikan ke data pasien');
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, 'Gagal: ' . $e->getMessage());
    }
}

if ($action === 'delete_all_success') {
    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE patients_data pd
            SET pd.daftar_done = 0
            WHERE pd.user_id = ?
              AND pd.ckg_scope = ?
              AND pd.id IN (
                  SELECT t.patient_id FROM (
                      SELECT DISTINCT patient_id
                      FROM job_success
                      WHERE user_id = ? AND task_type = 'pendaftaran' AND patient_id IN ({$scope_patient_sub})
                  ) t
              )
        ")->execute([$uid, $scope_mode, $uid]);

        $db->prepare("
            UPDATE patients_data pd
            SET pd.layanan_done = 0
            WHERE pd.user_id = ?
              AND pd.ckg_scope = ?
              AND pd.id IN (
                  SELECT t.patient_id FROM (
                      SELECT DISTINCT patient_id
                      FROM job_success
                      WHERE user_id = ? AND task_type = 'pelayanan' AND patient_id IN ({$scope_patient_sub})
                  ) t
              )
        ")->execute([$uid, $scope_mode, $uid]);

        $db->prepare("DELETE FROM job_success WHERE user_id = ? AND patient_id IN ({$scope_patient_sub})")->execute([$uid]);
        $db->commit();
        respond(true, 'Semua data sukses dihapus & dikembalikan ke data pasien');
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, 'Gagal: ' . $e->getMessage());
    }
}

if ($action === 'delete_one_failed') {
    $id  = (int)($_POST['id'] ?? 0);
    $src = $_POST['src'] ?? '';
    if (!$id) respond(false, 'ID tidak valid');

    if ($src === 'arsip')
        $db->prepare("DELETE FROM job_failed_x WHERE id = ? AND user_id = ? AND patient_id IN ({$scope_patient_sub})")->execute([$id, $uid]);
    else
        $db->prepare("DELETE FROM job_failed WHERE id = ? AND user_id = ? AND patient_id IN ({$scope_patient_sub})")->execute([$id, $uid]);

    respond(true, 'Data gagal dihapus');
}

if ($action === 'delete_all_failed') {
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM job_failed   WHERE user_id = ? AND patient_id IN ({$scope_patient_sub})")->execute([$uid]);
        $db->prepare("DELETE FROM job_failed_x WHERE user_id = ? AND patient_id IN ({$scope_patient_sub})")->execute([$uid]);
        $db->commit();
        respond(true, 'Semua data gagal dihapus');
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, 'Gagal: ' . $e->getMessage());
    }
}

respond(false, 'Aksi tidak dikenal');
