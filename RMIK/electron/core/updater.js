import updaterPkg from 'electron-updater'
const autoUpdater = updaterPkg.autoUpdater || updaterPkg.default?.autoUpdater
import { app } from 'electron'
import fs from 'fs'
import path from 'path'
import { send_to_renderer } from './windowManager.js'
import { read_json } from './utils.js'
import { config_file, license_file } from './paths.js'

autoUpdater.autoDownload = false
autoUpdater.autoInstallOnAppQuit = false
let manual_download_running = false
let current_feed_url = ''

function normalize_error(err)
{
    const text = String(err?.message || err || 'Unknown updater error')
    return text.replace(/\s+/g, ' ').trim()
}

function normalize_update_channel(value)
{
    const raw = String(value || '').trim().toLowerCase()
    const clean = raw.replace(/[^a-z0-9._-]/g, '-').replace(/-+/g, '-').replace(/^[-._]+|[-._]+$/g, '')
    return clean || 'public'
}

function resolve_updates_channel()
{
    const lic = read_json(license_file) || {}
    return normalize_update_channel(lic?.update_channel || 'public')
}

function resolve_updates_base_url(channel = 'public')
{
    const config = read_json(config_file) || {}
    const api_base = String(config?.api?.base_url || '').trim()
    const clean_channel = normalize_update_channel(channel)

    let base_url = ''
    if (api_base)
    {
        if (api_base.endsWith('/api'))
            base_url = api_base.replace(/\/api$/, '/updates/')
        else
        {
            try
            {
                const origin = new URL(api_base).origin
                base_url = `${origin}/updates/`
            }
            catch { }
        }
    }

    if (!base_url)
        base_url = 'https://rmik.gyatechindonesia.com/updates/'

    const normalized_base = base_url.replace(/\/+$/, '/')
    if (clean_channel === 'public')
        return normalized_base
    return `${normalized_base}channels/${clean_channel}/`
}

function apply_feed_url_for_current_license()
{
    const channel = resolve_updates_channel()
    const feed_url = resolve_updates_base_url(channel)
    if (feed_url === current_feed_url) return

    autoUpdater.setFeedURL({
        provider: 'generic',
        url: feed_url
    })
    current_feed_url = feed_url
}

