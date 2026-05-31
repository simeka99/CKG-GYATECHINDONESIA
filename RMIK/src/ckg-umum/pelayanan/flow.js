import { sel } from "./selector_config.js";
import { find_first_visible, is_profile_page, is_visible, safe_wait, wait_page_stable, retry_step, run_async_fallbacks } from "./helper.js";
import { log } from "../../core/helpers.js";

function get_same_location_row(page)
{
    return page.locator(sel.same_location_row_selector)
        .filter({ hasText: sel.same_location_row_text_pattern })
        .first();
}

async function ensure_privacy_modal_closed(page, timeout_ms)
{
    const title = page.locator("text=Pemberitahuan Privasi dan Ketentuan Penggunaan").first();
    const title_visible = await title.isVisible({ timeout: 500 }).catch(() => false);
    if (!title_visible)
        return false;

    const modal = title.locator("xpath=ancestor::div[contains(@class,'rounded-lg')][1]").first();
    await modal.waitFor({ state: "visible", timeout: Math.max(1800, Math.min(timeout_ms, 8000)) }).catch(() => { });

    await modal.evaluate((modal_node) =>
    {
        const scroll_nodes = Array.from((modal_node || document).querySelectorAll(".overflow-auto, .overflow-y-auto"))
            .filter((n) => n.scrollHeight > n.clientHeight);
        for (const node of scroll_nodes)
        {
            node.scrollTop = node.scrollHeight;
            node.dispatchEvent(new Event("scroll", { bubbles: true }));
        }
    }).catch(() => { });
    await safe_wait(page, 500);

    const is_verify_true = async () =>
        await modal.evaluate((modal_node) =>
        {
            const input = (modal_node || document).querySelector('input[name="verify"][type="checkbox"]');
            return input && String(input.value).trim().toLowerCase() === "true";
        }).catch(() => false);

    const click_targets = [
        modal.locator("div#verify.check").first(),
        modal.locator("div.check").first(),
        modal.locator("div.flex.gap-2.relative.items-center").first(),
        modal.locator('input[name="verify"]').first()
    ];

    for (const target of click_targets)
    {
        const visible = await target.isVisible({ timeout: 300 }).catch(() => false);
        if (!visible)
            continue;

        await target.click({ force: true, timeout: 1500 }).catch(() => { });
        await safe_wait(page, 300);

        if (await is_verify_true())
            break;
    }

    if (!await is_verify_true())
    {
        await modal.evaluate((modal_node) =>
        {
            const input = (modal_node || document).querySelector('input[name="verify"][type="checkbox"]');
            if (!input) return;

            let el = input;
            while (el && el !== document.body)
            {
                const comp = el.__vueParentComponent;
                if (comp && typeof comp.emit === "function")
                {
                    comp.emit("update:modelValue", true);
                    return;
                }
                el = el.parentElement;
            }

            input.checked = true;
            input.value = "true";
            input.dispatchEvent(new Event("change", { bubbles: true }));
        }).catch(() => { });
        await safe_wait(page, 300);
    }

    const deadline = Date.now() + 3000;
    let btn_enabled = false;
    while (Date.now() < deadline && !btn_enabled)
    {
        btn_enabled = await modal.evaluate((modal_node) =>
        {
            const nodes = Array.from((modal_node || document).querySelectorAll("button, div"));
            const btn = nodes.find((n) =>
            {
                const text = String(n.textContent || "").replace(/\s+/g, " ").trim().toLowerCase();
                if (text !== "setuju" || n.offsetParent === null) return false;
                const cls = String(n.className || "").toLowerCase();
                if (cls.includes("bg-disabled") || cls.includes("cursor-not-allowed")) return false;
                return true;
            });
            return Boolean(btn);
        }).catch(() => false);
        if (!btn_enabled)
            await safe_wait(page, 100);
    }

    if (!btn_enabled)
    {
        await modal.evaluate((modal_node) =>
        {
            const nodes = Array.from((modal_node || document).querySelectorAll("button, div"));
            const btn = nodes.find((n) =>
            {
                const text = String(n.textContent || "").replace(/\s+/g, " ").trim().toLowerCase();
                return text === "setuju" && n.offsetParent !== null;
            });
            if (!btn) return;
            btn.classList.remove("bg-disabled", "cursor-not-allowed");
            btn.removeAttribute("disabled");
            btn.setAttribute("aria-disabled", "false");
        }).catch(() => { });
        await safe_wait(page, 100);
    }

    const setuju_btn = modal.locator("button").filter({ hasText: /Setuju/ }).first();
    const setuju_btn_visible = await setuju_btn.isVisible({ timeout: 300 }).catch(() => false);
    if (setuju_btn_visible)
        await setuju_btn.click({ force: true, timeout: 2000 }).catch(() => { });
    else
    {
        const setuju_div = modal.locator("div").filter({ hasText: /^Setuju$/ }).first();
        await setuju_div.click({ force: true, timeout: 2000 }).catch(() => { });
    }
    await safe_wait(page, 300);

    const closed = await title.isHidden({ timeout: 1500 }).catch(() => false);
    if (closed)
        log("INFO", "privacy_modal_closed_for_pelayanan");
    return closed;
}

