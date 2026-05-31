import path from 'path'
import { fileURLToPath } from 'url'
import { spawnSync } from 'child_process'
import fs from 'fs'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const app_root = path.resolve(__dirname, '..')
const project_root = path.resolve(app_root, '..')
const update_channel_map_path = path.join(project_root, 'config', 'update_channels.json')

function normalize_channel(value)
{
    const raw = String(value || '').trim().toLowerCase()
    const clean = raw.replace(/[^a-z0-9._-]/g, '-').replace(/-+/g, '-').replace(/^[-._]+|[-._]+$/g, '')
    return clean
}

function normalize_license_key(value)
{
    return String(value || '').trim().toUpperCase()
}

function is_license_key(value)
{
    return /^GYA-[A-Z0-9]{8}-[A-Z0-9]{8}$/.test(normalize_license_key(value))
}

function parse_args(argv)
{
    const raw_args = (argv || []).map((x) => String(x || '').trim()).filter(Boolean)
    const positional = []
    let channel = ''

    for (let i = 0; i < raw_args.length; i++)
    {
        const arg = raw_args[i]
        if (arg === '--channel' || arg === '-c')
        {
            channel = raw_args[i + 1] || ''
            i += 1
            continue
        }

        if (arg.startsWith('--channel='))
        {
            channel = arg.slice('--channel='.length)
            continue
        }

        positional.push(arg)
    }

    const tokens = positional.flatMap((entry) =>
        entry
            .split(/[,;]+/)
            .map((x) => String(x || '').trim())
            .filter(Boolean)
    )

    const licenses = []
    for (const token of tokens)
    {
        const normalized = normalize_license_key(token)
        if (is_license_key(normalized))
            licenses.push(normalized)
    }

    return {
        channel: normalize_channel(channel),
        licenses: [...new Set(licenses)],
    }
}

function run_or_fail(command, args, env = {})
{
    const result = spawnSync(command, args, {
        cwd: app_root,
        env: { ...process.env, ...env },
        stdio: 'inherit',
        shell: true,
    })

    if (result.status !== 0)
        process.exit(result.status || 1)
}

function run_command(command, args, env = {})
{
    const result = spawnSync(command, args, {
        cwd: app_root,
        env: { ...process.env, ...env },
        stdio: 'inherit',
        shell: true,
    })
    if (typeof result.status === 'number')
        return result.status
    return 1
}

function read_package_json()
{
    const package_json_path = path.join(app_root, 'package.json')
    return JSON.parse(fs.readFileSync(package_json_path, 'utf-8'))
}

function restore_package_version(version)
{
    const pkg = read_package_json()
    pkg.version = String(version || '').trim()
    const package_json_path = path.join(app_root, 'package.json')
    fs.writeFileSync(package_json_path, JSON.stringify(pkg, null, 4) + '\n', 'utf-8')
}

function resolve_build_script_name()
{
    try
    {
        const package_json_path = path.join(app_root, 'package.json')
        const pkg = JSON.parse(fs.readFileSync(package_json_path, 'utf-8'))
        const scripts = pkg?.scripts || {}
        if (typeof scripts['build:lite'] === 'string' && scripts['build:lite'].trim())
            return 'build:lite'
    }
    catch
    {
        // fallback ke build
    }
    return 'build'
}

function load_channel_map()
{
    try
    {
        if (!fs.existsSync(update_channel_map_path))
            return { default: 'public', licenses: {}, prefixes: {} }
        return JSON.parse(fs.readFileSync(update_channel_map_path, 'utf-8'))
    }
    catch
    {
        return { default: 'public', licenses: {}, prefixes: {} }
    }
}

function save_channel_map(payload)
{
    const dir = path.dirname(update_channel_map_path)
    if (!fs.existsSync(dir))
        fs.mkdirSync(dir, { recursive: true })
    fs.writeFileSync(update_channel_map_path, JSON.stringify(payload, null, 2), 'utf-8')
}

const parsed = parse_args(process.argv.slice(2))
const env_channel = normalize_channel(process.env.UPDATES_CHANNEL || '')
const map = load_channel_map()
if (!map || typeof map !== 'object')
    throw new Error('Gagal membaca update_channels.json')

if (!map.licenses || typeof map.licenses !== 'object')
    map.licenses = {}
if (!map.prefixes || typeof map.prefixes !== 'object')
    map.prefixes = {}
if (!map.default)
    map.default = 'public'

const first_license = parsed.licenses[0] || ''
const existing_channel = first_license ? normalize_channel(map.licenses?.[first_license] || '') : ''
const inferred_channel = first_license ? normalize_channel(`lic-${first_license.toLowerCase()}`) : ''
const channel = parsed.channel || env_channel || existing_channel || inferred_channel

if (!channel)
{
    console.error('Gunakan: npm run deploy:license -- <LICENSE_KEY...>')
    console.error('Contoh 1 lisensi: npm run deploy:license -- GYA-7FE01192-C0E37041')
    console.error('Contoh multi lisensi: npm run deploy:license -- GYA-7FE01192-C0E37041 GYA-0D40C1A1-D47837C3')
    console.error('Contoh custom channel: npm run deploy:license -- --channel klinik-a GYA-7FE01192-C0E37041')
    process.exit(1)
}

console.log(`deploy channel: ${channel}`)

if (parsed.licenses.length > 0)
{
    for (const key of parsed.licenses)
    {
        map.licenses[key] = channel
        console.log(`mapped license: ${key} -> ${channel}`)
    }
    save_channel_map(map)
}
else
{
    console.log('warning: tidak ada license key valid yang dimap (deploy channel-only)')
}

const build_script = resolve_build_script_name()
const original_version = String(read_package_json()?.version || '').trim()
let bumped = false

try
{
    const bump_status = run_command('npm', ['run', 'bump:version'])
    if (bump_status !== 0)
        process.exit(bump_status)
    bumped = true

    const build_status = run_command('npm', ['run', build_script])
    if (build_status !== 0)
        throw new Error(`build gagal (exit ${build_status})`)

    const publish_status = run_command('npm', ['run', 'publish', '--', channel], {
        UPDATES_CHANNEL: channel,
        UPDATES_MOVE_ARTIFACTS: '1',
        UPDATES_CLEAN_DIST: '1',
    })
    if (publish_status !== 0)
        throw new Error(`publish gagal (exit ${publish_status})`)

    const latest_version = String(read_package_json()?.version || '').trim()
    console.log(`deploy license sukses. versi rilis: ${latest_version}`)
    process.exit(0)
}
catch (error)
{
    if (bumped)
    {
        try
        {
            restore_package_version(original_version)
            console.log(`versi dikembalikan: ${original_version}`)
        }
        catch (restore_error)
        {
            console.error('gagal mengembalikan versi package.json:', restore_error?.message || restore_error)
        }
    }

    console.error(error?.message || 'deploy license gagal')
    process.exit(1)
}
