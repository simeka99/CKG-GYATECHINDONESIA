import fetch from "node-fetch";
import fs from "fs";
import path from "path";
import { log, get_device_id, get_device_token, read_json, get_worker_log_line_for_web } from "../core/helpers.js";

function get_base(config)
{
    const base = config?.api?.base_url || process.env.API_BASE_URL || "https://rmik.gyatechindonesia.com/api";
    return base.replace(/\/+$/, "");
}

function get_key(config)
{
    const key = config?.api?.license_key || process.env.LICENSE_KEY;
    if (!key) throw new Error("License key belum di-set (config atau env)");
    return key;
}

function get_token(config)
{
    const token = get_device_token() || config?.api?.device_token || "";
    if (!token) throw new Error("Device token belum di-set. Login lisensi ulang diperlukan.");
    return token;
}

function is_missing_token_error(error)
{
    const text = String(error?.message || error || "").toLowerCase()
    return text.includes("device token belum di-set")
}

const stop_codes = new Set(['LICENSE_REVOKED', 'NO_KEY', 'SUBSCRIPTION_EXPIRED', 'SUBSCRIPTION_NOT_SET', 'QUOTA_EMPTY'])
const token_recover_codes = new Set(['DEVICE_TOKEN_REQUIRED', 'DEVICE_TOKEN_INVALID'])
const heartbeat_timeout_ms = 5000
const batch_timeout_ms = 30000
const result_timeout_ms = 30000

function read_first_text_value(source, keys = [])
{
    for (const key of keys)
    {
        const value = source?.[key]
        const text = String(value ?? '').trim()
        if (text !== '')
            return text
    }
    return ''
}

function normalize_job_payload(job)
{
    const source_job = job && typeof job === 'object' ? job : {}
    const source_data = source_job?.data && typeof source_job.data === 'object' ? source_job.data : {}
    const merged_source = { ...source_job, ...source_data }

    const nama = read_first_text_value(merged_source, [
        'nama', 'name', 'full_name', 'nama_lengkap', 'patient_name', 'pasien_nama', 'nama_pasien'
    ])
    const nik = read_first_text_value(merged_source, [
        'nik', 'no_ktp', 'no_nik', 'nomor_nik', 'patient_nik', 'pasien_nik'
    ])
    const jenis_kelamin = read_first_text_value(merged_source, [
        'jenis_kelamin', 'jk', 'gender', 'sex'
    ])
    const tanggal_lahir = read_first_text_value(merged_source, [
        'tanggal_lahir', 'tgl_lahir', 'birth_date', 'dob'
    ])

    return {
        ...source_job,
        data: {
            ...source_data,
            nama,
            nik,
            jenis_kelamin,
            tanggal_lahir
        }
    }
}

async function fetch_with_timeout(url, options = {}, timeout_ms = 10000)
{
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), Math.max(500, Number(timeout_ms) || 10000))
    try
    {
        return await fetch(url, { ...options, signal: controller.signal })
    }
    finally
    {
        clearTimeout(timer)
    }
}

async function parse_json_safe(res)
{
    const text = await res.text().catch(() => "");
    try
    {
        return JSON.parse(text);
    }
    catch
    {
        throw new Error(`HTTP ${res.status}: response bukan JSON - ${text.slice(0, 120)}`);
    }
}

function write_token_to_storage(next_token)
{
    const token = String(next_token || "").trim()
    if (!token) return

    const storage_dir = String(process.env.STORAGE_DIR || "").trim()
    if (!storage_dir) return

    const license_path = path.join(storage_dir, "license.json")
    const license_data = read_json(license_path)
    if (!license_data || typeof license_data !== "object") return

    license_data.device_token = token
    fs.writeFileSync(license_path, JSON.stringify(license_data, null, 2) + "\n", "utf-8")
}

async function refresh_device_token(config)
{
    log("WARN", "device_token_refresh_start")
    const base = get_base(config);
    const key = get_key(config);
    const device_id = get_device_id()

    const response = await fetch_with_timeout(`${base}/license/login.php`, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-License-Key": key,
            "X-Device-Id": device_id,
        },
        body: new URLSearchParams({ device_id }),
    }, heartbeat_timeout_ms);

    const data = await parse_json_safe(response).catch(() => ({}));
    if (!response.ok || !data?.ok)
        throw new Error(data?.error || `refresh token HTTP ${response.status}`);

    const payload = data?.data && typeof data.data === "object" ? data.data : data;
    const token = String(payload?.device_token || "").trim();
    if (!token) throw new Error("Device token kosong setelah refresh");

    process.env.DEVICE_TOKEN = token
    if (config?.api && typeof config.api === "object")
        config.api.device_token = token
    write_token_to_storage(token)
    log("INFO", "device_token_refresh_success")
    return token
}

