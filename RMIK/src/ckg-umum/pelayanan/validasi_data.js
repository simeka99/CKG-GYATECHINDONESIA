import { sel } from "./selector_config.js";
import { find_first_visible, is_visible, retry_step, run_async_fallbacks, safe_wait } from "./helper.js";
import { log } from "../../core/helpers.js";

function normalize_nik(value)
{
    return String(value || "").replace(/[^0-9]/g, "").slice(0, 16);
}

function is_digit_only(value)
{
    return /^[0-9]+$/.test(String(value || ""));
}

function is_valid_nik(value)
{
    return String(value || "").length === 16;
}

async function type_nik_strict(page, nik_input, nik_value)
{
    const typed = String(nik_value || "");

    await nik_input.click({ force: true }).catch(() => { });
    await nik_input.focus().catch(() => { });
    await nik_input.press("ControlOrMeta+a").catch(() => { });
    await nik_input.press("Backspace").catch(() => { });

    for (const digit of typed)
        await page.keyboard.press(digit).catch(() => { });

    await safe_wait(page, 80);

    const current_value = normalize_nik(await nik_input.inputValue().catch(() => ""));
    if (current_value !== typed)
    {
        await nik_input.fill("").catch(() => { });
        await nik_input.type(typed, { delay: 0 }).catch(async () =>
        {
            await nik_input.fill(typed).catch(() => { });
        });
    }

    await safe_wait(page, 80);
}

async function submit_nik_search(page, nik_input, nik_value, timeout_ms)
{
    const attempt_submit = async () =>
    {
        await nik_input.focus().catch(() => { });
        await page.keyboard.press("Enter").catch(() => { });
        await nik_input.press("Enter").catch(() => { });
    };

    const has_search_signal = async () =>
    {
        const result_row = page.locator("table tbody tr, [class*='row'], [class*='list']")
            .filter({ hasText: new RegExp(String(nik_value), "i") })
            .first();
        if (await result_row.isVisible({ timeout: 400 }).catch(() => false))
            return true;

        const meaningful_row_exists = await page.evaluate(() =>
        {
            const normalize = (value) => String(value || "").replace(/\s+/g, " ").trim().toLowerCase();
            const rows = Array.from(document.querySelectorAll(".table-individu-terdaftar table tbody tr"));
            for (const row of rows)
            {
                const row_text = normalize(row.textContent || "");
                if (row_text === "")
                    continue;
                if (row_text.includes("lakukan pencarian"))
                    continue;
                if (row_text.includes("tidak ditemukan"))
                    continue;
                if (row.querySelector("button, a, [role='button']"))
                    return true;
                if (row.querySelectorAll("td").length >= 8)
                    return true;
            }
            return false;
        }).catch(() => false);
        if (meaningful_row_exists)
            return true;

        const not_found = page.locator("div,span,p")
            .filter({ hasText: /tidak ditemukan|pastikan nama sesuai ktp|nik\/no. tiket/i })
            .first();
        if (await not_found.isVisible({ timeout: 400 }).catch(() => false))
            return true;

        return false;
    };

    const max_deadline = Date.now() + Math.max(3000, Math.min(Number(timeout_ms) || 0, 12000));
    while (Date.now() < max_deadline)
    {
        await attempt_submit();
        await safe_wait(page, 450);
        if (await has_search_signal())
            return true;
    }

    return false;
}

async function pick_filter_by_nik(page)
{
    const get_filter_label_text = async () =>
    {
        const label = page.locator(sel.search_filter_trigger_selector)
            .locator(sel.search_filter_label_selector)
            .first();
        if (!await is_visible(label, 300))
            return "";

        const text = await label.textContent().catch(() => "");
        return String(text || "").trim().toLowerCase();
    };

    if ((await get_filter_label_text()) === "nik")
        return true;

    const filter_trigger_candidates = [
        page.locator(sel.search_filter_trigger_selector).filter({ hasText: sel.search_filter_label_pattern }).first(),
        page.locator(sel.search_filter_trigger_selector).filter({ hasText: /^\s*(nama|nik|nomor tiket|mitra)\s*$/i }).first(),
        page.locator(sel.search_filter_trigger_selector).first()
    ];
    const filter_trigger = await find_first_visible(filter_trigger_candidates, sel.wait.element_visible_ms);
    if (!filter_trigger)
        return false;

    await filter_trigger.scrollIntoViewIfNeeded().catch(() => { });
    const opened = await run_async_fallbacks([
        () => filter_trigger.click({ timeout: sel.wait.click_timeout_ms }),
        () => filter_trigger.click({ force: true, timeout: sel.wait.click_timeout_ms })
    ]);
    if (!opened)
        return false;

    const nik_option = page.locator(sel.search_filter_option_selector)
        .filter({ hasText: sel.search_filter_option_nik_pattern })
        .first();
    if (!await is_visible(nik_option, sel.wait.element_visible_ms))
        return false;

    const clicked = await run_async_fallbacks([
        () => nik_option.click({ timeout: sel.wait.click_timeout_ms }),
        () => nik_option.click({ force: true, timeout: sel.wait.click_timeout_ms })
    ]);
    if (!clicked)
        return false;

    await safe_wait(page, 250);
    return (await get_filter_label_text()) === "nik";
}

