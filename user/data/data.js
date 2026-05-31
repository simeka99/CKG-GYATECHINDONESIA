'use strict';

const data_base = (window.DATA_WEB_BASE || '/user/data').replace(/\/+$/, '');

function fit_layout()
{
    const main = document.getElementById('main_wrap');
    if (!main) return;

    main.style.height = 'auto';
    main.style.overflow = 'visible';
    main.querySelectorAll('.data_scroll_area').forEach(el =>
    {
        el.style.flex = 'none';
        el.style.maxHeight = '';
    });
}

window.addEventListener('resize', fit_layout);
document.addEventListener('DOMContentLoaded', fit_layout);
fit_layout();

function toggle_all(cb)
{
    document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
    update_bulk_btn();
}

function update_bulk_btn()
{
    const checked = document.querySelectorAll('.row-check:checked');
    const all = document.querySelectorAll('.row-check');
    const btn = document.getElementById('bulk_actions');
    const cnt = document.getElementById('selected_count');
    const sa = document.getElementById('select_all');
    const icon = document.getElementById('select_all_icon');

    if (btn) btn.classList.toggle('hidden', checked.length === 0);
    if (cnt) cnt.textContent = checked.length;
    if (sa)
    {
        sa.indeterminate = checked.length > 0 && checked.length < all.length;
        sa.checked = all.length > 0 && checked.length === all.length;
    }
    if (icon) icon.style.opacity = (sa && sa.checked) ? '1' : '0';
}

function confirm_bulk_delete()
{
    const n = document.querySelectorAll('.row-check:checked').length;
    if (!n) return;
    if (confirm('Hapus ' + n + ' data peserta terpilih?\nTindakan ini tidak bisa dibatalkan.'))
        document.getElementById('bulk_form').submit();
}

function open_edit_modal_from_btn(btn)
{
    let row_data = {};
    try
    {
        row_data = JSON.parse(btn.getAttribute('data-row') || '{}');
    }
    catch (e)
    {
        console.error('[edit modal] gagal parse data:', e);
        return;
    }

    const modal = document.getElementById('edit_modal');
    const id_input = document.getElementById('edit_row_id');
    const id_label = document.getElementById('edit_row_label');
    const row_id = btn.getAttribute('data-row-id');

    if (!modal) return;

    if (id_input) id_input.value = row_id;
    if (id_label) id_label.textContent = '#' + row_id;

    modal.querySelectorAll('.modal-field').forEach(inp =>
    {
        const key = inp.getAttribute('data-field-key');
        inp.value = (row_data && key && row_data[key] != null) ? String(row_data[key]) : '';
    });

    set_bpjs_status('', '');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    setTimeout(() =>
    {
        const first = modal.querySelector('.modal-field');
        if (first) first.focus();
    }, 80);
}

function close_edit_modal()
{
    const modal = document.getElementById('edit_modal');
    if (!modal) return;
    modal.classList.remove('flex');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}

function format_date_dmy(val)
{
    val = String(val).trim();
    const dmy = val.match(/^(\d{2})[-/](\d{2})[-/](\d{4})$/);
    if (dmy) return dmy[3] + '-' + dmy[2] + '-' + dmy[1];
    const ymd = val.match(/^(\d{4})[-/](\d{2})[-/](\d{2})$/);
    if (ymd) return ymd[1] + '-' + ymd[2] + '-' + ymd[3];
    return val;
}

function normalize_phone_digits(val)
{
    return String(val || '').replace(/\D+/g, '');
}

function normalize_sync_fields_list(sync_fields)
{
    if (!Array.isArray(sync_fields)) return ['nik', 'nama', 'jenis_kelamin', 'tgl_lahir', 'no_hp'];
    const allowed = new Set(['nik', 'nama', 'jenis_kelamin', 'tgl_lahir', 'no_hp']);
    const result = [];
    for (const raw_key of sync_fields)
    {
        const key = String(raw_key || '').toLowerCase().trim();
        if (!allowed.has(key)) continue;
        if (!result.includes(key)) result.push(key);
    }
    return result.length > 0 ? result : ['nik', 'nama', 'jenis_kelamin', 'tgl_lahir', 'no_hp'];
}

function resolve_phone_fallback_from_form(target_input, modal_fields)
{
    if (target_input)
    {
        const target_val = String(target_input.value || '').trim();
        if (normalize_phone_digits(target_val) !== '') return target_val;
    }

    for (const inp of modal_fields)
    {
        const key_raw = String(inp.getAttribute('data-field-key') || '').toLowerCase().trim();
        const is_phone_field = key_raw.includes('hp') || key_raw.includes('telp') || key_raw.includes('telepon') || key_raw.includes('phone') || key_raw.includes('no_hp');
        if (!is_phone_field) continue;

        const candidate = String(inp.value || '').trim();
        if (normalize_phone_digits(candidate) === '') continue;
        return candidate;
    }

    return '';
}

