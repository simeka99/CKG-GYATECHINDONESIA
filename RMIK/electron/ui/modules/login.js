import { state } from './state.js'
import
{
    license_key_input, login_error, login_screen, main_controller, license_form,
    info_pc_label, info_task_type, info_mode, display_user, display_key, display_device,
    btn_logout, account_status_label, btn_load_account, acc_email_input, acc_pass_input,
    form_inputs, btn_submit_login, btn_text_login, loading_icon_login, valid_icon, login_status_badge
} from './elements.js'
import { notify, set_session_invalid, set_session_valid, set_btn_start_state } from './utils.js'

// Format lisensi otomatis (GYA-XXXXXXXX-XXXXXXXX)
if (license_key_input)
{
    license_key_input.addEventListener('input', function (e)
    {
        let value = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        let formattedValue = '';

        if (value.length > 0) formattedValue += value.substring(0, 3);
        if (value.length > 3) formattedValue += '-' + value.substring(3, 11);
        if (value.length > 11) formattedValue += '-' + value.substring(11, 19);

        e.target.value = formattedValue;

        // Reset error state visual
        login_error.classList.add('hidden', 'opacity-0');
        license_key_input.classList.remove('border-red-400', 'focus:border-red-400', 'focus:ring-red-400', 'focus:ring-red-400/20');
        if (valid_icon) valid_icon.classList.add('hidden');
    });
}

/**
 * Visual handler untuk pesan error & sukses sesuai template custom user
 */
function showStatus(message, type)
{
    if (!login_error) return;

    login_error.textContent = message;
    login_error.classList.remove('hidden');

    setTimeout(() => login_error.classList.remove('opacity-0'), 10);

    if (type === 'error')
    {
        login_error.className = 'text-center text-sm font-semibold text-red-500 mt-4 transition-opacity duration-300';
        license_key_input.classList.add('border-red-400', 'focus:border-red-400', 'focus:ring-red-400');

        // Kembalikan state tombol
        if (btn_text_login) btn_text_login.textContent = 'VERIFY & START APP';
        if (loading_icon_login) loading_icon_login.classList.add('hidden');
        if (btn_submit_login)
        {
            btn_submit_login.classList.remove('opacity-80', 'cursor-not-allowed');
            btn_submit_login.disabled = false;
        }
    } else
    {
        login_error.className = 'text-center text-sm font-semibold text-emerald-600 mt-4 transition-opacity duration-300';
        license_key_input.classList.add('border-emerald-400', 'focus:border-emerald-400', 'focus:ring-emerald-400');
        license_key_input.classList.remove('border-red-400', 'focus:border-red-400', 'focus:ring-red-400');

        if (login_status_badge)
        {
            login_status_badge.innerHTML = 'VERIFIED <span class="ml-2 w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>';
            login_status_badge.classList.replace('text-slate-400', 'text-emerald-500');
        }
        if (valid_icon) valid_icon.classList.remove('hidden');

        if (btn_text_login) btn_text_login.textContent = 'SYSTEM READY';
        if (loading_icon_login) loading_icon_login.classList.add('hidden');
    }
}

export function handle_login(e)
{
    if (e) e.preventDefault()
    if (!license_key_input.value)
    {
        showStatus('License Key tidak boleh kosong', 'error')
        return
    }

    if (license_key_input.value.length < 10)
    {
        showStatus('Format license key tidak valid.', 'error')
        return;
    }

    // Ubah visual tombol jadi loading (Verifying)
    if (btn_text_login) btn_text_login.textContent = 'VERIFYING...';
    if (loading_icon_login) loading_icon_login.classList.remove('hidden');
    if (btn_submit_login)
    {
        btn_submit_login.classList.add('opacity-80', 'cursor-not-allowed');
        btn_submit_login.disabled = true;
    }

    window.ipcRenderer.send('validate_license', license_key_input.value)
}

export function execute_login()
{
    state.is_logged_in = true

    // Matikan interval status sementara pindah halaman
    window.ipcRenderer.send('set_status_interval', false)

    showStatus('License verified! Memulai aplikasi...', 'success')

    // Animasi masuk (seperti yang dicontohkan User)
    const card = document.querySelector('.max-w-md');
    if (card)
    {
        setTimeout(() =>
        {
            card.style.transform = 'scale(0.95)';
            card.style.opacity = '0';
        }, 800);
    }

    setTimeout(() =>
    {
        // Efek Crossfade ke Dashboard
        login_screen.classList.add('opacity-0')
        setTimeout(() =>
        {
            login_screen.classList.add('hidden')
            main_controller.classList.remove('hidden')

            // Re-trigger layout
            void main_controller.offsetWidth

            main_controller.classList.add('opacity-100')

            // Nyalakan kembali status monitor backend
            window.ipcRenderer.send('set_status_interval', true)
        }, 500)
    }, 1500)
}

