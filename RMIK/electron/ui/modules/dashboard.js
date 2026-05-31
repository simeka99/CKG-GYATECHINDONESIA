import { state } from './state.js'
import
{
    login_error, license_key_input, app_version_label,
    btn_start, btn_stop, btn_settings, btn_theme_toggle, btn_minimize, btn_maximize, btn_close,
    btn_login_minimize, btn_login_maximize, btn_login_close,
    btn_login_si, btn_logout_si, btn_save_settings, btn_toggle_pass, btn_load_account,
    btn_clear, btn_verbose, btn_help, btn_close_help, btn_understand_help, btn_close_settings,
    btn_cancel_settings, btn_save_advanced, btn_reset_data,
    acc_email_input, acc_pass_input, account_status_label,
    settings_modal, settings_inner, help_modal, help_inner,
    cfg_headless, cfg_browser_engine, cfg_browser_channel, cfg_pause, cfg_slow_mo, cfg_stop_on_error, cfg_save_artifacts,
    cfg_mandiri_recheck_completed, cfg_mandiri_only_index, cfg_mandiri_refill_answered, cfg_mandiri_auto_submit,
    cfg_session_auto_relogin,
    cfg_wali_nik, cfg_wali_nama, cfg_wali_no_hp, cfg_wali_instansi_puskesmas, cfg_wali_tgl, cfg_wali_jk,
    info_pending, info_running, info_busy,
    status_indicator, footer_dot, log_status, window_size_label, clock_label
} from './elements.js'
import
{
    set_btn_start_state, set_session_invalid, set_session_loading, set_session_valid,
    set_ui_running, set_ui_stopped, notify, add_log, classify_log_color, clear_logs, toggle_theme
} from './utils.js'
import { logout } from './login.js'
import './updater.js'

const cfg_browser_hint = document.getElementById('cfgBrowserHint')

function parse_batch_progress_from_line(line)
{
    const text = String(line || '')
    const total_match = text.match(/\btotal=(\d+)\b/)
    const index_match = text.match(/\bindex=(\d+)\b/)
    if (!total_match || !index_match)
        return null

    const total = Number.parseInt(total_match[1], 10)
    const index = Number.parseInt(index_match[1], 10)
    if (!Number.isFinite(total) || !Number.isFinite(index) || total <= 0 || index <= 0)
        return null
    return { index, total }
}

function update_queue_progress_by_line(line)
{
    const text = String(line || '')
    const normalized = text.toLowerCase()
    const is_batch_start_line = normalized.includes('pelayanan_job_start') || normalized.includes('user_start')
    const is_batch_done_line = normalized.includes('pelayanan_job_done') || normalized.includes('user_done')
    const is_batch_finished = normalized.includes('worker_batch_done') || /(^|\s)batch_done(\s|$)/.test(normalized)

    if (is_batch_finished)
    {
        state.queue_total_locked = 0
        state.queue_current_index = 0
        return
    }

    if (!is_batch_start_line && !is_batch_done_line)
        return

    const progress = parse_batch_progress_from_line(text)
    if (!progress)
        return

    const should_reset_batch = progress.index === 1 || state.queue_total_locked !== progress.total
    if (should_reset_batch)
        state.queue_total_locked = progress.total
    state.queue_current_index = Math.max(0, Math.min(progress.index, progress.total))
}

function sync_verbose_button_state()
{
    if (!btn_verbose) return

    btn_verbose.innerText = state.show_verbose ? 'LOG ON' : 'LOG OFF'
    btn_verbose.className = state.show_verbose
        ? 'flex items-center gap-1.5 py-1 px-3 bg-amber-500/20 text-amber-400 rounded-lg transition-all text-[9px] font-bold border border-amber-500/30'
        : 'flex items-center gap-1.5 py-1 px-3 bg-slate-800 hover:bg-slate-700 text-slate-500 rounded-lg transition-all text-[9px] font-bold border border-slate-700'
}

window.onload = async () =>
{
    try
    {
        const v = await window.ipcRenderer.invoke('app_version')
        if (v && app_version_label) app_version_label.innerText = 'v' + v
    } catch { }

    await window.ipcRenderer.invoke('set_verbose_mode', state.show_verbose).catch(() => { })
    sync_verbose_button_state()
    set_btn_start_state()
    login_error.innerText = 'Memverifikasi status lisensi...'
    login_error.classList.remove('hidden', 'text-red-500')
    login_error.classList.add('text-slate-400')
    license_key_input.disabled = true

    const saved = await window.ipcRenderer.invoke('license_load')

    license_key_input.disabled = false
    login_error.classList.add('hidden')
    login_error.innerText = ''

    if (saved?.license_key)
    {
        if (license_key_input) license_key_input.value = String(saved.license_key || '')
        login_error.innerText = 'Lisensi tersimpan. Klik VERIFY & START APP untuk login manual.'
        login_error.classList.remove('hidden', 'text-red-500')
        login_error.classList.add('text-amber-500')
    }
}

