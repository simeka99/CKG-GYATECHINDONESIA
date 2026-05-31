import fs from "fs"
import path from "path"
import { fileURLToPath } from "url"
import fetch from "node-fetch"
import dotenv from "dotenv"
import { get_device_id, get_device_token } from "../src/core/helpers.js"
import { resolve_pemeriksaan_payload_pair } from "../src/ckg-umum/pelayanan/payload_splitter.js"

const script_file = fileURLToPath(import.meta.url)
const script_dir = path.dirname(script_file)
const root_dir = path.resolve(script_dir, "..")
dotenv.config({ path: path.join(root_dir, ".env") })

function parse_args(argv)
{
    const arg_map = {}
    for (const raw_item of argv)
    {
        const item = String(raw_item || "").trim()
        if (!item.startsWith("--"))
            continue
        const equal_index = item.indexOf("=")
        if (equal_index < 0)
        {
            arg_map[item.slice(2)] = "1"
            continue
        }
        const key = item.slice(2, equal_index).trim()
        const value = item.slice(equal_index + 1).trim()
        if (key !== "")
            arg_map[key] = value
    }
    return arg_map
}

function resolve_base_url()
{
    const base_url = String(process.env.DEV_API_BASE_URL || process.env.API_BASE_URL || "").trim()
    if (base_url === "")
        throw new Error("DEV_API_BASE_URL atau API_BASE_URL belum di-set")
    return base_url.replace(/\/+$/, "")
}

function resolve_license_key()
{
    const license_key = String(process.env.DEV_LICENSE_KEY || process.env.LICENSE_KEY || "").trim()
    if (license_key === "")
        throw new Error("DEV_LICENSE_KEY atau LICENSE_KEY belum di-set")
    return license_key
}

async function refresh_device_token(base_url, license_key, device_id)
{
    const login_response = await fetch(`${base_url}/license/login.php`, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-License-Key": license_key,
            "X-Device-Id": device_id
        },
        body: new URLSearchParams({ device_id })
    })

    const login_json = await login_response.json().catch(() => ({}))
    if (!login_response.ok || !login_json?.ok)
        throw new Error(String(login_json?.error || `refresh token HTTP ${login_response.status}`))

    const payload = login_json?.data && typeof login_json.data === "object" ? login_json.data : login_json
    const token = String(payload?.device_token || "").trim()
    if (token === "")
        throw new Error("device_token kosong dari login.php")
    return token
}

function ensure_output_path(arg_map)
{
    const output_raw = String(arg_map.output || "").trim()
    if (output_raw !== "")
        return path.isAbsolute(output_raw) ? output_raw : path.resolve(root_dir, output_raw)

    const output_dir = path.resolve(root_dir, "artifacts", "debug")
    const date = new Date()
    const pad = (value) => String(value).padStart(2, "0")
    const stamp = `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}_${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}`
    return path.join(output_dir, `pemeriksaan_mandiri_preview_${stamp}.json`)
}

