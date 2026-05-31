import path from "path"
import fs from "fs"
import { fileURLToPath } from "url"
import { read_json, ensure_dir, log, save_debug_artifacts, get_device_id } from "./core/helpers.js"
import { init_browser, close_browser } from "./core/browser.js"
import { ensure_authenticated } from "./core/auth.js"
process.env.TZ = "Asia/Jakarta";

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

function resolve_runtime_config_source()
{
    const mode = String(process.env.RUNTIME_CONFIG_SOURCE || "auto").trim().toLowerCase()
    if (mode === "dotenv" || mode === "server") return mode
    return process.env.CONFIG_FILE ? "server" : "dotenv"
}

const runtime_config_source = resolve_runtime_config_source()
const is_dev = runtime_config_source === "dotenv"

if (is_dev)
{
    const dotenv = await import("dotenv")
    dotenv.config()
}

const config_file_from_env = String(process.env.CONFIG_FILE || "").trim()
const root_dir = is_dev
    ? path.resolve(__dirname, "..")
    : path.dirname(config_file_from_env || path.resolve(__dirname, "..", "config.json"))

const storage_dir = process.env.STORAGE_DIR || path.join(root_dir, "storage")
const config_file = config_file_from_env || path.join(root_dir, "config.json")
const profile_cache_file = path.join(storage_dir, "profile_cache.json")
process.env.STORAGE_DIR = storage_dir

const IS_AGENT = process.env.WORKER_MODE === "1"
const SHOW_UI = process.env.SHOW_UI === "1"
const PROFILE_CACHE_TTL_MS = 30000
let profile_cache_key = ""
let profile_cache_at = 0
let profile_cache_value = null
let session_lock_path = ""

function sanitize_lock_name(value)
{
    return String(value || "")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/-+/g, "-")
        .replace(/^-|-$/g, "")
}

function is_process_alive(pid)
{
    const numeric_pid = Number(pid)
    if (!Number.isFinite(numeric_pid) || numeric_pid <= 0)
        return false

    try
    {
        process.kill(numeric_pid, 0)
        return true
    } catch
    {
        return false
    }
}

function resolve_session_lock_path(base_storage_dir, license_key)
{
    const lock_name = sanitize_lock_name(license_key) || "default"
    return path.join(base_storage_dir, `session_${lock_name}.lock`)
}

function acquire_session_lock(lock_path)
{
    const lock_exists = fs.existsSync(lock_path)
    if (lock_exists)
    {
        try
        {
            const lock_raw = fs.readFileSync(lock_path, "utf-8")
            const lock_data = JSON.parse(lock_raw || "{}")
            const lock_pid = Number(lock_data?.pid || 0)
            if (is_process_alive(lock_pid))
                throw new Error(`SESSION_LOCKED pid=${lock_pid}`)
        } catch (error)
        {
            if (String(error?.message || "").startsWith("SESSION_LOCKED"))
                throw error
        }

        try { fs.unlinkSync(lock_path) } catch { }
    }

    const payload = {
        pid: process.pid,
        created_at: new Date().toISOString()
    }
    fs.writeFileSync(lock_path, JSON.stringify(payload) + "\n", "utf-8")
    session_lock_path = lock_path
}

function release_session_lock()
{
    const target_path = String(session_lock_path || "").trim()
    if (target_path === "")
        return
    try
    {
        if (fs.existsSync(target_path))
            fs.unlinkSync(target_path)
    } catch { }
    session_lock_path = ""
}

function parse_bool(value)
{
    const text = String(value || "").trim().toLowerCase()
    return text === "1" || text === "true" || text === "yes" || text === "y"
}

function resolve_headless(config)
{
    if (process.env.HEADLESS === "1") return true
    if (process.env.HEADLESS === "0") return false
    if (SHOW_UI) return false
    return config?.browser?.headless ?? true
}

