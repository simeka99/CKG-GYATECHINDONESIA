import path from "path"
import { fileURLToPath } from "url"
import { log } from "../core/helpers.js"
import { ensure_session_active } from "../core/auth.js"
import { api_heartbeat } from "./client.js"
import { worker_state } from "./state.js"
import { main } from "../index.js"

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const MAX_RUN_MS = 10 * 60 * 1000
const POLL_WHEN_IDLE_MS = 900
const POLL_WHEN_BUSY_MS = 700
const POLL_WHEN_PAUSED_MS = 1200
const POLL_WHEN_ERROR_MS = 1500

let is_worker_running = false
let hb_in_flight = false
let last_session_guard_at = 0

function sleep(ms)
{
    return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)))
}

function resolve_cookies_file()
{
    const storage_dir = String(process.env.STORAGE_DIR || "").trim()
    if (storage_dir === "")
        return ""
    return path.join(storage_dir, "cookies.json")
}

async function has_session_error_modal(page)
{
    return await page.evaluate(() =>
    {
        const nodes = Array.from(document.querySelectorAll("div, span, h1, h2, h3"))
        return nodes.some((node) => /silahkan lakukan login ulang/i.test(String(node.textContent || "")))
    }).catch(() => false)
}

async function should_run_session_guard(page)
{
    const url = String(page?.url?.() || "")
    if (url.includes("/auth/login"))
        return true

    return await has_session_error_modal(page)
}

async function run_session_guard_if_needed(page, config)
{
    const now = Date.now()
    if ((now - last_session_guard_at) < 2500)
        return false

    last_session_guard_at = now
    const should_guard = await should_run_session_guard(page)
    if (!should_guard)
        return false

    const cookies_file = resolve_cookies_file()
    await ensure_session_active(page, page.context(), config, cookies_file)
    return true
}

export async function start_worker_loop(page, config)
{
    log("INFO", "worker_loop_started")

    return new Promise((_, reject) =>
    {
        let stopped = false
        const stop_with_error = (err) =>
        {
            if (stopped) return
            stopped = true
            reject(err)
        }

        ;(async () =>
        {
            while (!stopped)
            {
                if (hb_in_flight)
                {
                    await sleep(120)
                    continue
                }

                let next_wait_ms = POLL_WHEN_IDLE_MS

                hb_in_flight = true

                try
                {
                    const hb = await api_heartbeat(config)
                    worker_state.should_run = !!hb.should_run

                    const pending = hb.pending_count ?? 0
                    const running = hb.running_count ?? 0
                    const pending_other_keys = hb.pending_other_keys ?? 0

                    log("INFO", "heartbeat", {
                        should_run: hb.should_run,
                        pending_count: pending,
                        running_count: running,
                        pending_other_keys,
                        license_key_id: hb.license_key_id,
                        license_key: hb.license_key,
                        task_type: hb.task_type,
                        mode: hb.mode,
                        pc_label: hb.pc_label,
                        worker_busy: is_worker_running,
                    })

                    if (pending === 0 && pending_other_keys > 0)
                        log("WARN", "pending_exists_on_other_license_key", { pending_other_keys, license_key_id: hb.license_key_id })

                    if (!hb.should_run)
                    {
                        try
                        {
                            const recovered = await run_session_guard_if_needed(page, config)
                            if (recovered)
                                log("INFO", "session_guard_recovered_while_paused")
                        } catch (error)
                        {
                            const error_code = String(error?.code || "").trim().toUpperCase()
                            const is_hard_error = error_code === "AUTO_RELOGIN_MAX_RETRY"
                            log("ERROR", "session_guard_failed", { error: String(error?.message || error || "") })
                            if (is_hard_error)
                            {
                                stop_with_error(error)
                                break
                            }
                        }

                        if (is_worker_running)
                            log("INFO", "worker_paused_by_server_waiting_for_resume")

                        next_wait_ms = POLL_WHEN_PAUSED_MS
                    }
                    else if (pending === 0)
                    {
                        next_wait_ms = POLL_WHEN_IDLE_MS
                    }
                    else if (is_worker_running)
                    {
                        next_wait_ms = POLL_WHEN_BUSY_MS
                    }
                    else
                    {
                        is_worker_running = true

                        const run_started_at = Date.now()
                        const run_promise = main(page, {
                            mode: hb.mode || config?.meta?.mode || "umum",
                            task_type: hb.task_type || config?.meta?.action || "pendaftaran",
                        })

                        run_promise
                            .then(() =>
                            {
                                const elapsed_ms = Date.now() - run_started_at;
                                log("INFO", "worker_batch_done", { elapsed_ms })
                            })
                            .catch(err =>
                            {
                                const error_text = String(err?.message || err || "")
                                const error_code = String(err?.code || "").trim().toUpperCase()
                                log("ERROR", "worker_run_error", { error: error_text })

                                if (error_code === "AUTO_RELOGIN_MAX_RETRY" || error_text.includes("AUTO_RELOGIN_MAX_RETRY"))
                                {
                                    stop_with_error(err)
                                    return
                                }

                                if (
                                    error_text.includes("Target closed") ||
                                    error_text.includes("browser") ||
                                    error_text.includes("page")
                                )
                                {
                                    stop_with_error(err)
                                }
                            })
                            .finally(() =>
                            {
                                const run_ms = Date.now() - run_started_at
                                if (run_ms > MAX_RUN_MS)
                                    log("WARN", "worker_batch_too_long", { run_ms })
                                is_worker_running = false
                            })

                        next_wait_ms = POLL_WHEN_BUSY_MS
                    }

                } catch (e)
                {
                    if (e?.is_fatal)
                    {
                        const is_hard_logout = e.code === 'LICENSE_REVOKED' || e.code === 'NO_KEY'
                        log("ERROR", is_hard_logout ? "license_fatal" : "quota_fatal", { code: e.code, error: e.message })
                        stop_with_error(e)
                        break
                    }
                    log("ERROR", "heartbeat_error", { error: e?.message || String(e) })
                    next_wait_ms = POLL_WHEN_ERROR_MS
                } finally
                {
                    hb_in_flight = false
                }

                if (!stopped)
                    await sleep(next_wait_ms)
            }
        })().catch((err) =>
        {
            stop_with_error(err)
        })
    })
}
