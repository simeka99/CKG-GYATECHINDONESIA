(async () =>
{
    // ===== CONFIG =====
    const batch_config = {
        debug: true,
        pause_between_users_ms: 650,
        stop_on_error: false,
        start_index: 0,
        data_wali: {
            nik: "3206017011990001",
            nama: "Gita Amalia Puspita",
            tanggal_lahir: "1999-11-30",
            jenis_kelamin: "Perempuan"
        },
        data_list: [
            {
                nik: "3206036911000001",
                nama: "MIRNA MULYAWATI",
                nomor_whatsapp: "81324772772",
                jenis_kelamin: "Perempuan",
                tanggal_lahir: "2000-11-29",
                pekerjaan: "Ibu Rumah Tangga",
                domisili: { provinsi: "Jawa Barat", kabupaten_kota: "Kab. Tasikmalaya", kecamatan: "Cikalong", kelurahan: "CIDADALI" },
                detail_domisili: "Cimajaya, RT 002, RW 003"
            }
        ]
    };

    // ===== UTIL =====
    const util = (() =>
    {
        const now_ms = () => performance.now();
        const raf = () => new Promise((r) => requestAnimationFrame(() => r()));
        const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
        const is_visible = (el) => !!(el && (el.offsetParent || el.getClientRects().length));

        const pad2 = (n) => String(n).padStart(2, "0");
        const time_hms = (d = new Date()) =>
            `${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`;

        const format_duration = (ms) =>
        {
            const total_s = Math.max(0, Math.round(ms / 1000));
            const h = Math.floor(total_s / 3600);
            const m = Math.floor((total_s % 3600) / 60);
            const s = total_s % 60;

            const parts = [
                { ok: h > 0, text: `${h}h` },
                { ok: h > 0 || m > 0, text: `${m}m` },
                { ok: true, text: `${s}s` }
            ].filter(x => x.ok).map(x => x.text);

            return parts.join(" ");
        };

        const format_ms = (ms) => `${Math.round(ms)}ms`;
        const log = (level, message, data) =>
        {
            if (!batch_config.debug) return;
            const prefix = { info: "INFO", warn: "WARN", error: "ERROR", success: "SUCCESS" }[level] || "LOG";
            console.log(`[${time_hms()}] ${prefix}: ${message}`, data || "");
        };

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
            for (let i = 0; i < 6; i++)
            {
                if (el.value === expected) return true;
                await raf();
            }
            return false;
        };

        const detect_network_speed = async () =>
        {
            const t0 = now_ms();
            try
            {
                const controller = new AbortController();
                const timeout_id = setTimeout(() => controller.abort(), 300);
                await fetch("data:text/plain,test", { cache: "no-store", signal: controller.signal });
                clearTimeout(timeout_id);
                const dt = now_ms() - t0;
                return dt < 20 ? "fast" : (dt < 60 ? "medium" : "slow");
            }
            catch
            {
                return "medium";
            }
        };

        const get_timeout = (base_ms, network_speed) =>
        {
            const mult = { fast: 0.4, medium: 0.7, slow: 1.0 }[network_speed] || 1.0;
            return Math.round(base_ms * mult);
        };

        const wait_for = async (fn, base_ms, ctx) =>
        {
            const timeout = get_timeout(base_ms, ctx.network_speed);
            const t0 = now_ms();
            while (now_ms() - t0 < timeout)
            {
                const v = fn();
                if (v) return v;
                await raf();
            }
            return null;
        };

        const no_retry_codes = new Set([
            "ERROR_MODAL",
            "DUKCAPIL_UPDATE",
            "DUKCAPIL",
            "SISTEM_MENOLAK",
            "DATA_TIDAK_DITEMUKAN",
            "SUDAH_TERDAFTAR",
            "NOT_IN_LIST",
            "NOT_FOUND",
            "DATE_FORMAT",
            "DATE_MONTH",
            "DATE_DAY",
            "DOM_OPTION",
            "RESULT_MODAL"
        ]);

        const retry = async (fn, max_attempts, ctx) =>
        {
            let last_error;
            for (let i = 0; i < max_attempts; i++)
            {
                try { return await fn(); }
                catch (e)
                {
                    last_error = e;
                    if (no_retry_codes.has(e.code)) throw e;
                    if (i < max_attempts - 1)
                    {
                        log("warn", `Retry ${i + 1}/${max_attempts}`, e.code);
                        await wait_for(() => false, 60, ctx);
                    }
                }
            }
            throw last_error;
        };

        const query_visible = (selector, root = document) =>
            [...root.querySelectorAll(selector)].filter(is_visible);

        const find_button_by_text = (root, text) =>
        {
            const t = norm(text);
            const btns = query_visible("button", root);
            return btns.find(b => norm(b.textContent) === t) || btns.find(b => norm(b.textContent).includes(t)) || null;
        };

        const find_modal_by_keywords = (keywords) =>
        {
            const keys = keywords.map(norm);
            const modals = query_visible("div.shadow-gmail, div.rounded-lg, [role='dialog']");
            return modals.find(m => keys.every(k => norm(m.textContent).includes(k))) || null;
        };
        return {
            now_ms, raf, norm, is_visible,
            time_hms, format_duration, format_ms,
            log, create_error, ensure,
            click_fast, set_value_fast, verify_value,
            detect_network_speed, wait_for, retry,
            query_visible, find_button_by_text, find_modal_by_keywords,
        };
    })();

    // ===== MODAL RULES =====
    const modal_rules = (() =>
    {
        const pick_date = (raw) => (String(raw).match(/\d{4}-\d{2}-\d{2}/) || [])[0] || "";
        const pick_puskes = (raw) =>
        {
            const m = String(raw).match(/puskesmas terdaftar:\s*([^\n]+)/i);
            return (m && m[1]) ? m[1].trim() : "";
        };

        const rules = [
            {
                id: "already_registered",
                code: "SUDAH_TERDAFTAR",
                match: (t) => t.includes("individu sudah terdaftar") || t.includes("sudah terdaftar dengan detail"),
                status: (raw) =>
                {
                    const d = pick_date(raw);
                    const p = pick_puskes(raw);
                    const extra = [p ? `Puskesmas ${p}` : "", d ? d : ""].filter(Boolean).join(" | ");
                    return extra ? `SUDAH TERDAFTAR (${extra})` : "SUDAH TERDAFTAR";
                },
                close_buttons: ["Tutup", "Kembali", "Ok"]
            },
            {
                id: "dukcapil_update",
                code: "DUKCAPIL_UPDATE",
                match: (t) => t.includes("pembaharuan data identitas") && t.includes("dukcapil"),
                status: () => "ERROR: DUKCAPIL - UPDATE IDENTITAS",
                close_buttons: ["Ok", "Tutup", "Kembali"]
            },
            {
                id: "system_reject",
                code: "SISTEM_MENOLAK",
                match: (t) => t.includes("permintaan anda tidak dapat kami penuhi"),
                status: () => "ERROR: SISTEM MENOLAK - CEK DATA",
                close_buttons: ["Ok", "Tutup", "Kembali"]
            },
            {
                id: "not_found_data",
                code: "DATA_TIDAK_DITEMUKAN",
                match: (t) => t.includes("data tidak ditemukan") || (t.includes("tidak ditemukan") && t.includes("data")),
                status: () => "ERROR: DATA TIDAK DITEMUKAN",
                close_buttons: ["Ok", "Tutup", "Kembali"]
            },
            {
                id: "dukcapil_generic",
                code: "DUKCAPIL",
                match: (t) => t.includes("dukcapil"),
                status: () => "ERROR: DUKCAPIL - VALIDASI GAGAL",
                close_buttons: ["Ok", "Tutup", "Kembali"]
            },
            {
                id: "generic_error",
                code: "ERROR_MODAL",
                match: (t) => t.includes("terjadi kesalahan"),
                status: () => "ERROR: TERJADI KESALAHAN",
                close_buttons: ["Ok", "Tutup", "Kembali"]
            }
        ];

        const classify = (raw) =>
        {
            const t = util.norm(raw);
            return rules.find(r => r.match(t)) || null;
        };

        return { rules, classify };
    })();

    // ===== MODAL HANDLER =====
    const modal_handler = (() =>
    {
        const close_any_modal = async (modal, close_buttons, ctx) =>
        {
            const btn = close_buttons.map(t => util.find_button_by_text(modal, t)).find(Boolean)
                || util.find_button_by_text(modal, "Tutup")
                || util.find_button_by_text(modal, "Ok")
                || util.find_button_by_text(modal, "Kembali");

            btn && util.click_fast(btn);

            if (!btn)
            {
                const close_btn = modal.querySelector('button svg path[d*="17.59 19"]')?.closest("button");
                close_btn && util.click_fast(close_btn);
            }

            await util.wait_for(() => false, 180, ctx);
        };

        const close_all_modals = async (ctx) =>
        {
            for (let round = 0; round < 6; round++)
            {
                const modals = util.query_visible("div.shadow-gmail, div.rounded-lg, [role='dialog']");
                if (!modals.length) break;
                for (const m of modals)
                    await close_any_modal(m, ["Tutup", "Ok", "Kembali"], ctx);
                await util.wait_for(() => false, 160, ctx);
            }
        };

        const check_and_close_known_modal = async (ctx, base_timeout = 800) =>
        {
            const modal = await util.wait_for(() =>
            {
                const modals = util.query_visible("div.shadow-gmail, div.rounded-lg, [role='dialog']");
                return modals.find(m => !!modal_rules.classify(m.textContent || "")) || null;
            }, base_timeout, ctx);

            if (!modal) return { found: false };

            const raw = modal.textContent || "";
            const rule = modal_rules.classify(raw);
            if (!rule) return { found: false };

            await close_any_modal(modal, rule.close_buttons, ctx);
            await close_all_modals(ctx);

            return { found: true, code: rule.code, status_text: rule.status(raw) };
        };

        return { close_all_modals, check_and_close_known_modal };
    })();

    // ===== DATE HELPERS =====
    const date_helper = (() =>
    {
        const parse_date_yyyy_mm_dd = (s) =>
        {
            const m = String(s || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!m) throw util.create_error("DATE_FORMAT", 'Date must be "YYYY-MM-DD"', { input: s });
            const year = +m[1], month = +m[2], day = +m[3];
            if (!(month >= 1 && month <= 12)) throw util.create_error("DATE_MONTH", "Month must be 01-12", { input: s });

            const dim = [31, (year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            if (!(day >= 1 && day <= dim[month - 1])) throw util.create_error("DATE_DAY", `Day must be 01-${dim[month - 1]}`, { input: s });

            return { year, month, day };
        };

        const month_map = {
            1: ["Januari", "Jan"], 2: ["Februari", "Feb"], 3: ["Maret", "Mar"], 4: ["April", "Apr"],
            5: ["Mei"], 6: ["Juni", "Jun"], 7: ["Juli", "Jul"], 8: ["Agustus", "Agt"],
            9: ["September", "Sep"], 10: ["Oktober", "Okt"], 11: ["November", "Nov"], 12: ["Desember", "Des"]
        };

        const get_month_tokens = (month) => [...new Set((month_map[month] || []).map(x => util.norm(x)))];

        return { parse_date_yyyy_mm_dd, get_month_tokens };
    })();

    // ===== FORM ACTIONS =====
    const form_actions = (() =>
    {
        const fill_input_field = async (selectors, value, label, ctx) =>
        {
            const input = selectors.map(s => document.querySelector(s)).find(el => el && util.is_visible(el));
            util.ensure(input, `${label}_NOT_FOUND`, `${label} input not found`);
            util.set_value_fast(input, value);
            await util.verify_value(input, value);
            return true;
        };

        const select_dropdown = async (trigger_text, menu_validator, option_value, label, ctx) =>
        {
            return await util.retry(async () =>
            {
                const trigger = await util.wait_for(() =>
                {
                    const cands = util.query_visible("div.cursor-pointer");
                    return cands.find(d =>
                    {
                        const span = d.querySelector("span");
                        return span && util.norm(span.textContent) === util.norm(trigger_text);
                    }) || null;
                }, 900, ctx);

                util.ensure(trigger, `${label}_TRIGGER`, `${label} trigger not found`);
                util.click_fast(trigger);

                const menu = await util.wait_for(() =>
                {
                    const divs = util.query_visible("div");
                    return divs.find(menu_validator) || null;
                }, 900, ctx);

                util.ensure(menu, `${label}_MENU`, `${label} menu not visible`);

                const target = util.norm(option_value);
                const option = [...menu.querySelectorAll("div.py-2.px-4.cursor-pointer")]
                    .filter(util.is_visible)
                    .find(x => util.norm(x.textContent) === target);

                util.ensure(option, `${label}_OPTION`, `${label} option not found`);
                util.click_fast(option);
                return true;
            }, 3, ctx);
        };

        const select_gender = (value, ctx) =>
            select_dropdown(
                "pilih jenis kelamin",
                (d) =>
                {
                    const items = [...d.querySelectorAll("div.py-2.px-4.cursor-pointer")].filter(util.is_visible);
                    const t = items.map(x => util.norm(x.textContent));
                    return t.includes("laki-laki") && t.includes("perempuan");
                },
                value,
                "GENDER",
                ctx
            );

        const select_birth_date = async (yyyy_mm_dd, ctx) =>
        {
            return await util.retry(async () =>
            {
                const { year, month, day } = date_helper.parse_date_yyyy_mm_dd(yyyy_mm_dd);

                const wrapper = await util.wait_for(() =>
                {
                    const w = [...document.querySelectorAll(".mx-input-wrapper")]
                        .find(x => util.norm(x.innerText).includes("pilih tanggal lahir"));
                    return util.is_visible(w) ? w : null;
                }, 900, ctx);
                util.ensure(wrapper, "DOB_WRAPPER", "Birth date picker not found");
                util.click_fast(wrapper);

                const calendar = await util.wait_for(() => [...document.querySelectorAll(".mx-calendar")].filter(util.is_visible).pop(), 900, ctx);
                util.ensure(calendar, "DOB_CALENDAR", "Calendar not visible");

                const year_btn = calendar.querySelector("button.mx-btn-current-year");
                util.ensure(year_btn, "DOB_YEAR_BTN", "Year button not found");
                year_btn.click();

                const year_panel = await util.wait_for(() => [...document.querySelectorAll(".mx-calendar-panel-year")].filter(util.is_visible).pop(), 900, ctx);
                util.ensure(year_panel, "DOB_YEAR_PANEL", "Year panel not visible");

                const prev_btn = year_panel.querySelector("button .mx-icon-double-left")?.closest("button");
                const next_btn = year_panel.querySelector("button .mx-icon-double-right")?.closest("button");
                util.ensure(prev_btn && next_btn, "DOB_YEAR_NAV", "Year nav not found");

                let picked_year = false;
                for (let i = 0; i < 150 && !picked_year; i++)
                {
                    const table = year_panel.querySelector("table.mx-table-year");
                    const cells = table ? [...table.querySelectorAll("td.cell[data-year]")] : [];
                    const target = cells.find(c => +c.getAttribute("data-year") === year);
                    if (target) { target.click(); picked_year = true; break; }

                    const years = cells.map(c => +c.getAttribute("data-year")).filter(Number.isFinite);
                    const min_y = years.length ? Math.min(...years) : NaN;
                    const max_y = years.length ? Math.max(...years) : NaN;

                    (Number.isFinite(min_y) && year < min_y) && prev_btn.click();
                    (Number.isFinite(max_y) && year > max_y) && next_btn.click();

                    await util.raf();
                }

                util.ensure(picked_year, "DOB_YEAR_PICK", `Failed select year ${year}`);

                const month_panel = await util.wait_for(() => [...document.querySelectorAll(".mx-calendar-panel-month")].filter(util.is_visible).pop(), 900, ctx);
                util.ensure(month_panel, "DOB_MONTH_PANEL", "Month panel not visible");

                const tokens = date_helper.get_month_tokens(month);
                const month_cell = [...month_panel.querySelectorAll("td.cell")]
                    .find(c => tokens.includes(util.norm(c.textContent)));
                util.ensure(month_cell, "DOB_MONTH_PICK", "Month not found");
                month_cell.click();

                const date_panel = await util.wait_for(() => [...document.querySelectorAll(".mx-calendar-panel-date")].filter(util.is_visible).pop(), 900, ctx);
                util.ensure(date_panel, "DOB_DATE_PANEL", "Date panel not visible");

                const title = `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
                const date_cell = date_panel.querySelector(`td.cell[title="${title}"]`);
                util.ensure(date_cell, "DOB_DATE_CELL", "Date not in grid");
                util.ensure(!date_cell.classList.contains("disabled"), "DOB_DATE_DISABLED", "Date disabled");
                util.click_fast(date_cell.querySelector("div") || date_cell);

                return title;
            }, 3, ctx);
        };

        const select_job = async (value, ctx) =>
        {
            return await util.retry(async () =>
            {
                const trigger = await util.wait_for(() =>
                {
                    const cands = util.query_visible("div.cursor-pointer");
                    return cands.find(d => util.norm(d.textContent) === "pilih pekerjaan") || null;
                }, 900, ctx);
                util.ensure(trigger, "JOB_TRIGGER", "Job trigger not found");
                util.click_fast(trigger);

                const modal = await util.wait_for(() =>
                {
                    const title = [...document.querySelectorAll("div,header")]
                        .find(x => (x.textContent || "").includes("Pilih Pekerjaan"));
                    return title ? (title.closest(".modal-content") || title.closest("div.shadow-gmail") || title.closest("div")) : null;
                }, 900, ctx);
                util.ensure(modal, "JOB_MODAL", "Job modal not found");

                const search = modal.querySelector('input[placeholder="Cari pekerjaan"]') || modal.querySelector('input[type="search"]');
                search && (util.set_value_fast(search, value), await util.raf());

                const btn = await util.wait_for(() => util.find_button_by_text(modal, value), 1400, ctx);
                util.ensure(btn, "JOB_OPTION", "Job option not found");
                util.click_fast(btn);
                return true;
            }, 3, ctx);
        };

        const domisili_actions = (() =>
        {
            const find_scroll_container = (root) =>
            {
                const cands = [root, ...root.querySelectorAll("*")].filter(util.is_visible);
                return cands.find(el => el.scrollHeight > el.clientHeight + 5) || root;
            };

            const build_button_index = (root) =>
            {
                const map = new Map();
                util.query_visible("button", root).forEach(b =>
                {
                    const t = util.norm(b.textContent);
                    t && !map.has(t) && map.set(t, b);
                });
                return map;
            };

            const find_button_fast = (root, text) =>
            {
                const t = util.norm(text);
                const idx = build_button_index(root);
                return idx.get(t) || [...idx.entries()].find(([k]) => k.includes(t))?.[1] || null;
            };

            const jump_to_letter = (root, letter) =>
            {
                const l = util.norm(letter);
                if (!l) return;
                const header = util.query_visible("div", root).find(x => util.norm(x.textContent) === l);
                header && header.scrollIntoView({ block: "center" });
            };

            const find_button_with_scroll = async (root, text) =>
            {
                const scroller = find_scroll_container(root);
                jump_to_letter(root, util.norm(text)[0] || "");

                let btn = find_button_fast(root, text);
                let last = -1;
                const step = Math.max(350, Math.floor(scroller.clientHeight * 1.1));

                for (let i = 0; i < 45 && !btn; i++)
                {
                    scroller.scrollTop += step;
                    await util.raf();
                    if (scroller.scrollTop === last) break;
                    last = scroller.scrollTop;
                    btn = find_button_fast(root, text);
                }

                return btn;
            };

            const open_domisili_panel = async (ctx) =>
            {
                return await util.retry(async () =>
                {
                    const trigger = await util.wait_for(() =>
                    {
                        const cands = util.query_visible("div.cursor-pointer");
                        return cands.find(d => util.norm(d.textContent) === "pilih alamat domisili") || null;
                    }, 1100, ctx);

                    util.ensure(trigger, "DOM_TRIGGER", "Domisili trigger not found");
                    trigger.scrollIntoView({ block: "center", behavior: "smooth" });
                    await util.wait_for(() => false, 170, ctx);

                    util.click_fast(trigger);

                    const scope = await util.wait_for(() =>
                    {
                        const title = util.query_visible('[data-v-5240acbe]')
                            .find(el => util.norm(el.textContent) === "daftar provinsi");
                        if (!title) return null;
                        const root = title.parentElement;
                        return root?.parentElement || root || null;
                    }, 15000, ctx);

                    util.ensure(scope, "DOM_PANEL", "Domisili panel not visible");
                    return scope;
                }, 3, ctx);
            };

            const find_section_root = (scope, title) =>
            {
                const t = util.norm(title);
                const direct = util.query_visible('[data-v-5240acbe]', scope).find(x => util.norm(x.textContent) === t);
                if (direct) return direct.parentElement;

                const any = [...scope.querySelectorAll("div, header, span, p")]
                    .filter(util.is_visible)
                    .find(x =>
                    {
                        const own = Array.from(x.childNodes)
                            .filter(n => n.nodeType === Node.TEXT_NODE)
                            .map(n => n.textContent)
                            .join("");
                        return util.norm(own) === t;
                    });

                return any ? (any.parentElement || any) : null;
            };

            const select_domisili_step = async (scope, title, value, ctx) =>
            {
                const root = await util.wait_for(() => find_section_root(scope, title), 15000, ctx);
                util.ensure(root, "DOM_SECTION", `Domisili section missing: ${title}`);

                const btn = await find_button_with_scroll(root, value);
                util.ensure(btn, "DOM_OPTION", `Option not found: ${value}`);

                btn.scrollIntoView({ block: "center" });
                util.click_fast(btn);

                await util.wait_for(() => false, 360, ctx);
                return true;
            };

            const select_domisili = async (dom, ctx) =>
            {
                let last_error;
                const sequence = [
                    ["Daftar Provinsi", dom.provinsi],
                    ["Daftar Kabupaten/Kota", dom.kabupaten_kota],
                    ["Daftar Kecamatan", dom.kecamatan],
                    ["Daftar Kelurahan", dom.kelurahan]
                ];

                for (let attempt = 0; attempt < 3; attempt++)
                {
                    try
                    {
                        const scope = await open_domisili_panel(ctx);
                        for (const [title, value] of sequence)
                        {
                            await util.retry(async () => (await select_domisili_step(scope, title, value, ctx), true), 3, ctx);
                            await util.wait_for(() => false, 120, ctx);
                        }
                        return true;
                    }
                    catch (e)
                    {
                        last_error = e;
                        await modal_handler.close_all_modals(ctx);
                        await util.wait_for(() => false, 320, ctx);
                    }
                }

                throw last_error;
            };

            return { select_domisili };
        })();

        const select_exam_date_today = async (ctx) =>
        {
            return await util.retry(async () =>
            {
                const calendar = await util.wait_for(() =>
                {
                    const roots = util.query_visible('[data-v-5587335f]');
                    return roots.find(el => el.classList.contains("shadow-gmail")) || null;
                }, 1400, ctx);

                util.ensure(calendar, "EXAM_CALENDAR", "Exam calendar not found");

                const day = new Date().getDate();
                const grids = util.query_visible(".grid.grid-cols-7.gap-1", calendar);
                const grid = grids.length >= 2 ? grids[1] : calendar.querySelector(".grid.grid-cols-7.gap-1.mt-2");
                util.ensure(grid, "EXAM_GRID", "Exam grid not found");

                const btn = [...grid.querySelectorAll('button[type="button"]')]
                    .filter(util.is_visible)
                    .find(b =>
                    {
                        const span = b.querySelector("span.font-bold");
                        return !b.disabled && span && +span.textContent.trim() === day;
                    });

                util.ensure(btn, "EXAM_TODAY_UNAVAILABLE", "Today not selectable");
                util.click_fast(btn);
                return true;
            }, 3, ctx);
        };

        return {
            fill_input_field,
            select_gender,
            select_birth_date,
            select_job,
            select_domisili: domisili_actions.select_domisili,
            select_exam_date_today
        };
    })();




    // ===== FLOW HELPERS =====
    const flow = (() =>
    {
        const safe_step = async (ctx, step_name, fn, mandatory) =>
        {
            const t0 = util.now_ms();
            util.log("info", `Step: ${step_name}`);
            try
            {
                const out = await fn();
                const ms = util.now_ms() - t0;
                util.log("success", `${step_name} OK`, `${Math.round(ms)}ms`);
                return { ok: true, step_name, ms, out, mandatory };
            }
            catch (e)
            {
                const ms = util.now_ms() - t0;
                util.log("error", `${step_name} FAIL`, `${e.code || "ERR"}: ${e.message || String(e)}`);
                return { ok: false, step_name, ms, error: { code: e.code || "ERR", message: e.message || String(e) }, mandatory };
            }
        };

        const run_steps = async (ctx, steps) =>
        {
            const results = [];
            for (const s of steps)
            {
                const r = await safe_step(ctx, s.name, s.run, s.mandatory);
                results.push(r);
                if (s.mandatory && !r.ok) return { ok: false, results, stopped: r };
            }
            return { ok: true, results };
        };

        const click_daftar_baru = async (ctx) =>
        {
            return await util.retry(async () =>
            {
                const button = await util.wait_for(() =>
                {
                    const btns = util.query_visible("button");
                    return btns.find(b => util.norm(b.textContent).includes("daftar baru")) || null;
                }, 1400, ctx);

                util.ensure(button, "DAFTAR_BARU_BTN", "Daftar Baru not found");
                util.click_fast(button);
                await util.wait_for(() => false, 250, ctx);
                return true;
            }, 3, ctx);
        };

        const fill_basic_fields = async (ctx) =>
        {
            const d = ctx.data;
            await form_actions.fill_input_field(['input#nik[name="NIK"]', 'input[name="NIK"]', 'input#nik'], d.nik, "NIK", ctx);
            await form_actions.fill_input_field(['input[name="Nama"]', 'input[placeholder*="nama lengkap" i]', 'input#nama'], d.nama, "NAMA", ctx);
            await form_actions.fill_input_field(['input[name="Nomor Whatsapp"]', 'input[placeholder*="nomor whatsapp" i]', 'input[type="tel"]'], d.nomor_whatsapp, "WHATSAPP", ctx);
            return true;
        };

        const fill_detail_address = async (ctx) =>
        {
            return await util.retry(async () =>
            {
                await form_actions.fill_input_field(
                    ['textarea#detail-domisili[name="detail-domisili"]', 'textarea[name="detail-domisili"]', 'textarea[placeholder*="jl." i]', 'textarea[placeholder*="alamat" i]'],
                    ctx.data.detail_domisili,
                    "DETAIL_ADDRESS",
                    ctx
                );
                return true;
            }, 3, ctx);
        };

        const click_next_submit = async (ctx) =>
        {
            return await util.retry(async () =>
            {
                const submit = await util.wait_for(() =>
                {
                    const btns = util.query_visible('button[type="submit"]');
                    return btns.find(b => util.norm(b.textContent).includes("selanjutnya")) || null;
                }, 1400, ctx);

                util.ensure(submit, "NEXT_SUBMIT", "Selanjutnya button not found");
                util.click_fast(submit);
                return true;
            }, 3, ctx);
        };

        const wait_registration_modal = async (ctx) =>
        {
            const pre = await modal_handler.check_and_close_known_modal(ctx, 700);
            if (pre.found && pre.code === "SUDAH_TERDAFTAR") return { kind: "already_registered", status_text: pre.status_text };

            if (pre.found) throw util.create_error(pre.code, pre.status_text);

            const modal = await util.wait_for(() =>
                util.find_modal_by_keywords(["formulir pendaftaran", "list data individu"]),
                2200, ctx);

            util.ensure(modal, "FORM_MODAL", "Formulir Pendaftaran not found");

            await util.wait_for(() =>
            {
                const loading = modal.querySelectorAll(".td-loading, .shimmer");
                return loading.length === 0;
            }, 4500, ctx);

            await util.wait_for(() => false, 220, ctx);
            return { kind: "registration_modal", modal };
        };

        const click_pick_row = async (ctx, modal) =>
        {
            return await util.retry(async () =>
            {
                const rows = [...modal.querySelectorAll("tbody tr")]
                    .filter(util.is_visible)
                    .filter(r => !r.querySelector(".td-loading, .shimmer"));

                util.ensure(rows.length > 0, "NO_ROWS", "No loaded rows");

                const row = rows[0];
                const btn = [...row.querySelectorAll("button")].filter(util.is_visible).find(b => util.norm(b.textContent) === "pilih")
                    || [...modal.querySelectorAll("button")].filter(util.is_visible).find(b => util.norm(b.textContent) === "pilih");

                util.ensure(btn, "ROW_PICK_BTN", "Pilih button not found");
                util.click_fast(btn);
                return true;
            }, 3, ctx);
        };

        const click_register_with_nik = async (ctx, modal) =>
        {
            return await util.retry(async () =>
            {
                const btn = await util.wait_for(() =>
                {
                    const cands = [...modal.querySelectorAll("button")].filter(util.is_visible);
                    return cands.find(b => util.norm(b.textContent).includes("daftar dengan nik"))
                        || cands.find(b => util.norm(b.textContent).includes("daftarkan tanpa nik"))
                        || cands.find(b => util.norm(b.textContent).includes("daftar") && util.norm(b.textContent).includes("nik"))
                        || null;
                }, 1400, ctx);

                util.ensure(btn, "REG_WITH_NIK_BTN", "Daftar button not found");
                util.click_fast(btn);
                return true;
            }, 3, ctx);
        };

        const handle_wali_form = async (ctx) =>
        {
            const wali = batch_config.data_wali;

            const modal = await util.wait_for(() =>
            {
                const modals = util.query_visible("div.shadow-gmail");
                return modals.find(m => !!m.querySelector('input[placeholder*="NIK Wali" i]')) || null;
            }, 700, ctx);

            if (!modal) return { skipped: true, type: "no_form" };

            const actions = [
                {
                    match: () => !!modal.querySelector('input#noWali[name="noWali"]'),
                    run: async () =>
                    {
                        const no_wali = modal.querySelector('input#noWali[name="noWali"]');
                        const check_div = [...modal.querySelectorAll("#noWali")].find(el => el.classList.contains("check"));
                        check_div ? util.click_fast(check_div) : no_wali.click();
                        await util.raf();

                        const daftar_btn = await util.wait_for(() =>
                        {
                            const btns = [...modal.querySelectorAll("button")].filter(util.is_visible);
                            return btns.find(b => util.norm(b.textContent) === "daftar" && !b.classList.contains("bg-disabled")) || null;
                        }, 1400, ctx);

                        util.ensure(daftar_btn, "DAFTAR_BTN", "Daftar button disabled");
                        util.click_fast(daftar_btn);

                        const post = await modal_handler.check_and_close_known_modal(ctx, 1400);
                        if (post.found && post.code !== "SUDAH_TERDAFTAR") throw util.create_error(post.code, post.status_text);

                        return { skipped: false, type: "no_wali_checkbox", filled: false };
                    }
                },
                {
                    match: () => true,
                    run: async () =>
                    {
                        const nik_wali = modal.querySelector('input[placeholder*="NIK Wali" i]');
                        const nama_wali = modal.querySelector('input[placeholder*="Nama Lengkap" i]');
                        util.ensure(nik_wali, "NIK_WALI_NOT_FOUND", "NIK wali not found");
                        util.ensure(nama_wali, "NAMA_WALI_NOT_FOUND", "Nama wali not found");

                        util.set_value_fast(nik_wali, wali.nik);
                        await util.verify_value(nik_wali, wali.nik);

                        util.set_value_fast(nama_wali, wali.nama);
                        await util.verify_value(nama_wali, wali.nama);

                        await form_actions.select_birth_date(wali.tanggal_lahir, ctx);
                        await form_actions.select_gender(wali.jenis_kelamin, ctx);

                        const checkbox = modal.querySelector('input[name="Nomor sama dengan peserta"]');
                        if (checkbox && !checkbox.checked)
                        {
                            const check_div = [...modal.querySelectorAll("#phone-sama")].find(el => el.classList.contains("check"));
                            check_div ? util.click_fast(check_div) : checkbox.click();
                            await util.raf();
                        }

                        const daftar_btn = await util.wait_for(() =>
                        {
                            const btns = [...modal.querySelectorAll("button")].filter(util.is_visible);
                            return btns.find(b => util.norm(b.textContent) === "daftar" && !b.classList.contains("bg-disabled")) || null;
                        }, 1400, ctx);

                        util.ensure(daftar_btn, "DAFTAR_BTN", "Daftar button disabled");
                        util.click_fast(daftar_btn);

                        const post = await modal_handler.check_and_close_known_modal(ctx, 1400);
                        if (post.found && post.code !== "SUDAH_TERDAFTAR") throw util.create_error(post.code, post.status_text);

                        return { skipped: false, type: "full_wali_form", filled: true };
                    }
                }
            ];

            return await actions.find(a => a.match()).run();
        };

        const wait_result_modal = async (ctx) =>
        {
            await util.wait_for(() => false, 2000, ctx);

            const known = await modal_handler.check_and_close_known_modal(ctx, 1300);
            if (known.found) return { kind: "known_modal", code: known.code, status_text: known.status_text };

            const modal = await util.wait_for(() =>
            {
                const by_kw = util.find_modal_by_keywords(["berhasil", "daftar"]) ||
                    util.find_modal_by_keywords(["individu", "terdaftar"]) ||
                    util.find_modal_by_keywords(["berhasil"]);

                if (by_kw) return by_kw;

                const any = util.query_visible("div.shadow-gmail, div.rounded-lg").find(m =>
                {
                    const has_close = !!util.find_button_by_text(m, "Tutup");
                    const t = util.norm(m.textContent);
                    return has_close && (t.includes("daftar") || t.includes("berhasil") || t.includes("terdaftar"));
                });

                return any || null;
            }, 2200, ctx);

            if (!modal)
            {
                const last = await modal_handler.check_and_close_known_modal(ctx, 900);
                if (last.found) return { kind: "known_modal", code: last.code, status_text: last.status_text };
                throw util.create_error("RESULT_MODAL", "ERROR: RESULT MODAL NOT FOUND");
            }

            return { kind: "result_modal", modal };
        };

        const close_result_modal = async (ctx, result_payload) =>
        {
            const kind = result_payload.kind;
            if (kind === "known_modal") return { reg_code: result_payload.code, reg_text: result_payload.status_text };

            const modal = result_payload.modal;
            const t = util.norm(modal.textContent || "");

            const status_map = [
                { code: "TERDAFTAR_BARU", when: (x) => x.includes("berhasil") && x.includes("daftar") },
                { code: "TERDAFTAR", when: (x) => x.includes("terdaftar") },
                { code: "SUDAH_TERDAFTAR", when: (x) => x.includes("sudah terdaftar") }
            ];

            const reg_code = (status_map.find(r => r.when(t)) || { code: "UNKNOWN" }).code;

            const btn = util.find_button_by_text(modal, "Tutup") || util.find_button_by_text(modal, "Ok") || util.find_button_by_text(modal, "Kembali");
            util.ensure(btn, "RESULT_CLOSE", "Close button not found");
            util.click_fast(btn);

            await util.wait_for(() => false, 160, ctx);
            await modal_handler.close_all_modals(ctx);

            const reg_text_map = {
                TERDAFTAR_BARU: "TERDAFTAR BARU",
                TERDAFTAR: "TERDAFTAR",
                SUDAH_TERDAFTAR: "SUDAH TERDAFTAR",
                UNKNOWN: "UNKNOWN"
            };

            return { reg_code, reg_text: reg_text_map[reg_code] || "UNKNOWN" };
        };

        const attendance_actions = (() =>
        {
            const ensure_verify_checked = async (modal) =>
            {
                const checkbox = modal.querySelector('input[type="checkbox"]#verify')
                    || modal.querySelector('input[type="checkbox"][name="verify"]')
                    || modal.querySelector('input[type="checkbox"]');

                if (!checkbox) return false;

                const check_div = checkbox.parentElement?.querySelector(".check")
                    || checkbox.closest(".flex.gap-2.relative.items-center")?.querySelector(".check")
                    || modal.querySelector(".check");

                check_div && !checkbox.checked && util.click_fast(check_div);
                !checkbox.checked && checkbox.click();
                await util.raf();

                return checkbox.checked === true;
            };

            const handle_attendance_modal = async (ctx) =>
            {
                const modal = await util.wait_for(() => util.find_modal_by_keywords(["tandai hadir?"]), 1600, ctx);
                if (!modal) return { ok: false, code: "ABSEN_MODAL_NOT_FOUND" };

                const ok = await ensure_verify_checked(modal);
                if (!ok) return { ok: false, code: "ABSEN_VERIFY_FAILED" };

                const hadir_btn = await util.wait_for(() => util.find_button_by_text(modal, "Hadir"), 1600, ctx);
                if (!hadir_btn) return { ok: false, code: "ABSEN_BUTTON_DISABLED" };

                util.click_fast(hadir_btn);

                const success = await util.wait_for(() => util.find_modal_by_keywords(["berhasil hadir"]), 1600, ctx);
                if (success)
                {
                    const close_btn = util.find_button_by_text(success, "Tutup") || util.find_button_by_text(success, "Ok");
                    close_btn && util.click_fast(close_btn);
                    await util.wait_for(() => false, 140, ctx);
                    await modal_handler.close_all_modals(ctx);
                    return { ok: true, code: "ABSEN_BARU" };
                }

                await util.wait_for(() => false, 200, ctx);
                await modal_handler.close_all_modals(ctx);
                return { ok: true, code: "ABSEN_CLICKED" };
            };

            const clear_search_if_any = async (ctx) =>
            {
                const clear_btn = await util.wait_for(() =>
                {
                    const btns = util.query_visible("button.bg-error");
                    return btns.find(b => b.querySelector("svg")) || null;
                }, 600, ctx);

                clear_btn && (util.click_fast(clear_btn), await util.wait_for(() => false, 140, ctx));
            };

            const confirm_attendance_by_nik = async (ctx) =>
            {
                const table = document.querySelector(".table-individu-terdaftar");
                if (!table) return { ok: true, code: "SKIP_NOT_ON_LIST_PAGE" };

                const dropdown = await util.wait_for(() =>
                {
                    const dropdowns = util.query_visible("[data-v-7670251f]");
                    return dropdowns.find(d =>
                    {
                        const span = d.querySelector("span");
                        const tx = span ? util.norm(span.textContent) : "";
                        return tx.includes("nomor tiket") || tx.includes("nik") || tx.includes("nama");
                    }) || null;
                }, 1400, ctx);

                util.ensure(dropdown, "DROPDOWN_NOT_FOUND", "Search dropdown not found");
                util.click_fast(dropdown);
                await util.wait_for(() => false, 220, ctx);

                const nik_option = await util.wait_for(() =>
                {
                    const menus = util.query_visible("div").filter(d =>
                    {
                        const style = window.getComputedStyle(d);
                        const z = parseInt(style.zIndex || "0");
                        return style.position === "absolute" && z >= 1000;
                    });

                    for (const menu of menus)
                    {
                        const options = [...menu.querySelectorAll("div")].filter(util.is_visible);
                        const opt = options.find(x => util.norm(x.textContent) === "nik");
                        if (opt) return opt;
                    }
                    return null;
                }, 900, ctx);

                nik_option && util.click_fast(nik_option);

                const search_input = await util.wait_for(() =>
                {
                    const inputs = util.query_visible('input#searchNik, input[placeholder*="nik" i], input[placeholder*="nomor tiket" i]');
                    return inputs[0] || null;
                }, 1400, ctx);

                util.ensure(search_input, "SEARCH_INPUT_NOT_FOUND", "Search input not found");

                search_input.focus();
                search_input.value = "";
                await util.raf();

                util.set_value_fast(search_input, ctx.data.nik);
                await util.wait_for(() => false, 120, ctx);

                ["keydown", "keypress", "keyup"].forEach(type =>
                    search_input.dispatchEvent(new KeyboardEvent(type, { key: "Enter", keyCode: 13, code: "Enter", bubbles: true }))
                );

                await util.wait_for(() => false, 700, ctx);

                const text_nodes = () => util.query_visible("div, span, td").map(el => util.norm(el.textContent));
                const has = (k) => text_nodes().some(t => t.includes(k));

                const states = [
                    { code: "SUDAH_ABSEN", when: () => has("sudah hadir") },
                    { code: "NOT_IN_LIST", when: () => has("tidak ditemukan") || has("tidak ada data") || has("no data") }
                ];

                const detected = (states.find(s => s.when()) || null)?.code || null;

                if (detected === "SUDAH_ABSEN")
                {
                    await clear_search_if_any(ctx);
                    return { ok: true, code: "SUDAH_ABSEN" };
                }

                if (detected === "NOT_IN_LIST")
                {
                    await clear_search_if_any(ctx);
                    throw util.create_error("NOT_IN_LIST", "SKIP: SUDAH MASUK PELAYANAN (TIDAK ADA DI LIST)");
                }

                const konfirmasi_btn = await util.wait_for(() =>
                {
                    const btns = util.query_visible("button");
                    return btns.find(b => util.norm(b.textContent).includes("konfirmasi hadir")) || null;
                }, 1700, ctx);

                util.ensure(konfirmasi_btn, "KONFIRMASI_NOT_FOUND", "Konfirmasi hadir not found");
                util.click_fast(konfirmasi_btn);

                const r = await handle_attendance_modal(ctx);
                await clear_search_if_any(ctx);

                return r.ok ? { ok: true, code: r.code } : { ok: false, code: r.code };
            };

            return { confirm_attendance_by_nik };
        })();

        const run_one_user = async (ctx) =>
        {
            await modal_handler.close_all_modals(ctx);
            window.scrollTo({ top: 0, behavior: "instant" });

            const registration_steps = [
                { name: "click_daftar_baru", mandatory: true, run: () => click_daftar_baru(ctx) },
                { name: "fill_basic_fields", mandatory: true, run: () => fill_basic_fields(ctx) },
                { name: "select_gender", mandatory: true, run: () => form_actions.select_gender(ctx.data.jenis_kelamin, ctx) },
                { name: "select_birth_date", mandatory: true, run: () => form_actions.select_birth_date(ctx.data.tanggal_lahir, ctx) },
                { name: "select_job", mandatory: true, run: () => form_actions.select_job(ctx.data.pekerjaan, ctx) },
                { name: "select_domisili", mandatory: true, run: () => form_actions.select_domisili(ctx.data.domisili, ctx) },
                { name: "fill_detail_address", mandatory: true, run: () => fill_detail_address(ctx) },
                { name: "select_exam_date_today", mandatory: true, run: () => form_actions.select_exam_date_today(ctx) },
                { name: "click_next_submit", mandatory: true, run: () => click_next_submit(ctx) },
                { name: "wait_registration_modal", mandatory: true, run: () => wait_registration_modal(ctx) }
            ];

            const t0 = util.now_ms();
            const pre = await run_steps(ctx, registration_steps);

            if (!pre.ok)
            {
                return {
                    ok: false,
                    user_ms: util.now_ms() - t0,
                    reg: { code: "ERROR", text: `ERROR: ${pre.stopped.error?.code || "ERR"}` },
                    attendance: { code: "SKIP", text: "SKIP" }
                };
            }

            const reg_payload = pre.results.find(r => r.step_name === "wait_registration_modal")?.out;

            if (reg_payload && reg_payload.kind === "already_registered")
            {
                const reg = { code: "SUDAH_TERDAFTAR", text: reg_payload.status_text };
                const attendance = await (async () =>
                {
                    try
                    {
                        const a = await attendance_actions.confirm_attendance_by_nik(ctx);
                        const map = [
                            { code: "SUDAH_ABSEN", text: "SUDAH ABSEN" },
                            { code: "ABSEN_BARU", text: "ABSEN BARU" },
                            { code: "ABSEN_CLICKED", text: "ABSEN CLICKED" },
                            { code: "SKIP_NOT_ON_LIST_PAGE", text: "SKIP: NOT ON LIST PAGE" }
                        ];
                        const found = map.find(x => x.code === a.code);
                        return found ? { code: a.code, text: found.text } : { code: a.code, text: a.code };
                    }
                    catch (e)
                    {
                        const skip_map = [
                            { code: "NOT_IN_LIST", text: "SKIP: SUDAH MASUK PELAYANAN (TIDAK ADA DI LIST)" }
                        ];
                        const s = skip_map.find(x => x.code === e.code);
                        return s ? { code: e.code, text: s.text } : { code: "ERROR_ABSEN", text: `ERROR_ABSEN: ${e.code || "ERR"}` };
                    }
                })();

                return { ok: true, user_ms: util.now_ms() - t0, reg, attendance };
            }

            const reg_modal = reg_payload.modal;

            const after_submit_steps = [
                { name: "click_pick_row", mandatory: true, run: () => click_pick_row(ctx, reg_modal) },
                { name: "click_register_with_nik", mandatory: true, run: () => click_register_with_nik(ctx, reg_modal) },
                { name: "handle_wali_form", mandatory: false, run: () => handle_wali_form(ctx) },
                { name: "wait_result_modal", mandatory: true, run: () => wait_result_modal(ctx) }
            ];

            const post = await run_steps(ctx, after_submit_steps);
            if (!post.ok)
            {
                return {
                    ok: false,
                    user_ms: util.now_ms() - t0,
                    reg: { code: "ERROR", text: `ERROR: ${post.stopped.error?.code || "ERR"}` },
                    attendance: { code: "SKIP", text: "SKIP" }
                };
            }

            const result_payload = post.results.find(r => r.step_name === "wait_result_modal")?.out;
            const reg_close = await close_result_modal(ctx, result_payload);

            const reg = { code: reg_close.reg_code, text: reg_close.reg_text };

            const attendance = await (async () =>
            {
                const reg_allows_attend = new Set(["TERDAFTAR_BARU", "TERDAFTAR", "SUDAH_TERDAFTAR"]);
                if (!reg_allows_attend.has(reg.code)) return { code: "SKIP", text: "SKIP" };

                try
                {
                    const a = await attendance_actions.confirm_attendance_by_nik(ctx);
                    const map = [
                        { code: "SUDAH_ABSEN", text: "SUDAH ABSEN" },
                        { code: "ABSEN_BARU", text: "ABSEN BARU" },
                        { code: "ABSEN_CLICKED", text: "ABSEN CLICKED" },
                        { code: "SKIP_NOT_ON_LIST_PAGE", text: "SKIP: NOT ON LIST PAGE" }
                    ];
                    const found = map.find(x => x.code === a.code);
                    return found ? { code: a.code, text: found.text } : { code: a.code, text: a.code };
                }
                catch (e)
                {
                    const skip_map = [
                        { code: "NOT_IN_LIST", text: "SKIP: SUDAH MASUK PELAYANAN (TIDAK ADA DI LIST)" }
                    ];
                    const s = skip_map.find(x => x.code === e.code);
                    return s ? { code: e.code, text: s.text } : { code: "ERROR_ABSEN", text: `ERROR_ABSEN: ${e.code || "ERR"}` };
                }
            })();

            return { ok: true, user_ms: util.now_ms() - t0, reg, attendance };
        };

        return { run_one_user };
    })();

    // ===== STATUS FORMAT =====
    const status_formatter = (() =>
    {
        const rules = [
            { when: (o) => o.reg?.code === "SUDAH_TERDAFTAR", text: (o) => `${o.reg.text} | ${o.attendance.text}` },
            { when: (o) => o.reg?.code === "TERDAFTAR_BARU", text: (o) => `TERDAFTAR BARU | ${o.attendance.text}` },
            { when: (o) => o.reg?.code === "TERDAFTAR", text: (o) => `TERDAFTAR | ${o.attendance.text}` },
            { when: (o) => o.reg?.code === "DUKCAPIL_UPDATE", text: (o) => "ERROR: DUKCAPIL_UPDATE" },
            { when: (o) => o.reg?.code === "SISTEM_MENOLAK", text: (o) => "ERROR: SISTEM_MENOLAK" },
            { when: (o) => o.reg?.code === "DATA_TIDAK_DITEMUKAN", text: (o) => "ERROR: DATA_TIDAK_DITEMUKAN" },
            { when: (o) => o.reg?.code === "DUKCAPIL", text: (o) => "ERROR: DUKCAPIL" },
            { when: (o) => o.reg?.code === "ERROR_MODAL", text: (o) => "ERROR: TERJADI KESALAHAN" },
            { when: (o) => o.reg?.code === "RESULT_MODAL", text: (o) => "ERROR: RESULT_MODAL" },
            { when: (o) => o.reg?.code === "ERROR", text: (o) => o.reg.text || "ERROR" }
        ];

        const derive_status_text = (o) =>
        {
            const rule = rules.find(r => r.when(o));
            return rule ? rule.text(o) : "SUCCESS";
        };

        return { derive_status_text };
    })();

    // ===== MAIN BATCH =====
    const main = async () =>
    {
        const ctx_global = { network_speed: "medium" };

        util.log("info", "Detecting network...");
        ctx_global.network_speed = await util.detect_network_speed();
        util.log("success", `Network: ${ctx_global.network_speed.toUpperCase()}`);

        const total = batch_config.data_list.length;
        const batch_start_ms = Date.now();
        const user_times_ms = [];
        const report_rows = [];

        util.log("info", `BATCH START total=${total} start=${util.time_hms()}`);

        for (let i = batch_config.start_index; i < total; i++)
        {
            const data = batch_config.data_list[i];
            const ctx = { network_speed: ctx_global.network_speed, data };

            const one_start = Date.now();
            let out;

            try
            {
                out = await flow.run_one_user(ctx);
            }
            catch (e)
            {
                await modal_handler.close_all_modals(ctx);
                out = {
                    ok: false,
                    user_ms: Date.now() - one_start,
                    reg: { code: "ERROR", text: `ERROR: ${e.code || "FATAL"}` },
                    attendance: { code: "SKIP", text: "SKIP" }
                };
            }

            const one_ms = out.user_ms ?? (Date.now() - one_start);
            user_times_ms.push(one_ms);

            const status_text = status_formatter.derive_status_text(out);

            report_rows.push({
                No: i + 1,
                NIK: data.nik,
                Nama: data.nama,
                Status: status_text
            });

            const done = i + 1;
            const avg_ms = user_times_ms.reduce((a, b) => a + b, 0) / user_times_ms.length;
            const remaining = total - done;
            const eta_ms = avg_ms * remaining;
            const finish_at = new Date(Date.now() + eta_ms);

            util.log(
                "success",
                `DONE ${done}/${total} user=${util.format_ms(one_ms)} avg=${util.format_ms(avg_ms)} remaining≈${util.format_ms(eta_ms)} finish≈${util.time_hms(finish_at)}`
            );
            console.table([report_rows[report_rows.length - 1]]);

            if (!out.ok && batch_config.stop_on_error) break;

            await util.wait_for(() => false, batch_config.pause_between_users_ms, ctx);
        }

        console.log("\n=== FINAL REPORT ===");
        console.table(report_rows);

        const total_elapsed = Date.now() - batch_start_ms;
        util.log("info", `BATCH END total_time=${util.format_duration(total_elapsed)} finish=${util.time_hms()}`);

        return report_rows;
    };

    await main();
})();