async function close_login_warning_modal(page)
{
    const warning_modal = page.locator("div").filter({ hasText: /Belum berhasil masuk|email atau kata sandi/i }).first();
    const warning_visible = await warning_modal.isVisible({ timeout: 400 }).catch(() => false);
    if (!warning_visible)
        return false;

    const clicked = await run_async_fallbacks([
        () => warning_modal.getByRole("button", { name: /^\s*ok\s*$/i }).first().click({ force: true, timeout: 1200 }),
        () => warning_modal.locator("button.btn-fill-warning").first().click({ force: true, timeout: 1200 }),
        () => warning_modal.locator("button").first().click({ force: true, timeout: 1200 })
    ]);
    if (!clicked)
        return false;

    log("WARN", "login_warning_modal_closed_for_pelayanan");
    return true;
}

async function close_session_error_modal(page)
{
    const error_modal = page.locator("div").filter({ hasText: /silahkan lakukan login ulang/i }).first();
    const error_visible = await error_modal.isVisible({ timeout: 400 }).catch(() => false);
    if (!error_visible)
        return false;

    const clicked = await run_async_fallbacks([
        () => error_modal.locator("button.btn-fill-error").first().click({ force: true, timeout: 1200 }),
        () => error_modal.getByRole("button", { name: /^\s*ok\s*$/i }).first().click({ force: true, timeout: 1200 }),
        () => error_modal.locator("button").first().click({ force: true, timeout: 1200 })
    ]);
    if (!clicked)
        return false;

    await safe_wait(page, 300);
    log("WARN", "session_error_modal_closed_needs_relogin");
    return true;
}

export async function is_session_error_modal_visible(page)
{
    const error_modal = page.locator("div").filter({ hasText: /silahkan lakukan login ulang/i }).first();
    return await error_modal.isVisible({ timeout: 400 }).catch(() => false);
}

export async function clear_blocking_modal_for_pelayanan(page, timeout_ms = 5000)
{
    const deadline = Date.now() + Math.max(1200, Math.min(timeout_ms || 5000, 12000));
    let closed_any = false;
    let needs_relogin = false;
    while (Date.now() < deadline)
    {
        const closed_session_error = await close_session_error_modal(page).catch(() => false);
        if (closed_session_error)
        {
            needs_relogin = true;
            closed_any = true;
            break;
        }
        const closed_privacy = await ensure_privacy_modal_closed(page, timeout_ms).catch(() => false);
        const closed_warning = await close_login_warning_modal(page).catch(() => false);
        if (!closed_privacy && !closed_warning)
            break;
        closed_any = true;
        await safe_wait(page, 120);
    }
    return { closed_any, needs_relogin };
}

async function find_simpan_button(page)
{
    const candidates = sel.simpan_button_selectors.map((selector) =>
        page.locator(selector).filter({ hasText: sel.simpan_text_pattern }).first()
    );
    return await find_first_visible(candidates, 700);
}

async function has_disabled_simpan_placeholder(page)
{
    const disabled_container = page.locator(sel.simpan_disabled_selector)
        .filter({ hasText: sel.simpan_text_pattern })
        .first();
    return await is_visible(disabled_container, 500);
}

