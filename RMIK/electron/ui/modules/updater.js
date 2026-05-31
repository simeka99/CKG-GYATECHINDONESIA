import { main_controller, btn_update_notify, badge_update_notif } from './elements.js'
import { state } from './state.js'
import { notify } from './utils.js'

let versions_modal = null
let versions_list_box = null
let versions_status_box = null
let progress_wrap = null
let progress_fill = null
let progress_text = null
let versions_modal_panel = null
let versions_refresh_timer = null
let restart_prompt_modal = null
let restart_prompt_panel = null
let restart_prompt_desc = null

let app_version_cache = ''
let auto_check_started = false
let last_progress_notified = -10
let unread_update_notif_count = 0
let last_update_notice_key = ''
let auto_open_installer_after_manual_download = false

function compare_versions(a, b)
{
    const pa = String(a || '').split('.').map(x => Number(x) || 0)
    const pb = String(b || '').split('.').map(x => Number(x) || 0)
    const len = Math.max(pa.length, pb.length)

    for (let i = 0; i < len; i++)
    {
        const va = pa[i] ?? 0
        const vb = pb[i] ?? 0
        if (va !== vb) return va - vb
    }

    return 0
}

function safe_text(value)
{
    return String(value || '').replace(/[&<>"]/g, c =>
    ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;'
    }[c]))
}

function set_badge(value)
{
    if (!badge_update_notif) return

    if (!value)
    {
        badge_update_notif.classList.add('opacity-0', 'scale-50')
        btn_update_notify?.setAttribute('title', 'Cek update')
        return
    }

    badge_update_notif.textContent = String(value)
    badge_update_notif.classList.remove('opacity-0', 'scale-50')
}

function sync_unread_badge()
{
    if (unread_update_notif_count <= 0)
    {
        set_badge(null)
        return
    }
    set_badge(String(Math.min(99, unread_update_notif_count)))
}

function push_update_notice(key = '')
{
    const normalized_key = String(key || '').trim()
    if (normalized_key && normalized_key === last_update_notice_key) return
    if (normalized_key) last_update_notice_key = normalized_key
    unread_update_notif_count = Math.min(99, unread_update_notif_count + 1)
    sync_unread_badge()
}

function clear_update_notices()
{
    unread_update_notif_count = 0
    sync_unread_badge()
}

function render_progress(percent, status_text = '')
{
    if (!progress_wrap || !progress_fill || !progress_text) return

    const safe_percent = Math.max(0, Math.min(100, Number(percent || 0)))
    const visible = state.current_update_state === 'downloading'

    if (!visible)
    {
        progress_wrap.classList.add('hidden')
        return
    }

    progress_wrap.classList.remove('hidden')
    progress_fill.style.width = `${safe_percent}%`
    progress_text.textContent = `${status_text || 'Downloading update'}: ${safe_percent.toFixed(1)}%`
}

function set_updater_state(next_state, info = null, extra = {})
{
    state.current_update_state = next_state
    if (info) state.current_update_info = info

    if (next_state === 'downloading')
    {
        const percent = Number(extra.percent ?? state.current_update_percent ?? 0)
        state.current_update_percent = percent
        btn_update_notify?.setAttribute('title', `Downloading ${percent.toFixed(1)}%`)
    }
    else if (next_state === 'available')
    {
        btn_update_notify?.setAttribute('title', 'Ada pembaruan aplikasi')
    }
    else if (next_state === 'ready')
    {
        btn_update_notify?.setAttribute('title', 'Unduhan selesai - pilih Restart Sekarang/Nanti')
    }
    else
    {
        state.current_update_percent = 0
        btn_update_notify?.setAttribute('title', 'Cek update')
    }

    render_progress(state.current_update_percent)
}

async function ensure_app_version()
{
    if (app_version_cache) return app_version_cache
    try
    {
        app_version_cache = String(await window.ipcRenderer.invoke('app_version') || '').trim()
        return app_version_cache
    }
    catch
    {
        return ''
    }
}

