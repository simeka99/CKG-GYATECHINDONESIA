<?php
require_once __DIR__ . '/../includes/functions.php';

$error = '';
$favicon_path = __DIR__ . '/../favicon.svg';
$favicon_version = file_exists($favicon_path) ? (string) filemtime($favicon_path) : date('YmdHis');
$favicon_href = APP_URL . '/favicon.svg?v=' . $favicon_version;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = db()->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            // Cek validitas subscription (hanya untuk non-admin)
            if ($user['role'] !== 'admin') {
                $type = $user['subscription_type'] ?? 'time';
                if ($type === 'quota') {
                    if ((int) $user['quota_total'] <= 0 || (int) $user['quota_used'] >= (int) $user['quota_total']) {
                        $error = 'Kuota NIK Anda telah habis. Hubungi admin untuk menambah kuota.';
                    }
                } else {
                    $today = new DateTime(date('Y-m-d'));
                    $end = !empty($user['subscription_end'])
                        ? new DateTime(date('Y-m-d', strtotime($user['subscription_end'])))
                        : null;
                    if (!$end || $today > $end) {
                        $error = 'Masa langganan Anda telah habis. Hubungi admin untuk perpanjang akses.';
                    }
                }
            }

            // Hanya login jika tidak ada error
            if ($error === '') {
                // Start session sesuai role — tidak ganggu session role lain
                $area = ($user['role'] === 'admin') ? 'admin' : 'operator';
                start_session_for($area);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['sub_type'] = $user['subscription_type'] ?? 'time';
                $_SESSION['sub_end'] = $user['subscription_end'] ?? null;
                $_SESSION['quota_total'] = (int) ($user['quota_total'] ?? 0);
                $_SESSION['quota_used'] = (int) ($user['quota_used'] ?? 0);

                header('Location: ' . APP_URL . ($user['role'] === 'admin' ? '/admin/' : '/user/'));
                exit;
            }
        } else {
            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — RMIK Medical Record</title>
    <link rel="icon" type="image/svg+xml" href="<?= h($favicon_href) ?>">
    <link rel="shortcut icon" href="<?= h($favicon_href) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .input-field {
            width: 100%;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            color: #0f172a;
            border-radius: .75rem;
            padding: .75rem 1rem;
            font-size: .875rem;
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }

        .input-field:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, .1);
            background: #fff;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper .input-field {
            padding-right: 2.75rem;
        }

        .toggle-pass {
            position: absolute;
            right: .875rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            transition: color .2s;
        }

        .toggle-pass:hover {
            color: #0d9488;
        }
    </style>
</head>

<body class="min-h-screen bg-gradient-to-br from-teal-50 via-white to-slate-50 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-teal-600 rounded-2xl shadow-lg mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0
                       01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622
                       5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">RMIK Medical Record</h1>
            <p class="text-slate-400 text-sm mt-1 font-medium">Sistem Automasi Rekam Medis</p>
        </div>

        <div class="bg-white border border-slate-100 rounded-2xl p-8 shadow-xl shadow-slate-200/60">

            <?php if (isset($_GET['expired']) && !$error): ?>
                <div
                    class="mb-5 flex items-start gap-3 px-4 py-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-700 text-sm font-medium">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Sesi berakhir. Silakan login kembali.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['logout']) && !$error): ?>
                <div
                    class="mb-5 flex items-center gap-3 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-500 text-sm font-medium">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Anda berhasil keluar.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div
                    class="mb-5 flex items-start gap-3 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-600 text-sm font-medium">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5" autocomplete="off">
                <div>
                    <label
                        class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Username</label>
                    <input type="text" name="username" required autofocus class="input-field"
                        placeholder="Masukkan username" value="<?= h($_POST['username'] ?? '') ?>">
                </div>
                <div>
                    <label
                        class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="passwordInput" required class="input-field"
                            placeholder="Masukkan password">
                        <button type="button" class="toggle-pass" onclick="togglePass()">
                            <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </button>
                    </div>
                </div>
                <button type="submit"
                    class="w-full bg-teal-600 hover:bg-teal-700 active:scale-[0.98] text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md shadow-teal-100 mt-2">
                    Masuk ke Panel
                </button>
            </form>
        </div>
        <p class="text-center text-slate-400 text-xs mt-6 font-medium">
            RMIK Medical Record &copy; <?= date('Y') ?> &mdash; Gyatech Indonesia
        </p>
    </div>
    <script>
        function togglePass() {
            const input = document.getElementById('passwordInput');
            const icon = document.getElementById('eyeIcon');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.innerHTML = show ?
                `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7
            a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878
            9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3
            3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543
            7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>` :
                `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7
            -1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;
        }
    </script>
</body>

</html>
