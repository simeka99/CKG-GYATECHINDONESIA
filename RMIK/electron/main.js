import { app, BrowserWindow, ipcMain, shell } from 'electron'
import path from 'path'
import fs from 'fs'
import os from 'os'

import { set_main_window, get_main_window } from './core/windowManager.js'
import { init_updater, check_for_updates, start_download_update, install_update, get_update_versions, download_update_asset } from './core/updater.js'
import { account_load, account_save, config_advanced_load, config_advanced_save } from './core/config.js'
import { license_load, license_login, license_clear } from './core/api.js'
import { session_check, session_login, reset_cookies } from './core/session.js'
import { worker_start, worker_stop, get_worker_proc } from './core/worker.js'
import { config_file, storage_dir, user_data_dir, is_packaged } from './core/paths.js'
import { ensure_dir, read_json, write_json, update_last_active_time, check_idle_timeout, clear_all_user_data } from './core/utils.js'
import { flush_log_buffer_now, set_log_verbose } from './core/logger.js'

import { fileURLToPath } from 'url'
const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

import ejse from 'ejs-electron'

function apply_env_line(raw_line)
{
    const line = String(raw_line || '').trim()
    if (line === '' || line.startsWith('#'))
        return
    const eq_index = line.indexOf('=')
    if (eq_index <= 0)
        return

    const key = line.slice(0, eq_index).trim()
    if (key === '')
        return

    if (Object.prototype.hasOwnProperty.call(process.env, key))
        return

    let value = line.slice(eq_index + 1).trim()
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))
        value = value.slice(1, -1)
    process.env[key] = value
}

function load_local_env_file()
{
    const env_candidates = [
        path.resolve(__dirname, '..', '.env'),
        path.resolve(process.cwd(), '.env')
    ]

    for (const env_path of env_candidates)
    {
        if (!fs.existsSync(env_path))
            continue

        try
        {
            if (typeof process.loadEnvFile === 'function')
                process.loadEnvFile(env_path)
            else
            {
                const env_content = fs.readFileSync(env_path, 'utf-8')
                const env_lines = env_content.split(/\r?\n/)
                for (const env_line of env_lines)
                    apply_env_line(env_line)
            }
            return
        } catch
        {
            return
        }
    }
}

load_local_env_file()

app.commandLine.appendSwitch('disable-http-cache')
app.commandLine.appendSwitch('disk-cache-dir', path.join(user_data_dir, 'cache', 'http'))
app.commandLine.appendSwitch('gpu-disk-cache-dir', path.join(user_data_dir, 'cache', 'gpu'))

const got_single_instance_lock = app.requestSingleInstanceLock()
if (!got_single_instance_lock)
    app.quit()

app.on('second-instance', () =>
{
    const main_window = get_main_window()
    if (!main_window) return
    if (main_window.isMinimized()) main_window.restore()
    main_window.focus()
})

function init_config()
{
    ensure_dir(path.join(user_data_dir, 'cache', 'http'))
    ensure_dir(path.join(user_data_dir, 'cache', 'gpu'))
    ensure_dir(storage_dir)
    ensure_dir(user_data_dir)
    const current_config = read_json(config_file)
    if (current_config) return

    const default_config_candidates = [
        path.join(process.resourcesPath, 'app.asar', 'config.json'),
        path.join(app.getAppPath(), 'config.json'),
        path.resolve(__dirname, '..', 'config.json')
    ]
    const default_config_path = default_config_candidates.find(file_path => fs.existsSync(file_path))
    const default_config = default_config_path ? (read_json(default_config_path) || {}) : {}
    write_json(config_file, default_config)
}

async function create_window()
{
    const ejs_path = path.join(__dirname, 'ui', 'index.ejs')

    const main_window = new BrowserWindow({
        width: 1060,
        height: 800,
        minWidth: 600,
        minHeight: 400,
        frame: false,
        icon: path.join(__dirname, 'ui', 'assets', 'icon-rmik.png'),
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            devTools: !is_packaged,
            preload: path.join(__dirname, 'preload.cjs')
        }
    })

    set_main_window(main_window)
    await main_window.webContents.session.clearCache().catch(() => { })
    await main_window.webContents.session.clearStorageData({
        storages: ['cachestorage', 'serviceworkers']
    }).catch(() => { })
    main_window.loadFile(ejs_path)
    main_window.webContents.setWindowOpenHandler(({ url }) =>
    {
        if (/^https?:\/\//i.test(String(url || '')))
            shell.openExternal(url).catch(() => { })
        return { action: 'deny' }
    })
    main_window.webContents.on('will-navigate', (event, url) =>
    {
        if (!String(url || '').startsWith('file://'))
            event.preventDefault()
    })

    update_last_active_time()
    main_window.webContents.on('input-event', () => { update_last_active_time() })

    main_window.on('resize', () =>
    {
        if (!main_window || main_window.isDestroyed()) return
        const [w, h] = main_window.getSize()
        main_window.webContents.send('window_resized', { width: w, height: h })
    })

    main_window.webContents.on('did-finish-load', () =>
    {
        if (!main_window || main_window.isDestroyed()) return
        const [w, h] = main_window.getSize()
        main_window.webContents.send('window_resized', { width: w, height: h })
        main_window.webContents.send('app_version_changed', { version: app.getVersion() })
    })

    main_window.on('closed', () =>
    {
        set_main_window(null)
    })

    init_updater()
}