window.addEventListener('init-after-login-done', async () =>
{
    await check_session()
})

async function check_session()
{
    set_session_loading('Memeriksa sesi Sehat IndonesiaKu...')
    const result = await window.ipcRenderer.invoke('session_check')
    if (result.valid)
    {
        set_session_valid()
        return
    }

    set_session_invalid(result.reason)
}

async function do_session_login()
{
    set_session_loading('Membuka browser untuk login...')
    const result = await window.ipcRenderer.invoke('session_login')
    if (result.ok) set_session_valid()
    else set_session_invalid(result.error || 'Login gagal')
}

async function handle_start()
{
    if (state.is_node_running || !state.is_session_valid) return
    const result = await window.ipcRenderer.invoke('worker_start')
    if (!result.ok)
    {
        add_log('[SYSTEM] Gagal start: ' + result.error, 'text-red-400 font-bold')
        notify('Gagal menjalankan worker')
        return
    }
    if (result.already_running)
    {
        set_ui_running()
        notify('Worker sudah berjalan')
        return
    }
    notify('Koneksi Aktif')
}

async function handle_stop()
{
    if (!state.is_node_running) return
    const result = await window.ipcRenderer.invoke('worker_stop')
    if (!result.ok)
    {
        add_log('[SYSTEM] Gagal stop: ' + result.error, 'text-red-400')
        return
    }
    if (result.stopping)
    {
        notify('Proses stop dikirim')
        return
    }
    notify('Koneksi Terputus')
}

async function save_account()
{
    const email = acc_email_input.value.trim()
    const password = acc_pass_input.value.trim()

    if (!email || !password)
    {
        notify('Email dan password wajib diisi')
        await window.ipcRenderer.invoke('account_save', { email: '', password: '' })
        await check_session()
        return
    }

    if (account_status_label) account_status_label.innerText = 'Menyimpan...'
    const result = await window.ipcRenderer.invoke('account_save', { email, password })

    if (result.ok)
    {
        state.saved_account_data = { email, password }
        if (account_status_label)
        {
            account_status_label.innerText = 'TERSIMPAN ✓'
            account_status_label.className = 'text-[10px] text-emerald-500 font-bold tracking-wider'
        }
        if (btn_load_account) btn_load_account.classList.remove('hidden')
        acc_email_input.value = ''
        acc_pass_input.value = ''
        notify('Akun berhasil disimpan')
        add_log('[SYSTEM] Akun Sehat diperbarui: ' + email, 'text-amber-400 italic')
    } else
    {
        if (account_status_label) account_status_label.innerText = 'Gagal simpan'
        const error_message = String(result?.error || 'Gagal menyimpan akun').trim()
        notify('Gagal menyimpan akun: ' + error_message)
        add_log('[SYSTEM] Gagal simpan akun: ' + error_message, 'text-red-400')
    }

    await check_session()
}

function toggle_pass_visibility()
{
    acc_pass_input.type = acc_pass_input.type === 'password' ? 'text' : 'password'
}