function sanitize_file_name(name)
{
    const raw = String(name || '').trim()
    if (!raw) return 'update-installer.exe'
    const safe = raw.replace(/[<>:"/\\|?*\u0000-\u001F]+/g, '_')
    return safe || 'update-installer.exe'
}

function is_allowed_update_url(url)
{
    try
    {
        const parsed = new URL(url)
        const host = String(parsed.hostname || '').toLowerCase()
        return parsed.protocol === 'https:' && (host === 'rmik.gyatechindonesia.com' || host.endsWith('.gyatechindonesia.com'))
    }
    catch
    {
        return false
    }
}

export function init_updater()
{
    autoUpdater.on('checking-for-update', () =>
    {
        send_to_renderer('update_state', { state: 'checking' })
    })

    autoUpdater.on('update-available', (info) =>
    {
        send_to_renderer('update_state', { state: 'available' })
        send_to_renderer('update_available', info)
    })

    autoUpdater.on('update-not-available', (info) =>
    {
        send_to_renderer('update_state', { state: 'idle' })
        send_to_renderer('update_not_available', info || {})
    })

    autoUpdater.on('download-progress', (progress) =>
    {
        send_to_renderer('update_state', {
            state: 'downloading',
            percent: Number(progress?.percent || 0),
            transferred: Number(progress?.transferred || 0),
            total: Number(progress?.total || 0),
        })
        send_to_renderer('update_download_progress', progress || {})
    })

    autoUpdater.on('update-downloaded', () =>
    {
        send_to_renderer('update_state', { state: 'ready' })
        send_to_renderer('update_ready')
    })

    autoUpdater.on('error', (err) =>
    {
        send_to_renderer('update_state', { state: 'error' })
        send_to_renderer('update_error', { message: normalize_error(err) })
    })
}

export async function check_for_updates()
{
    if (!app.isPackaged)
        return { ok: false, skipped: true, reason: 'APP_NOT_PACKED' }

    try
    {
        apply_feed_url_for_current_license()
        const result = await autoUpdater.checkForUpdates()
        return { ok: true, info: result?.updateInfo || null }
    } catch (err)
    {
        return { ok: false, error: normalize_error(err) }
    }
}

export async function start_download_update()
{
    if (!app.isPackaged)
        return { ok: false, skipped: true, reason: 'APP_NOT_PACKED' }

    try
    {
        apply_feed_url_for_current_license()
        await autoUpdater.downloadUpdate()
        return { ok: true }
    } catch (err)
    {
        return { ok: false, error: normalize_error(err) }
    }
}

export function install_update()
{
    autoUpdater.quitAndInstall(true, true)
    return { ok: true }
}

export async function download_update_asset(payload = {})
{
    const url = String(payload?.url || '').trim()
    const file_name = sanitize_file_name(payload?.file || '')
    const version = String(payload?.version || '').trim()

    if (!/^https?:\/\//i.test(url))
        return { ok: false, error: 'URL file update tidak valid' }
    if (!is_allowed_update_url(url))
        return { ok: false, error: 'URL update tidak diizinkan' }

    if (manual_download_running)
        return { ok: false, error: 'Download update lain sedang berjalan' }

    manual_download_running = true

    const downloads_dir = app.getPath('downloads')
    const target_path = path.join(downloads_dir, file_name)
    const temp_path = `${target_path}.part`

    try
    {
        await fs.promises.mkdir(downloads_dir, { recursive: true })
        if (fs.existsSync(temp_path))
            await fs.promises.unlink(temp_path).catch(() => { })

        const { default: fetch } = await import('node-fetch')
        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' },
            redirect: 'follow'
        })

        if (!response.ok || !response.body)
            return { ok: false, error: `Gagal download file update (HTTP ${response.status})` }

        const total = Number(response.headers.get('content-length') || 0)
        const out = fs.createWriteStream(temp_path)

        await new Promise((resolve, reject) =>
        {
            let transferred = 0
            let last_emit = 0

            response.body.on('data', (chunk) =>
            {
                transferred += chunk.length
                const percent = total > 0 ? (transferred / total) * 100 : 0
                const now = Date.now()
                if (now - last_emit >= 180 || percent >= 100)
                {
                    last_emit = now
                    send_to_renderer('manual_update_download_progress', {
                        percent,
                        transferred,
                        total,
                        version,
                        file: file_name
                    })
                }
            })

            response.body.on('error', reject)
            out.on('error', reject)
            out.on('finish', resolve)
            response.body.pipe(out)
        })

        if (fs.existsSync(target_path))
            await fs.promises.unlink(target_path).catch(() => { })
        await fs.promises.rename(temp_path, target_path)

        send_to_renderer('manual_update_download_done', {
            file_path: target_path,
            version,
            file: file_name
        })

        return { ok: true, file_path: target_path, file: file_name }
    }
    catch (err)
    {
        await fs.promises.unlink(temp_path).catch(() => { })
        const message = normalize_error(err)
        send_to_renderer('manual_update_download_error', { message })
        return { ok: false, error: message }
    }
    finally
    {
        manual_download_running = false
    }
}

export async function get_update_versions()
{
    const channel = resolve_updates_channel()
    const base_url = resolve_updates_base_url(channel).replace(/\/+$/, '')
    const target = `${base_url}/versions.json?t=${Date.now()}`

    try
    {
        const { default: fetch } = await import('node-fetch')
        const response = await fetch(target, {
            method: 'GET',
            headers: { 'Cache-Control': 'no-cache' }
        })

        const data = await response.json().catch(() => ({}))
        if (!response.ok || !data?.ok || !Array.isArray(data?.versions))
            return { ok: false, error: data?.error || `HTTP ${response.status}` }

        const versions = data.versions.map((v) => ({
            version: String(v?.version || '').trim(),
            file: String(v?.file || '').trim(),
            url: String(v?.url || '').trim(),
            size_bytes: Number(v?.size_bytes || 0),
            updated_at: String(v?.updated_at || '').trim(),
        })).filter((v) => v.version && v.file && v.url)

        return {
            ok: true,
            latest_version: String(data?.latest_version || '').trim(),
            versions
        }
    }
    catch (err)
    {
        return { ok: false, error: normalize_error(err) }
    }
}

