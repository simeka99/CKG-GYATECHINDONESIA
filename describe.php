<?php
require 'd:/GYA TECH INDONESIA/HOSTING SHARED/rmik.gyatechindonesia.com/config/db.php';
$db = db();
$stmt = $db->query('DESCRIBE users');
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($columns);
