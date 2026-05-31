'use strict';

const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

const tbl_page = { sukses: 1, gagal: 1 };
const tbl_per_page = { sukses: 25, gagal: 25 };
const tbl_loaded = { sukses: false, gagal: false };
const stats_cache = { key: '' };
const badge_total = { sukses: 0, gagal: 0 };
let debounce_tm = null;
const monitor_scope_query = 'scope=' + encodeURIComponent(String(typeof MONITOR_SCOPE_MODE !== 'undefined' ? MONITOR_SCOPE_MODE : 'umum'));

function fit_layout()
{
    const main = document.getElementById('main_wrap');
    if (!main) return;

    const compact = window.innerWidth < 1200 || window.innerHeight < 820;
    if (compact)
    {
        main.style.height = 'auto';
        main.style.overflow = 'visible';

        const scroll_areas = main.querySelectorAll('.monitor_scroll_area');
        const max_h = Math.max(320, Math.round(window.innerHeight * 0.6)) + 'px';
        scroll_areas.forEach(el =>
        {
            el.style.flex = 'none';
            el.style.maxHeight = max_h;
        });
        return;
    }

    main.style.overflow = 'hidden';
    const top = main.getBoundingClientRect().top;
    main.style.height = (window.innerHeight - top) + 'px';

    const scroll_areas = main.querySelectorAll('.monitor_scroll_area');
    scroll_areas.forEach(el =>
    {
        el.style.flex = '1';
        el.style.maxHeight = '';
    });
}
window.addEventListener('resize', fit_layout);
document.addEventListener('DOMContentLoaded', fit_layout);
fit_layout();


function tick_clock()
{
    const now = new Date();
    const pad = v => String(v).padStart(2, '0');
    const ec = document.getElementById('live_clock');
    const ed = document.getElementById('live_date');
    if (ec) ec.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
    if (ed) ed.textContent = `${DAYS[now.getDay()]}, ${now.getDate()} ${MONTHS[now.getMonth()]} ${now.getFullYear()}`;
}
setInterval(tick_clock, 1000);
tick_clock();

function show_toast(ok, msg)
{
    const wrap = document.getElementById('toast_wrap');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = `pointer-events-auto flex items-center gap-2.5 px-4 py-3 rounded-xl shadow-lg text-xs font-semibold text-white transition-opacity duration-300 ${ok ? 'bg-emerald-600' : 'bg-rose-600'}`;
    el.innerHTML = `<svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        ${ok ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>'
            : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'}
    </svg><span>${msg}</span>`;
    wrap.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3500);
}

function is_tab_filtered(tab)
{
    const search_value = (document.getElementById('search_' + tab)?.value ?? '').trim();
    if (tab === 'sukses')
    {
        const status_value = document.getElementById('filter_status_sukses')?.value ?? '';
        return search_value !== '' || status_value !== '';
    }

    const error_value = document.getElementById('filter_error_gagal')?.value ?? '';
    const source_value = document.getElementById('filter_src_gagal')?.value ?? '';
    return search_value !== '' || error_value !== '' || source_value !== '';
}

function set_badge_total(tab, total)
{
    const badge = document.getElementById('badge_' + tab);
    if (!badge) return;
    const count = Number(total) || 0;
    badge.textContent = count > 0 ? String(count) : '';
}

function sync_badge_total()
{
    if (!is_tab_filtered('sukses')) set_badge_total('sukses', badge_total.sukses);
    if (!is_tab_filtered('gagal')) set_badge_total('gagal', badge_total.gagal);
}

