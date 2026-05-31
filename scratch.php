<?php
require 'config/db.php';
$db = db();
$t1 = $db->query('SHOW INDEXES FROM job_success')->fetchAll(PDO::FETCH_ASSOC);
$t2 = $db->query('SHOW INDEXES FROM job_failed')->fetchAll(PDO::FETCH_ASSOC);
$t3 = $db->query('SHOW INDEXES FROM job_queue')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['job_success' => $t1, 'job_failed' => $t2, 'job_queue' => $t3], JSON_PRETTY_PRINT);