async function is_locator_enabled(locator)
{
    const is_enabled = await locator.isEnabled().catch(() => false);
    const has_disabled_style = await locator.evaluate((element) =>
        element.disabled ||
        element.classList.contains("cursor-not-allowed") ||
        element.classList.contains("btn-disabled") ||
        element.closest(".cursor-not-allowed") !== null
    ).catch(() => true);
    return is_enabled && !has_disabled_style;
}

async function wait_simpan_button_ready(page, timeout_ms)
{
    const wait_ms = Math.max(2500, Math.min(Number(timeout_ms) || 0, 20000));
    const deadline = Date.now() + wait_ms;
    let last_error = "Tombol Simpan belum siap";

    while (Date.now() < deadline)
    {
        const target_button = await find_simpan_button(page);
        const is_ready = target_button ? await is_locator_enabled(target_button) : false;
        if (is_ready)
            return target_button;

        const has_disabled = await has_disabled_simpan_placeholder(page);
        last_error = target_button
            ? "Tombol Simpan masih disabled"
            : (has_disabled ? "Tombol Simpan masih mode nonaktif" : "Tombol Simpan belum ditemukan");

        await safe_wait(page, sel.wait.short_delay_ms);
    }

    throw new Error(last_error);
}

async function click_button_with_fallback(page, button)
{
    if (!button) return false;

    await button.scrollIntoViewIfNeeded().catch(() => { });
    return await run_async_fallbacks([
        () => button.click({ timeout: sel.wait.click_timeout_ms }),
        () => button.click({ force: true, timeout: sel.wait.click_timeout_ms }),
        () => page.getByRole("button", { name: sel.simpan_text_pattern }).first().click({ force: true, timeout: sel.wait.click_timeout_ms }),
        () => page.locator('button[type="submit"]').filter({ hasText: /Simpan/ }).first().click({ force: true, timeout: sel.wait.click_timeout_ms })
    ]);
}

export async function click_simpan_button(page, timeout_ms)
{
    return await retry_step(page, "click_simpan_button", sel.max_try.retry_step, async () =>
    {
        const target_button = await wait_simpan_button_ready(page, timeout_ms).catch(() => null);
        if (target_button)
        {
            await target_button.waitFor({ state: "visible", timeout: timeout_ms }).catch(() => { });
            if (await is_locator_enabled(target_button))
            {
                const clicked = await click_button_with_fallback(page, target_button);
                if (clicked)
                    return true;
            }
        }

        const direct_btn = page.locator('button[type="submit"]').filter({ hasText: /Simpan/ }).first();
        const direct_visible = await direct_btn.isVisible({ timeout: 500 }).catch(() => false);
        if (direct_visible)
        {
            await direct_btn.scrollIntoViewIfNeeded().catch(() => { });
            await direct_btn.click({ force: true, timeout: 2000 }).catch(() => { });
            return true;
        }

        throw new Error("Tombol Simpan masih disabled atau tidak ditemukan");
    });
}

export async function ensure_profile_saved(page, timeout_ms)
{
    if (!is_profile_page(page))
        return true;

    log("WARN", "profile_page_detected_before_pelayanan", { url: page.url() });
    await ensure_privacy_modal_closed(page, timeout_ms).catch(() => false);
    await safe_wait(page, 250);

    const quick_simpan_button = await find_simpan_button(page).catch(() => null);
    const has_disabled_simpan = await has_disabled_simpan_placeholder(page).catch(() => false);
    if (!quick_simpan_button || has_disabled_simpan)
    {
        await ensure_privacy_modal_closed(page, timeout_ms).catch(() => false);
        log("INFO", "profile_save_skip_and_continue_to_pelayanan", {
            reason: !quick_simpan_button ? "simpan_not_found" : "simpan_disabled"
        });
        return true;
    }

    for (let index = 0; index < sel.max_try.save_profile; index += 1)
    {
        await ensure_privacy_modal_closed(page, timeout_ms).catch(() => false);
        await click_simpan_button(page, timeout_ms).catch(() => { });
        await wait_page_stable(page, timeout_ms);
        await safe_wait(page, 1000);

        if (!is_profile_page(page))
            return true;
    }

    throw new Error("Halaman profile belum selesai disimpan, proses pelayanan belum bisa lanjut");
}

