<?php
require 'config/db.php';
$db = db();

$indexes = [
    'job_success' => [
        'idx_js_uid_lkid_finished'   => 'user_id, license_key_id, finished_at',
        'idx_js_uid_pid_task'        => 'user_id, patient_id, task_type',
        'idx_js_uid_lkid_pid'        => 'user_id, license_key_id, patient_id',
    ],
    'job_queue' => [
        'idx_jq_uid_pid_task_status' => 'user_id, patient_id, task_type, status',
        'idx_jq_uid_lkid_status'     => 'user_id, license_key_id, status',
        'idx_jq_uid_pid_status'      => 'user_id, patient_id, status',
    ],
    'job_failed' => [
        'idx_jf_uid_lkid_pid'        => 'user_id, license_key_id, patient_id',
        'idx_jf_uid_pid_task'        => 'user_id, patient_id, task_type',
        'idx_jf_uid_lkid_retry'      => 'user_id, license_key_id, is_no_retry',
    ],
    'job_failed_x' => [
        'idx_jfx_uid_lkid_pid'       => 'user_id, license_key_id, patient_id',
        'idx_jfx_uid_pid_task'       => 'user_id, patient_id, task_type',
    ],
    'patients_data' => [
        'idx_pd_uid_scope_done'      => 'user_id, ckg_scope, daftar_done, layanan_done, id',
        'idx_pd_uid_scope_upload'    => 'user_id, ckg_scope, upload_id, id',
    ],
    'admin_settings' => [
        'idx_as_key'                 => 'setting_key',
    ],
];

foreach ($indexes as $table => $table_indexes) {
    foreach ($table_indexes as $name => $cols) {
        try {
            $db->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
            echo "[$table] $name — OK\n";
        } catch (Exception $e) {
            echo "[$table] $name — " . $e->getMessage() . "\n";
        }
    }
}

echo "\nSelesai.\n";
