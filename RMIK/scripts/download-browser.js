import { execSync } from 'child_process'
import path from 'path'
import fs from 'fs'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root_dir = path.resolve(__dirname, '..')
const pw_browsers_dir = path.join(root_dir, 'pw-browsers')

console.log('[build-prep] Cek folder pw-browsers di:', pw_browsers_dir)

const use_bundled_browser = !['0', 'false', 'no', 'off'].includes(String(process.env.BUNDLE_PLAYWRIGHT_BROWSER || '1').trim().toLowerCase())

if (!fs.existsSync(pw_browsers_dir))
    fs.mkdirSync(pw_browsers_dir, { recursive: true })

if (!use_bundled_browser)
{
    fs.rmSync(pw_browsers_dir, { recursive: true, force: true })
    fs.mkdirSync(pw_browsers_dir, { recursive: true })
    console.log('[build-prep] Bundled browser dimatikan. Folder pw-browsers dikosongkan untuk build ringan.')
    process.exit(0)
}

const chromium_exists = fs.existsSync(pw_browsers_dir) &&
    fs.readdirSync(pw_browsers_dir).some(d => d.startsWith('chromium'))

if (chromium_exists)
{
    console.log('[build-prep] Chromium sudah ada di pw-browsers, skip download.')
    process.exit(0)
}

console.log('[build-prep] Download Playwright Chromium ke pw-browsers...')

try
{
    execSync('npx playwright install chromium', {
        env: {
            ...process.env,
            PLAYWRIGHT_BROWSERS_PATH: pw_browsers_dir,
        },
        stdio: 'inherit',
        cwd: root_dir,
    })
    console.log('[build-prep] Chromium berhasil didownload ke pw-browsers.')
} catch (e)
{
    console.error('[build-prep] GAGAL download Chromium:', e.message)
    process.exit(1)
}
