import fs from "fs"
import path from "path"
import { log, goto_url } from "./helpers.js"

let auto_relogin_locked = false
let auto_relogin_locked_reason = ""

function is_true_value(value)
{
    const text = String(value ?? "").trim().toLowerCase()
    return text === "1" || text === "true" || text === "yes" || text === "on"
}

function to_positive_number(value, fallback)
{
    const numeric_value = Number(value)
    if (!Number.isFinite(numeric_value) || numeric_value <= 0)
        return fallback
    return numeric_value
}

function get_captcha_solver_config(config, timeout_ms)
{
    const api_key = String(
        process.env.GROQ_API_KEY ||
        ""
    ).trim()
    const model = String(
        process.env.GROQ_MODEL ||
        "meta-llama/llama-4-scout-17b-16e-instruct"
    ).trim()
    const max_retries = Math.max(1, Math.min(5, Math.trunc(to_positive_number(
        process.env.GROQ_MAX_RETRIES,
        5
    ))))
    const request_timeout_ms = Math.min(10000, Math.trunc(to_positive_number(
        process.env.GROQ_TIMEOUT_MS,
        Math.min(timeout_ms, 20000)
    )))
    const auto_captcha_env = process.env.GROQ_AUTO_CAPTCHA
    const auto_captcha_enabled = auto_captcha_env == null
        ? true
        : is_true_value(auto_captcha_env)

    return {
        auto_captcha_enabled,
        api_key,
        model,
        max_retries,
        request_timeout_ms
    }
}

function normalize_captcha_text(value)
{
    return String(value || "").replace(/[^0-9]/g, "")
}

function is_session_auto_relogin_enabled(config)
{
    const meta_value = config?.meta?.session_auto_relogin
    if (typeof meta_value === "boolean")
        return meta_value
    const text = String(meta_value ?? "").trim().toLowerCase()
    if (text === "")
        return false
    return text === "1" || text === "true" || text === "yes" || text === "on"
}

async function get_captcha_image_base64(page)
{
    const image_selectors = [
        "img[alt='image-captcha' i]",
        "#img-captcha",
        "#captcha-image",
        "#captcha_image",
        "img[alt*='captcha' i]",
        "img[src*='captcha' i]",
        ".captcha img",
        "img.captcha",
    ]

    for (const selector of image_selectors)
    {
        const image_locator = page.locator(selector).first()
        const is_visible = await image_locator.isVisible({ timeout: 400 }).catch(() => false)
        if (!is_visible)
            continue

        const image_buffer = await image_locator.screenshot().catch(() => null)
        if (image_buffer && image_buffer.length > 0)
            return image_buffer.toString("base64")
    }

    return ""
}

async function request_captcha_text_from_groq(config, image_base64)
{
    const { default: fetch } = await import("node-fetch")
    const abort_controller = new AbortController()
    const timeout_handle = setTimeout(() => abort_controller.abort(), config.request_timeout_ms)

    try
    {
        const prompt_text = `You are an expert OCR system. Extract ONLY the alphanumeric characters from this CAPTCHA image. Rules: Output ONLY uppercase letters (A-Z) and numbers (0-9), no spaces, no punctuation, no explanation. Length: exactly 4 characters. Common confusions: O vs 0, I vs 1, S vs 5, Z vs 2. Return ONLY the characters.`;

        const response = await fetch("https://api.groq.com/openai/v1/chat/completions", {
            method: "POST",
            headers: {
                Authorization: `Bearer ${config.api_key}`,
                "Content-Type": "application/json",
            },
            body: JSON.stringify({
                model: config.model,
                temperature: 0,
                max_completion_tokens: 20,
                messages: [
                    {
                        role: "user",
                        content: [
                            {
                                type: "text",
                                text: prompt_text
                            },
                            {
                                type: "image_url",
                                image_url: {
                                    url: `data:image/png;base64,${image_base64}`
                                }
                            }
                        ]
                    }
                ]
            }),
            signal: abort_controller.signal
        })

        const payload = await response.json().catch(() => ({}))
        if (!response.ok)
        {
            const api_message = String(payload?.error?.message || `HTTP ${response.status}`).trim()
            throw new Error(api_message || "Groq request failed")
        }

        const raw_text = String(payload?.choices?.[0]?.message?.content || "").trim()
        return normalize_captcha_text(raw_text)
    } finally
    {
        clearTimeout(timeout_handle)
    }
}