async function find_same_location_checkbox(page)
{
    const row = get_same_location_row(page);
    const [first_selector, second_selector, third_selector] = sel.same_location_checkbox_selectors;
    const candidates = [
        row.locator(first_selector).first(),
        page.locator(second_selector).first(),
        page.locator(third_selector).first()
    ];
    return await find_first_visible(candidates, sel.wait.element_visible_ms);
}

async function is_same_location_active(checkbox)
{
    if (!checkbox) return false;

    return await checkbox.evaluate((element) =>
    {
        const checked = element.checked === true;
        const value_text = String(element.value || "").trim().toLowerCase();
        return checked || value_text === "true";
    }).catch(() => false);
}

async function set_same_location(page, checkbox)
{
    if (!checkbox) return false;

    if (await is_same_location_active(checkbox))
        return true;

    const toggled = await checkbox.evaluate((input_el) =>
    {
        let el = input_el;
        while (el && el !== document.body)
        {
            const comp = el.__vueParentComponent;
            if (comp && typeof comp.emit === "function")
            {
                comp.emit("update:modelValue", true);
                return true;
            }
            el = el.parentElement;
        }
        return false;
    }).catch(() => false);

    if (!toggled)
    {
        await checkbox.scrollIntoViewIfNeeded().catch(() => { });
        await checkbox.click({ force: true, timeout: 2000 }).catch(() => { });
    }

    await safe_wait(page, sel.wait.after_toggle_ms);
    return await is_same_location_active(checkbox);
}

async function select_role_puskesmas(page)
{
    const radio_btn = page.locator('input[type="radio"][value="puskesmas"]').first();
    const radio_visible = await radio_btn.isVisible({ timeout: 500 }).catch(() => false);

    if (radio_visible)
    {
        const parent_label = page.locator("label").filter({ has: radio_btn }).first();
        const parent_visible = await parent_label.isVisible({ timeout: 300 }).catch(() => false);
        if (parent_visible)
            await parent_label.click({ force: true, timeout: 1500 }).catch(() => { });
        else
            await radio_btn.click({ force: true, timeout: 1500 }).catch(() => { });
        await safe_wait(page, 300);
    }

    return await page.evaluate(() =>
    {
        const radio = document.querySelector('input[type="radio"][value="puskesmas"]');
        if (!radio) return false;

        let el = radio;
        while (el && el !== document.body)
        {
            const comp = el.__vueParentComponent;
            if (comp && typeof comp.emit === "function")
            {
                comp.emit("update:modelValue", "puskesmas");
                return true;
            }
            el = el.parentElement;
        }

        radio.checked = true;
        radio.dispatchEvent(new Event("change", { bubbles: true }));
        return true;
    }).catch(() => false);
}

async function open_pelayanan_umum_card(page, timeout_ms)
{
    const pelayanan_card = page.locator(sel.pelayanan_card_selector)
        .filter({ hasText: sel.pelayanan_card_text_pattern })
        .first();

    if (!await is_visible(pelayanan_card, 1500))
        return false;

    await pelayanan_card.scrollIntoViewIfNeeded().catch(() => { });
    await pelayanan_card.click({ force: true }).catch(() => { });
    await wait_page_stable(page, timeout_ms);
    await safe_wait(page, sel.wait.after_open_card_ms);
    return true;
}

async function is_pelayanan_list_ready(page)
{
    const search_input_candidates = sel.search_input_nik_selectors.map((selector) =>
        page.locator(selector).first()
    );
    const list_candidates = [
        page.locator(sel.pelayanan_table_selector).first(),
        page.locator(sel.status_tab_selector).first(),
        ...search_input_candidates
    ];
    const ready_locator = await find_first_visible(list_candidates, 900);
    return Boolean(ready_locator);
}

