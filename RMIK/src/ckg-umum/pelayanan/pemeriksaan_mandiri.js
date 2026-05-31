import fs from "fs";
import path from "path";
import { log } from "../../core/helpers.js";
import { worker_state } from "../../api/state.js";
import { sel } from "./selector_config.js";
import { collect_pelayanan_nakes } from "./pelayanan_nakes.js";
import { find_first_visible, is_visible, retry_step, run_async_fallbacks, safe_wait, wait_page_stable, is_page_alive, safe_goto } from "./helper.js";
import
{
    find_exam_date_confirmation_modal,
    find_start_examination_button,
    find_examination_date_button,
    find_send_report_button,
    find_modal_by_title,
    click_button_inside_modal,
    close_send_report_limit_modal_if_visible,
    close_processing_in_progress_modal_if_visible,
    dismiss_processing_modal,
    click_send_report_modal_confirm,
    click_finish_service_modal_confirm,
    click_send_report_button_once,
    click_finish_service_button_required
} from "./popup_handler.js";

function get_today_date_number()
{
    return Number(new Date().getDate());
}

function sanitize_file_part(value)
{
    return String(value || "")
        .replace(/[^a-z0-9_-]+/gi, "-")
        .replace(/-+/g, "-")
        .replace(/^-|-$/g, "")
        .toLowerCase();
}

function resolve_snapshot_output_dir()
{
    const from_env = String(process.env.DEV_DEBUG_DIR || "").trim();
    if (from_env !== "")
        return path.resolve(process.cwd(), from_env);
    return path.resolve(process.cwd(), "artifacts", "debug");
}

function should_save_web_question_snapshot()
{
    const save_artifacts = String(process.env.DEV_SAVE_ARTIFACTS || "").trim().toLowerCase();
    const explicit_flag = String(process.env.SAVE_WEB_QUESTION_SNAPSHOT || "").trim().toLowerCase();
    return save_artifacts === "true" || explicit_flag === "1" || explicit_flag === "true";
}

function resolve_boolean_option(options, option_key, env_key, fallback_value = false)
{
    if (typeof options?.[option_key] === "boolean")
        return options[option_key];

    if (env_key)
    {
        const env_value = String(process.env[env_key] || "").trim().toLowerCase();
        if (env_value === "true")
            return true;
        if (env_value === "false")
            return false;
    }

    return fallback_value;
}

function resolve_number_option(options, option_key, env_key, fallback_value = 0)
{
    const option_value = options?.[option_key];
    if (option_value !== undefined && option_value !== null && String(option_value).trim() !== "")
    {
        const parsed_option_value = Number(option_value);
        if (Number.isFinite(parsed_option_value))
            return parsed_option_value;
    }

    if (env_key)
    {
        const env_value = String(process.env[env_key] || "").trim();
        if (env_value !== "")
        {
            const parsed_env_value = Number(env_value);
            if (Number.isFinite(parsed_env_value))
                return parsed_env_value;
        }
    }

    return fallback_value;
}

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

function build_web_question_snapshot_payload(flow_result, options = {})
{
    const form_results = Array.isArray(flow_result?.pemeriksaan_mandiri_form_list?.results)
        ? flow_result.pemeriksaan_mandiri_form_list.results
        : [];
    const nakes_form_results = Array.isArray(flow_result?.pelayanan_nakes_form_list?.results)
        ? flow_result.pelayanan_nakes_form_list.results
        : [];

    const forms = form_results.map((item, index) => ({
        urutan: index + 1,
        layanan: String(item?.layanan || ""),
        form_title: String(item?.form_title || ""),
        row_id: String(item?.row_id || ""),
        status: String(item?.status || ""),
        submit_done: String(item?.submit_done || ""),
        before: item?.question_data_before || { items: [] },
        after_fill: item?.question_data_after_fill || { items: [] },
        final: item?.question_data || { items: [] }
    }));
    const nakes_forms = nakes_form_results.map((item, index) => ({
        urutan: index + 1,
        kategori: String(item?.kategori || ""),
        layanan: String(item?.layanan || ""),
        form_title: String(item?.form_title || ""),
        row_id: String(item?.row_id || ""),
        status: String(item?.status || ""),
        submit_done: String(item?.submit_done || ""),
        before: item?.question_data_before || { items: [] },
        after_fill: item?.question_data_after_fill || { items: [] },
        final: item?.question_data || { items: [] }
    }));

    return {
        generated_at: new Date().toISOString(),
        patient_data: {
            nik: String(options?.patient_data?.nik || ""),
            nama: String(options?.patient_data?.nama || "")
        },
        pemeriksaan_mandiri_payload_meta: {
            batch_key: String(flow_result?.pemeriksaan_mandiri_payload?.batch_key || ""),
            package_key: String(flow_result?.pemeriksaan_mandiri_payload?.package_key || ""),
            total_jenis_pemeriksaan: Number(flow_result?.pemeriksaan_mandiri_payload?.total_jenis_pemeriksaan || 0),
            total_pertanyaan: Number(flow_result?.pemeriksaan_mandiri_payload?.total_pertanyaan || 0)
        },
        pemeriksaan_nakes_payload_meta: {
            batch_key: String(flow_result?.pemeriksaan_nakes_payload?.batch_key || ""),
            package_key: String(flow_result?.pemeriksaan_nakes_payload?.package_key || ""),
            total_jenis_pemeriksaan: Number(flow_result?.pemeriksaan_nakes_payload?.total_jenis_pemeriksaan || 0),
            total_pertanyaan: Number(flow_result?.pemeriksaan_nakes_payload?.total_pertanyaan || 0)
        },
        status_table: flow_result?.pemeriksaan_mandiri_status || {},
        form_summary: flow_result?.pemeriksaan_mandiri_form_list || {},
        forms,
        pelayanan_nakes_summary: flow_result?.pelayanan_nakes || {},
        pelayanan_nakes_form_summary: flow_result?.pelayanan_nakes_form_list || {},
        pelayanan_nakes_forms: nakes_forms
    };
}

function save_web_question_snapshot(flow_result, options = {})
{
    if (!should_save_web_question_snapshot())
        return "";

    const output_dir = resolve_snapshot_output_dir();
    fs.mkdirSync(output_dir, { recursive: true });

    const fixed_file_from_env = String(process.env.DEV_WEB_QUESTION_SNAPSHOT_FILE || "").trim();
    const output_path = fixed_file_from_env !== ""
        ? path.resolve(process.cwd(), fixed_file_from_env)
        : (() =>
        {
            const now = new Date();
            const pad = (value) => String(value).padStart(2, "0");
            const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
            const nik = sanitize_file_part(options?.patient_data?.nik || "unknown");
            const file_name = `web_question_snapshot_${stamp}_${nik}.json`;
            return path.join(output_dir, file_name);
        })();
    fs.mkdirSync(path.dirname(output_path), { recursive: true });
    const payload = build_web_question_snapshot_payload(flow_result, options);
    fs.writeFileSync(output_path, JSON.stringify(payload, null, 2) + "\n", "utf-8");
    return output_path;
}


async function is_detail_examination_page(page)
{
    const in_detail_url = String(page.url() || "").includes("/detail-pemeriksaan");
    if (in_detail_url)
        return true;

    const finish_service_button = page.getByRole("button", { name: sel.finish_service_button_pattern }).first();
    if (await is_visible(finish_service_button, 300))
        return true;

    const start_treatment_button = page.getByRole("button", { name: sel.start_treatment_button_pattern }).first()
    return await is_visible(start_treatment_button, 300);
}

async function click_start_examination_button_if_available(page, timeout_ms)
{
    return await retry_step(page, "click_start_examination_button_if_available", sel.max_try.retry_step, async () =>
    {
        const max_wait_ms = Math.max(2500, Math.min(timeout_ms, 12000));
        const deadline = Date.now() + max_wait_ms;
        let start_examination_button = null;

        while (Date.now() < deadline)
        {
            start_examination_button = await find_start_examination_button(page);
            if (start_examination_button)
                break;

            const exam_date_confirmation_modal = await find_exam_date_confirmation_modal(page);
            if (exam_date_confirmation_modal)
                return false;

            const in_detail_page = await is_detail_examination_page(page);
            if (in_detail_page)
            {
                const table_ready = await wait_pemeriksaan_mandiri_table_ready(page, 900).catch(() => false);
                if (table_ready)
                    return false;
            }

            await safe_wait(page, 250);
        }

        if (!start_examination_button)
        {
            if (await is_detail_examination_page(page))
                return false;
            throw new Error("Tombol Mulai Pemeriksaan tidak ditemukan");
        }

        await start_examination_button.scrollIntoViewIfNeeded().catch(() => { });
        const clicked = await run_async_fallbacks([
            () => start_examination_button.click({ timeout: sel.wait.click_timeout_ms }),
            () => start_examination_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
        ]);
        if (!clicked)
            throw new Error("Klik Mulai Pemeriksaan gagal");

        await wait_page_stable(page, timeout_ms);
        await safe_wait(page, sel.wait.after_start_examination_ms);
        return clicked;
    }).catch(async () =>
    {
        if (await is_detail_examination_page(page))
            return false;
        throw new Error("Tombol Mulai Pemeriksaan tidak ditemukan");
    });
}

async function click_start_examination_button_required(page, timeout_ms)
{
    return await retry_step(page, "click_start_examination_button_required", sel.max_try.retry_step, async () =>
    {
        const max_wait_ms = Math.max(5000, Math.min(timeout_ms, 15000));
        const deadline = Date.now() + max_wait_ms;
        let start_examination_button = null;

        while (Date.now() < deadline)
        {
            start_examination_button = await find_start_examination_button(page);
            if (start_examination_button)
                break;

            await safe_wait(page, 250);
        }

        if (!start_examination_button)
            throw new Error("Tombol Mulai Pemeriksaan wajib klik, tapi tidak ditemukan");

        await start_examination_button.scrollIntoViewIfNeeded().catch(() => { });
        const clicked = await run_async_fallbacks([
            () => start_examination_button.click({ timeout: sel.wait.click_timeout_ms }),
            () => start_examination_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
        ]);
        if (!clicked)
            throw new Error("Klik tombol Mulai Pemeriksaan gagal");

        await wait_page_stable(page, timeout_ms);
        await safe_wait(page, sel.wait.after_start_examination_ms);
        return true;
    });
}

async function click_examination_date_button_if_available(page, timeout_ms)
{
    const examination_date_button = await find_examination_date_button(page);
    if (!examination_date_button)
        return false;

    await examination_date_button.scrollIntoViewIfNeeded().catch(() => { });
    const clicked = await run_async_fallbacks([
        () => examination_date_button.click({ timeout: sel.wait.click_timeout_ms }),
        () => examination_date_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
    ]);
    if (!clicked)
        return false;

    await page.waitForLoadState("domcontentloaded", { timeout: timeout_ms }).catch(() => { });
    await safe_wait(page, sel.wait.short_delay_ms);
    return true;
}

async function pick_today_date_in_calendar(modal, today_date_number)
{
    return await modal.evaluate((root, date_number) =>
    {
        const normalize = (value) => String(value || "").replace(/\s+/g, " ").trim();
        const buttons = Array.from(root.querySelectorAll("button"));
        const is_enabled_button = (button) =>
            !button.disabled &&
            !button.classList.contains("cursor-not-allowed") &&
            button.getAttribute("aria-disabled") !== "true";
        const get_day_text = (button) =>
        {
            const span = button.querySelector("span.font-bold");
            return normalize(span?.textContent || button.textContent || "");
        };

        const target_date_text = String(date_number);
        const today_date_button = buttons.find((button) =>
            is_enabled_button(button) &&
            get_day_text(button) === target_date_text &&
            /^[0-9]{1,2}$/.test(get_day_text(button))
        );
        if (today_date_button)
        {
            today_date_button.click();
            return true;
        }

        const selected_date_button = buttons.find((button) =>
            button.classList.contains("bg-theme") && /^[0-9]{1,2}$/.test(get_day_text(button))
        );
        if (selected_date_button)
            return true;

        return false;
    }, today_date_number).catch(() => false);
}

