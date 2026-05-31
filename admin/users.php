<?php
ob_start();
$page_title = 'Kelola Operator — RMIK Medical Record';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$success = '';
$error = '';

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

require_once __DIR__ . '/user/index.php';
require_once __DIR__ . '/../includes/footer.php';