async function refresh_captcha(page, timeout_ms)
{
    const trigger_selectors = [
        "#refresh-captcha",
        "#captcha-refresh",
        "[onclick*='captcha' i]",
        "button:has-text('Refresh')",
        "button:has-text('Muat Ulang')",
        "a:has-text('Refresh')",
    ]

    for (const selector of trigger_selectors)
    {
        const trigger_locator = page.locator(selector).first()
        const is_visible = await trigger_locator.isVisible({ timeout: 200 }).catch(() => false)
        if (!is_visible)
            continue

        const clicked = await trigger_locator.click({ timeout: timeout_ms }).then(() => true).catch(() => false)
        if (clicked)
        {
            await page.waitForTimeout(80).catch(() => { })
            return true
        }
    }

    const captcha_image = page.locator("img[alt='image-captcha' i], #img-captcha, #captcha-image, #captcha_image, img[alt*='captcha' i], img[src*='captcha' i]").first()
    const image_visible = await captcha_image.isVisible({ timeout: 350 }).catch(() => false)
    if (!image_visible)
        return false

    const clicked_image = await captcha_image.click({ timeout: timeout_ms }).then(() => true).catch(() => false)
    if (clicked_image)
        await page.waitForTimeout(80).catch(() => { })
    return clicked_image
}

async function wait_login_result(page, timeout_ms)
{
    const result = await Promise.race([
        page.waitForFunction(() => !location.pathname.includes("/auth/login"), null, { timeout: timeout_ms })
            .then(() => ({ ok: true, message: "" })),
        page.waitForSelector(
            '.text-error, .alert-danger, .error-message, :text("Belum berhasil masuk"), :text("User tidak dapat ditemukan"), :text("tidak valid"), :text("Kredensial salah")',
            { state: "visible", timeout: timeout_ms }
        ).then(async (element_node) =>
        {
            const text = element_node ? await element_node.innerText().catch(() => "") : ""
            return { ok: false, message: text || "Login Gagal" }
        })
    ]).catch(() => ({ ok: false, message: "Timeout menunggu submit login" }))

    return result
}

async function wait_login_redirect(page, timeout_ms)
{
    return await page
        .waitForFunction(() => !location.pathname.includes("/auth/login"), null, { timeout: timeout_ms })
        .then(() => true)
        .catch(() => false)
}

async function ensure_login_credentials(page, email, password, timeout_ms, force_refill_credentials = false)
{
    const email_input = page.locator("#email").first()
    const password_input = page.locator("#password").first()
    const credential_state = {
        email_value: await email_input.inputValue().then((value) => String(value || "").trim()).catch(() => ""),
        password_value: await password_input.inputValue().then((value) => String(value || "")).catch(() => "")
    }

    const normalized_email_value = credential_state.email_value
    const normalized_password_value = credential_state.password_value
    const should_fill_email = force_refill_credentials || normalized_email_value === "" || normalized_email_value !== String(email || "").trim()
    const should_fill_password = force_refill_credentials || normalized_password_value === "" || normalized_password_value !== String(password || "")

    if (should_fill_email)
    {
        await email_input.fill("")
        await email_input.type(String(email || ""), { delay: 20 })
    }

    if (should_fill_password)
    {
        await password_input.fill("")
        await password_input.type(String(password || ""), { delay: 20 })
    }

    return {
        filled_email: should_fill_email ? "yes" : "no",
        filled_password: should_fill_password ? "yes" : "no",
        force_refill: force_refill_credentials ? "yes" : "no"
    }
}