async function click_exam_date_save_button(modal)
{
    const save_button = await find_first_visible([
        modal.getByRole("button", { name: sel.exam_date_save_button_pattern }).first(),
        modal.locator("button").filter({ hasText: sel.exam_date_save_button_pattern }).first()
    ], sel.wait.element_visible_ms);
    if (!save_button)
        throw new Error("Tombol Simpan konfirmasi tanggal tidak ditemukan");

    await save_button.scrollIntoViewIfNeeded().catch(() => { });
    const clicked = await run_async_fallbacks([
        () => save_button.click({ timeout: sel.wait.click_timeout_ms }),
        () => save_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
    ]);
    if (!clicked)
        throw new Error("Klik Simpan konfirmasi tanggal gagal");
}

async function handle_exam_date_confirmation_if_visible(page, timeout_ms)
{
    const exam_date_confirmation_modal = await find_exam_date_confirmation_modal(page);
    if (!exam_date_confirmation_modal)
        return false;

    const is_visible_modal = await is_visible(exam_date_confirmation_modal, sel.wait.element_visible_ms);
    if (!is_visible_modal)
        return false;

    const today_date_number = get_today_date_number();
    const picked = await pick_today_date_in_calendar(exam_date_confirmation_modal, today_date_number);
    if (!picked)
        throw new Error("Tanggal pemeriksaan hari ini tidak bisa dipilih");

    await safe_wait(page, sel.wait.after_pick_exam_date_ms);
    await click_exam_date_save_button(exam_date_confirmation_modal);
    await page.waitForLoadState("domcontentloaded", { timeout: timeout_ms }).catch(() => { });
    await safe_wait(page, sel.wait.short_delay_ms);
    return true;
}

async function ensure_exam_date_confirmation(page, timeout_ms)
{
    const first_try = await handle_exam_date_confirmation_if_visible(page, timeout_ms);
    if (first_try)
        return {
            examination_date_button_clicked: false,
            exam_date_confirmation_done: true
        };

    const examination_date_button_clicked = await click_examination_date_button_if_available(page, timeout_ms);
    const second_try = await handle_exam_date_confirmation_if_visible(page, timeout_ms);
    if (second_try)
        return {
            examination_date_button_clicked,
            exam_date_confirmation_done: true
        };

    return {
        examination_date_button_clicked,
        exam_date_confirmation_done: false
    };
}

async function ensure_start_and_date_confirmation(page, timeout_ms, should_start_examination)
{
    const start_examination_clicked = should_start_examination
        ? await click_start_examination_button_required(page, timeout_ms)
        : await click_start_examination_button_if_available(page, timeout_ms);

    const exam_date_confirmation = should_start_examination
        ? await ensure_exam_date_confirmation(page, timeout_ms)
        : await (async () =>
        {
            const handled = await handle_exam_date_confirmation_if_visible(page, timeout_ms);
            return {
                examination_date_button_clicked: false,
                exam_date_confirmation_done: handled
            };
        })();
    const examination_date_button_clicked = exam_date_confirmation.examination_date_button_clicked;
    const exam_date_confirmation_done = exam_date_confirmation.exam_date_confirmation_done;

    if (should_start_examination && !exam_date_confirmation_done)
        throw new Error("Mulai Pemeriksaan atau Konfirmasi Tanggal tidak berhasil diproses");

    return {
        start_examination_clicked,
        examination_date_button_clicked,
        exam_date_confirmation_done
    };
}

async function wait_pemeriksaan_mandiri_table_ready(page, timeout_ms)
{
    const max_wait_ms = Math.max(4000, Math.min(timeout_ms, 12000));
    const deadline = Date.now() + max_wait_ms;
    while (Date.now() < deadline)
    {
        const ready_state = await page.evaluate(() =>
        {
            const clean_text = (value) => String(value || "").replace(/\s+/g, " ").trim().toLowerCase();
            const jumlah_node = Array.from(document.querySelectorAll("div, span"))
                .find((node) => /^jumlah pemeriksaan\s*\(\d+\s*\/\s*\d+\)$/i.test(String(node.textContent || "").replace(/\s+/g, " ").trim()));
            const jumlah_text = String(jumlah_node?.textContent || "").replace(/\s+/g, " ").trim();
            const jumlah_match = jumlah_text.match(/\((\d+)\s*\/\s*(\d+)\)/i);
            const jumlah_total = jumlah_match ? Number(jumlah_match[2]) : 0;
            const jumlah_done = jumlah_match ? Number(jumlah_match[1]) : 0;

            const table_nodes = Array.from(document.querySelectorAll("table"));
            const target_table = table_nodes.find((table_node) =>
            {
                const header_nodes = Array.from(table_node.querySelectorAll("thead th"));
                if (header_nodes.length !== 3)
                    return false;

                const header_keys = header_nodes.map((header_node) => clean_text(header_node.textContent || ""));
                return header_keys[0] === "layanan" && header_keys[1] === "status" && header_keys[2] === "aksi";
            });
            const row_count = target_table ? target_table.querySelectorAll("tbody tr").length : 0;
            const has_summary = jumlah_total > 0 || jumlah_done > 0;
            return {
                row_count,
                has_summary
            };
        }).catch(() => 0);
        if (Number(ready_state?.row_count || 0) > 0)
            return true;
        if (Boolean(ready_state?.has_summary) && Number(ready_state?.row_count || 0) > 0)
            return true;

        await page.evaluate(() =>
        {
            const clean_text = (value) => String(value || "").replace(/\s+/g, " ").trim().toLowerCase();
            const title_node = Array.from(document.querySelectorAll("div, span, h4, h5"))
                .find((node) => clean_text(node.textContent || "").startsWith("jumlah pemeriksaan"));
            if (!title_node)
                return;

            const clickable_node = title_node.closest("button, [role='button'], .cursor-pointer, div");
            if (!clickable_node)
                return;

            try { clickable_node.click(); } catch { }
        }).catch(() => { });

        await safe_wait(page, 300);
    }

    return false;
}

async function collect_pemeriksaan_mandiri_table_data(page)
{
    return await page.evaluate(() =>
    {
        const clean_text = (value) => String(value || "").replace(/\s+/g, " ").trim();
        const normalize_key = (value) => clean_text(value).toLowerCase();
        const get_status_key = (status_cell) =>
        {
            const icon_node = status_cell.querySelector("img");
            const icon_src = String(icon_node?.getAttribute("src") || "").toLowerCase();

            if (icon_src.includes("icon-success-gray.svg"))
                return "belum_dilayani";
            if (icon_src.includes("icon-success.svg"))
                return "sudah_dilayani";
            if (icon_src.includes("icon-warning"))
                return "sedang_diperiksa";

            return "tidak_diketahui";
        };

        const jumlah_label_node = Array.from(document.querySelectorAll("div, span"))
            .find((node) =>
            {
                const text = clean_text(node.textContent || "");
                return /^jumlah pemeriksaan\s*\(\d+\s*\/\s*\d+\)$/i.test(text);
            });
        const jumlah_label = clean_text(jumlah_label_node?.textContent || "");
        const jumlah_match = jumlah_label.match(/\((\d+)\s*\/\s*(\d+)\)/i);

        const table_nodes = Array.from(document.querySelectorAll("table"));
        const target_table = table_nodes.find((table_node) =>
        {
            const header_nodes = Array.from(table_node.querySelectorAll("thead th"));
            if (header_nodes.length !== 3)
                return false;

            const header_keys = header_nodes.map((header_node) => normalize_key(header_node.textContent || ""));
            return header_keys[0] === "layanan" && header_keys[1] === "status" && header_keys[2] === "aksi";
        });

        if (!target_table)
        {
            return {
                jumlah_pemeriksaan_label: jumlah_label || "-",
                jumlah_pemeriksaan_selesai: 0,
                jumlah_pemeriksaan_total: 0,
                total: 0,
                belum_dilayani: 0,
                sudah_dilayani: 0,
                sedang_diperiksa: 0,
                tidak_diketahui: 0,
                items: []
            };
        }

        const row_nodes = Array.from(target_table.querySelectorAll("tbody tr"));
        const items = [];
        const counter = {
            belum_dilayani: 0,
            sudah_dilayani: 0,
            sedang_diperiksa: 0,
            tidak_diketahui: 0
        };

        for (const row_node of row_nodes)
        {
            const cell_nodes = Array.from(row_node.querySelectorAll("td"));
            if (cell_nodes.length < 3)
                continue;

            const layanan = clean_text(cell_nodes[0].textContent || "");
            if (!layanan)
                continue;

            const status_key = get_status_key(cell_nodes[1]);
            const action_button = cell_nodes[2].querySelector("button");
            const action_text = clean_text(action_button?.textContent || cell_nodes[2].textContent || "");
            const row_container = cell_nodes[2].querySelector("div[id^='rowfrm']");
            const row_id = clean_text(row_container?.id || "");

            counter[status_key] += 1;
            items.push({
                layanan,
                status: status_key,
                aksi: action_text,
                row_id,
                row_index: items.length
            });
        }

        return {
            jumlah_pemeriksaan_label: jumlah_label || "-",
            jumlah_pemeriksaan_selesai: jumlah_match ? Number(jumlah_match[1]) : 0,
            jumlah_pemeriksaan_total: jumlah_match ? Number(jumlah_match[2]) : items.length,
            total: items.length,
            belum_dilayani: counter.belum_dilayani,
            sudah_dilayani: counter.sudah_dilayani,
            sedang_diperiksa: counter.sedang_diperiksa,
            tidak_diketahui: counter.tidak_diketahui,
            items
        };
    }).catch(() => ({
        jumlah_pemeriksaan_label: "-",
        jumlah_pemeriksaan_selesai: 0,
        jumlah_pemeriksaan_total: 0,
        total: 0,
        belum_dilayani: 0,
        sudah_dilayani: 0,
        sedang_diperiksa: 0,
        tidak_diketahui: 0,
        items: []
    }));
}

