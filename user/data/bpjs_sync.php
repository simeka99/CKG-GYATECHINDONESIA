<?php
require_once __DIR__ . '/../../includes/session.php';

header('Content-Type: application/json');

start_session_for('operator');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi habis, silakan login ulang']);
    exit;
}

require_once __DIR__ . '/../../includes/functions.php';
$uid = (int) $_SESSION['user_id'];
$bpjs_sync_setting = get_user_bpjs_sync_settings($uid);

$nik = trim($_GET['nik'] ?? '');
if (!$nik) {
    echo json_encode(['success' => false, 'message' => 'NIK tidak boleh kosong']);
    exit;
}

$api_url = rtrim(get_setting('bpjs_api_url'), '/');
$api_key = get_setting('bpjs_api_key');

if (!$api_url || !$api_key) {
    echo json_encode(['success' => false, 'message' => 'Konfigurasi BPJS belum diatur']);
    exit;
}

$target = $api_url . '/' . $nik;

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $api_key,
        'Accept: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$curl_err = curl_error($ch);

if (!$response || $curl_err) {
    echo json_encode(['success' => false, 'message' => 'Gagal menghubungi server BPJS: ' . $curl_err]);
    exit;
}

$result = json_decode($response, true);

if (!$result || empty($result['success'])) {
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Data tidak ditemukan']);
    exit;
}

$d = $result['data'];
$sync_field_map = array_flip($bpjs_sync_setting['sync_fields'] ?? []);
$payload_data = [];
if (isset($sync_field_map['nik']))
    $payload_data['nik'] = $d['nik'] ?? '';
if (isset($sync_field_map['nama']))
    $payload_data['nama'] = $d['nama'] ?? '';
if (isset($sync_field_map['tgl_lahir']))
    $payload_data['tglLahir'] = $d['tglLahir'] ?? '';
if (isset($sync_field_map['jenis_kelamin']))
    $payload_data['jenisKelamin'] = $d['jenisKelamin'] ?? '';
if (isset($sync_field_map['no_hp']))
    $payload_data['noHP'] = $d['noHP'] ?? '';

echo json_encode([
    'success' => true,
    'sync_fields' => $bpjs_sync_setting['sync_fields'],
    'phone_auto_fallback' => !empty($bpjs_sync_setting['phone_auto_fallback']),
    'phone_fallback_number' => (string) ($bpjs_sync_setting['phone_fallback_number'] ?? ''),
    'data' => $payload_data,
]);
