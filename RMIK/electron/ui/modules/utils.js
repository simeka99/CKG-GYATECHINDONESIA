import { state } from './state.js'
import
{
    MAX_DOM_LINES, IMPORTANT_PATTERNS,
    log_area, log_count_label, toast_box, theme_icon, btn_start, btn_stop,
    session_banner, session_dot, session_label, btn_login_si, btn_logout_si,
    status_indicator, footer_dot, log_status, info_pending, info_running, info_busy
} from './elements.js'

export function is_verbose_line(message)
{
    return !IMPORTANT_PATTERNS.some(p => message.includes(p))
}

export function classify_log_color(line)
{
    if (line.includes('pelayanan_nakes_form_progress'))
        return 'text-orange-400 font-bold'
    if (line.includes('pemeriksaan_mandiri_form_progress') || line.includes('user_start') || line.includes('pelayanan_job_start'))
        return 'text-sky-400 font-bold'
    if (line.includes('step_ok') || line.includes('Isi data peserta') || line.includes('Verifikasi data') || line.includes('Isi pekerjaan') || line.includes('Isi detail wali') || line.includes('Membuka konfirmasi') || line.includes('Konfirmasi pendaftaran') || line.includes('Menyimpan pendaftaran') || line.includes('Membuka pendaftaran baru'))
        return 'text-orange-400 font-bold'
    if (line.includes('user_skip_done')) return 'text-sky-400 font-bold'
    if (line.includes('batch_paused_by_signal') || line.includes('batch_resumed_by_signal')) return 'text-amber-400 font-bold'
    if (line.includes('pelayanan_mulai_clicked') || line.includes('PESERTA DIPROSES'))
        return 'text-emerald-400 font-bold'
    if (line.includes('[FATAL]') || line.includes('[ERR]')) return 'text-red-400'
    if (line.includes('gagal') || line.includes('GAGAL') || line.includes('error=') || line.includes('ERROR') || line.includes('success=no') || line.includes('success=false')) return 'text-red-400 font-bold'
    if (line.includes('user_done') || line.includes('pelayanan_job_done') || line.includes('batch_done') || line.includes('worker_batch_done') || line.includes('sukses'))
        return 'text-emerald-400 font-bold'
    if (line.includes('[WARN]')) return 'text-amber-400'
    return 'text-slate-100'
}

function mask_nik_value(value)
{
    const raw = String(value || '').replace(/\D/g, '')
    if (!raw) return '-'
    if (raw.length <= 4)
        return '*'.repeat(raw.length)
    if (raw.length <= 8)
        return `${raw.slice(0, 2)}${'*'.repeat(raw.length - 4)}${raw.slice(-2)}`
    return `${raw.slice(0, 4)}${'*'.repeat(raw.length - 8)}${raw.slice(-4)}`
}

function sanitize_nik_text(value)
{
    const raw = String(value || '')
    const with_key_masked = raw.replace(/(nik=)(\d{8,20})/gi, (_, prefix, nik) => `${prefix}${mask_nik_value(nik)}`)
    return with_key_masked.replace(/\b\d{16}\b/g, (nik) => mask_nik_value(nik))
}

function format_duration_short(total_seconds)
{
    const safe_seconds = Math.max(0, Number.parseInt(String(total_seconds || 0), 10) || 0)
    const hours = Math.floor(safe_seconds / 3600)
    const minutes = Math.floor((safe_seconds % 3600) / 60)
    const seconds = safe_seconds % 60
    if (hours > 0)
        return `${hours}j ${minutes}m ${seconds}d`
    if (minutes > 0)
        return `${minutes}m ${seconds}d`
    return `${seconds}d`
}