async function click_input_data_by_row(page, timeout_ms, row_data)
{
    const row_id = String(row_data?.row_id || "").trim();
    const layanan_name = String(row_data?.layanan || "").trim() || "-";

    if (row_id !== "")
    {
        const target_button = page.locator(`#${row_id} button`).first();
        const visible_button = await target_button.isVisible({ timeout: 1000 }).catch(() => false);
        if (visible_button)
        {
            await target_button.scrollIntoViewIfNeeded().catch(() => { });
            const clicked = await run_async_fallbacks([
                () => target_button.click({ timeout: sel.wait.click_timeout_ms }),
                () => target_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
            ]);
            if (clicked)
            {
                await page.waitForLoadState("domcontentloaded", { timeout: timeout_ms }).catch(() => { });
                await safe_wait(page, sel.wait.page_load_grace_ms);
                return { layanan: layanan_name, aksi: "Input Data", row_id };
            }
        }
    }

    if (layanan_name !== "-" && layanan_name !== "")
    {
        const layanan_row_button = page
            .locator("tbody tr", { hasText: layanan_name })
            .first()
            .locator("button")
            .first();
        const layanan_row_visible = await layanan_row_button.isVisible({ timeout: 1000 }).catch(() => false);
        if (layanan_row_visible)
        {
            await layanan_row_button.scrollIntoViewIfNeeded().catch(() => { });
            const clicked = await run_async_fallbacks([
                () => layanan_row_button.click({ timeout: sel.wait.click_timeout_ms }),
                () => layanan_row_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
            ]);
            if (clicked)
            {
                await page.waitForLoadState("domcontentloaded", { timeout: timeout_ms }).catch(() => { });
                await safe_wait(page, sel.wait.page_load_grace_ms);
                return { layanan: layanan_name, aksi: "Input Data", row_id };
            }
        }
    }

    const clicked_item = await page.evaluate(({ target_row_id, target_row_index, target_layanan_name }) =>
    {
        const clean_text = (value) => String(value || "").replace(/\s+/g, " ").trim();
        const normalize_key = (value) => clean_text(value).toLowerCase();
        const layanan_key = normalize_key(target_layanan_name || "");

        if (target_row_id)
        {
            const row_container = document.getElementById(target_row_id);
            const fast_button = row_container?.querySelector("button");
            if (fast_button)
            {
                const layanan_cell = row_container.closest("tr")?.querySelector("td");
                const layanan_text = clean_text(layanan_cell?.textContent || "");
                const action_text = clean_text(fast_button.textContent || "");
                if (/input data/i.test(action_text))
                {
                    fast_button.click();
                    return { layanan: layanan_text, aksi: action_text, row_id: target_row_id };
                }
            }
        }

        const table_nodes = Array.from(document.querySelectorAll("table"));
        const target_table = table_nodes.find((table_node) =>
        {
            const header_nodes = Array.from(table_node.querySelectorAll("thead th"));
            if (header_nodes.length !== 3)
                return false;

            const header_keys = header_nodes.map((header_node) => normalize_key(header_node.textContent || ""));
            return header_keys[0] === "layanan" && header_keys[1] === "status" && header_keys[2] === "aksi";
        });
        if (!target_table)
            return null;

        const row_nodes = Array.from(target_table.querySelectorAll("tbody tr"));
        let row_node = row_nodes[target_row_index] || null;
        if (target_row_id)
        {
            const row_by_id = row_nodes.find((node) =>
            {
                const id_node = node.querySelector("div[id^='rowfrm']");
                return clean_text(id_node?.id || "") === target_row_id;
            });
            if (row_by_id)
                row_node = row_by_id;
        }

        if (!row_node && layanan_key !== "")
        {
            row_node = row_nodes.find((node) =>
            {
                const layanan_cell = node.querySelector("td");
                const layanan_text = normalize_key(layanan_cell?.textContent || "");
                return layanan_text === layanan_key || layanan_text.includes(layanan_key) || layanan_key.includes(layanan_text);
            }) || null;
        }
        if (!row_node)
            return null;

        const cell_nodes = Array.from(row_node.querySelectorAll("td"));
        if (cell_nodes.length < 3)
            return null;

        const layanan = clean_text(cell_nodes[0].textContent || "");
        const row_container = cell_nodes[2].querySelector("div[id^='rowfrm']");
        const row_id = clean_text(row_container?.id || "");

        const input_data_button = cell_nodes[2].querySelector("button");
        if (!input_data_button)
            return null;

        const action_text = clean_text(input_data_button.textContent || "");
        if (!/input data/i.test(action_text))
            return null;

        input_data_button.click();
        return { layanan, aksi: action_text, row_id };
    }, {
        target_row_id: row_id,
        target_row_index: Number(row_data?.row_index ?? -1),
        target_layanan_name: layanan_name
    }).catch(() => null);

    if (!clicked_item)
        return null;

    await page.waitForLoadState("domcontentloaded", { timeout: timeout_ms }).catch(() => { });
    await safe_wait(page, sel.wait.page_load_grace_ms);
    return clicked_item;
}

async function collect_questions_from_form(page)
{
    return await page.evaluate(() =>
    {
        const clean_text = (value) => String(value || "").replace(/\s+/g, " ").trim();
        const get_question_text = (title_node) =>
        {
            if (!title_node)
                return "";

            const primary_node = title_node.querySelector(".sv-title-actions__title > .sv-string-viewer");
            const primary_text = clean_text(primary_node?.textContent || "");
            if (primary_text !== "")
                return primary_text;

            const candidate_nodes = Array.from(title_node.querySelectorAll(".sv-string-viewer"));
            const candidate_text = candidate_nodes
                .map((node) => clean_text(node.textContent || ""))
                .find((text) => text !== "");
            if (candidate_text)
                return candidate_text;

            const clone_node = title_node.cloneNode(true);
            const remove_nodes = clone_node.querySelectorAll(".sd-element__num, .sd-question__required-text");
            remove_nodes.forEach((node) => node.remove());
            return clean_text(clone_node.textContent || "");
        };
        const pick_choice_options = (question_node) =>
        {
            const option_nodes = Array.from(question_node.querySelectorAll(".sd-item .sv-string-viewer"));
            const dropdown_option_nodes = Array.from(question_node.querySelectorAll("li[role='option'] .sv-string-viewer, .sv-list__item .sv-string-viewer"));
            const options = option_nodes
                .concat(dropdown_option_nodes)
                .map((option_node) => clean_text(option_node.textContent || ""))
                .filter(Boolean);
            return Array.from(new Set(options));
        };
        const pick_selected_value = (question_node) =>
        {
            const selected_radio = question_node.querySelector("input[type='radio']:checked");
            if (selected_radio)
            {
                const radio_label = selected_radio.closest("label");
                const text_node = radio_label?.querySelector(".sv-string-viewer");
                return clean_text(text_node?.textContent || selected_radio.value || "");
            }

            const selected_checkbox_nodes = Array.from(question_node.querySelectorAll("input[type='checkbox']:checked"));
            if (selected_checkbox_nodes.length > 0)
            {
                const values = selected_checkbox_nodes.map((checkbox_node) =>
                {
                    const checkbox_label = checkbox_node.closest("label");
                    const text_node = checkbox_label?.querySelector(".sv-string-viewer");
                    return clean_text(text_node?.textContent || checkbox_node.value || "");
                }).filter(Boolean);
                return values.join(", ");
            }

            const text_input = question_node.querySelector("input[type='text'], input[type='number'], textarea");
            if (text_input)
                return clean_text(text_input.value || "");

            const select_input = question_node.querySelector("select");
            if (select_input)
            {
                const selected_option = select_input.options?.[select_input.selectedIndex];
                const selected_text = clean_text(selected_option?.textContent || "");
                if (selected_text !== "")
                    return selected_text;
                return clean_text(select_input.value || "");
            }

            const dropdown_input = question_node.querySelector("input.sd-dropdown__filter-string-input");
            if (dropdown_input)
            {
                const input_value = clean_text(dropdown_input.value || "");
                if (input_value !== "")
                    return input_value;
            }

            const combo_box = question_node.querySelector("[role='combobox']");
            if (combo_box)
            {
                const active_id = clean_text(combo_box.getAttribute("aria-activedescendant") || "");
                if (active_id !== "")
                {
                    const active_node = document.getElementById(active_id);
                    const active_text = clean_text(active_node?.textContent || "");
                    if (active_text !== "")
                        return active_text;
                }

                const combo_text = clean_text(combo_box.textContent || "");
                if (combo_text !== "")
                    return combo_text;
            }

            const dropdown_value_node = question_node.querySelector(".sd-dropdown__value .sv-string-viewer, .sd-dropdown__value, .sd-input.sd-dropdown .sv-string-viewer");
            if (dropdown_value_node)
            {
                const dropdown_value = clean_text(dropdown_value_node.textContent || "");
                if (dropdown_value !== "")
                    return dropdown_value;
            }

            const hidden_input_nodes = Array.from(question_node.querySelectorAll("input[type='hidden']"));
            const hidden_value = hidden_input_nodes
                .map((input_node) => clean_text(input_node.value || ""))
                .find((value) => value !== "" && value.length <= 80 && !/^sv[_-]/i.test(value));
            if (hidden_value)
                return hidden_value;

            return "";
        };
        const detect_type = (question_node) =>
        {
            if (question_node.querySelector("input[type='radio']")) return "radio";
            if (question_node.querySelector("input[type='checkbox']")) return "checkbox";
            if (question_node.querySelector("div.sd-dropdown[role='combobox'], div.sd-input.sd-dropdown")) return "dropdown";
            if (question_node.querySelector("select")) return "select";
            if (question_node.querySelector("textarea")) return "textarea";
            if (question_node.querySelector("input[type='number']")) return "number";
            if (question_node.querySelector("input[type='text']")) return "text";
            return "unknown";
        };

        const question_nodes = Array.from(document.querySelectorAll("div.sd-question"));
        const all_questions = [];

        for (const question_node of question_nodes)
        {
            const title_node = question_node.querySelector("h5.sd-question__title");
            if (!title_node)
                continue;

            const number_text = clean_text(title_node.querySelector(".sd-element__num")?.textContent || "");
            const question_text = get_question_text(title_node);
            const selected_value = pick_selected_value(question_node);
            const answered =
                question_node.classList.contains("sd-question--answered") ||
                selected_value !== "";
            const input_type = detect_type(question_node);
            const choices = pick_choice_options(question_node);
            const fieldset_node = question_node.querySelector("fieldset.sd-selectbase");
            const is_required =
                title_node.classList.contains("sd-question__title--required") ||
                title_node.querySelector(".sd-question__required-text") !== null ||
                String(fieldset_node?.getAttribute("aria-required") || "").toLowerCase() === "true";

            all_questions.push({
                nomor: number_text,
                pertanyaan: question_text,
                data_name: clean_text(question_node.getAttribute("data-name") || ""),
                tipe_input: input_type,
                wajib: is_required ? "ya" : "tidak",
                opsi_jawaban: choices,
                terjawab: answered ? "ya" : "tidak",
                jawaban_terpilih: selected_value || "-"
            });
        }

        const wajib_items = all_questions.filter((item) => item.wajib === "ya");
        return {
            total_pertanyaan: all_questions.length,
            total_pertanyaan_wajib: wajib_items.length,
            total_belum_terjawab: wajib_items.filter((item) => item.terjawab === "tidak").length,
            items: all_questions
        };
    }).catch(() => ({
        total_pertanyaan: 0,
        total_pertanyaan_wajib: 0,
        total_belum_terjawab: 0,
        items: []
    }));
}

function normalize_text_key(value)
{
    return String(value || "")
        .replace(/^\s*\d+\s*[\.\)\-:]\s*/g, "")
        .replace(/\*/g, "")
        .toLowerCase()
        .normalize("NFKD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9]+/g, " ")
        .trim();
}

function split_default_answer_values(default_value, answer_type)
{
    const clean_default = String(default_value || "").trim();
    if (clean_default === "")
        return [];

    const clean_answer_type = String(answer_type || "radio").trim().toLowerCase();
    if (clean_answer_type !== "checkbox")
        return [clean_default];

    return clean_default
        .split(",")
        .map((value) => String(value || "").trim())
        .filter((value) => value !== "");
}

function build_question_bank_service_list(pemeriksaan_mandiri_payload)
{
    const items = Array.isArray(pemeriksaan_mandiri_payload?.items)
        ? pemeriksaan_mandiri_payload.items
        : [];

    return items.map((item) =>
    {
        const nama = String(item?.nama || "").trim();
        const pertanyaan = Array.isArray(item?.pertanyaan) ? item.pertanyaan : [];
        return {
            nama,
            nama_key: normalize_text_key(nama),
            pertanyaan
        };
    }).filter((item) => item.nama_key !== "");
}

function find_question_bank_service(service_list, layanan_name, form_title)
{
    const layanan_key = normalize_text_key(layanan_name);
    const form_title_key = normalize_text_key(form_title);
    const candidate_keys = [layanan_key, form_title_key].filter((value) => value !== "");

    for (const key of candidate_keys)
    {
        const exact = service_list.find((item) => item.nama_key === key);
        if (exact)
            return exact;
    }

    for (const key of candidate_keys)
    {
        const partial = service_list.find((item) => key.includes(item.nama_key) || item.nama_key.includes(key));
        if (partial)
            return partial;
    }

    return null;
}

function find_question_bank_service_strict(service_list, layanan_name, form_title)
{
    const layanan_key = normalize_text_key(layanan_name);
    const form_title_key = normalize_text_key(form_title);
    const candidate_keys = [layanan_key, form_title_key].filter((value) => value !== "");

    for (const key of candidate_keys)
    {
        const exact = service_list.find((item) => item.nama_key === key);
        if (exact)
            return exact;
    }

    return null;
}