function switch_tab(panel)
{
    document.querySelectorAll('.tab_panel').forEach(p => p.classList.add('hidden'));
    document.getElementById('panel_' + panel)?.classList.remove('hidden');

    const toolbar = document.getElementById('toolbar_gagal');
    if (toolbar)
    {
        toolbar.classList.toggle('hidden', panel !== 'gagal');
        toolbar.classList.toggle('flex', panel === 'gagal');
    }

    document.querySelectorAll('.tab_btn').forEach(btn =>
    {
        const active = btn.dataset.panel === panel;
        const color = btn.dataset.color;
        btn.classList.remove('border-emerald-500', 'text-emerald-700', 'border-rose-500', 'text-rose-700', 'border-transparent', 'text-slate-400');
        btn.classList.add(active ? `border-${color}-500` : 'border-transparent', active ? `text-${color}-700` : 'text-slate-400');
        const badge = document.getElementById('badge_' + btn.dataset.panel);
        if (badge) badge.className = `ml-0.5 px-1.5 py-0.5 rounded-full text-[9px] font-black ${active ? `bg-${color}-100 text-${color}-700` : 'bg-slate-100 text-slate-400'}`;
    });

    if (!tbl_loaded[panel])
    {
        tbl_loaded[panel] = true;
        load_table(panel);
    }
}

function set_ring(ring_id, pct_id, ok_id, all_id, pct, ok, all)
{
    const el_ring = document.getElementById(ring_id);
    const el_pct = document.getElementById(pct_id);
    const el_ok = document.getElementById(ok_id);
    const el_all = document.getElementById(all_id);
    if (el_ring) el_ring.setAttribute('stroke-dasharray', `${Math.min(pct, 100).toFixed(1)} 100`);
    if (el_pct) el_pct.textContent = Math.round(pct) + '%';
    if (el_ok) el_ok.textContent = (+ok).toLocaleString('id-ID');
    if (el_all) el_all.textContent = (+all).toLocaleString('id-ID');
}

function render_stats(stats)
{
    const key = JSON.stringify(stats);
    if (stats_cache.key === key) return;
    stats_cache.key = key;

    const pending = +stats.pending || 0;
    const running = +stats.running || 0;
    const success = +stats.success || 0;
    const failed = +stats.failed || 0;
    const retryable = +stats.retryable_count || 0;

    const el = id => document.getElementById(id);
    if (el('stat_pending')) el('stat_pending').textContent = pending.toLocaleString('id-ID');
    if (el('stat_running')) el('stat_running').textContent = running.toLocaleString('id-ID');
    if (el('stat_success')) el('stat_success').textContent = success.toLocaleString('id-ID');
    if (el('stat_failed')) el('stat_failed').textContent = failed.toLocaleString('id-ID');

    const daftar_ok = +stats.daftar_ok || 0;
    const daftar_all = +stats.daftar_all || 0;
    const layanan_ok = +stats.layanan_ok || 0;
    const layanan_all = +stats.layanan_all || 0;

    set_ring('ring_daftar', 'pct_daftar', 'ok_daftar', 'all_daftar',
        daftar_all > 0 ? daftar_ok / daftar_all * 100 : 0, daftar_ok, daftar_all);
    set_ring('ring_layan', 'pct_layan', 'ok_layan', 'all_layan',
        layanan_all > 0 ? layanan_ok / layanan_all * 100 : 0, layanan_ok, layanan_all);

    const btn_retry = el('btn_retry_all');
    const lbl_retry = el('label_retry');

    if (lbl_retry)
        lbl_retry.textContent = failed > 0
            ? `${failed.toLocaleString('id-ID')} job gagal${retryable > 0 ? ` · ${retryable.toLocaleString('id-ID')} bisa di-retry` : ' · semua tidak bisa di-retry'}`
            : '';

    if (btn_retry)
    {
        btn_retry.disabled = retryable === 0;
        btn_retry.textContent = retryable > 0 ? `Retry ${retryable.toLocaleString('id-ID')} Job` : 'Tidak Ada yang Bisa Di-retry';
        btn_retry.className = 'ml-auto px-3 py-1.5 rounded-lg text-[10px] font-black border transition-colors '
            + (retryable > 0
                ? 'bg-amber-500 hover:bg-amber-600 text-white border-amber-400 cursor-pointer'
                : 'bg-slate-50 text-slate-300 border-slate-100 cursor-not-allowed');
    }

    badge_total.sukses = success;
    badge_total.gagal = failed;
    sync_badge_total();
}

