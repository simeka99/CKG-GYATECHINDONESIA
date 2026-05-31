import fs from "fs";
import path from "path";
import { log } from "../../core/helpers.js";
import { ensure_session_active } from "../../core/auth.js";
import { clear_blocking_modal_for_pelayanan, click_mulai_for_visible_row, ensure_profile_saved, is_session_error_modal_visible, submit_same_location_for_pelayanan } from "./flow.js";
import { inspect_peserta_status_after_search, search_peserta_by_nik } from "./validasi_data.js";
import { resolve_timeout_ms, safe_wait, is_page_alive, safe_goto } from "./helper.js";
import { run_self_examination_flow } from "./pemeriksaan_mandiri.js";
import { api_fetch_batch, api_report_result } from "../../api/client.js";
import { worker_state } from "../../api/state.js";
import { resolve_pemeriksaan_payload_pair } from "./payload_splitter.js";

function is_on_login_page(page)
{
    const url = String(page.url() || "");
    return url.includes("/auth/login") || url.includes("/auth/register");
}

function is_on_external_form_page(page)
{
    const url = String(page.url() || "");
    return url.includes("form.kemkes.go.id/");
}

function resolve_cookies_file()
{
    const storage_dir = String(process.env.STORAGE_DIR || "").trim();
    if (storage_dir === "")
        return "";
    return path.join(storage_dir, "cookies.json");
}

async function recover_session_if_needed(page, config, timeout_ms)
{
    const on_login = is_on_login_page(page);
    const has_session_error = !on_login && await is_session_error_modal_visible(page).catch(() => false);

    if (!on_login && !has_session_error)
        return false;

    if (has_session_error)
        log("WARN", "session_error_modal_detected_triggering_relogin", { url: page.url() });
    else
        log("WARN", "session_lost_detected_on_login_page", { url: page.url() });

    const cookies_file = resolve_cookies_file();
    const context = page.context();

    try
    {
        await ensure_session_active(page, context, config, cookies_file);
        log("INFO", "session_recovered_successfully");
        return true;
    }
    catch (error)
    {
        log("ERROR", "session_recovery_failed", { error: String(error?.message || error || "") });
        const recovery_error = new Error("Sesi habis dan gagal login ulang: " + String(error?.message || ""));
        recovery_error.code = String(error?.code || "SESSION_RELOGIN_FAILED");
        throw recovery_error;
    }
}

async function detect_runtime_state(page)
{
    const url = String(page.url() || "");
    const has_search_input = await page.locator("#searchNik, input[placeholder*='NIK']").first().isVisible({ timeout: 250 }).catch(() => false);
    const has_pelayanan_table = await page.locator(".table-individu-terdaftar table").first().isVisible({ timeout: 250 }).catch(() => false);
    const has_back_to_main = await page.getByRole("button", { name: /kembali ke halaman utama/i }).first().isVisible({ timeout: 250 }).catch(() => false);
    const has_privacy_modal = await page.locator("div, h1, h2, h3, span").filter({ hasText: /pemberitahuan privasi dan ketentuan penggunaan/i }).first().isVisible({ timeout: 250 }).catch(() => false);
    const has_login_warning_modal = await page.locator("div, span").filter({ hasText: /belum berhasil masuk|email atau kata sandi/i }).first().isVisible({ timeout: 250 }).catch(() => false);
    const has_session_error_modal = await page.locator("div, span").filter({ hasText: /silahkan lakukan login ulang/i }).first().isVisible({ timeout: 250 }).catch(() => false);

    return {
        url,
        is_login: is_on_login_page(page),
        is_profile: url.includes("/profile"),
        is_pelayanan: url.includes("/ckg-pelayanan"),
        is_external_form: is_on_external_form_page(page),
        has_search_input: Boolean(has_search_input),
        has_pelayanan_table: Boolean(has_pelayanan_table),
        has_back_to_main: Boolean(has_back_to_main),
        has_session_error_modal: Boolean(has_session_error_modal),
        has_blocking_modal: Boolean(
            has_privacy_modal ||
            has_login_warning_modal ||
            has_session_error_modal
        )
    };
}

