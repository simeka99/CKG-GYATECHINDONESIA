import path from "path";
import fs from "fs";
import { fileURLToPath } from "url";
import { log, read_json, ensure_dir } from "../../core/helpers.js";
import { run_one_user } from "./flow.js";
import { derive_status_text } from "./modal.js";
import { api_fetch_batch, api_report_result } from "../../api/client.js";
import { worker_state } from "../../api/state.js";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root_dir = path.resolve(__dirname, "../../..");

const RESULTS_PATH = path.resolve(root_dir, "./storage/results.json");
const JOB_QUEUE_PATH = path.resolve(root_dir, "./storage/job_queue.json");
const JOB_SUCCESS_PATH = path.resolve(root_dir, "./storage/job_success.json");
const JOB_FAILED_PATH = path.resolve(root_dir, "./storage/job_failed.json");

const NO_RETRY_CODES = new Set([
    "SISTEM_MENOLAK", "DUKCAPIL_UPDATE", "DUKCAPIL",
    "DATA_TIDAK_DITEMUKAN", "SUDAH_TERDAFTAR", "SUDAH_MENERIMA_LAYANAN",
    "VALIDASI_TIDAK_VALID", "VALIDASI_PESERTA_WALI_TIDAK_VALID",
    "NOT_IN_LIST"
]);

const SUCCESS_CODES = new Set(["TERDAFTAR_BARU", "TERDAFTAR"]);
const MIN_USER_TIMEOUT_MS = 3000;
const MAX_USER_TIMEOUT_MS = 120000;
const MIN_WORKER_USER_TIMEOUT_MS = 10000;
const DEFAULT_WORKER_USER_TIMEOUT_MS = 45000;
const MAX_WORKER_USER_TIMEOUT_MS = 120000;

const ERROR_DESCRIPTIONS = {
    SISTEM_MENOLAK: "Sistem menolak - tidak memenuhi syarat",
    DUKCAPIL_UPDATE: "Data Dukcapil perlu pembaruan",
    DUKCAPIL: "Gagal verifikasi Dukcapil",
    DATA_TIDAK_DITEMUKAN: "Data peserta tidak ditemukan di sistem",
    VALIDASI_TIDAK_VALID: "Data peserta tidak valid",
    VALIDASI_PESERTA_WALI_TIDAK_VALID: "Data peserta atau wali tidak valid",
    SUDAH_TERDAFTAR: "Sudah terdaftar di Sehat Indonesiaku",
    SUDAH_MENERIMA_LAYANAN: "Sudah menerima layanan",
    NOT_IN_LIST: "Tidak ada di daftar antrian",
    UNKNOWN: "Status tidak dikenali",
    ERROR: "Error teknis saat proses",
};

// ─── HELPERS ─────────────────────────────────────────────────
function normalize_text(value)
{
    return String(value || "")
        .toLowerCase()
        .normalize("NFKD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9]+/g, " ")
        .trim();
}

function levenshtein_distance(a, b)
{
    const s = String(a || "");
    const t = String(b || "");
    const n = s.length;
    const m = t.length;
    if (n === 0) return m;
    if (m === 0) return n;

    const dp = new Array(m + 1);
    for (let j = 0; j <= m; j++) dp[j] = j;

    for (let i = 1; i <= n; i++)
    {
        let prev = dp[0];
        dp[0] = i;

        for (let j = 1; j <= m; j++)
        {
            const temp = dp[j];
            const cost = s[i - 1] === t[j - 1] ? 0 : 1;
            dp[j] = Math.min(
                dp[j] + 1,
                dp[j - 1] + 1,
                prev + cost
            );
            prev = temp;
        }
    }

    return dp[m];
}

function is_similar_text(expected_norm, actual_norm)
{
    const e = expected_norm.replace(/\s+/g, "");
    const a = actual_norm.replace(/\s+/g, "");
    if (!e || !a) return false;

    const dist = levenshtein_distance(e, a);
    const max_len = Math.max(e.length, a.length);
    const similarity = max_len ? (1 - (dist / max_len)) : 0;

    if (max_len >= 10) return similarity >= 0.72;
    if (max_len >= 7) return similarity >= 0.78;
    return similarity >= 0.84;
}

function is_puskesmas_match(expected, actual)
{
    const expected_norm = normalize_text(expected);
    const actual_norm = normalize_text(actual);
    if (!expected_norm || !actual_norm) return false;

    if (expected_norm === actual_norm) return true;
    if (expected_norm.includes(actual_norm) || actual_norm.includes(expected_norm)) return true;

    const expected_tokens = [...new Set(expected_norm.split(" ").filter(token => token.length >= 3))];
    const actual_tokens = new Set(actual_norm.split(" ").filter(token => token.length >= 3));
    if (!expected_tokens.length || !actual_tokens.size) return false;

    const same_count = expected_tokens.filter(token => actual_tokens.has(token)).length;
    const threshold = Math.max(1, Math.floor(expected_tokens.length * 0.6));
    if (same_count >= threshold) return true;

    return is_similar_text(expected_norm, actual_norm);
}

function evaluate_job_success(out, expected_puskesmas)
{
    const reg_code = out.reg?.code;
    if (SUCCESS_CODES.has(reg_code)) return { ok: !!out?.ok, reason: "" };

    if (reg_code !== "SUDAH_MENERIMA_LAYANAN")
        return { ok: false, reason: "" };

    const text_fallback = String(out.reg?.text || "").match(/puskesmas\s+([^)|\n]+)/i)?.[1] || "";
    const actual_puskesmas =
        out.reg?.detail?.puskesmas_pemeriksa ||
        out.reg?.detail?.puskesmas ||
        text_fallback;
    if (!expected_puskesmas)
    {
        return {
            ok: false,
            reason: "Puskesmas setting di EXE kosong, tidak bisa validasi peserta sudah menerima layanan"
        };
    }

    if (is_puskesmas_match(expected_puskesmas, actual_puskesmas))
        return { ok: true, reason: "" };

    return {
        ok: false,
        reason: `Puskesmas tidak cocok (setting: "${expected_puskesmas}", sistem: "${actual_puskesmas || "-"}")`
    };
}

