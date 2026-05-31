import { sel } from "./selector_config.js";
import { log } from "../../core/helpers.js";
import { worker_state } from "../../api/state.js";
import { find_first_visible, is_visible, retry_step, run_async_fallbacks, safe_wait, wait_page_stable } from "./helper.js";

function is_worker_stop_requested()
{
    const is_worker_mode = String(process.env.WORKER_MODE || "").trim() === "1";
    if (!is_worker_mode)
        return false;
    return worker_state.should_run === false;
}

function ensure_worker_running()
{
    if (!is_worker_stop_requested())
        return;
    const error = new Error("STOPPED_BY_SERVER");
    error.code = "STOPPED_BY_SERVER";
    throw error;
}

export async function find_exam_date_confirmation_modal(page)
{
    const title_pattern = sel.exam_date_confirmation_title_pattern;
    const modal_candidates = [
        page.locator(sel.exam_date_confirmation_modal_selector).filter({ hasText: title_pattern }).first(),
        page.locator("div").filter({ hasText: title_pattern }).first()
    ];
    return await find_first_visible(modal_candidates, sel.wait.element_visible_ms);
}

export async function find_start_examination_button(page)
{
    const by_role = page.getByRole("button", { name: sel.start_examination_button_pattern }).first();
    const by_selector = page.locator("button").filter({ hasText: sel.start_examination_button_pattern }).first();
    const by_partial_text = page.locator("button").filter({ hasText: /mulai\s*pemeriksaan/i }).first();
    const by_tracking_wide = page.locator("button .tracking-wide").filter({ hasText: /mulai\s*pemeriksaan/i }).first();
    return await find_first_visible([by_role, by_selector, by_partial_text, by_tracking_wide], sel.wait.element_visible_ms);
}

export async function find_examination_date_button(page)
{
    const by_role = page.getByRole("button", { name: sel.examination_date_button_pattern }).first();
    const by_selector = page.locator("button").filter({ hasText: sel.examination_date_button_pattern }).first();
    return await find_first_visible([by_role, by_selector], sel.wait.element_visible_ms);
}

export async function find_send_report_button(page)
{
    const by_div_selector = page.locator("div.btn-sent-report").filter({ hasText: sel.send_report_button_pattern }).first();
    const by_button_selector = page.locator("button.btn-sent-report").filter({ hasText: sel.send_report_button_pattern }).first();
    const by_selector = page.locator(sel.send_report_button_selector).filter({ hasText: sel.send_report_button_pattern }).first();
    const by_role = page.getByRole("button", { name: sel.send_report_button_pattern }).first();
    return await find_first_visible(
        [by_div_selector, by_button_selector, by_selector, by_role],
        Math.max(2600, sel.wait.element_visible_ms)
    );
}

export async function find_modal_by_title(page, modal_selector, title_pattern)
{
    const by_selector = page.locator(modal_selector).filter({ hasText: title_pattern }).first();
    const by_generic = page.locator("div").filter({ hasText: title_pattern }).first();
    return await find_first_visible([by_selector, by_generic], Math.max(5200, sel.wait.element_visible_ms));
}

export async function click_button_inside_modal(modal, button_pattern, click_timeout_ms)
{
    const modal_button = await find_first_visible([
        modal.getByRole("button", { name: button_pattern }).first(),
        modal.locator("button").filter({ hasText: button_pattern }).first(),
        modal.locator("div,button").filter({ hasText: button_pattern }).first()
    ], Math.max(2200, sel.wait.element_visible_ms));
    if (!modal_button)
        return false;

    await modal_button.scrollIntoViewIfNeeded().catch(() => { });
    const clicked = await run_async_fallbacks([
        () => modal_button.click({ timeout: click_timeout_ms }),
        () => modal_button.click({ force: true, timeout: click_timeout_ms })
    ]);
    return Boolean(clicked);
}