async function return_from_external_form_if_needed(page, timeout_ms)
{
    if (!is_on_external_form_page(page))
        return false;

    const target_button = page.getByRole("button", { name: /kembali ke halaman utama/i }).first();
    const visible = await target_button.isVisible({ timeout: 500 }).catch(() => false);
    if (!visible)
        return false;

    const clicked = await target_button.click({ timeout: Math.min(timeout_ms, 2500) }).then(() => true).catch(async () =>
    {
        return await target_button.click({ force: true, timeout: Math.min(timeout_ms, 2500) }).then(() => true).catch(() => false);
    });
    if (!clicked)
        return false;

    await safe_wait(page, 900);
    return true;
}

function normalize_status_text(value)
{
    return String(value || "").toLowerCase().replace(/\s+/g, " ").trim();
}

function parse_boolean_value(value, fallback_value = false)
{
    if (typeof value === "boolean")
        return value;

    const text = String(value ?? "").trim().toLowerCase();
    if (text === "")
        return fallback_value;
    if (text === "1" || text === "true" || text === "yes" || text === "on")
        return true;
    if (text === "0" || text === "false" || text === "no" || text === "off")
        return false;
    return fallback_value;
}

function parse_integer_value(value, fallback_value = 0)
{
    const numeric_value = Number(value);
    if (!Number.isFinite(numeric_value))
        return fallback_value;
    return Math.trunc(numeric_value);
}

function resolve_mandiri_flow_options(config)
{
    const meta = config?.meta || {};
    return {
        auto_submit_form: parse_boolean_value(meta.pemeriksaan_mandiri_auto_submit, true),
        recheck_completed_form: parse_boolean_value(meta.pemeriksaan_mandiri_recheck_completed, true),
        refill_answered: parse_boolean_value(meta.pemeriksaan_mandiri_refill_answered, false),
        only_index: parse_integer_value(meta.pemeriksaan_mandiri_only_index, 0),
        detailed_question_audit: config?.debug?.save_artifacts === true
    };
}

async function resolve_status_after_search(page, timeout_ms, nik_value)
{
    const used_nik = await search_peserta_by_nik(page, timeout_ms, nik_value || "");
    let status = await inspect_peserta_status_after_search(page, used_nik);
    if (status?.found !== false && String(status?.status_tab || "") !== "not_found")
        return { used_nik, status };

    log("WARN", "search_status_retry_once", {
        nik: used_nik,
        status_tab: String(status?.status_tab || "not_found"),
        reason: String(status?.reason || "row_not_found")
    });

    await safe_wait(page, 450);
    const used_nik_retry = await search_peserta_by_nik(page, timeout_ms, used_nik);
    status = await inspect_peserta_status_after_search(page, used_nik_retry);
    return { used_nik: used_nik_retry, status };
}

function derive_status_text(out)
{
    const code = String(out?.reg?.code || "").trim();
    const text = String(out?.reg?.text || "").trim();
    if (text !== "")
        return text;
    if (code !== "")
        return code;
    return out?.ok ? "DILAYANI" : "ERROR";
}

function resolve_error_code(error_message)
{
    const message = normalize_status_text(error_message);
    if (message.includes("batas kirim rapor habis") || message.includes("3 kali mengirimkan rapor kesehatan"))
        return "BATAS_KIRIM_RAPOR_HABIS";
    if (message.includes("stopped_by_server"))
        return "STOPPED_BY_SERVER";
    if (message.includes("data pemeriksaan sedang diproses"))
        return "DATA_PEMERIKSAAN_DIPROSES";
    if (message.includes("tidak ditemukan") || message.includes("not found") || message.includes("tidak ada di daftar"))
        return "NOT_IN_LIST";
    if (message.includes("nik"))
        return "NOT_IN_LIST";
    return "ERROR";
}

function ensure_worker_can_continue()
{
    const is_worker_mode = String(process.env.WORKER_MODE || "").trim() === "1";
    if (!is_worker_mode)
        return;
    if (worker_state.should_run !== false)
        return;
    const error = new Error("STOPPED_BY_SERVER");
    error.code = "STOPPED_BY_SERVER";
    throw error;
}

