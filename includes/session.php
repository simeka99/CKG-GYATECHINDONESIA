<?php
// includes/session.php
// Dipanggil PERTAMA sebelum apapun — jangan ada output sebelum file ini

function start_session_for(string $area = 'operator'): void
{
    $names = [
        'admin' => 'RMIK_ADMIN',
        'operator' => 'RMIK_USER',
    ];

    $name = $names[$area] ?? 'RMIK_USER';

    // Kalau session sudah jalan dengan nama yang sama, skip
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === $name) {
        return;
    }

    // Kalau session jalan tapi nama beda, close dulu
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name($name);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}