async function open_settings_modal()
{
    const data = await window.ipcRenderer.invoke('config_advanced_load')
    if (data)
    {
        if (cfg_headless) cfg_headless.value = String(data.headless)
        if (cfg_browser_engine) cfg_browser_engine.value = data.browser_engine || 'system'
        if (cfg_browser_channel) cfg_browser_channel.value = data.browser_channel || 'msedge'
        if (cfg_pause) cfg_pause.value = data.pause_between_users_ms
        if (cfg_slow_mo) cfg_slow_mo.value = data.slow_mo_ms
        if (cfg_stop_on_error) cfg_stop_on_error.value = String(data.stop_on_error)
        if (cfg_save_artifacts) cfg_save_artifacts.value = String(data.save_artifacts)
        if (cfg_mandiri_recheck_completed) cfg_mandiri_recheck_completed.value = String(data.pemeriksaan_mandiri_recheck_completed)
        if (cfg_mandiri_only_index) cfg_mandiri_only_index.value = Number(data.pemeriksaan_mandiri_only_index) || 0
        if (cfg_mandiri_refill_answered) cfg_mandiri_refill_answered.value = String(data.pemeriksaan_mandiri_refill_answered)
        if (cfg_mandiri_auto_submit) cfg_mandiri_auto_submit.value = String(data.pemeriksaan_mandiri_auto_submit)
        if (cfg_session_auto_relogin) cfg_session_auto_relogin.value = String(data.session_auto_relogin)
        if (cfg_wali_nik) cfg_wali_nik.value = data.wali_nik || ''
        if (cfg_wali_nama) cfg_wali_nama.value = data.wali_nama || ''
        if (cfg_wali_no_hp) cfg_wali_no_hp.value = data.wali_no_hp || ''
        if (cfg_wali_instansi_puskesmas) cfg_wali_instansi_puskesmas.value = data.wali_instansi_puskesmas || ''
        if (cfg_wali_tgl) cfg_wali_tgl.value = data.wali_tgl || ''
        if (cfg_wali_jk) cfg_wali_jk.value = data.wali_jk || 'Perempuan'
    }
    sync_browser_engine_ui()

    settings_modal.classList.remove('hidden')
    setTimeout(() =>
    {
        settings_modal.classList.remove('opacity-0')
        if (settings_inner)
        {
            settings_inner.classList.remove('scale-95')
            settings_inner.classList.add('scale-100')
        }
    }, 10)
}

function close_settings_modal()
{
    settings_modal.classList.add('opacity-0')
    if (settings_inner)
    {
        settings_inner.classList.remove('scale-100')
        settings_inner.classList.add('scale-95')
    }
    setTimeout(() => settings_modal.classList.add('hidden'), 300)
}

async function save_advanced()
{
    const save_payload = {
        headless: cfg_headless?.value === 'true',
        browser_engine: cfg_browser_engine?.value || 'system',
        browser_channel: cfg_browser_channel?.value || 'msedge',
        pause_between_users_ms: Number(cfg_pause?.value) || 650,
        slow_mo_ms: Number(cfg_slow_mo?.value) || 0,
        stop_on_error: cfg_stop_on_error?.value === 'true',
        pemeriksaan_mandiri_recheck_completed: cfg_mandiri_recheck_completed?.value === 'true',
        pemeriksaan_mandiri_only_index: Number(cfg_mandiri_only_index?.value) || 0,
        pemeriksaan_mandiri_refill_answered: cfg_mandiri_refill_answered?.value === 'true',
        pemeriksaan_mandiri_auto_submit: cfg_mandiri_auto_submit?.value === 'true',
        session_auto_relogin: cfg_session_auto_relogin?.value !== 'false',
        wali_nik: cfg_wali_nik?.value.trim() || '',
        wali_nama: cfg_wali_nama?.value.trim() || '',
        wali_no_hp: cfg_wali_no_hp?.value.trim() || '',
        wali_instansi_puskesmas: cfg_wali_instansi_puskesmas?.value.trim() || '',
        wali_tgl: cfg_wali_tgl?.value || '',
        wali_jk: cfg_wali_jk?.value || 'Perempuan'
    }
    if (cfg_save_artifacts)
        save_payload.save_artifacts = cfg_save_artifacts.value === 'true'

    const result = await window.ipcRenderer.invoke('config_advanced_save', save_payload)

    if (result.ok)
    {
        notify('Pengaturan berhasil disimpan')
        add_log('[SYSTEM] Konfigurasi diperbarui', 'text-sky-400')
        close_settings_modal()
    } else notify('Gagal simpan: ' + result.error)
}

function sync_browser_engine_ui()
{
    if (!cfg_browser_engine || !cfg_browser_channel) return

    const engine = String(cfg_browser_engine.value || 'system').trim().toLowerCase()
    const bundled_only = engine === 'bundled'

    cfg_browser_channel.disabled = bundled_only
    cfg_browser_channel.classList.toggle('opacity-60', bundled_only)
    cfg_browser_channel.classList.toggle('cursor-not-allowed', bundled_only)

    if (cfg_browser_hint)
    {
        cfg_browser_hint.textContent = bundled_only
            ? 'Mode Chromium bawaan hanya bisa dipakai jika Anda build versi bundled.'
            : 'Mode tanpa bundle: gunakan browser sistem (Edge/Chrome) agar aplikasi lebih ringan.'
    }
}

function open_help_modal()
{
    help_modal.classList.remove('hidden')
    setTimeout(() =>
    {
        help_modal.classList.remove('opacity-0')
        if (help_inner)
        {
            help_inner.classList.remove('scale-95')
            help_inner.classList.add('scale-100')
        }
    }, 10)
}