export async function close_send_report_limit_modal_if_visible(page, timeout_ms)
{
    const modal_title_pattern = /batas kirim rapor habis/i;
    const modal_desc_pattern = /3 kali mengirimkan rapor kesehatan/i;
    const modal_candidates = [
        page.locator("div[role='dialog'], div[class*='shadow-gmail'], div.p-2").filter({ hasText: modal_title_pattern }).first(),
        page.locator("div[role='dialog'], div[class*='shadow-gmail'], div.p-2").filter({ hasText: modal_desc_pattern }).first(),
        page.locator("div").filter({ hasText: modal_title_pattern }).first(),
        page.locator("div").filter({ hasText: modal_desc_pattern }).first()
    ];
    const modal = await find_first_visible(modal_candidates, Math.max(500, Math.min(timeout_ms, 1500)));
    if (!modal)
        return false;

    const close_clicked = await click_button_inside_modal(modal, /tutup|close|x/i, Math.min(2200, timeout_ms));
    if (!close_clicked)
    {
        const close_button = await find_first_visible([
            modal.getByRole("button", { name: /tutup|close|x/i }).first(),
            modal.locator("button[aria-label*='close' i], button[aria-label*='tutup' i]").first(),
            modal.locator("button").first()
        ], 600);
        if (close_button)
            await run_async_fallbacks([
                () => close_button.click({ timeout: 1200 }),
                () => close_button.click({ force: true, timeout: 1200 })
            ]);
    }

    await safe_wait(page, sel.wait.short_delay_ms);
    log("WARN", "batas_kirim_rapor_habis_modal_detected", {
        action: "close_and_mark_failed"
    });
    const error = new Error("Batas Kirim Rapor Habis");
    error.code = "BATAS_KIRIM_RAPOR_HABIS";
    throw error;
}

export async function close_processing_in_progress_modal_if_visible(page, timeout_ms)
{
    const modal_title_pattern = /data pemeriksaan sedang diproses/i;
    const modal_candidates = [
        page.locator("div[role='dialog'], div[class*='shadow-gmail'], div.p-2").filter({ hasText: modal_title_pattern }).first(),
        page.locator("div").filter({ hasText: modal_title_pattern }).first()
    ];
    const modal = await find_first_visible(modal_candidates, 500);
    if (!modal)
        return false;

    const close_clicked = await click_button_inside_modal(modal, /tutup/i, Math.min(2200, timeout_ms));
    if (!close_clicked)
    {
        const close_button = await find_first_visible([
            modal.getByRole("button", { name: /tutup/i }).first(),
            modal.locator("button").filter({ hasText: /tutup/i }).first(),
            modal.locator("button").first()
        ], 500);
        if (close_button)
            await run_async_fallbacks([
                () => close_button.click({ timeout: 1200 }),
                () => close_button.click({ force: true, timeout: 1200 })
            ]);
    }

    await safe_wait(page, sel.wait.short_delay_ms);
    log("WARN", "pemeriksaan_processing_modal_detected", {
        action: "close_and_skip_current_job"
    });
    const error = new Error("Data pemeriksaan sedang diproses, lanjut ke antrian berikutnya");
    error.code = "DATA_PEMERIKSAAN_DIPROSES";
    throw error;
}

export async function dismiss_processing_modal(page)
{
    return await page.evaluate(() =>
    {
        const nodes = Array.from(document.querySelectorAll("div, span"));
        const has_processing = nodes.some((n) =>
            /data pemeriksaan sedang diproses/i.test(String(n.textContent || "").replace(/\s+/g, " ").trim()));
        if (!has_processing)
            return false;

        const buttons = Array.from(document.querySelectorAll("button"));
        const tutup_btn = buttons.find((b) =>
        {
            const text = String(b.textContent || "").replace(/\s+/g, " ").trim().toLowerCase();
            return text === "tutup" && b.offsetParent !== null;
        });
        if (!tutup_btn)
            return false;

        tutup_btn.click();
        return true;
    }).catch(() => false);
}

export async function click_send_report_modal_confirm(page, timeout_ms)
{
    await close_send_report_limit_modal_if_visible(page, timeout_ms);
    const modal = await find_modal_by_title(page, sel.send_report_modal_selector, sel.send_report_modal_title_pattern);
    if (!modal)
    {
        const direct_confirm_button = await find_first_visible([
            page.getByRole("button", { name: sel.send_report_confirm_button_pattern }).first(),
            page.locator("button").filter({ hasText: sel.send_report_confirm_button_pattern }).first()
        ], Math.max(3200, sel.wait.element_visible_ms));
        if (!direct_confirm_button)
            throw new Error("Popup Kirim Rapor tidak muncul");

        await direct_confirm_button.scrollIntoViewIfNeeded().catch(() => { });
        const direct_clicked = await run_async_fallbacks([
            () => direct_confirm_button.click({ timeout: sel.wait.click_timeout_ms }),
            () => direct_confirm_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
        ]);
        if (!direct_clicked)
            throw new Error("Tombol Kirim pada popup rapor tidak ditemukan");

        await wait_page_stable(page, timeout_ms).catch(() => { });
        await safe_wait(page, sel.wait.short_delay_ms);
        await close_send_report_limit_modal_if_visible(page, timeout_ms);
        return true;
    }

    const clicked = await click_button_inside_modal(modal, sel.send_report_confirm_button_pattern, sel.wait.click_timeout_ms);
    if (!clicked)
        throw new Error("Tombol Kirim pada popup rapor tidak ditemukan");

    await wait_page_stable(page, timeout_ms).catch(() => { });
    await safe_wait(page, sel.wait.short_delay_ms);
    await close_send_report_limit_modal_if_visible(page, timeout_ms);
    return true;
}

