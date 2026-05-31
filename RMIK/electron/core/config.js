import path from 'path'
import { config_file, license_file, storage_dir } from './paths.js'
import { read_json, write_json, get_device_id, ensure_dir } from './utils.js'

const profile_cache_file = path.join(storage_dir, 'profile_cache.json')

const profile_defaults = {
    account_email: '',
    account_password: '',
    wali_nik: '',
    wali_nama: '',
    wali_no_hp: '',
    wali_tanggal_lahir: '',
    wali_jenis_kelamin: 'Perempuan',
    wali_instansi_puskesmas: '',
}

function get_base_url()
{
    const config = read_json(config_file) || {}
    return (config?.api?.base_url || 'https://rmik.gyatechindonesia.com/api').replace(/\/+$/, '')
}

function get_license_key()
{
    return read_json(license_file)?.license_key || ''
}

function get_device_token()
{
    return String(read_json(license_file)?.device_token || '').trim()
}

function normalize_profile(profile)
{
    const raw = profile || {}
    return {
        account_email: String(raw.account_email || '').trim(),
        account_password: String(raw.account_password || '').trim(),
        wali_nik: String(raw.wali_nik || '').trim(),
        wali_nama: String(raw.wali_nama || '').trim(),
        wali_no_hp: String(raw.wali_no_hp || '').trim(),
        wali_tanggal_lahir: String(raw.wali_tanggal_lahir || '').trim(),
        wali_jenis_kelamin: String(raw.wali_jenis_kelamin || 'Perempuan').trim() || 'Perempuan',
        wali_instansi_puskesmas: String(raw.wali_instansi_puskesmas || '').trim(),
    }
}

function to_boolean_value(value, fallback = false)
{
    if (typeof value === 'boolean')
        return value

    const text = String(value ?? '').trim().toLowerCase()
    if (text === '')
        return fallback
    if (text === '1' || text === 'true' || text === 'yes' || text === 'on')
        return true
    if (text === '0' || text === 'false' || text === 'no' || text === 'off')
        return false
    return fallback
}

function to_integer_value(value, fallback = 0)
{
    const numeric_value = Number(value)
    if (!Number.isFinite(numeric_value))
        return fallback
    return Math.trunc(numeric_value)
}

function has_profile_data(profile)
{
    const data = normalize_profile(profile)
    return (
        data.account_email !== '' ||
        data.account_password !== '' ||
        data.wali_nik !== '' ||
        data.wali_nama !== '' ||
        data.wali_no_hp !== '' ||
        data.wali_tanggal_lahir !== '' ||
        data.wali_instansi_puskesmas !== ''
    )
}

function read_cached_profile()
{
    const cache = read_json(profile_cache_file)
    if (!cache || typeof cache !== 'object') return null
    const current_license_key = String(get_license_key() || '').trim()
    const cached_license_key = String(cache.cache_license_key || '').trim()
    if (current_license_key && cached_license_key && current_license_key !== cached_license_key)
        return null
    return normalize_profile(cache)
}

function write_cached_profile(profile)
{
    ensure_dir(storage_dir)
    const current_license_key = String(get_license_key() || '').trim()
    const payload = {
        ...normalize_profile(profile),
        cache_license_key: current_license_key,
        updated_at: new Date().toISOString(),
    }
    write_json(profile_cache_file, payload)
}

function read_legacy_profile_from_config()
{
    const config = read_json(config_file) || {}
    const wali_data = config?.config?.data_wali || {}
    return normalize_profile({
        account_email: config?.credentials?.email || '',
        account_password: config?.credentials?.password || '',
        wali_nik: wali_data.nik || '',
        wali_nama: wali_data.nama || '',
        wali_no_hp: wali_data.no_hp || '',
        wali_tanggal_lahir: wali_data.tanggal_lahir || '',
        wali_jenis_kelamin: wali_data.jenis_kelamin || 'Perempuan',
        wali_instansi_puskesmas: wali_data.instansi_puskesmas || '',
    })
}

async function ensure_device_token()
{
    const current_token = get_device_token()
    if (current_token)
        return { ok: true, token: current_token }

    try
    {
        const { license_load } = await import('./api.js')
        await license_load()
    } catch
    {
    }

    const refreshed_token = get_device_token()
    if (refreshed_token)
        return { ok: true, token: refreshed_token }

    return { ok: false, error: 'Device token kosong. Login ulang lisensi.' }
}

async function refresh_license_data()
{
    try
    {
        const { license_load } = await import('./api.js')
        await license_load()
    } catch
    {
    }
}