function format_dynamic_separator_line(summary_text)
{
    const safe_summary_text = String(summary_text || '').trim()
    if (!safe_summary_text)
        return ''

    const target_width = 78
    const min_dash = 6
    const summary_block = ` ${safe_summary_text} `
    const remaining_width = Math.max(0, target_width - summary_block.length)
    const left_dash_count = Math.max(min_dash, Math.floor(remaining_width / 2))
    const right_dash_count = Math.max(min_dash, remaining_width - Math.floor(remaining_width / 2))
    return `${'-'.repeat(left_dash_count)}${summary_block}${'-'.repeat(right_dash_count)}`
}

export function clean_log_line(message)
{
    const ts_match = message.match(/^\[(\d{2}:\d{2}:\d{2})\]/)
    const ts = ts_match ? ts_match[1] : ''
    const raw = String(message || '')
    const lower = raw.toLowerCase()

    const hidden_patterns = [
        'adaptive_wait_profile',
        'pelayanan_runtime_state',
        'pelayanan_same_location_skipped',
        'pelayanan_status,',
        'pelayanan_final_action',
        'pemeriksaan_mandiri_started',
        'search_peserta_by_nik_done',
        'pemeriksaan_nakes_bank_received',
        'pemeriksaan_mandiri_click_row_retry',
        'pemeriksaan_mandiri_click_row_retry_step',
        'pemeriksaan_mandiri_status_list',
        'pemeriksaan_mandiri_form_list',
        'pelayanan_nakes_question_list',
        'pelayanan_nakes_form_list',
        '[api result]',
        '[api result resp]',
        'report_network_error',
        'pelayanan_answer_quality_warning',
        'fill_input_ok',
        'select_gender_',
        'select_birth_date_',
        'select_exam_date_',
        'select_job_',
        'select_domisili_',
        'select_status_pernikahan_',
        'select_penyandang_disabilitas_',
        'domisili_step',
        'domisili_options_debug',
        'fill_detail_address',
        'attendance_verify_checked',
        'attendance_hadir_clicked',
        'attendance_modal_closed',
        'attendance_error',
        'registration_modal_ready',
        'registration_modal_fallback_by_button',
        'wali_form_not_found',
        'wali_no_wali_checked',
        'standby_reset_clicked',
        'sudah_menerima_layanan_detail',
        'kuota_habis_lanjut_clicked',
        'kuota_habis_lanjut_not_found',
        'verifikasi_detected',
        'step_start',
        'job_source_',
        'user_timeout_mode',
        'timeout_previous_user_still_running',
        'queue_ui_recover_failed',
        'batch_waiting_for_resume',
        'sukses',
        'gagal',
        'batch_start',
        'report_retry',
        'pending_exists_on_other_license_key',
        'ckg_umum_start',
        'navigating_to_',
        'halaman_pelayanan_umum_ready',
        'url=',
        'fill_wali_inline_start',
        'wali_inline_no_wali_checked',
        'step_fail',
        'form_step2_ready',
        'fill_form_step2_',
        'retry '
    ]

    if (message.includes('worker_batch_done'))
    {
        const ms_match = message.match(/elapsed_ms=(\d+)/)
        if (ms_match)
        {
            const duration_s = Math.max(0, Math.round(Number.parseInt(ms_match[1], 10) / 1000))
            const duration_text = format_duration_short(duration_s)
            return `[${ts}] - Seluruh antrean berhasil diselesaikan dalam waktu ${duration_text}`
        }
        return `[${ts}] - Seluruh antrean berhasil diselesaikan`
    }

    if (message.includes('batch_paused_by_signal')) return `[${ts}] - ⚠️ Robot dihentikan sementara (Pause)`
    if (message.includes('batch_resumed_by_signal')) return `[${ts}] - ▶️ Robot dilanjutkan kembali (Resume)`

    if (message.includes('user_skip_done'))
    {
        const nik_match = message.match(/nik=([0-9]+)/)
        const nik = nik_match ? mask_nik_value(nik_match[1]) : '-'
        return sanitize_nik_text(`[${ts}] - SKIP PESERTA: [${nik}] sudah pernah diproses di sesi sebelumnya`)
    }

    if (message.includes('user_start'))
    {
        const index_match = message.match(/index=(\d+)/)
        const total_match = message.match(/total=(\d+)/)
        const nik_match = message.match(/nik=([0-9]+)/)
        const nama_match = message.match(/nama=([^ ](?:.*[^=])?)/)
        const index = index_match ? index_match[1] : '?'
        const total = total_match ? total_match[1] : '?'
        const nik = nik_match ? mask_nik_value(nik_match[1]) : '-'
        const nama = nama_match ? nama_match[1] : '-'
        return sanitize_nik_text(`[${ts}] - START PENDAFTARAN [${index}/${total}] [${nik} - ${nama}]`)
    }

    if (message.includes('user_done'))
    {
        const index_match = message.match(/index=(\d+)/)
        const total_match = message.match(/total=(\d+)/)
        const nik_match = message.match(/nik=([0-9]+)/)
        const nama = message.match(/nama=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/nama=([^\n]+)$/)?.[1] || '-'
        const success_match = message.match(/success=(true|false)/)
        const status_raw = (message.match(/status=([^\n]+)/)?.[1] || '').trim()
        const dur_pos = status_raw.indexOf('dur_ms=')
        const status_clean = (dur_pos >= 0 ? status_raw.slice(0, dur_pos) : status_raw).trim()
        const index = index_match ? index_match[1] : '?'
        const total = total_match ? total_match[1] : '?'
        const nik = nik_match ? mask_nik_value(nik_match[1]) : '-'
        const success = success_match?.[1] === 'true'
        let status = (status_clean || '-').replace(/â€”/g, '-').trim()
        status = status.replace(/\s*\|\s*ABSEN\s*(BARU|LAMA)/i, '').trim()

        const dur_ms_match = message.match(/dur_ms=(\d+)/)
        const duration_s = Math.max(0, Math.round(Number.parseInt(dur_ms_match ? dur_ms_match[1] : '0', 10) / 1000))
        const duration_text = format_duration_short(duration_s)

        const result_prefix = success ? "DONE PENDAFTARAN" : "GAGAL PENDAFTARAN"
        return sanitize_nik_text(`[${ts}] - ${result_prefix} [${index}/${total}] [${nik} - ${nama}] -> ${status}\n      Proses ${duration_text}`)
    }

    if (message.includes('step_ok'))
    {
        const step_match = message.match(/step=([a-zA-Z_0-9]+)/)?.[1]
        if (!step_match) return ''
        const step_names = {
            "fill_form_step1": "Isi Data Peserta & Wali",
            "fill_form_step2": "Isi Pekerjaan & Domisili",
            "handle_wali_form": "Isi Detail Wali Pasien",
            "handle_verifikasi": "Verifikasi Data Pasien",
            "wait_registration_modal": "Membuka Konfirmasi Pendaftaran",
            "wait_result_modal": "Menyimpan Pendaftaran",
            "click_register_with_nik": "Konfirmasi Pendaftaran",
            "click_daftar_baru": "Membuka Pendaftaran Baru"
        }
        if (step_names[step_match])
        {
            return sanitize_nik_text(`[${ts}] - ${step_names[step_match]} selesai`)
        }
        return ''
    }

    if (/(^|\s)batch_done(\s|$)/.test(message))
    {
        const total_match = message.match(/total=(\d+)/)
        const ms_match = message.match(/elapsed_ms=(\d+)/)
        const total = total_match ? total_match[1] : '?'
        const ms = ms_match ? parseInt(ms_match[1]) : 0
        const sec = (ms / 1000).toFixed(1)
        return `[${ts}] - Selesai memproses ${total} data (${sec} detik)`
    }

    if (message.includes('pemeriksaan_mandiri_bank_received'))
    {
        const package_key = message.match(/package_key=([^\s]+)/)?.[1] || '-'
        const total_pertanyaan = message.match(/total_pertanyaan=(\d+)/)?.[1] || '-'
        const nik = mask_nik_value(message.match(/nik=([0-9]+)/)?.[1] || '')
        const nama = message.match(/nama=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/nama=([^\n]+)$/)?.[1] || '-'
        return sanitize_nik_text(`[${ts}] - PILIH PAKET UMUR: ${package_key} (${total_pertanyaan} soal) [${nik} - ${nama}]`)
    }

    if (message.includes('pemeriksaan_mandiri_form_progress'))
    {
        const step = message.match(/step=([0-9]+\/[0-9]+)/)?.[1] || '?/?'
        const layanan = message.match(/layanan=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/layanan=([^\n]+)$/)?.[1] || '-'
        return sanitize_nik_text(`[${ts}] - Proses Pemeriksaan Mandiri (${step}) - ${layanan}`)
    }

    if (message.includes('pelayanan_nakes_form_progress'))
    {
        const step = message.match(/step=([0-9]+\/[0-9]+)/)?.[1] || '?/?'
        const layanan = message.match(/layanan=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/layanan=([^\n]+)$/)?.[1] || '-'
        return sanitize_nik_text(`[${ts}] - Proses Pemeriksaan Nakes (${step}) - ${layanan}`)
    }

    if (message.includes('pelayanan_job_start'))
    {
        const index = message.match(/index=(\d+)/)?.[1] || '?'
        const total = message.match(/total=(\d+)/)?.[1] || '?'
        const nik = mask_nik_value(message.match(/nik=([0-9]+)/)?.[1] || '')
        const nama = message.match(/nama=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/nama=([^\n]+)$/)?.[1] || '-'
        return sanitize_nik_text(`[${ts}] - START PELAYANAN [${index}/${total}] [${nik} - ${nama}]`)
    }

    if (message.includes('pelayanan_mulai_clicked'))
    {
        const nik = mask_nik_value(message.match(/nik=([0-9]+)/)?.[1] || '')
        const nama = message.match(/nama=([^\n]+)$/)?.[1] || '-'
        return sanitize_nik_text(`[${ts}] - PESERTA DIPROSES [${nik} - ${nama}]`)
    }

    if (message.includes('pelayanan_job_done'))
    {
        const index = message.match(/index=(\d+)/)?.[1] || '?'
        const total = message.match(/total=(\d+)/)?.[1] || '?'
        const nik = mask_nik_value(message.match(/nik=([0-9]+)/)?.[1] || '')
        const nama = message.match(/nama=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/nama=([^\n]+)$/)?.[1] || '-'
        const success = message.match(/success=(yes|true)/i) ? true : false
        const status_text = message.match(/status_text=(.+?)(?:\s+duration_ms=|$)/)?.[1] || '-'
        const duration_ms = Number.parseInt(message.match(/duration_ms=(\d+)/)?.[1] || '0', 10)
        const estimate_remaining_s = Number.parseInt(message.match(/estimate_remaining_s=(\d+)/)?.[1] || '0', 10)
        const duration_s = Math.max(0, Math.round(Math.max(0, duration_ms) / 1000))
        const duration_text = format_duration_short(duration_s)
        const remaining_text = format_duration_short(estimate_remaining_s)
        const result_prefix = success ? "DONE PELAYANAN" : "GAGAL PELAYANAN"
        return sanitize_nik_text(`[${ts}] - ${result_prefix} [${index}/${total}] [${nik} - ${nama}] -> ${status_text}\n      Proses ${duration_text} | estimasi sisa ${remaining_text}`)
    }
    if (hidden_patterns.some(pattern => lower.includes(pattern)))
        return ''

    return sanitize_nik_text(message)
}

