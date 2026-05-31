import fs from 'fs'
import os from 'os'
import crypto from 'crypto'
import path from 'path'
import { user_data_dir, storage_dir, config_file, license_file } from './paths.js'

export function ensure_dir(dir_path)
{
    if (!fs.existsSync(dir_path))
        fs.mkdirSync(dir_path, { recursive: true })
}

export function read_json(file_path)
{
    if (!fs.existsSync(file_path)) return null
    try
    {
        return JSON.parse(fs.readFileSync(file_path, 'utf-8'))
    } catch
    {
        return null
    }
}

export function write_json(file_path, data)
{
    fs.writeFileSync(file_path, JSON.stringify(data, null, 4), 'utf-8')
}

export function get_device_id()
{
    const env_device_id = String(process.env.DEVICE_ID || '').trim()
    if (env_device_id) return env_device_id

    const license_data = read_json(license_file)
    const stored_device_id = String(license_data?.device_id || '').trim()
    if (stored_device_id) return stored_device_id

    const parts = [
        os.hostname(),
        os.cpus()[0]?.model || 'unknown',
        os.platform(),
        os.arch(),
    ]
    return crypto.createHash('sha256').update(parts.join('||')).digest('hex')
}

export const IDLE_TIMEOUT_MS = 2 * 60 * 60 * 1000

export function update_last_active_time()
{
    try
    {
        const license_data = read_json(license_file)
        if (license_data)
        {
            license_data.last_active_time = Date.now()
            write_json(license_file, license_data)
        }
    } catch { }
}

export function check_idle_timeout()
{
    try
    {
        const license_data = read_json(license_file)
        if (license_data && license_data.last_active_time)
        {
            const idle_time = Date.now() - license_data.last_active_time
            if (idle_time >= IDLE_TIMEOUT_MS)
            {
                return true
            }
        }
        return false
    } catch
    {
        return false
    }
}

export function clear_all_user_data()
{
    try
    {
        const files = [
            license_file,
            path.join(storage_dir, 'cookies.json'),
            path.join(storage_dir, 'profile_cache.json'),
            path.join(storage_dir, 'results.json'),
            path.join(user_data_dir, 'results.json'),
            path.join(process.cwd(), 'results.json')
        ]
        files.forEach(f => { try { if (fs.existsSync(f)) fs.unlinkSync(f) } catch { } })

        const config = read_json(config_file)
        if (config)
        {
            config.credentials = { email: '', password: '' }
            if (!config.browser) config.browser = {}
            if (!config.debug) config.debug = {}

            // Reset ke default produksi supaya run berikutnya tetap background cepat.
            config.browser.engine = 'system'
            config.browser.channel = 'msedge'
            config.browser.headless = true
            config.browser.slow_mo_ms = 0
            config.debug.save_artifacts = false

            if (config.config && config.config.data_wali)
            {
                config.config.data_wali = {
                    nik: '',
                    nama: '',
                    no_hp: '',
                    tanggal_lahir: '',
                    jenis_kelamin: 'Perempuan',
                    instansi_puskesmas: ''
                }
            }
            write_json(config_file, config)
        }
    } catch { }
}