async function close_failed_login_modal(page, timeout_ms)
{
    const warning_modal = page.locator("div").filter({ hasText: /Belum berhasil masuk|email atau kata sandi/i }).first()
    const modal_visible = await warning_modal.isVisible({ timeout: 1000 }).catch(() => false)
    if (!modal_visible)
        return false

    const click_attempts = [
        async () => await warning_modal.getByRole("button", { name: /^\s*ok\s*$/i }).first().click({ force: true, timeout: timeout_ms }),
        async () => await warning_modal.locator("button.btn-fill-warning").first().click({ force: true, timeout: timeout_ms }),
        async () => await warning_modal.locator("button").first().click({ force: true, timeout: timeout_ms }),
        async () => await page.getByRole("button", { name: /^\s*ok\s*$/i }).first().click({ force: true, timeout: timeout_ms }),
        async () => await page.locator("button.btn-fill-warning:has-text('Ok')").first().click({ force: true, timeout: timeout_ms }),
    ]

    for (const click_attempt of click_attempts)
    {
        const clicked_ok = await click_attempt().then(() => true).catch(() => false)
        if (clicked_ok)
        {
            await page.waitForTimeout(80).catch(() => { })
            return true
        }
    }

    const clicked_by_script = await page.evaluate(() =>
    {
        const button_nodes = Array.from(document.querySelectorAll("button.btn-fill-warning, button"))
        for (const button_node of button_nodes)
        {
            const button_text = String(button_node.textContent || "").replace(/\s+/g, " ").trim().toLowerCase()
            if (button_text === "ok")
            {
                button_node.click()
                return true
            }
        }
        return false
    }).catch(() => false)

    if (!clicked_by_script)
        return false

    await page.waitForTimeout(80).catch(() => { })
    return true
}

async function submit_login_form(page, timeout_ms)
{
    const submit_button = page.locator('button[type="submit"]:has-text("Masuk")').first()
    await submit_button.waitFor({ state: "visible", timeout: timeout_ms })
    await submit_button.click({ timeout: timeout_ms })
}

async function has_valid_captcha_input(page)
{
    const captcha_input = page.locator("#input-captcha").first()
    const captcha_value = await captcha_input.inputValue().then((value) => String(value || "")).catch(() => "")
    return /^\d{4}$/.test(String(captcha_value || "").trim())
}

async function is_authenticated_marker_visible(page)
{
    const marker_locators = [
        page.locator("#searchNik").first(),
        page.locator("button:has-text('Logout')").first(),
        page.locator("a:has-text('Keluar')").first(),
        page.locator("div").filter({ hasText: /Cek Kesehatan Gratis/i }).first(),
        page.locator("h1, h2, h3, div").filter({ hasText: /Profil/i }).first()
    ]

    for (const marker of marker_locators)
    {
        const visible = await marker.isVisible({ timeout: 250 }).catch(() => false)
        if (visible)
            return true
    }

    return false
}

async function wait_authenticated_session_ready(page, timeout_ms)
{
    const deadline = Date.now() + Math.max(3000, Math.min(timeout_ms || 120000, 30000))
    while (Date.now() < deadline)
    {
        if (await is_on_login_error_query(page))
            return false

        const on_login = await is_on_login(page)
        if (!on_login)
        {
            const marker_visible = await is_authenticated_marker_visible(page)
            if (marker_visible)
                return true

            await page.waitForLoadState("domcontentloaded", { timeout: 1200 }).catch(() => { })
            if (!(await is_on_login(page)))
                return true
        }

        await page.waitForTimeout(180).catch(() => { })
    }

    return !(await is_on_login(page))
}

async function wait_manual_captcha_input(page, timeout_ms)
{
    const wait_timeout_ms = Math.max(15000, Math.min(timeout_ms || 120000, 600000))
    const deadline = Date.now() + wait_timeout_ms
    log("INFO", "captcha_manual_wait")

    while (Date.now() < deadline)
    {
        const is_valid = await has_valid_captcha_input(page)
        if (is_valid)
            return true

        const on_login = await is_on_login(page)
        if (!on_login)
            return true

        await page.waitForTimeout(200).catch(() => { })
    }

    return false
}