export function add_log(message, color_class)
{
    if (!state.show_verbose && is_verbose_line(message)) return

    const display = clean_log_line(message)
    if (display === '') return

    state.log_line_count++
    if (log_count_label) log_count_label.innerText = state.log_line_count + ' lines'

    const item = document.createElement('div')
    item.style.cssText = 'display:flex; gap:8px; padding:2px 0; align-items:flex-start'

    const arrow = document.createElement('span')
    arrow.style.cssText = 'color:#475569; user-select:none; flex-shrink:0; padding-top:1px'
    arrow.textContent = '➜'

    const match = display.match(/^(\[\d{2}:\d{2}:\d{2}\]\s*(?:-\s*)?)([\s\S]*)/)

    if (match)
    {
        const prefix_span = document.createElement('span')
        prefix_span.style.cssText = 'flex-shrink:0; white-space:pre; opacity:0.8'
        prefix_span.className = color_class
        prefix_span.textContent = match[1]

        const text_span = document.createElement('span')
        text_span.style.cssText = 'word-break:break-word; white-space:pre-wrap; flex:1'
        text_span.className = color_class
        text_span.textContent = match[2]

        item.appendChild(arrow)
        item.appendChild(prefix_span)
        item.appendChild(text_span)
    } else
    {
        const text_span = document.createElement('span')
        text_span.className = color_class
        text_span.style.cssText = 'word-break:break-word; white-space:pre-wrap; flex:1'
        text_span.textContent = display

        item.appendChild(arrow)
        item.appendChild(text_span)
    }
    if (log_area)
    {
        log_area.appendChild(item)
        while (log_area.children.length > MAX_DOM_LINES) log_area.removeChild(log_area.firstChild)
        log_area.scrollTo({ top: log_area.scrollHeight, behavior: 'instant' })
    }
}

