<?php
require_once __DIR__ . '/../includes/functions.php';

$area = $_GET['area'] ?? 'operator';
$area = in_array($area, ['admin', 'operator']) ? $area : 'operator';

start_session_for($area);

session_unset();
session_destroy();

header('Location: ' . APP_URL . '/auth/login.php?logout=1');
exit;