async function solve_captcha_automatically(page, config, timeout_ms)
{
    const solver_config = get_captcha_solver_config(config, timeout_ms)
    if (!solver_config.auto_captcha_enabled)
        return { ok: false, reason: "auto_captcha_disabled" }
    if (!solver_config.api_key)
        return { ok: false, reason: "groq_api_key_missing" }

    for (let attempt = 1; attempt <= solver_config.max_retries; attempt += 1)
    {
        const image_base64 = await get_captcha_image_base64(page)
        if (!image_base64)
            return { ok: false, reason: "captcha_image_not_found" }

        try
        {
            const captcha_text = await request_captcha_text_from_groq(solver_config, image_base64)
            const is_valid_captcha = captcha_text.length === 4
            if (!is_valid_captcha)
            {
                if (attempt < solver_config.max_retries)
                    await refresh_captcha(page, timeout_ms)
                continue
            }

            const captcha_input = page.locator("#input-captcha").first()
            await captcha_input.fill("")
            await captcha_input.fill(captcha_text)
            return { ok: true, captcha_text, attempt }
        } catch (error)
        {
            if (attempt < solver_config.max_retries)
            {
                await refresh_captcha(page, timeout_ms)
                continue
            }
            return { ok: false, reason: String(error?.message || "groq_error") }
        }
    }

    return { ok: false, reason: "captcha_not_solved" }
}

async function is_on_login(page)
{
    return page.url().includes("/auth/login")
}

function is_login_error_query_url(url)
{
    const text = String(url || "")
    return text.includes("/auth/login") && /[?&]q=error(?:&|$)/i.test(text)
}

async function is_on_login_error_query(page)
{
    return is_login_error_query_url(page.url())
}

async function try_recover_session_from_login_redirect(page, home_url, timeout_ms)
{
    const target_home_url = String(home_url || "").trim()
    if (target_home_url === "")
        return false

    for (let try_index = 1; try_index <= 2; try_index += 1)
    {
        await goto_url(page, target_home_url, timeout_ms).catch(() => { })
        if (!(await is_on_login(page)))
        {
            log("INFO", "session_recovered_from_login_redirect", { try_index })
            return true
        }
        await page.waitForTimeout(300 * try_index).catch(() => { })
    }

    return false
}

function reset_cookies(cookies_file)
{
    try
    {
        const provided_path = String(cookies_file || "").trim()
        const storage_dir = String(process.env.STORAGE_DIR || "").trim()
        const resolved_path = provided_path || (storage_dir ? path.join(storage_dir, "cookies.json") : "")

        if (resolved_path && fs.existsSync(resolved_path))
            fs.unlinkSync(resolved_path)
        log("INFO", "cookies_reset")
    } catch { }
}

function resolve_logout_url(login_url)
{
    const value = String(login_url || "").trim()
    if (value === "")
        return ""

    try
    {
        const parsed = new URL(value)
        parsed.pathname = "/auth/logout"
        parsed.search = ""
        parsed.hash = ""
        return parsed.toString()
    } catch
    {
        return value.replace(/\/auth\/login.*$/i, "/auth/logout")
    }
}

async function hard_reset_session_before_relogin(page, context, login_url, cookies_file, timeout_ms, reason = "")
{
    reset_cookies(cookies_file)
    log("INFO", "cookies_reset", { reason })

    await context?.clearCookies?.().catch(() => { })
    await page.evaluate(() =>
    {
        try { localStorage.clear() } catch { }
        try { sessionStorage.clear() } catch { }
    }).catch(() => { })

    const logout_url = resolve_logout_url(login_url)
    if (logout_url === "")
        return

    await goto_url(page, logout_url, Math.min(timeout_ms, 15000)).catch(() => { })
    await dismiss_session_error_login_modal(page, Math.min(timeout_ms, 2500)).catch(() => { })
}

async function is_session_terminated_modal_visible(page)
{
    const title = page.locator("div,span").filter({ hasText: /sesi telah berakhir/i }).first()
    if (!(await title.isVisible({ timeout: 800 }).catch(() => false)))
        return false

    const body = page.locator("div,span").filter({ hasText: /login dari perangkat lain/i }).first()
    return await body.isVisible({ timeout: 800 }).catch(() => true)
}