function apply_dev_override(config)
{
    if (!is_dev) return config

    if (!config.credentials) config.credentials = {}
    if (!config.api) config.api = {}
    if (!config.config) config.config = {}
    if (!config.browser) config.browser = {}
    if (!config.debug) config.debug = {}
    if (!config.config.data_wali) config.config.data_wali = {}

    const dev_instansi_puskesmas =
        process.env.DEV_WALI_INSTANSI_PUSKESMAS ||
        process.env.DEV_INSTANSI_PUSKESMAS ||
        ""

    config.credentials.email = process.env.DEV_EMAIL || ""
    config.credentials.password = process.env.DEV_PASSWORD || ""

    config.api.base_url = process.env.DEV_API_BASE_URL || config.api.base_url
    config.api.license_key = process.env.DEV_LICENSE_KEY || ""
    config.api.device_token = process.env.DEV_DEVICE_TOKEN || config.api.device_token || ""

    config.config.pause_between_users_ms = Number(process.env.DEV_PAUSE_BETWEEN_USERS_MS) || 500
    config.config.stop_on_error = process.env.DEV_STOP_ON_ERROR === "true"
    config.config.start_index = Number(process.env.DEV_START_INDEX) || 0
    config.config.user_timeout_ms = Number(process.env.DEV_USER_TIMEOUT_MS) || config.config.user_timeout_ms || 45000

    config.config.data_wali = {
        nik: process.env.DEV_WALI_NIK || "",
        nama: process.env.DEV_WALI_NAMA || "",
        tanggal_lahir: process.env.DEV_WALI_TANGGAL_LAHIR || "",
        jenis_kelamin: process.env.DEV_WALI_JENIS_KELAMIN || "",
        no_hp:
            process.env.DEV_WALI_NO_HP ||
            process.env.DEV_WALI_NOMOR_WHATSAPP ||
            "",
        instansi_puskesmas: dev_instansi_puskesmas
    }
    config.config.instansi_puskesmas = dev_instansi_puskesmas || config.config.instansi_puskesmas || ""

    config.browser.headless = process.env.DEV_HEADLESS !== "false"
    config.browser.slow_mo_ms = Number(process.env.DEV_SLOW_MO_MS) || 0
    config.browser.timeout_ms = Number(process.env.DEV_TIMEOUT_MS) || 120000
    config.browser.engine = process.env.DEV_BROWSER_ENGINE || config.browser.engine || "auto"
    config.browser.channel = process.env.DEV_BROWSER_CHANNEL || config.browser.channel || "msedge"
    const persistent_profile_env = String(process.env.DEV_BROWSER_PERSISTENT_PROFILE || "").trim().toLowerCase()
    const runtime_mode = String(process.env.DEV_MODE || config?.meta?.mode || "").trim().toLowerCase()
    const runtime_action = String(process.env.DEV_TYPE || config?.meta?.action || "").trim().toLowerCase()
    const default_persistent_profile = runtime_mode === "umum" && runtime_action === "pelayanan"
    if (persistent_profile_env === "true")
        config.browser.persistent_profile = true
    else if (persistent_profile_env === "false")
        config.browser.persistent_profile = false
    else
        config.browser.persistent_profile = default_persistent_profile
    config.browser.profile_dir = process.env.DEV_BROWSER_PROFILE_DIR || config.browser.profile_dir || ""

    config.debug.save_artifacts = process.env.DEV_SAVE_ARTIFACTS === "true"

    if (!config.meta) config.meta = {}
    config.meta.mode = process.env.DEV_MODE || "umum"
    config.meta.action = process.env.DEV_TYPE || "pendaftaran"
    config.meta.search_nik = process.env.DEV_SEARCH_NIK || config.meta.search_nik || ""
    config.meta.pemeriksaan_mandiri_file = process.env.DEV_PEMERIKSAAN_MANDIRI_FILE || config.meta.pemeriksaan_mandiri_file || ""
    config.meta.pemeriksaan_mandiri_recheck_completed = parse_bool(process.env.DEV_PEMERIKSAAN_MANDIRI_RECHECK_COMPLETED || config.meta.pemeriksaan_mandiri_recheck_completed || "true")
    config.meta.pemeriksaan_mandiri_only_index = Number(process.env.DEV_PEMERIKSAAN_MANDIRI_ONLY_INDEX || config.meta.pemeriksaan_mandiri_only_index || 0) || 0
    config.meta.pemeriksaan_mandiri_refill_answered = parse_bool(process.env.DEV_PEMERIKSAAN_MANDIRI_REFILL_ANSWERED || config.meta.pemeriksaan_mandiri_refill_answered || "false")
    config.meta.pemeriksaan_mandiri_auto_submit = parse_bool(process.env.DEV_PEMERIKSAAN_MANDIRI_AUTO_SUBMIT || config.meta.pemeriksaan_mandiri_auto_submit || "true")
    config.meta.session_auto_relogin = parse_bool(process.env.DEV_SESSION_AUTO_RELOGIN || config.meta.session_auto_relogin || "false")

    return config
}

function get_runtime_device_id()
{
    return get_device_id()
}

function get_runtime_device_token(config)
{
    const token = process.env.DEVICE_TOKEN || config?.api?.device_token || ""
    return String(token).trim()
}

function get_runtime_license_key(config)
{
    const key = process.env.LICENSE_KEY || config?.api?.license_key || ""
    return String(key).trim()
}