export function clear_logs()
{
    if (log_area) log_area.innerHTML = '<p class="text-slate-600 italic opacity-50">// Logs cleared.</p>'
    state.log_line_count = 0
    if (log_count_label) log_count_label.innerText = '0 lines'
    notify('Log dibersihkan')
}

export function notify(message)
{
    if (!toast_box) return
    toast_box.innerHTML = message
    toast_box.classList.remove('translate-x-[200%]')
    setTimeout(() => toast_box.classList.add('translate-x-[200%]'), 3000)
}

export function notify_action(title, desc, btn_text, on_click, on_dismiss)
{
    if (!toast_box) return

    toast_box.innerHTML = `
        <div class="flex flex-col gap-2 relative pr-6">
            <button id="toastCloseBtn" class="absolute -right-2 -top-2 text-slate-400 hover:text-red-500 font-bold">×</button>
            <div class="font-bold text-amber-500 whitespace-nowrap">${title}</div>
            <div class="text-[10px] text-slate-300 font-normal leading-relaxed">${desc}</div>
            <button id="toastActionBtn" class="mt-1 px-4 py-1.5 bg-amber-500 text-slate-900 rounded font-bold text-[10px] hover:bg-amber-400 transition">${btn_text}</button>
        </div>
    `
    toast_box.style.borderLeftColor = '#f59e0b'
    toast_box.classList.remove('translate-x-[200%]')

    const close_btn = document.getElementById('toastCloseBtn')
    const action_btn = document.getElementById('toastActionBtn')

    if (close_btn)
    {
        close_btn.onclick = () =>
        {
            toast_box.classList.add('translate-x-[200%]')
            if (on_dismiss) on_dismiss()
        }
    }

    if (action_btn)
    {
        action_btn.onclick = () =>
        {
            if (on_click) on_click()
            toast_box.classList.add('translate-x-[200%]')
        }
    }
}