async function acknowledge_session_terminated_modal(page, timeout_ms)
{
    const ok_button = page.getByRole("button", { name: /^\s*ok\s*$/i }).first()
    if (!(await ok_button.isVisible({ timeout: 900 }).catch(() => false)))
        return false

    await ok_button.click({ force: true, timeout: timeout_ms }).catch(() => { })
    await page.waitForTimeout(250)
    return true
}

async function login_manual_captcha(page, config, login_url, email, password, timeout_ms)
{
    await goto_url(page, login_url, timeout_ms)
    const solver_config = get_captcha_solver_config(config, timeout_ms)
    const use_auto_captcha = solver_config.auto_captcha_enabled && solver_config.api_key !== ""
    const retry_limit = use_auto_captcha ? solver_config.max_retries : 1

    if (!use_auto_captcha)
        log("INFO", "captcha_manual_mode_active")

    for (let login_try_index = 1; login_try_index <= retry_limit; login_try_index += 1)
    {
        const quick_check_timeout_ms = Math.min(timeout_ms, 3500)
        if (login_try_index === 1)
        {
            const fill_result = await ensure_login_credentials(page, email, password, timeout_ms, false)
            log("INFO", "login_credentials_checked", {
                login_try_index,
                filled_email: fill_result.filled_email,
                filled_password: fill_result.filled_password,
                force_refill: fill_result.force_refill
            })
        }
        else
            log("INFO", "login_credentials_skip_refill", { login_try_index })
        await page.locator("#input-captcha").focus().catch(() => { })

        if (use_auto_captcha)
        {
            const auto_captcha_result = await solve_captcha_automatically(page, config, timeout_ms)
            if (!auto_captcha_result.ok)
            {
                log("WARN", "captcha_auto_failed", { login_try_index, reason: auto_captcha_result.reason || "captcha_not_solved" })
                if (login_try_index < retry_limit)
                {
                    await refresh_captcha(page, timeout_ms)
                    continue
                }
                throw new Error(`Login Gagal: OCR captcha (${String(auto_captcha_result.reason || "captcha_not_solved")})`)
            }
            log("INFO", "captcha_auto_filled", {
                attempt: auto_captcha_result.attempt,
                captcha_len: auto_captcha_result.captcha_text.length
            })
        }
        else
        {
            const manual_ready = await wait_manual_captcha_input(page, timeout_ms)
            if (!manual_ready)
                throw new Error("Login Gagal: captcha manual tidak diisi (4 digit)")
        }

        if (!(await is_on_login(page)))
        {
            const ready = await wait_authenticated_session_ready(page, timeout_ms)
            if (!ready)
                throw new Error("Login Gagal: verifikasi sesi setelah navigasi tidak stabil")
            return
        }

        const captcha_valid = await has_valid_captcha_input(page)
        if (!captcha_valid)
        {
            log("WARN", "captcha_input_invalid_skip_submit", { login_try_index })
            if (login_try_index < retry_limit)
            {
                await refresh_captcha(page, timeout_ms)
                continue
            }
            throw new Error("Login Gagal: captcha bukan 4 digit")
        }
        await page.waitForTimeout(120).catch(() => { })
        await submit_login_form(page, timeout_ms)
        const auto_redirected = await wait_login_redirect(page, Math.min(quick_check_timeout_ms, 900))
        if (auto_redirected)
        {
            const ready = await wait_authenticated_session_ready(page, timeout_ms)
            if (!ready)
                throw new Error("Login Gagal: verifikasi sesi setelah redirect tidak stabil")
            return
        }

        const modal_closed_fast = await close_failed_login_modal(page, Math.min(timeout_ms, 700))
        if (modal_closed_fast && login_try_index < retry_limit)
        {
            log("WARN", "login_retry_after_modal_fast", { login_try_index, retry_limit })
            await refresh_captcha(page, timeout_ms)
            continue
        }

        const result = await wait_login_result(page, quick_check_timeout_ms)
        if (result.ok)
        {
            const ready = await wait_authenticated_session_ready(page, timeout_ms)
            if (!ready)
                throw new Error("Login Gagal: verifikasi sesi setelah submit tidak stabil")
            return
        }

        const modal_closed = await close_failed_login_modal(page, timeout_ms)
        if (modal_closed && login_try_index < retry_limit)
        {
            log("WARN", "login_retry_after_modal", { login_try_index, retry_limit })
            await refresh_captcha(page, timeout_ms)
            continue
        }

        throw new Error(`Login Gagal: ${String(result.message || "email/password/captcha tidak valid")}`)
    }

    throw new Error("Login Gagal: retry habis")
}

