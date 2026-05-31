import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const package_json_path = path.join(__dirname, '..', 'package.json')

function parse_version(input)
{
    const raw = String(input || '').trim()
    const m = raw.match(/^(\d+)\.(\d+)\.(\d+)$/)
    if (!m) return null
    return {
        major: Number(m[1]),
        minor: Number(m[2]),
        patch: Number(m[3]),
    }
}

function build_version(v)
{
    return `${v.major}.${v.minor}.${v.patch}`
}

function next_version(current, level = 'patch')
{
    const parsed = parse_version(current)
    if (!parsed)
        throw new Error(`Versi package.json tidak valid: "${current}". Gunakan format x.y.z`)

    if (level === 'major')
        return build_version({ major: parsed.major + 1, minor: 0, patch: 0 })

    if (level === 'minor')
        return build_version({ major: parsed.major, minor: parsed.minor + 1, patch: 0 })

    return build_version({ major: parsed.major, minor: parsed.minor, patch: parsed.patch + 1 })
}

function resolve_bump_level()
{
    const arg = String(process.argv[2] || '').trim().toLowerCase()
    const env = String(process.env.BUMP_LEVEL || '').trim().toLowerCase()
    const level = arg || env || 'patch'
    return ['major', 'minor', 'patch'].includes(level) ? level : 'patch'
}

function main()
{
    if (String(process.env.SKIP_VERSION_BUMP || '').trim() === '1')
    {
        console.log('skip bump: SKIP_VERSION_BUMP=1')
        return
    }

    const raw = fs.readFileSync(package_json_path, 'utf-8')
    const pkg = JSON.parse(raw)
    const old_version = String(pkg?.version || '').trim()
    const level = resolve_bump_level()
    const new_version = next_version(old_version, level)

    pkg.version = new_version
    fs.writeFileSync(package_json_path, JSON.stringify(pkg, null, 4) + '\n', 'utf-8')

    console.log(`version bumped (${level}): ${old_version} -> ${new_version}`)
}

main()