export function toggle_theme()
{
    const html = document.documentElement
    if (html.classList.contains('dark'))
    {
        html.classList.remove('dark')
        if (theme_icon) theme_icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />'
    } else
    {
        html.classList.add('dark')
        if (theme_icon) theme_icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M16.243 17.657l.707.707M7.757 6.343l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" />'
    }
}

export function set_btn_start_state()
{
    const can_start = state.is_session_valid && !state.is_node_running
    btn_start.disabled = !can_start
    if (can_start)
        btn_start.className = 'group flex items-center justify-center gap-3 bg-emerald-600 hover:bg-emerald-700 text-white py-4 rounded-2xl font-bold transition-all transform active:scale-95 shadow-lg shadow-emerald-200 dark:shadow-none'
    else
        btn_start.className = 'w-full flex items-center justify-center gap-3 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-600 py-4 rounded-2xl font-bold cursor-not-allowed transition-all'
}

export function set_session_valid()
{
    state.is_session_valid = true
    set_btn_start_state()
    session_banner.className = 'mb-4 rounded-2xl px-4 py-3 flex items-center justify-between gap-3 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30'
    session_dot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0'
    if (session_label)
    {
        session_label.className = 'text-[11px] font-bold text-emerald-700 dark:text-emerald-400'
        session_label.innerText = 'Sesi Sehat IndonesiaKu aktif'
    }
    btn_login_si.classList.add('hidden')
    btn_logout_si.classList.remove('hidden')
}