function build_params(tab)
{
    const p = new URLSearchParams();
    p.set('tab', tab);
    p.set('page', tbl_page[tab]);
    p.set('per_page', tbl_per_page[tab]);
    p.set('q', document.getElementById('search_' + tab)?.value ?? '');
    if (tab === 'sukses')
        p.set('filter_status', document.getElementById('filter_status_sukses')?.value ?? '');
    else
    {
        p.set('filter_error', document.getElementById('filter_error_gagal')?.value ?? '');
        p.set('filter_src', document.getElementById('filter_src_gagal')?.value ?? '');
    }
    return p;
}

function load_table(tab, page)
{
    if (page !== undefined) tbl_page[tab] = page;
    else tbl_page[tab] = 1;

    const tbody = document.getElementById('tbody_' + tab);
    const cols = tab === 'sukses' ? 7 : 8;
    if (tbody) tbody.innerHTML = `<tr><td colspan="${cols}" class="px-4 py-8 text-center text-xs text-slate-300">
        <svg class="inline w-4 h-4 animate-spin mr-1.5 text-slate-300" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
        </svg>Memuat...</td></tr>`;

    fetch('monitor.php?ajax=render&' + monitor_scope_query + '&' + build_params(tab).toString())
        .then(r => r.json())
        .then(d =>
        {
            if (tbody) tbody.innerHTML = d.rows ?? '';
            const info = document.getElementById('info_' + tab);
            const btns = document.getElementById('btns_' + tab);
            if (info) info.textContent = d.info ?? '-';
            if (btns) btns.innerHTML = d.pagination ?? '';
            if (is_tab_filtered(tab))
                set_badge_total(tab, d.total ?? 0);
            else
                sync_badge_total();
        })
        .catch(() =>
        {
            if (tbody) tbody.innerHTML = `<tr><td colspan="${cols}" class="px-4 py-8 text-center text-xs text-rose-300">Gagal memuat data</td></tr>`;
        });
}

function goto_page(tab, page)
{
    tbl_page[tab] = page;
    load_table(tab, page);
}

function change_per_page(tab)
{
    tbl_per_page[tab] = parseInt(document.getElementById('per_page_' + tab)?.value ?? 25);
    load_table(tab);
}

function debounce_load(tab)
{
    clearTimeout(debounce_tm);
    debounce_tm = setTimeout(() => load_table(tab), 350);
}

function show_detail(row)
{
    const is_gagal = row.src !== undefined;
    const modal_title = document.getElementById('modal_title');
    const modal_body = document.getElementById('modal_body');
    const modal = document.getElementById('modal_detail');
    if (!modal) return;

    if (modal_title) modal_title.textContent = is_gagal ? 'Detail Gagal' : 'Detail Sukses';

    const field = (label, val, mono) =>
        `<div class="bg-slate-50 rounded-xl p-3">
            <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">${label}</div>
            <div class="${mono ? 'font-mono text-sm font-bold' : 'text-sm font-semibold'} text-slate-700">${val || '-'}</div>
        </div>`;

    let html = `<div class="grid grid-cols-2 gap-3">
        <div class="col-span-2">${field('NIK', row.nik, true)}</div>
        <div class="col-span-2">${field('Nama', row.nama, false)}</div>
        <div>${field('PC', row.pc_label, false)}</div>
        <div>${field('Tipe', row.task_type, false)}</div>`;

    if (is_gagal)
    {
        const error_detail = row.error_detail || row.error_msg || '-';
        html += `<div class="col-span-2 bg-slate-50 rounded-xl p-3">
            <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Keterangan Error</div>
            <div class="text-xs font-semibold text-slate-700">${row.reg_code || '-'}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">${error_detail}</div>
        </div>
        <div>${field('Sumber', row.src === 'arsip' ? 'Arsip' : 'Aktif', false)}</div>
        <div>${field('Attempt', (row.attempt ?? 0) + 'x', false)}</div>`;
    } else
    {
        html += `<div class="col-span-2">${field('Status', row.status_reg, false)}</div>`;
    }

    html += `<div class="col-span-2">${field('Waktu', (row.ts || '-').slice(0, 16), false)}</div></div>`;
    if (modal_body) modal_body.innerHTML = html;
    modal.classList.remove('hidden');
}

