<?php
// api/license/set_running.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$lic = api_auth(); // baca X-License-Key, ambil baris license_keys
$db  = db();

$running = isset($_POST['running']) ? (int)$_POST['running'] : 0;
$running = $running ? 1 : 0;

$stmt = $db->prepare("UPDATE license_keys SET is_running = ? WHERE id = ?");
$stmt->execute([$running, $lic['id']]);

json_response([
    'ok'         => true,
    'license'    => $lic['license_key'],
    'is_running' => $running
]);
