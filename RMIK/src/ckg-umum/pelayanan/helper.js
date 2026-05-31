import { log } from "../../core/helpers.js";
import { sel } from "./selector_config.js";

const adaptive_wait_state_map = new WeakMap();
const adaptive_speed_enabled = String(process.env.DEV_ADAPTIVE_SPEED ?? "true").trim().toLowerCase() !== "false";
const adaptive_wait_min = Number(process.env.DEV_ADAPTIVE_WAIT_MIN || 0.5);
const adaptive_wait_max = Number(process.env.DEV_ADAPTIVE_WAIT_MAX || 1.7);
const adaptive_probe_interval_ms = Number(process.env.DEV_ADAPTIVE_PROBE_INTERVAL_MS || 700);

function clamp_number(value, min, max)
{
    return Math.max(min, Math.min(max, value));
}

function get_adaptive_wait_state(page)
{
    const current_state = adaptive_wait_state_map.get(page);
    if (current_state)
        return current_state;

    const next_state = {
        multiplier: 1,
        last_probe_at: 0,
        last_log_bucket: 10
    };
    adaptive_wait_state_map.set(page, next_state);
    return next_state;
}

function get_probe_target_multiplier(snapshot)
{
    if (!snapshot)
        return 1.3;

    const ready_state = String(snapshot.ready_state || "").toLowerCase();
    const loading_active = Boolean(snapshot.loading_active);
    const busy_nodes = Number(snapshot.busy_nodes || 0);

    if (loading_active || busy_nodes > 0)
        return 1.4;
    if (ready_state === "complete")
        return 0.45;
    if (ready_state === "interactive")
        return 0.7;
    return 1.1;
}

async function tune_adaptive_wait(page)
{
    if (!adaptive_speed_enabled)
        return 1;

    const state = get_adaptive_wait_state(page);
    const now_ms = Date.now();
    if (now_ms - state.last_probe_at < adaptive_probe_interval_ms)
        return state.multiplier;

    state.last_probe_at = now_ms;
    const snapshot = await page.evaluate(() =>
    {
        const loading_node = document.querySelector(".nuxt-loading-indicator");
        const loading_opacity = Number(window.getComputedStyle(loading_node || document.body).opacity || 0);
        const loading_active = Boolean(loading_node) && loading_opacity > 0.02;
        const busy_nodes = document.querySelectorAll(".loading, .is-loading, .cursor-progress, [aria-busy='true']").length;
        return {
            ready_state: document.readyState,
            loading_active,
            busy_nodes
        };
    }).catch(() => null);

    const target_multiplier = get_probe_target_multiplier(snapshot);
    const current_multiplier = Number(state.multiplier || 1);
    const next_multiplier = clamp_number((current_multiplier * 0.68) + (target_multiplier * 0.32), adaptive_wait_min, adaptive_wait_max);
    state.multiplier = next_multiplier;

    const log_bucket = Math.round(next_multiplier * 10);
    if (Math.abs(log_bucket - Number(state.last_log_bucket || 10)) >= 2)
    {
        state.last_log_bucket = log_bucket;
        log("INFO", "adaptive_wait_profile", {
            multiplier: Number(next_multiplier.toFixed(2)),
            ready_state: String(snapshot?.ready_state || "unknown"),
            loading_active: snapshot?.loading_active ? "yes" : "no",
            busy_nodes: Number(snapshot?.busy_nodes || 0)
        });
    }

    return next_multiplier;
}

export async function safe_wait(page, ms)
{
    const base_ms = Math.max(50, Math.min(ms || 200, 30000));
    const multiplier = await tune_adaptive_wait(page).catch(() => 1);
    const safe_ms = clamp_number(Math.round(base_ms * multiplier), 35, 45000);
    return await page.waitForTimeout(safe_ms).catch(() => { });
}

export async function wait_page_stable(page, timeout_ms)
{
    const multiplier = await tune_adaptive_wait(page).catch(() => 1);
    const max_ms = Math.max(2000, Math.min(Math.round((timeout_ms || 5000) * multiplier), 45000));
    await page.waitForLoadState("domcontentloaded", { timeout: max_ms }).catch(() => { });
    await page.waitForLoadState("load", { timeout: Math.min(max_ms, 8000) }).catch(() => { });
    await safe_wait(page, sel.wait.page_load_grace_ms);
}

export async function is_page_alive(page)
{
    try
    {
        const url = page.url();
        if (!url || url === "about:blank" || url === "chrome-error://chromewebdata/")
            return false;
        await page.evaluate(() => document.readyState).catch(() => null);
        return true;
    }
    catch
    {
        return false;
    }
}

export async function safe_goto(page, url, timeout_ms)
{
    const max_ms = Math.max(10000, Math.min(timeout_ms || 30000, 120000));
    for (let attempt = 1; attempt <= 3; attempt += 1)
    {
        try
        {
            await page.goto(url, { waitUntil: "domcontentloaded", timeout: max_ms });
            await safe_wait(page, sel.wait.page_load_grace_ms);
            return true;
        }
        catch (error)
        {
            log("WARN", "safe_goto_retry", {
                url,
                attempt,
                error: String(error?.message || error || "")
            });
            if (attempt < 3)
                await safe_wait(page, 1000 * attempt);
        }
    }
    return false;
}

export async function retry_step(page, name, max_try, fn)
{
    let last_error = null;
    const safe_max = Math.max(1, Math.min(max_try || sel.max_try.retry_step, 10));

    for (let index = 0; index < safe_max; index += 1)
    {
        try
        {
            return await fn();
        }
        catch (error)
        {
            last_error = error;
            const error_msg = String(error?.message || error || "");
            const is_page_dead = error_msg.includes("Target closed") ||
                error_msg.includes("context or browser has been closed") ||
                error_msg.includes("Execution context was destroyed") ||
                error_msg.includes("frame was detached");

            log("WARN", "retry_step_failed", {
                name,
                try_index: index + 1,
                max_try: safe_max,
                message: error_msg,
                fatal: is_page_dead ? "yes" : "no"
            });

            if (is_page_dead)
                throw last_error;

            const backoff_ms = Math.min(sel.wait.retry_delay_ms * Math.pow(1.5, index), 5000);
            await safe_wait(page, backoff_ms);

            if (!(await is_page_alive(page)))
                throw new Error(`Page tidak responsif saat retry ${name}`);
        }
    }

    throw last_error || new Error(`Langkah gagal setelah ${safe_max} percobaan: ${name}`);
}

export async function is_visible(locator, timeout_ms = 700)
{
    const safe_ms = Math.max(300, Math.min(timeout_ms || 700, 10000));
    return await locator.isVisible({ timeout: safe_ms }).catch(() => false);
}

export async function find_first_visible(candidates, timeout_ms = 700)
{
    const safe_ms = Math.max(300, Math.min(timeout_ms || 700, 10000));
    for (const locator of candidates)
        if (await is_visible(locator, safe_ms))
            return locator;

    return null;
}

export async function run_async_fallbacks(actions)
{
    for (const action of actions)
    {
        const ok = await action().then(() => true).catch(() => false);
        if (ok) return true;
    }

    return false;
}

export function is_profile_page(page)
{
    const url = String(page.url() || "");
    return url.includes("/profile");
}

export function resolve_timeout_ms(config)
{
    return Number(config?.browser?.timeout_ms ?? 120000);
}