export async function api_heartbeat(config, allow_retry = true)
{
    const base = get_base(config);
    const key = get_key(config);
    let token = "";
    try
    {
        token = get_token(config);
    }
    catch (error)
    {
        if (allow_retry && is_missing_token_error(error))
        {
            await refresh_device_token(config)
            return await api_heartbeat(config, false)
        }
        throw error
    }

    const worker_log_line = String(get_worker_log_line_for_web() || "").slice(0, 1200);
    let res;
    try
    {
        res = await fetch_with_timeout(`${base}/heartbeat.php`, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "X-License-Key": key,
                "X-Device-Id": get_device_id(),
                "X-Device-Token": token,
            },
            body: new URLSearchParams({
                worker_log_line,
            }),
        }, heartbeat_timeout_ms);
    }
    catch (e)
    {
        const is_timeout = e?.name === 'AbortError'
        const err = new Error(is_timeout ? "API Heartbeat Timeout" : ("API Heartbeat Network Error: " + e.message));
        err.is_fatal = false;
        err.code = is_timeout ? "NETWORK_TIMEOUT" : "NETWORK_ERROR";
        throw err;
    }

    if (!res.ok)
    {
        const body = await parse_json_safe(res).catch(() => ({}));
        const code = String(body?.code || `HTTP_${res.status}`).trim().toUpperCase();

        if (allow_retry && token_recover_codes.has(code))
        {
            await refresh_device_token(config)
            return await api_heartbeat(config, false)
        }

        const err = new Error(body?.error || `heartbeat HTTP ${res.status}`);
        err.code = code;
        err.is_fatal = stop_codes.has(code) || res.status === 401;
        throw err;
    }

    return parse_json_safe(res);
}

export async function api_fetch_batch(config, limit = 1000, allow_retry = true)
{
    const base = get_base(config);
    const key = get_key(config);
    let token = "";
    try
    {
        token = get_token(config);
    }
    catch (error)
    {
        if (allow_retry && is_missing_token_error(error))
        {
            await refresh_device_token(config)
            return await api_fetch_batch(config, limit, false)
        }
        throw error
    }

    const res = await fetch_with_timeout(`${base}/job/batch.php`, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-License-Key": key,
            "X-Device-Id": get_device_id(),
            "X-Device-Token": token,
        },
        body: new URLSearchParams({ limit: String(limit) }),
    }, batch_timeout_ms);

    const json = await parse_json_safe(res).catch(() => ({}));
    if (!res.ok || !json?.ok)
    {
        const code = String(json?.code || `HTTP_${res.status}`).trim().toUpperCase();
        if (allow_retry && token_recover_codes.has(code))
        {
            await refresh_device_token(config)
            return await api_fetch_batch(config, limit, false)
        }
        throw new Error("API batch error: " + (json?.error || `HTTP ${res.status}`));
    }

    const jobs = Array.isArray(json.jobs) ? json.jobs : []
    return jobs.map((job) => normalize_job_payload(job))
}

export async function api_report_result(config, job, out, status_text, allow_retry = true)
{
    const base = get_base(config);
    const key = get_key(config);
    let token = "";
    try
    {
        token = get_token(config);
    }
    catch (error)
    {
        if (allow_retry && is_missing_token_error(error))
        {
            await refresh_device_token(config)
            return await api_report_result(config, job, out, status_text, false)
        }
        throw error
    }
    const truly_success = !!out.ok;
    const reg_code = out.reg?.code || "";
    const error_msg = out.error_msg || out.reg?.text || "";
    const patient_data = job?.data && typeof job.data === "object" ? job.data : {};
    const nama = String(patient_data.nama || "").trim();
    const nik = String(patient_data.nik || "").trim();
    const jenis_kelamin = String(patient_data.jenis_kelamin || "").trim();
    const tanggal_lahir = String(patient_data.tanggal_lahir || "").trim();
    const umur_operasional = String(
        patient_data.umur_operasional || patient_data.umur || patient_data.usia || ""
    ).trim();

    const body = new URLSearchParams({
        job_id: job.job_id,
        status: truly_success ? "success" : "failed",
        reg_code,
        error_msg,
        duration_ms: out.duration_ms ?? 0,
        result_data: JSON.stringify({
            job_id: job.job_id,
            patient_id: job.patient_id,
            task_type: job.task_type,
            status_reg: reg_code,
            status_absen: out.attendance?.code || "",
            status_text,
            nama,
            nik,
            jenis_kelamin,
            tanggal_lahir,
            umur_operasional,
            duration_ms: out.duration_ms,
        }),
    });

    log("INFO", "[API RESULT]", {
        url: `${base}/job/result.php`,
        job_id: job.job_id,
        status: truly_success ? "success" : "failed",
        reg_code,
    });

    let res;
    try
    {
        res = await fetch_with_timeout(`${base}/job/result.php`, {
            method: "POST",
            headers: {
                "X-License-Key": key,
                "X-Device-Id": get_device_id(),
                "X-Device-Token": token,
                "Content-Type": "application/x-www-form-urlencoded",
            },
            body: body.toString(),
        }, result_timeout_ms);
    }
    catch (e)
    {
        log("ERROR", "report_network_error", { error: e.message });
        throw e;
    }

    const text = await res.text().catch(() => "");
    log("INFO", "[API RESULT RESP]", { status: res.status, body: text });

    let parsed = null
    try
    {
        parsed = JSON.parse(text)
    }
    catch
    {
        parsed = { ok: false, error: "invalid json response" }
    }

    const code = String(parsed?.code || `HTTP_${res.status}`).trim().toUpperCase()
    if (allow_retry && token_recover_codes.has(code))
    {
        await refresh_device_token(config)
        return await api_report_result(config, job, out, status_text, false)
    }

    return parsed;
}