export async function accept_privacy_if_present(page, timeout_ms)
{
    const title = page.locator("text=Pemberitahuan Privasi dan Ketentuan Penggunaan").first()
    if (!(await title.isVisible().catch(() => false))) return false

    log("INFO", "privacy_modal_found")
    const modal = title.locator("xpath=ancestor::div[contains(@class,'rounded-lg')][1]").first()
    await modal.waitFor({ state: "visible", timeout: Math.max(1800, Math.min(timeout_ms, 8000)) }).catch(() => { })

    await modal.evaluate((modal_node) =>
    {
        const scroll_nodes = Array.from((modal_node || document).querySelectorAll(".overflow-auto, .overflow-y-auto"))
            .filter((n) => n.scrollHeight > n.clientHeight)
        for (const node of scroll_nodes)
        {
            node.scrollTop = node.scrollHeight
            node.dispatchEvent(new Event("scroll", { bubbles: true }))
        }
    }).catch(() => { })
    await page.waitForTimeout(500)

    const is_verify_true = async () =>
        await modal.evaluate((modal_node) =>
        {
            const input = (modal_node || document).querySelector('input[name="verify"][type="checkbox"]')
            return input && String(input.value).trim().toLowerCase() === "true"
        }).catch(() => false)

    const click_targets = [
        modal.locator("div#verify.check").first(),
        modal.locator("div.check").first(),
        modal.locator("div.flex.gap-2.relative.items-center").first(),
        modal.locator('input[name="verify"]').first()
    ]

    for (const target of click_targets)
    {
        const visible = await target.isVisible({ timeout: 300 }).catch(() => false)
        if (!visible)
            continue

        await target.click({ force: true, timeout: 1500 }).catch(() => { })
        await page.waitForTimeout(300)

        if (await is_verify_true())
            break
    }

    if (!await is_verify_true())
    {
        await modal.evaluate((modal_node) =>
        {
            const input = (modal_node || document).querySelector('input[name="verify"][type="checkbox"]')
            if (!input) return

            let el = input
            while (el && el !== document.body)
            {
                const comp = el.__vueParentComponent
                if (comp && typeof comp.emit === "function")
                {
                    comp.emit("update:modelValue", true)
                    return
                }
                el = el.parentElement
            }

            input.checked = true
            input.value = "true"
            input.dispatchEvent(new Event("change", { bubbles: true }))
        }).catch(() => { })
        await page.waitForTimeout(300)
    }

    const deadline = Date.now() + 3000
    let btn_enabled = false
    while (Date.now() < deadline && !btn_enabled)
    {
        btn_enabled = await modal.evaluate((modal_node) =>
        {
            const nodes = Array.from((modal_node || document).querySelectorAll("button, div"))
            const btn = nodes.find((n) =>
            {
                const text = String(n.textContent || "").replace(/\s+/g, " ").trim().toLowerCase()
                if (text !== "setuju" || n.offsetParent === null) return false
                const cls = String(n.className || "").toLowerCase()
                if (cls.includes("bg-disabled") || cls.includes("cursor-not-allowed")) return false
                return true
            })
            return Boolean(btn)
        }).catch(() => false)
        if (!btn_enabled)
            await page.waitForTimeout(100)
    }

    if (!btn_enabled)
    {
        await modal.evaluate((modal_node) =>
        {
            const nodes = Array.from((modal_node || document).querySelectorAll("button, div"))
            const btn = nodes.find((n) =>
            {
                const text = String(n.textContent || "").replace(/\s+/g, " ").trim().toLowerCase()
                return text === "setuju" && n.offsetParent !== null
            })
            if (!btn) return
            btn.classList.remove("bg-disabled", "cursor-not-allowed")
            btn.removeAttribute("disabled")
            btn.setAttribute("aria-disabled", "false")
        }).catch(() => { })
        await page.waitForTimeout(100)
    }

    const setuju_btn = modal.locator("button").filter({ hasText: /Setuju/ }).first()
    const setuju_btn_visible = await setuju_btn.isVisible({ timeout: 300 }).catch(() => false)
    if (setuju_btn_visible)
        await setuju_btn.click({ force: true, timeout: 2000 }).catch(() => { })
    else
    {
        const setuju_div = modal.locator("div").filter({ hasText: /^Setuju$/ }).first()
        await setuju_div.click({ force: true, timeout: 2000 }).catch(() => { })
    }
    await page.waitForTimeout(300)

    const closed = await title.isHidden({ timeout: 1500 }).catch(() => false)
    if (closed)
        log("INFO", "privacy_agree_clicked")
    await page.waitForLoadState("load", { timeout: Math.min(timeout_ms, 15000) }).catch(() => { })
    return true
}