function format_size(size_bytes)
{
    const size = Number(size_bytes || 0)
    if (!Number.isFinite(size) || size <= 0) return '-'
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
    return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function format_date(value)
{
    const d = new Date(value || '')
    if (Number.isNaN(d.getTime())) return '-'
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
    const pad = (num) => String(num).padStart(2, '0')
    const day = pad(d.getDate())
    const month = months[d.getMonth()] || 'Jan'
    const year = d.getFullYear()
    const hour = pad(d.getHours())
    const minute = pad(d.getMinutes())
    return `${day} ${month} ${year}, ${hour}:${minute}`
}

async function start_auto_update_download(info = null)
{
    if (state.current_update_state === 'downloading')
    {
        notify('Sedang mengunduh pembaruan. Setelah selesai, pilih Restart Sekarang atau Nanti di lonceng.')
        return true
    }

    if (state.current_update_state === 'ready')
    {
        notify('Pembaruan siap dipasang. Pilih Restart Sekarang atau Nanti di lonceng.')
        return true
    }

    let resolved_info = info || state.current_update_info || null

    if (!resolved_info?.version)
    {
        const check_result = await window.ipcRenderer.invoke('check_for_updates').catch(() => null)

        if (check_result?.skipped && check_result.reason === 'APP_NOT_PACKED')
        {
            set_updater_state('idle')
            notify('Updater internal hanya aktif di EXE build, bukan mode dev.')
            return false
        }

        resolved_info = check_result?.info || state.current_update_info || null
        if (!resolved_info?.version)
        {
            set_updater_state('idle')
            notify('Versi terbaru belum terdeteksi sebagai update internal.')
            return false
        }
    }

    set_updater_state('downloading', resolved_info, { percent: 0 })

    const res = await window.ipcRenderer.invoke('start_download_update').catch(() => ({ ok: false }))
    if (res?.ok)
    {
        notify('Unduhan pembaruan dimulai. Setelah selesai, pilih Restart Sekarang atau Nanti di lonceng.')
        return true
    }

    if (res?.skipped && res?.reason === 'APP_NOT_PACKED')
    {
        set_updater_state('idle')
        notify('Updater internal hanya aktif di EXE build, bukan mode dev.')
        return false
    }

    set_updater_state('available', resolved_info)
    notify('Gagal mulai download update: ' + (res?.error || 'Unknown error'))
    return false
}

async function start_asset_download(url, version, file)
{
    set_updater_state('downloading', { version }, { percent: 0 })
    const res = await window.ipcRenderer.invoke('download_update_asset', { url, version, file }).catch(() => ({ ok: false }))
    if (!res?.ok)
    {
        set_updater_state('idle')
        notify('Gagal download file update: ' + (res?.error || 'Unknown error'))
        auto_open_installer_after_manual_download = false
        return false
    }
    return true
}

function ensure_versions_modal()
{
    if (versions_modal) return

    const root = document.createElement('div')
    root.id = 'updateVersionsModal'
    root.className = 'fixed inset-0 z-[300] hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4 opacity-0 transition-opacity duration-200'
    root.innerHTML = `
        <div id="updateVersionsPanel" class="w-full max-w-3xl rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden transform transition-all duration-200 scale-95 opacity-0">
            <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                <div>
                    <div class="text-sm font-bold text-slate-900 dark:text-white">Daftar Versi Aplikasi</div>
                    <div id="updateVersionStatus" class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Memuat daftar versi...</div>
                </div>
                <div class="flex items-center gap-2">
                    <button id="btnUpdateRefreshList" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-[11px] font-bold text-slate-700 dark:text-slate-200">Refresh</button>
                    <button id="btnUpdateCloseList" class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-[11px] font-bold text-white">Tutup</button>
                </div>
            </div>
            <div id="updateProgressWrap" class="hidden px-5 py-3 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/40">
                <div id="updateProgressText" class="text-[11px] font-semibold text-slate-700 dark:text-slate-200 mb-2">Downloading update: 0.0%</div>
                <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                    <div id="updateProgressFill" class="h-2 bg-emerald-500 transition-all duration-300" style="width:0%"></div>
                </div>
            </div>
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/30 text-[11px] text-slate-600 dark:text-slate-300">
                Klik <b>Unduh</b> untuk mengunduh pembaruan. Setelah selesai, pilih <b>Restart Sekarang</b> atau <b>Nanti</b>.
            </div>
            <div id="updateVersionList" class="max-h-[62vh] overflow-y-auto p-4 space-y-2 bg-white dark:bg-slate-900"></div>
        </div>
    `

    document.body.appendChild(root)
    versions_modal = root
    versions_list_box = document.getElementById('updateVersionList')
    versions_status_box = document.getElementById('updateVersionStatus')
    progress_wrap = document.getElementById('updateProgressWrap')
    progress_fill = document.getElementById('updateProgressFill')
    progress_text = document.getElementById('updateProgressText')
    versions_modal_panel = document.getElementById('updateVersionsPanel')

    const btn_close = document.getElementById('btnUpdateCloseList')
    const btn_refresh = document.getElementById('btnUpdateRefreshList')

    if (btn_close) btn_close.addEventListener('click', close_versions_modal)
    if (btn_refresh) btn_refresh.addEventListener('click', () => render_versions_list(true))

    root.addEventListener('click', (event) =>
    {
        if (event.target === root) close_versions_modal()
    })

    if (versions_list_box)
    {
        versions_list_box.addEventListener('click', async (event) =>
        {
            const target = event.target
            if (!(target instanceof HTMLElement)) return

            const button = target.closest('button[data-download-mode]')
            if (!button) return

            const mode = String(button.getAttribute('data-download-mode') || 'asset')
            const url = String(button.getAttribute('data-download-url') || '').trim()
            const version = String(button.getAttribute('data-version') || '').trim()
            const file = String(button.getAttribute('data-file') || '').trim()

            if (mode === 'install')
            {
                close_restart_prompt_modal()
                notify('Menghentikan aplikasi...')
                await window.ipcRenderer.invoke('install_update').catch(() => { })
                return
            }

            if (mode === 'later')
            {
                close_restart_prompt_modal()
                close_versions_modal()
                notify('Pembaruan ditunda. Anda bisa restart kapan saja dari lonceng.')
                return
            }

            if (mode === 'auto_install')
            {
                await start_auto_update_download(state.current_update_info || { version })
                return
            }

            if (mode === 'current')
            {
                notify('Versi ini sedang dipakai dan tidak perlu di-download.')
                return
            }

            if (!url)
            {
                notify('URL download tidak valid')
                return
            }

            if (mode === 'asset_install')
                auto_open_installer_after_manual_download = true

            await start_asset_download(url, version, file)
        })
    }
}

function open_versions_modal()
{
    ensure_versions_modal()
    if (!versions_modal) return
    versions_modal.classList.remove('hidden')
    versions_modal.classList.add('flex')
    requestAnimationFrame(() =>
    {
        versions_modal?.classList.remove('opacity-0')
        versions_modal_panel?.classList.remove('scale-95', 'opacity-0')
    })
    render_progress(state.current_update_percent)
}

function close_versions_modal()
{
    if (!versions_modal) return
    versions_modal.classList.add('opacity-0')
    versions_modal_panel?.classList.add('scale-95', 'opacity-0')
    setTimeout(() =>
    {
        if (!versions_modal) return
        versions_modal.classList.add('hidden')
        versions_modal.classList.remove('flex')
    }, 200)
}

function refresh_versions_modal_if_open()
{
    if (!versions_modal || versions_modal.classList.contains('hidden')) return
    if (versions_refresh_timer)
        clearTimeout(versions_refresh_timer)

    versions_refresh_timer = setTimeout(() =>
    {
        render_versions_list(false).catch(() => { })
    }, 300)
}

function ensure_restart_prompt_modal()
{
    if (restart_prompt_modal) return

    const root = document.createElement('div')
    root.id = 'updateReadyPromptModal'
    root.className = 'fixed inset-0 z-[350] hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4 opacity-0 transition-opacity duration-200'
    root.innerHTML = `
        <div id="updateReadyPromptPanel" class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden transform transition-all duration-200 scale-95 opacity-0">
            <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-700">
                <div class="text-base font-extrabold text-slate-900 dark:text-white">Pembaruan Siap Dipasang</div>
                <div id="updateReadyPromptDesc" class="text-[12px] text-slate-600 dark:text-slate-300 mt-1.5">Unduhan pembaruan selesai. Restart aplikasi sekarang untuk menerapkan versi terbaru.</div>
            </div>
            <div class="px-5 py-4 flex items-center justify-end gap-2 bg-slate-50 dark:bg-slate-800/30">
                <button id="btnUpdateRestartLater" type="button" class="px-3 py-2 rounded-lg bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-100 text-[12px] font-bold">Nanti</button>
                <button id="btnUpdateRestartNow" type="button" class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-[12px] font-bold">Restart Sekarang</button>
            </div>
        </div>
    `

    document.body.appendChild(root)
    restart_prompt_modal = root
    restart_prompt_panel = document.getElementById('updateReadyPromptPanel')
    restart_prompt_desc = document.getElementById('updateReadyPromptDesc')

    const btn_restart_now = document.getElementById('btnUpdateRestartNow')
    const btn_restart_later = document.getElementById('btnUpdateRestartLater')

    if (btn_restart_now)
    {
        btn_restart_now.addEventListener('click', async () =>
        {
            close_restart_prompt_modal()
            notify('Menghentikan aplikasi...')
            await window.ipcRenderer.invoke('install_update').catch(() => { })
        })
    }

    if (btn_restart_later)
    {
        btn_restart_later.addEventListener('click', () =>
        {
            close_restart_prompt_modal()
            notify('Pembaruan ditunda. Anda bisa restart kapan saja dari lonceng.')
        })
    }
}

function open_restart_prompt_modal()
{
    ensure_restart_prompt_modal()
    if (!restart_prompt_modal) return

    const version = String(state.current_update_info?.version || '').trim()
    if (restart_prompt_desc)
    {
        restart_prompt_desc.textContent = version
            ? `Unduhan pembaruan v${version} selesai. Restart aplikasi sekarang untuk menerapkan versi terbaru.`
            : 'Unduhan pembaruan selesai. Restart aplikasi sekarang untuk menerapkan versi terbaru.'
    }

    restart_prompt_modal.classList.remove('hidden')
    restart_prompt_modal.classList.add('flex')
    requestAnimationFrame(() =>
    {
        restart_prompt_modal?.classList.remove('opacity-0')
        restart_prompt_panel?.classList.remove('scale-95', 'opacity-0')
    })
}

function close_restart_prompt_modal()
{
    if (!restart_prompt_modal) return
    restart_prompt_modal.classList.add('opacity-0')
    restart_prompt_panel?.classList.add('scale-95', 'opacity-0')
    setTimeout(() =>
    {
        if (!restart_prompt_modal) return
        restart_prompt_modal.classList.add('hidden')
        restart_prompt_modal.classList.remove('flex')
    }, 200)
}

function render_rows(versions, app_version)
{
    if (!versions_list_box) return

    const available_version = String(state.current_update_info?.version || '').trim()
    const is_ready_state = state.current_update_state === 'ready'

    const html = versions.map((v, index) =>
    {
        const version = safe_text(v.version)
        const file = safe_text(v.file)
        const url = safe_text(v.url)
        const is_latest = index === 0
        const is_current = app_version && v.version === app_version
        const is_available = available_version && v.version === available_version
        const is_newer_than_installed = app_version ? compare_versions(v.version, app_version) > 0 : is_latest
        const use_install = Boolean(
            is_ready_state && (
                is_available || (
                    !available_version &&
                    is_latest &&
                    !is_current &&
                    is_newer_than_installed
                )
            )
        )
        const use_auto = Boolean(!use_install && (is_available || (is_latest && !is_current && is_newer_than_installed)))
        const is_current_locked = Boolean(is_current)
        const button_label = is_current_locked ? 'Sedang Dipakai' : 'Unduh'
        const button_mode = use_install ? 'install' : (use_auto ? 'auto_install' : (is_current_locked ? 'current' : 'asset_install'))
        const button_class = use_auto || use_install
            ? 'bg-emerald-600 hover:bg-emerald-500'
            : (is_current_locked ? 'bg-slate-300 dark:bg-slate-700 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-500')
        const action_controls = use_install
            ? `
                        <div class="shrink-0 flex items-center gap-2">
                            <button
                                type="button"
                                data-download-mode="install"
                                data-version="${version}"
                                class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-bold"
                            >Restart Sekarang</button>
                            <button
                                type="button"
                                data-download-mode="later"
                                data-version="${version}"
                                class="px-3 py-1.5 rounded-lg bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-100 text-[11px] font-bold"
                            >Nanti</button>
                        </div>`
            : `
                    <button
                        type="button"
                        data-download-url="${url}"
                        data-version="${version}"
                        data-file="${file}"
                        data-download-mode="${button_mode}"
                        class="shrink-0 px-3 py-1.5 rounded-lg ${button_class} text-white text-[11px] font-bold"
                        ${is_current_locked ? 'disabled aria-disabled="true"' : ''}
                    >${button_label}</button>`

        const badges = [
            is_latest ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30">Latest</span>' : '',
            is_current ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30">Terpasang</span>' : '',
            is_available ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-100 dark:bg-sky-500/20 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-500/30">Update Siap</span>' : ''
        ].filter(Boolean).join(' ')

        return `
            <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/40 px-4 py-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-bold text-slate-900 dark:text-white">v${version}</div>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">${file}</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-500 mt-1">Ukuran: ${format_size(v.size_bytes)} - ${format_date(v.updated_at)}</div>
                        <div class="mt-1.5 flex items-center gap-1.5">${badges}</div>
                    </div>
${action_controls}
                </div>
            </div>
        `
    }).join('')

    versions_list_box.innerHTML = html || '<div class="text-[12px] text-slate-500 dark:text-slate-400">Belum ada versi yang tersedia.</div>'
}

async function render_versions_list(force_check = false)
{
    ensure_versions_modal()
    if (!versions_list_box || !versions_status_box) return

    if (force_check)
    {
        await window.ipcRenderer.invoke('check_for_updates').catch(() => null)
    }

    const app_version = await ensure_app_version()
    versions_status_box.textContent = 'Memuat daftar versi...'
    if (!versions_list_box.children.length)
        versions_list_box.innerHTML = '<div class="text-[12px] text-slate-500 dark:text-slate-400">Loading...</div>'

    const list_result = await window.ipcRenderer.invoke('get_update_versions').catch(() => null)
    if (!list_result?.ok)
    {
        versions_status_box.textContent = 'Gagal memuat daftar versi.'
        versions_list_box.innerHTML = `<div class="text-[12px] text-rose-500 dark:text-rose-300">Tidak dapat membaca versions.json. ${safe_text(list_result?.error || '')}</div>`
        return
    }

    const versions = merge_with_available_version(Array.isArray(list_result.versions) ? list_result.versions : [])
    versions_status_box.textContent = `Versi terpasang: v${app_version || '-'} - Tersedia: ${versions.length} versi`
    render_rows(versions, app_version)
}

function merge_with_available_version(base_versions)
{
    const available_version = String(state.current_update_info?.version || '').trim()
    if (!available_version) return base_versions
    if (base_versions.some(v => String(v?.version || '').trim() === available_version)) return base_versions

    const fallback_row = {
        version: available_version,
        file: `RMIK Setup ${available_version}.exe`,
        url: '',
        size_bytes: 0,
        updated_at: new Date().toISOString(),
    }

    return [fallback_row, ...base_versions].sort((a, b) => compare_versions(String(b?.version || ''), String(a?.version || '')))
}

async function trigger_check_for_updates({ silent = true } = {})
{
    if (state.current_update_state === 'checking' || state.current_update_state === 'downloading')
        return

    if (!silent) notify('Memeriksa pembaruan...')
    set_updater_state('checking')

    const result = await window.ipcRenderer.invoke('check_for_updates').catch(() => null)
    if (result?.skipped && result.reason === 'APP_NOT_PACKED' && !silent)
    {
        set_updater_state('idle')
        notify('Updater aktif di aplikasi EXE (build), bukan mode dev.')
    }
}

function setup_auto_check()
{
    if (auto_check_started) return
    auto_check_started = true

    window.addEventListener('init-after-login-done', () =>
    {
        trigger_check_for_updates({ silent: true }).catch(() => { })
    })

    setInterval(() =>
    {
        if (main_controller && main_controller.classList.contains('hidden')) return
        trigger_check_for_updates({ silent: true }).catch(() => { })
    }, 5 * 60 * 1000)
}

if (btn_update_notify)
{
    btn_update_notify.addEventListener('click', async () =>
    {
        clear_update_notices()

        if (state.current_update_state === 'checking')
        {
            notify('Sedang memeriksa pembaruan...')
            return
        }

        open_versions_modal()
        await render_versions_list(state.current_update_state !== 'downloading')
    })
}

window.ipcRenderer.on('update_available', (event, info) =>
{
    set_updater_state('available', info)
    push_update_notice(`available:${String(info?.version || '').trim() || 'latest'}`)
    refresh_versions_modal_if_open()
})

window.ipcRenderer.on('update_not_available', () =>
{
    if (state.current_update_state !== 'ready')
        set_updater_state('idle')
})

window.ipcRenderer.on('update_download_progress', (event, progress) =>
{
    const percent = Number(progress?.percent || 0)
    state.current_update_percent = percent
    set_updater_state('downloading', state.current_update_info || null, { percent })
    render_progress(percent, 'Downloading update')

    const rounded = Math.floor(percent)
    if (rounded >= last_progress_notified + 10 || rounded >= 100)
    {
        last_progress_notified = rounded
        notify(`Mengunduh update: ${percent.toFixed(1)}%`)
    }
})

window.ipcRenderer.on('update_ready', () =>
{
    state.current_update_percent = 100
    set_updater_state('ready')
    push_update_notice(`ready:${String(state.current_update_info?.version || '').trim() || 'latest'}`)
    render_progress(100, 'Download selesai')
    refresh_versions_modal_if_open()
    open_restart_prompt_modal()
})

window.ipcRenderer.on('update_state', (event, payload) =>
{
    const next = payload?.state || 'idle'
    if (next === 'checking')
        set_updater_state('checking')
    else if (next === 'downloading')
        set_updater_state('downloading', state.current_update_info || null, { percent: Number(payload?.percent || 0) })
    else if (next === 'idle' && state.current_update_state !== 'ready')
        set_updater_state('idle')
})

window.ipcRenderer.on('update_error', (event, payload) =>
{
    set_updater_state('error')
    notify('Updater error: ' + (payload?.message || 'Unknown error'))
})

window.ipcRenderer.on('manual_update_download_progress', (event, payload) =>
{
    const percent = Number(payload?.percent || 0)
    const version = String(payload?.version || '').trim()
    state.current_update_percent = percent
    set_updater_state('downloading', version ? { version } : state.current_update_info || null, { percent })
    render_progress(percent, version ? `Downloading installer v${version}` : 'Downloading installer')
})

window.ipcRenderer.on('manual_update_download_done', (event, payload) =>
{
    set_updater_state('idle')
    render_progress(0)

    const file_path = String(payload?.file_path || '').trim()
    const version = String(payload?.version || '').trim()

    if (auto_open_installer_after_manual_download)
    {
        auto_open_installer_after_manual_download = false
        window.ipcRenderer.invoke('open_downloaded_update', file_path).then((res) =>
        {
            if (!res?.ok) notify('Gagal membuka installer: ' + (res?.error || 'Unknown error'))
        }).catch(() => notify('Gagal membuka installer'))
        return
    }

    notify(`Installer ${version ? `v${version}` : ''} selesai diunduh ke folder Downloads.`)
})

window.ipcRenderer.on('manual_update_download_error', (event, payload) =>
{
    auto_open_installer_after_manual_download = false
    set_updater_state('idle')
    notify('Download installer gagal: ' + (payload?.message || 'Unknown error'))
})

setup_auto_check()