export async function submit_same_location_for_pelayanan(page, timeout_ms)
{
    for (let index = 0; index < sel.max_try.submit_loop; index += 1)
    {
        await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => false);
        const current_url = String(page.url() || "");
        if (current_url.includes("/auth/login") || current_url.includes("/auth/register"))
            throw new Error("Sesi habis — halaman dialihkan ke login");

        const has_role_form = await page.locator("div").filter({ hasText: /pilihan role/i }).first().isVisible({ timeout: 500 }).catch(() => false);
        if (has_role_form)
        {
            const role_selected = await select_role_puskesmas(page);
            if (role_selected)
                log("INFO", "role_puskesmas_auto_selected");
            await safe_wait(page, 200);
        }

        const checkbox = await find_same_location_checkbox(page);
        if (!checkbox)
        {
            if (await is_pelayanan_list_ready(page))
            {
                log("INFO", "pelayanan_same_location_skipped", { reason: "checkbox_not_present" });
                return true;
            }

            const card_opened = await open_pelayanan_umum_card(page, timeout_ms);
            if (!card_opened && await is_pelayanan_list_ready(page))
            {
                log("INFO", "pelayanan_same_location_skipped", { reason: "card_not_present" });
                return true;
            }
            await safe_wait(page, sel.wait.retry_loop_open_card_ms);
            continue;
        }

        const already_active = await is_same_location_active(checkbox);
        if (!already_active)
        {
            if (!await set_same_location(page, checkbox))
            {
                await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => false);
                await safe_wait(page, sel.wait.retry_delay_ms);
                continue;
            }
        }

        if (!await click_simpan_button(page, timeout_ms).then(() => true).catch(() => false))
        {
            const direct_submit = page.locator('button[type="submit"]').filter({ hasText: /Simpan/ }).first();
            const direct_visible = await direct_submit.isVisible({ timeout: 500 }).catch(() => false);
            if (direct_visible)
            {
                await direct_submit.click({ force: true, timeout: 2000 }).catch(() => { });
                await wait_page_stable(page, timeout_ms);
                await safe_wait(page, sel.wait.after_submit_ms);
                return true;
            }

            await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => false);
            await safe_wait(page, sel.wait.retry_loop_open_card_ms);
            continue;
        }

        await wait_page_stable(page, timeout_ms);
        await safe_wait(page, sel.wait.after_submit_ms);
        return true;
    }

    throw new Error("Checkbox lokasi atau tombol Simpan tidak ditemukan di halaman pelayanan");
}

