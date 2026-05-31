import { utilityProcess } from 'electron'
import path from 'path'
import { user_data_dir, storage_dir, config_file, license_file, src_dir } from './paths.js'
import { read_json, get_device_id, clear_all_user_data, update_last_active_time } from './utils.js'
import { license_load } from './api.js'
import { send_to_renderer } from './windowManager.js'
import { check_cookies_valid } from './session.js'
import { buffer_log, flush_log_buffer_now } from './logger.js'
import { set_worker_proc_ref } from './worker_state.js'

let worker_proc = null
let stop_in_progress = false

export function get_worker_proc()
{
    return worker_proc
}

function parse_heartbeat(line)
{
    const get = (key) =>
    {
        const m = line.match(new RegExp(key + '=([^\\s]+)'))
        return m ? m[1] : '—'
    }
    return {
        pending: get('pending_count'),
        running: get('running_count'),
        busy: get('worker_busy'),
        should_run: get('should_run'),
    }
}

function mask_url_for_electron_console(raw_url)
{
    const value = String(raw_url || '').trim()
    if (value === '')
        return '[redacted]'

    try
    {
        const parsed = new URL(value)
        return `${parsed.origin}/[redacted]`
    } catch
    {
        return '[redacted]'
    }
}

function sanitize_sensitive_line_for_electron_console(line)
{
    const text = String(line || '')
    if (text === '')
        return text

    return text.replace(/https?:\/\/[^\s]+/gi, (matched_url) => mask_url_for_electron_console(matched_url))
}

export async function worker_start()
{
    if (worker_proc)
        return { ok: false, error: 'Worker sudah berjalan' }
    if (stop_in_progress)
        return { ok: false, error: 'Worker sedang dihentikan. Coba lagi sebentar.' }

    const verified_license = await license_load()
    if (!verified_license?.license_key)
        return { ok: false, error: 'Lisensi belum tervalidasi. Login ulang dengan internet aktif.' }
    const device_token = String(verified_license?.device_token || '').trim()
    if (!device_token)
        return { ok: false, error: 'Device token belum tersedia. Login ulang lisensi terlebih dahulu.' }

    const session = check_cookies_valid()
    if (!session.valid)
    {
        send_to_renderer('session_status', 'invalid')
        send_to_renderer('worker_log_batch', ['[SYSTEM] Sesi tidak valid. Login manual via tombol Login pada banner sesi.'])
        return { ok: false, error: session.reason || 'Sesi Sehat IndonesiaKu tidak aktif. Login manual diperlukan.' }
    }

    const config = read_json(config_file) || {}
    const headless = config?.browser?.headless !== false

    const entry_path = path.join(src_dir, 'worker_entry.cjs')
    worker_proc = utilityProcess.fork(entry_path, [], {
        env: {
            ...process.env,
            WORKER_MODE: '1',
            HEADLESS: headless ? '1' : '0',
            USER_DATA_DIR: user_data_dir,
            STORAGE_DIR: storage_dir,
            CONFIG_FILE: config_file,
            SRC_DIR: src_dir,
            LICENSE_KEY: verified_license.license_key,
            DEVICE_ID: get_device_id(),
            DEVICE_TOKEN: device_token,
            RUNTIME_CONFIG_SOURCE: 'server',
        },
        cwd: src_dir,
        stdio: 'pipe'
    })
    set_worker_proc_ref(worker_proc)

    let stop_reason = null

    if (worker_proc.stdout)
    {
        worker_proc.stdout.on('data', chunk =>
        {
            chunk.toString().split('\n')
                .filter(line => line.trim())
                .forEach(line =>
                {
                    if (line.includes('license_fatal')) stop_reason = 'revoked'
                    else if (line.includes('quota_fatal')) stop_reason = 'quota'
                    if (line.includes('heartbeat'))
                    {
                        update_last_active_time()
                        send_to_renderer('worker_heartbeat', parse_heartbeat(line))
                    } else
                    {
                        buffer_log(sanitize_sensitive_line_for_electron_console(line))
                    }
                })
        })
    }

    if (worker_proc.stderr)
    {
        worker_proc.stderr.on('data', chunk =>
        {
            chunk.toString().split('\n')
                .filter(line => line.trim())
                .forEach(line => buffer_log('[ERR] ' + sanitize_sensitive_line_for_electron_console(line)))
        })
    }

    worker_proc.on('message', ({ type, data }) =>
    {
        if (type === 'status') send_to_renderer('worker_status', data)
    })

    worker_proc.on('exit', code =>
    {
        flush_log_buffer_now()
        worker_proc = null
        stop_in_progress = false
        set_worker_proc_ref(null)

        if (stop_reason === 'revoked')
        {
            clear_all_user_data()
            send_to_renderer('worker_status', 'stopped')
            send_to_renderer('license_revoked', true)
            send_to_renderer('worker_log_batch', [
                '[SYSTEM] ⛔ License dicabut oleh admin. Silakan hubungi admin untuk informasi lebih lanjut.'
            ])
        } else if (stop_reason === 'quota')
        {
            send_to_renderer('worker_status', 'stopped')
            send_to_renderer('quota_empty', true)
            send_to_renderer('worker_log_batch', [
                '[SYSTEM] ⛔ Kuota NIK habis. Worker berhenti otomatis. Hubungi admin untuk tambah kuota.'
            ])
        } else
        {
            send_to_renderer('worker_status', 'stopped')
            send_to_renderer('worker_log_batch', [`[SYSTEM] Aplikasi berhenti.`])
        }
    })

    send_to_renderer('worker_status', 'running')
    send_to_renderer('worker_log_batch', [`[SYSTEM] Aplikasi dimulai...`])
    return { ok: true }
}

export async function worker_stop()
{
    if (!worker_proc)
    {
        if (stop_in_progress)
            return { ok: true, message: 'Worker sedang proses berhenti' }
        return { ok: false, error: 'Worker tidak berjalan' }
    }
    if (stop_in_progress)
        return { ok: true, message: 'Worker sedang proses berhenti' }

    stop_in_progress = true

    flush_log_buffer_now()

    const proc = worker_proc

    try { proc.send({ type: 'stop' }) } catch { }

    try
    {
        const config = read_json(config_file)
        const lic = read_json(license_file)
        if (config && lic && lic.license_key)
        {
            const base_url = (config.api?.base_url || 'https://rmik.gyatechindonesia.com/api').replace(/\/+$/, '')
            const device_token = String(lic?.device_token || '').trim()
            const { default: fetch } = await import('node-fetch')
            await fetch(`${base_url}/license/set_running.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-License-Key': lic.license_key,
                    'X-Device-Id': get_device_id(),
                    'X-Device-Token': device_token,
                },
                body: new URLSearchParams({ running: '0' }),
            }).catch(() => { })
        }
    } catch { }

    try { proc.kill('SIGTERM') } catch { }
    setTimeout(() =>
    {
        if (!worker_proc)
            return
        try { proc.kill('SIGKILL') } catch { }
    }, 1500)

    send_to_renderer('worker_log_batch', ['[SYSTEM] Perintah stop dikirim. Menunggu proses berhenti...'])
    return { ok: true, stopping: true }
}