function build_question_answer_map(question_service)
{
    const answer_map = {};
    if (!question_service || !Array.isArray(question_service.pertanyaan))
        return answer_map;

    for (const question_item of question_service.pertanyaan)
    {
        const text = String(question_item?.text || "").trim();
        const text_key = normalize_text_key(text);
        if (text_key === "")
            continue;

        const jawaban = Array.isArray(question_item?.jawaban)
            ? question_item.jawaban.map((value) => String(value || "").trim()).filter((value) => value !== "")
            : [];

        answer_map[text_key] = {
            text,
            jawaban,
            jawaban_default: String(question_item?.jawaban_default || "").trim(),
            answer_mode: String(question_item?.answer_mode || "fixed").trim(),
            answer_type: String(question_item?.answer_type || "radio").trim()
        };
    }

    return answer_map;
}

function has_duplicate_question_text_key(ordered_answers, text_key)
{
    const key = normalize_text_key(text_key);
    if (key === "" || !Array.isArray(ordered_answers) || ordered_answers.length === 0)
        return false;

    let total = 0;
    for (const answer_item of ordered_answers)
    {
        const item_key = normalize_text_key(String(answer_item?.text || ""));
        if (item_key === key)
            total += 1;
        if (total > 1)
            return true;
    }
    return false;
}

function build_question_answer_list(question_service)
{
    if (!question_service || !Array.isArray(question_service.pertanyaan))
        return [];

    return question_service.pertanyaan.map((question_item) =>
    {
        const text = String(question_item?.text || "").trim();
        const jawaban = Array.isArray(question_item?.jawaban)
            ? question_item.jawaban.map((value) => String(value || "").trim()).filter((value) => value !== "")
            : [];

        return {
            text,
            jawaban,
            jawaban_default: String(question_item?.jawaban_default || "").trim(),
            answer_mode: String(question_item?.answer_mode || "fixed").trim(),
            answer_type: String(question_item?.answer_type || "radio").trim()
        };
    });
}

function build_global_question_answer_map(service_list)
{
    const answer_map = {};
    for (const service_item of service_list)
    {
        const question_list = Array.isArray(service_item?.pertanyaan) ? service_item.pertanyaan : [];
        for (const question_item of question_list)
        {
            const text = String(question_item?.text || "").trim();
            const text_key = normalize_text_key(text);
            if (text_key === "" || answer_map[text_key])
                continue;

            const jawaban = Array.isArray(question_item?.jawaban)
                ? question_item.jawaban.map((value) => String(value || "").trim()).filter((value) => value !== "")
                : [];

            answer_map[text_key] = {
                text,
                jawaban,
                jawaban_default: String(question_item?.jawaban_default || "").trim(),
                answer_mode: String(question_item?.answer_mode || "fixed").trim(),
                answer_type: String(question_item?.answer_type || "radio").trim()
            };
        }
    }
    return answer_map;
}

function pick_expected_values(answer_item)
{
    if (!answer_item)
        return [];

    const answer_mode = String(answer_item?.answer_mode || "fixed").trim().toLowerCase();
    const default_value = String(answer_item?.jawaban_default || "").trim();
    const values = Array.isArray(answer_item?.jawaban)
        ? answer_item.jawaban.map((value) => String(value || "").trim()).filter((value) => value !== "")
        : [];

    if (answer_mode === "random" && values.length > 0)
        return values;

    if (default_value !== "")
        return split_default_answer_values(default_value, answer_item?.answer_type);

    return values.length > 0 ? [values[0]] : [];
}

function find_answer_item_from_bank(question_text, question_number, answer_map, ordered_answers)
{
    const text_key = normalize_text_key(question_text);
    const ordered_index = Number.isFinite(question_number) ? Math.max(0, question_number - 1) : -1;
    const duplicate_text = has_duplicate_question_text_key(ordered_answers, text_key);
    if (duplicate_text && ordered_index >= 0 && Array.isArray(ordered_answers) && ordered_answers[ordered_index])
        return ordered_answers[ordered_index];

    if (text_key !== "" && answer_map?.[text_key])
        return answer_map[text_key];

    const map_keys = Object.keys(answer_map || {});
    if (text_key !== "")
    {
        const input_tokens = text_key.split(" ").filter((token) => token !== "");
        let best_key = "";
        let best_score = 0;

        for (const key of map_keys)
        {
            if (text_key.includes(key) || key.includes(text_key))
                return answer_map[key];

            const key_tokens = key.split(" ").filter((token) => token !== "");
            if (input_tokens.length === 0 || key_tokens.length === 0)
                continue;

            let overlap = 0;
            for (const token of input_tokens)
            {
                if (key_tokens.includes(token))
                    overlap += 1;
            }

            const denominator = Math.max(input_tokens.length, key_tokens.length);
            const score = denominator > 0 ? overlap / denominator : 0;
            if (score > best_score)
            {
                best_score = score;
                best_key = key;
            }
        }

        if (best_key !== "" && best_score >= 0.7)
            return answer_map[best_key];
    }

    if (text_key === "" && ordered_index >= 0 && Array.isArray(ordered_answers) && ordered_answers[ordered_index])
        return ordered_answers[ordered_index];

    return null;
}

function is_answer_value_match(actual_value, expected_values, question_input_type = "")
{
    const actual_key = normalize_text_key(actual_value);
    if (actual_key === "" || expected_values.length === 0)
        return false;

    const expected_keys = expected_values
        .map((value) => normalize_text_key(value))
        .filter((value) => value !== "");
    if (expected_keys.length === 0)
        return false;

    const input_type = String(question_input_type || "").trim().toLowerCase();
    const strict_types = new Set(["radio", "dropdown", "select", "checkbox"]);
    if (strict_types.has(input_type))
        return expected_keys.some((expected_key) => actual_key === expected_key);

    if (input_type === "number")
    {
        const actual_number = Number(String(actual_value || "").replace(",", "."));
        if (Number.isFinite(actual_number))
        {
            for (const raw_expected of expected_values)
            {
                const expected_text = String(raw_expected || "").trim();
                const range_match = expected_text.match(/(-?\d+(?:[.,]\d+)?)\s*[-~]\s*(-?\d+(?:[.,]\d+)?)/);
                if (range_match)
                {
                    const min_value = Number(String(range_match[1] || "").replace(",", "."));
                    const max_value = Number(String(range_match[2] || "").replace(",", "."));
                    if (Number.isFinite(min_value) && Number.isFinite(max_value))
                    {
                        if (actual_number >= Math.min(min_value, max_value) && actual_number <= Math.max(min_value, max_value))
                            return true;
                    }
                }
            }
        }
    }

    return expected_keys.some((expected_key) => actual_key === expected_key || actual_key.includes(expected_key) || expected_key.includes(actual_key));
}

function build_answer_match_summary(question_data, answer_map, ordered_answers)
{
    const items = Array.isArray(question_data?.items) ? question_data.items : [];
    const mismatches = [];
    let total_checked = 0;
    let total_match = 0;

    for (const item of items)
    {
        const question_text = String(item?.pertanyaan || "").trim();
        const actual_value = String(item?.jawaban_terpilih || "").trim();
        const number_match = String(item?.nomor || "").match(/^(\d+)/);
        const question_number = number_match ? Number(number_match[1]) : NaN;
        const answer_item = find_answer_item_from_bank(question_text, question_number, answer_map, ordered_answers);
        const expected_values = pick_expected_values(answer_item);
        if (expected_values.length === 0)
            continue;

        total_checked += 1;
        const matched = is_answer_value_match(actual_value, expected_values, String(item?.tipe_input || ""));
        if (matched)
        {
            total_match += 1;
            continue;
        }

        mismatches.push({
            nomor: String(item?.nomor || "-"),
            pertanyaan: question_text,
            jawaban_web: actual_value || "-",
            jawaban_server_expected: expected_values[0] || "-"
        });
    }

    return {
        total_checked,
        total_match,
        total_mismatch: mismatches.length,
        ok: mismatches.length === 0,
        mismatches
    };
}

async function collect_form_title(page)
{
    const title_text = await page.locator("h4.sd-page__title .sv-string-viewer, h4.sd-page__title")
        .first()
        .textContent()
        .catch(() => "");
    return String(title_text || "").replace(/\s+/g, " ").trim();
}

