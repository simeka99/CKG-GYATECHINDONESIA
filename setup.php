<?php
require_once __DIR__ . '/includes/functions.php';

$new_password = 'Admin@1234';
$hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);

// Update atau insert admin
$stmt = db()->prepare("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
$stmt->execute();
$existing = $stmt->fetch();

if ($existing) {
    db()->prepare("UPDATE users SET password = ?, is_active = 1 WHERE username = 'admin'")
        ->execute([$hash]);
    echo "<p style='color:green;font-family:monospace'>✅ Password admin berhasil direset ke: <b>Admin@1234</b></p>";
} else {
    db()->prepare("INSERT INTO users (username, password, full_name, role, is_active) VALUES ('admin', ?, 'Administrator', 'admin', 1)")
        ->execute([$hash]);
    echo "<p style='color:green;font-family:monospace'>✅ Admin berhasil dibuat. Username: <b>admin</b> | Password: <b>Admin@1234</b></p>";
}

echo "<p style='color:red;font-family:monospace'>⚠️ HAPUS FILE INI SETELAH SELESAI!</p>";
echo "<p style='font-family:monospace'>Hash: <code>" . htmlspecialchars($hash) . "</code></p>";
echo "<br><a href='/auth/login.php' style='font-family:monospace;color:teal'>→ Pergi ke Login</a>";
?>