function get_error_msg(out, success_meta = null)
{
    if (success_meta?.reason) return success_meta.reason;
    const code = out.reg?.code || "ERROR";
    const text = String(out.reg?.text || "").trim();
    if (text) return text;
    const error_msg = String(out.error_msg || "").trim();
    if (error_msg) return error_msg;
    return ERROR_DESCRIPTIONS[code] || code;
}
let local_results_cache = null;
function read_results()
{
    if (local_results_cache) return local_results_cache;
    local_results_cache = fs.existsSync(RESULTS_PATH) ? read_json(RESULTS_PATH) : { items: [] };
    if (!local_results_cache || !Array.isArray(local_results_cache.items)) local_results_cache = { items: [] };
    return local_results_cache;
}

function write_result(nik, fields)
{
    const data = read_results();
    const idx = data.items.findIndex(i => i.nik === nik);
    if (idx >= 0) Object.assign(data.items[idx], fields);
    else data.items.push({ nik, ...fields });
    ensure_dir(path.dirname(RESULTS_PATH));
    fs.writeFileSync(RESULTS_PATH, JSON.stringify(data, null, 2), "utf-8");
}

let array_cache = {};
function read_array_json_cached(file_path)
{
    if (array_cache[file_path]) return array_cache[file_path];
    if (!fs.existsSync(file_path)) { array_cache[file_path] = []; return array_cache[file_path]; }
    const data = read_json(file_path);
    array_cache[file_path] = Array.isArray(data) ? data : [];
    return array_cache[file_path];
}

function append_job(file_path, payload)
{
    const list = read_array_json_cached(file_path);
    list.push(payload);
    ensure_dir(path.dirname(file_path));
    fs.writeFileSync(file_path, JSON.stringify(list, null, 2), "utf-8");
}

function clamp_number(value, min, max)
{
    const number_value = Number(value);
    if (!Number.isFinite(number_value)) return min;
    return Math.min(max, Math.max(min, Math.floor(number_value)));
}

function resolve_user_timeout_ms(config)
{
    const is_worker_mode = process.env.WORKER_MODE === "1";
    if (is_worker_mode)
    {
        return clamp_number(
            config?.config?.user_timeout_ms ?? DEFAULT_WORKER_USER_TIMEOUT_MS,
            MIN_WORKER_USER_TIMEOUT_MS,
            MAX_WORKER_USER_TIMEOUT_MS
        );
    }

    return clamp_number(
        config?.config?.user_timeout_ms ?? 45000,
        MIN_USER_TIMEOUT_MS,
        MAX_USER_TIMEOUT_MS
    );
}