async function auto_fill_form_answers(page, answer_map, ordered_answers = [], options = {})
{
    const refill_answered = options?.refill_answered === true;
    return await page.evaluate(async ({ answers, ordered_items, refill_answered }) =>
    {
        const normalize = (value) => String(value || "")
            .replace(/^\s*\d+\s*[\.\)\-:]\s*/g, "")
            .replace(/\*/g, "")
            .toLowerCase()
            .normalize("NFKD")
            .replace(/[\u0300-\u036f]/g, "")
            .replace(/[^a-z0-9]+/g, " ")
            .trim();

        const clean = (value) => String(value || "").replace(/\s+/g, " ").trim();
        const compact = (value) => String(value || "")
            .toLowerCase()
            .normalize("NFKD")
            .replace(/[\u0300-\u036f]/g, "")
            .replace(/[^a-z0-9]+/g, "");
        const is_same_option = (left_value, right_value) =>
        {
            const left_clean = clean(left_value);
            const right_clean = clean(right_value);
            if (left_clean === "" || right_clean === "")
                return false;
            if (normalize(left_clean) === normalize(right_clean))
                return true;
            const left_comp = compact(left_clean);
            const right_comp = compact(right_clean);
            if (left_comp === right_comp)
                return true;
            if (left_comp.length > 3 && right_comp.length > 3)
            {
                if (left_comp.includes(right_comp) || right_comp.includes(left_comp))
                    return true;
            }
            return false;
        };
        const split_default_values = (default_value, answer_type) =>
        {
            const clean_default = clean(default_value || "");
            if (clean_default === "")
                return [];

            const clean_answer_type = clean(answer_type || "radio").toLowerCase();
            if (clean_answer_type !== "checkbox")
                return [clean_default];

            return clean_default
                .split(",")
                .map((value) => clean(value))
                .filter((value) => value !== "");
        };
        const pick_random_numeric_value = (raw_value) =>
        {
            const value = clean(raw_value || "");
            if (value === "")
                return "";

            const range_match = value.match(/(-?\d+(?:[.,]\d+)?)\s*(?:-|~|s\.?\s*d\.?|sd|s\/d|to)\s*(-?\d+(?:[.,]\d+)?)/i);
            if (range_match)
            {
                const min_raw = Number(String(range_match[1] || "").replace(",", "."));
                const max_raw = Number(String(range_match[2] || "").replace(",", "."));
                if (Number.isFinite(min_raw) && Number.isFinite(max_raw))
                {
                    const min_value = Math.min(min_raw, max_raw);
                    const max_value = Math.max(min_raw, max_raw);
                    if (Number.isInteger(min_value) && Number.isInteger(max_value))
                    {
                        const random_integer = Math.floor(Math.random() * ((max_value - min_value) + 1)) + min_value;
                        return String(random_integer);
                    }
                    const random_decimal = (Math.random() * (max_value - min_value)) + min_value;
                    return String(Number(random_decimal.toFixed(2)));
                }
            }

            const first_number = value.match(/-?\d+(?:[.,]\d+)?/);
            if (!first_number)
                return "";
            return String(Number(String(first_number[0] || "").replace(",", ".")));
        };
        const pick_random_option_values = (answer_item, option_values) =>
        {
            if (!Array.isArray(option_values) || option_values.length === 0)
                return [];

            const random_index = Math.floor(Math.random() * option_values.length);
            const selected_option = clean(option_values[random_index] || "");
            if (selected_option === "")
                return [];

            const answer_type = clean(answer_item?.answer_type || "radio").toLowerCase();
            if (answer_type === "number" || answer_type === "range")
            {
                const random_numeric = pick_random_numeric_value(selected_option);
                if (random_numeric !== "")
                    return [random_numeric];
            }

            return [selected_option];
        };
        const wait_ms = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
        const final_answer_cache = {};
        const dispatch_input_events = (element) =>
        {
            element.dispatchEvent(new Event("input", { bubbles: true }));
            element.dispatchEvent(new Event("change", { bubbles: true }));
        };
        const dispatch_mouse_events = (element) =>
        {
            element.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));
            element.dispatchEvent(new MouseEvent("mouseup", { bubbles: true }));
            element.dispatchEvent(new MouseEvent("click", { bubbles: true }));
        };
        const set_choice_input = (input_node, next_checked) =>
        {
            if (!input_node)
                return false;
            const current_checked = Boolean(input_node.checked);
            if (current_checked === Boolean(next_checked))
                return false;
            if (next_checked)
            {
                try { input_node.click(); } catch { }
                if (!input_node.checked)
                    dispatch_mouse_events(input_node);
                dispatch_input_events(input_node);
                return Boolean(input_node.checked);
            }

            if (input_node.type === "checkbox")
            {
                try { input_node.click(); } catch { }
                if (input_node.checked)
                    dispatch_mouse_events(input_node);
                dispatch_input_events(input_node);
                return !input_node.checked;
            }

            return false;
        };
        const is_question_visible = (question_node) =>
        {
            if (!question_node || !question_node.isConnected)
                return false;
            const style = window.getComputedStyle(question_node);
            if (style.display === "none" || style.visibility === "hidden" || style.opacity === "0")
                return false;
            const rect = question_node.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0)
                return false;
            return true;
        };
        const get_question_text = (title_node) =>
        {
            if (!title_node)
                return "";

            const primary_node = title_node.querySelector(".sv-title-actions__title > .sv-string-viewer");
            const primary_text = clean(primary_node?.textContent || "");
            if (primary_text !== "")
                return primary_text;

            const candidate_nodes = Array.from(title_node.querySelectorAll(".sv-string-viewer"));
            const candidate_text = candidate_nodes
                .map((node) => clean(node.textContent || ""))
                .find((text) => text !== "");
            if (candidate_text)
                return candidate_text;

            const clone_node = title_node.cloneNode(true);
            const remove_nodes = clone_node.querySelectorAll(".sd-element__num, .sd-question__required-text");
            remove_nodes.forEach((node) => node.remove());
            return clean(clone_node.textContent || "");
        };
        const get_question_number = (question_node) =>
        {
            const number_text = clean(question_node.querySelector(".sd-element__num")?.textContent || "");
            const number_match = number_text.match(/^(\d+)/);
            if (!number_match)
                return 999999;
            return Number(number_match[1]);
        };
        const get_question_cache_key = (question_node, question_text, ordered_index) =>
        {
            const data_name = clean(question_node.getAttribute("data-name") || "");
            if (data_name !== "")
                return normalize(data_name);
            return `${normalize(question_text)}|${ordered_index}`;
        };
        const is_question_answered = (question_node) =>
        {
            return Boolean(
                question_node.classList.contains("sd-question--answered") ||
                question_node.querySelector("input[type='radio']:checked") ||
                question_node.querySelector("input[type='checkbox']:checked") ||
                clean(question_node.querySelector("input[type='text'], input[type='number'], textarea")?.value || "") !== "" ||
                clean(question_node.querySelector("input.sd-dropdown__filter-string-input")?.value || "") !== "" ||
                clean(question_node.querySelector("select")?.value || "") !== ""
            );
        };

        const pick_answer_item = (question_text, question_index) =>
        {
            const key = normalize(question_text);
            const key_total = key === ""
                ? 0
                : ordered_items.reduce((total, answer_item) =>
                {
                    const item_key = normalize(clean(answer_item?.text || ""));
                    return item_key === key ? total + 1 : total;
                }, 0);
            if (key_total > 1 && ordered_items[question_index])
                return ordered_items[question_index];
            if (key === "")
                return ordered_items[question_index] || null;
            if (answers[key])
                return answers[key];

            const keys = Object.keys(answers);
            let best_key = "";
            let best_score = 0;

            const key_tokens = key.split(" ").filter((token) => token !== "");
            for (const answer_key of keys)
            {
                if (key.includes(answer_key) || answer_key.includes(key))
                    return answers[answer_key] || null;

                const answer_tokens = answer_key.split(" ").filter((token) => token !== "");
                if (key_tokens.length === 0 || answer_tokens.length === 0)
                    continue;

                let overlap = 0;
                for (const token of key_tokens)
                {
                    if (answer_tokens.includes(token))
                        overlap += 1;
                }

                const denominator = Math.max(key_tokens.length, answer_tokens.length);
                const score = denominator > 0 ? overlap / denominator : 0;
                if (score > best_score)
                {
                    best_score = score;
                    best_key = answer_key;
                }
            }

            if (best_key !== "" && best_score >= 0.7)
                return answers[best_key] || null;
            return null;
        };

        const pick_desired_values = (answer_item, fallback_values = [], question_cache_key = "") =>
        {
            if (question_cache_key !== "" && Array.isArray(final_answer_cache[question_cache_key]) && final_answer_cache[question_cache_key].length > 0)
                return final_answer_cache[question_cache_key];

            let desired_values = [];
            if (answer_item)
            {
                const answer_mode = clean(answer_item.answer_mode || "fixed").toLowerCase();
                const default_value = clean(answer_item.jawaban_default || "");
                const option_values = Array.isArray(answer_item.jawaban)
                    ? answer_item.jawaban.map((value) => clean(value)).filter((value) => value !== "")
                    : [];

                if (answer_mode === "random" && option_values.length > 0)
                    desired_values = pick_random_option_values(answer_item, option_values);
                else if (default_value !== "")
                {
                    const split_values = split_default_values(default_value, answer_item?.answer_type);
                    if (split_values.length > 0)
                        desired_values = split_values;
                }
                else if (option_values.length > 0)
                {
                    if (answer_mode === "fixed")
                    {
                        const tidak_option = option_values.find((value) => normalize(value) === "tidak");
                        if (default_value === "" && tidak_option)
                            desired_values = [tidak_option];
                        else if (default_value === "")
                            desired_values = [option_values[0]];
                        else
                            desired_values = option_values;
                    }
                    else if (answer_mode === "random")
                        desired_values = pick_random_option_values(answer_item, option_values);
                    else
                        desired_values = option_values;
                }
            }

            if (desired_values.length === 0)
                desired_values = fallback_values;
            if (question_cache_key !== "" && desired_values.length > 0)
                final_answer_cache[question_cache_key] = desired_values;
            return desired_values;
        };
        const normalize_number_value = (value) =>
        {
            const raw_text = clean(value || "");
            if (raw_text === "")
                return "";
            if (/^-?\d+(\.\d+)?$/.test(raw_text))
                return raw_text;

            const range_match = raw_text.match(/(-?\d+(?:\.\d+)?)\s*[-~s.d/]+\s*(-?\d+(?:\.\d+)?)/i);
            if (range_match)
                return String(range_match[1] || "");

            const first_number = raw_text.match(/-?\d+(?:\.\d+)?/);
            return first_number ? String(first_number[0] || "") : "";
        };
        const clamp_positive_number_value = (value, fallback_value = "1") =>
        {
            const normalized_value = normalize_number_value(value);
            const fallback_normalized = normalize_number_value(fallback_value);
            const fallback_numeric = Number(fallback_normalized || "1");
            const safe_fallback = Number.isFinite(fallback_numeric) && fallback_numeric > 0
                ? String(Math.floor(fallback_numeric))
                : "1";

            if (normalized_value === "")
                return safe_fallback;

            const numeric_value = Number(normalized_value);
            if (!Number.isFinite(numeric_value))
                return safe_fallback;
            if (numeric_value <= 0)
                return safe_fallback;
            return String(Math.floor(numeric_value));
        };
        const set_input_value = async (target_input, next_value) =>
        {
            if (!target_input)
                return false;

            const desired_value = String(next_value ?? "");
            const current_value = clean(target_input.value || "");
            if (current_value === desired_value)
                return false;

            try { target_input.focus(); } catch { }
            target_input.value = desired_value;
            dispatch_input_events(target_input);
            await wait_ms(20);

            const applied_value = clean(target_input.value || "");
            if (applied_value !== desired_value)
            {
                target_input.setAttribute("value", desired_value);
                target_input.value = desired_value;
                dispatch_input_events(target_input);
                await wait_ms(20);
            }

            try { target_input.blur(); } catch { }
            return clean(target_input.value || "") === desired_value;
        };
        const read_dropdown_value = (question_node, dropdown_root) =>
        {
            const dropdown_input = dropdown_root?.querySelector("input.sd-dropdown__filter-string-input");
            const input_value = clean(dropdown_input?.value || "");
            if (input_value !== "")
                return input_value;

            const combo_box = dropdown_root?.querySelector("[role='combobox']") || question_node.querySelector("[role='combobox']");
            if (combo_box)
            {
                const active_id = clean(combo_box.getAttribute("aria-activedescendant") || "");
                if (active_id !== "")
                {
                    const active_node = document.getElementById(active_id);
                    const active_text = clean(active_node?.textContent || "");
                    if (active_text !== "")
                        return active_text;
                }

                const combo_text = clean(combo_box.textContent || "");
                if (combo_text !== "")
                    return combo_text;
            }

            const value_node = dropdown_root?.querySelector(".sd-dropdown__value .sv-string-viewer, .sd-dropdown__value, .sd-input.sd-dropdown .sv-string-viewer")
                || question_node.querySelector(".sd-dropdown__value .sv-string-viewer, .sd-dropdown__value, .sd-input.sd-dropdown .sv-string-viewer");
            return clean(value_node?.textContent || "");
        };
        const pick_dropdown_option = async (question_node, answer_item, question_cache_key = "") =>
        {
            const dropdown_root = question_node.querySelector("div.sd-dropdown[role='combobox'], div.sd-input.sd-dropdown");
            if (!dropdown_root)
                return false;

            const dropdown_input = dropdown_root.querySelector("input.sd-dropdown__filter-string-input");
            const chevron_button = question_node.querySelector(".sd-dropdown_chevron-button");
            const open_targets = [dropdown_root, dropdown_input, chevron_button].filter(Boolean);
            for (const target of open_targets)
            {
                try { target.click(); } catch { }
                dispatch_mouse_events(target);
            }
            await wait_ms(120);

            const list_id = clean(dropdown_root.getAttribute("aria-controls") || "");
            const list_node = list_id !== "" ? document.getElementById(list_id) : null;
            const option_nodes = (list_node
                ? Array.from(list_node.querySelectorAll("li[role='option'], .sv-list__item"))
                : Array.from(document.querySelectorAll("li[role='option'], .sv-list__item")))
                .filter((node) => clean(node.textContent || "") !== "");

            const fallback_values = option_nodes
                .map((node) => clean(node.textContent || ""))
                .filter((value) => value !== "");
            const desired_values = pick_desired_values(answer_item, fallback_values, question_cache_key);
            const desired = clean(desired_values[0] || "");
            const desired_key = normalize(desired);
            if (desired_key === "")
                return false;
            const current_value_key = normalize(read_dropdown_value(question_node, dropdown_root));
            if (current_value_key === desired_key)
                return false;

            const matched_option = option_nodes.find((option_node) =>
            {
                const option_text = clean(option_node.textContent || "");
                return is_same_option(option_text, desired);
            }) || option_nodes[0];

            if (!matched_option)
                return false;

            try { matched_option.click(); } catch { }
            dispatch_mouse_events(matched_option);
            const body_node = matched_option.querySelector(".sv-list__item-body");
            if (body_node)
            {
                try { body_node.click(); } catch { }
                dispatch_mouse_events(body_node);
            }
            await wait_ms(80);
            const selected_value_key = normalize(read_dropdown_value(question_node, dropdown_root));
            if (selected_value_key === desired_key)
                return true;

            const selected_text = clean(matched_option.textContent || "");
            if (dropdown_input)
            {
                const before_value = clean(dropdown_input.value || "");
                if (before_value === "" && selected_text !== "")
                {
                    const has_readonly = dropdown_input.hasAttribute("readonly");
                    if (has_readonly)
                        dropdown_input.removeAttribute("readonly");
                    dropdown_input.value = selected_text;
                    dispatch_input_events(dropdown_input);
                    if (has_readonly)
                        dropdown_input.setAttribute("readonly", "");
                }
                return clean(dropdown_input.value || "") !== "";
            }

            return selected_text !== "";
        };

        let total_questions = 0;
        let total_filled = 0;
        let total_answered_after = 0;
        let total_unmapped = 0;
        const seen_unmapped = {};
        const question_finalized = {};

        for (let step_index = 0; step_index < 40; step_index += 1)
        {
            const question_nodes = Array.from(document.querySelectorAll("div.sd-question"))
                .filter((question_node) => is_question_visible(question_node))
                .map((question_node, list_index) =>
                {
                    const rect = question_node.getBoundingClientRect();
                    const question_number = get_question_number(question_node);
                    return {
                        question_node,
                        list_index,
                        question_number,
                        top: rect.top
                    };
                })
                .sort((left, right) =>
                {
                    if (left.question_number !== right.question_number)
                        return left.question_number - right.question_number;
                    if (left.top !== right.top)
                        return left.top - right.top;
                    return left.list_index - right.list_index;
                });
            total_questions = question_nodes.length;
            let filled_this_step = false;
            let pending_question_total = 0;

            for (let question_index = 0; question_index < question_nodes.length; question_index += 1)
            {
                const question_meta = question_nodes[question_index];
                const question_node = question_meta.question_node;
                const title_node = question_node.querySelector("h5.sd-question__title");
                const question_text = get_question_text(title_node);
                const ordered_index = question_meta.question_number < 999999
                    ? Math.max(0, question_meta.question_number - 1)
                    : question_index;
                const answer_item = pick_answer_item(question_text, ordered_index);
                const question_cache_key = get_question_cache_key(question_node, question_text, ordered_index);
                if (question_finalized[question_cache_key])
                    continue;
                pending_question_total += 1;
                const answered_before = is_question_answered(question_node);
                const answer_mode = clean(answer_item?.answer_mode || "fixed").toLowerCase();
                if (answered_before && answer_mode === "random" && !refill_answered)
                {
                    question_finalized[question_cache_key] = true;
                    continue;
                }
                if (answered_before && !refill_answered)
                {
                    question_finalized[question_cache_key] = true;
                    continue;
                }

                const radio_inputs = Array.from(question_node.querySelectorAll("input[type='radio']"));
                const checkbox_inputs = Array.from(question_node.querySelectorAll("input[type='checkbox']"));
                const select_input = question_node.querySelector("select");
                const textarea_input = question_node.querySelector("textarea");
                const number_input = question_node.querySelector("input[type='number']");
                const text_input = question_node.querySelector("input[type='text']");

                let filled = false;
                if (!answer_item)
                {
                    const unmapped_key = `${normalize(question_text)}|${ordered_index}`;
                    if (!seen_unmapped[unmapped_key])
                    {
                        seen_unmapped[unmapped_key] = true;
                        total_unmapped += 1;
                    }
                    continue;
                }

                if (radio_inputs.length > 0)
                {
                    const radio_items = radio_inputs.map((input_node) =>
                    {
                        const label_node = input_node.closest("label");
                        const text_label_node = label_node?.querySelector(".sv-string-viewer");
                        return {
                            input_node,
                            label_text: clean(text_label_node?.textContent || input_node.value || "")
                        };
                    });
                    const fallback_values = radio_items.map((item) => item.label_text).filter((value) => value !== "");
                    const desired_values = pick_desired_values(answer_item, fallback_values, question_cache_key);
                    const desired = desired_values[0] || "";
                    const desired_key = normalize(desired);
                    if (desired_key !== "")
                    {
                        const matched_item = radio_items.find((item) =>
                            is_same_option(item.label_text, desired)
                        );
                        if (matched_item)
                        {
                            if (matched_item.input_node.checked)
                            {
                                question_finalized[question_cache_key] = true;
                                continue;
                            }
                            const label_node = matched_item.input_node.closest("label");
                            filled = set_choice_input(matched_item.input_node, true);
                            if (!filled && label_node)
                            {
                                try { label_node.click(); } catch { }
                                dispatch_mouse_events(label_node);
                                filled = set_choice_input(matched_item.input_node, true);
                            }
                        }
                    }
                }
                else if (checkbox_inputs.length > 0)
                {
                    const checkbox_items = checkbox_inputs.map((input_node) =>
                    {
                        const label_node = input_node.closest("label");
                        const text_label_node = label_node?.querySelector(".sv-string-viewer");
                        return {
                            input_node,
                            label_text: clean(text_label_node?.textContent || input_node.value || "")
                        };
                    });
                    const fallback_values = checkbox_items.slice(0, 1).map((item) => item.label_text).filter((value) => value !== "");
                    const desired_values = pick_desired_values(answer_item, fallback_values, question_cache_key)
                        .map((value) => normalize(value))
                        .filter((value) => value !== "");
                    if (desired_values.length > 0)
                    {
                        const matched_items = checkbox_items.filter((item) =>
                            desired_values.some((desired_key) =>
                                is_same_option(item.label_text, desired_key)
                            )
                        );
                        const target_items = matched_items.length > 0 ? matched_items : [];

                        for (const target_item of target_items)
                        {
                            if (!target_item.input_node.checked)
                            {
                                const label_node = target_item.input_node.closest("label");
                                const changed = set_choice_input(target_item.input_node, true);
                                if (!changed && label_node)
                                {
                                    try { label_node.click(); } catch { }
                                    dispatch_mouse_events(label_node);
                                    set_choice_input(target_item.input_node, true);
                                }
                                filled = true;
                            }
                        }
                        if (!filled && target_items.length > 0)
                            question_finalized[question_cache_key] = true;
                    }
                }
                else if (select_input)
                {
                    const option_nodes = Array.from(select_input.querySelectorAll("option"));
                    const fallback_values = option_nodes.map((option_node) => clean(option_node.textContent || option_node.value || "")).filter((value) => value !== "");
                    const desired_values = pick_desired_values(answer_item, fallback_values, question_cache_key);
                    const desired = desired_values[0] || "";
                    const desired_key = normalize(desired);
                    if (desired_key !== "")
                    {
                        const matched_option = option_nodes.find((option_node) =>
                        {
                            const option_text = clean(option_node.textContent || option_node.value || "");
                            return is_same_option(option_text, desired);
                        });
                        if (matched_option)
                        {
                            if (clean(select_input.value || "") === clean(matched_option.value || ""))
                            {
                                question_finalized[question_cache_key] = true;
                                continue;
                            }
                            select_input.value = matched_option.value;
                            dispatch_input_events(select_input);
                            filled = true;
                        }
                    }
                }
                else if (question_node.querySelector("div.sd-dropdown[role='combobox'], div.sd-input.sd-dropdown"))
                {
                    filled = await pick_dropdown_option(question_node, answer_item, question_cache_key);
                }
                else if (textarea_input || number_input || text_input)
                {
                    const target_input = textarea_input || number_input || text_input;
                    const fallback_values = number_input ? ["1"] : ["-"];
                    const desired_values = pick_desired_values(answer_item, fallback_values, question_cache_key);
                    let desired_value = clean(desired_values[0] || "");
                    if (number_input)
                    {
                        desired_value = clamp_positive_number_value(desired_value, fallback_values[0] || "1");
                    }
                    if (desired_value === "")
                        desired_value = fallback_values[0] || "-";
                    if (clean(target_input.value || "") === desired_value)
                    {
                        question_finalized[question_cache_key] = true;
                        continue;
                    }

                    filled = await set_input_value(target_input, desired_value);
                }

                if (filled)
                {
                    total_filled += 1;
                    filled_this_step = true;
                    if (is_question_answered(question_node))
                        question_finalized[question_cache_key] = true;
                    await wait_ms(20);
                }
            }

            if (pending_question_total === 0)
                break;
            if (!filled_this_step)
                break;
        }

        total_answered_after = Array.from(document.querySelectorAll("div.sd-question"))
            .filter((question_node) => is_question_visible(question_node))
            .filter((question_node) => is_question_answered(question_node))
            .length;

        return { total_questions, total_filled, total_answered_after, total_unmapped };
    }, {
        answers: answer_map,
        ordered_items: ordered_answers,
        refill_answered
    }).catch((error) => ({
        total_questions: 0,
        total_filled: 0,
        total_answered_after: 0,
        total_unmapped: 0,
        error_message: String(error?.message || error || "")
    }));
}