async function find_first_nik_from_page(page)
{
    return await page.evaluate(({ peserta_panel_pattern, nik_text_pattern_source, nik_text_pattern_flags }) =>
    {
        const visible = (element) =>
        {
            if (!element) return false;
            const style = window.getComputedStyle(element);
            if (style.visibility === "hidden" || style.display === "none") return false;
            return element.offsetParent !== null;
        };

        const panel_regex = new RegExp(peserta_panel_pattern, "i");
        const nik_regex = new RegExp(nik_text_pattern_source, nik_text_pattern_flags);
        const panel_nodes = Array.from(document.querySelectorAll("div,section,article"))
            .filter((node) => visible(node) && panel_regex.test((node.textContent || "").trim()));

        for (const panel of panel_nodes)
        {
            const text = String(panel.innerText || panel.textContent || "");
            const matches = text.match(nik_regex);
            if (matches && matches.length > 0)
            {
                const nik = String(matches[0] || "").replace(/\D+/g, "");
                if (nik.length === 16)
                    return nik;
            }
        }

        const body_text = String(document.body?.innerText || "");
        const fallback_matches = body_text.match(nik_regex);
        if (!fallback_matches || fallback_matches.length === 0)
            return "";

        for (const raw of fallback_matches)
        {
            const nik = String(raw || "").replace(/\D+/g, "");
            if (nik.length === 16)
                return nik;
        }

        return "";
    }, {
        peserta_panel_pattern: sel.peserta_panel_pattern.source,
        nik_text_pattern_source: sel.nik_text_pattern.source,
        nik_text_pattern_flags: sel.nik_text_pattern.flags
    }).catch(() => "");
}

export async function search_peserta_by_nik(page, timeout_ms, nik_value = "")
{
    await switch_status_tab(page, sel.status_tab_sedang_pemeriksaan_pattern).catch(() => false);
    await safe_wait(page, 300);

    const input_nik = normalize_nik(nik_value);
    const list_nik = normalize_nik(await find_first_nik_from_page(page));
    const resolved_nik = input_nik || list_nik;
    if (!is_valid_nik(resolved_nik))
        throw new Error("NIK peserta tidak ditemukan dari daftar");

    const selected = await retry_step(page, "pick_filter_by_nik", sel.max_try.retry_step, async () =>
    {
        const ok = await pick_filter_by_nik(page);
        if (!ok) throw new Error("Filter NIK belum bisa dipilih");
        return true;
    }).catch(() => false);
    if (!selected)
        throw new Error("Filter pencarian NIK tidak ditemukan");

    const nik_input = await find_first_visible(
        sel.search_input_nik_selectors.map((selector) => page.locator(selector).first()),
        sel.wait.element_visible_ms
    );
    if (!nik_input)
        throw new Error("Input pencarian NIK tidak ditemukan");

    await nik_input.scrollIntoViewIfNeeded().catch(() => { });
    await type_nik_strict(page, nik_input, resolved_nik);

    const nik_number_error = page.locator("div,span,p")
        .filter({ hasText: /nik hanya bisa angka/i })
        .first();
    if (await nik_number_error.isVisible({ timeout: 250 }).catch(() => false))
        await type_nik_strict(page, nik_input, resolved_nik);

    const typed_value = await nik_input.inputValue().catch(() => resolved_nik);
    const safe_value = normalize_nik(typed_value);
    if (safe_value !== typed_value)
        await type_nik_strict(page, nik_input, safe_value);
    if (!is_valid_nik(safe_value) || !is_digit_only(safe_value))
        throw new Error("NIK pencarian tidak valid setelah input");

    const confirmed_value = await nik_input.inputValue().catch(() => safe_value);
    if (confirmed_value !== safe_value || !is_digit_only(confirmed_value))
        throw new Error("Input NIK mengandung karakter non-digit");

    const submitted = await submit_nik_search(page, nik_input, safe_value, timeout_ms);
    if (!submitted)
        throw new Error("Pencarian NIK tidak terpicu setelah Enter");

    await page.waitForLoadState("domcontentloaded", { timeout: timeout_ms }).catch(() => { });

    log("INFO", "search_peserta_by_nik_done", { nik: safe_value });
    return safe_value;
}