function close_modal()
{
    document.getElementById('modal_detail')?.classList.add('hidden');
}

function post_action(form_data, on_success)
{
    fetch('monitor.php', { method: 'POST', body: form_data })
        .then(r => r.json())
        .then(d =>
        {
            show_toast(d.ok, d.msg ?? (d.ok ? 'Berhasil' : 'Gagal'));
            if (d.ok && on_success) on_success();
        })
        .catch(() => show_toast(false, 'Gagal terhubung ke server'));
}

function delete_row(tab, id, src)
{
    if (!confirm('Hapus data ini?')) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    if (tab === 'sukses')
    {
        fd.append('action', 'delete_one_success');
        fd.append('id', id);
    } else
    {
        fd.append('action', 'delete_one_failed');
        fd.append('id', id);
        fd.append('src', src);
    }
    post_action(fd, () => { fetch_stats(); load_table(tab, tbl_page[tab]); });
}

function delete_all(tab)
{
    if (!confirm(`Hapus SEMUA data ${tab}? Tindakan ini tidak bisa dibatalkan.`)) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action', tab === 'sukses' ? 'delete_all_success' : 'delete_all_failed');
    post_action(fd, () => { fetch_stats(); load_table(tab); });
}

function retry_one(failed_id, source_type)
{
    if (!confirm('Pindahkan job ini ke antrian?')) return;
    const fd = new FormData();
    fd.append('action', 'retry_one');
    fd.append('jf_id', failed_id);
    fd.append('src', source_type || 'arsip');
    fd.append('csrf_token', CSRF_TOKEN);
    post_action(fd, () => { fetch_stats(); load_table('gagal', tbl_page.gagal); });
}

function mark_success_from_failed(failed_id, source_type)
{
    if (!confirm('Set data gagal ini jadi SUKSES manual?')) return;
    const fd = new FormData();
    fd.append('action', 'mark_success_from_failed');
    fd.append('id', failed_id);
    fd.append('src', source_type || 'aktif');
    fd.append('csrf_token', CSRF_TOKEN);
    post_action(fd, () => { fetch_stats(); load_table('gagal', tbl_page.gagal); load_table('sukses', tbl_page.sukses); });
}

function mark_failed_from_success(success_id)
{
    if (!confirm('Set data sukses ini jadi GAGAL manual?')) return;
    const fd = new FormData();
    fd.append('action', 'mark_failed_from_success');
    fd.append('id', success_id);
    fd.append('csrf_token', CSRF_TOKEN);
    post_action(fd, () => { fetch_stats(); load_table('sukses', tbl_page.sukses); load_table('gagal', tbl_page.gagal); });
}

function retry_all()
{
    if (!confirm('Pindahkan semua job yang bisa di-retry ke antrian?')) return;
    const fd = new FormData();
    fd.append('action', 'retry_all');
    fd.append('csrf_token', CSRF_TOKEN);
    post_action(fd, () => { fetch_stats(); load_table('gagal'); });
}