export async function click_finish_service_modal_confirm(page, timeout_ms)
{
    const dismissed = await dismiss_processing_modal(page);
    if (dismissed)
    {
        await safe_wait(page, 300);
        throw new Error("Modal 'sedang diproses' muncul, retry selesaikan layanan");
    }

    const modal = await find_modal_by_title(page, sel.finish_service_modal_selector, sel.finish_service_modal_title_pattern);
    if (!modal)
        throw new Error("Popup Konfirmasi Selesaikan Layanan tidak muncul");

    const clicked = await click_button_inside_modal(modal, sel.finish_service_confirm_button_pattern, sel.wait.click_timeout_ms);
    if (!clicked)
        throw new Error("Tombol Konfirmasi pada popup Selesaikan Layanan tidak ditemukan");

    await wait_page_stable(page, timeout_ms).catch(() => { });
    await safe_wait(page, sel.wait.short_delay_ms);
    return true;
}

export async function click_send_report_button_once(page, timeout_ms)
{
    return await retry_step(page, "click_send_report_button_once", sel.max_try.retry_step, async () =>
    {
        ensure_worker_running();
        await page.evaluate(() =>
        {
            window.scrollTo(0, 0);
        }).catch(() => { });
        await safe_wait(page, sel.wait.short_delay_ms);
        const send_report_button = await find_send_report_button(page);
        if (!send_report_button)
            throw new Error("Tombol Kirim Rapor tidak ditemukan");

        const disabled = await send_report_button.evaluate((node, disabled_selector) =>
        {
            const text = String(node?.textContent || "").replace(/\s+/g, " ").trim().toLowerCase();
            const has_label = text.includes("kirim rapor");
            if (!has_label)
                return true;
            if (text.includes("lihat rapor"))
                return true;

            const self_disabled = node.matches?.(disabled_selector) || node.classList?.contains("cursor-not-allowed");
            if (self_disabled)
                return true;

            const disabled_parent = node.closest?.(disabled_selector);
            if (disabled_parent)
                return true;

            const is_disabled_attr = String(node.getAttribute?.("aria-disabled") || "").toLowerCase() === "true";
            if (is_disabled_attr)
                return true;

            return false;
        }, sel.send_report_disabled_selector).catch(() => true);
        if (disabled)
            throw new Error("Tombol Kirim Rapor masih disabled");

        await send_report_button.scrollIntoViewIfNeeded().catch(() => { });
        const clicked = await run_async_fallbacks([
            () => send_report_button.click({ timeout: sel.wait.click_timeout_ms }),
            () => send_report_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
        ]);
        if (!clicked)
            throw new Error("Klik Kirim Rapor gagal");

        const current_url_after_click = String(page.url() || "");
        if (/pkg\.kemkes\.go\.id\/rapor/i.test(current_url_after_click))
        {
            await page.goBack({ waitUntil: "domcontentloaded", timeout: Math.min(9000, timeout_ms) }).catch(() => { });
            throw new Error("Salah klik ke Lihat Rapor, retry Kirim Rapor");
        }

        await wait_page_stable(page, timeout_ms).catch(() => { });
        await safe_wait(page, sel.wait.short_delay_ms);
        await click_send_report_modal_confirm(page, timeout_ms);
        return true;
    });
}

export async function click_finish_service_button_required(page, timeout_ms)
{
    await close_send_report_limit_modal_if_visible(page, timeout_ms);
    await dismiss_processing_modal(page);
    return await retry_step(page, "click_finish_service_button_required", sel.max_try.retry_step, async () =>
    {
        ensure_worker_running();
        await dismiss_processing_modal(page);
        const finish_button = await find_first_visible([
            page.getByRole("button", { name: sel.finish_service_button_pattern }).first(),
            page.locator("button").filter({ hasText: sel.finish_service_button_pattern }).first(),
            page.locator("div, button").filter({ hasText: sel.finish_service_button_pattern }).first()
        ], Math.max(2200, sel.wait.element_visible_ms));
        if (!finish_button)
            throw new Error("Tombol Selesaikan Layanan tidak ditemukan");

        await finish_button.scrollIntoViewIfNeeded().catch(() => { });
        const clicked = await run_async_fallbacks([
            () => finish_button.click({ timeout: sel.wait.click_timeout_ms }),
            () => finish_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
        ]);
        if (!clicked)
            throw new Error("Klik Selesaikan Layanan gagal");

        await wait_page_stable(page, timeout_ms).catch(() => { });
        await safe_wait(page, sel.wait.short_delay_ms);
        await click_finish_service_modal_confirm(page, timeout_ms);
        return true;
    });
}
