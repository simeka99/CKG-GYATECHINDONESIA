import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const app_root = path.resolve(__dirname, '..')
const updates_dir = path.resolve(app_root, '..', 'updates')
const dist_dir = path.join(app_root, 'dist')

function should_delete_local(name)
{
    const value = String(name || '').trim()
    if (!value) return false
    return /\.exe(\.blockmap)?$/i.test(value) || /^(latest\.yml|versions\.json)$/i.test(value)
}

function main()
{
    let deleted = 0

    if (fs.existsSync(updates_dir))
    {
        const files = fs.readdirSync(updates_dir)
        for (const name of files)
        {
            if (!should_delete_local(name)) continue
            const target = path.join(updates_dir, name)
            if (!fs.existsSync(target)) continue
            fs.unlinkSync(target)
            deleted += 1
            console.log(`deleted local: ${name}`)
        }
    }

    if (fs.existsSync(dist_dir))
    {
        fs.rmSync(dist_dir, { recursive: true, force: true })
        console.log(`deleted local dir: ${dist_dir}`)
    }

    console.log(`clean-local selesai. total artifact terhapus: ${deleted}`)
}

main()
