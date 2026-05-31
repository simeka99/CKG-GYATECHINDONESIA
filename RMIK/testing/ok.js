(async () =>
{
    const data = {
        nik: "3206032308730002",
        nama: "NUNU SAPNUDIN",
        nomor_whatsapp: "85797501539",
        jenis_kelamin: "Laki-Laki",
        tanggal_lahir: "1973-08-23",
        pekerjaan: "Wirausaha/Pekerja Mandiri",
        domisili: {
            provinsi: "Jawa Barat",
            kabupaten_kota: "Kab. Tasikmalaya",
            kecamatan: "Cikalong",
            kelurahan: "Tonjongsari"
        },
        detail_domisili: "Pareang, Rt 001, Rw 002"
    };

    const data_wali = {
        nik: "3206017011990001",
        nama: "Gita Amalia Puspita",
        tanggal_lahir: "1999-11-30",
        jenis_kelamin: "Perempuan"
    };

    const DEBUG = true;
    let network_speed = 'fast';

    const log = (level, message, data) =>
    {
        if (!DEBUG) return;
        const timestamp = new Date().toISOString().slice(11, 23);
        const prefix = { info: 'INFO', warn: 'WARN', error: 'ERROR', success: 'SUCCESS' }[level] || 'LOG';
        console.log(`[${timestamp}] ${prefix}: ${message}`, data || '');
    };

    const perf_now = () => performance.now();
    const raf = () => new Promise((r) => requestAnimationFrame(() => r()));
    const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
    const is_visible = (el) => !!(el && (el.offsetParent || el.getClientRects().length));

    const create_benchmark = () =>
    {
        const marks = [];
        const t0 = perf_now();
        return {
            mark: (name) => marks.push({ name, t: perf_now() - t0 }),
            summary: () =>
            {
                const steps = [];
                for (let i = 0; i < marks.length; i++)
                {
                    const prev = i === 0 ? 0 : marks[i - 1].t;
                    steps.push({ step: marks[i].name, ms_from_start: Math.round(marks[i].t), ms_step: Math.round(marks[i].t - prev) });
                }
                return { total_ms: marks.length ? Math.round(marks[marks.length - 1].t) : 0, steps };
            }
        };
    };

    const benchmark = create_benchmark();

    const create_error = (code, message, meta = {}) =>
    {
        const e = new Error(message);
        e.code = code;
        e.meta = meta;
        return e;
    };

    const ensure = (cond, code, message, meta = {}) =>
    {
        if (!cond) throw create_error(code, message, meta);
        return cond;
    };

    const detect_network_speed = async () =>
    {
        const t0 = perf_now();
        try
        {
            const controller = new AbortController();
            const timeout_id = setTimeout(() => controller.abort(), 300);
            await fetch('data:text/plain,test', { cache: 'no-store', signal: controller.signal });
            clearTimeout(timeout_id);
            const elapsed = perf_now() - t0;
            if (elapsed < 20) return 'fast';
            if (elapsed < 60) return 'medium';
            return 'slow';
        }
        catch
        {
            return 'medium';
        }
    };

    const get_timeout = (base, speed) =>
    {
        const multipliers = { fast: 0.4, medium: 0.7, slow: 1.0 };
        return Math.round(base * (multipliers[speed] || 1));
    };

    const wait_for = async (fn, base_timeout = 1000) =>
    {
        const timeout = get_timeout(base_timeout, network_speed);
        const t0 = perf_now();
        while (perf_now() - t0 < timeout)
        {
            const v = fn();
            if (v) return v;
            await raf();
        }
        return null;
    };

    const retry = async (fn, max_attempts = 3) =>
    {
        let last_error;
        for (let i = 0; i < max_attempts; i++)
        {
            try
            {
                return await fn();
            }
            catch (e)
            {
                last_error = e;
                const no_retry_codes = ["ERROR_MODAL", "DUKCAPIL_ERROR", "NOT_FOUND", "DATE_FORMAT", "DATE_MONTH", "DATE_DAY", "DOM_OPTION"];
                if (no_retry_codes.includes(e.code)) throw e;
                if (i < max_attempts - 1)
                {
                    log('warn', `Retry ${i + 1}/${max_attempts}`, e.code);
                    await wait_for(() => false, 50);
                }
            }
        }
        throw last_error;
    };

    const click_fast = (el) =>
    {
        if (!el) return false;
        el.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, pointerType: "mouse" }));
        el.dispatchEvent(new MouseEvent("mousedown", { bubbles: true, cancelable: true, view: window }));
        el.dispatchEvent(new MouseEvent("mouseup", { bubbles: true, cancelable: true, view: window }));
        el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, view: window }));
        return true;
    };

    const set_value_fast = (el, value) =>
    {
        if (!el) return false;
        el.focus();
        el.value = value;
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
        el.blur();
        return true;
    };

    const verify_value = async (el, expected) =>
    {
        for (let i = 0; i < 5; i++)
        {
            if (el.value === expected) return true;
            await raf();
        }
        return false;
    };

    const safe_step = async (name, fn, mandatory = true) =>
    {
        const t0 = perf_now();
        log('info', `Step: ${name}`);
        try
        {
            const out = await fn();
            const ms = Math.round(perf_now() - t0);
            benchmark.mark(name);
            log('success', `${name} OK`, `${ms}ms`);
            return { ok: true, name, ms, out, mandatory };
        }
        catch (e)
        {
            const ms = Math.round(perf_now() - t0);
            benchmark.mark(name);
            log('error', `${name} FAIL`, `${e.code || 'ERR'}: ${e.message}`);
            return { ok: false, name, ms, error: { code: e.code || "ERR", message: e.message || String(e), meta: e.meta || {} }, mandatory };
        }
    };

    const close_all_modals = async () =>
    {
        const all_modals = [...document.querySelectorAll("div.shadow-gmail, div.rounded-lg, [role='dialog']")].filter(is_visible);
        for (const modal of all_modals)
        {
            const close_btn = modal.querySelector('button svg path[d*="17.59 19"]')?.closest('button');
            if (close_btn)
            {
                click_fast(close_btn);
                await wait_for(() => false, 80);
            }
        }
    };

    const handle_error_modal = async (timeout = 400) =>
    {
        const error_patterns = [
            { keywords: ["terjadi kesalahan", "dukcapil"], type: "dukcapil", close_all: true },
            { keywords: ["terjadi kesalahan", "pembaharuan data identitas"], type: "dukcapil", close_all: true },
            { keywords: ["terjadi kesalahan", "permintaan anda tidak dapat kami penuhi"], type: "general", close_all: true },
            { keywords: ["terjadi kesalahan"], type: "generic", close_all: true }
        ];

        const error_result = await wait_for(() =>
        {
            const modals = [...document.querySelectorAll("div.shadow-gmail, div.rounded-lg")].filter(is_visible);
            for (const modal of modals)
            {
                const txt = norm(modal.textContent);
                for (const pattern of error_patterns)
                {
                    if (pattern.keywords.every(k => txt.includes(k)))
                    {
                        return { modal, pattern };
                    }
                }
            }
            return null;
        }, timeout);

        if (!error_result) return { found: false };

        log('error', `Error detected: ${error_result.pattern.type}`);

        const ok_btns = [...error_result.modal.querySelectorAll("button")].filter(is_visible).filter((b) => norm(b.textContent).includes("ok"));
        for (const btn of ok_btns)
        {
            click_fast(btn);
            await wait_for(() => false, 80);
        }

        await wait_for(() => false, 200);
        const close_btn = error_result.modal.querySelector('button svg path[d*="17.59 19"]')?.closest('button');
        if (close_btn)
        {
            click_fast(close_btn);
            await wait_for(() => false, 150);
        }

        if (error_result.pattern.close_all)
        {
            await wait_for(() => false, 150);
            await close_all_modals();
            await wait_for(() => false, 100);
        }

        return { found: true, type: error_result.pattern.type };
    };

    const parse_date_yyyy_mm_dd = (s) =>
    {
        const m = String(s || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) throw create_error("DATE_FORMAT", 'Date must be "YYYY-MM-DD"', { input: s });
        const year = +m[1], month = +m[2], day = +m[3];
        if (!(month >= 1 && month <= 12)) throw create_error("DATE_MONTH", "Month must be 01-12", { input: s });
        const days_in_month = [31, (year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if (!(day >= 1 && day <= days_in_month[month - 1])) throw create_error("DATE_DAY", `Day must be 01-${days_in_month[month - 1]} for month ${month}`, { input: s });
        return { year, month, day };
    };

    const month_map = {
        1: ["Januari", "Jan"], 2: ["Februari", "Feb"], 3: ["Maret", "Mar"], 4: ["April", "Apr"],
        5: ["Mei", "Mei"], 6: ["Juni", "Jun"], 7: ["Juli", "Jul"], 8: ["Agustus", "Agu"],
        9: ["September", "Sep"], 10: ["Oktober", "Okt"], 11: ["November", "Nov"], 12: ["Desember", "Des"]
    };

    const get_month_tokens = (month) => [...new Set((month_map[month] || []).map((x) => norm(x)))];

    const find_button_by_text = (root, text) =>
    {
        const t = norm(text);
        const btns = [...root.querySelectorAll("button")].filter(is_visible);
        return btns.find((b) => norm(b.textContent) === t) || btns.find((b) => norm(b.textContent).includes(t)) || null;
    };

    const find_modal_by_keywords = (keywords) =>
    {
        const keys = keywords.map(norm);
        const modals = [...document.querySelectorAll("div.shadow-gmail, div.rounded-lg, [role='dialog']")].filter(is_visible);
        return modals.find((m) => { const tx = norm(m.textContent); return keys.every((k) => tx.includes(k)); }) || null;
    };

    const fill_input_field = async (selectors, value, label) =>
    {
        const input = selectors.map(s => document.querySelector(s)).find(el => el && is_visible(el));
        ensure(input, `${label}_NOT_FOUND`, `${label} input not found`);
        set_value_fast(input, value);
        await verify_value(input, value);
        return true;
    };

    const select_dropdown = async (trigger_text, menu_validator, option_value, label) =>
    {
        return await retry(async () =>
        {
            const trigger = await wait_for(() =>
            {
                const cands = [...document.querySelectorAll("div.cursor-pointer")].filter(is_visible);
                return cands.find((d) =>
                {
                    const span = d.querySelector("span");
                    return span && norm(span.textContent) === norm(trigger_text);
                }) || null;
            }, 600);
            ensure(trigger, `${label}_TRIGGER`, `${label} trigger not found`);
            click_fast(trigger);

            const menu = await wait_for(() =>
            {
                const divs = [...document.querySelectorAll("div")].filter(is_visible);
                return divs.find(menu_validator) || null;
            }, 600);
            ensure(menu, `${label}_MENU`, `${label} menu did not appear`);

            const target = norm(option_value);
            const option = [...menu.querySelectorAll("div.py-2.px-4.cursor-pointer")].find((x) => norm(x.textContent) === target);
            ensure(option, `${label}_OPTION`, `${label} option "${option_value}" not found`);
            click_fast(option);
            return true;
        }, 3);
    };

    const select_gender = async (value) =>
    {
        return await select_dropdown(
            "pilih jenis kelamin",
            (d) =>
            {
                const items = [...d.querySelectorAll("div.py-2.px-4.cursor-pointer")];
                if (items.length < 2) return false;
                const t = items.map((x) => norm(x.textContent));
                return t.includes("laki-laki") && t.includes("perempuan");
            },
            value,
            "GENDER"
        );
    };

    const select_birth_date = async (yyyy_mm_dd) =>
    {
        return await retry(async () =>
        {
            const { year, month, day } = parse_date_yyyy_mm_dd(yyyy_mm_dd);

            const wrapper = await wait_for(() =>
            {
                const w = [...document.querySelectorAll(".mx-input-wrapper")].find((x) => norm(x.innerText).includes("pilih tanggal lahir"));
                return is_visible(w) ? w : null;
            }, 600);
            ensure(wrapper, "DOB_WRAPPER", "Birth date picker wrapper not found");
            click_fast(wrapper);

            const calendar = await wait_for(() => [...document.querySelectorAll(".mx-calendar")].filter(is_visible).pop(), 600);
            ensure(calendar, "DOB_CALENDAR", "Birth date calendar panel not visible");

            const year_button = calendar.querySelector("button.mx-btn-current-year");
            ensure(year_button, "DOB_YEAR_BTN", "Year button not found");
            year_button.click();

            const year_panel = await wait_for(() => [...document.querySelectorAll(".mx-calendar-panel-year")].filter(is_visible).pop(), 600);
            ensure(year_panel, "DOB_YEAR_PANEL", "Year panel not visible");

            const prev_btn = year_panel.querySelector("button .mx-icon-double-left")?.closest("button");
            const next_btn = year_panel.querySelector("button .mx-icon-double-right")?.closest("button");
            ensure(prev_btn && next_btn, "DOB_YEAR_NAV", "Year navigation buttons not found");

            let picked_year = false;
            let attempts = 0;

            while (!picked_year && attempts < 150)
            {
                const table = year_panel.querySelector("table.mx-table-year");
                const cells = table ? [...table.querySelectorAll("td.cell[data-year]")] : [];
                const target = cells.find((c) => +c.getAttribute("data-year") === year);

                if (target) { target.click(); picked_year = true; break; }

                const years = cells.map((c) => +c.getAttribute("data-year")).filter(Number.isFinite);
                if (!years.length) break;

                const min_y = Math.min(...years);
                const max_y = Math.max(...years);

                if (year < min_y) prev_btn.click();
                else if (year > max_y) next_btn.click();
                else break;

                await raf();
                attempts++;
            }

            ensure(picked_year, "DOB_YEAR_PICK", `Failed to select year ${year}`);

            const month_panel = await wait_for(() => [...document.querySelectorAll(".mx-calendar-panel-month")].filter(is_visible).pop(), 600);
            ensure(month_panel, "DOB_MONTH_PANEL", "Month panel not visible");

            const tokens = get_month_tokens(month);
            const month_cell = [...month_panel.querySelectorAll("td.cell")].find((c) => tokens.includes(norm(c.textContent)));
            ensure(month_cell, "DOB_MONTH_PICK", `Month ${String(month).padStart(2, "0")} not found`);
            month_cell.click();

            const date_panel = await wait_for(() => [...document.querySelectorAll(".mx-calendar-panel-date")].filter(is_visible).pop(), 600);
            ensure(date_panel, "DOB_DATE_PANEL", "Date grid panel not visible");

            const title = `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
            const date_cell = date_panel.querySelector(`td.cell[title="${title}"]`);
            ensure(date_cell, "DOB_DATE_CELL", `Date "${title}" not present in grid`);
            ensure(!date_cell.classList.contains("disabled"), "DOB_DATE_DISABLED", `Date "${title}" is disabled`);
            click_fast(date_cell.querySelector("div") || date_cell);

            return title;
        }, 3);
    };

    const click_daftar_baru_button = async () =>
    {
        return await retry(async () =>
        {
            const button = await wait_for(() =>
            {
                const btns = [...document.querySelectorAll('button')].filter(is_visible);
                return btns.find(b =>
                {
                    const parent = b.closest('[data-v-4bb6178a]');
                    if (!parent) return false;
                    const txt = norm(b.textContent);
                    return txt.includes('daftar baru');
                }) || null;
            }, 800);

            ensure(button, "DAFTAR_BARU_BTN", "Daftar Baru button not found");
            click_fast(button);
            await wait_for(() => false, 300);
            return true;
        }, 3);
    };

    const fill_basic_fields = async () =>
    {
        return await retry(async () =>
        {
            await fill_input_field(['input#nik[name="NIK"]', 'input[name="NIK"]', 'input#nik'], data.nik, "NIK");
            await fill_input_field(['input[name="Nama"]', 'input[placeholder*="nama lengkap" i]', 'input#nama'], data.nama, "NAMA");
            await fill_input_field(['input[name="Nomor Whatsapp"]', 'input[placeholder*="nomor whatsapp" i]', 'input[type="tel"]'], data.nomor_whatsapp, "WHATSAPP");
            return true;
        }, 3);
    };

    const select_job = async (value) =>
    {
        return await retry(async () =>
        {
            const trigger = await wait_for(() =>
            {
                const cands = [...document.querySelectorAll("div.cursor-pointer")].filter(is_visible);
                return cands.find((d) => norm(d.textContent) === "pilih pekerjaan") || null;
            }, 600);
            ensure(trigger, "JOB_TRIGGER", 'Job trigger not found');
            click_fast(trigger);

            const modal = await wait_for(() =>
            {
                const title = [...document.querySelectorAll("div,header")].find((x) => (x.textContent || "").includes("Pilih Pekerjaan"));
                return title ? title.closest(".modal-content") || title.closest("div.shadow-gmail") || title.closest("div") : null;
            }, 600);
            ensure(modal, "JOB_MODAL", "Job modal did not appear");

            const search = modal.querySelector('input[placeholder="Cari pekerjaan"]') || modal.querySelector('input[type="search"]');
            if (search) { set_value_fast(search, value); await raf(); }

            const btn = await wait_for(() => find_button_by_text(modal, value), 600);
            ensure(btn, "JOB_OPTION", `Job option "${value}" not found`);
            click_fast(btn);
            return true;
        }, 3);
    };

    const find_scroll_container = (root) =>
    {
        if (!root) return null;
        const cands = [root, ...root.querySelectorAll("*")].filter(is_visible);
        return cands.find((el) => el.scrollHeight > el.clientHeight + 5) || root;
    };

    const build_button_index = (root) =>
    {
        const map = new Map();
        const btns = [...root.querySelectorAll("button")].filter(is_visible);
        for (const b of btns) { const t = norm(b.textContent); if (t && !map.has(t)) map.set(t, b); }
        return map;
    };

    const jump_to_letter = (root, letter) =>
    {
        const l = norm(letter);
        if (!l) return;
        const divs = [...root.querySelectorAll("div")].filter(is_visible);
        const header = divs.find((x) => norm(x.textContent) === l);
        if (header) header.scrollIntoView({ block: "center" });
    };

    const find_button_fast = (root, text) =>
    {
        const t = norm(text);
        const idx = build_button_index(root);
        if (idx.has(t)) return idx.get(t);
        for (const [k, v] of idx.entries()) if (k.includes(t)) return v;
        return null;
    };

    const find_button_with_scroll = async (root, text) =>
    {
        let btn = find_button_fast(root, text);
        if (btn) return btn;

        const scroller = find_scroll_container(root);
        if (!scroller) return null;

        const t = norm(text);
        jump_to_letter(root, t[0] || "");

        let last = -1;
        const step = Math.max(350, Math.floor(scroller.clientHeight * 1.1));

        for (let i = 0; i < 35; i++)
        {
            btn = find_button_fast(root, text);
            if (btn) return btn;
            scroller.scrollTop += step;
            await raf();
            if (scroller.scrollTop === last) break;
            last = scroller.scrollTop;
        }
        return null;
    };

    const open_domisili_panel = async () =>
    {
        return await retry(async () =>
        {
            const existing_panel = [...document.querySelectorAll('[data-v-5240acbe]')].filter(is_visible).find((el) => norm(el.textContent).includes("daftar provinsi"));
            if (existing_panel)
            {
                log('warn', 'Domisili panel already open, closing...');
                const close_btn = existing_panel.closest('div')?.querySelector('button svg')?.closest('button');
                if (close_btn)
                {
                    click_fast(close_btn);
                    await wait_for(() => false, 300);
                }
            }

            const trigger = await wait_for(() =>
            {
                const cands = [...document.querySelectorAll("div.cursor-pointer")].filter(is_visible);
                return cands.find((d) => norm(d.textContent) === "pilih alamat domisili") || null;
            }, 600);
            ensure(trigger, "DOM_TRIGGER", 'Domisili trigger not found');

            trigger.scrollIntoView({ block: "center", behavior: "smooth" });
            await wait_for(() => false, 200);

            click_fast(trigger);

            const scope = await wait_for(() =>
            {
                const title = [...document.querySelectorAll('[data-v-5240acbe]')].filter(is_visible).find((el) => norm(el.textContent) === "daftar provinsi");
                if (!title) return null;
                const root = title.parentElement;
                return root?.parentElement || root || null;
            }, 1000);
            ensure(scope, "DOM_PANEL", "Domisili panel not visible");
            return scope;
        }, 3);
    };


    const find_section_root = (scope, title) =>
    {
        const t = norm(title);
        let el = [...scope.querySelectorAll('[data-v-5240acbe]')].filter(is_visible).find((x) => norm(x.textContent) === t);
        if (el) return el.parentElement;
        el = [...scope.querySelectorAll('div, header, span, p')].filter(is_visible).find((x) => 
        {
            const own_text = Array.from(x.childNodes).filter(n => n.nodeType === Node.TEXT_NODE).map(n => n.textContent).join('');
            return norm(own_text) === t;
        });
        if (el) return el.parentElement || el;
        const all_text_elements = [...scope.querySelectorAll('*')].filter(is_visible);
        el = all_text_elements.find((x) =>
        {
            const txt = norm(x.textContent);
            const words = t.split(' ');
            return words.every(w => txt.includes(w));
        });
        return el ? (el.parentElement || el) : null;
    };

    const select_domisili_step = async (scope, title, value) =>
    {
        const root = await wait_for(() => find_section_root(scope, title), 700);
        ensure(root, "DOM_SECTION", `Domisili section "${title}" not found`);
        const btn = await find_button_with_scroll(root, value);
        ensure(btn, "DOM_OPTION", `Option "${value}" not found in "${title}"`);
        btn.scrollIntoView({ block: "center" });
        click_fast(btn);
        await wait_for(() => false, 500);
        return true;
    };

    const select_domisili = async (dom) =>
    {
        let last_error;
        for (let main_attempt = 1; main_attempt <= 3; main_attempt++)
        {
            try
            {
                log('info', `Domisili attempt ${main_attempt}/3`);

                if (main_attempt > 1)
                {
                    log('info', 'Resetting form state...');
                    window.scrollTo({ top: 0, behavior: "smooth" });
                    await wait_for(() => false, 500);

                    const form_check = document.querySelector('input[placeholder*="Nama Lengkap"]') ||
                        document.querySelector('input[placeholder*="NIK"]');
                    ensure(form_check, "FORM_LOST", "Registration form not found");
                }

                const scope = await open_domisili_panel();

                await retry(async () =>
                {
                    await select_domisili_step(scope, "Daftar Provinsi", dom.provinsi);
                    return true;
                }, 3);

                await retry(async () =>
                {
                    await select_domisili_step(scope, "Daftar Kabupaten/Kota", dom.kabupaten_kota);
                    return true;
                }, 3);

                await wait_for(() => false, 200);

                await retry(async () =>
                {
                    await select_domisili_step(scope, "Daftar Kecamatan", dom.kecamatan);
                    return true;
                }, 3);

                await wait_for(() => false, 300);

                await retry(async () =>
                {
                    await select_domisili_step(scope, "Daftar Kelurahan", dom.kelurahan);
                    return true;
                }, 3);

                log('success', 'Domisili complete!');
                return true;
            }
            catch (e)
            {
                last_error = e;
                log('error', `Domisili attempt ${main_attempt} failed: ${e.code}`);

                const all_modals = document.querySelectorAll('[role="dialog"], .modal, [data-v-5240acbe]');
                all_modals.forEach(modal =>
                {
                    if (is_visible(modal))
                    {
                        const close_btns = modal.querySelectorAll('button');
                        close_btns.forEach(btn =>
                        {
                            const svg = btn.querySelector('svg');
                            if (svg && is_visible(btn)) click_fast(btn);
                        });
                    }
                });

                await wait_for(() => false, 400);

                if (main_attempt < 3)
                {
                    log('info', 'Waiting before retry...');
                    await wait_for(() => false, 300);
                }
            }
        }
        throw last_error;
    };


    const fill_detailed_address = async () =>
    {
        return await retry(async () =>
        {
            await fill_input_field(['textarea#detail-domisili[name="detail-domisili"]', 'textarea[name="detail-domisili"]', 'textarea[placeholder*="jl." i]', 'textarea[placeholder*="alamat" i]'], data.detail_domisili, "DETAIL_ADDRESS");
            return true;
        }, 3);
    };

    const select_exam_date_today = async () =>
    {
        return await retry(async () =>
        {
            const calendar = await wait_for(() => [...document.querySelectorAll('[data-v-5587335f]')].filter(is_visible).find((el) => el.classList.contains("shadow-gmail")) || null, 600);
            ensure(calendar, "EXAM_CALENDAR", "Exam date calendar not found/visible");

            const today = new Date();
            const day = today.getDate();

            const grids = [...calendar.querySelectorAll(".grid.grid-cols-7.gap-1")].filter(is_visible);
            const grid = grids.length >= 2 ? grids[1] : calendar.querySelector(".grid.grid-cols-7.gap-1.mt-2") || null;
            ensure(grid, "EXAM_GRID", "Exam date grid not found");

            const btn = [...grid.querySelectorAll('button[type="button"]')].filter(is_visible).find((b) =>
            {
                if (b.disabled) return false;
                const span = b.querySelector("span.font-bold");
                return span && +span.textContent.trim() === day;
            });

            ensure(btn, "EXAM_TODAY_UNAVAILABLE", `Today's date (${day}) is not selectable`);
            click_fast(btn);
            return day;
        }, 3);
    };

    const click_next_submit = async () =>
    {
        return await retry(async () =>
        {
            const submit = await wait_for(() =>
            {
                const btns = [...document.querySelectorAll('button[type="submit"]')].filter(is_visible);
                return btns.find((b) => norm(b.textContent).includes("selanjutnya")) || null;
            }, 600);
            ensure(submit, "NEXT_SUBMIT", 'Submit button not found');
            click_fast(submit);
            return true;
        }, 3);
    };

    const wait_registration_modal = async () =>
    {
        return await retry(async () =>
        {
            const modal = await wait_for(() => find_modal_by_keywords(["formulir pendaftaran", "list data individu"]), 1200);
            ensure(modal, "FORM_MODAL", "Formulir Pendaftaran modal not found");
            benchmark.mark("registration_modal_visible");

            log('info', 'Waiting for table data to load...');
            await wait_for(() =>
            {
                const loading = modal.querySelectorAll('.td-loading, .shimmer');
                return loading.length === 0;
            }, 3000);

            await wait_for(() => false, 300);

            return modal;
        }, 3);
    };

    const click_pick_row = async (modal) =>
    {
        return await retry(async () =>
        {
            const error_modal = [...modal.querySelectorAll('div')].find(d =>
            {
                const txt = norm(d.textContent);
                return txt.includes('sudah terdaftar') ||
                    txt.includes('data tidak ditemukan') ||
                    txt.includes('gagal') ||
                    txt.includes('error');
            });

            if (error_modal && is_visible(error_modal))
            {
                const error_text = norm(error_modal.textContent).substring(0, 100);
                log('error', `MODAL ERROR: ${error_text}`);
                throw new Err("ERROR_MODAL", `Registration error: ${error_text}`);
            }

            const all_rows = [...modal.querySelectorAll("tbody tr")].filter(is_visible);
            const rows = all_rows.filter(r => !r.querySelector('.td-loading, .shimmer'));

            log('info', `Found ${rows.length} loaded rows (${all_rows.length} total)`);

            if (rows.length === 0)
            {
                throw new Err("NO_ROWS", "No loaded rows, still loading data");
            }

            const row = rows[0];
            let btn = [...row.querySelectorAll("button")].filter(is_visible).find((b) => norm(b.textContent) === "pilih");

            if (!btn)
            {
                log('info', 'No button in row, trying modal level...');
                const modal_btns = [...modal.querySelectorAll("button")].filter(is_visible);
                btn = modal_btns.find((b) => norm(b.textContent) === "pilih");
            }

            ensure(btn, "ROW_PICK_BTN", 'Row button "Pilih" not found');
            click_fast(btn);
            return true;
        }, 3);
    };

    const click_register_with_nik = async (modal) =>
    {
        return await retry(async () =>
        {
            const error_modal = [...modal.querySelectorAll('div')].find(d =>
            {
                const txt = norm(d.textContent);
                return txt.includes('sudah terdaftar') ||
                    txt.includes('tidak sesuai') ||
                    txt.includes('gagal') ||
                    txt.includes('error');
            });

            if (error_modal && is_visible(error_modal))
            {
                const error_text = norm(error_modal.textContent).substring(0, 100);
                log('error', `MODAL ERROR: ${error_text}`);
                throw new Err("ERROR_MODAL", `Registration error: ${error_text}`);
            }

            const btn = await wait_for(() =>
            {
                const cands = [...modal.querySelectorAll("button")].filter(is_visible);
                const with_nik = cands.find((b) => norm(b.textContent).includes("daftar dengan nik"));
                if (with_nik) return with_nik;

                const without_nik = cands.find((b) => norm(b.textContent).includes("daftarkan tanpa nik"));
                if (without_nik) return without_nik;

                return cands.find((b) =>
                {
                    const txt = norm(b.textContent);
                    return txt.includes("daftar") && txt.includes("nik");
                }) || null;
            }, 500);

            ensure(btn, "REG_WITH_NIK_BTN", "Register button not found");
            log('info', `Clicking: "${norm(btn.textContent)}"`);
            click_fast(btn);
            return true;
        }, 3);
    };

    const handle_wali_form_after_register = async () =>
    {
        log('info', 'Checking wali form');

        const modal = await wait_for(() =>
        {
            const modals = [...document.querySelectorAll("div.shadow-gmail")].filter(is_visible);
            for (const m of modals)
            {
                const nik_wali_input = m.querySelector('input[placeholder*="NIK Wali" i]');
                if (nik_wali_input) return m;
            }
            return null;
        }, 250);

        if (!modal)
        {
            log('info', 'No wali form');
            return { skipped: true, type: 'no_form' };
        }

        log('info', 'Wali form detected');

        const no_wali_checkbox = modal.querySelector('input#noWali[name="noWali"]');

        if (no_wali_checkbox)
        {
            log('info', 'Skip wali checkbox');

            const check_div = [...modal.querySelectorAll('#noWali')].find(el => el.classList.contains('check'));
            if (check_div) click_fast(check_div);
            else no_wali_checkbox.click();
            await raf();

            const daftar_btn = await wait_for(() =>
            {
                const btns = [...modal.querySelectorAll("button")].filter(is_visible);
                return btns.find((b) => norm(b.textContent) === "daftar" && !b.classList.contains("bg-disabled")) || null;
            }, 500);
            ensure(daftar_btn, "DAFTAR_BTN", 'Button "Daftar" not enabled');

            click_fast(daftar_btn);

            await wait_for(() => false, 600);
            const err_result = await handle_error_modal(600);
            if (err_result.found) throw create_error("ERROR_MODAL", `Wali registration error: ${err_result.type}`);

            return { skipped: false, type: 'no_wali_checkbox', filled: false };
        }

        log('info', 'Filling wali form');

        const nik_wali = modal.querySelector('input[placeholder*="NIK Wali" i]');
        const nama_wali = modal.querySelector('input[placeholder*="Nama Lengkap" i]');

        ensure(nik_wali, "NIK_WALI_NOT_FOUND", "NIK Wali input not found");
        ensure(nama_wali, "NAMA_WALI_NOT_FOUND", "Nama Wali input not found");

        set_value_fast(nik_wali, data_wali.nik);
        await verify_value(nik_wali, data_wali.nik);

        set_value_fast(nama_wali, data_wali.nama);
        await verify_value(nama_wali, data_wali.nama);

        await select_birth_date(data_wali.tanggal_lahir);
        await select_gender(data_wali.jenis_kelamin);

        const checkbox = modal.querySelector('input[name="Nomor sama dengan peserta"]');
        if (checkbox && !checkbox.checked)
        {
            const check_div = [...modal.querySelectorAll('#phone-sama')].find(el => el.classList.contains('check'));
            if (check_div) click_fast(check_div);
            else checkbox.click();
            await raf();
        }

        const daftar_btn = await wait_for(() =>
        {
            const btns = [...modal.querySelectorAll("button")].filter(is_visible);
            return btns.find((b) => norm(b.textContent) === "daftar" && !b.classList.contains("bg-disabled")) || null;
        }, 500);
        ensure(daftar_btn, "DAFTAR_BTN", 'Button "Daftar" not enabled');

        click_fast(daftar_btn);

        await wait_for(() => false, 600);
        const err_result = await handle_error_modal(600);
        if (err_result.found) throw create_error("ERROR_MODAL", `Wali registration error: ${err_result.type}`);

        return { skipped: false, type: 'full_wali_form', filled: true };
    };

    const wait_result_modal = async () =>
    {
        log('info', 'Waiting result modal');

        await wait_for(() => false, 500);
        const err_check = await handle_error_modal(500);
        if (err_check.found)
        {
            log('error', `Error modal found: ${err_check.type}`);
            throw create_error("ERROR_MODAL", "Error modal detected before result modal");
        }

        let modal = await wait_for(() => find_modal_by_keywords(["berhasil", "daftar"]), 500);
        if (!modal) modal = await wait_for(() => find_modal_by_keywords(["individu", "terdaftar"]), 500);
        if (!modal) modal = await wait_for(() => find_modal_by_keywords(["berhasil"]), 500);

        if (!modal)
        {
            modal = await wait_for(() =>
            {
                const modals = [...document.querySelectorAll("div.shadow-gmail, div.rounded-lg")].filter(is_visible);
                return modals.find((m) =>
                {
                    const has_tutup = find_button_by_text(m, "Tutup");
                    const txt = norm(m.textContent);
                    return has_tutup && (txt.includes("daftar") || txt.includes("berhasil") || txt.includes("terdaftar"));
                }) || null;
            }, 800);
        }

        if (!modal)
        {
            const final_err = await handle_error_modal(400);
            if (final_err.found)
            {
                throw create_error("ERROR_MODAL", "Error modal detected instead of result");
            }
        }

        ensure(modal, "RESULT_MODAL", "Result modal not found");
        log('success', 'Result modal found!');
        return modal;
    };

    const close_result_modal = async (modal) =>
    {
        const text = norm(modal.textContent);
        const status = text.includes("individu sudah terdaftar") ? "already_registered" : text.includes("berhasil daftar") ? "registered" : "unknown";

        log('info', `Registration status: ${status}`);

        const close_button = find_button_by_text(modal, "Tutup");
        ensure(close_button, "RESULT_CLOSE", 'Close button not found');
        click_fast(close_button);
        // await wait_for(() => false, 250);

        return { status };
    };

    const ensure_attendance_checkbox_checked = async (attendance_modal) =>
    {
        const checkbox = attendance_modal.querySelector('input[type="checkbox"]#verify') || attendance_modal.querySelector('input[type="checkbox"][name="verify"]') || attendance_modal.querySelector('input[type="checkbox"]');
        if (!checkbox) return false;

        const check_div = checkbox.parentElement?.querySelector(".check") || checkbox.closest(".flex.gap-2.relative.items-center")?.querySelector(".check") || attendance_modal.querySelector(".check");
        if (!check_div) return false;

        if (!checkbox.checked) { click_fast(check_div); await raf(); }
        if (!checkbox.checked) { checkbox.click(); await raf(); }

        return checkbox.checked === true;
    };

    const handle_attendance_modal_flow = async () =>
    {
        const attendance_modal = await wait_for(() => find_modal_by_keywords(["tandai hadir?"]), 800);
        if (!attendance_modal) return { ok: false, reason: "modal_not_found" };

        const checked = await ensure_attendance_checkbox_checked(attendance_modal);
        if (!checked) return { ok: false, reason: "checkbox_failed" };

        const hadir_button = await wait_for(() => find_button_by_text(attendance_modal, "Hadir"), 600);
        if (!hadir_button) return { ok: false, reason: "button_not_enabled" };

        click_fast(hadir_button);

        const success_modal = await wait_for(() => find_modal_by_keywords(["berhasil hadir"]), 1000);
        if (success_modal)
        {
            const close_button = find_button_by_text(success_modal, "Tutup");
            if (close_button) click_fast(close_button);
            await wait_for(() => false, 120);
            return { ok: true, status: "hadir_success" };
        }

        return { ok: true, status: "hadir_clicked" };
    };

    const confirm_attendance_by_nik = async () =>
    {
        const table = document.querySelector('.table-individu-terdaftar');
        if (!table)
        {
            log('info', 'Not on list page');
            return { skipped: true, reason: "not_on_list_page" };
        }

        return await retry(async () =>
        {
            log('info', `SEARCH BY NIK: ${data.nik}`);

            const dropdown = await wait_for(() =>
            {
                const dropdowns = [...document.querySelectorAll('[data-v-7670251f]')].filter(is_visible);
                return dropdowns.find((d) =>
                {
                    const span = d.querySelector('span');
                    return span && (norm(span.textContent).includes("nomor tiket") || norm(span.textContent).includes("nik") || norm(span.textContent).includes("nama"));
                });
            }, 500);

            if (!dropdown)
            {
                log('warn', 'Search dropdown not found');
                throw create_error('DROPDOWN_NOT_FOUND', 'Search dropdown not found');
            }

            log('info', 'Opening dropdown');
            click_fast(dropdown);
            await wait_for(() => false, 250);

            log('info', 'Looking for NIK option...');
            const nik_option = await wait_for(() =>
            {
                const menus = [...document.querySelectorAll('div')].filter(is_visible).filter(d => 
                {
                    const style = window.getComputedStyle(d);
                    return style.position === 'absolute' && parseInt(style.zIndex) >= 2000;
                });

                for (const menu of menus)
                {
                    const options = [...menu.querySelectorAll('div')].filter(is_visible).filter(opt => 
                    {
                        const txt = norm(opt.textContent);
                        return txt === 'nik' || txt === 'nomor tiket' || txt === 'nama';
                    });

                    const nik_opt = options.find(opt => norm(opt.textContent) === 'nik');
                    if (nik_opt) 
                    {
                        log('success', 'NIK option found!');
                        return nik_opt;
                    }
                }

                return null;
            }, 800);

            if (!nik_option)
            {
                log('warn', 'NIK option not found, using default...');
            }
            else
            {
                log('info', 'Selecting NIK');
                click_fast(nik_option);
                await wait_for(() => false, 200);
            }

            const search_input = await wait_for(() =>
            {
                const inputs = [...document.querySelectorAll('input#searchNik, input[placeholder*="nomor tiket" i]')].filter(is_visible);
                return inputs[0] || null;
            }, 500);

            if (!search_input)
            {
                log('warn', 'Search input not found');
                throw create_error('SEARCH_INPUT_NOT_FOUND', 'Search input not found');
            }

            log('info', `Typing NIK: ${data.nik}`);
            search_input.focus();
            search_input.value = '';
            await raf();

            set_value_fast(search_input, data.nik);
            await wait_for(() => false, 100);

            log('info', 'Pressing Enter...');
            search_input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, code: 'Enter', bubbles: true }));
            search_input.dispatchEvent(new KeyboardEvent('keypress', { key: 'Enter', keyCode: 13, code: 'Enter', bubbles: true }));
            search_input.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', keyCode: 13, code: 'Enter', bubbles: true }));

            await wait_for(() => false, 500);

            log('info', 'Looking for Konfirmasi button...');
            const konfirmasi_btn = await wait_for(() =>
            {
                const btns = [...document.querySelectorAll("button")].filter(is_visible);
                return btns.find((b) => norm(b.textContent).includes("konfirmasi hadir")) || null;
            }, 1000);

            if (!konfirmasi_btn)
            {
                log('warn', 'Konfirmasi button not found');

                const sudah_hadir = [...document.querySelectorAll('div, span, td')].filter(is_visible).find(el => norm(el.textContent).includes("sudah hadir"));
                if (sudah_hadir)
                {
                    log('info', 'Already attended');

                    const clear_btn = await wait_for(() =>
                    {
                        const btns = [...document.querySelectorAll('button.bg-error')].filter(is_visible);
                        return btns.find(b => b.querySelector('svg')) || null;
                    }, 250);

                    if (clear_btn)
                    {
                        log('info', 'Clearing search');
                        click_fast(clear_btn);
                        await wait_for(() => false, 150);
                    }

                    return { found: true, status: 'already_attended' };
                }

                const no_data = [...document.querySelectorAll('div, span, td')].filter(is_visible).find(el =>
                {
                    const txt = norm(el.textContent);
                    return txt.includes("tidak ditemukan") || txt.includes("tidak ada data") || txt.includes("no data");
                });
                if (no_data)
                {
                    log('warn', 'NIK not found in list');
                    throw create_error('NOT_IN_LIST', 'NIK not found in list');
                }

                throw create_error('KONFIRMASI_NOT_FOUND', 'Konfirmasi button not found');
            }

            const row = konfirmasi_btn.closest('tr');
            const cells = row ? row.querySelectorAll('td') : [];
            const nama = cells[2] ? cells[2].textContent.trim() : 'Unknown';

            log('success', `FOUND: ${nama}`);
            click_fast(konfirmasi_btn);

            const result = await handle_attendance_modal_flow();

            const clear_btn = await wait_for(() =>
            {
                const btns = [...document.querySelectorAll('button.bg-error')].filter(is_visible);
                return btns.find(b => b.querySelector('svg')) || null;
            }, 250);

            if (clear_btn)
            {
                log('info', 'Clearing search');
                click_fast(clear_btn);
                await wait_for(() => false, 150);
            }

            if (result.ok && result.status === 'hadir_success')
            {
                log('success', `Konfirmasi SUCCESS: ${nama}`);
                return { found: true, status: 'confirmed', nama };
            }
            else
            {
                log('warn', `Konfirmasi FAILED: ${nama}`);
                return { found: true, status: 'failed', nama, reason: result.reason };
            }
        }, 3);
    };

    const run = async () =>
    {
        log('info', 'Detecting network...');
        network_speed = await detect_network_speed();
        log('success', `Network: ${network_speed.toUpperCase()}`);
        benchmark.mark("start");
        const results = [];

        results.push(await safe_step("click_daftar_baru", click_daftar_baru_button, true));
        results.push(await safe_step("fill_basic_fields", fill_basic_fields, true));
        results.push(await safe_step("select_gender", () => select_gender(data.jenis_kelamin), true));
        results.push(await safe_step("select_birth_date", () => select_birth_date(data.tanggal_lahir), true));
        results.push(await safe_step("select_job", () => select_job(data.pekerjaan), true));
        results.push(await safe_step("select_domisili", () => select_domisili(data.domisili), true));
        results.push(await safe_step("fill_detailed_address", fill_detailed_address, true));
        results.push(await safe_step("select_exam_date_today", select_exam_date_today, true));

        const failed_mandatory = results.filter(r => r.mandatory && !r.ok);
        if (failed_mandatory.length > 0)
        {
            log('error', 'STOPPED: Mandatory step failed');
            const bench = benchmark.summary();
            const combined = results.map((r, i) => ({
                step: r.name,
                status: r.ok ? "✓" : "✗",
                step_ms: r.ms,
                total_ms: bench.steps[i] ? bench.steps[i].ms_from_start : 0,
                error: r.ok ? "" : r.error?.code
            }));
            console.log("\n=== EXECUTION REPORT ===");
            console.table(combined);
            console.log(`\nTOTAL: ${bench.total_ms}ms | NETWORK: ${network_speed.toUpperCase()} | STATUS: STOPPED ❌`);
            return { ok: false, stopped_reason: "mandatory_failed", results, benchmark: bench };
        }

        results.push(await safe_step("click_next_submit", click_next_submit, true));

        const reg_modal_step = await safe_step("wait_registration_modal", wait_registration_modal, true);
        results.push(reg_modal_step);
        if (!reg_modal_step.ok)
        {
            const bench = benchmark.summary();
            const combined = results.map((r, i) => ({
                step: r.name,
                status: r.ok ? "✓" : "✗",
                step_ms: r.ms,
                total_ms: bench.steps[i] ? bench.steps[i].ms_from_start : 0,
                error: r.ok ? "" : r.error?.code
            }));
            console.log("\n=== EXECUTION REPORT ===");
            console.table(combined);
            console.log(`\nTOTAL: ${bench.total_ms}ms | STOPPED ❌`);
            return { ok: false, results, benchmark: bench };
        }

        const reg_modal = reg_modal_step.out;

        results.push(await safe_step("click_pick_row", () => click_pick_row(reg_modal), true));
        results.push(await safe_step("click_register_with_nik", () => click_register_with_nik(reg_modal), true));

        const register_result = results[results.length - 1];
        if (!register_result.ok && register_result.error?.code === "ERROR_MODAL")
        {
            const bench = benchmark.summary();
            const combined = results.map((r, i) => ({
                step: r.name,
                status: r.ok ? "✓" : "✗",
                step_ms: r.ms,
                total_ms: bench.steps[i] ? bench.steps[i].ms_from_start : 0,
                error: r.ok ? "" : r.error?.code
            }));
            console.log("\n=== EXECUTION REPORT ===");
            console.table(combined);
            console.log(`\nTOTAL: ${bench.total_ms}ms | NETWORK: ${network_speed.toUpperCase()} | STATUS: DATA ERROR ❌`);
            return { ok: false, stopped_reason: "data_error", results, benchmark: bench };
        }

        const wali_result = await safe_step("handle_wali_form", handle_wali_form_after_register, false);
        results.push(wali_result);

        if (!wali_result.ok && wali_result.error?.code === "ERROR_MODAL")
        {
            const bench = benchmark.summary();
            const combined = results.map((r, i) => ({
                step: r.name,
                status: r.ok ? "✓" : "✗",
                step_ms: r.ms,
                total_ms: bench.steps[i] ? bench.steps[i].ms_from_start : 0,
                error: r.ok ? "" : r.error?.code
            }));
            console.log("\n=== EXECUTION REPORT ===");
            console.table(combined);
            console.log(`\nTOTAL: ${bench.total_ms}ms | NETWORK: ${network_speed.toUpperCase()} | STATUS: WALI ERROR ❌`);
            return { ok: false, stopped_reason: "wali_data_error", results, benchmark: bench };
        }

        const result_modal_step = await safe_step("wait_result_modal", wait_result_modal, true);
        results.push(result_modal_step);

        if (!result_modal_step.ok)
        {
            const bench = benchmark.summary();
            const combined = results.map((r, i) => ({
                step: r.name,
                status: r.ok ? "✓" : "✗",
                step_ms: r.ms,
                total_ms: bench.steps[i] ? bench.steps[i].ms_from_start : 0,
                error: r.ok ? "" : r.error?.code
            }));
            console.log("\n=== EXECUTION REPORT ===");
            console.table(combined);
            console.log(`\nTOTAL: ${bench.total_ms}ms | STOPPED ❌`);
            return { ok: false, results, benchmark: bench };
        }

        const result_modal = result_modal_step.out;

        const status_step = await safe_step("close_result_modal", () => close_result_modal(result_modal), true);
        results.push(status_step);

        if (!status_step.ok)
        {
            const bench = benchmark.summary();
            const combined = results.map((r, i) => ({
                step: r.name,
                status: r.ok ? "✓" : "✗",
                step_ms: r.ms,
                total_ms: bench.steps[i] ? bench.steps[i].ms_from_start : 0,
                error: r.ok ? "" : r.error?.code
            }));
            console.log("\n=== EXECUTION REPORT ===");
            console.table(combined);
            console.log(`\nTOTAL: ${bench.total_ms}ms | STOPPED ❌`);
            return { ok: false, results, benchmark: bench };
        }

        const registration_status = status_step.out.status;
        const final_status = { network: network_speed, registration: registration_status, wali: wali_result.out?.type || 'unknown' };

        if (registration_status === "registered")
        {
            const attendance_step = await safe_step("nik_search_attendance", confirm_attendance_by_nik, false);
            results.push(attendance_step);
            final_status.attendance = attendance_step.out || {};
        }
        else
        {
            final_status.attendance = "skipped";
        }

        const bench = benchmark.summary();
        const combined = results.map((r, i) => ({
            step: r.name,
            status: r.ok ? "✓" : "✗",
            step_ms: r.ms,
            total_ms: bench.steps[i] ? bench.steps[i].ms_from_start : 0
        }));

        console.log("\n=== EXECUTION REPORT ===");
        console.table(combined);
        console.log(`\nTOTAL: ${bench.total_ms}ms | NETWORK: ${network_speed.toUpperCase()} | STATUS: SUCCESS ✅`);
        console.log("\n=== SUMMARY ===");
        console.table([final_status]);

        return { ok: true, results, benchmark: bench, final_status };
    };

    try
    {
        await run();
    }
    catch (e)
    {
        log('error', 'FATAL', `${e.code || 'ERR'}: ${e.message}`);
    }

})();