async function request_profile(method, payload, allow_retry = true)
{
    const license_key = get_license_key()
    if (!license_key)
        return { ok: false, error: 'Belum login lisensi' }

    const device_token_result = await ensure_device_token()
    if (!device_token_result.ok)
        return { ok: false, error: device_token_result.error }

    const { default: fetch } = await import('node-fetch')
    const headers = {
        'X-License-Key': license_key,
        'X-Device-Id': get_device_id(),
        'X-Device-Token': device_token_result.token,
    }
    const options = {
        method,
        headers,
    }
    if (method === 'POST')
    {
        options.headers['Content-Type'] = 'application/x-www-form-urlencoded'
        options.body = new URLSearchParams(payload || {})
    }

    const response = await fetch(`${get_base_url()}/license/profile.php`, options)
    const data = await response.json().catch(() => ({}))
    if (!response.ok || !data?.ok)
    {
        const error_code = String(data?.code || '').trim().toUpperCase()
        if (allow_retry && (error_code === 'DEVICE_TOKEN_INVALID' || error_code === 'DEVICE_TOKEN_REQUIRED'))
        {
            await refresh_license_data()
            return await request_profile(method, payload, false)
        }
        return { ok: false, error: data?.error || `HTTP ${response.status}` }
    }

    return { ok: true, data }
}

function purge_local_sensitive_profile()
{
    const config = read_json(config_file)
    if (!config) return

    let changed = false

    const email = config?.credentials?.email || ''
    const password = config?.credentials?.password || ''
    if (email || password)
    {
        config.credentials = { email: '', password: '' }
        changed = true
    }

    const wali = config?.config?.data_wali || {}
    if (
        wali.nik ||
        wali.nama ||
        wali.no_hp ||
        wali.tanggal_lahir ||
        wali.instansi_puskesmas
    )
    {
        if (!config.config) config.config = {}
        config.config.data_wali = {
            nik: '',
            nama: '',
            no_hp: '',
            tanggal_lahir: '',
            jenis_kelamin: 'Perempuan',
            instansi_puskesmas: '',
        }
        changed = true
    }

    if (changed) write_json(config_file, config)
}

async function remote_profile_load()
{
    try
    {
        const result = await request_profile('GET')
        if (!result.ok)
            return { ok: false, error: result.error }
        return { ok: true, data: normalize_profile(result.data?.data || profile_defaults) }
    } catch (error)
    {
        return { ok: false, error: error?.message || 'Gagal memuat profile server' }
    }
}

async function remote_profile_save(profile)
{
    try
    {
        const payload = normalize_profile({ ...profile_defaults, ...(profile || {}) })
        const result = await request_profile('POST', payload)
        if (!result.ok)
            return { ok: false, error: result.error }

        const saved_profile = normalize_profile(result.data?.data || payload)
        write_cached_profile(saved_profile)
        purge_local_sensitive_profile()
        return { ok: true, data: saved_profile }
    } catch (error)
    {
        return { ok: false, error: error?.message || 'Gagal menyimpan profile server' }
    }
}

async function migrate_legacy_profile_to_server()
{
    const legacy_profile = read_legacy_profile_from_config()
    if (!has_profile_data(legacy_profile))
        return

    const remote_profile = await remote_profile_load()
    if (!remote_profile.ok)
        return

    if (has_profile_data(remote_profile.data))
    {
        write_cached_profile(remote_profile.data)
        purge_local_sensitive_profile()
        return
    }

    const migrated = await remote_profile_save(legacy_profile)
    if (!migrated.ok)
        write_cached_profile(legacy_profile)
}

async function load_effective_profile()
{
    await migrate_legacy_profile_to_server()

    const remote_profile = await remote_profile_load()
    if (remote_profile.ok)
    {
        write_cached_profile(remote_profile.data)
        purge_local_sensitive_profile()
        return remote_profile.data
    }

    const cached_profile = read_cached_profile()
    if (cached_profile)
        return cached_profile

    const legacy_profile = read_legacy_profile_from_config()
    if (has_profile_data(legacy_profile))
        return legacy_profile

    return { ...profile_defaults }
}

function load_profile_for_settings()
{
    const cached_profile = read_cached_profile()
    if (cached_profile && has_profile_data(cached_profile))
        return cached_profile

    const legacy_profile = read_legacy_profile_from_config()
    if (legacy_profile && has_profile_data(legacy_profile))
        return legacy_profile

    return { ...profile_defaults }
}

function is_wali_profile_changed(current_profile, next_profile)
{
    const current_value = normalize_profile(current_profile || {})
    const next_value = normalize_profile(next_profile || {})
    return (
        current_value.wali_nik !== next_value.wali_nik ||
        current_value.wali_nama !== next_value.wali_nama ||
        current_value.wali_no_hp !== next_value.wali_no_hp ||
        current_value.wali_tanggal_lahir !== next_value.wali_tanggal_lahir ||
        current_value.wali_jenis_kelamin !== next_value.wali_jenis_kelamin ||
        current_value.wali_instansi_puskesmas !== next_value.wali_instansi_puskesmas
    )
}

function sync_profile_background(next_profile)
{
    Promise.resolve()
        .then(async () =>
        {
            await remote_profile_save(next_profile)
        })
        .catch(() => { })
}

