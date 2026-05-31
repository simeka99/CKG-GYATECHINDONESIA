import fs from 'fs'
import path from 'path'
import { spawnSync } from 'child_process'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const root_dir = path.resolve(__dirname, '..')
const package_json_path = path.join(root_dir, 'package.json')

function read_package_json()
{
    return JSON.parse(fs.readFileSync(package_json_path, 'utf-8'))
}

function write_package_json(data)
{
    fs.writeFileSync(package_json_path, JSON.stringify(data, null, 4) + '\n', 'utf-8')
}

function set_package_version(version)
{
    const pkg = read_package_json()
    pkg.version = String(version || '').trim()
    write_package_json(pkg)
}

function run_command(command, args = [], extra_env = {})
{
    const result = spawnSync(command, args, {
        cwd: root_dir,
        stdio: 'inherit',
        shell: true,
        env: {
            ...process.env,
            ...extra_env,
        }
    })

    if (typeof result.status === 'number')
        return result.status
    return 1
}

function resolve_build_script()
{
    const mode = String(process.argv[2] || '').trim().toLowerCase()
    return mode === 'bundled' ? 'build:bundled' : 'build'
}

function main()
{
    const original_pkg = read_package_json()
    const original_version = String(original_pkg?.version || '').trim()
    const build_script = resolve_build_script()
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

        const publish_status = run_command('npm', ['run', 'publish'], {
            UPDATES_MOVE_ARTIFACTS: '1',
            UPDATES_CLEAN_DIST: '1',
        })
        if (publish_status !== 0)
            throw new Error(`publish gagal (exit ${publish_status})`)

        const latest_version = String(read_package_json()?.version || '').trim()
        console.log(`deploy sukses. versi rilis: ${latest_version}`)
        process.exit(0)
    }
    catch (error)
    {
        if (bumped)
        {
            try
            {
                set_package_version(original_version)
                console.log(`versi dikembalikan: ${original_version}`)
            }
            catch (restore_error)
            {
                console.error('gagal mengembalikan versi package.json:', restore_error?.message || restore_error)
            }
        }

        const message = error?.message || 'deploy gagal'
        console.error(message)
        process.exit(1)
    }
}

main()
