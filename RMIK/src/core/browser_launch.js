import fs from 'fs'

function normalize_engine(value)
{
    const engine = String(value || 'system').trim().toLowerCase()
    return ['auto', 'system', 'bundled'].includes(engine) ? engine : 'system'
}

function normalize_channel(value)
{
    const channel = String(value || 'msedge').trim().toLowerCase()
    return ['msedge', 'chrome'].includes(channel) ? channel : 'msedge'
}

export function get_browser_runtime_config(config = {})
{
    return {
        engine: normalize_engine(config?.browser?.engine),
        channel: normalize_channel(config?.browser?.channel),
    }
}

function build_attempts(runtime_config)
{
    const preferred = runtime_config.channel
    const fallback = preferred === 'chrome' ? 'msedge' : 'chrome'
    const bundled_available = has_bundled_browser_available()

    if (runtime_config.engine === 'bundled')
        return [{ type: 'bundled' }]

    if (runtime_config.engine === 'system')
        return bundled_available
            ? [{ type: 'channel', channel: preferred }, { type: 'channel', channel: fallback }, { type: 'bundled' }]
            : [{ type: 'channel', channel: preferred }, { type: 'channel', channel: fallback }]

    return bundled_available
        ? [{ type: 'channel', channel: preferred }, { type: 'channel', channel: fallback }, { type: 'bundled' }]
        : [{ type: 'channel', channel: preferred }, { type: 'channel', channel: fallback }]
}

export function get_browser_attempts(runtime_config)
{
    return build_attempts(runtime_config)
}

function has_bundled_browser_available()
{
    const bundled_path = String(process.env.PLAYWRIGHT_BROWSERS_PATH || '').trim()
    if (!bundled_path || !fs.existsSync(bundled_path)) return false

    try
    {
        return fs.readdirSync(bundled_path).some(name => String(name || '').startsWith('chromium'))
    } catch
    {
        return false
    }
}

export function describe_browser_attempt(attempt)
{
    if (attempt?.type === 'channel')
        return attempt.channel === 'msedge' ? 'Microsoft Edge' : 'Google Chrome'
    return 'Bundled Chromium'
}

export async function launch_chromium_browser(chromium, config, launch_options = {}, hooks = {})
{
    const runtime_config = get_browser_runtime_config(config)
    const attempts = get_browser_attempts(runtime_config)
    let last_error = null

    for (const attempt of attempts)
    {
        const label = describe_browser_attempt(attempt)
        try
        {
            hooks.on_attempt?.(attempt, label)

            const browser = await chromium.launch({
                ...launch_options,
                ...(attempt.type === 'channel' ? { channel: attempt.channel } : {}),
            })

            hooks.on_success?.(attempt, label)
            return { browser, browser_label: label, browser_attempt: attempt, runtime_config }
        }
        catch (error)
        {
            last_error = error
            hooks.on_failure?.(attempt, label, error)
        }
    }

    const message = String(last_error?.message || last_error || 'Browser launch failed')
    throw new Error(`Tidak dapat membuka browser otomatis. Coba install Microsoft Edge/Google Chrome atau gunakan build bundled. Detail: ${message}`)
}