app.whenReady().then(async () =>
{
    init_config()
    await create_window()

    setTimeout(() =>
    {
        check_for_updates()
    }, 3000)

    setInterval(() =>
    {
        check_for_updates().catch(() => { })
    }, 30 * 60 * 1000)

    setInterval(() =>
    {
        if (check_idle_timeout())
        {
            clear_all_user_data();
            const mw = get_main_window();
            if (mw)
            {
                mw.webContents.send('session_status', 'invalid');
                mw.webContents.send('worker_log_batch', ['[SYSTEM] Sesi Anda habis karena aplikasi menganggur selama lebih dari 2 jam. Data sesi otomatis dibersihkan demi keamanan.']);
            }
        }
    }, 10 * 60 * 1000)
})

app.on('window-all-closed', () =>
{
    flush_log_buffer_now()
    const p = get_worker_proc()
    if (p) p.kill()
    if (process.platform !== 'darwin')
        app.quit()
})

app.on('before-quit', () =>
{
    flush_log_buffer_now()
})

ipcMain.on('window_minimize', () => get_main_window()?.minimize())
ipcMain.on('window_maximize', () =>
{
    const mw = get_main_window()
    if (!mw) return
    if (mw.isMaximized()) mw.unmaximize()
    else mw.maximize()
})
ipcMain.on('window_close', () =>
{
    const p = get_worker_proc()
    if (p) p.kill()
    get_main_window()?.close()
})

ipcMain.handle('license_load', license_load)
ipcMain.handle('license_login', (e, k) => license_login(k))
ipcMain.handle('license_clear', license_clear)

ipcMain.handle('account_load', account_load)
ipcMain.handle('account_save', (e, cred) => account_save(cred))

ipcMain.handle('config_advanced_load', config_advanced_load)
ipcMain.handle('config_advanced_save', (e, act) => config_advanced_save(act))
ipcMain.handle('set_verbose_mode', (e, enabled) =>
{
    set_log_verbose(enabled === true || enabled === 'true')
    return { ok: true }
})

ipcMain.handle('app_version', () => app.getVersion())

ipcMain.handle('session_check', session_check)
ipcMain.handle('session_login', session_login)
ipcMain.handle('session_logout', async () =>
{
    reset_cookies()
    return { ok: true }
})

ipcMain.handle('worker_start', worker_start)
ipcMain.handle('worker_stop', worker_stop)
ipcMain.handle('worker_get_state', () => ({ running: !!get_worker_proc() }))

ipcMain.handle('check_for_updates', () => check_for_updates())
ipcMain.handle('start_download_update', start_download_update)
ipcMain.handle('install_update', install_update)
ipcMain.handle('get_update_versions', () => get_update_versions())
ipcMain.handle('download_update_asset', (e, payload) => download_update_asset(payload))
ipcMain.handle('open_downloaded_update', async (e, local_path) =>
{
    const value = String(local_path || '').trim()
    if (!value) return { ok: false, error: 'Path file kosong' }
    const normalized_path = path.resolve(value)
    const downloads_dir = path.resolve(app.getPath('downloads'))
    const ext = path.extname(normalized_path).toLowerCase()
    if (!normalized_path.startsWith(downloads_dir + path.sep) || ext !== '.exe')
        return { ok: false, error: 'Path installer tidak diizinkan' }

    try
    {
        const err = await shell.openPath(normalized_path)
        if (err) return { ok: false, error: err }
        return { ok: true }
    } catch (error)
    {
        return { ok: false, error: String(error?.message || error || 'Gagal membuka installer') }
    }
})
ipcMain.handle('open_external_url', async (e, target_url) =>
{
    const value = String(target_url || '').trim()
    if (!/^https?:\/\//i.test(value))
        return { ok: false, error: 'URL tidak valid' }

    try
    {
        await shell.openExternal(value)
        return { ok: true }
    } catch (err)
    {
        return { ok: false, error: String(err?.message || err || 'Gagal membuka link') }
    }
})