function summarize_answer_quality(form_results)
{
    const result_items = Array.isArray(form_results) ? form_results : [];
    const summary = {
        total_checked: 0,
        total_mismatch: 0,
        total_unmapped: 0,
        total_unmatched_form: 0
    };

    for (const item of result_items)
    {
        const checked = Number(item?.answer_match_summary?.total_checked || 0);
        const mismatch = Number(item?.answer_match_summary?.total_mismatch || 0);
        const unmapped = Number(item?.auto_fill_result?.total_unmapped || 0);
        const question_bank_match = String(item?.question_bank_match || "").trim().toLowerCase();

        summary.total_checked += Number.isFinite(checked) ? checked : 0;
        summary.total_mismatch += Number.isFinite(mismatch) ? mismatch : 0;
        summary.total_unmapped += Number.isFinite(unmapped) ? unmapped : 0;
        if (question_bank_match === "no_match_skip" || question_bank_match === "global_fallback")
            summary.total_unmatched_form += 1;
    }

    return summary;
}

function resolve_local_pemeriksaan_payload(config)
{
    const file_from_env = String(process.env.DEV_PEMERIKSAAN_MANDIRI_FILE || "").trim();
    const file_from_config = String(config?.meta?.pemeriksaan_mandiri_file || "").trim();
    const source_file = file_from_env !== "" ? file_from_env : file_from_config;
    if (source_file === "")
        return {
            pemeriksaan_mandiri_payload: {},
            pemeriksaan_nakes_payload: {}
        };

    const resolved_path = path.isAbsolute(source_file)
        ? source_file
        : path.resolve(process.cwd(), source_file);

    if (!fs.existsSync(resolved_path))
    {
        log("WARN", "pemeriksaan_mandiri_local_file_not_found", { path: resolved_path });
        return {
            pemeriksaan_mandiri_payload: {},
            pemeriksaan_nakes_payload: {}
        };
    }

    try
    {
        const raw = fs.readFileSync(resolved_path, "utf-8");
        const json = JSON.parse(raw || "{}");
        const payload_pair = resolve_pemeriksaan_payload_pair(
            json?.pemeriksaan_mandiri && typeof json.pemeriksaan_mandiri === "object"
                ? json
                : { pemeriksaan_mandiri: json }
        );
        const pemeriksaan_mandiri_payload = payload_pair?.pemeriksaan_mandiri_payload || {};
        const pemeriksaan_nakes_payload = payload_pair?.pemeriksaan_nakes_payload || {};
        const total_pertanyaan = Number(pemeriksaan_mandiri_payload?.total_pertanyaan || 0);
        const total_jenis_pemeriksaan = Number(pemeriksaan_mandiri_payload?.total_jenis_pemeriksaan || 0);
        const total_pertanyaan_nakes = Number(pemeriksaan_nakes_payload?.total_pertanyaan || 0);
        const total_jenis_pemeriksaan_nakes = Number(pemeriksaan_nakes_payload?.total_jenis_pemeriksaan || 0);
        log("INFO", "pemeriksaan_mandiri_local_file_loaded", {
            path: resolved_path,
            total_jenis_pemeriksaan,
            total_pertanyaan,
            total_jenis_pemeriksaan_nakes,
            total_pertanyaan_nakes
        });
        return {
            pemeriksaan_mandiri_payload: pemeriksaan_mandiri_payload && typeof pemeriksaan_mandiri_payload === "object"
                ? pemeriksaan_mandiri_payload
                : {},
            pemeriksaan_nakes_payload: pemeriksaan_nakes_payload && typeof pemeriksaan_nakes_payload === "object"
                ? pemeriksaan_nakes_payload
                : {}
        };
    } catch (error)
    {
        log("WARN", "pemeriksaan_mandiri_local_file_invalid", {
            path: resolved_path,
            error: String(error?.message || error || "")
        });
        return {
            pemeriksaan_mandiri_payload: {},
            pemeriksaan_nakes_payload: {}
        };
    }
}

