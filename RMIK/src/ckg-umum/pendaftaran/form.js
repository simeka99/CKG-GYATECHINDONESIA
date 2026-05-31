import { log } from "../../core/helpers.js";
import { SEL } from "./selector_config.js";

const MONTH_MAP = {
    1: ["januari", "jan"], 2: ["februari", "feb"], 3: ["maret", "mar"],
    4: ["april", "apr"], 5: ["mei"], 6: ["juni", "jun"],
    7: ["juli", "jul"], 8: ["agustus", "agt"], 9: ["september", "sep"],
    10: ["oktober", "okt"], 11: ["november", "nov"], 12: ["desember", "des"]
};

function parse_date(s)
{
    const m = String(s || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) throw Object.assign(new Error(`DATE_FORMAT: "${s}"`), { code: "DATE_FORMAT" });
    const year = +m[1], month = +m[2], day = +m[3];
    if (month < 1 || month > 12) throw Object.assign(new Error("DATE_MONTH"), { code: "DATE_MONTH" });
    return { year, month, day };
}

function norm_text(s)
{
    return (s || "").toLowerCase().replace(/\s+/g, " ").trim();
}

async function vue_fill(el_handle, value)
{
    await el_handle.evaluate((el, val) =>
    {
        el.focus();
        el.value = val;
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
        el.blur();
    }, value);
}

async function vue_type_with_retry(page, selector, value, timeout_ms = 10000)
{
    const deadline = Date.now() + timeout_ms;
    let last_error;

    while (Date.now() < deadline)
    {
        try
        {
            const input = page.locator(selector).first();
            await input.waitFor({ state: "visible", timeout: 1200 });

            const handle = await input.elementHandle();
            if (!handle) throw new Error("INPUT_HANDLE_NULL");

            await handle.evaluate((el, val) =>
            {
                el.scrollIntoView({ block: "center" });
                el.focus();
                el.value = "";
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.value = val;
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.dispatchEvent(new Event("change", { bubbles: true }));
            }, String(value));

            await page.waitForTimeout(300);
            return true;
        }
        catch (e)
        {
            last_error = e;
            await page.waitForTimeout(200);
        }
    }

    throw last_error || new Error(`TYPE_FAILED: ${selector}`);
}

async function click_exact_option_with_retry(page, option_sel, text_sel, value, timeout_ms = 10000)
{
    const deadline = Date.now() + timeout_ms;

    while (Date.now() < deadline)
    {
        const clicked = await page.evaluate(({ option_sel, text_sel, target }) =>
        {
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();

            const options = [...document.querySelectorAll(option_sel)]
                .filter(el => el.offsetParent);

            const btn = options.find(el =>
            {
                const inner = text_sel ? el.querySelector(text_sel) : null;
                const txt = inner ? inner.textContent : el.textContent;
                return norm(txt) === norm(target);
            });

            if (!btn) return false;

            btn.scrollIntoView({ block: "center" });
            btn.click();
            return true;
        }, {
            option_sel,
            text_sel,
            target: value
        }).catch(() => false);

        if (clicked) return true;
        await page.waitForTimeout(250);
    }

    throw Object.assign(
        new Error(`OPTION_NOT_FOUND: "${value}"`),
        { code: "OPTION_NOT_FOUND" }
    );
}

async function wait_next_step_input(page, next_placeholder, timeout_ms = 10000)
{
    if (!next_placeholder) return true;

    await page.locator(`input[placeholder="${next_placeholder}"]`).first().waitFor({
        state: "visible",
        timeout: timeout_ms
    });

    return true;
}

export async function fill_input(page, selectors, value, label, timeout_ms)
{
    for (const sel of selectors)
    {
        try
        {
            const el = page.locator(sel).first();
            if (!await el.isVisible().catch(() => false)) continue;
            await vue_fill(el, String(value));
            log("INFO", "fill_input_ok", { label, sel });
            return true;
        }
        catch
        {
            continue;
        }
    }

    throw Object.assign(new Error(`${label}_NOT_FOUND`), { code: `${label}_NOT_FOUND` });
}

export async function fill_phone(page, value, timeout_ms)
{
    for (const sel of SEL.phone)
    {
        try
        {
            const el = page.locator(sel).first();
            await el.waitFor({ state: "visible", timeout: 2000 });
            await vue_fill(el, String(value));
            log("INFO", "fill_input_ok", { label: "PHONE", sel });
            return true;
        }
        catch
        {
            continue;
        }
    }

    throw Object.assign(new Error("PHONE_NOT_FOUND"), { code: "PHONE_NOT_FOUND" });
}

