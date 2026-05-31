import { app } from 'electron'
import path from 'path'
import fs from 'fs'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

export const is_packaged = app.isPackaged
const app_data_dir = app.getPath('appData')
const dev_user_data_dir = path.join(app_data_dir, 'RMIK-DEV')

if (!is_packaged)
    app.setPath('userData', dev_user_data_dir)

if (is_packaged)
{
    const bundled_browsers_dir = path.join(process.resourcesPath, 'pw-browsers')
    if (fs.existsSync(bundled_browsers_dir))
        process.env.PLAYWRIGHT_BROWSERS_PATH = bundled_browsers_dir
}

export const user_data_dir = is_packaged
    ? app.getPath('userData')
    : dev_user_data_dir

export const storage_dir = is_packaged
    ? path.join(app.getPath('userData'), 'storage')
    : path.join(dev_user_data_dir, 'storage')

export const license_file = path.join(storage_dir, 'license.json')
export const config_file = is_packaged
    ? path.join(app.getPath('userData'), 'config.json')
    : path.join(dev_user_data_dir, 'config.json')

function resolve_packaged_src_dir()
{
    const candidate_dirs = [
        path.join(process.resourcesPath, 'app.asar.unpacked', 'src'),
        path.join(app.getAppPath(), 'src'),
        path.join(process.resourcesPath, 'src'),
    ]
    const found_dir = candidate_dirs.find(dir_path => fs.existsSync(dir_path))
    return found_dir || candidate_dirs[0]
}

export const src_dir = is_packaged
    ? resolve_packaged_src_dir()
    : path.join(path.resolve(__dirname, '..', '..'), 'src')