async function ensure_pelayanan_page(page, config, timeout_ms)
{
    const pelayanan_url = config?.urls?.ckg_umum?.pelayanan || "";
    if (!pelayanan_url)
        throw new Error("URL pelayanan CKG-UMUM tidak ada di config");

    const max_attempt = 6;
    for (let index = 0; index < max_attempt; index += 1)
    {
        const modal_result = await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => ({ closed_any: false, needs_relogin: false }));
        if (modal_result.needs_relogin)
            await recover_session_if_needed(page, config, timeout_ms).catch(() => { });

        await recover_session_if_needed(page, config, timeout_ms);

        const state = await detect_runtime_state(page);
        log("DEBUG", "pelayanan_runtime_state", {
            try_index: index + 1,
            is_login: state.is_login ? "yes" : "no",
            is_profile: state.is_profile ? "yes" : "no",
            is_external_form: state.is_external_form ? "yes" : "no",
            is_pelayanan: state.is_pelayanan ? "yes" : "no",
            has_search_input: state.has_search_input ? "yes" : "no",
            has_pelayanan_table: state.has_pelayanan_table ? "yes" : "no",
            has_blocking_modal: state.has_blocking_modal ? "yes" : "no",
            has_session_error_modal: state.has_session_error_modal ? "yes" : "no"
        });

        if (state.has_session_error_modal || state.is_login)
        {
            await recover_session_if_needed(page, config, timeout_ms);
            await safe_goto(page, pelayanan_url, timeout_ms);
            await safe_wait(page, 1100);
            continue;
        }

        if (state.is_external_form)
        {
            const returned = await return_from_external_form_if_needed(page, timeout_ms);
            if (!returned)
                await safe_goto(page, pelayanan_url, timeout_ms);
            await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => { });
            await safe_wait(page, 900);
            continue;
        }

        if (state.is_profile)
        {
            await ensure_profile_saved(page, timeout_ms);
            await safe_goto(page, pelayanan_url, timeout_ms);
            await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => { });
            await safe_wait(page, 900);
            continue;
        }

        if (!state.is_pelayanan)
        {
            await safe_goto(page, pelayanan_url, timeout_ms);
            await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => { });
            await safe_wait(page, 900);
            continue;
        }

        const is_detail_pemeriksaan_page = /\/detail-pemeriksaan/i.test(String(state.url || ""));
        if (is_detail_pemeriksaan_page)
        {
            await safe_goto(page, pelayanan_url, timeout_ms);
            await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => { });
            await safe_wait(page, 900);
            continue;
        }

        if (state.has_search_input || state.has_pelayanan_table)
            return;

        await safe_wait(page, 650);
    }

    throw new Error("Gagal masuk ke halaman pelayanan dalam kondisi siap pakai");
}

async function validate_pelayanan_prerequisite_for_search(page, config, timeout_ms)
{
    const max_attempt = 4;

    for (let index = 0; index < max_attempt; index += 1)
    {
        ensure_worker_can_continue();
        const pre_modal = await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => ({ closed_any: false, needs_relogin: false }));
        if (pre_modal.needs_relogin)
            await recover_session_if_needed(page, config, timeout_ms).catch(() => { });
        await recover_session_if_needed(page, config, timeout_ms);
        await ensure_pelayanan_page(page, config, timeout_ms);
        const post_modal = await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => ({ closed_any: false, needs_relogin: false }));
        if (post_modal.needs_relogin)
            await recover_session_if_needed(page, config, timeout_ms).catch(() => { });
        await submit_same_location_for_pelayanan(page, timeout_ms);
        const final_modal = await clear_blocking_modal_for_pelayanan(page, timeout_ms).catch(() => ({ closed_any: false, needs_relogin: false }));
        if (final_modal.needs_relogin)
            await recover_session_if_needed(page, config, timeout_ms).catch(() => { });

        const state = await detect_runtime_state(page);
        const ready_for_search =
            state.is_pelayanan &&
            !state.is_login &&
            !state.is_profile &&
            !state.is_external_form &&
            !state.has_blocking_modal &&
            state.has_search_input;

        if (ready_for_search)
            return true;

        await safe_wait(page, 650);
    }

    throw new Error("Validasi pra-search gagal: login/session, halaman pelayanan, atau lokasi pelayanan belum siap");
}