async function perform_relogin(page, context, config, cookies_file, reason = "session_relogin")
{
    const login_url = config?.urls?.login
    const home_url = config?.urls?.home
    const email = config?.credentials?.email
    const password = config?.credentials?.password
    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000)

    if (auto_relogin_locked)
    {
        const err = new Error(auto_relogin_locked_reason || "AUTO_RELOGIN_MAX_RETRY")
        err.code = "AUTO_RELOGIN_MAX_RETRY"
        throw err
    }

    if (!email || !password) throw new Error("config.credentials.email & password wajib ada untuk auto-login")

    const is_headless = Boolean(config?.browser?.headless ?? true)
    const solver_config = get_captcha_solver_config(config, timeout_ms)
    const can_auto_solve_captcha_in_headless = solver_config.auto_captcha_enabled && solver_config.api_key !== ""
    if (is_headless && !can_auto_solve_captcha_in_headless)
    {
        const err = new Error("HEADLESS_RELOGIN_REQUIRES_BROWSER")
        err.code = "HEADLESS_RELOGIN_REQUIRES_BROWSER"
        throw err
    }

    if (await is_session_terminated_modal_visible(page))
        await acknowledge_session_terminated_modal(page, timeout_ms).catch(() => { })

    log("WARN", "session_relogin_required", { reason })
    const hard_reset_reasons = new Set([
        "session_terminated_modal",
        "session_error_login_modal",
        "redirected_to_login",
        "login_error_query",
        "ensure_authenticated_fallback"
    ])
    const should_hard_reset = hard_reset_reasons.has(String(reason || "").trim().toLowerCase())
    if (should_hard_reset)
        await hard_reset_session_before_relogin(page, context, login_url, cookies_file, timeout_ms, reason)
    else
        log("INFO", "cookies_reset_skipped", { reason })

    log("INFO", "manual_login_start")
    try
    {
        await login_manual_captcha(page, config, login_url, email, password, timeout_ms)
    }
    catch (error)
    {
        auto_relogin_locked = true
        auto_relogin_locked_reason = `AUTO_RELOGIN_MAX_RETRY: Login otomatis dihentikan setelah 5 percobaan. Lanjut login manual. Detail: ${String(error?.message || "Login gagal")}`
        const err = new Error(auto_relogin_locked_reason)
        err.code = "AUTO_RELOGIN_MAX_RETRY"
        throw err
    }
    await accept_privacy_if_present(page, timeout_ms)
    const ready_after_login = await wait_authenticated_session_ready(page, timeout_ms)
    if (!ready_after_login)
        throw new Error("Login berhasil submit tapi sesi belum stabil")

    await context.storageState({ path: cookies_file })
    log("INFO", "session_saved", { cookies_file })

    await goto_url(page, home_url, timeout_ms)
    await accept_privacy_if_present(page, timeout_ms)
    const ready_after_home = await wait_authenticated_session_ready(page, timeout_ms)
    if (!ready_after_home)
        throw new Error("Sesi belum stabil setelah kembali ke halaman utama")
    log("INFO", "home_loaded", { url: page.url() })

    auto_relogin_locked = false
    auto_relogin_locked_reason = ""
    return true
}

