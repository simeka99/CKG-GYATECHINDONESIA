import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import { Client } from 'basic-ftp'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const app_root = path.resolve(__dirname, '..')
const dist_dir = path.join(app_root, 'dist')
const updates_root_dir = path.resolve(app_root, '..', 'updates')
const latest_yml = path.join(dist_dir, 'latest.yml')
const sftp_json_path = path.resolve(app_root, '..', '.vscode', 'sftp.json')
const package_json_path = path.join(app_root, 'package.json')
const updates_channel = resolve_updates_channel()
const updates_dir = updates_channel === 'public'
    ? updates_root_dir
    : path.join(updates_root_dir, 'channels', updates_channel)

function ensure_dir(dir_path)
{
    if (!fs.existsSync(dir_path))
        fs.mkdirSync(dir_path, { recursive: true })
}

function env_bool(name, fallback = false)
{
    const raw = process.env[name]
    if (raw == null || raw === '') return fallback
    return ['1', 'true', 'yes', 'on'].includes(String(raw).trim().toLowerCase())
}

function parse_json_file(file_path)
{
    try
    {
        if (!fs.existsSync(file_path)) return null
        return JSON.parse(fs.readFileSync(file_path, 'utf-8'))
    }
    catch
    {
        return null
    }
}

function clean_name(value)
{
    return String(value || '').trim().replace(/^['"]|['"]$/g, '')
}

function parse_keep_count()
{
    const raw = Number(process.env.UPDATES_KEEP_VERSIONS || 3)
    if (!Number.isFinite(raw)) return 3
    return Math.max(1, Math.floor(raw))
}

function normalize_channel(value)
{
    const raw = String(value || '').trim().toLowerCase()
    const clean = raw.replace(/[^a-z0-9._-]/g, '-').replace(/-+/g, '-').replace(/^[-._]+|[-._]+$/g, '')
    return clean || 'public'
}

function resolve_updates_channel()
{
    const env_channel = process.env.UPDATES_CHANNEL
    if (env_channel && String(env_channel).trim())
        return normalize_channel(env_channel)

    const arg = process.argv.slice(2).find((x) => !String(x || '').startsWith('--'))
    if (arg && String(arg).trim())
        return normalize_channel(arg)

    return 'public'
}

function extract_release_version(file_name)
{
    const m = String(file_name || '').match(/^.+\sSetup\s([0-9]+(?:\.[0-9]+)*)\.exe(?:\.blockmap)?$/i)
    return m ? m[1] : null
}

function to_iso(datetime)
{
    try
    {
        return new Date(datetime).toISOString()
    }
    catch
    {
        return new Date().toISOString()
    }
}

function resolve_public_updates_url()
{
    const env_url = String(process.env.UPDATES_PUBLIC_URL || '').trim()
    if (env_url)
    {
        const base = env_url.replace(/\/+$/, '/')
        return updates_channel === 'public' ? base : `${base}channels/${updates_channel}/`
    }

    const pkg = parse_json_file(package_json_path) || {}
    const publish_url = pkg?.build?.publish?.[0]?.url
    if (publish_url && typeof publish_url === 'string')
    {
        const base = publish_url.replace(/\/+$/, '/')
        return updates_channel === 'public' ? base : `${base}channels/${updates_channel}/`
    }

    const fallback = 'https://rmik.gyatechindonesia.com/updates/'
    return updates_channel === 'public' ? fallback : `${fallback}channels/${updates_channel}/`
}

function compare_versions_desc(a, b)
{
    const pa = String(a).split('.').map((x) => Number(x) || 0)
    const pb = String(b).split('.').map((x) => Number(x) || 0)
    const len = Math.max(pa.length, pb.length)
    for (let i = 0; i < len; i++)
    {
        const va = pa[i] ?? 0
        const vb = pb[i] ?? 0
        if (va !== vb) return vb - va
    }
    return 0
}

function group_release_files(file_names)
{
    const groups = new Map()
    for (const name of file_names)
    {
        const version = extract_release_version(name)
        if (!version) continue
        const key = version
        if (!groups.has(key))
            groups.set(key, [])
        groups.get(key).push(name)
    }
    return groups
}

function parse_artifacts_from_latest(content)
{
    const files = new Set()
    const lines = String(content || '').split(/\r?\n/)

    for (const raw of lines)
    {
        const line = raw.trim()
        if (line.startsWith('path:'))
            files.add(clean_name(line.slice('path:'.length)))
        else if (line.startsWith('- url:'))
            files.add(clean_name(line.slice('- url:'.length)))
        else if (line.startsWith('url:'))
            files.add(clean_name(line.slice('url:'.length)))
    }

    return [...files].filter(Boolean)
}

function transfer_file(src, dest)
{
    const move_artifacts = env_bool('UPDATES_MOVE_ARTIFACTS', false)

    if (fs.existsSync(dest))
        fs.unlinkSync(dest)

    if (move_artifacts)
    {
        try
        {
            fs.renameSync(src, dest)
        }
        catch
        {
            fs.copyFileSync(src, dest)
            fs.unlinkSync(src)
        }
    }
    else
    {
        fs.copyFileSync(src, dest)
    }

    const size_mb = (fs.statSync(dest).size / (1024 * 1024)).toFixed(2)
    console.log(`${move_artifacts ? 'moved' : 'copied'}: ${path.basename(dest)} (${size_mb} MB)`)
}

function build_remote_updates_dir(base_remote_dir)
{
    const normalized = String(base_remote_dir || '').replace(/\/+$/, '')
    if (updates_channel === 'public') return normalized
    return `${normalized}/channels/${updates_channel}`
}

function clean_dist_output()
{
    if (!env_bool('UPDATES_CLEAN_DIST', false)) return
    if (!fs.existsSync(dist_dir)) return

    fs.rmSync(dist_dir, { recursive: true, force: true })
    console.log(`cleaned: ${dist_dir}`)
}

function resolve_upload_config()
{
    if (env_bool('UPDATES_UPLOAD', true) === false)
        return { enabled: false, reason: 'UPDATES_UPLOAD=0' }

    const env_cfg = {
        host: process.env.UPDATES_FTP_HOST || '',
        port: Number(process.env.UPDATES_FTP_PORT || 21),
        user: process.env.UPDATES_FTP_USER || '',
        password: process.env.UPDATES_FTP_PASSWORD || '',
        secure: env_bool('UPDATES_FTP_SECURE', false),
        base_remote_path: (process.env.UPDATES_FTP_BASE_PATH || '').trim(),
        remote_dir_name: (process.env.UPDATES_REMOTE_DIR || 'updates').trim() || 'updates'
    }

    if (env_cfg.host && env_cfg.user && env_cfg.password && env_cfg.base_remote_path)
    {
        const remote_dir_base = env_cfg.remote_dir_name.startsWith('/')
            ? env_cfg.remote_dir_name
            : `${env_cfg.base_remote_path.replace(/\/+$/, '')}/${env_cfg.remote_dir_name}`
        const remote_dir = build_remote_updates_dir(remote_dir_base)

        return {
            enabled: true,
            source: 'env',
            host: env_cfg.host,
            port: env_cfg.port,
            user: env_cfg.user,
            password: env_cfg.password,
            secure: env_cfg.secure,
            remote_dir
        }
    }

    const sftp_cfg = parse_json_file(sftp_json_path)
    if (!sftp_cfg) return { enabled: false, reason: 'config FTP tidak ditemukan' }

    const base_remote_path = String(sftp_cfg.remotePath || '').trim()
    const remote_dir_name = (process.env.UPDATES_REMOTE_DIR || 'updates').trim() || 'updates'
    const remote_dir_base = remote_dir_name.startsWith('/')
        ? remote_dir_name
        : `${base_remote_path.replace(/\/+$/, '')}/${remote_dir_name}`
    const remote_dir = build_remote_updates_dir(remote_dir_base)

    return {
        enabled: true,
        source: '.vscode/sftp.json',
        host: String(sftp_cfg.host || ''),
        port: Number(sftp_cfg.port || 21),
        user: String(sftp_cfg.username || ''),
        password: String(sftp_cfg.password || ''),
        secure: String(sftp_cfg.protocol || '').toLowerCase() === 'ftps',
        remote_dir
    }
}

function format_size_mb(bytes)
{
    const n = Number(bytes || 0)
    const mb = n / (1024 * 1024)
    return `${mb.toFixed(2)} MB`
}

function format_percent(done_bytes, total_bytes)
{
    const done = Math.max(0, Number(done_bytes || 0))
    const total = Math.max(1, Number(total_bytes || 1))
    const pct = Math.min(100, Math.max(0, (done / total) * 100))
    return `${pct.toFixed(1)}%`
}

async function upload_files(copied_files)
{
    const cfg = resolve_upload_config()
    if (!cfg.enabled)
    {
        console.log(`skip upload: ${cfg.reason}`)
        return
    }

    if (!cfg.host || !cfg.user || !cfg.password || !cfg.remote_dir)
    {
        console.log('skip upload: konfigurasi FTP belum lengkap')
        return
    }

    const client = new Client(60000)
    client.ftp.verbose = false
    const progress_state = {
        active_remote_name: '',
        active_total_bytes: 0,
        last_logged_at: 0,
        last_logged_bytes: -1,
    }
    const keep_versions = parse_keep_count()
    const copied_version_names = new Set(
        copied_files
            .map(extract_release_version)
            .filter(Boolean)
    )

    try
    {
        console.log('')
        console.log(`upload source: ${cfg.source}`)
        console.log(`upload target: ${cfg.host}:${cfg.port}${cfg.remote_dir}`)

        await client.access({
            host: cfg.host,
            port: cfg.port,
            user: cfg.user,
            password: cfg.password,
            secure: cfg.secure
        })

        await client.ensureDir(cfg.remote_dir.replace(/\\/g, '/'))
        client.trackProgress((info) =>
        {
            if (!info || info.type !== 'upload')
                return
            if (!progress_state.active_remote_name)
                return

            const info_name = path.posix.basename(String(info.name || ''))
            if (info_name && info_name !== progress_state.active_remote_name)
                return

            const total_bytes = Math.max(1, Number(progress_state.active_total_bytes || 1))
            const done_bytes = Math.min(total_bytes, Math.max(0, Number(info.bytes || 0)))
            const now = Date.now()
            const should_throttle = (now - progress_state.last_logged_at) < 700
            const is_complete = done_bytes >= total_bytes

            if (should_throttle && !is_complete)
                return
            if (done_bytes === progress_state.last_logged_bytes && !is_complete)
                return

            progress_state.last_logged_at = now
            progress_state.last_logged_bytes = done_bytes

            console.log(
                `[upload] ${progress_state.active_remote_name} ` +
                `${format_percent(done_bytes, total_bytes)} ` +
                `(${format_size_mb(done_bytes)} / ${format_size_mb(total_bytes)})`
            )
        })

        for (const file_name of copied_files)
        {
            const local_path = path.join(updates_dir, file_name)
            if (!fs.existsSync(local_path))
            {
                console.warn(`skip upload (not found): ${file_name}`)
                continue
            }

            const remote_name = path.posix.basename(file_name)
            const file_size_bytes = Number(fs.statSync(local_path).size || 0)
            progress_state.active_remote_name = remote_name
            progress_state.active_total_bytes = Math.max(1, file_size_bytes)
            progress_state.last_logged_at = 0
            progress_state.last_logged_bytes = -1

            console.log(`[upload] start: ${remote_name} (${format_size_mb(file_size_bytes)})`)
            await client.uploadFrom(local_path, remote_name)
            console.log(`[upload] done: ${remote_name}`)
        }
        client.trackProgress()

        console.log('Upload ke server selesai.')

        const list = await client.list()
        const remote_names = list
            .filter((entry) => entry?.type !== 2)
            .map((entry) => entry.name)
            .filter((name) => extract_release_version(name))

        const groups = group_release_files(remote_names)
        const versions_sorted = [...groups.keys()].sort(compare_versions_desc)
        const keep_set = new Set(versions_sorted.slice(0, keep_versions))

        for (const v of copied_version_names) keep_set.add(v)

        const removed = []
        for (const [version, names] of groups.entries())
        {
            if (keep_set.has(version)) continue
            for (const name of names)
            {
                await client.remove(name).catch(() => { })
                removed.push(name)
            }
        }

        if (removed.length)
        {
            console.log(`Remote clean: ${removed.length} file lama dihapus (keep ${keep_versions} versi).`)
        }
    }
    finally
    {
        client.close()
    }
}

function clean_local_old_releases(copied_files)
{
    const keep_versions = parse_keep_count()
    const all_names = fs.existsSync(updates_dir)
        ? fs.readdirSync(updates_dir, { withFileTypes: true })
            .filter((x) => x.isFile())
            .map((x) => x.name)
            .filter((name) => extract_release_version(name))
        : []

    const groups = group_release_files(all_names)
    const versions_sorted = [...groups.keys()].sort(compare_versions_desc)
    const keep_set = new Set(versions_sorted.slice(0, keep_versions))

    for (const v of copied_files.map(extract_release_version).filter(Boolean))
        keep_set.add(v)

    const removed = []
    for (const [version, names] of groups.entries())
    {
        if (keep_set.has(version)) continue
        for (const name of names)
        {
            const target = path.join(updates_dir, name)
            try
            {
                if (fs.existsSync(target))
                {
                    fs.unlinkSync(target)
                    removed.push(name)
                }
            } catch { }
        }
    }

    if (removed.length)
    {
        console.log(`Local clean: ${removed.length} file lama dihapus (keep ${keep_versions} versi).`)
    }
}

function write_versions_manifest()
{
    const public_base = resolve_public_updates_url()
    const now_iso = to_iso(Date.now())

    const all = fs.readdirSync(updates_dir, { withFileTypes: true })
        .filter((x) => x.isFile())
        .map((x) => x.name)

    const exes_raw = all
        .filter((name) => name.toLowerCase().endsWith('.exe'))
        .map((name) =>
        {
            const version = extract_release_version(name)
            if (!version) return null
            const blockmap = `${name}.blockmap`
            const has_blockmap = all.includes(blockmap)
            const full_path = path.join(updates_dir, name)
            const stat = fs.existsSync(full_path) ? fs.statSync(full_path) : null

            return {
                version,
                file: name,
                blockmap: has_blockmap ? blockmap : '',
                size_bytes: Number(stat?.size || 0),
                updated_at: to_iso(stat?.mtime || now_iso),
                updated_at_epoch: Number(stat?.mtimeMs || 0),
                url: public_base + encodeURIComponent(name).replace(/%2F/g, '/'),
            }
        })
        .filter(Boolean)

    const exes_by_version = new Map()
    for (const item of exes_raw)
    {
        const prev = exes_by_version.get(item.version)
        if (!prev || item.updated_at_epoch > prev.updated_at_epoch)
            exes_by_version.set(item.version, item)
    }

    const exes = [...exes_by_version.values()]
        .map(({ updated_at_epoch, ...row }) => row)
        .sort((a, b) => compare_versions_desc(a.version, b.version))

    const latest = exes[0] || null
    const payload = {
        ok: true,
        channel: updates_channel,
        generated_at: now_iso,
        latest_version: latest?.version || '',
        versions: exes
    }

    const target = path.join(updates_dir, 'versions.json')
    fs.writeFileSync(target, JSON.stringify(payload, null, 2), 'utf-8')
    console.log(`generated: versions.json (${exes.length} versi)`)
    return 'versions.json'
}

async function main()
{
    if (!fs.existsSync(latest_yml))
    {
        console.error(`latest.yml tidak ditemukan di: ${latest_yml}`)
        process.exit(1)
    }

    ensure_dir(updates_dir)
    console.log(`channel: ${updates_channel}`)

    const latest_content = fs.readFileSync(latest_yml, 'utf-8')
    const artifacts = parse_artifacts_from_latest(latest_content)
    const copied = []

    transfer_file(latest_yml, path.join(updates_dir, 'latest.yml'))
    copied.push('latest.yml')

    for (const name of artifacts)
    {
        const src = path.join(dist_dir, name)
        const dest = path.join(updates_dir, name)
        if (!fs.existsSync(src))
        {
            console.warn(`skip (not found): ${name}`)
            continue
        }

        transfer_file(src, dest)
        copied.push(name)

        const maybe_blockmap = `${name}.blockmap`
        const src_blockmap = path.join(dist_dir, maybe_blockmap)
        if (fs.existsSync(src_blockmap))
        {
            transfer_file(src_blockmap, path.join(updates_dir, maybe_blockmap))
            copied.push(maybe_blockmap)
        }
    }

    console.log('')
    console.log(`Publish updates selesai. ${copied.length} file disalin ke:`)
    console.log(updates_dir)

    clean_local_old_releases(copied)
    const manifest_name = write_versions_manifest()
    copied.push(manifest_name)
    await upload_files(copied)
    clean_dist_output()
}

main().catch((err) =>
{
    console.error('Publish updates gagal:', err?.message || err)
    process.exit(1)
})