function should_keep_browser_open(config)
{
    if (parse_bool(process.env.DEV_KEEP_BROWSER_OPEN))
        return true

    const mode = String(config?.meta?.mode || "").trim().toLowerCase()
    const action = String(config?.meta?.action || "").trim().toLowerCase()
    return mode === "umum" && action === "pelayanan"
}

async function hold_browser_for_manual_control(page)
{
    const keep_open_ms = Number(process.env.DEV_KEEP_BROWSER_OPEN_MS || 0)
    if (Number.isFinite(keep_open_ms) && keep_open_ms > 0)
    {
        await page.waitForTimeout(keep_open_ms).catch(() => { })
        return
    }

    while (true)
    {
        if (page.isClosed()) return
        await page.waitForTimeout(1000).catch(() => { })
    }
}

async function ensure_authenticated_with_fallback(config, cookies_file)
{
    let runtime = await init_browser(config, cookies_file)
    let page = runtime.page
    let context = runtime.context

    try
    {
        await ensure_authenticated(page, context, config, cookies_file)
        return { runtime, page, context }
    } catch (e)
    {
        if (e?.code !== "HEADLESS_RELOGIN_REQUIRES_BROWSER" || !config.browser.headless)
            throw e

        log("WARN", "headless_login_required_opening_browser")
        await close_browser()

        runtime = await init_browser(config, cookies_file, false)
        page = runtime.page
        context = runtime.context

        const interactive_config = {
            ...config,
            browser: { ...config.browser, headless: false }
        }

        await ensure_authenticated(page, context, interactive_config, cookies_file)

        log("INFO", "manual_login_done_relaunch_headless")
        await close_browser()

        runtime = await init_browser(config, cookies_file, true)
        page = runtime.page
        context = runtime.context
        await ensure_authenticated(page, context, config, cookies_file)
        return { runtime, page, context }
    }
}

function normalize_profile_data(data)
{
    return {
        account_email: String(data?.account_email || "").trim(),
        account_password: String(data?.account_password || "").trim(),
        wali_nik: String(data?.wali_nik || "").trim(),
        wali_nama: String(data?.wali_nama || "").trim(),
        wali_no_hp: String(data?.wali_no_hp || "").trim(),
        wali_tanggal_lahir: String(data?.wali_tanggal_lahir || "").trim(),
        wali_jenis_kelamin: String(data?.wali_jenis_kelamin || "Perempuan").trim() || "Perempuan",
        wali_instansi_puskesmas: String(data?.wali_instansi_puskesmas || "").trim(),
    }
}

function read_cached_profile_data(license_key)
{
    if (!fs.existsSync(profile_cache_file))
        return null

    const cache = read_json(profile_cache_file)
    if (!cache || typeof cache !== "object") return null

    const cache_key = String(cache.cache_license_key || "").trim()
    const target_key = String(license_key || "").trim()
    if (target_key && cache_key && cache_key !== target_key)
        return null

    return normalize_profile_data(cache)
}

function write_cached_profile_data(license_key, profile_data)
{
    const payload = {
        ...normalize_profile_data(profile_data),
        cache_license_key: String(license_key || "").trim(),
        updated_at: new Date().toISOString(),
    }
    ensure_dir(path.dirname(profile_cache_file))
    fs.writeFileSync(profile_cache_file, JSON.stringify(payload, null, 2) + "\n", "utf-8")
}

async function fetch_license_profile(config)
{
    const base_url = (config?.api?.base_url || "https://rmik.gyatechindonesia.com/api").replace(/\/+$/, "")
    const license_key = get_runtime_license_key(config)
    const device_id = get_runtime_device_id()
    const device_token = get_runtime_device_token(config)

    if (!license_key || !base_url || !device_token)
        return read_cached_profile_data(license_key)

    const cache_key = `${base_url}|${license_key}|${device_id}`
    if (
        profile_cache_value &&
        profile_cache_key === cache_key &&
        (Date.now() - profile_cache_at) < PROFILE_CACHE_TTL_MS
    )
    {
        return profile_cache_value
    }

    try
    {
        const { default: fetch } = await import("node-fetch")
        const res = await fetch(`${base_url}/license/profile.php`, {
            method: "GET",
            headers: {
                "X-License-Key": license_key,
                "X-Device-Id": device_id,
                "X-Device-Token": device_token,
            }
        })

        const payload = await res.json().catch(() => ({}))
        if (!res.ok || !payload?.ok)
            return read_cached_profile_data(license_key)

        const data = payload?.data || {}
        const normalized = normalize_profile_data(data)

        profile_cache_key = cache_key
        profile_cache_at = Date.now()
        profile_cache_value = normalized
        write_cached_profile_data(license_key, normalized)
        return normalized
    } catch
    {
        return read_cached_profile_data(license_key)
    }
}