export async function select_gender(page, value, timeout_ms, scope_locator = null)
{
    log("INFO", "select_gender_start", { value });

    const res = scope_locator
        ? await scope_locator.evaluate((el, { target, texts }) =>
        {
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
            const trigger = [...el.querySelectorAll("div.cursor-pointer")]
                .find(x => texts.includes(norm(x.textContent)));
            if (!trigger) return { state: "not_found" };
            if (norm(trigger.textContent) === norm(target)) return { state: "already_selected" };
            trigger.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
            return { state: "opened" };
        }, { target: value, texts: SEL.gender_trigger_text })
        : await page.evaluate(({ target, texts }) =>
        {
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
            const trigger = [...document.querySelectorAll("div.cursor-pointer")]
                .filter(el => el.offsetParent)
                .find(el => texts.includes(norm(el.textContent)));
            if (!trigger) return { state: "not_found" };
            if (norm(trigger.textContent) === norm(target)) return { state: "already_selected" };
            trigger.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
            return { state: "opened" };
        }, { target: value, texts: SEL.gender_trigger_text });

    if (res.state === "not_found")
        throw Object.assign(new Error("GENDER_TRIGGER: not found"), { code: "GENDER_TRIGGER" });

    if (res.state === "already_selected")
    {
        log("INFO", "select_gender_already_selected", { value });
        return true;
    }

    await page.waitForSelector(SEL.gender_option, { state: "visible", timeout: timeout_ms });

    const found = await page.evaluate(({ target, sel }) =>
    {
        const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
        const opt = [...document.querySelectorAll(sel)]
            .filter(el => el.offsetParent)
            .find(el => norm(el.textContent) === norm(target));
        if (!opt) return false;
        opt.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
        return true;
    }, { target: value, sel: SEL.gender_option });

    if (!found)
        throw Object.assign(new Error(`GENDER_OPTION_NOT_FOUND: "${value}"`), { code: "GENDER_OPTION_NOT_FOUND" });

    log("INFO", "select_gender_ok", { value });
    return true;
}