function today_str()
{
    const now = new Date();
    const pad = v => String(v).padStart(2, '0');
    return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}`;
}

function export_table(tab, format)
{
    if (typeof window.ensure_export_libs === 'function')
    {
        window.ensure_export_libs(function () { do_export(tab, format); });
        return;
    }
    do_export(tab, format);
}

function do_export(tab, format)
{
    const params = build_params(tab);
    params.set('per_page', 9999);
    params.set('page', 1);

    fetch('monitor.php?ajax=render&' + monitor_scope_query + '&' + params.toString())
        .then(r => r.json())
        .then(d =>
        {
            const rows = d.export_rows ?? [];
            const headers = d.export_headers ?? [];
            const name = `monitor_${tab}_${today_str()}`;
            if (!rows.length) { show_toast(false, 'Tidak ada data untuk diexport'); return; }
            if (format === 'xlsx') do_xlsx(headers, rows, name);
            else do_pdf(headers, rows, name, tab);
        })
        .catch(() => show_toast(false, 'Gagal mengambil data export'));
}

function do_xlsx(headers, rows, filename)
{
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const wb = XLSX.utils.book_new();
    ws['!cols'] = headers.map(() => ({ wch: 22 }));
    XLSX.utils.book_append_sheet(wb, ws, 'Data');
    XLSX.writeFile(wb, filename + '.xlsx');
    show_toast(true, 'File XLSX berhasil diunduh');
}

function do_pdf(headers, rows, filename, tab)
{
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.text(`Monitor ${tab === 'sukses' ? 'Sukses' : 'Gagal'}`, 14, 14);
    doc.setFontSize(8);
    doc.setFont('helvetica', 'normal');
    doc.text(`Dicetak: ${new Date().toLocaleString('id-ID')}`, 14, 20);
    doc.autoTable({
        head: [headers],
        body: rows,
        startY: 25,
        styles: { fontSize: 7, cellPadding: 2 },
        headStyles: { fillColor: [30, 30, 30], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [248, 250, 252] },
    });
    doc.save(filename + '.pdf');
    show_toast(true, 'File PDF berhasil diunduh');
}

function send_wa(tab)
{
    const params = build_params(tab);
    params.set('per_page', 9999);
    params.set('page', 1);

    fetch('monitor.php?ajax=render&' + monitor_scope_query + '&' + params.toString())
        .then(r => r.json())
        .then(d =>
        {
            const rows = d.export_rows ?? [];
            if (!rows.length) { show_toast(false, 'Tidak ada data untuk dikirim'); return; }
            let text = `*Monitor ${tab === 'sukses' ? 'Sukses' : 'Gagal'} RMIK*\nTotal: ${rows.length} data\n\n`;
            rows.slice(0, 20).forEach((r, i) => { text += `${i + 1}. ${r[1]} (${r[0]}) - ${r[4]}\n`; });
            if (rows.length > 20) text += `\n_...dan ${rows.length - 20} lainnya_`;
            window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
            show_toast(true, 'Membuka WhatsApp...');
        })
        .catch(() => show_toast(false, 'Gagal mengambil data'));
}

let stats_poll_timer = null;
let stats_poll_in_flight = false;
let last_stats_payload = null;

function next_stats_poll_delay(payload_ok, payload)
{
    if (document.hidden) return 15000;
    if (!payload_ok || !payload) return 9000;

    const stats = payload.stats || {};
    const pending = Number(stats.pending || 0);
    const running = Number(stats.running || 0);
    if (pending > 0 || running > 0) return 4500;
    return 9000;
}

function schedule_stats_poll(delay_ms)
{
    clearTimeout(stats_poll_timer);
    stats_poll_timer = setTimeout(fetch_stats, Math.max(1200, Number(delay_ms) || 5000));
}

function fetch_stats()
{
    if (stats_poll_in_flight)
    {
        schedule_stats_poll(1800);
        return;
    }

    stats_poll_in_flight = true;
    fetch('monitor.php?ajax=1&' + monitor_scope_query)
        .then(r => r.json())
        .then(data =>
        {
            last_stats_payload = data;
            try { render_stats(data.stats); } catch (e) { console.error('render_stats:', e); }
            const el = document.getElementById('last_update');
            if (el) el.textContent = data.ts ?? '-';
            schedule_stats_poll(next_stats_poll_delay(true, data));
        })
        .catch(() =>
        {
            const el = document.getElementById('last_update');
            if (el) el.textContent = 'error';
            schedule_stats_poll(next_stats_poll_delay(false, last_stats_payload));
        })
        .finally(() =>
        {
            stats_poll_in_flight = false;
        });
}

switch_tab('sukses');
fetch_stats();
document.addEventListener('visibilitychange', () =>
{
    if (!document.hidden) schedule_stats_poll(600);
});