function sync_bpjs()
{
    const nik_input = document.getElementById('modal_nik_field');
    if (!nik_input) return;

    const nik = nik_input.value.trim();
    if (!nik)
    {
        set_bpjs_status('error', 'Isi NIK terlebih dahulu');
        return;
    }

    const btn = document.getElementById('btn_bpjs_sync');
    const icon = document.getElementById('bpjs_sync_icon');
    const text = document.getElementById('bpjs_sync_text');

    btn.disabled = true;
    icon.style.animation = 'spin 1s linear infinite';
    text.textContent = 'Memuat...';
    btn.classList.add('opacity-70', 'cursor-not-allowed');
    set_bpjs_status('', '');

    fetch(`${data_base}/bpjs_sync.php?nik=` + encodeURIComponent(nik), {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(r => r.json())
        .then(res =>
        {
            reset_bpjs_btn(btn, icon, text);

            if (!res.success)
            {
                set_bpjs_status('error', res.message || 'Data tidak ditemukan');
                return;
            }

            const sync_fields = normalize_sync_fields_list(res.sync_fields);
            const sync_field_set = new Set(sync_fields);
            const phone_auto_fallback = !!res.phone_auto_fallback;
            const phone_fallback_number = normalize_phone_digits(res.phone_fallback_number || '');
            const no_hp_from_bpjs_enabled = sync_field_set.has('no_hp');
            const no_hp_from_fallback_enabled = !no_hp_from_bpjs_enabled && phone_fallback_number !== '';

            const map_rules = [
                { setting_key: 'nik', response_key: 'nik', matcher: key_raw => key_raw.includes('nik') },
                { setting_key: 'nama', response_key: 'nama', matcher: key_raw => key_raw.includes('nama') },
                { setting_key: 'tgl_lahir', response_key: 'tglLahir', matcher: key_raw => key_raw.includes('lahir') || key_raw.includes('tanggal') },
                { setting_key: 'jenis_kelamin', response_key: 'jenisKelamin', matcher: key_raw => key_raw.includes('kelamin') || key_raw.includes('jenis') },
                { setting_key: 'no_hp', response_key: 'noHP', matcher: key_raw => key_raw.includes('hp') || key_raw.includes('telp') || key_raw.includes('telepon') || key_raw.includes('phone') || key_raw.includes('no_hp') },
            ];

            let filled = 0;
            const modal_fields = Array.from(document.querySelectorAll('#edit_form .modal-field'));
            modal_fields.forEach(inp =>
            {
                const key_raw = (inp.getAttribute('data-field-key') || '').toLowerCase().trim();

                for (const rule of map_rules)
                {
                    if (!rule.matcher(key_raw)) continue;

                    if (rule.setting_key !== 'no_hp' && !sync_field_set.has(rule.setting_key)) continue;
                    if (rule.setting_key === 'no_hp' && !no_hp_from_bpjs_enabled && !no_hp_from_fallback_enabled) continue;

                    let val = '';

                    if (rule.setting_key !== 'no_hp')
                    {
                        if (!Object.prototype.hasOwnProperty.call(res.data || {}, rule.response_key)) continue;
                        val = String(res.data[rule.response_key] ?? '').trim();
                    }
                    else
                    {
                        if (no_hp_from_bpjs_enabled && Object.prototype.hasOwnProperty.call(res.data || {}, rule.response_key))
                            val = String(res.data[rule.response_key] ?? '').trim();
                    }

                    if (rule.setting_key === 'tgl_lahir' && val)
                        val = format_date_dmy(val);

                    if (rule.setting_key === 'no_hp' && !val)
                    {
                        if (no_hp_from_fallback_enabled && phone_fallback_number)
                            val = phone_fallback_number;
                        else if (no_hp_from_bpjs_enabled && phone_auto_fallback)
                            val = resolve_phone_fallback_from_form(inp, modal_fields);
                    }

                    if (!val) continue;

                    inp.value = val;
                    inp.classList.add('border-blue-400', 'bg-blue-50');
                    setTimeout(() => inp.classList.remove('border-blue-400', 'bg-blue-50'), 1500);
                    filled++;
                    break;
                }
            });

            if (filled <= 0)
            {
                set_bpjs_status('error', 'Data BPJS tidak cocok dengan kolom form');
                return;
            }

            set_bpjs_status('success', 'Berhasil mengisi ' + filled + ' field dari BPJS. Klik Simpan untuk menyimpan.');
        })
        .catch(err =>
        {
            reset_bpjs_btn(btn, icon, text);
            set_bpjs_status('error', 'Koneksi gagal, coba lagi');
            console.error('[bpjs sync]', err);
        });
}

function reset_bpjs_btn(btn, icon, text)
{
    btn.disabled = false;
    icon.style.animation = '';
    text.textContent = 'Sync BPJS';
    btn.classList.remove('opacity-70', 'cursor-not-allowed');
}

function set_bpjs_status(type, msg)
{
    const el = document.getElementById('bpjs_sync_status');
    if (!el) return;

    if (!msg)
    {
        el.classList.add('hidden');
        el.textContent = '';
        return;
    }

    el.classList.remove('hidden', 'text-emerald-600', 'text-rose-500');
    el.classList.add(type === 'success' ? 'text-emerald-600' : 'text-rose-500');
    el.textContent = (type === 'success' ? '✓ ' : '✗ ') + msg;
}

const bpjs_bulk_state_key = 'bpjs_bulk_sync_state_v4';
const bpjs_bulk_worker_count = 1;
let bpjs_bulk_running = false;
let bpjs_bulk_abort_requested = false;
let bpjs_bulk_abort_controllers = new Map();
let bpjs_bulk_selected_scope = 'sisa';
let bpjs_bulk_selected_batch_size = 1;
let bpjs_bulk_selected_target_mode = '100';
let bpjs_bulk_selected_target_custom = 100;
let bpjs_bulk_progress_timer = null;
let bpjs_bulk_progress_target = 0;

function set_bpjs_bulk_status(type, msg)
{
    const el = document.getElementById('bpjs_bulk_sync_status');
    if (!el) return;

    if (!msg)
    {
        el.classList.add('hidden');
        el.textContent = '';
        return;
    }

    el.classList.remove('hidden', 'text-emerald-600', 'text-rose-500', 'text-sky-600');
    if (type === 'success') el.classList.add('text-emerald-600');
    else if (type === 'progress') el.classList.add('text-sky-600');
    else el.classList.add('text-rose-500');

    const prefix = type === 'success' ? '[OK] ' : (type === 'progress' ? '[..] ' : '[X] ');
    el.textContent = prefix + msg;
}

function read_bpjs_bulk_state()
{
    try
    {
        const raw = localStorage.getItem(bpjs_bulk_state_key);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return (parsed && typeof parsed === 'object') ? parsed : null;
    }
    catch
    {
        return null;
    }
}

function write_bpjs_bulk_state(state)
{
    try
    {
        localStorage.setItem(bpjs_bulk_state_key, JSON.stringify(state));
    }
    catch { }
}

function clear_bpjs_bulk_state()
{
    try
    {
        localStorage.removeItem(bpjs_bulk_state_key);
    }
    catch { }
}

function normalize_bpjs_bulk_scope(scope)
{
    const val = String(scope || '').toLowerCase().trim();
    if (val === 'gagal') return 'gagal';
    return 'sisa';
}

function get_bpjs_bulk_scope_label(scope)
{
    return normalize_bpjs_bulk_scope(scope) === 'gagal' ? 'Gagal' : 'Sisa';
}

function get_bpjs_bulk_count(scope)
{
    const btn = document.getElementById('btn_bpjs_bulk_sync');
    if (!btn) return 0;
    const safe_scope = normalize_bpjs_bulk_scope(scope);
    const sisa_count = Number(btn.dataset.countSisa || 0);
    const gagal_count = Number(btn.dataset.countGagal || 0);
    return safe_scope === 'gagal' ? gagal_count : sisa_count;
}

function get_bpjs_bulk_total_count()
{
    return get_bpjs_bulk_count('sisa') + get_bpjs_bulk_count('gagal');
}

function get_bpjs_bulk_default_scope()
{
    if (get_bpjs_bulk_count('sisa') > 0) return 'sisa';
    return 'gagal';
}

function normalize_bpjs_bulk_batch_size(size)
{
    const n = Number(size || 1);
    if (!Number.isFinite(n)) return 1;
    return 1;
}

function normalize_bpjs_bulk_target_mode(mode)
{
    const val = String(mode || '').toLowerCase().trim();
    if (val === '10' || val === '50' || val === '100' || val === 'all' || val === 'custom')
        return val;
    return '100';
}

function normalize_bpjs_bulk_target_custom(value)
{
    const n = Number(value || 0);
    if (!Number.isFinite(n)) return 100;
    return Math.max(1, Math.floor(n));
}

function hide_bpjs_bulk_menu()
{
    const modal = document.getElementById('bpjs_bulk_scope_modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function set_bpjs_bulk_cancel_visible(visible)
{
    const btn = document.getElementById('btn_bpjs_bulk_cancel');
    if (!btn) return;
    btn.disabled = !visible;
    btn.classList.toggle('opacity-50', !visible);
    btn.classList.toggle('cursor-not-allowed', !visible);
}

function show_bpjs_bulk_progress_modal()
{
    const modal = document.getElementById('bpjs_bulk_progress_modal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function hide_bpjs_bulk_progress_modal()
{
    const modal = document.getElementById('bpjs_bulk_progress_modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function set_bpjs_bulk_progress_modal(state, subtitle = '')
{
    const title = document.getElementById('bpjs_bulk_progress_title');
    const count = document.getElementById('bpjs_bulk_progress_count');
    const percent = document.getElementById('bpjs_bulk_progress_percent');
    const fill = document.getElementById('bpjs_bulk_progress_fill');
    const sub = document.getElementById('bpjs_bulk_progress_subtitle');
    if (!title || !count || !percent || !fill || !sub) return;

    const target_total = Math.max(1, Number(state.target_total || 0));
    const rendered_value = state.rendered_processed ?? state.total_processed ?? 0;
    const processed_total = Math.max(0, Math.min(target_total, Number(rendered_value || 0)));
    const percent_number = Math.max(0, Math.min(100, Math.round((processed_total / target_total) * 100)));
    const scope_label = get_bpjs_bulk_scope_label(state.scope);

    title.textContent = `Sinkronisasi ${scope_label}`;
    count.textContent = `${processed_total}/${target_total}`;
    percent.textContent = `${percent_number}%`;
    fill.style.width = `${percent_number}%`;
    sub.textContent = subtitle !== '' ? subtitle : `Berhasil ${state.total_synced || 0} data, gagal ${state.total_failed || 0} data.`;
}

function set_bpjs_bulk_target_mode(mode)
{
    const safe_mode = normalize_bpjs_bulk_target_mode(mode);
    bpjs_bulk_selected_target_mode = safe_mode;

    const mode_select = document.getElementById('bpjs_bulk_target_mode');
    if (mode_select)
        mode_select.value = safe_mode;

    const custom_wrap = document.getElementById('bpjs_bulk_target_custom_wrap');
    if (custom_wrap)
        custom_wrap.classList.toggle('hidden', safe_mode !== 'custom');

    set_bpjs_bulk_scope(bpjs_bulk_selected_scope);
}

function set_bpjs_bulk_target_custom(value)
{
    bpjs_bulk_selected_target_custom = normalize_bpjs_bulk_target_custom(value);
    const custom_input = document.getElementById('bpjs_bulk_target_custom');
    if (custom_input)
        custom_input.value = String(bpjs_bulk_selected_target_custom);
    set_bpjs_bulk_scope(bpjs_bulk_selected_scope);
}

function resolve_bpjs_bulk_target_total(scope_count)
{
    const total = Math.max(0, Number(scope_count || 0));
    if (total <= 0) return 0;

    if (bpjs_bulk_selected_target_mode === 'all')
        return total;

    if (bpjs_bulk_selected_target_mode === 'custom')
        return Math.max(1, Math.min(total, Number(bpjs_bulk_selected_target_custom || 100)));

    const preset = Number(bpjs_bulk_selected_target_mode || 100);
    return Math.max(1, Math.min(total, preset));
}

function toggle_bpjs_bulk_menu()
{
    const btn = document.getElementById('btn_bpjs_bulk_sync');
    if (!btn || btn.disabled || bpjs_bulk_running) return;

    const modal = document.getElementById('bpjs_bulk_scope_modal');
    if (!modal) return;

    if (modal.classList.contains('hidden'))
    {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        return;
    }

    hide_bpjs_bulk_menu();
}

function set_bpjs_bulk_scope(scope)
{
    const safe_scope = normalize_bpjs_bulk_scope(scope);
    bpjs_bulk_selected_scope = safe_scope;

    const sisa_btn = document.getElementById('bpjs_bulk_scope_option_sisa');
    const gagal_btn = document.getElementById('bpjs_bulk_scope_option_gagal');
    const desc = document.getElementById('bpjs_bulk_scope_desc');
    const start_btn = document.getElementById('btn_bpjs_bulk_scope_start');

    if (sisa_btn)
    {
        const active = safe_scope === 'sisa';
        sisa_btn.classList.toggle('ring-2', active);
        sisa_btn.classList.toggle('ring-blue-300', active);
        sisa_btn.classList.toggle('border-blue-300', active);
        sisa_btn.classList.toggle('bg-blue-50', active);
    }

    if (gagal_btn)
    {
        const active = safe_scope === 'gagal';
        gagal_btn.classList.toggle('ring-2', active);
        gagal_btn.classList.toggle('ring-rose-300', active);
        gagal_btn.classList.toggle('border-rose-300', active);
        gagal_btn.classList.toggle('bg-rose-50', active);
    }

    const selected_count = get_bpjs_bulk_count(safe_scope);
    const selected_target_total = resolve_bpjs_bulk_target_total(selected_count);
    if (start_btn)
        start_btn.disabled = selected_target_total <= 0;

    if (start_btn)
        start_btn.classList.toggle('opacity-60', selected_target_total <= 0);

    if (desc)
    {
        const label = get_bpjs_bulk_scope_label(safe_scope);
        const mode_label = bpjs_bulk_selected_target_mode === 'all'
            ? 'semua data'
            : `${selected_target_total} data`;
        if (selected_target_total > 0)
            desc.textContent = `Target ${label}: ${mode_label}, progress tampil 1 per 1 data sampai selesai.`;
        else
            desc.textContent = `Target ${label}: tidak ada data untuk disinkronkan.`;
    }
}

function set_bpjs_bulk_batch_size(size)
{
    bpjs_bulk_selected_batch_size = normalize_bpjs_bulk_batch_size(size);
    const batch_input = document.getElementById('bpjs_bulk_batch_size');
    if (batch_input)
        batch_input.value = String(bpjs_bulk_selected_batch_size);
    set_bpjs_bulk_scope(bpjs_bulk_selected_scope);
}

function open_bpjs_bulk_scope_modal()
{
    const btn = document.getElementById('btn_bpjs_bulk_sync');
    if (!btn || btn.disabled || bpjs_bulk_running) return;
    toggle_bpjs_bulk_menu();
    set_bpjs_bulk_target_mode(bpjs_bulk_selected_target_mode);
    set_bpjs_bulk_target_custom(bpjs_bulk_selected_target_custom);
    set_bpjs_bulk_scope(bpjs_bulk_selected_scope);
}

function start_bpjs_bulk_from_selected_scope()
{
    hide_bpjs_bulk_menu();
    const selected_total = resolve_bpjs_bulk_target_total(get_bpjs_bulk_count(bpjs_bulk_selected_scope));
    sync_bpjs_bulk_start(current_upload_id(), bpjs_bulk_selected_scope, selected_total);
}

function update_bpjs_bulk_button_text()
{
    const btn = document.getElementById('btn_bpjs_bulk_sync');
    const txt = document.getElementById('bpjs_bulk_sync_text');
    const badge = document.getElementById('bpjs_bulk_sync_badge');
    if (!btn || !txt || !badge) return;

    txt.textContent = 'Sinkron BPJS Global';
    badge.textContent = String(get_bpjs_bulk_total_count());

    const can_sync = current_upload_id() > 0 && get_bpjs_bulk_total_count() > 0;
    if (!bpjs_bulk_running)
        btn.disabled = !can_sync;
}

function reset_bpjs_bulk_btn(btn, icon, text, should_hide_progress_modal = true)
{
    if (!btn || !icon || !text) return;
    bpjs_bulk_running = false;
    bpjs_bulk_abort_requested = false;
    bpjs_bulk_abort_controllers = new Map();
    bpjs_bulk_progress_target = 0;
    if (bpjs_bulk_progress_timer)
    {
        clearInterval(bpjs_bulk_progress_timer);
        bpjs_bulk_progress_timer = null;
    }
    btn.classList.remove('opacity-70', 'cursor-not-allowed');
    if (should_hide_progress_modal)
        hide_bpjs_bulk_progress_modal();
    set_bpjs_bulk_cancel_visible(false);
    update_bpjs_bulk_button_text();
}

function render_bpjs_bulk_running_progress(state)
{
    const txt = document.getElementById('bpjs_bulk_sync_text');
    const badge = document.getElementById('bpjs_bulk_sync_badge');
    if (!txt || !badge) return;

    const target_total = Math.max(1, Number(state.target_total || 0));
    const processed_total = Math.max(0, Math.min(target_total, Number(state.total_processed || 0)));
    const scope_label = get_bpjs_bulk_scope_label(state.scope);

    txt.textContent = `Sinkronisasi ${scope_label}`;
    badge.textContent = `${processed_total}/${target_total}`;
    set_bpjs_bulk_progress_modal(state);
}

function render_bpjs_bulk_status_progress(state)
{
    const scope_now = get_bpjs_bulk_scope_label(state.scope);
    const target_total = Math.max(1, Number(state.target_total || 0));
    const rendered_value = state.rendered_processed ?? state.total_processed ?? 0;
    const progress_now = Math.max(
        0,
        Math.min(
            target_total,
            Number(rendered_value || 0)
        )
    );
    set_bpjs_bulk_status(
        'progress',
        `[${scope_now.toUpperCase()}] ${progress_now}/${target_total} | berhasil ${state.total_synced || 0} | gagal ${state.total_failed || 0}`
    );
    set_bpjs_bulk_progress_modal(
        state,
        `Proses ${progress_now}/${target_total}. Berhasil ${state.total_synced || 0}, gagal ${state.total_failed || 0}.`
    );
}

function animate_bpjs_bulk_running_progress(state)
{
    const target_total = Math.max(1, Number(state.target_total || 0));
    const target_processed = Math.max(0, Math.min(target_total, Number(state.total_processed || 0)));
    state.rendered_processed = target_processed;
    bpjs_bulk_progress_target = target_processed;
    render_bpjs_bulk_running_progress({ ...state, total_processed: target_processed });
    render_bpjs_bulk_status_progress(state);
}

function current_upload_id()
{
    const btn = document.getElementById('btn_bpjs_bulk_sync');
    return Number(btn?.dataset.uploadId || 0);
}

function wait_ms(ms)
{
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function wait_bpjs_bulk_progress_complete(state, timeout_ms = 35000)
{
    const started_at = Date.now();
    const target_total = Math.max(1, Number(state.target_total || 0));
    const target_processed = Math.max(0, Math.min(target_total, Number(state.total_processed || 0)));
    bpjs_bulk_progress_target = Math.max(bpjs_bulk_progress_target, target_processed);

    state.rendered_processed = target_processed;
    render_bpjs_bulk_running_progress({ ...state, total_processed: target_processed });
    render_bpjs_bulk_status_progress(state);
    if (Date.now() - started_at < timeout_ms)
        await wait_ms(50);
}

function normalize_worker_count(v)
{
    const n = Number(v || 0);
    if (!Number.isFinite(n)) return bpjs_bulk_worker_count;
    return Math.max(1, Math.min(4, Math.floor(n)));
}

function normalize_worker_state_arrays(state)
{
    const worker_total = normalize_worker_count(state.worker_total || bpjs_bulk_worker_count);
    const legacy_last = Number(state.last_id || 0);
    const requested_total = Math.max(0, Number(state.requested_total || state.target_total || 0));

    let last_ids = Array.isArray(state.worker_last_ids) ? state.worker_last_ids.slice(0, worker_total) : [];
    while (last_ids.length < worker_total) last_ids.push(legacy_last);
    last_ids = last_ids.map(v => Number(v || 0));

    let done_flags = Array.isArray(state.worker_done) ? state.worker_done.slice(0, worker_total) : [];
    while (done_flags.length < worker_total) done_flags.push(false);
    done_flags = done_flags.map(v => !!v);

    state.worker_total = worker_total;
    state.worker_last_ids = last_ids;
    state.worker_done = done_flags;
    state.scope = normalize_bpjs_bulk_scope(state.scope);
    state.batch_size = 1;
    const ui_scope_total = Number(get_bpjs_bulk_count(state.scope) || 0);
    const processed_total = Number(state.total_processed || 0);
    const unresolved_total = Number(state.last_unresolved || 0);
    const fallback_target_total = Math.max(requested_total, processed_total + unresolved_total);
    const scope_limited_target = ui_scope_total > 0 ? Math.min(ui_scope_total, fallback_target_total) : fallback_target_total;

    state.requested_total = Math.max(1, fallback_target_total);
    state.target_total = Math.max(1, Math.max(processed_total, scope_limited_target));

    state.rendered_processed = Math.max(0, Math.min(state.target_total, Number(state.total_processed || 0)));
}

function abort_all_bpjs_bulk_requests()
{
    for (const ctrl of bpjs_bulk_abort_controllers.values())
    {
        try { ctrl.abort(); } catch { }
    }
    bpjs_bulk_abort_controllers.clear();
}

async function run_bpjs_bulk_sync(state, resumed = false)
{
    if (bpjs_bulk_running) return;
    bpjs_bulk_running = true;
    bpjs_bulk_abort_requested = false;
    normalize_worker_state_arrays(state);

    const btn = document.getElementById('btn_bpjs_bulk_sync');
    const icon = document.getElementById('bpjs_bulk_sync_icon');
    const text = document.getElementById('bpjs_bulk_sync_text');
    if (!btn || !icon || !text)
    {
        bpjs_bulk_running = false;
        return;
    }

    btn.disabled = true;
    const scope_label = get_bpjs_bulk_scope_label(state.scope);
    text.textContent = resumed ? `Lanjut ${scope_label}...` : `Sinkronisasi ${scope_label}...`;
    btn.classList.add('opacity-70', 'cursor-not-allowed');
    set_bpjs_bulk_cancel_visible(true);
    show_bpjs_bulk_progress_modal();
    set_bpjs_bulk_progress_modal(state, resumed ? 'Melanjutkan proses dari posisi terakhir...' : 'Menyiapkan sinkronisasi...');
    hide_bpjs_bulk_menu();
    animate_bpjs_bulk_running_progress(state);

    let finished = false;
    let keep_progress_modal = false;

    try
    {
        while (true)
        {
            if (bpjs_bulk_abort_requested)
                throw Object.assign(new Error('Sinkronisasi dibatalkan'), { code: 'BULK_CANCELLED' });

            const target_total_now = Math.max(1, Number(state.target_total || 0));
            if (Number(state.total_processed || 0) >= target_total_now)
            {
                finished = true;
                keep_progress_modal = true;
                await wait_bpjs_bulk_progress_complete(state);
                set_bpjs_bulk_status('success', `Sinkronisasi selesai sesuai target ${target_total_now} data.`);
                set_bpjs_bulk_progress_modal(state, `Selesai. Berhasil ${state.total_synced || 0} data, gagal ${state.total_failed || 0} data.`);
                clear_bpjs_bulk_state();
                setTimeout(() => window.location.reload(), 1200);
                break;
            }

            const active_workers = [];
            for (let i = 0; i < state.worker_total; i++)
                if (!state.worker_done[i]) active_workers.push(i);

            if (active_workers.length === 0)
            {
                finished = true;
                keep_progress_modal = true;
                await wait_bpjs_bulk_progress_complete(state);
                const unresolved = Number(state.last_unresolved || 0);
                const scope_now = get_bpjs_bulk_scope_label(state.scope);
                if (unresolved > 0)
                {
                    set_bpjs_bulk_status(
                        'success',
                        `[${scope_now.toUpperCase()}] Selesai. Berhasil ${state.total_synced || 0}, gagal ${state.total_failed || 0}, sisa ${unresolved}.`
                    );
                    set_bpjs_bulk_progress_modal(state, `Selesai. Berhasil ${state.total_synced || 0} data, gagal ${state.total_failed || 0} data.`);
                } else
                {
                    set_bpjs_bulk_status(
                        'success',
                        `[${scope_now.toUpperCase()}] Selesai. Semua sinkron (${state.total_synced || 0} data).`
                    );
                    set_bpjs_bulk_progress_modal(state, `Selesai. Semua data target berhasil diproses.`);
                }
                clear_bpjs_bulk_state();
                setTimeout(() => window.location.reload(), 1400);
                break;
            }

            const req_promises = active_workers.map(async (worker_index) =>
            {
                const remaining_target = Math.max(0, Number(state.target_total || 0) - Number(state.total_processed || 0));
                const worker_batch_size = Math.max(1, Math.min(Number(state.batch_size || 1), remaining_target || Number(state.batch_size || 1)));
                const body = new URLSearchParams({
                    upload_id: String(state.upload_id),
                    last_id: String(state.worker_last_ids[worker_index] || 0),
                    batch_size: String(worker_batch_size),
                    scope: state.scope,
                    shard_total: String(state.worker_total),
                    shard_index: String(worker_index),
                });

                const ctrl = new AbortController();
                bpjs_bulk_abort_controllers.set(worker_index, ctrl);

                try
                {
                    const res = await fetch(`${data_base}/bpjs_sync_bulk.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: body.toString(),
                        signal: ctrl.signal,
                    });
                    const json = await res.json().catch(() => ({}));
                    return { worker_index, json };
                }
                finally
                {
                    bpjs_bulk_abort_controllers.delete(worker_index);
                }
            });

            const settled = await Promise.allSettled(req_promises);
            let round_processed = 0;

            for (const item of settled)
            {
                if (item.status === 'rejected')
                {
                    if (bpjs_bulk_abort_requested)
                        throw Object.assign(new Error('Sinkronisasi dibatalkan'), { code: 'BULK_CANCELLED' });

                    throw new Error('Gagal sinkronisasi BPJS (worker error)');
                }

                const { worker_index, json } = item.value;
                if (!json || !json.ok)
                    throw new Error((json && json.message) || 'Gagal sinkronisasi BPJS');

                if (json.scope)
                    state.scope = normalize_bpjs_bulk_scope(json.scope);

                const processed = Number(json.processed || 0);
                const synced = Number(json.synced || 0);
                const failed = Number(json.failed || 0);

                round_processed += processed;
                state.total_processed = Number(state.total_processed || 0) + processed;
                state.total_synced = Number(state.total_synced || 0) + synced;
                state.total_failed = Number(state.total_failed || 0) + failed;
                state.worker_last_ids[worker_index] = Number(json.last_id || state.worker_last_ids[worker_index] || 0);
                state.last_unresolved = Number(json.unresolved || state.last_unresolved || 0);

                if (json.done || processed === 0)
                    state.worker_done[worker_index] = true;
            }

            state.running = true;
            write_bpjs_bulk_state(state);
            animate_bpjs_bulk_running_progress(state);

            if (round_processed <= 0)
                await wait_ms(80);
            else
                await wait_ms(10);
        }
    }
    catch (e)
    {
        const cancelled = e?.name === 'AbortError' || e?.code === 'BULK_CANCELLED' || bpjs_bulk_abort_requested;
        if (cancelled)
        {
            finished = true;
            clear_bpjs_bulk_state();
            set_bpjs_bulk_status('error', 'Sinkronisasi dibatalkan');
            set_bpjs_bulk_progress_modal(state, 'Sinkronisasi dibatalkan oleh user.');
            return;
        }

        state.running = true;
        write_bpjs_bulk_state(state);
        set_bpjs_bulk_status('error', `${e.message || 'Sinkronisasi gagal'} (refresh untuk lanjutkan)`);
        set_bpjs_bulk_progress_modal(state, e.message || 'Sinkronisasi gagal.');
    }
    finally
    {
        abort_all_bpjs_bulk_requests();
        if (!finished)
        {
            state.running = true;
            write_bpjs_bulk_state(state);
        }
        reset_bpjs_bulk_btn(btn, icon, text, !keep_progress_modal);
    }
}

function sync_bpjs_bulk_start(upload_id, scope = 'sisa', target_total_input = 0)
{
    if (bpjs_bulk_running)
    {
        set_bpjs_bulk_status('error', 'Sinkronisasi sedang berjalan. Tekan Stop dulu jika ingin ganti target.');
        return;
    }

    if (!upload_id)
    {
        set_bpjs_bulk_status('error', 'Upload tidak valid');
        return;
    }

    const safe_scope = normalize_bpjs_bulk_scope(scope);
    bpjs_bulk_selected_scope = safe_scope;
    bpjs_bulk_selected_batch_size = 1;
    set_bpjs_bulk_scope(safe_scope);
    hide_bpjs_bulk_menu();

    const scope_count = get_bpjs_bulk_count(safe_scope);
    if (scope_count <= 0)
    {
        set_bpjs_bulk_status('error', `Tidak ada data ${get_bpjs_bulk_scope_label(safe_scope)} untuk disinkronkan`);
        return;
    }

    const requested_total = Math.max(1, Math.min(scope_count, Number(target_total_input || resolve_bpjs_bulk_target_total(scope_count))));

    const state = {
        running: true,
        upload_id: Number(upload_id),
        scope: safe_scope,
        batch_size: 1,
        requested_total: requested_total,
        worker_total: bpjs_bulk_worker_count,
        worker_last_ids: Array(bpjs_bulk_worker_count).fill(0),
        worker_done: Array(bpjs_bulk_worker_count).fill(false),
        total_processed: 0,
        total_synced: 0,
        total_failed: 0,
        target_total: requested_total,
        last_unresolved: requested_total,
        started_at: Date.now(),
    };

    write_bpjs_bulk_state(state);
    run_bpjs_bulk_sync(state, false);
}

function cancel_bpjs_bulk_sync()
{
    hide_bpjs_bulk_menu();

    if (!bpjs_bulk_running)
    {
        clear_bpjs_bulk_state();
        set_bpjs_bulk_status('error', 'Tidak ada proses sinkronisasi aktif');
        return;
    }

    bpjs_bulk_abort_requested = true;
    abort_all_bpjs_bulk_requests();

    clear_bpjs_bulk_state();
    set_bpjs_bulk_status('progress', 'Membatalkan sinkronisasi...');
}

window.sync_bpjs_bulk_start = sync_bpjs_bulk_start;
window.toggle_bpjs_bulk_menu = toggle_bpjs_bulk_menu;
window.cancel_bpjs_bulk_sync = cancel_bpjs_bulk_sync;

function cleanup_legacy_bpjs_ui()
{
    const usia_select = document.querySelector('select[name="filter_usia"]');
    if (usia_select)
    {
        const usia_form = usia_select.closest('form') || usia_select.parentElement;
        if (usia_form) usia_form.remove();
    }

    const menu = document.getElementById('bpjs_bulk_scope_menu');
    if (menu) menu.remove();

    const btn = document.getElementById('btn_bpjs_bulk_sync');
    if (!btn) return;

    btn.onclick = null;
    btn.addEventListener('click', open_bpjs_bulk_scope_modal);
}

document.addEventListener('DOMContentLoaded', () =>
{
    cleanup_legacy_bpjs_ui();

    bpjs_bulk_selected_scope = get_bpjs_bulk_default_scope();
    bpjs_bulk_selected_batch_size = 1;
    set_bpjs_bulk_target_mode('100');
    set_bpjs_bulk_target_custom(100);
    set_bpjs_bulk_scope(bpjs_bulk_selected_scope);

    const scope_modal = document.getElementById('bpjs_bulk_scope_modal');
    if (scope_modal)
    {
        scope_modal.addEventListener('click', e =>
        {
            if (e.target === scope_modal)
                hide_bpjs_bulk_menu();
        });
    }

    const close_scope_btn = document.getElementById('btn_bpjs_bulk_scope_close');
    if (close_scope_btn)
        close_scope_btn.addEventListener('click', hide_bpjs_bulk_menu);

    const cancel_scope_btn = document.getElementById('btn_bpjs_bulk_scope_cancel');
    if (cancel_scope_btn)
        cancel_scope_btn.addEventListener('click', hide_bpjs_bulk_menu);

    const scope_sisa_btn = document.getElementById('bpjs_bulk_scope_option_sisa');
    if (scope_sisa_btn)
        scope_sisa_btn.addEventListener('click', () => set_bpjs_bulk_scope('sisa'));

    const scope_gagal_btn = document.getElementById('bpjs_bulk_scope_option_gagal');
    if (scope_gagal_btn)
        scope_gagal_btn.addEventListener('click', () => set_bpjs_bulk_scope('gagal'));

    const target_mode = document.getElementById('bpjs_bulk_target_mode');
    if (target_mode)
    {
        target_mode.value = bpjs_bulk_selected_target_mode;
        target_mode.addEventListener('change', () => set_bpjs_bulk_target_mode(target_mode.value));
    }

    const target_custom = document.getElementById('bpjs_bulk_target_custom');
    if (target_custom)
    {
        target_custom.value = String(bpjs_bulk_selected_target_custom);
        target_custom.addEventListener('input', () =>
        {
            if (bpjs_bulk_selected_target_mode !== 'custom') return;
            set_bpjs_bulk_target_custom(target_custom.value);
        });
        target_custom.addEventListener('change', () =>
        {
            if (bpjs_bulk_selected_target_mode !== 'custom') return;
            set_bpjs_bulk_target_custom(target_custom.value);
        });
    }

    const start_scope_btn = document.getElementById('btn_bpjs_bulk_scope_start');
    if (start_scope_btn)
        start_scope_btn.addEventListener('click', start_bpjs_bulk_from_selected_scope);

    const cancel_btn = document.getElementById('btn_bpjs_bulk_cancel');
    if (cancel_btn)
        cancel_btn.addEventListener('click', cancel_bpjs_bulk_sync);

    const modal = document.getElementById('edit_modal');
    if (modal)
        modal.addEventListener('click', e => { if (e.target === modal) close_edit_modal(); });

    document.addEventListener('keydown', e =>
    {
        if (e.key === 'Escape')
        {
            close_edit_modal();
            hide_bpjs_bulk_menu();
        }
    });

    update_bpjs_bulk_button_text();

    const saved = read_bpjs_bulk_state();
    const upload_id_now = current_upload_id();

    if (saved && saved.running && Number(saved.upload_id) === upload_id_now)
    {
        const saved_scope = normalize_bpjs_bulk_scope(saved.scope);
        const saved_batch_size = 1;
        bpjs_bulk_selected_scope = saved_scope;
        bpjs_bulk_selected_batch_size = saved_batch_size;
        set_bpjs_bulk_batch_size(saved_batch_size);
        set_bpjs_bulk_scope(saved_scope);
        set_bpjs_bulk_status(
            'progress',
            `[${get_bpjs_bulk_scope_label(saved_scope).toUpperCase()}] Melanjutkan sinkronisasi dari progress terakhir...`
        );
        run_bpjs_bulk_sync({ ...saved, scope: saved_scope, batch_size: saved_batch_size }, true);
    }
    else if (saved && saved.running && Number(saved.upload_id) > 0 && Number(saved.upload_id) !== upload_id_now)
    {
        set_bpjs_bulk_status(
            'progress',
            `Sinkronisasi aktif tersimpan di file upload #${saved.upload_id}. Buka file itu untuk melanjutkan.`
        );
    }
});