async function run()
{
    const arg_map = parse_args(process.argv.slice(2))
    const base_url = resolve_base_url()
    const license_key = resolve_license_key()
    const device_id = get_device_id()

    let device_token = String(process.env.DEVICE_TOKEN || process.env.DEV_DEVICE_TOKEN || get_device_token() || "").trim()
    if (device_token === "")
        device_token = await refresh_device_token(base_url, license_key, device_id)

    const request_body = new URLSearchParams({
        preview_mode: "1",
        package_key: String(arg_map.package_key || "").trim(),
        jenis_kelamin: String(arg_map.jenis_kelamin || "").trim(),
        tanggal_lahir: String(arg_map.tanggal_lahir || "").trim(),
        usia_tahun: String(arg_map.usia_tahun || "").trim()
    })

    const fetch_preview_json = async (active_device_token, allow_retry = true) =>
    {
        const response = await fetch(`${base_url}/job/batch.php`, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "X-License-Key": license_key,
                "X-Device-Id": device_id,
                "X-Device-Token": active_device_token
            },
            body: request_body
        })

        const json = await response.json().catch(() => ({}))
        if (!response.ok || !json?.ok)
        {
            const error_text = String(json?.error || `preview HTTP ${response.status}`).trim()
            const error_code = String(json?.code || "").trim().toUpperCase()
            const is_invalid_token = error_code === "DEVICE_TOKEN_INVALID" || /device token/i.test(error_text)
            if (allow_retry && is_invalid_token)
            {
                const fresh_token = await refresh_device_token(base_url, license_key, device_id)
                return fetch_preview_json(fresh_token, false)
            }
            throw new Error(error_text)
        }

        return json
    }

    const json = await fetch_preview_json(device_token, true)

    const output_path = ensure_output_path(arg_map)
    const output_dir = path.dirname(output_path)
    fs.mkdirSync(output_dir, { recursive: true })

    const is_preview_mode = json?.preview_mode === true
    const first_job = Array.isArray(json?.jobs) && json.jobs.length > 0 ? json.jobs[0] : {}
    const fallback_pair = resolve_pemeriksaan_payload_pair(first_job?.data || {})
    const fallback_pemeriksaan_mandiri = fallback_pair?.pemeriksaan_mandiri_payload || {}
    const fallback_pemeriksaan_nakes = fallback_pair?.pemeriksaan_nakes_payload || {}
    const fallback_preview = {
        job_id: first_job?.job_id || null,
        patient_id: first_job?.patient_id || null,
        nik: first_job?.data?.nik || "",
        nama: first_job?.data?.nama || "",
        package_key: fallback_pemeriksaan_mandiri?.package_key || fallback_pemeriksaan_nakes?.package_key || "",
        batch_key: fallback_pemeriksaan_mandiri?.batch_key || fallback_pemeriksaan_nakes?.batch_key || ""
    }

    const preview_pair = resolve_pemeriksaan_payload_pair(json || {})
    const preview_pemeriksaan_mandiri = preview_pair?.pemeriksaan_mandiri_payload || {}
    const preview_pemeriksaan_nakes = preview_pair?.pemeriksaan_nakes_payload || {}

    const output_payload = {
        generated_at: new Date().toISOString(),
        source_mode: is_preview_mode ? "preview_mode" : "batch_fallback",
        preview: is_preview_mode ? (json?.preview || {}) : fallback_preview,
        pemeriksaan_mandiri: is_preview_mode
            ? preview_pemeriksaan_mandiri
            : fallback_pemeriksaan_mandiri,
        pemeriksaan_nakes: is_preview_mode
            ? preview_pemeriksaan_nakes
            : fallback_pemeriksaan_nakes
    }

    fs.writeFileSync(output_path, JSON.stringify(output_payload, null, 2) + "\n", "utf-8")

    const total_jenis_pemeriksaan = Number(output_payload?.pemeriksaan_mandiri?.total_jenis_pemeriksaan || 0)
    const total_pertanyaan = Number(output_payload?.pemeriksaan_mandiri?.total_pertanyaan || 0)
    const total_jenis_pemeriksaan_nakes = Number(output_payload?.pemeriksaan_nakes?.total_jenis_pemeriksaan || 0)
    const total_pertanyaan_nakes = Number(output_payload?.pemeriksaan_nakes?.total_pertanyaan || 0)

    console.log(`output_path=${output_path}`)
    console.log(`source_mode=${output_payload.source_mode}`)
    console.log(`total_jenis_pemeriksaan=${total_jenis_pemeriksaan}`)
    console.log(`total_pertanyaan=${total_pertanyaan}`)
    console.log(`total_jenis_pemeriksaan_nakes=${total_jenis_pemeriksaan_nakes}`)
    console.log(`total_pertanyaan_nakes=${total_pertanyaan_nakes}`)
}

run().catch((error) =>
{
    const message = String(error?.message || error || "preview gagal")
    console.error(`error=${message}`)
    process.exit(1)
})