async function process_one_pelayanan_job(page, config, timeout_ms, job_data)
{
    const started_at = Date.now();
    const mandiri_flow_options = resolve_mandiri_flow_options(config);
    try
    {
        ensure_worker_can_continue();
        await recover_session_if_needed(page, config, timeout_ms);

        const nik_value = String(job_data?.nik || "").trim();
        const payload_pair = resolve_pemeriksaan_payload_pair(job_data || {});
        const pemeriksaan_mandiri_payload = payload_pair?.pemeriksaan_mandiri_payload || {};
        const pemeriksaan_nakes_payload = payload_pair?.pemeriksaan_nakes_payload || {};
        ensure_worker_can_continue();
        await validate_pelayanan_prerequisite_for_search(page, config, timeout_ms);
        const resolved_status = await resolve_status_after_search(page, timeout_ms, nik_value || "");
        const used_nik = resolved_status.used_nik;
        const status = resolved_status.status;

        if (status.found === false)
        {
            const reason = String(status.reason || "peserta tidak ditemukan");
            return {
                ok: false,
                duration_ms: Date.now() - started_at,
                reg: { code: "NOT_IN_LIST", text: reason },
                attendance: { code: "SKIP", text: "SKIP" },
                error_msg: reason
            };
        }

        if (status.should_skip)
        {
            const status_line = [
                "action=skip",
                `status_tab=${status.status_tab || "-"}`,
                `pemeriksaan_mandiri=${status.pemeriksaan_mandiri || "-"}`,
                `pelayanan=${status.pelayanan || "-"}`
            ].join(", ");
            log("INFO", `pelayanan_status, ${status_line}`);
            return {
                ok: true,
                duration_ms: Date.now() - started_at,
                reg: { code: "SUDAH_MENERIMA_LAYANAN", text: "Sudah menerima layanan" },
                attendance: { code: "DILAYANI", text: "DILAYANI" }
            };
        }

        const pemeriksaan_mandiri_status = normalize_status_text(status.pemeriksaan_mandiri);
        const pelayanan_status = normalize_status_text(status.pelayanan);
        const should_start_examination =
            pemeriksaan_mandiri_status === "belum lengkap" &&
            pelayanan_status === "belum pemeriksaan";

        const status_line = [
            "action=process",
            `status_tab=${status.status_tab || "-"}`,
            `pemeriksaan_mandiri=${status.pemeriksaan_mandiri || "-"}`,
            `pelayanan=${status.pelayanan || "-"}`,
            `start_examination=${should_start_examination ? "yes" : "no"}`
        ].join(", ");
        log("INFO", `pelayanan_status, ${status_line}`);
        await click_mulai_for_visible_row(page, timeout_ms);
        log("INFO", "pelayanan_mulai_clicked", { nik: status.nik, nama: status.name });
        ensure_worker_can_continue();
        const flow_result = await run_self_examination_flow(page, timeout_ms, {
            should_start_examination,
            pemeriksaan_mandiri_payload,
            pemeriksaan_nakes_payload,
            auto_submit_form: mandiri_flow_options.auto_submit_form,
            recheck_completed_form: mandiri_flow_options.recheck_completed_form,
            refill_answered: mandiri_flow_options.refill_answered,
            only_index: mandiri_flow_options.only_index,
            detailed_question_audit: mandiri_flow_options.detailed_question_audit,
            patient_data: {
                nik: String(status.nik || nik_value || "").trim(),
                nama: String(status.name || job_data?.nama || "").trim()
            }
        });
        ensure_worker_can_continue();

        const form_summary = flow_result?.pemeriksaan_mandiri_form_list || {};
        const form_results = Array.isArray(form_summary?.results) ? form_summary.results : [];
        const nakes_form_summary = flow_result?.pelayanan_nakes_form_list || {};
        const nakes_form_results = Array.isArray(nakes_form_summary?.results) ? nakes_form_summary.results : [];
        const expected_mandiri_questions = Number(pemeriksaan_mandiri_payload?.total_pertanyaan || 0);
        const expected_nakes_questions = Number(pemeriksaan_nakes_payload?.total_pertanyaan || 0);
        const total_target_form = Number(form_summary?.total_layanan || 0);
        const total_form_ready = Number(form_summary?.total_form_siaga || 0);
        const submit_done_total = Number(flow_result?.submit_done_total || 0);
        const total_target_form_nakes = Number(nakes_form_summary?.total_layanan || 0);
        const total_form_ready_nakes = Number(nakes_form_summary?.total_form_siaga || 0);
        const submit_done_total_nakes = nakes_form_results.reduce((sum, item) =>
        {
            return sum + (String(item?.submit_done || "").toLowerCase() === "yes" ? 1 : 0);
        }, 0);
        const total_belum_terjawab_wajib = form_results.reduce((sum, item) =>
        {
            const remaining = Number(item?.question_data?.total_belum_terjawab || 0);
            return sum + (Number.isFinite(remaining) ? remaining : 0);
        }, 0);
        const total_belum_terjawab_wajib_nakes = nakes_form_results.reduce((sum, item) =>
        {
            const remaining = Number(item?.question_data?.total_belum_terjawab || 0);
            return sum + (Number.isFinite(remaining) ? remaining : 0);
        }, 0);
        const mandiri_answer_quality = summarize_answer_quality(form_results);
        const nakes_answer_quality = summarize_answer_quality(nakes_form_results);
        const send_report_clicked = flow_result?.send_report_clicked === true;
        const finish_service_clicked = flow_result?.finish_service_clicked === true;
        const final_action_completed = send_report_clicked && finish_service_clicked;
        const mandiri_answer_quality_issue =
            mandiri_answer_quality.total_mismatch > 0 ||
            mandiri_answer_quality.total_unmapped > 0 ||
            mandiri_answer_quality.total_unmatched_form > 0;
        const nakes_answer_quality_issue =
            nakes_answer_quality.total_mismatch > 0 ||
            nakes_answer_quality.total_unmapped > 0 ||
            nakes_answer_quality.total_unmatched_form > 0;
        const answer_quality_issue = mandiri_answer_quality_issue || nakes_answer_quality_issue;

        const mandiri_completed = expected_mandiri_questions <= 0
            ? true
            : total_target_form > 0 &&
            total_form_ready > 0 &&
            submit_done_total >= total_form_ready &&
            total_belum_terjawab_wajib === 0;
        const nakes_completed = expected_nakes_questions <= 0
            ? true
            : total_target_form_nakes > 0 &&
            total_form_ready_nakes > 0 &&
            submit_done_total_nakes >= total_form_ready_nakes &&
            total_belum_terjawab_wajib_nakes === 0;

        const is_completed = mandiri_completed && nakes_completed && final_action_completed;

        if (answer_quality_issue)
        {
            log("WARN", "pelayanan_answer_quality_warning", {
                nik: status.nik,
                mandiri_total_checked: mandiri_answer_quality.total_checked,
                mandiri_total_mismatch: mandiri_answer_quality.total_mismatch,
                mandiri_total_unmapped: mandiri_answer_quality.total_unmapped,
                mandiri_total_unmatched_form: mandiri_answer_quality.total_unmatched_form,
                nakes_total_checked: nakes_answer_quality.total_checked,
                nakes_total_mismatch: nakes_answer_quality.total_mismatch,
                nakes_total_unmapped: nakes_answer_quality.total_unmapped,
                nakes_total_unmatched_form: nakes_answer_quality.total_unmatched_form
            });
        }

        if (!is_completed) {
            const error_message = "Pelayanan belum selesai penuh (mandiri/nakes belum sesuai server atau Kirim Rapor dan Selesaikan Layanan belum berhasil)";
            log("WARN", "pelayanan_submit_not_detected", {
                nik: status.nik,
                expected_mandiri_questions,
                total_target_form,
                total_form_ready,
                submit_done_total,
                total_belum_terjawab_wajib,
                mandiri_total_checked: mandiri_answer_quality.total_checked,
                mandiri_total_mismatch: mandiri_answer_quality.total_mismatch,
                mandiri_total_unmapped: mandiri_answer_quality.total_unmapped,
                mandiri_total_unmatched_form: mandiri_answer_quality.total_unmatched_form,
                expected_nakes_questions,
                total_target_form_nakes,
                total_form_ready_nakes,
                submit_done_total_nakes,
                total_belum_terjawab_wajib_nakes,
                nakes_total_checked: nakes_answer_quality.total_checked,
                nakes_total_mismatch: nakes_answer_quality.total_mismatch,
                nakes_total_unmapped: nakes_answer_quality.total_unmapped,
                nakes_total_unmatched_form: nakes_answer_quality.total_unmatched_form,
                send_report_clicked: send_report_clicked ? "yes" : "no",
                finish_service_clicked: finish_service_clicked ? "yes" : "no"
            });
            return {
                ok: false,
                duration_ms: Date.now() - started_at,
                reg: { code: "PELAYANAN_BELUM_SELESAI", text: error_message },
                attendance: { code: "SKIP", text: "SKIP" },
                error_msg: error_message
            };
        }

        return {
            ok: true,
            duration_ms: Date.now() - started_at,
            reg: { code: "DILAYANI", text: "Pelayanan selesai diproses" },
            attendance: { code: "DILAYANI", text: "DILAYANI" }
        };
    } catch (error)
    {
        const error_message = String(error?.message || error || "Proses pelayanan gagal").trim();
        const direct_error_code = String(error?.code || "").trim().toUpperCase();
        const error_code = direct_error_code === "STOPPED_BY_SERVER"
            ? "STOPPED_BY_SERVER"
            : (direct_error_code !== "" ? direct_error_code : resolve_error_code(error_message));
        return {
            ok: false,
            duration_ms: Date.now() - started_at,
            reg: { code: error_code, text: error_message },
            attendance: { code: "SKIP", text: "SKIP" },
            error_msg: error_message
        };
    }
}