async function run_user_with_timeout(page, data, wali_data, timeout_ms, user_timeout_ms)
{
    const run_started_at = Date.now();
    let timeout_id = null;
    let timed_out = false;

    const run_promise = run_one_user(page, data, wali_data, timeout_ms);
    const timeout_promise = new Promise((resolve) =>
    {
        timeout_id = setTimeout(() =>
        {
            timed_out = true;
            resolve(null);
        }, user_timeout_ms);
    });

    const result = await Promise.race([run_promise, timeout_promise]);

    if (timeout_id) clearTimeout(timeout_id);

    if (!timed_out && result)
        return result;

    run_promise.catch(() => { });

    await Promise.race([
        (async () =>
        {
            try
            {
                const current_url = String(page.url() || "").trim();
                if (current_url.startsWith("http"))
                    await page.goto(current_url, { waitUntil: "domcontentloaded", timeout: 500 }).catch(() => { });
                await page.keyboard.press("Escape").catch(() => { });
                await page.waitForLoadState("domcontentloaded", { timeout: 500 }).catch(() => { });
            } catch { }
        })(),
        page.waitForTimeout(500)
    ]).catch(() => { });

    const still_running = await Promise.race([
        run_promise.then(() => false).catch(() => false),
        page.waitForTimeout(200).then(() => true)
    ]).catch(() => true);

    if (still_running)
    {
        log("WARN", "timeout_previous_user_still_running", {
            nik: data?.nik || "",
            timeout_ms: user_timeout_ms
        });
    }

    return {
        ok: false,
        duration_ms: Date.now() - run_started_at,
        reg: { code: "USER_TIMEOUT_SKIP", text: `TIMEOUT ${user_timeout_ms}MS` },
        attendance: { code: "SKIP", text: "SKIP" },
        error_msg: `Timeout proses peserta lebih dari ${user_timeout_ms}ms`,
    };
}

async function recover_queue_ui_state(page)
{
    const is_ready = async () =>
    {
        const has_nik = await page.locator('input#nik[name="NIK"], input[name="NIK"], input#nik')
            .first()
            .isVisible({ timeout: 300 })
            .catch(() => false);
        if (has_nik) return true;

        return await page.locator("button")
            .filter({ hasText: /daftar baru/i })
            .first()
            .isVisible({ timeout: 300 })
            .catch(() => false);
    };

    if (await is_ready())
        return true;

    await page.keyboard.press("Escape").catch(() => { });
    await page.evaluate(() =>
    {
        const visible = (el) => !!(el && el.offsetParent);
        const close_candidates = [
            ...document.querySelectorAll("button.btn-transparent.absolute.right-4.top-3"),
            ...document.querySelectorAll("button.btn-transparent")
        ].filter(visible);

        for (const button of close_candidates)
            button.click();
    }).catch(() => { });

    if (await is_ready())
        return true;

    const current_url = String(page.url() || "").trim();
    if (current_url.startsWith("http"))
        await page.goto(current_url, { waitUntil: "domcontentloaded", timeout: 2500 }).catch(() => { });

    await page.waitForLoadState("domcontentloaded", { timeout: 1200 }).catch(() => { });
    await page.keyboard.press("Escape").catch(() => { });

    return await is_ready();
}