export async function select_birth_date(page, date_string, timeout_ms, scope_locator = null)
{
    const { year, month, day } = parse_date(date_string);
    const title = `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
    log("INFO", "select_birth_date_start", { date_string });

    let opened = false;

    if (scope_locator)
    {
        opened = await scope_locator.evaluate((root, sel) =>
        {
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
            const wrappers = [...root.querySelectorAll(sel)].filter(x => x.offsetParent);
            const trigger = wrappers.find(x => norm(x.innerText).includes("pilih tanggal lahir")) || wrappers[0];
            if (!trigger) return false;

            trigger.scrollIntoView({ block: "center" });
            trigger.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
            return true;
        }, SEL.calendar_wrapper).catch(() => false);
    }
    else
    {
        await page.waitForSelector(SEL.calendar_wrapper, { state: "visible", timeout: timeout_ms });
        opened = await page.evaluate((sel) =>
        {
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
            const w = [...document.querySelectorAll(sel)]
                .find(x => x.offsetParent && norm(x.innerText).includes("pilih tanggal lahir"));
            if (!w) return false;
            w.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
            return true;
        }, SEL.calendar_wrapper).catch(() => false);
    }

    if (!opened)
        throw Object.assign(new Error("DOB_TRIGGER_NOT_FOUND"), { code: "DOB_TRIGGER_NOT_FOUND" });

    await page.waitForSelector(SEL.calendar_root, { state: "visible", timeout: timeout_ms });

    await page.evaluate((sel) =>
    {
        [...document.querySelectorAll(`${sel} button.mx-btn-current-year`)]
            .filter(el => el.offsetParent).pop()?.click();
    }, SEL.calendar_root);

    await page.waitForSelector(SEL.calendar_year_panel, { state: "visible", timeout: timeout_ms });

    let picked_year = false;
    for (let i = 0; i < 30 && !picked_year; i++)
    {
        const result = await page.evaluate(({ target_year, sel }) =>
        {
            const panel = [...document.querySelectorAll(sel)]
                .filter(el => el.offsetParent).pop();
            if (!panel) return { done: false, nav: null };

            const cells = [...(panel.querySelector("table.mx-table-year")?.querySelectorAll("td.cell[data-year]") || [])];
            const target = cells.find(c => +c.getAttribute("data-year") === target_year);

            if (target)
            {
                target.click();
                return { done: true, nav: null };
            }

            const years = cells.map(c => +c.getAttribute("data-year")).filter(Number.isFinite);
            const min_y = years.length ? Math.min(...years) : NaN;
            const max_y = years.length ? Math.max(...years) : NaN;

            if (Number.isFinite(min_y) && target_year < min_y)
            {
                panel.querySelector("button .mx-icon-double-left")?.closest("button")?.click();
                return { done: false, nav: "prev" };
            }

            if (Number.isFinite(max_y) && target_year > max_y)
            {
                panel.querySelector("button .mx-icon-double-right")?.closest("button")?.click();
                return { done: false, nav: "next" };
            }

            return { done: false, nav: null };
        }, { target_year: year, sel: SEL.calendar_year_panel });

        if (result.done)
        {
            picked_year = true;
            break;
        }

        if (result.nav)
        {
            await page.waitForFunction((sel) =>
            {
                const panel = [...document.querySelectorAll(sel)]
                    .filter(el => el.offsetParent).pop();
                return panel && panel.querySelectorAll("td.cell[data-year]").length > 0;
            }, SEL.calendar_year_panel, { timeout: 3000 });
        }
        else
        {
            break;
        }
    }

    if (!picked_year)
        throw Object.assign(new Error(`DOB_YEAR_PICK: ${year}`), { code: "DOB_YEAR_PICK" });

    await page.waitForSelector(SEL.calendar_month_panel, { state: "visible", timeout: timeout_ms });

    const month_picked = await page.evaluate(({ tokens, sel }) =>
    {
        const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
        const panel = [...document.querySelectorAll(sel)]
            .filter(el => el.offsetParent).pop();
        if (!panel) return false;

        const cell = [...panel.querySelectorAll("td.cell")]
            .find(c => tokens.includes(norm(c.textContent)));

        if (!cell) return false;
        cell.click();
        return true;
    }, { tokens: MONTH_MAP[month] || [], sel: SEL.calendar_month_panel });

    if (!month_picked)
        throw Object.assign(new Error(`DOB_MONTH_PICK: ${month}`), { code: "DOB_MONTH_PICK" });

    await page.waitForSelector(SEL.calendar_date_panel, { state: "visible", timeout: timeout_ms });

    await page.waitForFunction(({ t, sel }) =>
    {
        const panel = [...document.querySelectorAll(sel)]
            .filter(el => el.offsetParent).pop();
        return panel && !!panel.querySelector(`td.cell[title="${t}"]`);
    }, { t: title, sel: SEL.calendar_date_panel }, { timeout: timeout_ms });

    const date_picked = await page.evaluate(({ t, sel }) =>
    {
        const panel = [...document.querySelectorAll(sel)]
            .filter(el => el.offsetParent).pop();
        if (!panel) return "NO_PANEL";

        const cell = panel.querySelector(`td.cell[title="${t}"]`);
        if (!cell) return "NO_CELL";
        if (cell.classList.contains("disabled")) return "DISABLED";

        (cell.querySelector("div") || cell).dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
        return "OK";
    }, { t: title, sel: SEL.calendar_date_panel });

    if (date_picked !== "OK")
    {
        throw Object.assign(
            new Error(`DOB_DATE_PICK: ${title} (${date_picked})`),
            { code: date_picked === "DISABLED" ? "DOB_DATE_DISABLED" : "DOB_DATE_PICK" }
        );
    }

    log("INFO", "select_birth_date_ok", { date: title });
    return title;
}

export async function select_exam_date_today(page, timeout_ms)
{
    log("INFO", "select_exam_date_today_start");
    const today_day = await page.evaluate(() => new Date().getDate());

    for (const sel of SEL.exam_grid)
    {
        const visible = await page.waitForSelector(sel, { state: "visible", timeout: 3000 }).catch(() => null);
        if (!visible) continue;

        const found = await page.evaluate(({ sel, today_day }) =>
        {
            const grid = document.querySelector(sel);
            if (!grid) return false;

            const btn = [...grid.querySelectorAll('button[type="button"]')]
                .filter(b => !b.disabled && !b.classList.contains("cursor-not-allowed"))
                .find(b =>
                {
                    const span = b.querySelector("span.font-bold");
                    return span && +(span.textContent.trim()) === today_day;
                });

            if (!btn) return false;
            btn.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
            return true;
        }, { sel, today_day });

        if (found)
        {
            log("INFO", "select_exam_date_today_ok", { day: today_day });
            return true;
        }

        break;
    }

    throw Object.assign(new Error("EXAM_TODAY_UNAVAILABLE"), { code: "EXAM_TODAY_UNAVAILABLE" });
}

export async function select_job(page, value, timeout_ms = 10000)
{
    log("INFO", "select_job_start", { value });

    const search_sel = `input[placeholder="${SEL.job_search_placeholder}"]`;
    const wrapper_sel = 'div.relative.border-1.border-solid.rounded-lg.font-medium.flex';

    const wrapper = page.locator(wrapper_sel).first();
    const trigger = page.locator(SEL.job_trigger_sel).first();

    await wrapper.waitFor({ state: "visible", timeout: timeout_ms });
    await trigger.waitFor({ state: "visible", timeout: timeout_ms });

    let opened = false;

    for (let attempt = 1; attempt <= 3 && !opened; attempt++)
    {
        log("INFO", "select_job_open_try", { attempt });

        try
        {
            await wrapper.scrollIntoViewIfNeeded();
            await wrapper.click({ force: true, timeout: 1200 });
        }
        catch (e)
        {
            log("WARN", "select_job_wrapper_click_fail", { attempt, message: e.message });
        }

        opened = await page.locator(search_sel).first()
            .isVisible({ timeout: 1000 })
            .catch(() => false);

        if (opened)
        {
            log("INFO", "select_job_opened_by_wrapper", { attempt });
            break;
        }

        try
        {
            await trigger.scrollIntoViewIfNeeded();
            await trigger.click({ force: true, timeout: 1200 });
        }
        catch (e)
        {
            log("WARN", "select_job_trigger_click_fail", { attempt, message: e.message });
        }

        opened = await page.locator(search_sel).first()
            .isVisible({ timeout: 1000 })
            .catch(() => false);

        if (opened)
        {
            log("INFO", "select_job_opened_by_trigger", { attempt });
            break;
        }

        const js_open = await page.evaluate(({ wrapper_sel, trigger_sel, search_sel }) =>
        {
            const visible = (el) => !!(el && el.offsetParent);

            const search = document.querySelector(search_sel);
            if (visible(search)) return "already_open";

            const wrapper = [...document.querySelectorAll(wrapper_sel)].find(visible);
            const trigger = [...document.querySelectorAll(trigger_sel)].find(visible);

            const fire = (el) =>
            {
                if (!el) return false;
                el.scrollIntoView({ block: "center" });
                el.dispatchEvent(new MouseEvent("mousedown", { bubbles: true, cancelable: true }));
                el.dispatchEvent(new MouseEvent("mouseup", { bubbles: true, cancelable: true }));
                el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
                return true;
            };

            if (fire(wrapper)) return "wrapper";
            if (fire(trigger)) return "trigger";
            return "none";
        }, {
            wrapper_sel,
            trigger_sel: SEL.job_trigger_sel,
            search_sel
        }).catch(() => "none");

        log("INFO", "select_job_js_open_try", { attempt, js_open });

        opened = await page.locator(search_sel).first()
            .isVisible({ timeout: 1200 })
            .catch(() => false);

        if (!opened) await page.waitForTimeout(250);
    }

    if (!opened)
        throw Object.assign(new Error("JOB_SEARCH_NOT_VISIBLE"), { code: "JOB_SEARCH_NOT_VISIBLE" });

    let search_filled = false;

    for (let attempt = 1; attempt <= 4 && !search_filled; attempt++)
    {
        try
        {
            const input = page.locator(search_sel).first();
            await input.waitFor({ state: "visible", timeout: 1500 });

            const handle = await input.elementHandle();
            if (!handle) throw new Error("SEARCH_HANDLE_NULL");

            await handle.evaluate((el, val) =>
            {
                el.scrollIntoView({ block: "center" });
                el.focus();
                el.value = "";
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.value = val;
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.dispatchEvent(new Event("change", { bubbles: true }));
            }, String(value));

            await page.waitForTimeout(350);
            search_filled = true;
            log("INFO", "select_job_search_fill_ok", { attempt, value });
        }
        catch (e)
        {
            log("WARN", "select_job_search_fill_retry", {
                attempt,
                message: e.message
            });
            await page.waitForTimeout(250);
        }
    }

    if (!search_filled)
    {
        throw Object.assign(
            new Error(`JOB_SEARCH_FILL_FAILED: "${value}"`),
            { code: "JOB_SEARCH_FILL_FAILED" }
        );
    }

    const options_debug = await page.evaluate((option_sel) =>
    {
        const clean = (s) => (s || "").replace(/\s+/g, " ").trim();
        return [...document.querySelectorAll(option_sel)]
            .filter(el => el.offsetParent)
            .map(el => clean(el.textContent))
            .filter(Boolean)
            .slice(0, 50);
    }, SEL.job_option_sel).catch(() => []);

    log("INFO", "select_job_options_debug", {
        count: options_debug.length,
        options: options_debug
    });

    let clicked = false;

    for (let attempt = 1; attempt <= 4 && !clicked; attempt++)
    {
        clicked = await page.evaluate(({ option_sel, text_sel, target }) =>
        {
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();

            const btn = [...document.querySelectorAll(option_sel)]
                .filter(el => el.offsetParent)
                .find(el =>
                {
                    const inner = text_sel ? el.querySelector(text_sel) : null;
                    const txt = inner ? inner.textContent : el.textContent;
                    return norm(txt) === norm(target);
                });

            if (!btn) return false;

            btn.scrollIntoView({ block: "center" });
            btn.click();
            return true;
        }, {
            option_sel: SEL.job_option_sel,
            text_sel: SEL.job_option_text_sel,
            target: value
        }).catch(() => false);

        log("INFO", "select_job_click_try", { attempt, clicked });

        if (!clicked) await page.waitForTimeout(250);
    }

    if (!clicked)
    {
        throw Object.assign(
            new Error(`JOB_OPTION_NOT_FOUND: "${value}"`),
            { code: "JOB_OPTION_NOT_FOUND", options_debug }
        );
    }

    const selected_ok = await page.waitForFunction(({ trigger_sel, target }) =>
    {
        const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
        const trigger = document.querySelector(trigger_sel);
        return !!trigger && norm(trigger.textContent) === norm(target);
    }, {
        trigger_sel: SEL.job_trigger_sel,
        target: value
    }, {
        timeout: 3000,
        polling: 100
    }).then(() => true).catch(() => false);

    if (!selected_ok)
    {
        const trigger_text = await trigger.textContent().catch(() => "");
        log("ERROR", "select_job_verify_failed", {
            expected: value,
            actual: trigger_text
        });

        throw Object.assign(
            new Error(`JOB_NOT_SELECTED: "${value}"`),
            { code: "JOB_NOT_SELECTED" }
        );
    }

    log("INFO", "select_job_ok", { value });
    return true;
}

export async function select_domisili(page, domisili, timeout_ms = 10000)
{
    log("INFO", "select_domisili_start", domisili);

    const first_placeholder = SEL.domisili_steps[0].placeholder;

    const trigger = page.locator(SEL.domisili_trigger_sel)
        .filter({ hasText: new RegExp(SEL.domisili_trigger_text, "i") })
        .first();

    await trigger.waitFor({ state: "visible", timeout: timeout_ms });

    let opened = false;

    for (let attempt = 1; attempt <= 3 && !opened; attempt++)
    {
        try
        {
            await trigger.scrollIntoViewIfNeeded();
            await trigger.click({ force: true, timeout: 1200 });

            opened = await page.locator(`input[placeholder="${first_placeholder}"]`).first()
                .isVisible({ timeout: 1200 })
                .catch(() => false);

            if (opened)
            {
                log("INFO", "select_domisili_opened", { attempt });
                break;
            }
        }
        catch (e)
        {
            log("WARN", "select_domisili_open_retry", {
                attempt,
                message: e.message
            });
        }

        const js_open = await page.evaluate(({ trigger_sel, trigger_text, first_placeholder }) =>
        {
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
            const visible = (el) => !!(el && el.offsetParent);

            const search = document.querySelector(`input[placeholder="${first_placeholder}"]`);
            if (visible(search)) return true;

            const trigger = [...document.querySelectorAll(trigger_sel)]
                .find(el => visible(el) && norm(el.textContent).includes(norm(trigger_text)));

            if (!trigger) return false;

            trigger.scrollIntoView({ block: "center" });
            trigger.dispatchEvent(new MouseEvent("mousedown", { bubbles: true, cancelable: true }));
            trigger.dispatchEvent(new MouseEvent("mouseup", { bubbles: true, cancelable: true }));
            trigger.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
            return true;
        }, {
            trigger_sel: SEL.domisili_trigger_sel,
            trigger_text: SEL.domisili_trigger_text,
            first_placeholder
        }).catch(() => false);

        log("INFO", "select_domisili_js_open_try", { attempt, js_open });

        opened = await page.locator(`input[placeholder="${first_placeholder}"]`).first()
            .isVisible({ timeout: 1200 })
            .catch(() => false);

        if (!opened) await page.waitForTimeout(250);
    }

    if (!opened)
    {
        throw Object.assign(
            new Error("DOMISILI_SEARCH_NOT_VISIBLE"),
            { code: "DOMISILI_SEARCH_NOT_VISIBLE" }
        );
    }

    for (let i = 0; i < SEL.domisili_steps.length; i++)
    {
        const step_cfg = SEL.domisili_steps[i];
        const step_value = domisili[step_cfg.key];
        const input_sel = `input[placeholder="${step_cfg.placeholder}"]`;
        const next_placeholder = SEL.domisili_steps[i + 1]?.placeholder || null;

        log("INFO", "domisili_step", {
            order: i + 1,
            key: step_cfg.key,
            value: step_value
        });

        if (!step_value)
        {
            throw Object.assign(
                new Error(`DOMISILI_VALUE_EMPTY: ${step_cfg.key}`),
                { code: "DOM_VALUE_EMPTY" }
            );
        }

        await vue_type_with_retry(page, input_sel, step_value, 10000);

        const options_debug = await page.evaluate((option_sel) =>
        {
            const clean = (s) => (s || "").replace(/\s+/g, " ").trim();
            return [...document.querySelectorAll(option_sel)]
                .filter(el => el.offsetParent)
                .map(el => clean(el.textContent))
                .filter(Boolean)
                .slice(0, 30);
        }, SEL.domisili_option_sel).catch(() => []);

        log("INFO", "domisili_options_debug", {
            key: step_cfg.key,
            count: options_debug.length,
            options: options_debug
        });

        await click_exact_option_with_retry(
            page,
            SEL.domisili_option_sel,
            SEL.domisili_option_text_sel,
            step_value,
            10000
        );

        if (next_placeholder)
            await wait_next_step_input(page, next_placeholder, 10000);
        else
            await page.waitForTimeout(300);

        log("INFO", "domisili_step_selected", {
            order: i + 1,
            key: step_cfg.key,
            value: step_value
        });
    }

    log("INFO", "select_domisili_ok", domisili);
    return true;
}

function normalize_simple_text(value)
{
    return norm_text(value).replace(/[^a-z0-9]+/g, "");
}

function map_status_pernikahan_option(value)
{
    const lookup = normalize_simple_text(value);
    const status_map = [
        { label: "Cerai Mati", tokens: ["ceraimati", "jandamati", "dudamati"] },
        { label: "Cerai Hidup", tokens: ["ceraihidup", "cerai"] },
        { label: "Belum Menikah", tokens: ["belummenikah", "belumkawin", "tidakkawin", "single"] },
        { label: "Menikah", tokens: ["menikah", "kawin", "nikah"] }
    ];

    for (const status_item of status_map)
    {
        if (status_item.tokens.some(token => lookup.includes(token)))
            return status_item.label;
    }

    return String(value || "").trim();
}

function map_disabilitas_option(value)
{
    const lookup = normalize_simple_text(value);
    if (!lookup)
        return "";
    if (["tidak", "none", "normal", "sehat"].some(token => lookup.includes(token)))
        return "Tidak memiliki disabilitas";
    if (["disabil", "difabel", "cacat", "ya", "memiliki"].some(token => lookup.includes(token)))
        return "Memiliki disablilitas";
    return String(value || "").trim();
}

async function open_simple_dropdown(page, trigger_sel, trigger_text, option_sel, timeout_ms = 10000)
{
    const trigger_tokens = (Array.isArray(trigger_text) ? trigger_text : [trigger_text]).map(norm_text);
    let opened = false;

    for (let attempt = 1; attempt <= 3 && !opened; attempt++)
    {
        const clicked = await page.evaluate(({ trigger_sel, trigger_tokens }) =>
        {
            const visible = (el) => !!(el && el.offsetParent);
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
            const trigger = [...document.querySelectorAll(trigger_sel)]
                .find(el =>
                    visible(el) &&
                    trigger_tokens.some(token => norm(el.textContent).includes(token))
                );
            if (!trigger) return false;

            trigger.scrollIntoView({ block: "center" });
            trigger.dispatchEvent(new MouseEvent("mousedown", { bubbles: true, cancelable: true }));
            trigger.dispatchEvent(new MouseEvent("mouseup", { bubbles: true, cancelable: true }));
            trigger.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
            return true;
        }, {
            trigger_sel,
            trigger_tokens
        }).catch(() => false);

        if (!clicked)
        {
            await page.waitForTimeout(250);
            continue;
        }

        opened = await page.waitForFunction((sel) =>
        {
            const visible = (el) => !!(el && el.offsetParent);
            return [...document.querySelectorAll(sel)].some(visible);
        }, option_sel, {
            timeout: Math.min(timeout_ms, 2000)
        }).then(() => true).catch(() => false);

        if (!opened)
            await page.waitForTimeout(250);
    }

    if (!opened)
        throw Object.assign(new Error("SIMPLE_DROPDOWN_NOT_OPEN"), { code: "SIMPLE_DROPDOWN_NOT_OPEN" });

    return true;
}

async function click_simple_dropdown_option(page, option_sel, value, timeout_ms = 10000)
{
    const target = String(value || "").trim();
    if (!target)
        return true;

    const clicked = await page.waitForFunction(({ option_sel, target }) =>
    {
        const visible = (el) => !!(el && el.offsetParent);
        const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
        const normalize = (s) => norm(s).replace(/[^a-z0-9]+/g, "");
        const options = [...document.querySelectorAll(option_sel)].filter(visible);
        if (!options.length) return false;

        const target_norm = norm(target);
        const target_simple = normalize(target);

        let option = options.find(el => norm(el.textContent) === target_norm);
        if (!option)
            option = options.find(el => normalize(el.textContent) === target_simple);
        if (!option)
            option = options.find(el => norm(el.textContent).includes(target_norm) || target_norm.includes(norm(el.textContent)));
        if (!option) return false;

        option.scrollIntoView({ block: "center" });
        option.dispatchEvent(new MouseEvent("mousedown", { bubbles: true, cancelable: true }));
        option.dispatchEvent(new MouseEvent("mouseup", { bubbles: true, cancelable: true }));
        option.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
        return true;
    }, {
        option_sel,
        target
    }, {
        timeout: timeout_ms
    }).then(() => true).catch(() => false);

    if (!clicked)
        throw Object.assign(new Error(`SIMPLE_OPTION_NOT_FOUND: "${target}"`), { code: "SIMPLE_OPTION_NOT_FOUND" });

    return true;
}

export async function select_status_pernikahan(page, value, timeout_ms = 10000)
{
    const target = map_status_pernikahan_option(value);
    if (!target)
        return true;

    log("INFO", "select_status_pernikahan_start", { value: target });
    await open_simple_dropdown(
        page,
        SEL.status_pernikahan_trigger_sel,
        SEL.status_pernikahan_trigger_text,
        SEL.status_pernikahan_option_sel,
        timeout_ms
    );
    await click_simple_dropdown_option(page, SEL.status_pernikahan_option_sel, target, timeout_ms);
    log("INFO", "select_status_pernikahan_ok", { value: target });
    return true;
}

export async function select_penyandang_disabilitas(page, value, timeout_ms = 10000)
{
    const target = map_disabilitas_option(value);
    if (!target)
        return true;

    log("INFO", "select_penyandang_disabilitas_start", { value: target });
    await open_simple_dropdown(
        page,
        SEL.disabilitas_trigger_sel,
        SEL.disabilitas_trigger_text,
        SEL.disabilitas_option_sel,
        timeout_ms
    );
    await click_simple_dropdown_option(page, SEL.disabilitas_option_sel, target, timeout_ms);
    log("INFO", "select_penyandang_disabilitas_ok", { value: target });
    return true;
}

export async function fill_detail_address(page, value, timeout_ms)
{
    await fill_input(page, SEL.detail_address, value, "DETAIL_ADDRESS", timeout_ms);
}

export async function fill_wali_inline(page, wali_data, timeout_ms)
{
    log("INFO", "fill_wali_inline_start");

    const wali_section = page.locator("div").filter({
        has: page.locator(SEL.wali_nik.join(", "))
    }).first();

    if (!await wali_section.isVisible({ timeout: 800 }).catch(() => false))
    {
        log("INFO", "wali_inline_not_found");
        return { skipped: true, type: "no_wali_form" };
    }

    const has_no_wali = await page.evaluate(() =>
    {
        return [...document.querySelectorAll('input[type="checkbox"]')]
            .some(cb =>
            {
                const label = (cb.name || cb.id || "").toLowerCase();
                return label.includes("nowali") || label.includes("no_wali") || label.includes("tidak ada wali");
            });
    });

    if (has_no_wali)
    {
        await page.evaluate(() =>
        {
            const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
            const cb = [...document.querySelectorAll('input[type="checkbox"]')]
                .find(el =>
                {
                    const label = norm(el.name || el.id || "");
                    return label.includes("nowali") || label.includes("no_wali");
                });

            if (!cb) return;

            const check_div = [...document.querySelectorAll(`#${cb.id}`)]
                .find(x => x !== cb && x.classList.contains("check"));

            const target = check_div || cb;
            target.scrollIntoView({ block: "center" });
            target.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));

            if (!cb.checked) cb.click();
            cb.dispatchEvent(new Event("change", { bubbles: true }));
        });

        log("INFO", "wali_inline_no_wali_checked");
        return { skipped: false, type: "no_wali_checkbox" };
    }

    if (!wali_data || !wali_data.nik)
    {
        log("INFO", "wali_inline_no_data_skip");
        return { skipped: true, type: "no_wali_data" };
    }

    await page.evaluate(({ nik, nama, sel_nik, sel_nama }) =>
    {
        const set_val = (input, val) =>
        {
            if (!input) return;
            input.focus();
            input.value = val;
            input.dispatchEvent(new Event("input", { bubbles: true }));
            input.dispatchEvent(new Event("change", { bubbles: true }));
            input.blur();
        };

        const find = (sels) => sels.map(s => document.querySelector(s)).find(Boolean);
        set_val(find(sel_nik), nik);
        set_val(find(sel_nama), nama);
    }, { nik: wali_data.nik, nama: wali_data.nama, sel_nik: SEL.wali_nik, sel_nama: SEL.wali_nama });

    if (wali_data.tanggal_lahir)
        await select_birth_date(page, wali_data.tanggal_lahir, timeout_ms, wali_section);

    if (wali_data.jenis_kelamin)
    {
        let gender_done = false;

        for (let attempt = 0; attempt < 3 && !gender_done; attempt++)
        {
            const opened = await page.evaluate((target) =>
            {
                const norm = (s) => (s || "").toLowerCase().trim();
                const trigger = [...document.querySelectorAll("div.cursor-pointer")]
                    .find(el => norm(el.innerText).includes("pilih jenis kelamin"));

                if (!trigger) return "not_found";
                if (norm(trigger.innerText) === norm(target)) return "already_selected";

                trigger.click();
                return "opened";
            }, wali_data.jenis_kelamin);

            if (opened === "not_found")
                throw Object.assign(new Error("GENDER_WALI_TRIGGER: not found"), { code: "GENDER_WALI_TRIGGER" });

            if (opened === "already_selected")
            {
                gender_done = true;
                break;
            }

            await page.waitForTimeout(400);

            const found = await page.waitForFunction((target) =>
            {
                const norm = (s) => (s || "").toLowerCase().trim();
                const opt = [...document.querySelectorAll("div.py-2.px-4.cursor-pointer")]
                    .find(el => norm(el.innerText) === norm(target));

                if (!opt) return null;

                opt.scrollIntoView({ block: "center" });
                opt.click();
                return true;
            }, wali_data.jenis_kelamin, { timeout: 3000 }).catch(() => null);

            if (found)
            {
                gender_done = true;
                break;
            }

            await page.waitForTimeout(300);
        }

        if (!gender_done)
            throw Object.assign(new Error(`GENDER_WALI_OPTION: "${wali_data.jenis_kelamin}"`), { code: "GENDER_WALI_OPTION" });

        log("INFO", "wali_gender_ok", { value: wali_data.jenis_kelamin });
    }

    await page.evaluate(() =>
    {
        const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
        const cb = [...document.querySelectorAll('input[type="checkbox"]')]
            .find(el => norm(el.name || "").includes("nomor sama") || norm(el.id || "").includes("phone-sama"));

        if (!cb || cb.checked) return;

        const cd = [...document.querySelectorAll("#phone-sama")]
            .find(x => x.classList.contains("check"));

        cd ? cd.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true })) : cb.click();
    });

    log("INFO", "wali_inline_done");
    return { skipped: false, type: "full_wali_form" };
}