async function run_api_pelayanan_jobs(page, config, timeout_ms)
{
    const jobs = await api_fetch_batch(config, 50);
    log("INFO", "pelayanan_job_source_api", { total: jobs.length });
    let total_duration_ms = 0;
    let processed_count = 0;
    for (let index = 0; index < jobs.length; index += 1)
    {
        if (!worker_state.should_run)
            break;

        if (!(await is_page_alive(page)))
        {
            log("ERROR", "pelayanan_page_dead", { index: index + 1 });
            break;
        }

        const job = jobs[index] || {};
        const data = job.data || {};
        const nik = String(data.nik || "").trim();
        const nama = String(data.nama || data.name || "").trim();
        const payload_pair = resolve_pemeriksaan_payload_pair(data || {});
        const mandiri_payload = payload_pair?.pemeriksaan_mandiri_payload || {};
        const nakes_payload = payload_pair?.pemeriksaan_nakes_payload || {};

        if (mandiri_payload && typeof mandiri_payload === "object")
        {
            log("INFO", "pemeriksaan_mandiri_bank_received", {
                nik,
                nama,
                batch_key: mandiri_payload.batch_key || "",
                package_key: mandiri_payload.package_key || "",
                total_jenis_pemeriksaan: Number(mandiri_payload.total_jenis_pemeriksaan || 0),
                total_pertanyaan: Number(mandiri_payload.total_pertanyaan || 0)
            });
        }
        if (nakes_payload && typeof nakes_payload === "object")
        {
            log("INFO", "pemeriksaan_nakes_bank_received", {
                nik,
                nama,
                batch_key: nakes_payload.batch_key || "",
                package_key: nakes_payload.package_key || "",
                total_jenis_pemeriksaan: Number(nakes_payload.total_jenis_pemeriksaan || 0),
                total_pertanyaan: Number(nakes_payload.total_pertanyaan || 0)
            });
        }

        log("INFO", "pelayanan_job_start", {
            index: index + 1,
            total: jobs.length,
            job_id: job.job_id,
            nik,
            nama
        });

        const out = await process_one_pelayanan_job(page, config, timeout_ms, data);
        const status_text = derive_status_text(out);
        const duration_ms = Math.max(0, Number(out?.duration_ms || 0));
        total_duration_ms += duration_ms;
        processed_count += 1;
        const avg_duration_ms = processed_count > 0
            ? Math.round(total_duration_ms / processed_count)
            : 0;
        const remaining_count = Math.max(0, jobs.length - (index + 1));
        const estimate_remaining_s = Math.max(0, Math.round((avg_duration_ms * remaining_count) / 1000));
        const estimate_total_s = Math.max(0, Math.round((avg_duration_ms * jobs.length) / 1000));
        const elapsed_total_s = Math.max(0, Math.round(total_duration_ms / 1000));
        const report = () => api_report_result(config, job, out, status_text);

        await report().catch(async (error) =>
        {
            log("WARN", "pelayanan_report_retry", {
                job_id: job.job_id,
                error: String(error?.message || error || "")
            });
            await safe_wait(page, 2000);
            await report().catch((error_retry) =>
            {
                log("ERROR", "pelayanan_report_failed", {
                    job_id: job.job_id,
                    error: String(error_retry?.message || error_retry || "")
                });
            });
        });

        log("INFO", "pelayanan_job_done", {
            index: index + 1,
            total: jobs.length,
            job_id: job.job_id,
            nik,
            nama,
            success: out.ok ? "yes" : "no",
            status_text,
            duration_ms,
            avg_duration_ms,
            estimate_remaining_s,
            estimate_total_s,
            elapsed_total_s
        });
    }
}

