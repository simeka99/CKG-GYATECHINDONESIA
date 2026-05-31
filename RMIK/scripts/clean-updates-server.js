import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import { Client } from 'basic-ftp'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const app_root = path.resolve(__dirname, '..')
const sftp_json_path = path.resolve(app_root, '..', '.vscode', 'sftp.json')

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

function should_delete_remote(name)
{
    const value = String(name || '').trim()
    if (!value) return false
    return /\.exe(\.blockmap)?$/i.test(value) || /^(latest\.yml|versions\.json)$/i.test(value)
}

function resolve_upload_config()
{
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
        const remote_dir = env_cfg.remote_dir_name.startsWith('/')
            ? env_cfg.remote_dir_name
            : `${env_cfg.base_remote_path.replace(/\/+$/, '')}/${env_cfg.remote_dir_name}`

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
    const remote_dir = remote_dir_name.startsWith('/')
        ? remote_dir_name
        : `${base_remote_path.replace(/\/+$/, '')}/${remote_dir_name}`

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

async function main()
{
    const cfg = resolve_upload_config()
    if (!cfg.enabled)
    {
        console.error(`clean-server gagal: ${cfg.reason}`)
        process.exit(1)
    }

    if (!cfg.host || !cfg.user || !cfg.password || !cfg.remote_dir)
    {
        console.error('clean-server gagal: konfigurasi FTP belum lengkap')
        process.exit(1)
    }

    const client = new Client(60000)
    client.ftp.verbose = false

    try
    {
        console.log(`clean-server source: ${cfg.source}`)
        console.log(`clean-server target: ${cfg.host}:${cfg.port}${cfg.remote_dir}`)

        await client.access({
            host: cfg.host,
            port: cfg.port,
            user: cfg.user,
            password: cfg.password,
            secure: cfg.secure
        })

        await client.ensureDir(cfg.remote_dir.replace(/\\/g, '/'))
        const list = await client.list()
        const to_delete = list
            .map((entry) => entry.name)
            .filter(should_delete_remote)

        if (!to_delete.length)
        {
            console.log('clean-server: tidak ada artifact update yang perlu dihapus.')
            return
        }

        for (const name of to_delete)
        {
            await client.remove(name).catch(() => { })
            console.log(`deleted: ${name}`)
        }

        const remain = await client.list()
        console.log(`clean-server selesai. total dihapus: ${to_delete.length}`)
        console.log('sisa file remote:')
        remain.forEach((x) => console.log(` - ${x.name}`))
    }
    finally
    {
        client.close()
    }
}

main().catch((err) =>
{
    console.error('clean-server gagal:', err?.message || err)
    process.exit(1)
})