async function submit_current_form_if_available(page, timeout_ms)
{
    const submit_button = await find_first_visible([
        page.getByRole("button", { name: /kirim/i }).first(),
        page.locator("input.sd-navigation__complete-btn[value*='Kirim']").first(),
        page.locator("input.sd-navigation__complete-btn").first()
    ], 1000);
    if (!submit_button)
        return false;

    await submit_button.scrollIntoViewIfNeeded().catch(() => { });
    const clicked = await run_async_fallbacks([
        () => submit_button.click({ timeout: sel.wait.click_timeout_ms }),
        () => submit_button.click({ force: true, timeout: sel.wait.click_timeout_ms })
    ]);
    if (!clicked)
        return false;

    await wait_page_stable(page, timeout_ms);
    return true;
}

async function wait_form_question_ready(page, timeout_ms)
{
    const max_wait_ms = Math.max(1800, Math.min(timeout_ms, 6000));
    const deadline = Date.now() + max_wait_ms;
    while (Date.now() < deadline)
    {
        const question_count = await page.locator("div.sd-question").count().catch(() => 0);
        if (question_count > 0)
            return true;

        const page_title = page.locator("h4.sd-page__title").first();
        if (await page_title.isVisible({ timeout: 200 }).catch(() => false))
        {
            await safe_wait(page, 120);
            const check_again = await page.locator("div.sd-question").count().catch(() => 0);
            if (check_again > 0)
                return true;
        }

        await safe_wait(page, 90);
    }

    return false;
}

async function run_form_auto_fill_with_retry(page, answer_map, ordered_answers, refill_answered, max_try = 3)
{
    const safe_max_try = Math.max(1, Number(max_try) || 1);
    let last_question_data_after_fill = {
        total_pertanyaan: 0,
        total_pertanyaan_wajib: 0,
        total_belum_terjawab: 0,
        items: []
    };
    const merged_auto_fill_result = {
        total_questions: 0,
        total_filled: 0,
        total_answered_after: 0,
        total_unmapped: 0
    };

    for (let try_index = 0; try_index < safe_max_try; try_index += 1)
    {
        ensure_worker_running();
        const use_refill_answered = refill_answered || try_index > 0;
        const auto_fill_result = await auto_fill_form_answers(page, answer_map, ordered_answers, {
            refill_answered: use_refill_answered
        });
        const question_data_after_fill = await collect_questions_from_form(page);
        last_question_data_after_fill = question_data_after_fill;

        merged_auto_fill_result.total_questions = Math.max(
            Number(merged_auto_fill_result.total_questions || 0),
            Number(auto_fill_result?.total_questions || 0)
        );
        merged_auto_fill_result.total_filled += Number(auto_fill_result?.total_filled || 0);
        merged_auto_fill_result.total_answered_after = Number(auto_fill_result?.total_answered_after || 0);
        merged_auto_fill_result.total_unmapped = Math.max(
            Number(merged_auto_fill_result.total_unmapped || 0),
            Number(auto_fill_result?.total_unmapped || 0)
        );

        const remaining_required = Number(question_data_after_fill?.total_belum_terjawab || 0);
        if (remaining_required <= 0)
        {
            return {
                auto_fill_result: merged_auto_fill_result,
                question_data_after_fill: last_question_data_after_fill,
                fill_retry_count: try_index + 1
            };
        }

        await safe_wait(page, 120 + (try_index * 100));
    }

    return {
        auto_fill_result: merged_auto_fill_result,
        question_data_after_fill: last_question_data_after_fill,
        fill_retry_count: safe_max_try
    };
}

