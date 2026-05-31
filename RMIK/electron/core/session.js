import fs from 'fs'
import path from 'path'
import { storage_dir, config_file } from './paths.js'
import { read_json, check_idle_timeout, clear_all_user_data, update_last_active_time, ensure_dir } from './utils.js'
import { send_to_renderer } from './windowManager.js'
import { account_load } from './config.js'
import { launch_chromium_browser } from './browser_launch.js'
import { get_worker_proc_ref } from './worker_state.js'

function is_true_value(value)
{
    const text = String(value ?? '').trim().toLowerCase()
    return text === '1' || text === 'true' || text === 'yes' || text === 'on'
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
        ''
    ).trim()
    const model = String(
        process.env.GROQ_MODEL ||
        'meta-llama/llama-4-scout-17b-16e-instruct'
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
    return String(value || '').replace(/[^0-9]/g, '')
}

async function get_captcha_image_base64(page)
{
    const image_selectors = [
        "img[alt='image-captcha' i]",
        '#img-captcha',
        '#captcha-image',
        '#captcha_image',
        "img[alt*='captcha' i]",
        "img[src*='captcha' i]",
        '.captcha img',
        'img.captcha',
    ]

    for (const selector of image_selectors)
    {
        const image_locator = page.locator(selector).first()
        const is_visible = await image_locator.isVisible({ timeout: 400 }).catch(() => false)
        if (!is_visible)
            continue

        const image_buffer = await image_locator.screenshot().catch(() => null)
        if (image_buffer && image_buffer.length > 0)
            return image_buffer.toString('base64')
    }

    return ''
}

