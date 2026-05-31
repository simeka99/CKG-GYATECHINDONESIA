import { spawnSync } from 'child_process'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const root_dir = path.resolve(__dirname, '..')
const root_parent_dir = path.dirname(root_dir)
const build_workspace_dir = path.join(root_parent_dir, '.build_workspace_rmik')
const build_project_dir = path.join(build_workspace_dir, 'project')
const build_dist_dir = path.join(build_project_dir, 'dist')

function run_command(command, args = [], extra_env = {}, cwd = root_dir)
{
    const result = spawnSync(command, args, {
        cwd,
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

function ensure_clean_dir(dir_path)
{
    fs.rmSync(dir_path, { recursive: true, force: true })
    fs.mkdirSync(dir_path, { recursive: true })
}

function copy_project_to_workspace()
{
    ensure_clean_dir(build_project_dir)

    const blocked_roots = [
        'node_modules',
        'dist',
        '.build_workspace',
        '.build_workspace_rmik',
    ]

    fs.cpSync(root_dir, build_project_dir, {
        recursive: true,
        filter: (source_path) =>
        {
            const relative_path = path.relative(root_dir, source_path).replace(/\\/g, '/')
            if (!relative_path)
                return true
            for (const blocked_root of blocked_roots)
                if (relative_path === blocked_root || relative_path.startsWith(`${blocked_root}/`))
                    return false
            return true
        },
    })

    const source_node_modules_path = path.join(root_dir, 'node_modules')
    if (!fs.existsSync(source_node_modules_path))
        throw new Error('node_modules tidak ditemukan, jalankan npm install dulu')

    const workspace_node_modules_path = path.join(build_project_dir, 'node_modules')
    if (fs.existsSync(workspace_node_modules_path))
        fs.rmSync(workspace_node_modules_path, { recursive: true, force: true })

    fs.symlinkSync(source_node_modules_path, workspace_node_modules_path, 'junction')
}

function copy_build_result()
{
    const target_dist_dir = path.join(root_dir, 'dist')
    if (!fs.existsSync(build_dist_dir))
        throw new Error('hasil build dist tidak ditemukan di workspace')
    fs.rmSync(target_dist_dir, { recursive: true, force: true })
    fs.cpSync(build_dist_dir, target_dist_dir, { recursive: true })
}

function cleanup_workspace()
{
    fs.rmSync(build_workspace_dir, { recursive: true, force: true })
}

function main()
{
    console.log('build mode: plain source')
    let build_status = 1

    try
    {
        copy_project_to_workspace()
        build_status = run_command('electron-builder', ['--win', '--x64'], {
            CSC_IDENTITY_AUTO_DISCOVERY: 'false'
        }, build_project_dir)
        if (build_status === 0)
            copy_build_result()
    }
    finally
    {
        cleanup_workspace()
    }

    if (build_status !== 0)
        process.exit(build_status)

    process.exit(0)
}

main()
