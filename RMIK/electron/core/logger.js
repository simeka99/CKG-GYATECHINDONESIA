import { send_to_renderer } from './windowManager.js'

let log_buffer = []
let flush_timer = null
let verbose_mode = true

const IMPORTANT_PATTERNS = [
    'user_start', 'user_done', 'batch_done', 'worker_batch_done',
    '[SYSTEM]', '[ERR]', '[FATAL]', '[WARN]',
    'fatal_error', 'worker_run_error', 'heartbeat_error',
    'pelayanan_job_start', 'pelayanan_job_done', 'pelayanan_mulai_clicked',
    'pemeriksaan_mandiri_bank_received'
]

function is_important_line(line)
{
    const text = String(line || '')
    return IMPORTANT_PATTERNS.some(p => text.includes(p))
}

export function set_log_verbose(enabled)
{
    verbose_mode = !!enabled
}

export function buffer_log(line)
{
    if (!verbose_mode && !is_important_line(line))
        return

    log_buffer.push(line)
    if (log_buffer.length >= 20)
    {
        flush_log_buffer_now()
        return
    }
    if (!flush_timer)
    {
        flush_timer = setTimeout(() =>
        {
            flush_log_buffer_now()
        }, 200)
    }
}

export function flush_log_buffer_now()
{
    if (flush_timer)
    {
        clearTimeout(flush_timer)
        flush_timer = null
    }
    if (log_buffer.length === 0) return
    const batch = [...log_buffer]
    log_buffer = []
    send_to_renderer('worker_log_batch', batch)
}