async function request_captcha_text_from_groq(config, image_base64)
{
    const { default: fetch } = await import('node-fetch')
    const abort_controller = new AbortController()
    const timeout_handle = setTimeout(() => abort_controller.abort(), config.request_timeout_ms)

    try
    {
        const prompt_text = 'Extract only captcha digits. Return exactly 4 digits (0-9), no spaces, no symbols, no words.'

        const response = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${config.api_key}`,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                model: config.model,
                temperature: 0,
                max_completion_tokens: 20,
                messages: [
                    {
                        role: 'user',
                        content: [
                            {
                                type: 'text',
                                text: prompt_text
                            },
                            {
                                type: 'image_url',
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
            throw new Error(api_message || 'Groq request failed')
        }

        const raw_text = String(payload?.choices?.[0]?.message?.content || '').trim()
        return normalize_captcha_text(raw_text)
    } finally
    {
        clearTimeout(timeout_handle)
    }
}

async function refresh_captcha(page, timeout_ms)
{
    const trigger_selectors = [
        '#refresh-captcha',
        '#captcha-refresh',
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

async function solve_captcha_automatically(page, config, timeout_ms)
{
    const solver_config = get_captcha_solver_config(config, timeout_ms)
    if (!solver_config.auto_captcha_enabled)
        return { ok: false, reason: 'auto_captcha_disabled' }
    if (!solver_config.api_key)
        return { ok: false, reason: 'groq_api_key_missing' }

    for (let attempt = 1; attempt <= solver_config.max_retries; attempt++)
    {
        const image_base64 = await get_captcha_image_base64(page)
        if (!image_base64)
            return { ok: false, reason: 'captcha_image_not_found' }

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

            await page.locator('#input-captcha').fill('')
            await page.locator('#input-captcha').fill(captcha_text)
            return { ok: true, captcha_text, attempt }
        } catch (error)
        {
            if (attempt < solver_config.max_retries)
            {
                await refresh_captcha(page, timeout_ms)
                continue
            }
            return { ok: false, reason: String(error?.message || 'groq_error') }
        }
    }

    return { ok: false, reason: 'captcha_not_solved' }
}

async function wait_login_result(page, timeout_ms)
{
    const result = await Promise.race([
        page.waitForFunction(() => !location.pathname.includes('/auth/login'), null, { timeout: timeout_ms })
            .then(() => ({ ok: true, message: '' })),
        page.waitForSelector(
            '.text-error, .alert-danger, .error-message, :text("Belum berhasil masuk"), :text("User tidak dapat ditemukan"), :text("tidak valid"), :text("Kredensial salah")',
            { state: 'visible', timeout: timeout_ms }
        ).then(async element_node =>
        {
            const text = element_node ? await element_node.innerText().catch(() => '') : ''
            return { ok: false, message: text || 'Login Gagal' }
        })
    ]).catch(() => ({ ok: false, message: 'Timeout menunggu submit login' }))

    return result
}

async function wait_login_redirect(page, timeout_ms)
{
    return await page
        .waitForFunction(() => !location.pathname.includes('/auth/login'), null, { timeout: timeout_ms })
        .then(() => true)
        .catch(() => false)
}

async function ensure_login_credentials(page, email, password, timeout_ms, force_refill_credentials = false)
{
    const credential_state = await page.evaluate(() =>
    {
        const email_node = document.querySelector('#email')
        const password_node = document.querySelector('#password')
        return {
            email_value: String(email_node?.value || '').trim(),
            password_value: String(password_node?.value || '')
        }
    }).catch(() => ({ email_value: '', password_value: '' }))

    const normalized_email_value = credential_state.email_value
    const normalized_password_value = credential_state.password_value
    const should_fill_email = force_refill_credentials || normalized_email_value === '' || normalized_email_value !== String(email || '').trim()
    const should_fill_password = force_refill_credentials || normalized_password_value === '' || normalized_password_value !== String(password || '')

    if (should_fill_email)
    {
        const email_input = page.locator('#email').first()
        await email_input.fill('')
        await email_input.type(String(email || ''), { delay: 20 })
    }

    if (should_fill_password)
    {
        const password_input = page.locator('#password').first()
        await password_input.fill('')
        await password_input.type(String(password || ''), { delay: 20 })
    }

    return {
        filled_email: should_fill_email ? 'yes' : 'no',
        filled_password: should_fill_password ? 'yes' : 'no',
        force_refill: force_refill_credentials ? 'yes' : 'no'
    }
}

async function close_failed_login_modal(page, timeout_ms)
{
    const warning_modal = page.locator('div').filter({ hasText: /Belum berhasil masuk|email atau kata sandi/i }).first()
    const modal_visible = await warning_modal.isVisible({ timeout: 1000 }).catch(() => false)
    if (!modal_visible)
        return false

    const click_attempts = [
        async () => await warning_modal.getByRole('button', { name: /^\s*ok\s*$/i }).first().click({ force: true, timeout: timeout_ms }),
        async () => await warning_modal.locator('button.btn-fill-warning').first().click({ force: true, timeout: timeout_ms }),
        async () => await warning_modal.locator('button').first().click({ force: true, timeout: timeout_ms }),
        async () => await page.getByRole('button', { name: /^\s*ok\s*$/i }).first().click({ force: true, timeout: timeout_ms }),
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
        const button_nodes = Array.from(document.querySelectorAll('button.btn-fill-warning, button'))
        for (const button_node of button_nodes)
        {
            const button_text = String(button_node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase()
            if (button_text === 'ok')
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
    await submit_button.waitFor({ state: 'visible', timeout: timeout_ms })
    await submit_button.click({ timeout: timeout_ms })
}

async function has_valid_captcha_input(page)
{
    const captcha_value = await page.evaluate(() =>
    {
        const captcha_input = document.querySelector('#input-captcha')
        return String(captcha_input?.value || '')
    }).catch(() => '')
    return /^\d{4}$/.test(String(captcha_value || '').trim())
}

async function wait_manual_captcha_input(page, timeout_ms, report_status)
{
    const wait_timeout_ms = Math.max(15000, Math.min(timeout_ms || 120000, 600000))
    const deadline = Date.now() + wait_timeout_ms
    let last_tick_second = -1

    while (Date.now() < deadline)
    {
        const is_valid = await has_valid_captcha_input(page)
        if (is_valid)
            return true

        const remaining_ms = Math.max(0, deadline - Date.now())
        const remaining_second = Math.ceil(remaining_ms / 1000)
        const should_tick = remaining_second !== last_tick_second && (remaining_second % 10 === 0 || remaining_second <= 5)
        if (should_tick && typeof report_status === 'function')
        {
            report_status(remaining_second)
            last_tick_second = remaining_second
        }

        await page.waitForTimeout(200).catch(() => { })
    }

    return false
}

async function accept_privacy_if_present(page, timeout_ms)
{
    const title = page.locator('text=Pemberitahuan Privasi dan Ketentuan Penggunaan').first()
    if (!(await title.isVisible().catch(() => false)))
        return false

    const modal = title.locator("xpath=ancestor::div[contains(@class,'rounded-lg')][1]").first()
    await modal.waitFor({ state: 'visible', timeout: Math.max(1800, Math.min(timeout_ms, 8000)) }).catch(() => { })

    await modal.evaluate((modal_node) =>
    {
        const scroll_nodes = Array.from((modal_node || document).querySelectorAll('.overflow-auto, .overflow-y-auto'))
            .filter((n) => n.scrollHeight > n.clientHeight)
        for (const node of scroll_nodes)
        {
            node.scrollTop = node.scrollHeight
            node.dispatchEvent(new Event('scroll', { bubbles: true }))
        }
    }).catch(() => { })
    await page.waitForTimeout(500)

    const is_verify_true = async () =>
        await modal.evaluate((modal_node) =>
        {
            const input = (modal_node || document).querySelector('input[name="verify"][type="checkbox"]')
            return input && String(input.value).trim().toLowerCase() === 'true'
        }).catch(() => false)

    const click_targets = [
        modal.locator('div#verify.check').first(),
        modal.locator('div.check').first(),
        modal.locator('div.flex.gap-2.relative.items-center').first(),
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
                if (comp && typeof comp.emit === 'function')
                {
                    comp.emit('update:modelValue', true)
                    return
                }
                el = el.parentElement
            }

            input.checked = true
            input.value = 'true'
            input.dispatchEvent(new Event('change', { bubbles: true }))
        }).catch(() => { })
        await page.waitForTimeout(300)
    }

    const deadline = Date.now() + 3000
    let btn_enabled = false
    while (Date.now() < deadline && !btn_enabled)
    {
        btn_enabled = await modal.evaluate((modal_node) =>
        {
            const nodes = Array.from((modal_node || document).querySelectorAll('button, div'))
            const btn = nodes.find((n) =>
            {
                const text = String(n.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase()
                if (text !== 'setuju' || n.offsetParent === null) return false
                const cls = String(n.className || '').toLowerCase()
                if (cls.includes('bg-disabled') || cls.includes('cursor-not-allowed')) return false
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
            const nodes = Array.from((modal_node || document).querySelectorAll('button, div'))
            const btn = nodes.find((n) =>
            {
                const text = String(n.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase()
                return text === 'setuju' && n.offsetParent !== null
            })
            if (!btn) return
            btn.classList.remove('bg-disabled', 'cursor-not-allowed')
            btn.removeAttribute('disabled')
            btn.setAttribute('aria-disabled', 'false')
        }).catch(() => { })
        await page.waitForTimeout(100)
    }

    const setuju_btn = modal.locator('button').filter({ hasText: /Setuju/ }).first()
    const setuju_btn_visible = await setuju_btn.isVisible({ timeout: 300 }).catch(() => false)
    if (setuju_btn_visible)
        await setuju_btn.click({ force: true, timeout: 2000 }).catch(() => { })
    else
    {
        const setuju_div = modal.locator('div').filter({ hasText: /^Setuju$/ }).first()
        await setuju_div.click({ force: true, timeout: 2000 }).catch(() => { })
    }
    await page.waitForTimeout(300)

    const closed = await title.isHidden({ timeout: 1500 }).catch(() => false)
    if (closed)
        await page.waitForLoadState('load', { timeout: Math.min(timeout_ms, 15000) }).catch(() => { })
    return true
}

export function reset_cookies()
{
    const cookies_path = path.join(storage_dir, 'cookies.json')
    try
    {
        if (fs.existsSync(cookies_path)) fs.unlinkSync(cookies_path)
    } catch { }
}

export function check_cookies_valid()
{
    const cookies_path = path.join(storage_dir, 'cookies.json')

    if (!fs.existsSync(cookies_path))
        return { valid: false, reason: 'Belum pernah login ke Sehat IndonesiaKu' }

    if (check_idle_timeout())
    {
        clear_all_user_data()
        return { valid: false, reason: 'Sesi habis! Anda tidak menjalankan aplikasi lebih dari 2 Jam' }
    }

    try
    {
        const data = JSON.parse(fs.readFileSync(cookies_path, 'utf-8'))
        const cookies = data?.cookies || []

        if (cookies.length === 0)
        {
            reset_cookies()
            return { valid: false, reason: 'File cookie kosong — perlu login ulang' }
        }

        const now = Date.now() / 1000
        const session_cookie = cookies.find(c =>
            c.name && (c.name.toLowerCase().includes('session') || c.name.toLowerCase().includes('token'))
        )

        if (session_cookie?.expires && session_cookie.expires > 0 && session_cookie.expires < now)
        {
            reset_cookies()
            return { valid: false, reason: 'Sesi sudah expired — silakan login ulang' }
        }

        return { valid: true }
    } catch
    {
        reset_cookies()
        return { valid: false, reason: 'File cookie tidak dapat dibaca' }
    }
}

export async function check_credentials()
{
    const account = await account_load().catch(() => null)
    const email = account?.email
    const password = account?.password
    if (!email || !password)
    {
        reset_cookies()
        return false
    }
    return true
}

export async function logout_web()
{
    const cookies_path = path.join(storage_dir, 'cookies.json')
    if (!fs.existsSync(cookies_path)) return

    if (get_worker_proc_ref())
        return

    try
    {
        const config = read_json(config_file)
        const login_url = config?.urls?.login || 'https://sehatindonesiaku.kemkes.go.id/auth/login'
        const logout_url = login_url.replace('/auth/login', '/auth/logout')

        const { chromium } = await import('playwright')
        const launched = await launch_chromium_browser(chromium, config, {
            headless: true,
            args: [
                "--disable-dev-shm-usage", "--no-sandbox", "--disable-setuid-sandbox", "--js-flags=--max-old-space-size=512",
                "--disable-gpu", "--disable-software-rasterizer", "--disable-extensions", "--mute-audio"
            ]
        })
        const browser = launched.browser
        const context = await browser.newContext()
        await context.addCookies(
            JSON.parse(fs.readFileSync(cookies_path, 'utf-8'))?.cookies || []
        )
        const page = await context.newPage()
        await page.goto(logout_url, { timeout: 10000 }).catch(() => { })
        await browser.close()

        fs.unlinkSync(cookies_path)
    } catch { }
}

export async function session_check()
{
    if (!(await check_credentials()))
    {
        send_to_renderer('session_status', 'invalid')
        return { valid: false, reason: 'Email & Password belum diisi di Pengaturan' }
    }

    const cookies_check = check_cookies_valid()
    if (!cookies_check.valid)
    {
        send_to_renderer('session_status', 'invalid')
        return cookies_check
    }

    if (get_worker_proc_ref())
    {
        send_to_renderer('session_status', 'valid')
        return { valid: true }
    }

    try
    {
        const cookies_path = path.join(storage_dir, 'cookies.json')
        const { chromium } = await import('playwright')

        const launched = await launch_chromium_browser(chromium, read_json(config_file), {
            headless: true,
            args: [
                "--disable-dev-shm-usage", "--no-sandbox", "--disable-setuid-sandbox", "--js-flags=--max-old-space-size=512",
                "--disable-gpu", "--disable-software-rasterizer", "--disable-extensions", "--mute-audio"
            ]
        })
        const browser = launched.browser
        const context = await browser.newContext()

        const raw = JSON.parse(fs.readFileSync(cookies_path, 'utf-8'))
        const cookies = raw?.cookies || raw
        if (cookies.length > 0)
            await context.addCookies(cookies)

        const page = await context.newPage()
        const config = read_json(config_file)
        const home_url = config?.urls?.home || 'https://sehatindonesiaku.kemkes.go.id/'

        await page.goto(home_url, { timeout: 15000, waitUntil: 'domcontentloaded' })
        const is_logged_in = !page.url().includes('/auth/login')

        await browser.close()

        if (!is_logged_in)
        {
            reset_cookies()
            send_to_renderer('session_status', 'invalid')
            return { valid: false, reason: 'Sesi sudah expired — silakan login ulang' }
        }

        send_to_renderer('session_status', 'valid')
        return { valid: true }
    } catch (e)
    {
        reset_cookies()
        send_to_renderer('session_status', 'invalid')
        return { valid: false, reason: 'Gagal verifikasi sesi: ' + e.message }
    }
}

export async function session_login()
{
    if (get_worker_proc_ref())
        return { ok: false, error: 'Worker sedang berjalan. Stop worker terlebih dahulu sebelum login ulang.' }

    const config = read_json(config_file)
    if (!config)
        return { ok: false, error: 'config.json tidak ditemukan' }

    const login_url = config?.urls?.login
    const account = await account_load().catch(() => null)
    const email = account?.email
    const password = account?.password
    const cookies_path = path.join(storage_dir, 'cookies.json')
    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000)

    if (!email || !password)
        return { ok: false, error: 'Isi email & password Sehat IndonesiaKu di Pengaturan terlebih dahulu' }

    reset_cookies()

    let chromium_browser = null
    try
    {
        const { chromium } = await import('playwright')

        send_to_renderer('worker_log_batch', ['[SYSTEM] Mempersiapkan browser untuk akses Sehat IndonesiaKu...'])
        send_to_renderer('session_status', 'logging_in')

        const launched = await launch_chromium_browser(chromium, config, {
            headless: false,
            slowMo: 20,
            args: [
                "--disable-dev-shm-usage",
                "--no-sandbox",
                "--disable-setuid-sandbox",
                "--js-flags=--max-old-space-size=512"
            ]
        }, {
            on_attempt: (attempt, label) =>
            {
                send_to_renderer('worker_log_batch', [`[SYSTEM] Mencoba membuka ${label}...`])
            },
            on_failure: (attempt, label) =>
            {
                send_to_renderer('worker_log_batch', [`[WARN] ${label} tidak tersedia, mencoba opsi berikutnya...`])
            }
        })
        chromium_browser = launched.browser
        const context = await chromium_browser.newContext({ viewport: { width: 1280, height: 720 } })
        const page = await context.newPage()

        await page.goto(login_url, { timeout: timeout_ms, waitUntil: 'domcontentloaded' })

        const error_modal = page.locator('div').filter({ hasText: /silahkan lakukan login ulang/i }).first()
        const error_modal_visible = await error_modal.isVisible({ timeout: 800 }).catch(() => false)
        if (error_modal_visible)
        {
            const ok_btn = error_modal.locator('button.btn-fill-error, button').first()
            await ok_btn.click({ force: true, timeout: 2000 }).catch(() => { })
            await page.waitForTimeout(400)
            send_to_renderer('worker_log_batch', ['[SYSTEM] Modal error ditutup, melanjutkan login...'])
        }

        const solver_config = get_captcha_solver_config(config, timeout_ms)
        const use_auto_captcha = solver_config.auto_captcha_enabled && solver_config.api_key !== ''
        const retry_limit = use_auto_captcha ? solver_config.max_retries : 1
        let all_auto_captcha_failed = false

        if (!use_auto_captcha)
            send_to_renderer('worker_log_batch', ['[SYSTEM] Mode captcha manual aktif. Silakan isi captcha manual (4 digit).'])

        for (let login_try_index = 1; login_try_index <= retry_limit; login_try_index++)
        {
            const quick_check_timeout_ms = Math.min(timeout_ms, 3500)
            if (login_try_index === 1)
            {
                const fill_result = await ensure_login_credentials(page, email, password, timeout_ms, false)
                send_to_renderer('worker_log_batch', [
                    `[SYSTEM] Credential check ${login_try_index}/${retry_limit}: email=${fill_result.filled_email}, password=${fill_result.filled_password}, force=${fill_result.force_refill}`
                ])
            }
            else
                send_to_renderer('worker_log_batch', [`[SYSTEM] Credential skip refill ${login_try_index}/${retry_limit}`])
            await page.locator('#input-captcha').focus().catch(() => { })

            if (use_auto_captcha)
            {
                const auto_captcha_result = await solve_captcha_automatically(page, config, timeout_ms)
                if (!auto_captcha_result.ok)
                {
                    send_to_renderer('worker_log_batch', [`[WARN] OCR captcha gagal ${login_try_index}/${retry_limit} (${auto_captcha_result.reason || 'captcha_not_solved'})`])
                    if (login_try_index < retry_limit)
                    {
                        await refresh_captcha(page, timeout_ms)
                        continue
                    }
                    all_auto_captcha_failed = true
                    break
                }
                send_to_renderer('worker_log_batch', [`[SYSTEM] Captcha terisi otomatis (percobaan ${auto_captcha_result.attempt}).`])
            }
            else
            {
                const manual_ready = await wait_manual_captcha_input(page, timeout_ms, (remaining_second) =>
                {
                    send_to_renderer('worker_log_batch', [`[SYSTEM] Menunggu captcha manual... ${remaining_second} detik tersisa`])
                })
                if (!manual_ready)
                    throw new Error('Captcha manual tidak diisi (4 digit)')
            }

            const captcha_valid = await has_valid_captcha_input(page)
            if (!captcha_valid)
            {
                send_to_renderer('worker_log_batch', [`[WARN] Captcha invalid (bukan 4 digit) ${login_try_index}/${retry_limit}`])
                if (use_auto_captcha && login_try_index < retry_limit)
                {
                    await refresh_captcha(page, timeout_ms)
                    continue
                }
                throw new Error('Captcha tidak valid (bukan 4 digit)')
            }
            await page.waitForTimeout(120).catch(() => { })
            await submit_login_form(page, timeout_ms)
            const auto_redirected = await wait_login_redirect(page, Math.min(quick_check_timeout_ms, 900))
            if (auto_redirected)
                break

            const modal_closed_fast = await close_failed_login_modal(page, Math.min(timeout_ms, 700))
            if (modal_closed_fast && use_auto_captcha && login_try_index < retry_limit)
            {
                send_to_renderer('worker_log_batch', [`[WARN] Login gagal, lanjut retry captcha ${login_try_index + 1}/${retry_limit}...`])
                await refresh_captcha(page, timeout_ms)
                continue
            }

            const login_result = await wait_login_result(page, quick_check_timeout_ms)
            if (login_result.ok)
                break

            const modal_closed = await close_failed_login_modal(page, timeout_ms)
            if (modal_closed && use_auto_captcha && login_try_index < retry_limit)
            {
                send_to_renderer('worker_log_batch', [`[WARN] Login gagal, ulangi percobaan ${login_try_index + 1}/${retry_limit}...`])
                await refresh_captcha(page, timeout_ms)
                continue
            }

            throw new Error(login_result.message || 'email/password/captcha tidak valid')
        }

        if (use_auto_captcha && all_auto_captcha_failed && page.url().includes('/auth/login'))
        {
            const manual_wait_seconds = 15
            send_to_renderer('worker_log_batch', [
                `[SYSTEM] Captcha otomatis gagal. Browser dibiarkan terbuka ${manual_wait_seconds} detik untuk login manual...`
            ])

            let manual_login_success = false
            for (let wait_index = manual_wait_seconds; wait_index > 0; wait_index -= 1)
            {
                await page.waitForTimeout(1000).catch(() => { })
                const still_on_login = page.url().includes('/auth/login')
                if (!still_on_login)
                {
                    manual_login_success = true
                    send_to_renderer('worker_log_batch', ['[SYSTEM] Login manual berhasil terdeteksi!'])
                    break
                }
                if (wait_index % 5 === 0 || wait_index <= 3)
                    send_to_renderer('worker_log_batch', [`[SYSTEM] Menunggu login manual... ${wait_index} detik tersisa`])
            }

            if (!manual_login_success)
                throw new Error('Captcha otomatis gagal dan tidak ada login manual dalam 15 detik')
        }
        else if (page.url().includes('/auth/login'))
            throw new Error('Login gagal setelah retry')

        ensure_dir(path.dirname(cookies_path))
        await context.storageState({ path: cookies_path })

        const privacy_accepted = await accept_privacy_if_present(page, timeout_ms)
        if (privacy_accepted)
            await context.storageState({ path: cookies_path })

        await chromium_browser.close()
        chromium_browser = null

        send_to_renderer('worker_log_batch', ['[SYSTEM] Berhasil terhubung ke Web Sehat IndonesiaKu!'])
        send_to_renderer('session_status', 'valid')
        update_last_active_time()

        return { ok: true }
    } catch (e)
    {
        if (chromium_browser)
            await chromium_browser.close().catch(() => { })
        reset_cookies()
        send_to_renderer('session_status', 'invalid')

        let err_msg = e.message || String(e);
        if (err_msg.includes('Target page, context or browser has been closed') ||
            err_msg.includes('Target closed') ||
            err_msg.includes('browser') ||
            err_msg.includes('Navigating frame was detached') ||
            err_msg.includes('Execution context was destroyed')) 
        {
            err_msg = 'Proses login dibatalkan karena browser antrian ditutup sebelum selesai. Silakan Login ulang.';
        }

        return { ok: false, error: err_msg }
    }
}