// Setup IPC Listeners terkait Login
export function setup_login_listeners()
{
    if (license_form) license_form.addEventListener('submit', handle_login)

    // Handle Validasi Berhasil
    window.ipcRenderer.on('license_valid', (event, { name, is_active, expiration_date }) =>
    {
        if (!is_active)
        {
            showStatus('License Key tidak aktif. Silakan hubungi admin.', 'error')
            return
        }

        const today = new Date()
        const expDate = new Date(expiration_date.split('-').reverse().join('-'))
        if (expDate < today)
        {
            showStatus('License Key Anda telah kedaluwarsa.', 'error')
            return
        }

        display_user.innerText = name || 'Admin'
        display_key.innerText = license_key_input.value

        form_inputs.forEach(input =>
        {
            input.disabled = true
        })

        execute_login()
    })

    // Handle Validasi Gagal
    window.ipcRenderer.on('license_invalid', (event, msg) =>
    {
        showStatus(msg || 'License Key tidak valid atau tidak ditemukan.', 'error')
    })

    window.ipcRenderer.on('license_error', (event, msg) =>
    {
        showStatus('Gagal terhubung ke server RMIK. Coba lagi.', 'error')
    })

    // Auto-login jika key sudah tersimpan
    window.ipcRenderer.on('config_loaded', (event, config) =>
    {
        if (!state.config_loaded)
        {
            state.config_loaded = true
            if (config.licenseKey)
            {
                license_key_input.value = config.licenseKey
                // Trigger submit langsung untuk auto check
                license_form.dispatchEvent(new Event('submit'))
            }
        }
    })
}

export async function validate_license()
{
    const key = license_key_input.value.trim().toUpperCase()
    if (key.length < 10)
    {
        show_login_error('Format lisensi tidak valid!')
        return
    }

    // Ubah visual tombol jadi loading (Verifying) sesuai template user
    if (btn_text_login) btn_text_login.textContent = 'VERIFYING...';
    if (loading_icon_login) loading_icon_login.classList.remove('hidden');
    if (btn_submit_login)
    {
        btn_submit_login.classList.add('opacity-80', 'cursor-not-allowed');
        btn_submit_login.disabled = true;
    }

    // Sembunyikan pesan error sebelumnya
    if (login_error)
    {
        login_error.classList.add('hidden', 'opacity-0');
    }

    let result
    try
    {
        result = await Promise.race([
            window.ipcRenderer.invoke('license_login', key),
            new Promise((_, reject) => setTimeout(() => reject(new Error('TIMEOUT')), 20000))
        ])
    } catch (err)
    {
        const msg = err?.message === 'TIMEOUT'
            ? 'Verifikasi lisensi timeout. Cek koneksi internet lalu coba lagi.'
            : ('Gagal verifikasi lisensi: ' + (err?.message || 'Unknown error'))
        show_login_error(msg)
        return
    }

    if (!result?.ok)
    {
        show_login_error(result?.error || 'Lisensi tidak valid atau tidak dikenali.')
        return
    }

    populate_pc_info(result.data)

    // Status Berhasil
    if (login_error)
    {
        login_error.textContent = 'License verified! Memulai aplikasi...';
        login_error.classList.remove('hidden');
        login_error.className = 'text-center text-sm font-semibold text-emerald-600 mt-4 transition-opacity duration-300';
    }
    if (license_key_input)
    {
        license_key_input.classList.add('border-emerald-400', 'focus:border-emerald-400', 'focus:ring-emerald-400');
        license_key_input.classList.remove('border-red-400', 'focus:border-red-400', 'focus:ring-red-400');
    }
    if (login_status_badge)
    {
        login_status_badge.innerHTML = 'VERIFIED <span class="ml-2 w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>';
        login_status_badge.classList.replace('text-slate-400', 'text-emerald-500');
    }
    if (valid_icon) valid_icon.classList.remove('hidden');

    if (btn_text_login) btn_text_login.textContent = 'SYSTEM READY';
    if (loading_icon_login) loading_icon_login.classList.add('hidden');

    // Animasi Pop Down Card
    const card = document.querySelector('.max-w-md');
    if (card)
    {
        setTimeout(() =>
        {
            card.style.transform = 'scale(0.95)';
            card.style.opacity = '0';
        }, 800);
    }

    // Tunda sedikit untuk animasi sebelum pindah layar
    setTimeout(async () =>
    {
        enter_dashboard(false, result.data)
        await init_after_login()
    }, 1500)
}