export async function handle_pelayanan(page, config)
{
    log("INFO", "pelayanan_umum_handler_start");
    const timeout_ms = resolve_timeout_ms(config);
    const mandiri_flow_options = resolve_mandiri_flow_options(config);
    const use_api = process.env.WORKER_MODE === "1" && Boolean(
        (config?.api?.base_url || process.env.API_BASE_URL) &&
        (config?.api?.license_key || process.env.LICENSE_KEY)
    );

    await page.waitForLoadState("domcontentloaded", { timeout: timeout_ms }).catch(() => { });
    await safe_wait(page, 1500);

    await recover_session_if_needed(page, config, timeout_ms);

    await ensure_profile_saved(page, timeout_ms);
    await ensure_pelayanan_page(page, config, timeout_ms);

    if (use_api)
    {
        await run_api_pelayanan_jobs(page, config, timeout_ms);
    } else
    {
        const local_payload = resolve_local_pemeriksaan_payload(config);
        const local_pemeriksaan_mandiri_payload = local_payload?.pemeriksaan_mandiri_payload || {};
        const local_pemeriksaan_nakes_payload = local_payload?.pemeriksaan_nakes_payload || {};
        await validate_pelayanan_prerequisite_for_search(page, config, timeout_ms);
        const resolved_status = await resolve_status_after_search(page, timeout_ms, config?.meta?.search_nik || "");
        const used_nik = resolved_status.used_nik;
        const status = resolved_status.status;

        if (status.should_skip)
        {
            const status_line = [
                "action=skip",
                `status_tab=${status.status_tab || "-"}`,
                `pemeriksaan_mandiri=${status.pemeriksaan_mandiri || "-"}`,
                `pelayanan=${status.pelayanan || "-"}`
            ].join(", ");
            log("INFO", `pelayanan_status, ${status_line}`);
        } else
        {
            const pemeriksaan_mandiri_status = normalize_status_text(status.pemeriksaan_mandiri);
            const pelayanan_status = normalize_status_text(status.pelayanan);
            const should_start_examination =
                pemeriksaan_mandiri_status === "belum lengkap" &&
                pelayanan_status === "belum pemeriksaan";

            const status_line = [
                "action=process",
                `status_tab=${status.status_tab || "-"}`,
                `pemeriksaan_mandiri=${status.pemeriksaan_mandiri || "-"}`,
                `pelayanan=${status.pelayanan || "-"}`,
                `start_examination=${should_start_examination ? "yes" : "no"}`
            ].join(", ");
            log("INFO", `pelayanan_status, ${status_line}`);
            await click_mulai_for_visible_row(page, timeout_ms);
            log("INFO", "pelayanan_mulai_clicked", { nik: status.nik, nama: status.name });
            await run_self_examination_flow(page, timeout_ms, {
                should_start_examination,
                pemeriksaan_mandiri_payload: local_pemeriksaan_mandiri_payload,
                pemeriksaan_nakes_payload: local_pemeriksaan_nakes_payload,
                auto_submit_form: mandiri_flow_options.auto_submit_form,
                recheck_completed_form: mandiri_flow_options.recheck_completed_form,
                refill_answered: mandiri_flow_options.refill_answered,
                only_index: mandiri_flow_options.only_index,
                detailed_question_audit: mandiri_flow_options.detailed_question_audit,
                patient_data: {
                    nik: String(status.nik || used_nik || "").trim(),
                    nama: String(status.name || "").trim()
                }
            });
        }
    }

    log("DEBUG", "halaman_pelayanan_umum_ready", { url: page.url() });
    log("INFO", "browser_siap_digunakan_untuk_pelayanan_umum");
}

