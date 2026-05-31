(function ()
{
    if (window.unified_poll_ready) return;
    window.unified_poll_ready = true;

    const csrf_token = String(window.jobs_csrf_token || '');
    const scope_mode = String(window.jobs_scope_mode || 'umum');
    const registered_cards = {};
    let poll_timer = null;
    let poll_in_flight = false;
    let poll_pause_until = 0;
    let err_count = 0;
    let poll_round = 0;
    let last_data = null;
    let avail_last_ts = 0;

    function set_g(id, val)
    {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    function set_btn(id, enabled)
    {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.disabled = !enabled;
        btn.style.opacity = enabled ? '1' : '0.4';
        btn.style.cursor = enabled ? 'pointer' : 'not-allowed';
        btn.style.pointerEvents = enabled ? 'auto' : 'none';
    }

    window.register_job_card = function (lk, ac_hex, apply_fn)
    {
        registered_cards[lk] = { ac_hex: ac_hex, apply_fn: apply_fn };
    };

    function next_delay(ok, data)
    {
        if (document.hidden) return 12000;
        if (!ok || !data) return 6000;
        const pj = Number(data.global?.pending_jobs ?? 0);
        const rj = Number(data.global?.running_jobs ?? 0);
        if (rj > 0 || pj > 0) return 2600;
        return 7000;
    }

    function schedule(ms)
    {
        clearTimeout(poll_timer);
        poll_timer = setTimeout(run_poll, Math.max(1000, Number(ms) || 2500));
    }

    function apply_global(g)
    {
        if (!g) return;
        set_g('g-stat-queue', g.queue.toLocaleString('id-ID'));
        set_g('g-stat-success', g.success.toLocaleString('id-ID'));
        set_g('g-stat-failed', g.failed.toLocaleString('id-ID'));
        set_g('g-stat-failed-x', g.failed_x.toLocaleString('id-ID'));
        set_g('g-stat-daftar', g.daftar_ok.toLocaleString('id-ID'));
        set_g('g-stat-layanan', g.layanan_ok.toLocaleString('id-ID'));
        set_g('g-stat-pasien', g.pasien.toLocaleString('id-ID'));

        set_btn('g-btn-start', !!g.can_start);
        set_btn('g-btn-stop', !!g.can_stop);
        set_btn('g-btn-retry', !!g.can_retry);
        set_btn('g-btn-clear', !!g.can_clear);
        set_btn('g-btn-selesai', !!g.can_selesai);

        set_g('g-btn-start-count', g.start_count > 0 ? g.start_count.toLocaleString('id-ID') : '');
        set_g('g-btn-stop-count', g.stop_count > 0 ? g.stop_count.toLocaleString('id-ID') : '');
        set_g('g-btn-retry-count', g.retry_count > 0 ? g.retry_count.toLocaleString('id-ID') : '');
        set_g('g-btn-clear-count', g.clear_count > 0 ? g.clear_count.toLocaleString('id-ID') : '');

        const pj = Number(g.pending_jobs ?? 0);
        const rj = Number(g.running_jobs ?? 0);
        set_g('g-btn-selesai-info',
            (pj > 0 || rj > 0)
                ? '(' + [rj > 0 ? rj + 'r' : '', pj > 0 ? pj + 'p' : ''].filter(Boolean).join('/') + ')'
                : '');
    }

    function run_poll()
    {
        if (poll_in_flight)
        {
            schedule(1500);
            return;
        }
        const now_ts = Date.now();
        if (now_ts < poll_pause_until)
        {
            schedule(poll_pause_until - now_ts);
            return;
        }

        poll_in_flight = true;
        poll_round++;

        const need_avail = poll_round <= 1 || (now_ts - avail_last_ts) >= 30000;
        const url = '/user/jobs/poll_unified.php?scope=' + encodeURIComponent(scope_mode)
            + '&with_avail=' + (need_avail ? '1' : '0');

        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d)
            {
                err_count = 0;
                if (!d.ok)
                {
                    schedule(next_delay(false, last_data));
                    return;
                }
                last_data = d;
                if (need_avail) avail_last_ts = Date.now();

                apply_global(d.global);

                if (Array.isArray(d.pcs))
                {
                    var container = document.querySelector('#jobs-cards > div.space-y-4');
                    d.pcs.forEach(function (pc_data)
                    {
                        var card = registered_cards[pc_data.lk_id];
                        if (card && card.apply_fn)
                            card.apply_fn(pc_data);

                        if (container)
                        {
                            var card_el = document.querySelector('[data-card-lk="' + pc_data.lk_id + '"]');
                            if (card_el)
                            {
                                container.appendChild(card_el);
                            }
                        }
                    });
                }

                schedule(next_delay(true, d));
            })
            .catch(function ()
            {
                err_count++;
                if (err_count >= 5)
                {
                    poll_pause_until = Date.now() + 30000;
                    err_count = 0;
                }
                schedule(next_delay(false, last_data));
            })
            .finally(function ()
            {
                poll_in_flight = false;
            });
    }

    document.addEventListener('visibilitychange', function ()
    {
        if (!document.hidden) schedule(700);
    });

    schedule(100);
})();