function close_help_modal()
{
    help_modal.classList.add('opacity-0')
    if (help_inner)
    {
        help_inner.classList.remove('scale-100')
        help_inner.classList.add('scale-95')
    }
    setTimeout(() => help_modal.classList.add('hidden'), 300)
}

window.ipcRenderer.on('worker_log_batch', (event, lines) =>
{
    lines.forEach((line) =>
    {
        update_queue_progress_by_line(line)
        add_log(line, classify_log_color(line))
    })
})

window.ipcRenderer.on('worker_heartbeat', (event, hb) =>
{
    if (!state.is_node_running) set_ui_running()

    const pending_count = Number.parseInt(String(hb?.pending ?? '0'), 10)
    const running_count = Number.parseInt(String(hb?.running ?? '0'), 10)
    const safe_pending_count = Number.isFinite(pending_count) ? Math.max(0, pending_count) : 0
    const safe_running_count = Number.isFinite(running_count) ? Math.max(0, running_count) : 0
    const total_queue_count = safe_pending_count + safe_running_count

    const queue_total_locked = Number(state.queue_total_locked || 0)
    const queue_current_index = Number(state.queue_current_index || 0)
    const has_locked_queue = Number.isFinite(queue_total_locked) && queue_total_locked > 0

    if (has_locked_queue)
    {
        const safe_queue_index = Math.max(0, Math.min(queue_current_index, queue_total_locked))
        if (info_pending) info_pending.innerText = String(queue_total_locked)
        if (info_running) info_running.innerText = `${safe_queue_index}/${queue_total_locked}`
    }
    else
    {
        if (info_pending) info_pending.innerText = String(total_queue_count)
        if (info_running) info_running.innerText = `${safe_running_count}/${total_queue_count}`
    }
    if (info_busy) info_busy.innerText = hb.busy === 'true' ? 'Sibuk' : 'Siap'

    if (state.is_node_running)
    {
        if (hb.should_run === 'false')
        {
            status_indicator.children[0].innerText = 'Paused by Web'
            status_indicator.children[0].className = 'text-[10px] font-bold text-amber-500 uppercase tracking-wider'
            status_indicator.children[1].className = 'w-2.5 h-2.5 rounded-full bg-amber-500 transition-all'
            footer_dot.className = 'w-1.5 h-1.5 bg-amber-500 rounded-full inline-block'
            if (log_status) { log_status.innerText = 'PAUSED'; log_status.className = 'tracking-widest uppercase text-amber-500 font-bold' }
        } else
        {
            status_indicator.children[0].innerText = 'Running'
            status_indicator.children[0].className = 'text-[10px] font-bold text-emerald-500 uppercase tracking-wider'
            status_indicator.children[1].className = 'w-2.5 h-2.5 rounded-full bg-emerald-500 transition-all active-pulse'
            footer_dot.className = 'w-1.5 h-1.5 bg-emerald-500 rounded-full inline-block'
            if (log_status) { log_status.innerText = 'PROCESSING'; log_status.className = 'tracking-widest uppercase text-emerald-500 font-bold' }
        }
    }
})

async function sync_worker_state()
{
    try
    {
        const result = await window.ipcRenderer.invoke('worker_get_state')
        if (result?.running) set_ui_running()
        else set_ui_stopped()
    } catch { }
}

window.ipcRenderer.on('worker_status', (event, s) =>
{
    if (s === 'running') set_ui_running()
    else set_ui_stopped()
})

window.ipcRenderer.on('window_resized', (event, { width, height }) =>
{
    if (window_size_label) window_size_label.innerText = `${width} × ${height}`
    const login_debug = document.getElementById('loginDebugSize')
    if (login_debug) login_debug.innerText = `${width} × ${height}`
})

window.ipcRenderer.on('app_version_changed', (event, payload) =>
{
    const version = String(payload?.version || '').trim()
    if (version && app_version_label) app_version_label.innerText = 'v' + version
})

window.ipcRenderer.on('session_status', (event, s) =>
{
    if (s === 'valid') set_session_valid()
    else if (s === 'invalid') set_session_invalid('Login browser gagal')
    else if (s === 'logging_in') set_session_loading('Sedang login ke Sehat IndonesiaKu...')
})