async function collect_all_pemeriksaan_mandiri_forms(
    page,
    timeout_ms,
    detail_page_url,
    status_items,
    pemeriksaan_mandiri_payload = {},
    auto_submit_form = false,
    execution_options = {}
)
{
    const force_click_all_rows = execution_options?.force_click_all_rows === true;
    const fast_click_mode = execution_options?.fast_click_mode === true;
    const refill_answered = execution_options?.refill_answered === true;
    const only_index = Number(execution_options?.only_index || 0);
    const detailed_question_audit = execution_options?.detailed_question_audit === true;
    const form_targets = status_items
        .filter((item) =>
            /input data/i.test(String(item.aksi || "")) &&
            (force_click_all_rows || String(item.status || "") !== "sudah_dilayani")
        )
        .slice()
        .sort((a, b) => Number(a?.row_index ?? 99999) - Number(b?.row_index ?? 99999));
    const filtered_targets = only_index > 0 && only_index <= form_targets.length
        ? [form_targets[only_index - 1]]
        : form_targets;
    const service_list = build_question_bank_service_list(pemeriksaan_mandiri_payload);
    const form_results = [];
    const ensure_back_to_table = async () =>
    {
        if (!(await is_page_alive(page)))
            return false;

        const ready_now = await wait_pemeriksaan_mandiri_table_ready(page, 1400).catch(() => false);
        if (ready_now)
            return true;

        const back_locators = [
            page.getByRole("button", { name: /kembali ke halaman utama|kembali/i }).first(),
            page.getByRole("link", { name: /kembali ke halaman utama|kembali/i }).first(),
            page.locator("button:has-text('Kembali'), a:has-text('Kembali'), [role='button']:has-text('Kembali')").first()
        ];
        let back_clicked = false;
        for (const locator of back_locators)
        {
            const back_visible = await locator.isVisible({ timeout: 500 }).catch(() => false);
            if (!back_visible)
                continue;

            const clicked = await run_async_fallbacks([
                () => locator.click({ timeout: 2000 }),
                () => locator.click({ force: true, timeout: 2000 })
            ]);
            if (!clicked)
                continue;

            back_clicked = true;
            const ready_after_back = await wait_pemeriksaan_mandiri_table_ready(page, 6000).catch(() => false);
            if (ready_after_back)
                return true;
        }
        if (back_clicked)
            await safe_wait(page, 180);

        await safe_goto(page, detail_page_url, timeout_ms);
        return await wait_pemeriksaan_mandiri_table_ready(page, Math.min(timeout_ms, 8000)).catch(() => false);
    };

    for (let index = 0; index < filtered_targets.length; index += 1)
    {
        ensure_worker_running();
        await close_processing_in_progress_modal_if_visible(page, timeout_ms);
        if (!(await is_page_alive(page)))
        {
            log("ERROR", "pemeriksaan_mandiri_page_dead", { step: `${index + 1}/${filtered_targets.length}` });
            break;
        }

        log("INFO", "pemeriksaan_mandiri_form_progress", {
            step: `${index + 1}/${filtered_targets.length}`,
            layanan: String(filtered_targets[index]?.layanan || "-"),
            row_id: String(filtered_targets[index]?.row_id || "-")
        });
        const table_ready_now = await wait_pemeriksaan_mandiri_table_ready(page, fast_click_mode ? 900 : 1400).catch(() => false);
        if (!table_ready_now)
            await ensure_back_to_table();

        await handle_exam_date_confirmation_if_visible(page, timeout_ms).catch(() => false);
        await close_processing_in_progress_modal_if_visible(page, timeout_ms);

        const target_item = filtered_targets[index];
        let opened_item = await click_input_data_by_row(page, timeout_ms, target_item);
        if (!opened_item)
        {
            log("WARN", "pemeriksaan_mandiri_click_row_retry", {
                layanan: String(target_item?.layanan || "-"),
                row_id: String(target_item?.row_id || "-")
            });
            await safe_goto(page, detail_page_url, timeout_ms);
            await wait_pemeriksaan_mandiri_table_ready(page, timeout_ms);
            opened_item = await click_input_data_by_row(page, timeout_ms, target_item);
        }
        if (!opened_item)
        {
            const max_retry_step = 3;
            for (let retry_step = 1; retry_step <= max_retry_step; retry_step += 1)
            {
                log("WARN", "pemeriksaan_mandiri_click_row_retry_step", {
                    layanan: String(target_item?.layanan || "-"),
                    row_id: String(target_item?.row_id || "-"),
                    retry_step
                });

                await safe_wait(page, 220 + (retry_step * 180));
                await handle_exam_date_confirmation_if_visible(page, timeout_ms).catch(() => false);

                const latest_status = await collect_pemeriksaan_mandiri_table_data(page).catch(() => null);
                const latest_items = Array.isArray(latest_status?.items) ? latest_status.items : [];
                const refreshed_item = latest_items.find((item) =>
                {
                    const item_row_id = String(item?.row_id || "").trim();
                    const item_layanan = String(item?.layanan || "").trim().toLowerCase();
                    const target_row_id = String(target_item?.row_id || "").trim();
                    const target_layanan = String(target_item?.layanan || "").trim().toLowerCase();
                    if (target_row_id !== "" && item_row_id === target_row_id)
                        return true;
                    if (target_layanan !== "" && item_layanan === target_layanan)
                        return true;
                    return false;
                }) || target_item;

                opened_item = await click_input_data_by_row(page, timeout_ms, refreshed_item);
                if (opened_item)
                    break;

                await safe_goto(page, detail_page_url, timeout_ms);
                await wait_pemeriksaan_mandiri_table_ready(page, timeout_ms).catch(() => false);
            }
        }
        if (!opened_item)
        {
            log("WARN", "pemeriksaan_mandiri_click_row_failed", {
                layanan: String(target_item?.layanan || "-"),
                row_id: String(target_item?.row_id || "-")
            });
            form_results.push({
                layanan: target_item.layanan || "-",
                status: target_item.status || "-",
                row_id: target_item.row_id || "-",
                opened: "no",
                form_ready: "no",
                question_data: {
                    total_pertanyaan: 0,
                    total_pertanyaan_wajib: 0,
                    total_belum_terjawab: 0,
                    items: []
                }
            });
            continue;
        }

        const form_ready = await wait_form_question_ready(page, timeout_ms);
        if (!form_ready)
            log("WARN", "pemeriksaan_mandiri_form_not_ready", { layanan: String(opened_item?.layanan || "-") });
        const form_title = form_ready ? await collect_form_title(page) : "";
        const matched_service = find_question_bank_service(
            service_list,
            opened_item.layanan || target_item.layanan || "",
            form_title
        );
        const specific_answer_map = matched_service
            ? build_question_answer_map(matched_service)
            : {};
        const answer_map = {
            ...specific_answer_map
        };
        const ordered_answers = matched_service
            ? build_question_answer_list(matched_service)
            : [];
        const empty_question_data = {
            total_pertanyaan: 0,
            total_pertanyaan_wajib: 0,
            total_belum_terjawab: 0,
            items: []
        };
        const question_data_before = detailed_question_audit && form_ready
            ? await collect_questions_from_form(page)
            : empty_question_data;
        const fill_result = form_ready
            ? await run_form_auto_fill_with_retry(page, answer_map, ordered_answers, refill_answered, 3)
            : {
                auto_fill_result: {
                    total_questions: 0,
                    total_filled: 0,
                    total_answered_after: 0,
                    total_unmapped: 0
                },
                question_data_after_fill: empty_question_data,
                fill_retry_count: 0
            };
        const auto_fill_result = fill_result.auto_fill_result;
        const question_data_after_fill = fill_result.question_data_after_fill;
        const can_submit_form = Number(question_data_after_fill?.total_belum_terjawab || 0) <= 0;
        const submit_done = form_ready && auto_submit_form && can_submit_form
            ? await submit_current_form_if_available(page, timeout_ms)
            : false;
        const question_data = form_ready
            ? await collect_questions_from_form(page)
            : empty_question_data;
        const answer_match_source = question_data_after_fill;

        form_results.push({
            layanan: opened_item.layanan || target_item.layanan || "-",
            status: target_item.status || "-",
            row_id: target_item.row_id || "-",
            opened: "yes",
            form_ready: form_ready ? "yes" : "no",
            form_title: form_title || "-",
            question_bank_match: matched_service?.nama || "global_fallback",
            answer_map_size: Object.keys(answer_map || {}).length,
            ordered_answers_size: Array.isArray(ordered_answers) ? ordered_answers.length : 0,
            auto_fill_result,
            fill_retry_count: Number(fill_result?.fill_retry_count || 0),
            question_data_before,
            question_data_after_fill,
            answer_match_summary: build_answer_match_summary(answer_match_source, answer_map, ordered_answers),
            submit_done: submit_done ? "yes" : "no",
            question_data
        });

        await ensure_back_to_table();
    }

    return {
        total_layanan: filtered_targets.length,
        total_form_berhasil_dibuka: form_results.filter((item) => item.opened === "yes").length,
        total_form_siaga: form_results.filter((item) => item.form_ready === "yes").length,
        results: form_results
    };
}

async function wait_pelayanan_nakes_ready(page, timeout_ms)
{
    const max_wait_ms = Math.max(2500, Math.min(timeout_ms, 12000));
    const deadline = Date.now() + max_wait_ms;
    while (Date.now() < deadline)
    {
        const ready_state = await page.evaluate(() =>
        {
            const has_title = Array.from(document.querySelectorAll("div,span,h3,h4"))
                .some((node) => /pelayanan oleh nakes/i.test(String(node.textContent || "").replace(/\s+/g, " ").trim()));
            const form_row_count = document.querySelectorAll("div[id^='rowfrm']").length;
            return {
                has_title,
                form_row_count
            };
        }).catch(() => ({
            has_title: false,
            form_row_count: 0
        }));

        if (Number(ready_state?.form_row_count || 0) > 0)
            return true;
        if (Boolean(ready_state?.has_title) && Number(ready_state?.form_row_count || 0) > 0)
            return true;

        await page.evaluate(() =>
        {
            const height = Math.max(document.body?.scrollHeight || 0, document.documentElement?.scrollHeight || 0);
            window.scrollTo(0, Math.max(0, Math.floor(height * 0.7)));
        }).catch(() => { });
        await safe_wait(page, 150);
    }

    return false;
}

async function ensure_back_to_pelayanan_nakes_table(page, timeout_ms, detail_page_url)
{
    const ready_now = await wait_pelayanan_nakes_ready(page, 1200).catch(() => false);
    if (ready_now)
        return true;

    await safe_goto(page, detail_page_url, timeout_ms);
    return await wait_pelayanan_nakes_ready(page, Math.min(timeout_ms, 7000)).catch(() => false);
}

function is_nakes_status_done(status_text)
{
    const value = String(status_text || "").toLowerCase().replace(/\s+/g, " ").trim();
    if (value === "")
        return false;
    return value.includes("sudah") || value.includes("selesai");
}