export function show_login_error(msg)
{
    if (!login_error) return;

    login_error.textContent = msg;
    login_error.classList.remove('hidden');
    setTimeout(() => login_error.classList.remove('opacity-0'), 10);
    login_error.className = 'text-center text-sm font-semibold text-red-500 mt-4 transition-opacity duration-300';

    if (license_key_input)
    {
        license_key_input.classList.add('border-red-400', 'focus:border-red-400', 'focus:ring-red-400');
    }

    // Kembalikan state tombol ke semula
    if (btn_text_login) btn_text_login.textContent = 'VERIFY & START APP';
    if (loading_icon_login) loading_icon_login.classList.add('hidden');
    if (btn_submit_login)
    {
        btn_submit_login.classList.remove('opacity-80', 'cursor-not-allowed');
        btn_submit_login.disabled = false;
    }
}

export function populate_pc_info(data)
{
    if (!data) return
    if (data.pc_label && info_pc_label) info_pc_label.innerText = data.pc_label
    if (data.task_type && info_task_type) info_task_type.innerText = String(data.task_type).toUpperCase()
    if (data.mode && info_mode) info_mode.innerText = String(data.mode).toUpperCase()
    const name = data.full_name || data.username
    if (name && display_user) display_user.innerText = name
    if (data.device_id && display_device) display_device.innerText = data.device_id.toUpperCase()
}

export function enter_dashboard(is_quick, license_data)
{
    if (license_data)
    {
        const license_key = typeof license_data === 'string' ? license_data : license_data?.license_key
        const device_id = typeof license_data === 'string' ? '' : license_data?.device_id || ''
        if (display_key && license_key) display_key.innerText = license_key
        if (display_device && device_id) display_device.innerText = 'ID: ' + device_id.toUpperCase()
    }

    if (is_quick)
    {
        login_screen.classList.add('hidden')
        main_controller.classList.remove('hidden', 'opacity-0')
        main_controller.classList.add('opacity-100')
    } else
    {
        login_screen.style.opacity = '0'
        login_screen.style.transform = 'scale(0.98)'
        setTimeout(() =>
        {
            login_screen.classList.add('hidden')
            main_controller.classList.remove('hidden')
            setTimeout(() =>
            {
                main_controller.classList.remove('opacity-0')
                main_controller.classList.add('opacity-100')
            }, 50)
        }, 500)
    }
    notify(is_quick ? 'Selamat datang kembali!' : 'Aktivasi berhasil!')
}

export async function logout()
{
    if (state.is_node_running) await window.ipcRenderer.invoke('worker_stop')
    await window.ipcRenderer.invoke('license_clear')
    window.location.reload()
}

export async function init_after_login()
{
    const license = await window.ipcRenderer.invoke('license_load')
    if (license?.full_name && display_user) display_user.innerText = license.full_name
    else if (license?.username && display_user) display_user.innerText = license.username

    const account = await window.ipcRenderer.invoke('account_load')
    if (account?.email)
    {
        state.saved_account_data = account
        if (account_status_label)
        {
            account_status_label.innerText = 'SUDAH TERISI ✓'
            account_status_label.className = 'text-[10px] text-emerald-500 font-bold tracking-wider'
        }
        if (btn_load_account) btn_load_account.classList.remove('hidden')
    } else
    {
        state.saved_account_data = null
        if (account_status_label)
        {
            account_status_label.innerText = 'KOSONG'
            account_status_label.className = 'text-[10px] text-slate-400 font-bold tracking-wider'
        }
        if (btn_load_account) btn_load_account.classList.add('hidden')
    }

    if (acc_email_input) acc_email_input.value = ''
    if (acc_pass_input) acc_pass_input.value = ''

    window.dispatchEvent(new CustomEvent('init-after-login-done'))
}

if (license_form)
{
    license_form.addEventListener('submit', event =>
    {
        event.preventDefault()
        validate_license()
    })
}

if (btn_logout)
    btn_logout.addEventListener('click', () => logout())