export function set_session_invalid(reason)
{
    state.is_session_valid = false
    set_btn_start_state()
    session_banner.className = 'mb-4 rounded-2xl px-4 py-3 flex items-center justify-between gap-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30'
    session_dot.className = 'w-2.5 h-2.5 rounded-full bg-red-500 shrink-0'
    if (session_label)
    {
        session_label.className = 'text-[11px] font-bold text-red-700 dark:text-red-400'
        session_label.innerText = reason || 'Belum login ke Sehat IndonesiaKu'
    }
    btn_login_si.classList.remove('hidden')
    btn_logout_si.classList.add('hidden')
}

export function set_session_loading(msg)
{
    state.is_session_valid = false
    set_btn_start_state()
    session_banner.className = 'mb-4 rounded-2xl px-4 py-3 flex items-center justify-between gap-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30'
    session_dot.className = 'w-2.5 h-2.5 rounded-full bg-amber-400 shrink-0'
    if (session_label)
    {
        session_label.className = 'text-[11px] font-bold text-amber-700 dark:text-amber-400'
        session_label.innerText = msg || 'Memproses...'
    }
    btn_login_si.classList.add('hidden')
    btn_logout_si.classList.add('hidden')
}

export function set_ui_running()
{
    state.is_node_running = true
    set_btn_start_state()
    btn_stop.disabled = false
    btn_stop.className = 'w-full flex items-center justify-center gap-3 bg-red-600 hover:bg-red-700 text-white py-4 rounded-2xl font-bold transition-all transform active:scale-95 shadow-lg shadow-red-200 dark:shadow-none'
    status_indicator.children[0].innerText = 'Running'
    status_indicator.children[0].className = 'text-[10px] font-bold text-emerald-500 uppercase tracking-wider'
    status_indicator.children[1].className = 'w-2.5 h-2.5 rounded-full bg-emerald-500 transition-all active-pulse'
    footer_dot.className = 'w-1.5 h-1.5 bg-emerald-500 rounded-full inline-block'
    if (log_status)
    {
        log_status.innerText = 'PROCESSING'
        log_status.className = 'tracking-widest uppercase text-emerald-500 font-bold'
    }
}

export function set_ui_stopped()
{
    state.is_node_running = false
    state.queue_total_locked = 0
    state.queue_current_index = 0
    set_btn_start_state()
    btn_stop.disabled = true
    btn_stop.className = 'flex items-center justify-center gap-3 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-600 py-4 rounded-2xl font-bold cursor-not-allowed transition-all'
    status_indicator.children[0].innerText = 'Offline'
    status_indicator.children[0].className = 'text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider'
    status_indicator.children[1].className = 'w-2.5 h-2.5 rounded-full bg-slate-300 dark:bg-slate-700 transition-all'
    footer_dot.className = 'w-1.5 h-1.5 bg-slate-700 rounded-full inline-block'
    if (log_status)
    {
        log_status.innerText = 'IDLE'
        log_status.className = 'tracking-widest uppercase text-slate-500 font-bold'
    }
    if (info_pending) info_pending.innerText = '—'
    if (info_running) info_running.innerText = '—'
    if (info_busy) info_busy.innerText = '—'
}