async function save_profile_patch(patch)
{
    const remote_profile = await remote_profile_load()
    const cached_profile = read_cached_profile()
    const legacy_profile = read_legacy_profile_from_config()

    let base_profile = remote_profile.ok ? remote_profile.data : null
    if (!base_profile || !has_profile_data(base_profile))
        base_profile = cached_profile
    if (!base_profile || !has_profile_data(base_profile))
        base_profile = legacy_profile
    if (!base_profile)
        base_profile = { ...profile_defaults }

    const next_profile = normalize_profile({ ...base_profile, ...(patch || {}) })
    const result = await remote_profile_save(next_profile)
    if (result.ok)
        return result

    write_cached_profile(next_profile)
    return result
}

export async function account_load()
{
    const profile = await load_effective_profile()
    return {
        email: profile.account_email || '',
        password: profile.account_password || '',
    }
}

export async function account_save(credentials)
{
    const result = await save_profile_patch({
        account_email: String(credentials?.email || '').trim(),
        account_password: String(credentials?.password || '').trim(),
    })
    return result.ok ? { ok: true } : { ok: false, error: result.error || 'Gagal menyimpan akun' }
}

export async function config_advanced_load()
{
    try
    {
        const config = read_json(config_file) || {}
        const profile = load_profile_for_settings()

        return {
            pause_between_users_ms: config.config?.pause_between_users_ms ?? 650,
            browser_engine: config.browser?.engine ?? 'system',
            browser_channel: config.browser?.channel ?? 'msedge',
            stop_on_error: config.config?.stop_on_error ?? false,
            headless: config.browser?.headless ?? true,
            slow_mo_ms: config.browser?.slow_mo_ms ?? 0,
            save_artifacts: config.debug?.save_artifacts ?? false,
            pemeriksaan_mandiri_recheck_completed: to_boolean_value(config.meta?.pemeriksaan_mandiri_recheck_completed, true),
            pemeriksaan_mandiri_only_index: to_integer_value(config.meta?.pemeriksaan_mandiri_only_index, 0),
            pemeriksaan_mandiri_refill_answered: to_boolean_value(config.meta?.pemeriksaan_mandiri_refill_answered, false),
            pemeriksaan_mandiri_auto_submit: to_boolean_value(config.meta?.pemeriksaan_mandiri_auto_submit, true),
            session_auto_relogin: to_boolean_value(config.meta?.session_auto_relogin, true),
            wali_nik: profile.wali_nik || '',
            wali_nama: profile.wali_nama || '',
            wali_no_hp: profile.wali_no_hp || '',
            wali_tgl: profile.wali_tanggal_lahir || '',
            wali_jk: profile.wali_jenis_kelamin || 'Perempuan',
            wali_instansi_puskesmas: profile.wali_instansi_puskesmas || '',
        }
    } catch
    {
        return null
    }
}

export async function config_advanced_save(act)
{
    try
    {
        const config = read_json(config_file) || {}
        if (!config.config) config.config = {}
        if (!config.browser) config.browser = {}
        if (!config.debug) config.debug = {}
        if (!config.meta) config.meta = {}

        config.config.pause_between_users_ms = Number(act.pause_between_users_ms) || 650
        config.browser.engine = String(act.browser_engine || 'system').trim() || 'system'
        config.browser.channel = String(act.browser_channel || 'msedge').trim() || 'msedge'
        config.config.stop_on_error = act.stop_on_error === true || act.stop_on_error === 'true'
        config.browser.headless = act.headless === true || act.headless === 'true'
        config.browser.slow_mo_ms = Number(act.slow_mo_ms) || 0
        if (Object.prototype.hasOwnProperty.call(act || {}, 'save_artifacts'))
            config.debug.save_artifacts = act.save_artifacts === true || act.save_artifacts === 'true'

        config.meta.pemeriksaan_mandiri_recheck_completed = to_boolean_value(act.pemeriksaan_mandiri_recheck_completed, true)
        config.meta.pemeriksaan_mandiri_only_index = to_integer_value(act.pemeriksaan_mandiri_only_index, 0)
        config.meta.pemeriksaan_mandiri_refill_answered = to_boolean_value(act.pemeriksaan_mandiri_refill_answered, false)
        config.meta.pemeriksaan_mandiri_auto_submit = to_boolean_value(act.pemeriksaan_mandiri_auto_submit, true)
        config.meta.session_auto_relogin = to_boolean_value(act.session_auto_relogin, true)
        write_json(config_file, config)

        const current_profile = load_profile_for_settings()
        const next_profile_patch = {
            wali_nik: String(act.wali_nik || '').trim(),
            wali_nama: String(act.wali_nama || '').trim(),
            wali_no_hp: String(act.wali_no_hp || '').trim(),
            wali_tanggal_lahir: String(act.wali_tgl || '').trim(),
            wali_jenis_kelamin: String(act.wali_jk || 'Perempuan').trim() || 'Perempuan',
            wali_instansi_puskesmas: String(act.wali_instansi_puskesmas || '').trim(),
        }
        const next_profile = normalize_profile({ ...current_profile, ...next_profile_patch })
        if (!is_wali_profile_changed(current_profile, next_profile))
            return { ok: true }

        write_cached_profile(next_profile)
        sync_profile_background(next_profile)
        return { ok: true }
    } catch (err)
    {
        return { ok: false, error: err.message }
    }
}