async function apply_server_profile(config)
{
    if (is_dev) return config

    const profile = await fetch_license_profile(config)
    if (!profile) return config

    if (!config.credentials) config.credentials = {}
    if (!config.config) config.config = {}
    if (!config.config.data_wali) config.config.data_wali = {}

    config.credentials.email = profile.account_email
    config.credentials.password = profile.account_password
    config.config.data_wali = {
        nik: profile.wali_nik,
        nama: profile.wali_nama,
        no_hp: profile.wali_no_hp,
        tanggal_lahir: profile.wali_tanggal_lahir,
        jenis_kelamin: profile.wali_jenis_kelamin,
        instansi_puskesmas: profile.wali_instansi_puskesmas,
    }

    if (profile.wali_instansi_puskesmas)
        config.config.instansi_puskesmas = profile.wali_instansi_puskesmas

    return config
}

export async function main(page, overrides = {})
{
    const config = apply_dev_override(read_json(config_file))
    await apply_server_profile(config)

    if (!config) throw new Error("config.json tidak ditemukan: " + config_file)
    if (!config.meta) config.meta = {}

    if (overrides.mode) config.meta.mode = overrides.mode
    if (overrides.task_type) config.meta.action = overrides.task_type

    const mode = config.meta?.mode || "umum"
    const action = config.meta?.action || "pendaftaran"

    log("INFO", "app_start", { mode, action })

    if (mode === "umum")
    {
        const { run_ckg_umum } = await import("./ckg-umum/app.js")
        await run_ckg_umum(page, action, config)
    } else if (mode === "sekolah")
    {
        const { run_ckg_sekolah } = await import("./ckg-sekolah/index.js")
        await run_ckg_sekolah(page, action, config)
    } else
        throw new Error(`Mode tidak valid: ${mode}`)

    log("INFO", "task_completed_successfully")
}

const is_main = process.argv[1] === fileURLToPath(import.meta.url)

async function run_entrypoint()
{
    if (!is_main) return

    const config = apply_dev_override(read_json(config_file))
    await apply_server_profile(config)

    if (!config) throw new Error("config.json tidak ditemukan: " + config_file)
    if (!config.meta) config.meta = {}
    if (!config.browser) config.browser = {}

    const cookies_file = path.join(storage_dir, "cookies.json")
    const debug_dir = is_dev
        ? path.join(root_dir, "artifacts", "debug")
        : path.join(storage_dir, "debug")

    config.browser.headless = resolve_headless(config)

    ensure_dir(path.dirname(cookies_file))
    ensure_dir(debug_dir)

    const runtime_license_key = get_runtime_license_key(config)
    const lock_file_path = resolve_session_lock_path(storage_dir, runtime_license_key)
    try
    {
        acquire_session_lock(lock_file_path)
        process.on("exit", release_session_lock)
        process.on("SIGINT", () =>
        {
            release_session_lock()
            process.exit(130)
        })
        process.on("SIGTERM", () =>
        {
            release_session_lock()
            process.exit(143)
        })
    } catch (error)
    {
        const lock_message = String(error?.message || error || "")
        if (lock_message.startsWith("SESSION_LOCKED"))
        {
            log("ERROR", "session_lock_active", { lock: lock_file_path, detail: lock_message })
            process.exit(1)
        }
        throw error
    }

    log("INFO", "entrypoint", {
        mode: IS_AGENT ? "agent" : "local",
        headless: config.browser.headless,
    })

    if (IS_AGENT)
    {
        let runtime = null
        let page = null
        try
        {
            runtime = await ensure_authenticated_with_fallback(config, cookies_file)
            page = runtime.page

            log("INFO", "session_ready_starting_worker_loop")
            const { start_worker_loop } = await import("./api/worker.js")
            await start_worker_loop(page, config)
        } catch (e)
        {
            log("ERROR", "fatal_error", { error: e?.message || String(e) })
            await save_debug_artifacts(page, debug_dir, "fatal").catch(() => { })
            await close_browser()
            release_session_lock()
            process.exit(1)
        }
    } else
    {
        const runtime = await ensure_authenticated_with_fallback(config, cookies_file)
        const page = runtime.page
        const keep_browser_open = should_keep_browser_open(config)

        try
        {
            log("INFO", "authentication_success")
            await main(page)
            log("INFO", "task_completed_successfully")

            if (keep_browser_open)
            {
                log("INFO", "browser_keep_open_active", { url: page.url() })
                await hold_browser_for_manual_control(page)
            }
        } catch (e)
        {
            log("ERROR", "run_failed", { error: e?.message || String(e) })
            await save_debug_artifacts(page, debug_dir, "fatal").catch(() => { })
            throw e
        } finally
        {
            await close_browser()
            release_session_lock()
        }
    }
}

run_entrypoint()