function normalize_status_text(value)
{
    return String(value || "").toLowerCase().replace(/\s+/g, " ").trim();
}

async function switch_status_tab(page, tab_pattern)
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
}

async function inspect_first_row_status(page)
{
    return await page.evaluate(() =>
    {
        const normalize = (value) => String(value || "").toLowerCase().replace(/\s+/g, " ").trim();
        const clean = (value) => String(value || "").replace(/\s+/g, " ").trim();
        const table = document.querySelector(".table-individu-terdaftar table");
        if (!table)
            return { found: false, reason: "table_not_found" };

        const headers = Array.from(table.querySelectorAll("thead th"))
            .map((header) => normalize(header.textContent));
        const rows = Array.from(table.querySelectorAll("tbody tr"))
            .filter((row) => row instanceof HTMLElement && row.offsetParent !== null);
        if (!rows.length)
            return { found: false, reason: "row_not_found" };

        for (const row of rows)
        {
            const row_cells = Array.from(row.querySelectorAll("td"))
                .map((cell) => clean(cell.textContent));
            const row_text = normalize(row.textContent || "");
            const is_placeholder_row =
                row_text.includes("tidak ditemukan") ||
                row_text.includes("lakukan pencarian") ||
                row_text.includes("pastikan nama sesuai ktp");
            if (is_placeholder_row)
                continue;

            const header_map = {};
            headers.forEach((header, index) =>
            {
                header_map[header] = row_cells[index] || "";
            });

            const name = header_map["nama"] || "";
            const pemeriksaan_mandiri = header_map["pemeriksaan mandiri"] || "";
            const pelayanan = header_map["pelayanan"] || "";
            const visible_cell_count = row_cells.filter((value) => value !== "").length;
            const has_mulai_button = Array.from(row.querySelectorAll("button"))
                .some((button) => normalize(button.textContent).includes("mulai"));
            const has_status_data = pemeriksaan_mandiri !== "" || pelayanan !== "";
            const has_core_data = name !== "" || has_status_data || has_mulai_button || visible_cell_count >= 4;

            if (!has_core_data)
                continue;

            return {
                found: true,
                name,
                pemeriksaan_mandiri,
                pelayanan
            };
        }

        return { found: false, reason: "row_empty_or_placeholder" };
    }).catch(() => ({ found: false, reason: "evaluate_failed" }));
}

export async function inspect_peserta_status_after_search(page, nik_value = "")
{
    const tab_sequence = [
        {
            key: "sedang_pemeriksaan",
            pattern: sel.status_tab_sedang_pemeriksaan_pattern
        },
        {
            key: "belum_pemeriksaan",
            pattern: sel.status_tab_belum_pemeriksaan_pattern
        },
        {
            key: "selesai_pemeriksaan",
            pattern: sel.status_tab_selesai_pemeriksaan_pattern
        }
    ];

    let inspected = { found: false, reason: "row_not_found" };
    let found_tab = "";

    for (let loop_index = 0; loop_index < 3; loop_index += 1)
    {
        for (const tab of tab_sequence)
        {
            await switch_status_tab(page, tab.pattern).catch(() => false);
            await safe_wait(page, Math.max(250, sel.wait.after_tab_switch_ms));
            const tab_result = await inspect_first_row_status(page);
            if (!tab_result?.found)
                continue;

            inspected = tab_result;
            found_tab = tab.key;
            break;
        }

        if (inspected?.found)
            break;
        await safe_wait(page, 300);
    }

    if (!inspected?.found)
        return {
            found: false,
            nik: String(nik_value || ""),
            should_process: true,
            should_skip: false,
            reason: inspected?.reason || "unknown",
            status_tab: found_tab || "not_found"
        };

    const mandiri_status = normalize_status_text(inspected.pemeriksaan_mandiri);
    const pelayanan_status = normalize_status_text(inspected.pelayanan);
    const is_mandiri_complete = mandiri_status === "lengkap";
    const is_pelayanan_complete = pelayanan_status === "selesai pemeriksaan";
    const should_skip = is_mandiri_complete && is_pelayanan_complete;

    return {
        found: true,
        nik: String(nik_value || ""),
        name: String(inspected.name || ""),
        pemeriksaan_mandiri: inspected.pemeriksaan_mandiri,
        pelayanan: inspected.pelayanan,
        status_tab: found_tab || "unknown",
        should_skip,
        should_process: !should_skip
    };
}