window.ipcRenderer.on('license_revoked', () =>
{
    state.is_node_running = false
    state.is_session_valid = false
    set_btn_start_state()
    btn_stop.disabled = true
    add_log('[SYSTEM] ⛔ AKSES DICABUT OLEH ADMIN — Aplikasi akan keluar otomatis dalam 5 detik.', 'text-red-500 font-bold')
    notify('License dicabut! Mengarahkan ke login...')
    setTimeout(() => window.location.reload(), 5000)
})

window.ipcRenderer.on('quota_empty', () =>
{
    state.is_node_running = false
    set_btn_start_state()
    btn_stop.disabled = true
    add_log('[SYSTEM] ⛔ KUOTA NIK HABIS — Sistem berhenti otomatis. Hubungi admin untuk menambah kuota.', 'text-amber-500 font-bold')
    notify('Kuota NIK habis! Sistem berhenti.')
})

btn_theme_toggle.addEventListener('click', () => toggle_theme())
btn_minimize.addEventListener('click', () => window.ipcRenderer.send('window_minimize'))
btn_maximize.addEventListener('click', () => window.ipcRenderer.send('window_maximize'))
btn_close.addEventListener('click', () => window.ipcRenderer.send('window_close'))

if (btn_login_minimize) btn_login_minimize.addEventListener('click', () => window.ipcRenderer.send('window_minimize'))
if (btn_login_maximize) btn_login_maximize.addEventListener('click', () => window.ipcRenderer.send('window_maximize'))
if (btn_login_close) btn_login_close.addEventListener('click', () => window.ipcRenderer.send('window_close'))

btn_start.addEventListener('click', () => handle_start())
btn_stop.addEventListener('click', () => handle_stop())
btn_save_settings.addEventListener('click', () => save_account())
btn_clear.addEventListener('click', () => clear_logs())
btn_toggle_pass.addEventListener('click', () => toggle_pass_visibility())
btn_login_si.addEventListener('click', () => do_session_login())

btn_logout_si.addEventListener('click', async () =>
{
    state.is_session_valid = false
    set_btn_start_state()
    set_session_loading('Menghapus sesi...')
    await window.ipcRenderer.invoke('session_logout')
    await check_session()
})

btn_settings.addEventListener('click', () => open_settings_modal())
if (cfg_browser_engine) cfg_browser_engine.addEventListener('change', () => sync_browser_engine_ui())

if (btn_load_account)
{
    btn_load_account.addEventListener('click', () =>
    {
        if (state.saved_account_data?.email)
        {
            acc_email_input.value = state.saved_account_data.email
            acc_pass_input.value = state.saved_account_data.password || ''
            notify('Akun berhasil dimuat dari pengaturan')
        }
    })
}

btn_close_settings.addEventListener('click', () => close_settings_modal())
btn_cancel_settings.addEventListener('click', () => close_settings_modal())
btn_save_advanced.addEventListener('click', () => save_advanced())

if (btn_reset_data)
{
    btn_reset_data.addEventListener('click', async () =>
    {
        if (confirm('Apakah Anda yakin ingin MENGHAPUS SEMUA DATA LOKAL (Akun, Pasien, Sesi, dan Lisensi)?\n\nAplikasi akan dibersihkan seperti baru install.'))
        {
            close_settings_modal()
            notify('Menghapus data...')
            if (state.is_node_running) await window.ipcRenderer.invoke('worker_stop')
            await window.ipcRenderer.invoke('license_clear')
            window.location.reload()
        }
    })
}

settings_modal.addEventListener('click', event =>
{
    if (event.target === settings_modal) close_settings_modal()
})

btn_help.addEventListener('click', () => open_help_modal())
btn_close_help.addEventListener('click', () => close_help_modal())
btn_understand_help.addEventListener('click', () => close_help_modal())
help_modal.addEventListener('click', event =>
{
    if (event.target === help_modal) close_help_modal()
})

btn_verbose.addEventListener('click', function ()
{
    state.show_verbose = !state.show_verbose
    window.ipcRenderer.invoke('set_verbose_mode', state.show_verbose).catch(() => { })
    sync_verbose_button_state()
})

document.querySelectorAll('.settings-tab-btn').forEach(btn =>
{
    btn.addEventListener('click', function ()
    {
        document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'))
        document.querySelectorAll('.settings-tab-panel').forEach(p => p.classList.remove('active'))
        this.classList.add('active')
        const panel = document.getElementById('tab_' + this.dataset.tab)
        if (panel) panel.classList.add('active')
    })
})

setInterval(() =>
{
    if (clock_label) clock_label.innerText = new Date().toLocaleTimeString('en-GB')
}, 1000)

sync_worker_state().catch(() => { })
