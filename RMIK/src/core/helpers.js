
import fs from "fs";
import path from "path";
import os from "os";
import crypto from "crypto";

let worker_last_log_line = "";
let worker_last_action_line = "";
let worker_last_action_at_ms = 0;

export function read_json(file_path)
{
    try
    {
        const raw = fs.readFileSync(file_path, "utf-8");
        if (!raw.trim()) return {};
        return JSON.parse(raw);
    }
    catch (e)
    {
        log("ERROR", "read_json_failed", { file: file_path, error: e.message });
        return {};
    }
}


export function ensure_dir(dir_path)
{
    fs.mkdirSync(dir_path, { recursive: true });
}


export function file_exists(file_path)
{
    try
    {
        fs.accessSync(file_path, fs.constants.F_OK);
        return true;
    }
    catch
    {
        return false;
    }
}


export function safe_stringify(value)
{
    try
    {
        return JSON.stringify(value);
    }
    catch
    {
        return String(value);
    }
}


export function stamp()
{
    const d = new Date();
    const p = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
}


function format_meta_for_console(meta)
{
    if (!meta || typeof meta !== "object") return "";

    const parts = [];
    for (const [key, val] of Object.entries(meta))
    {
        if (val === undefined) continue;
        if (val === null)
            parts.push(`${key}=-`);
        else if (typeof val === "object")
        {
            try { parts.push(`${key}=${JSON.stringify(val)}`); }
            catch { parts.push(`${key}=${String(val)}`); }
        }
        else
            parts.push(`${key}=${String(val)}`);
    }

    return parts.length ? " " + parts.join(" ") : "";
}


export function log(level, message, meta)
{
    const d = new Date()
    const p = (n) => String(n).padStart(2, "0")
    const ts = `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`

    const nik = meta?.nik
    const nama = meta?.nama
    const tag = nik || nama ? ` [${nik || "-"} - ${nama || "-"}]` : ""
    const meta_str = format_meta_for_console(meta)
    const line = `[${ts}] -${tag} ${message}${meta_str}`
    console.log(line)

    worker_last_log_line = line
    const clean_message = String(message || "").trim().toLowerCase()
    if (clean_message !== "heartbeat")
    {
        worker_last_action_line = line
        worker_last_action_at_ms = Date.now()
    }
}

export function get_worker_log_line_for_web()
{
    if (worker_last_action_line)
        return worker_last_action_line
    const line = String(worker_last_log_line || "")
    if (line.toLowerCase().includes("heartbeat"))
        return ""
    return line
}


export function is_dev_mode()
{
    const script = String(process.env.npm_lifecycle_event || "");
    return script === "dev" || script === "agent";
}


export async function save_debug_artifacts(page, debug_dir, label)
{
    ensure_dir(debug_dir);
    const s = stamp();
    const png_path = path.join(debug_dir, `${s}_${label}.png`);
    const html_path = path.join(debug_dir, `${s}_${label}.html`);

    await page.screenshot({ path: png_path, fullPage: true }).catch((e) =>
    {
        log("WARN", "screenshot_failed", { error: e.message });
    });

    const html = await page.content().catch(() => "");
    if (html) fs.writeFileSync(html_path, html, "utf-8");

    log("INFO", "artifacts_saved", { png_path, html_path });
}


export async function goto_url(page, url, timeout_ms)
{
    await page.goto(url, { waitUntil: "domcontentloaded", timeout: timeout_ms });
    await page.waitForLoadState("load", { timeout: Math.min(timeout_ms, 15000) }).catch(() => { });
}

export function get_device_id()
{
    const env_device_id = String(process.env.DEVICE_ID || "").trim()
    if (env_device_id) return env_device_id

    const storage_dir = String(process.env.STORAGE_DIR || "").trim()
    if (storage_dir)
    {
        const license_file = path.join(storage_dir, "license.json")
        const license_data = read_json(license_file)
        const stored_device_id = String(license_data?.device_id || "").trim()
        if (stored_device_id) return stored_device_id
    }

    const parts = [
        os.hostname(),
        os.cpus()[0]?.model || "unknown",
        os.platform(),
        os.arch(),
    ]
    return crypto.createHash("sha256").update(parts.join("||")).digest("hex")
}

export function get_device_token()
{
    const env_token = String(process.env.DEVICE_TOKEN || "").trim()
    if (env_token) return env_token

    const storage_dir = String(process.env.STORAGE_DIR || "").trim()
    if (!storage_dir) return ""

    const license_file = path.join(storage_dir, "license.json")
    const license_data = read_json(license_file)
    return String(license_data?.device_token || "").trim()
}