// ─── HANDLE PENDAFTARAN ──────────────────────────────────────
export async function handle_pendaftaran(page, config)
{
    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000);
    const user_timeout_ms = resolve_user_timeout_ms(config);
    const wali_data = config?.config?.data_wali || {};
    const expected_puskesmas =
        wali_data.instansi_puskesmas ||
        config?.config?.instansi_puskesmas ||
        "";
    const pause_ms = config?.config?.pause_between_users_ms ?? 650;
    const stop_on_error = config?.config?.stop_on_error ?? false;
    const start_index = config?.config?.start_index ?? 0;
    const use_api = process.env.WORKER_MODE === "1" && Boolean(
        (config?.api?.base_url || process.env.API_BASE_URL) &&
        (config?.api?.license_key || process.env.LICENSE_KEY)
    );

    // Ambil data job dari API atau file lokal
    let data_list;
    if (use_api)
    {
        log("INFO", "job_source_api", { base_url: config.api.base_url });
        data_list = await api_fetch_batch(config, 50);
    } else
    {
        log("INFO", "job_source_local", { path: JOB_QUEUE_PATH });
        data_list = read_array_json_cached(JOB_QUEUE_PATH);
    }

    // NIK yang sudah selesai (hanya mode lokal)
    const done_niks = use_api
        ? new Set()
        : new Set(read_results().items.filter(i => i.state === "done").map(i => i.nik));

    const total = data_list.length;
    const batch_start = Date.now();
    const user_times = [], report_rows = [];

    ensure_dir(path.dirname(RESULTS_PATH));
    ensure_dir(path.dirname(JOB_SUCCESS_PATH));
    ensure_dir(path.dirname(JOB_FAILED_PATH));
    log("INFO", "batch_start", { total, start_index, source: use_api ? "api" : "local" });
    log("INFO", "user_timeout_mode", { user_timeout_ms });

    for (let i = start_index; i < total; i++)
    {
        const job = data_list[i];
        const data = use_api ? job.data : job;
        if (!data) continue;

        // Hentikan loop jika worker dihentikan (mode API)
        if (use_api && !worker_state.should_run)
        {
            log("INFO", "batch_paused_by_signal", { idx: i + 1, remaining: total - i });
            let is_waiting = true;
            while (!worker_state.should_run)
            {
                await page.waitForTimeout(2000).catch(() => { });
                if (!worker_state.should_run && is_waiting)
                {
                    is_waiting = false;
                    log("INFO", "batch_waiting_for_resume");
                }
            }
            log("INFO", "batch_resumed_by_signal");
        }

        // Skip NIK yang sudah done (mode lokal)
        if (!use_api && done_niks.has(data.nik))
        {
            log("INFO", "user_skip_done", { nik: data.nik });
            report_rows.push({ no: i + 1, nik: data.nik, nama: data.nama, status: "SKIP" });
            continue;
        }

        log("INFO", "user_start", { index: i + 1, total, nik: data.nik, nama: data.nama });
        write_result(data.nik, { state: "in_progress", nama: data.nama, started_at: new Date().toISOString() });

        // Jalankan proses 1 user
        const one_start = Date.now();
        let out;
        try
        {
            out = await run_user_with_timeout(page, data, wali_data, timeout_ms, user_timeout_ms);
        } catch (e)
        {
            out = {
                ok: false,
                duration_ms: Date.now() - one_start,
                reg: { code: "ERROR", text: e.message?.split(":")?.[0] || "FATAL" },
                attendance: { code: "SKIP", text: "SKIP" },
                error_msg: e.message,
            };
        }

        const recovered = await recover_queue_ui_state(page).catch(() => false);
        if (!recovered)
        {
            log("WARN", "queue_ui_recover_failed", {
                nik: data?.nik || "",
                reg_code: out?.reg?.code || "UNKNOWN"
            });
        }

        const status_text = derive_status_text(out);
        const success_meta = evaluate_job_success(out, expected_puskesmas);
        const truly_success = success_meta.ok;
        const error_msg = truly_success ? null : get_error_msg(out, success_meta);
        const is_no_retry = NO_RETRY_CODES.has(out.reg?.code);

        // Simpan hasil ke results.json
        write_result(data.nik, {
            state: "done", is_success: truly_success,
            status_reg: out.reg?.code, status_absen: out.attendance?.code,
            status_text, error_msg, is_no_retry,
            duration_ms: out.duration_ms,
            started_at: new Date(one_start).toISOString(),
            finished_at: new Date().toISOString(),
        });

        user_times.push(out.duration_ms);
        report_rows.push({ no: i + 1, nik: data.nik, nama: data.nama, status: status_text });

        if (use_api)
        {
            const report = () => api_report_result(config, job, { ...out, ok: truly_success }, status_text);
            await report().catch(async (err) =>
            {
                log("WARN", "report_retry", { job_id: job.job_id, error: err?.message });
                await page.waitForTimeout(1500).catch(() => { });
                await report().catch(err2 => log("ERROR", "report_failed", { job_id: job.job_id, error: err2?.message }));
            });
        } else
        {
            const payload = {
                no: i + 1, nik: data.nik, nama: data.nama,
                status_reg: out.reg?.code, status_absen: out.attendance?.code,
                status_text, error_msg, is_no_retry,
                duration_ms: out.duration_ms, finished_at: new Date().toISOString(),
            };
            if (truly_success)
            {
                append_job(JOB_SUCCESS_PATH, payload);
                log("INFO", "sukses", { nik: data.nik, reg: out.reg?.code });
            } else
            {
                append_job(JOB_FAILED_PATH, payload);
                log("WARN", "gagal", { nik: data.nik, reg: out.reg?.code, error_msg });
            }
        }

        // Hitung ETA berdasarkan rata-rata durasi
        const avg_ms = user_times.reduce((a, b) => a + b, 0) / user_times.length;
        const remaining = total - (i + 1);
        log("INFO", "user_done", {
            index: i + 1, total, nik: data.nik, nama: data.nama, success: truly_success,
            status: status_text, dur_ms: out.duration_ms,
            eta_s: Math.round((avg_ms * remaining) / 1000),
        });

        if (!truly_success && !is_no_retry && stop_on_error) break;
        if (remaining > 0 && pause_ms > 0) await page.waitForTimeout(pause_ms);
    }

    log("INFO", "batch_done", { total, elapsed_ms: Date.now() - batch_start });
    return report_rows;
}