export async function click_mulai_for_visible_row(page, timeout_ms)
{
    await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => false);
    const switch_to_tab = async (tab_pattern) =>
    {
        const clicked_by_dom = await page.evaluate(({ source, flags }) =>
        {
            const regex = new RegExp(source, flags);
            const normalize = (value) => String(value || "").replace(/\s+/g, " ").trim();
            const tab_nodes = Array.from(document.querySelectorAll("div.cursor-pointer.px-3, [role='tab'], button"));
            const target_node = tab_nodes.find((node) => regex.test(normalize(node.textContent || "")));
            if (!target_node)
                return false;
            try { target_node.click(); } catch { return false; }
            return true;
        }, {
            source: String(tab_pattern?.source || "sedang pemeriksaan"),
            flags: String(tab_pattern?.flags || "i")
        }).catch(() => false);
        if (clicked_by_dom)
        {
            await safe_wait(page, sel.wait.after_tab_switch_ms);
            return true;
        }

        const tab_locator = await find_first_visible([
            page.locator(sel.status_tab_selector).filter({ hasText: tab_pattern }).first()
        ], sel.wait.element_visible_ms);
        if (!tab_locator)
            return false;

        await tab_locator.scrollIntoViewIfNeeded().catch(() => { });
        const clicked = await run_async_fallbacks([
            () => tab_locator.click({ timeout: sel.wait.click_timeout_ms }),
            () => tab_locator.click({ force: true, timeout: sel.wait.click_timeout_ms })
        ]);
        if (!clicked)
            return false;
        await safe_wait(page, sel.wait.after_tab_switch_ms);
        return true;
    };

    const click_mulai_on_current_tab = async () =>
    {
        const clicked_by_dom = await page.evaluate(() =>
        {
            const normalize = (value) => String(value || "").replace(/\s+/g, " ").trim().toLowerCase();
            const is_visible = (element) =>
            {
                if (!element) return false;
                const style = window.getComputedStyle(element);
                if (style.display === "none" || style.visibility === "hidden") return false;
                return element.offsetParent !== null;
            };
            const rows = Array.from(document.querySelectorAll(".table-individu-terdaftar table tbody tr"));
            for (const row of rows)
            {
                if (!is_visible(row))
                    continue;
                const row_text = normalize(row.textContent || "");
                if (row_text.includes("tidak ditemukan") || row_text.includes("lakukan pencarian"))
                    continue;

                const action_nodes = Array.from(row.querySelectorAll("button, a, [role='button']"));
                const mulai_node = action_nodes.find((node) => normalize(node.textContent || "") === "mulai");
                if (!mulai_node)
                    continue;
                try
                {
                    mulai_node.click();
                    return true;
                }
                catch
                {
                    continue;
                }
            }
            return false;
        }).catch(() => false);
        if (clicked_by_dom)
            return true;

        const table = page.locator(sel.pelayanan_table_selector).first();
        await table.waitFor({ state: "visible", timeout: timeout_ms });

        const rows = table.locator(sel.pelayanan_row_selector);
        const total_rows = await rows.count().catch(() => 0);
        if (total_rows > 0)
        {
            for (let row_index = 0; row_index < total_rows; row_index += 1)
            {
                const row = rows.nth(row_index);
                if (!await row.isVisible().catch(() => false))
                    continue;
                const row_text = String(await row.textContent().catch(() => "") || "").toLowerCase();
                if (row_text.includes("tidak ditemukan") || row_text.includes("lakukan pencarian"))
                    continue;

                const mulai_button = await find_first_visible([
                    row.getByRole("button", { name: sel.mulai_button_pattern }).first(),
                    row.locator("button").filter({ hasText: sel.mulai_button_pattern }).first(),
                    row.locator("a").filter({ hasText: sel.mulai_button_pattern }).first(),
                    row.locator("[role='button']").filter({ hasText: sel.mulai_button_pattern }).first()
                ], 300);
                if (!mulai_button)
                    continue;

                await mulai_button.scrollIntoViewIfNeeded().catch(() => { });
                const clicked = await run_async_fallbacks([
                    () => mulai_button.click({ timeout: sel.wait.click_timeout_ms }),
                    () => mulai_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
                ]);
                if (clicked)
                    return true;
            }
        }

        const global_mulai_button = await find_first_visible([
            page.getByRole("button", { name: sel.mulai_button_pattern }).first(),
            page.locator("button").filter({ hasText: sel.mulai_button_pattern }).first(),
            page.locator("a").filter({ hasText: sel.mulai_button_pattern }).first(),
            page.locator("[role='button']").filter({ hasText: sel.mulai_button_pattern }).first()
        ], 350);
        if (!global_mulai_button)
            return false;

        await global_mulai_button.scrollIntoViewIfNeeded().catch(() => { });
        return await run_async_fallbacks([
            () => global_mulai_button.click({ timeout: sel.wait.click_timeout_ms }),
            () => global_mulai_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
        ]);
    };

    return await retry_step(page, "click_mulai_for_visible_row", sel.max_try.retry_step, async () =>
    {
        const tab_sequence = [
            sel.status_tab_sedang_pemeriksaan_pattern,
            sel.status_tab_belum_pemeriksaan_pattern,
            sel.status_tab_selesai_pemeriksaan_pattern
        ];
        let found_and_clicked = false;
        for (const tab_pattern of tab_sequence)
        {
            await switch_to_tab(tab_pattern).catch(() => false);
            await safe_wait(page, Math.max(350, sel.wait.after_tab_switch_ms));
            for (let wait_index = 0; wait_index < 3; wait_index += 1)
            {
                if (!await click_mulai_on_current_tab())
                {
                    await safe_wait(page, 300 + (wait_index * 150));
                    continue;
                }
                found_and_clicked = true;
                break;
            }
            if (found_and_clicked)
                break;
        }

        if (!found_and_clicked)
            throw new Error("Tombol Mulai tidak ditemukan di row hasil pencarian");

        await wait_page_stable(page, timeout_ms);
        await safe_wait(page, 350);
        return true;
    });
}
