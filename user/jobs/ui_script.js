(function ()
{
    if (window.job_ui_ready) return;
    window.job_ui_ready = true;

    const icon_html = {
        running: `<svg class="w-4 h-4 text-blue-500 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                  </svg>`,
        pending: `<span class="w-2.5 h-2.5 rounded-full bg-amber-400 block"></span>`,
        done: `<svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                  </svg>`,
        failed: `<svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/>
                  </svg>`,
    };

    const badge_cls = {
        running: 'bg-blue-100 text-blue-700',
        pending: 'bg-amber-50 text-amber-700',
        done: 'bg-emerald-50 text-emerald-700',
        failed: 'bg-rose-50 text-rose-600',
    };

    const label_txt = { running: 'Run', pending: 'Wait', done: 'OK', failed: 'Err' };
    const csrf_token = String(window.jobs_csrf_token || '');
    const scope_mode = String(window.jobs_scope_mode || 'umum');
    const process_log_cache = {};
    const row_process_time_cache = {};
    const last_worker_line_cache = {};
    const last_active_item_cache = {};
    const row_status_text = {
        running: 'Proses Berjalan',
        pending: 'Menunggu Proses',
        done: 'Proses Selesai',
        failed: 'Proses Gagal'
    };
    const row_status_cls = {
        running: 'text-blue-500',
        pending: 'text-slate-400',
        done: 'text-emerald-500',
        failed: 'text-rose-400'
    };
    function format_elapsed_time(elapsed_ms)
    {
        const ms_value = Math.max(0, Number(elapsed_ms || 0));
        if (ms_value < 1000)
            return Math.round(ms_value) + 'ms';
        const sec_value = ms_value / 1000;
        if (sec_value < 10)
            return sec_value.toFixed(1) + 's';
        return Math.round(sec_value) + 's';
    }

    function ensure_row_time_cache(item, now_ms, is_active)
    {
        const row_id = String(item.id || '');
        if (row_id === '') return null;
        if (!row_process_time_cache[row_id])
            row_process_time_cache[row_id] = { start_ms: null, end_ms: null, last_status: '', accumulated_ms: 0, last_update_ms: now_ms };

        const row_cache = row_process_time_cache[row_id];

        if (item.st === 'running')
        {
            if (row_cache.start_ms === null) row_cache.start_ms = now_ms;
            if (is_active)
            {
                row_cache.accumulated_ms += (now_ms - row_cache.last_update_ms);
            }
        }
        else if (item.st === 'done' || item.st === 'failed')
        {
            if (row_cache.end_ms === null) row_cache.end_ms = now_ms;
        }

        row_cache.last_update_ms = now_ms;
        row_cache.last_status = String(item.st || '');
        return row_cache;
    }

    function build_row_log_line(item, now_ms, is_active)
    {
        const row_cache = ensure_row_time_cache(item, now_ms, is_active);
        const elapsed_running = row_cache ? row_cache.accumulated_ms : 0;
        const elapsed_done = row_cache && row_cache.start_ms !== null && row_cache.end_ms !== null
            ? row_cache.accumulated_ms
            : 0;

        if (item.st === 'running')
        {
            if (elapsed_running > 0)
                return 'Proses Berjalan - ' + format_elapsed_time(elapsed_running);
            return 'Proses Berjalan';
        }
        if (item.st === 'pending')
            return 'Menunggu Proses';
        if (item.st === 'done')
        {
            if (elapsed_done > 0)
                return 'Proses Selesai - ' + format_elapsed_time(elapsed_done);
            return 'Proses Selesai';
        }
        if (item.st === 'failed' && item.em)
        {
            if (elapsed_done > 0)
                return 'Proses Gagal - ' + format_elapsed_time(elapsed_done);
            if (elapsed_running > 0)
                return 'Proses Gagal - ' + format_elapsed_time(elapsed_running);
            return 'Proses Gagal';
        }
        return row_status_text[item.st] || 'status tidak diketahui';
    }

    function pick_active_running_item_id(items, worker_log_line)
    {
        const worker_line_low = String(worker_log_line || '').toLowerCase();
        if (!Array.isArray(items) || items.length === 0)
            return 0;
        if (worker_line_low !== '')
        {
            for (let i = 0; i < items.length; i++)
            {
                const item_row = items[i];
                if (String(item_row.st || '') !== 'running')
                    continue;
                const nik_low = String(item_row.raw_nik || item_row.nik || '').toLowerCase();
                if (nik_low !== '' && worker_line_low.includes(nik_low))
                    return Number(item_row.id || 0);
            }
        }
        for (let i = 0; i < items.length; i++)
        {
            const item_row = items[i];
            if (String(item_row.st || '') === 'running')
                return Number(item_row.id || 0);
        }
        return 0;
    }

    function esc_html(s)
    {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function set_el(id, val)
    {
        const el = document.getElementById(id);
        if (el) el.innerHTML = val;
    }

    function set_txt(id, val)
    {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    function process_log_level_class(level)
    {
        if (level === 'ok') return 'border-emerald-200 bg-emerald-50 text-emerald-700';
        if (level === 'warn') return 'border-amber-200 bg-amber-50 text-amber-700';
        if (level === 'err') return 'border-rose-200 bg-rose-50 text-rose-700';
        return 'border-slate-200 bg-slate-50 text-slate-600';
    }

    window.add_job_process_log = function (lk, text, level = 'info')
    {
        const safe_text = String(text || '').trim();
        if (!safe_text) return;
        const host = document.getElementById('process-log-' + lk);
        if (!host) return;

        const cache_key = String(lk);
        if (!process_log_cache[cache_key])
            process_log_cache[cache_key] = { last_text: '', last_ts: 0 };

        const now_ts = Date.now();
        const cache_row = process_log_cache[cache_key];

        if (cache_row.last_text === safe_text && (now_ts - cache_row.last_ts) < 5000)
            return;

        cache_row.last_text = safe_text;
        cache_row.last_ts = now_ts;

        host.className = 'mb-2 rounded-lg border px-3 py-2 text-[11px] font-medium ' + process_log_level_class(level);
        host.textContent = safe_text;
        host.classList.remove('hidden');
    };

    function delete_btn(lk, jq_id)
    {
        const inputs = `
            <input type="hidden" name="action"         value="pc_delete_item">
            <input type="hidden" name="csrf_token"     value="${esc_html(csrf_token)}">
            <input type="hidden" name="license_key_id" value="${lk}">
            <input type="hidden" name="jq_id"          value="${jq_id}">`;
        const ico = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                d="M6 18L18 6M6 6l12 12"/></svg>`;
        return `
            <form method="POST" class="inline sm:hidden">
                ${inputs}
                <button type="submit" onclick="return confirm('Hapus 1 item dari antrian?')"
                    class="p-1.5 text-slate-300 hover:text-rose-500 active:text-rose-500 rounded-lg touch-manipulation">
                    ${ico}
                </button>
            </form>
            <form method="POST" class="hidden sm:inline group-hover:inline">
                ${inputs}
                <button type="submit" onclick="return confirm('Hapus 1 item dari antrian?')"
                    class="p-1 text-slate-300 hover:text-rose-500 transition-colors rounded">
                    ${ico}
                </button>
            </form>`;
    }

    function manual_mark_btn(lk, row_id, source_type, target_state)
    {
        const is_success = target_state === 'success';
        const action_name = is_success ? 'pc_mark_manual_success' : 'pc_mark_manual_failed';
        const confirm_text = is_success
            ? 'Tandai manual sebagai SUKSES? Data akan masuk ke sukses.'
            : 'Tandai manual sebagai GAGAL? Data akan masuk ke gagal.';
        const btn_cls = is_success
            ? 'p-1 rounded text-emerald-500 hover:bg-emerald-50 hover:text-emerald-700 transition-colors'
            : 'p-1 rounded text-rose-500 hover:bg-rose-50 hover:text-rose-700 transition-colors';
        const icon_ok = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>`;
        const icon_fail = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>`;
        return `
            <form method="POST" action="jobs.php?scope=${encodeURIComponent(scope_mode)}" class="inline">
                <input type="hidden" name="action" value="${action_name}">
                <input type="hidden" name="csrf_token" value="${esc_html(csrf_token)}">
                <input type="hidden" name="license_key_id" value="${lk}">
                <input type="hidden" name="row_id" value="${row_id}">
                <input type="hidden" name="source_type" value="${esc_html(source_type)}">
                <button type="submit" onclick="return confirm('${confirm_text}')"
                    class="${btn_cls}" title="${is_success ? 'Tandai sukses manual' : 'Tandai gagal manual'}">
                    ${is_success ? icon_ok : icon_fail}
                </button>
            </form>`;
    }

    function empty_state()
    {
        return `<div class="flex-1 min-h-[100px] flex flex-col items-center justify-center
                            border-2 border-dashed border-slate-200 rounded-xl gap-2 py-8">
                    <svg class="w-9 h-9 text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0
                               00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <p class="text-xs font-semibold text-slate-400">Antrian kosong</p>
                    <p class="text-[11px] text-slate-300 text-center px-4">Pilih jumlah lalu klik Simpan ke Antrian</p>
                </div>`;
    }

    window.set_lim = function (lk, val, hex)
    {
        document.getElementById('lim_' + lk).value = val;
        document.querySelectorAll('.chip-' + lk).forEach(b =>
        {
            const active = +b.dataset.val === val;
            b.style.background = active ? hex : '#fff';
            b.style.color = active ? '#fff' : '#475569';
            b.style.borderColor = active ? hex : '#e2e8f0';
        });
        document.getElementById('cust_' + lk).value = '';
    };

    window.set_lim_custom = function (lk, val)
    {
        document.getElementById('lim_' + lk).value = val || 1;
        document.querySelectorAll('.chip-' + lk).forEach(b =>
        {
            b.style.background = '#fff';
            b.style.color = '#475569';
            b.style.borderColor = '#e2e8f0';
        });
    };

    window.confirm_fetch = function (form, lk)
    {
        const n = +document.getElementById('lim_' + lk).value || 0;
        return n > 0 ? confirm('Ambil ' + n.toLocaleString('id-ID') + ' data ke antrian?') : false;
    };

    window.open_fetch_filter_modal = function (lk, pc_label, task_type, filter_settings = {})
    {
        const modal = document.getElementById('fetchFilterModal');
        if (!modal) return;

        const lk_input = document.getElementById('fetch_filter_lk');
        const pc_lbl = document.getElementById('fetch_filter_pc_label');
        const task_lbl = document.getElementById('fetch_filter_task_label');

        if (lk_input) lk_input.value = String(lk);
        if (pc_lbl) pc_lbl.textContent = String(pc_label || '-');
        if (task_lbl) task_lbl.textContent = String(task_type || '-').toUpperCase();

        const g = document.getElementById('fetch_filter_gender');
        const amin = document.getElementById('fetch_filter_age_min');
        const amax = document.getElementById('fetch_filter_age_max');
        const upload_id = document.getElementById('fetch_filter_upload_id');
        if (g) g.value = String(filter_settings?.gender || '');
        if (amin) amin.value = filter_settings?.age_min ?? '';
        if (amax) amax.value = filter_settings?.age_max ?? '';
        if (upload_id) upload_id.value = String(filter_settings?.upload_id || 0);

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
    };

    window.close_fetch_filter_modal = function ()
    {
        const modal = document.getElementById('fetchFilterModal');
        if (!modal) return;
        modal.classList.remove('flex');
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    };

    window.save_fetch_filter = function ()
    {
        const form = document.getElementById('fetch_filter_form');
        if (!form) return false;

        const lk = +(document.getElementById('fetch_filter_lk')?.value || 0);
        if (!lk)
        {
            alert('Data antrian tidak valid.');
            return false;
        }

        const age_min_el = document.getElementById('fetch_filter_age_min');
        const age_max_el = document.getElementById('fetch_filter_age_max');
        const age_min = age_min_el && age_min_el.value !== '' ? +age_min_el.value : null;
        const age_max = age_max_el && age_max_el.value !== '' ? +age_max_el.value : null;

        if (age_min !== null && (age_min < 0 || age_min > 150))
        {
            alert('Usia minimal harus 0-150 tahun.');
            return false;
        }
        if (age_max !== null && (age_max < 0 || age_max > 150))
        {
            alert('Usia maksimal harus 0-150 tahun.');
            return false;
        }
        if (age_min !== null && age_max !== null && age_min > age_max)
        {
            alert('Usia minimal tidak boleh lebih besar dari usia maksimal.');
            return false;
        }

        form.submit();
        return true;
    };

    function apply_btn_state(id, enabled)
    {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.disabled = !enabled;
        btn.style.opacity = enabled ? '1' : '0.4';
        btn.style.cursor = enabled ? 'pointer' : 'not-allowed';
        btn.style.pointerEvents = enabled ? 'auto' : 'none';
    }

    function update_buttons(lk, d, pending, running, retryable_failed)
    {
        const can_start = pending > 0 && !d.is_running;
        const can_stop = (running > 0) || (d.is_running && pending > 0);
        const can_retry = retryable_failed > 0;
        const can_clear = pending > 0;
        const can_selesai = pending === 0 && running === 0;

        const selesai_info = (!can_selesai && (running > 0 || pending > 0))
            ? '(' + [running > 0 ? running + 'r' : '', pending > 0 ? pending + 'p' : '']
                .filter(Boolean).join('/') + ')'
            : '';

        apply_btn_state('btn-start-' + lk, can_start);
        apply_btn_state('btn-stop-' + lk, can_stop);
        apply_btn_state('btn-retry-' + lk, can_retry);
        apply_btn_state('btn-clear-' + lk, can_clear);
        apply_btn_state('btn-selesai-' + lk, can_selesai);
        apply_btn_state('btn-start-m-' + lk, can_start);
        apply_btn_state('btn-stop-m-' + lk, can_stop);
        apply_btn_state('btn-retry-m-' + lk, can_retry);
        apply_btn_state('btn-clear-m-' + lk, can_clear);
        apply_btn_state('btn-selesai-m-' + lk, can_selesai);

        set_txt('btn-start-count-' + lk, pending > 0 ? pending : '');
        set_txt('btn-stop-count-' + lk, running > 0 ? running : '');
        set_txt('btn-retry-count-' + lk, retryable_failed > 0 ? retryable_failed : '');
        set_txt('btn-clear-count-' + lk, pending > 0 ? pending : '');
        set_txt('btn-selesai-info-' + lk, selesai_info);
        set_txt('btn-start-m-count-' + lk, pending > 0 ? pending : '');
        set_txt('btn-stop-m-count-' + lk, running > 0 ? running : '');
        set_txt('btn-retry-m-count-' + lk, retryable_failed > 0 ? retryable_failed : '');
        set_txt('btn-clear-m-count-' + lk, pending > 0 ? pending : '');
        set_txt('btn-selesai-m-info-' + lk, selesai_info);
    }

    function mask_nik_value(value)
    {
        const raw = String(value || '').replace(/\D/g, '');
        if (!raw) return '-';
        if (!window.hide_nik_enabled) return raw;
        if (raw.length <= 6) return '*'.repeat(raw.length);
        if (raw.length <= 10) return `${raw.slice(0, 2)}${'*'.repeat(raw.length - 4)}${raw.slice(-2)}`;
        return `${raw.slice(0, 3)}${'*'.repeat(raw.length - 6)}${raw.slice(-3)}`;
    }
    function sanitize_nik_text(value)
    {
        const raw = String(value || '');
        const with_key_masked = raw.replace(/(nik=)(\d{8,20})/gi, (_, prefix, nik) => `${prefix}${mask_nik_value(nik)}`);
        return with_key_masked.replace(/\b\d{16}\b/g, (nik) => mask_nik_value(nik));
    }

    function clean_worker_line(message)
    {
        const raw = String(message || '');
        const lower = raw.toLowerCase();
        const hidden_patterns = [
            'adaptive_wait_profile', 'pelayanan_runtime_state', 'pelayanan_same_location_skipped', 'pelayanan_status,',
            'pelayanan_final_action', 'pemeriksaan_mandiri_started', 'search_peserta_by_nik_done', 'pemeriksaan_nakes_bank_received',
            'pemeriksaan_mandiri_click_row_retry', 'pemeriksaan_mandiri_click_row_retry_step', 'pemeriksaan_mandiri_status_list',
            'pemeriksaan_mandiri_form_list', 'pelayanan_nakes_question_list', 'pelayanan_nakes_form_list', '[api result]', '[api result resp]',
            'report_network_error', 'pelayanan_answer_quality_warning', 'fill_input_ok', 'select_gender_', 'select_birth_date_',
            'select_exam_date_', 'select_job_', 'select_domisili_', 'select_status_pernikahan_', 'select_penyandang_disabilitas_',
            'domisili_step', 'domisili_options_debug', 'fill_detail_address', 'attendance_verify_checked', 'attendance_hadir_clicked',
            'attendance_modal_closed', 'attendance_error', 'registration_modal_ready', 'registration_modal_fallback_by_button',
            'wali_form_not_found', 'wali_no_wali_checked', 'standby_reset_clicked', 'sudah_menerima_layanan_detail',
            'kuota_habis_lanjut_clicked', 'kuota_habis_lanjut_not_found', 'verifikasi_detected', 'step_start', 'job_source_',
            'user_timeout_mode', 'timeout_previous_user_still_running', 'queue_ui_recover_failed', 'batch_waiting_for_resume',
            'sukses', 'gagal', 'batch_start', 'report_retry', 'pending_exists_on_other_license_key', 'ckg_umum_start',
            'navigating_to_', 'halaman_pelayanan_umum_ready', 'url=', 'fill_wali_inline_start', 'wali_inline_no_wali_checked',
            'step_fail', 'form_step2_ready', 'fill_form_step2_', 'retry ',
            'pelayanan_mulai_clicked', 'pelayanan_mulai_', 'pelayanan_started', 'pelayanan_click_',
            'click_mulai', 'click_next', 'click_submit', 'click_confirm', 'click_back', 'clicked'
        ];

        if (message.includes('worker_batch_done'))
        {
            const ms_match = message.match(/elapsed_ms=(\d+)/);
            if (ms_match)
            {
                const ms = parseInt(ms_match[1], 10);
                if (ms > 0)
                    return 'Seluruh antrean selesai diproses dalam ' + format_elapsed_time(ms);
            }
            return 'Seluruh antrean selesai diproses';
        }
        if (message.includes('batch_paused_by_signal')) return 'Robot dihentikan sementara (Pause)';
        if (message.includes('batch_resumed_by_signal')) return 'Robot dilanjutkan kembali (Resume)';

        if (message.includes('user_skip_done'))
        {
            const nik_match = message.match(/nik=([0-9]+)/);
            const nik = nik_match ? mask_nik_value(nik_match[1]) : '-';
            return sanitize_nik_text(`SKIP PESERTA: [${nik}] sudah pernah diproses di sesi sebelumnya`);
        }

        if (message.includes('user_start'))
        {
            const index_match = message.match(/index=(\d+)/);
            const total_match = message.match(/total=(\d+)/);
            const nik_match = message.match(/nik=([0-9]+)/);
            const nama = message.match(/nama=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/nama=([^\n]+)$/)?.[1] || '-';
            const index = index_match ? index_match[1] : '?';
            const total = total_match ? total_match[1] : '?';
            const nik = nik_match ? mask_nik_value(nik_match[1]) : '-';
            return sanitize_nik_text(`START PENDAFTARAN [${index}/${total}] [${nik} - ${nama}]`);
        }

        if (message.includes('pelayanan_job_start'))
        {
            const current = message.match(/current=(\d+)/)?.[1] || '?';
            const total = message.match(/total=(\d+)/)?.[1] || '?';
            const nik_match = message.match(/nik=([0-9]+)/);
            const nik = nik_match ? mask_nik_value(nik_match[1]) : '-';
            return sanitize_nik_text(`START PELAYANAN [${current}/${total}] [${nik}]`);
        }

        if (message.includes('user_done'))
        {
            const index_match = message.match(/index=(\d+)/);
            const total_match = message.match(/total=(\d+)/);
            const nik_match = message.match(/nik=([0-9]+)/);
            const nama = message.match(/nama=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/nama=([^\n]+)$/)?.[1] || '-';
            const success_match = message.match(/success=(true|false)/);
            const status_raw = (message.match(/status=([^\n]+)/)?.[1] || '').trim();
            const dur_pos = status_raw.indexOf('dur_ms=');
            const status_clean = (dur_pos >= 0 ? status_raw.slice(0, dur_pos) : status_raw).trim();
            const index = index_match ? index_match[1] : '?';
            const total = total_match ? total_match[1] : '?';
            const nik = nik_match ? mask_nik_value(nik_match[1]) : '-';
            const success = success_match?.[1] === 'true';
            let status = (status_clean || '-').replace(/â€”/g, '-').trim();
            status = status.replace(/\s*\|\s*ABSEN\s*(BARU|LAMA)/i, '').trim();
            const result_prefix = success ? "DONE PENDAFTARAN" : "GAGAL PENDAFTARAN";
            return sanitize_nik_text(`${result_prefix} [${index}/${total}] [${nik} - ${nama}] -> ${status}`);
        }

        if (message.includes('pelayanan_job_done'))
        {
            const current = message.match(/current=(\d+)/)?.[1] || '?';
            const total = message.match(/total=(\d+)/)?.[1] || '?';
            const nik_match = message.match(/nik=([0-9]+)/);
            const nama = message.match(/nama=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/nama=([^\n]+)$/)?.[1] || '-';
            const status_raw = (message.match(/status=([^\n]+)/)?.[1] || '').trim();
            const dur_pos = status_raw.indexOf('dur_ms=');
            const status = (dur_pos >= 0 ? status_raw.slice(0, dur_pos) : status_raw).trim();
            const nik = nik_match ? mask_nik_value(nik_match[1]) : '-';
            const success = !message.toLowerCase().includes('success=false');
            const result_prefix = success ? "DONE PELAYANAN" : "GAGAL PELAYANAN";
            return sanitize_nik_text(`${result_prefix} [${current}/${total}] [${nik} - ${nama}] -> ${status}`);
        }

        if (message.includes('step_ok'))
        {
            const step_match = message.match(/step=([a-zA-Z_0-9]+)/)?.[1];
            if (!step_match) return '';
            const step_names = {
                "fill_form_step1": "Isi Data Peserta & Wali",
                "fill_form_step2": "Isi Pekerjaan & Domisili",
                "handle_wali_form": "Isi Detail Wali Pasien",
                "handle_verifikasi": "Verifikasi Data Pasien",
                "wait_registration_modal": "Membuka Konfirmasi Pendaftaran",
                "wait_result_modal": "Menyimpan Pendaftaran",
                "click_register_with_nik": "Konfirmasi Pendaftaran",
                "click_daftar_baru": "Membuka Pendaftaran Baru"
            };
            if (step_names[step_match])
            {
                return sanitize_nik_text(`${step_names[step_match]} selesai`);
            }
            return '';
        }

        if (message.includes('pemeriksaan_mandiri_bank_received'))
        {
            const package_key = message.match(/package_key=([^\s]+)/)?.[1] || '-';
            const total_pertanyaan = message.match(/total_pertanyaan=(\d+)/)?.[1] || '-';
            const nik = mask_nik_value(message.match(/nik=([0-9]+)/)?.[1] || '');
            const nama = message.match(/nama=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/nama=([^\n]+)$/)?.[1] || '-';
            return sanitize_nik_text(`PILIH PAKET UMUR: ${package_key} (${total_pertanyaan} soal) [${nik} - ${nama}]`);
        }

        if (message.includes('pemeriksaan_mandiri_form_progress'))
        {
            const step = message.match(/step=([0-9]+\/[0-9]+)/)?.[1] || '?/?';
            const layanan = message.match(/layanan=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/layanan=([^\n]+)$/)?.[1] || '-';
            return sanitize_nik_text(`Proses Pemeriksaan Mandiri (${step}) - ${layanan}`);
        }

        if (message.includes('pelayanan_nakes_form_progress'))
        {
            const step = message.match(/step=([0-9]+\/[0-9]+)/)?.[1] || '?/?';
            const layanan = message.match(/layanan=([^\n]+?)\s+[a-z_]+=/i)?.[1] || message.match(/layanan=([^\n]+)$/)?.[1] || '-';
            return sanitize_nik_text(`Proses Pemeriksaan Nakes (${step}) - ${layanan}`);
        }

        if (/(^|\s)batch_done(\s|$)/.test(message))
        {
            const total_match = message.match(/total=(\d+)/);
            const total = total_match ? total_match[1] : '?';
            return `Selesai memproses ${total} data`;
        }

        if (hidden_patterns.some(pattern => lower.includes(pattern))) return '';

        return sanitize_nik_text(message);
    }

    window.init_job_card = function (lk, ac_hex)
    {
        let last_avail_value = 0;
        let prev_state = null;
        let run_started_ms = 0;

        function apply_data(d)
        {
            const pending = d.pending ?? 0;
            const running = d.running ?? 0;
            const done = d.done ?? 0;
            const failed = d.failed ?? 0;
            const retryable_failed = d.retryable_failed ?? failed;
            const batch_total = d.batch_total ?? 0;

            const bar_done = Math.max(0, done);
            const bar_total = Math.max(batch_total, pending + running + failed + bar_done);
            const pct = bar_total > 0 ? Math.min(100, Math.round(bar_done / bar_total * 100)) : 0;

            set_txt('stat-pend-' + lk, pending.toLocaleString('id-ID'));
            set_txt('stat-run-' + lk, running.toLocaleString('id-ID'));
            set_txt('stat-done-' + lk, bar_done.toLocaleString('id-ID'));
            set_txt('stat-fail-' + lk, failed.toLocaleString('id-ID'));

            const bar_fill = document.getElementById('bar-fill-' + lk);
            if (bar_fill) bar_fill.style.width = pct + '%';
            set_txt('bar-label-' + lk,
                bar_done.toLocaleString('id-ID') + ' dari ' + bar_total.toLocaleString('id-ID') + ' selesai');
            set_txt('bar-pct-' + lk, pct + '%');

            if (d.avail !== null && d.avail !== undefined)
            {
                last_avail_value = Number(d.avail ?? 0);
            }
            const avail_value = Number(last_avail_value ?? 0);
            set_txt('avail-' + lk, avail_value.toLocaleString('id-ID') + ' data');

            const fetch_btn = document.getElementById('fetch-btn-' + lk);
            const filter_btn = document.getElementById('fetch-filter-btn-' + lk);
            if (fetch_btn)
            {
                const avail = avail_value;
                fetch_btn.disabled = avail === 0;
                fetch_btn.style.background = avail > 0 ? ac_hex : '#e2e8f0';
                fetch_btn.style.color = avail > 0 ? '#fff' : '#94a3b8';
                const ico_no = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0
                           015.636 5.636m12.728 12.728L5.636 5.636"/></svg>`;
                const ico_ok = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>`;
                fetch_btn.innerHTML = avail === 0
                    ? `${ico_no} Tidak Ada Data`
                    : `${ico_ok} Simpan ke Antrian`;
            }

            if (filter_btn)
            {
                const avail = avail_value;
                filter_btn.disabled = avail === 0;
                if (avail > 0)
                {
                    filter_btn.classList.remove('bg-slate-100', 'text-slate-300', 'border-slate-200', 'cursor-not-allowed');
                    filter_btn.classList.add('bg-white', 'text-slate-600', 'border-slate-300', 'hover:bg-slate-50');
                } else
                {
                    filter_btn.classList.remove('bg-white', 'text-slate-600', 'border-slate-300', 'hover:bg-slate-50');
                    filter_btn.classList.add('bg-slate-100', 'text-slate-300', 'border-slate-200', 'cursor-not-allowed');
                }
            }

            const dot = document.getElementById('dot-' + lk);
            if (dot) dot.className = 'block w-2.5 h-2.5 rounded-full ' +
                (d.online ? 'bg-emerald-500' : 'bg-slate-300');

            const ping = document.getElementById('ping-' + lk);
            if (ping) ping.style.display = (d.online && d.is_running) ? '' : 'none';

            const online_lbl = document.getElementById('online-lbl-' + lk);
            if (online_lbl)
            {
                let txt = d.online ? 'Online' : 'Offline';
                if (d.online && d.is_running)
                {
                    txt += running > 0
                        ? ` <span class="ml-1 text-blue-600 font-bold animate-pulse">(${running} berjalan)</span>`
                        : ` <span class="ml-1 text-emerald-600 font-bold">(aktif)</span>`;
                }
                online_lbl.innerHTML = txt;
                online_lbl.className = 'text-[11px] font-semibold ' +
                    (d.online ? 'text-emerald-600' : 'text-slate-400');
            }

            const running_lbl = document.getElementById('is-running-lbl-' + lk);
            if (running_lbl)
            {
                running_lbl.textContent = d.is_running ? '1 (aktif)' : '0 (stop)';
                running_lbl.className = 'font-extrabold ' +
                    (d.is_running ? 'text-emerald-600' : 'text-slate-400');
            }

            update_buttons(lk, d, pending, running, retryable_failed);

            update_sched_ui(lk, d);

            set_txt('list-count-' + lk, '(' + d.items.length + ')');

            if (prev_state === null)
            {
                if (d.is_running && running > 0)
                {
                    if (run_started_ms <= 0)
                        run_started_ms = Date.now();
                    window.add_job_process_log(lk, 'Proses sedang berjalan', 'ok');
                }
            } else
            {
                if (!!prev_state.online !== !!d.online)
                    window.add_job_process_log(lk, d.online ? 'koneksi online' : 'koneksi offline', d.online ? 'ok' : 'warn');

                if (!!prev_state.is_running !== !!d.is_running)
                {
                    if (d.is_running && running > 0)
                        run_started_ms = Date.now();
                    window.add_job_process_log(lk, d.is_running ? 'proses dimulai' : 'proses dihentikan', d.is_running ? 'ok' : 'warn');
                }

                const fail_diff = Number(failed) - Number(prev_state.failed ?? 0);
                if (fail_diff > 0)
                    window.add_job_process_log(lk, 'ada data gagal, cek pesan merah di daftar peserta', 'err');
            }
            prev_state = { online: !!d.online, is_running: !!d.is_running, pending, running, done, failed };
            const worker_line_for_card = clean_worker_line(String(d.worker_log_line || '').trim());
            let worker_line_text = worker_line_for_card;
            if (worker_line_for_card !== '' && d.is_running && !worker_line_for_card.toLowerCase().includes('heartbeat'))
            {
                if (worker_line_for_card.toLowerCase().includes('worker_batch_done'))
                {
                    const elapsed_ms = run_started_ms > 0 ? (Date.now() - run_started_ms) : 0;
                    if (elapsed_ms > 0)
                        worker_line_text = worker_line_for_card + ' - durasi ' + format_elapsed_time(elapsed_ms);
                    run_started_ms = 0;
                }
                last_worker_line_cache[lk] = worker_line_text;
            }
            if (!d.is_running || running === 0)
                last_worker_line_cache[lk] = '';
            const display_worker_line = !worker_line_text || worker_line_for_card.toLowerCase().includes('heartbeat')
                ? (last_worker_line_cache[lk] || '')
                : worker_line_text;

            const wrap = document.getElementById('list-wrap-' + lk);
            if (!wrap) return;

            if (d.items.length === 0)
            {
                wrap.innerHTML = empty_state();
                return;
            }

            const now_ms = Date.now();
            const active_running_item_id = pick_active_running_item_id(d.items, d.worker_log_line);

            if (last_active_item_cache[lk] !== active_running_item_id)
            {
                last_worker_line_cache[lk] = active_running_item_id > 0 ? 'Memulai proses...' : '';
                last_active_item_cache[lk] = active_running_item_id;
            }
            const li_html = d.items.map(item =>
            {
                let effective_status = String(item.st || 'pending');
                if (effective_status === 'running' && active_running_item_id > 0 && Number(item.id || 0) !== active_running_item_id)
                    effective_status = 'pending';
                const source_type = String(item.st || 'pending') === 'failed' ? 'failed' : 'queue';
                const can_mark_success = source_type === 'queue' || source_type === 'failed';
                const can_mark_failed = source_type === 'queue';
                const row_item = Object.assign({}, item, { st: effective_status });
                let inline_log = '';
                if (effective_status === 'running' && display_worker_line !== '' && !display_worker_line.toLowerCase().includes('batch_done'))
                {
                    const dl = display_worker_line.toLowerCase();
                    let badge_color;
                    if (dl.startsWith('start ') || dl.startsWith('mulai') || dl.includes('dilanjutkan'))
                        badge_color = 'bg-emerald-50 border-emerald-200 text-emerald-700';
                    else if (dl.startsWith('done ') || dl.startsWith('selesai') || dl.includes('selesai diproses') || dl.includes('step ok') || dl.includes('selesai'))
                        badge_color = 'bg-emerald-50 border-emerald-200 text-emerald-700';
                    else if (dl.startsWith('gagal') || dl.includes('error') || dl.includes('dihentikan'))
                        badge_color = 'bg-rose-50 border-rose-200 text-rose-600';
                    else if (dl.includes('nakes'))
                        badge_color = 'bg-orange-50 border-orange-200 text-orange-600';
                    else
                        badge_color = 'bg-blue-50 border-blue-200 text-blue-600';
                    inline_log = `<span class="inline-flex items-center ml-1.5 px-1.5 py-0.5 rounded-md border text-[9px] font-semibold whitespace-nowrap ${badge_color}">${esc_html(display_worker_line)}</span>`;
                }

                return `
                <li class="group flex items-center gap-2 px-3 py-2 hover:bg-white transition-colors"
                    data-jqid="${item.id}">
                    <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
                        ${icon_html[effective_status] ?? icon_html.pending}
                    </div>
                    <div class="flex-1 min-w-0 flex items-center gap-2">
                        <div class="min-w-0 flex-shrink-0">
                            <div class="font-mono text-[10px] text-slate-400 truncate leading-tight">${esc_html(item.nik)}</div>
                            <div class="text-[11px] font-semibold text-slate-700 truncate leading-tight">${esc_html(item.nama)}</div>
                            ${item.em ? `<div class="text-[9px] text-rose-400 truncate" title="${esc_html(item.em)}">${esc_html(item.em)}</div>` : ''}
                        </div>
                        ${inline_log ? `<div class="flex-1 min-w-0">${inline_log}</div>` : ''}
                    </div>
                    <div class="flex-shrink-0 flex items-center gap-2">
                        <div class="flex items-center gap-1">
                            ${can_mark_success ? manual_mark_btn(lk, item.id, source_type, 'success') : ''}
                            ${can_mark_failed ? manual_mark_btn(lk, item.id, source_type, 'failed') : ''}
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-md ${badge_cls[effective_status] ?? 'bg-slate-100 text-slate-400'}">
                                ${label_txt[effective_status] ?? '-'}
                            </span>
                            ${effective_status === 'pending' ? delete_btn(lk, item.id) : ''}
                        </div>
                    </div>
                </li>`;
            }).join('');

            const existing_scroll_container = wrap.querySelector('.overflow-y-auto');
            if (existing_scroll_container)
            {
                const ul = existing_scroll_container.querySelector('ul');
                if (ul)
                {
                    const saved_scroll = existing_scroll_container.scrollTop;
                    ul.innerHTML = li_html;
                    existing_scroll_container.scrollTop = saved_scroll;
                }

                const p = wrap.querySelector('p.text-center');
                if (d.items.length >= 200)
                {
                    if (!p)
                    {
                        const new_p = document.createElement('p');
                        new_p.className = 'text-center text-[10px] text-slate-400 mt-1.5';
                        new_p.textContent = 'Menampilkan maks 200 item';
                        wrap.appendChild(new_p);
                    }
                } else if (p)
                {
                    p.remove();
                }
            } else
            {
                wrap.innerHTML = `
                    <div class="overflow-y-auto border border-slate-100 rounded-xl bg-slate-50/30"
                         style="max-height:320px; -webkit-overflow-scrolling:touch;">
                        <ul class="divide-y divide-slate-100">${li_html}</ul>
                    </div>
                    ${d.items.length >= 200
                        ? '<p class="text-center text-[10px] text-slate-400 mt-1.5">Menampilkan maks 200 item</p>'
                        : ''}`;
            }
        }

        if (typeof window.register_job_card === 'function')
            window.register_job_card(lk, ac_hex, apply_data);
    };


    window.toggle_sched_panel = function (lk)
    {
        const panel = document.getElementById('sched-panel-' + lk);
        const chevron = document.getElementById('sched-chevron-' + lk);
        if (!panel) return;
        const is_now_hidden = panel.classList.toggle('hidden');
        chevron.style.transform = is_now_hidden ? '' : 'rotate(180deg)';
    };

    window.on_sched_toggle = function (lk)
    {
        const en = document.getElementById('sched-en-' + lk)?.checked;
        ['sched-start-row-', 'sched-stop-row-'].forEach(p =>
        {
            const el = document.getElementById(p + lk);
            if (!el) return;
            el.classList.toggle('opacity-40', !en);
            el.classList.toggle('pointer-events-none', !en);
        });
    };

    window.on_stop_toggle = function (lk)
    {
        const on = document.getElementById('sched-stop-on-' + lk)?.checked;
        const row = document.getElementById('sched-stop-time-row-' + lk);
        if (!row) return;
        row.classList.toggle('opacity-40', !on);
        row.classList.toggle('pointer-events-none', !on);
    };

    window.on_retry_toggle = function (lk)
    {
        const on = document.getElementById('retry-auto-' + lk)?.checked;
        const el = document.getElementById('retry-opts-' + lk);
        if (!el) return;
        el.classList.toggle('opacity-40', !on);
        el.classList.toggle('pointer-events-none', !on);
    };

    window.save_schedule = function (lk)
    {
        const get = id => document.getElementById(id + lk);
        const body = new URLSearchParams({
            lk_id: lk,
            csrf_token: csrf_token,
            sched_enabled: get('sched-en-')?.checked ? 1 : 0,
            sched_start: get('sched-start-')?.value ?? '',
            sched_stop_on: get('sched-stop-on-')?.checked ? 1 : 0,
            sched_stop: get('sched-stop-')?.value ?? '',
            retry_auto: get('retry-auto-')?.checked ? 1 : 0,
            retry_interval: get('retry-interval-')?.value ?? 300,
        });

        fetch('/user/jobs/schedule_save.php?scope=' + encodeURIComponent(scope_mode), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(r => r.json())
            .then(d =>
            {
                const msg = document.getElementById('sched-save-msg-' + lk);
                if (!msg) return;
                msg.textContent = d.ok ? 'Tersimpan' : 'Gagal: ' + (d.msg ?? '');
                msg.className = 'text-[11px] font-semibold ' +
                    (d.ok ? 'text-emerald-600' : 'text-rose-500');
                msg.classList.remove('hidden');
                setTimeout(() => msg.classList.add('hidden'), 3000);

                if (d.ok)
                {
                    const active =
                        get('sched-en-')?.checked ||
                        get('retry-auto-')?.checked;
                    const badge = document.getElementById('sched-badge-' + lk);
                    if (badge)
                    {
                        badge.textContent = active ? 'Aktif' : 'Off';
                        badge.className = 'text-[9px] font-bold px-2 py-0.5 rounded-full ' +
                            (active ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-400');
                    }
                    window.add_job_process_log(lk, 'jadwal tersimpan', 'ok');
                } else
                {
                    window.add_job_process_log(lk, 'gagal simpan jadwal', 'err');
                }
            })
            .catch(() =>
            {
                const msg = document.getElementById('sched-save-msg-' + lk);
                if (!msg) return;
                msg.textContent = 'Gagal terhubung ke server';
                msg.className = 'text-[11px] font-semibold text-rose-500';
                msg.classList.remove('hidden');
                setTimeout(() => msg.classList.add('hidden'), 3000);
                window.add_job_process_log(lk, 'server tidak merespon', 'err');
            });
    };

    window.update_sched_ui = function (lk, d)
    {
        const el = document.getElementById('sched-countdown-' + lk);
        if (!el) return;

        if (!d.sched_enabled && !d.retry_auto)
        {
            el.innerHTML = '';
            el.classList.add('hidden');
            return;
        }

        const now_parts = (d.server_time ?? '').split(':').map(Number);
        if (now_parts.length < 2) return;
        const now_min = now_parts[0] * 60 + now_parts[1];

        const chip = (txt, cls) =>
            `<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold ${cls}">${txt}</span>`;

        const parts_html = [];

        if (d.sched_enabled && d.sched_start)
        {
            if (d.is_running)
            {
                parts_html.push(chip('Berjalan', 'bg-emerald-50 text-emerald-700'));
            } else
            {
                const sp = d.sched_start.split(':').map(Number);
                const start_m = sp[0] * 60 + sp[1];
                let diff = start_m - now_min;
                if (diff < 0) diff += 1440;

                if (diff <= 1)
                {
                    parts_html.push(chip('Start: sebentar lagi', 'bg-blue-50 text-blue-600'));
                } else
                {
                    const hh = Math.floor(diff / 60);
                    const mm = diff % 60;
                    const txt = 'Start ' + (hh > 0 ? hh + 'j ' : '') + mm + 'm lagi';
                    parts_html.push(chip(txt, 'bg-blue-50 text-blue-600'));
                }
            }

            if (d.sched_stop_on && d.sched_stop)
            {
                const ep = d.sched_stop.split(':').map(Number);
                const stop_m = ep[0] * 60 + ep[1];
                let diff_s = stop_m - now_min;
                if (diff_s < 0) diff_s += 1440;
                const hh = Math.floor(diff_s / 60);
                const mm = diff_s % 60;
                const txt = 'Stop ' + (hh > 0 ? hh + 'j ' : '') + mm + 'm lagi';
                parts_html.push(chip(txt, 'bg-rose-50 text-rose-500'));
            }
        }

        if (d.retry_auto)
        {
            parts_html.push(chip('Retry tiap ' + d.retry_interval + 'dtk', 'bg-amber-50 text-amber-600'));

            if (d.retry_last)
            {
                const rt = new Date(d.retry_last.replace(' ', 'T'));
                if (!isNaN(rt))
                {
                    const hh = String(rt.getHours()).padStart(2, '0');
                    const mm = String(rt.getMinutes()).padStart(2, '0');
                    const ss = String(rt.getSeconds()).padStart(2, '0');
                    const lbl = hh + ':' + mm + ':' + ss;
                    parts_html.push(chip('Retry terakhir ' + lbl, 'bg-slate-100 text-slate-500'));
                    set_txt('retry-last-' + lk, 'Terakhir retry: ' + lbl);
                }
            }
        }

        if (parts_html.length > 0)
        {
            el.innerHTML = `<div class="flex flex-wrap gap-1.5">${parts_html.join('')}</div>`;
            el.classList.remove('hidden');
        } else
        {
            el.innerHTML = '';
            el.classList.add('hidden');
        }
    };

    document.addEventListener('keydown', (e) =>
    {
        if (e.key === 'Escape')
            window.close_fetch_filter_modal?.();
    });

    document.addEventListener('submit', (e) =>
    {
        const form = e.target;
        if (!(form instanceof HTMLFormElement))
            return;
        if (String(form.method || '').toUpperCase() !== 'POST')
            return;
        if (!form.querySelector('input[name="csrf_token"]'))
        {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'csrf_token';
            hidden.value = csrf_token;
            form.appendChild(hidden);
            return;
        }
        const existing = form.querySelector('input[name="csrf_token"]');
        if (existing && !String(existing.value || '').trim())
            existing.value = csrf_token;
    }, true);

    document.addEventListener('click', (e) =>
    {
        const modal = document.getElementById('fetchFilterModal');
        if (!modal || modal.classList.contains('hidden')) return;
        if (e.target === modal) window.close_fetch_filter_modal?.();
    });

})();