async function collect_all_pelayanan_nakes_forms(
    page,
    timeout_ms,
    detail_page_url,
    pemeriksaan_nakes_payload = {},
    auto_submit_form = false,
    execution_options = {}
)
{
    const force_click_all_rows = execution_options?.force_click_all_rows === true;
    const refill_answered = execution_options?.refill_answered === true;
    const detailed_question_audit = execution_options?.detailed_question_audit === true;
    const service_list = build_question_bank_service_list(pemeriksaan_nakes_payload);
    const form_results = [];

    await wait_pelayanan_nakes_ready(page, timeout_ms).catch(() => false);
    const pelayanan_nakes_status = await collect_pelayanan_nakes(page, { prepare: true }).catch(() => ({
        items: []
    }));
    const raw_items = Array.isArray(pelayanan_nakes_status?.items) ? pelayanan_nakes_status.items : [];
    const form_targets = raw_items
        .filter((item) =>
            /input data/i.test(String(item?.aksi || "")) &&
            String(item?.aksi_bisa_klik || "").toLowerCase() === "ya" &&
            (force_click_all_rows || !is_nakes_status_done(item?.status)) &&
            Boolean(find_question_bank_service_strict(service_list, item?.layanan || "", ""))
        )
        .slice()
        .sort((a, b) => Number(a?.row_index ?? 99999) - Number(b?.row_index ?? 99999));

    for (let index = 0; index < form_targets.length; index += 1)
    {
        ensure_worker_running();
        await close_processing_in_progress_modal_if_visible(page, timeout_ms);
        if (!(await is_page_alive(page)))
            break;

        const target_item = form_targets[index];
        log("INFO", "pelayanan_nakes_form_progress", {
            step: `${index + 1}/${form_targets.length}`,
            kategori: String(target_item?.kategori || "-"),
            layanan: String(target_item?.layanan || "-"),
            row_id: String(target_item?.row_id || "-")
        });

        await handle_exam_date_confirmation_if_visible(page, timeout_ms).catch(() => false);
        await close_processing_in_progress_modal_if_visible(page, timeout_ms);

        let opened_item = await click_input_data_by_row(page, timeout_ms, target_item);
        if (!opened_item)
        {
            await safe_wait(page, 180);
            await collect_pelayanan_nakes(page, { prepare: false }).catch(() => null);
            opened_item = await click_input_data_by_row(page, timeout_ms, target_item);
        }

        if (!opened_item)
        {
            form_results.push({
                kategori: target_item?.kategori || "-",
                layanan: target_item?.layanan || "-",
                status: target_item?.status || "-",
                row_id: target_item?.row_id || "-",
                opened: "no",
                form_ready: "no",
                question_data: {
                    total_pertanyaan: 0,
                    total_pertanyaan_wajib: 0,
                    total_belum_terjawab: 0,
                    items: []
                }
            });
            continue;
        }

        const form_ready = await wait_form_question_ready(page, timeout_ms);
        const form_title = form_ready ? await collect_form_title(page) : "";
        const matched_service = find_question_bank_service_strict(
            service_list,
            target_item?.layanan || "",
            form_title
        );
        if (!matched_service)
        {
            const empty_question_data = {
                total_pertanyaan: 0,
                total_pertanyaan_wajib: 0,
                total_belum_terjawab: 0,
                items: []
            };
            const quick_question_data = form_ready
                ? await collect_questions_from_form(page)
                : empty_question_data;
            form_results.push({
                kategori: target_item?.kategori || "-",
                layanan: target_item?.layanan || "-",
                status: target_item?.status || "-",
                row_id: target_item?.row_id || "-",
                opened: "yes",
                form_ready: form_ready ? "yes" : "no",
                form_title: form_title || "-",
                question_bank_match: "no_match_skip",
                answer_map_size: 0,
                ordered_answers_size: 0,
                auto_fill_result: {
                    total_questions: 0,
                    total_filled: 0,
                    total_answered_after: 0,
                    total_unmapped: 0
                },
                question_data_before: empty_question_data,
                question_data_after_fill: empty_question_data,
                answer_match_summary: {
                    total_checked: 0,
                    total_match: 0,
                    total_mismatch: 0,
                    ok: true,
                    mismatches: []
                },
                submit_done: "no",
                question_data: quick_question_data
            });
            await ensure_back_to_pelayanan_nakes_table(page, timeout_ms, detail_page_url);
            continue;
        }
        const specific_answer_map = matched_service
            ? build_question_answer_map(matched_service)
            : {};
        const answer_map = {
            ...specific_answer_map
        };
        const ordered_answers = matched_service
            ? build_question_answer_list(matched_service)
            : [];
        const empty_question_data = {
            total_pertanyaan: 0,
            total_pertanyaan_wajib: 0,
            total_belum_terjawab: 0,
            items: []
        };
        const question_data_before = detailed_question_audit && form_ready
            ? await collect_questions_from_form(page)
            : empty_question_data;
        const fill_result = form_ready
            ? await run_form_auto_fill_with_retry(page, answer_map, ordered_answers, refill_answered, 3)
            : {
                auto_fill_result: {
                    total_questions: 0,
                    total_filled: 0,
                    total_answered_after: 0,
                    total_unmapped: 0
                },
                question_data_after_fill: empty_question_data,
                fill_retry_count: 0
            };
        const auto_fill_result = fill_result.auto_fill_result;
        const question_data_after_fill = fill_result.question_data_after_fill;
        const can_submit_form = Number(question_data_after_fill?.total_belum_terjawab || 0) <= 0;
        const submit_done = form_ready && auto_submit_form && can_submit_form
            ? await submit_current_form_if_available(page, timeout_ms)
            : false;
        const question_data = form_ready
            ? await collect_questions_from_form(page)
            : empty_question_data;
        const answer_match_source = question_data_after_fill;

        form_results.push({
            kategori: target_item?.kategori || "-",
            layanan: target_item?.layanan || "-",
            status: target_item?.status || "-",
            row_id: target_item?.row_id || "-",
            opened: "yes",
            form_ready: form_ready ? "yes" : "no",
            form_title: form_title || "-",
            question_bank_match: matched_service?.nama || "global_fallback",
            answer_map_size: Object.keys(answer_map || {}).length,
            ordered_answers_size: Array.isArray(ordered_answers) ? ordered_answers.length : 0,
            auto_fill_result,
            fill_retry_count: Number(fill_result?.fill_retry_count || 0),
            question_data_before,
            question_data_after_fill,
            answer_match_summary: build_answer_match_summary(answer_match_source, answer_map, ordered_answers),
            submit_done: submit_done ? "yes" : "no",
            question_data
        });

        await ensure_back_to_pelayanan_nakes_table(page, timeout_ms, detail_page_url);
    }

    return {
        total_layanan: form_targets.length,
        total_form_berhasil_dibuka: form_results.filter((item) => item.opened === "yes").length,
        total_form_siaga: form_results.filter((item) => item.form_ready === "yes").length,
        results: form_results
    };
}

export async function run_self_examination_flow(page, timeout_ms, options = {})
{
    ensure_worker_running();
    await close_send_report_limit_modal_if_visible(page, timeout_ms);
    await close_processing_in_progress_modal_if_visible(page, timeout_ms);
    const should_start_examination = options?.should_start_examination === true;
    const pemeriksaan_mandiri_payload = options?.pemeriksaan_mandiri_payload || {};
    const pemeriksaan_nakes_payload = options?.pemeriksaan_nakes_payload || {};
    const patient_data = options?.patient_data || {};
    const auto_submit_form = resolve_boolean_option(options, "auto_submit_form", "DEV_PEMERIKSAAN_MANDIRI_AUTO_SUBMIT", true);
    const recheck_completed_form = resolve_boolean_option(options, "recheck_completed_form", "DEV_PEMERIKSAAN_MANDIRI_RECHECK_COMPLETED", true);
    const force_click_all_rows = recheck_completed_form;
    const fast_click_mode = true;
    const refill_answered = resolve_boolean_option(options, "refill_answered", "DEV_PEMERIKSAAN_MANDIRI_REFILL_ANSWERED", false);
    const detailed_question_audit = resolve_boolean_option(options, "detailed_question_audit", "", should_save_web_question_snapshot());
    const log_question_detail = resolve_boolean_option(options, "log_question_detail", "DEV_LOG_QUESTION_DETAIL", true);
    const only_index = Math.trunc(resolve_number_option(options, "only_index", "DEV_PEMERIKSAAN_MANDIRI_ONLY_INDEX", 0));
    ensure_worker_running();
    const start_and_date = await ensure_start_and_date_confirmation(page, timeout_ms, should_start_examination);
    const start_examination_clicked = start_and_date.start_examination_clicked;
    const examination_date_button_clicked = start_and_date.examination_date_button_clicked;
    const exam_date_confirmation_done = start_and_date.exam_date_confirmation_done;
    const detail_page_url = String(page.url() || "");
    ensure_worker_running();
    await close_send_report_limit_modal_if_visible(page, timeout_ms);
    await close_processing_in_progress_modal_if_visible(page, timeout_ms);
    await wait_pemeriksaan_mandiri_table_ready(page, timeout_ms);
    let pemeriksaan_mandiri_status = await collect_pemeriksaan_mandiri_table_data(page);
    if (!Array.isArray(pemeriksaan_mandiri_status?.items) || pemeriksaan_mandiri_status.items.length === 0)
    {
        await safe_wait(page, 650);
        await wait_pemeriksaan_mandiri_table_ready(page, Math.min(timeout_ms, 10000));
        pemeriksaan_mandiri_status = await collect_pemeriksaan_mandiri_table_data(page);
    }
    const pemeriksaan_mandiri_form_list = await collect_all_pemeriksaan_mandiri_forms(
        page,
        timeout_ms,
        detail_page_url,
        pemeriksaan_mandiri_status.items || [],
        pemeriksaan_mandiri_payload,
        auto_submit_form,
        {
            force_click_all_rows,
            fast_click_mode,
            refill_answered,
            detailed_question_audit,
            only_index
        }
    );
    ensure_worker_running();
    await close_send_report_limit_modal_if_visible(page, timeout_ms);
    await close_processing_in_progress_modal_if_visible(page, timeout_ms);
    await collect_pelayanan_nakes(page, { prepare: true });
    const pelayanan_nakes_form_list = await collect_all_pelayanan_nakes_forms(
        page,
        timeout_ms,
        detail_page_url,
        pemeriksaan_nakes_payload,
        auto_submit_form,
        {
            force_click_all_rows,
            refill_answered,
            detailed_question_audit
        }
    );
    ensure_worker_running();
    await close_send_report_limit_modal_if_visible(page, timeout_ms);
    await close_processing_in_progress_modal_if_visible(page, timeout_ms);
    const pelayanan_nakes_after = await collect_pelayanan_nakes(page, { prepare: false });
    ensure_worker_running();
    const send_report_clicked = await click_send_report_button_once(page, timeout_ms);
    ensure_worker_running();
    const finish_service_clicked = await click_finish_service_button_required(page, timeout_ms);

    if (log_question_detail)
    {
        log("INFO", "pemeriksaan_mandiri_status_list", pemeriksaan_mandiri_status);
        log("INFO", "pemeriksaan_mandiri_form_list", pemeriksaan_mandiri_form_list);
        log("INFO", "pelayanan_nakes_question_list", pelayanan_nakes_after);
        log("INFO", "pelayanan_nakes_form_list", pelayanan_nakes_form_list);
    }
    log("INFO", "pelayanan_final_action", {
        send_report_clicked: send_report_clicked ? "yes" : "no",
        finish_service_clicked: finish_service_clicked ? "yes" : "no"
    });
    log("INFO", "pemeriksaan_mandiri_started", {
        should_start_examination: should_start_examination ? "yes" : "no",
        start_examination_clicked: start_examination_clicked ? "yes" : "no",
        examination_date_button_clicked: examination_date_button_clicked ? "yes" : "no",
        exam_date_confirmation: exam_date_confirmation_done ? "yes" : "no",
        recheck_completed_form: recheck_completed_form ? "yes" : "no",
        refill_answered: refill_answered ? "yes" : "no",
        only_index: only_index > 0 ? String(only_index) : "-"
    });
    const form_results = Array.isArray(pemeriksaan_mandiri_form_list?.results)
        ? pemeriksaan_mandiri_form_list.results
        : [];
    const submit_done_total = form_results
        .filter((item) => String(item?.submit_done || "").toLowerCase() === "yes")
        .length;
    const flow_result = {
        should_start_examination,
        start_examination_clicked,
        examination_date_button_clicked,
        exam_date_confirmation_done,
        pemeriksaan_mandiri_payload,
        pemeriksaan_nakes_payload,
        pemeriksaan_mandiri_status,
        pemeriksaan_mandiri_form_list,
        pelayanan_nakes: pelayanan_nakes_after,
        pelayanan_nakes_form_list,
        send_report_clicked,
        finish_service_clicked,
        submit_done_total
    };

    const snapshot_path = save_web_question_snapshot(flow_result, { patient_data });
    if (snapshot_path !== "")
        log("INFO", "pemeriksaan_mandiri_web_question_snapshot_saved", {
            path: snapshot_path,
            nik: String(patient_data?.nik || ""),
            total_form: Number(pemeriksaan_mandiri_form_list?.total_layanan || 0)
        });

    return flow_result;
}