async function is_session_error_login_modal_visible(page)
{
    const modal = page.locator("div").filter({ hasText: /silahkan lakukan login ulang/i }).first()
    return await modal.isVisible({ timeout: 600 }).catch(() => false)
}

async function dismiss_session_error_login_modal(page, timeout_ms)
{
    const modal = page.locator("div").filter({ hasText: /silahkan lakukan login ulang/i }).first()
    const ok_button = modal.locator("button.btn-fill-error, button").first()
    await ok_button.click({ force: true, timeout: Math.min(timeout_ms, 2000) }).catch(() => { })
    await page.waitForTimeout(350)
}

export async function ensure_session_active(page, context, config, cookies_file = "")
{
    const allow_auto_relogin = is_session_auto_relogin_enabled(config)
    if (!allow_auto_relogin)
        return false

    if (auto_relogin_locked)
    {
        const err = new Error(auto_relogin_locked_reason || "AUTO_RELOGIN_MAX_RETRY")
        err.code = "AUTO_RELOGIN_MAX_RETRY"
        throw err
    }

    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000)
    if (await is_session_terminated_modal_visible(page))
        return await perform_relogin(page, context, config, cookies_file, "session_terminated_modal")

    if (await is_session_error_login_modal_visible(page))
    {
        log("WARN", "session_error_login_modal_detected_triggering_relogin")
        await dismiss_session_error_login_modal(page, timeout_ms)
        return await perform_relogin(page, context, config, cookies_file, "session_error_login_modal")
    }

    if (await is_on_login_error_query(page))
    {
        log("WARN", "session_login_error_query_detected_triggering_relogin", { url: page.url() })
        await dismiss_session_error_login_modal(page, timeout_ms).catch(() => { })
        return await perform_relogin(page, context, config, cookies_file, "login_error_query")
    }

    if (await is_on_login(page))
        return await perform_relogin(page, context, config, cookies_file, "redirected_to_login")

    await accept_privacy_if_present(page, timeout_ms).catch(() => false)
    return false
}

export async function ensure_authenticated(page, context, config, cookies_file)
{
    const login_url = config?.urls?.login
    const home_url = config?.urls?.home
    const email = config?.credentials?.email
    const password = config?.credentials?.password
    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000)

    if (!login_url || !home_url) throw new Error("config.urls.login & config.urls.home wajib ada")
    const allow_auto_relogin = is_session_auto_relogin_enabled(config)

    if (allow_auto_relogin && auto_relogin_locked)
    {
        const err = new Error(auto_relogin_locked_reason || "AUTO_RELOGIN_MAX_RETRY")
        err.code = "AUTO_RELOGIN_MAX_RETRY"
        throw err
    }

    try
    {
        await goto_url(page, home_url, timeout_ms)

        if (await ensure_session_active(page, context, config, cookies_file))
            return true

        if (!(await is_on_login(page)))
        {
            await accept_privacy_if_present(page, timeout_ms)
            log("INFO", "session_valid", { url: page.url() })
            return true
        }

        const recovered = await try_recover_session_from_login_redirect(page, home_url, timeout_ms)
        if (recovered)
        {
            log("INFO", "session_valid_after_soft_recover", { url: page.url() })
            return true
        }

        log("WARN", "session_expired_resetting")
        reset_cookies(cookies_file)

    } catch (e)
    {
        log("ERROR", "session_check_failed", { error: e?.message })
        reset_cookies(cookies_file)
    }

    if (!allow_auto_relogin)
    {
        const err = new Error("SESSION_EXPIRED_AUTO_RELOGIN_DISABLED")
        err.code = "SESSION_EXPIRED_AUTO_RELOGIN_DISABLED"
        throw err
    }
    return await perform_relogin(page, context, config, cookies_file, "ensure_authenticated_fallback")
}
