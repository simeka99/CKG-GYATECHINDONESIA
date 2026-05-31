// (async function ()
// {

//     const sleep = ms => new Promise(r => setTimeout(r, ms));
//     const click = el => el && el.click();

//     const today = 21;
//     const year = 2026;
//     const month = "02";

//     let collected = [];

//     // ===== INTERCEPT FETCH RESPONSE =====
//     const originalFetch = window.fetch;
//     window.fetch = async function (...args)
//     {
//         const res = await originalFetch.apply(this, args);

//         try
//         {
//             const url = typeof args[0] === "string" ? args[0] : args[0]?.url;

//             if (url && url.includes("/api/pkg/list-claim"))
//             {
//                 const clone = res.clone();
//                 const json = await clone.json();

//                 if (json?.data?.length)
//                 {
//                     collected.push(...json.data);
//                     console.log("Ambil", json.data.length, "data");
//                 }
//             }
//         } catch (e) { }

//         return res;
//     };

//     console.log("START FAST LOOP...");

//     for (let day = 20; day <= today; day++)
//     {

//         console.log("Tanggal:", day);

//         click(document.querySelector(".mx-input-wrapper"));
//         await sleep(400);

//         const dateStr = `${year}-${month}-${String(day).padStart(2, "0")}`;
//         const cell = [...document.querySelectorAll("td.cell")]
//             .find(td => td.title === dateStr);

//         if (!cell) continue;

//         click(cell);
//         await sleep(200);
//         click(cell);
//         await sleep(1200);

//         if (document.body.innerText.includes("Lakukan Pencarian"))
//         {
//             continue;
//         }

//         while (true)
//         {
//             await sleep(800);

//             const nextLi = [...document.querySelectorAll("li.page-item")]
//                 .find(li => li.innerText.trim() === ">");

//             if (!nextLi || nextLi.classList.contains("disabled"))
//             {
//                 break;
//             }

//             click(nextLi.querySelector("a"));
//         }
//     }

//     await sleep(1500);

//     console.log("TOTAL RAW:", collected.length);

//     // ===== FORMAT DATA =====
//     const data_list = collected.map(item => ({
//         nik: item.patient_nik,
//         nama: item.patient_full_name,
//         nomor_whatsapp: item.patient_mobile_number?.replace(/^62/, ""),
//         jenis_kelamin: item.patient_gender === "LAKI-LAKI" ? "Laki-Laki" : "Perempuan",
//         tanggal_lahir: item.patient_born_date,
//         pekerjaan: item.patient_job?.name || "",
//         domisili: {
//             provinsi: item.patient_domicile?.province_name || "",
//             kabupaten_kota: item.patient_domicile?.city_name || "",
//             kecamatan: item.patient_domicile?.district_name || "",
//             kelurahan: item.patient_domicile?.sub_district_name || ""
//         },
//         detail_domisili: item.patient_domicile?.address || ""
//     }));

//     const final_output = { data_list };

//     console.log("Download JSON...");

//     const blob = new Blob(
//         [JSON.stringify(final_output, null, 2)],
//         { type: "application/json" }
//     );

//     const a = document.createElement("a");
//     a.href = URL.createObjectURL(blob);
//     a.download = "data_list_full.json";
//     a.click();

//     console.log("SELESAI 🚀 FILE TERDOWNLOAD");

// })();

void (async function ()
{
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const click = el => el && el.click();
    const START_YMD = "2026-01-01";
    const END_YMD = "2026-02-27";
    let collected = [];

    const pad2 = n => String(n).padStart(2, "0");
    const parseYMD = s => { const [y, m, d] = String(s).split("-").map(x => parseInt(x, 10)); return { y, m, d }; };
    const monthMap = { jan: 1, feb: 2, mar: 3, apr: 4, mei: 5, jun: 6, jul: 7, agt: 8, sep: 9, okt: 10, nov: 11, des: 12 };
    const monthLabel = { 1: "Jan", 2: "Feb", 3: "Mar", 4: "Apr", 5: "Mei", 6: "Jun", 7: "Jul", 8: "Agt", 9: "Sep", 10: "Okt", 11: "Nov", 12: "Des" };
    const parseMonthLabel = txt => { const k = String(txt || "").trim().slice(0, 3).toLowerCase(); return monthMap[k] || null; };
    const is_visible = el => { if (!el) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };

    const wait_for = async (fn, ms) =>
    {
        const t0 = Date.now();
        while (Date.now() - t0 < ms) { const v = fn(); if (v) return v; await sleep(80); }
        return null;
    };

    // ─── TRANSFORM: raw API item → format output yang diinginkan ────
    const transform_item = (raw) =>
    {
        const gender_map = { "LAKI-LAKI": "Laki-laki", "PEREMPUAN": "Perempuan" };
        const normalize_phone = (p) =>
        {
            if (!p) return "";
            const s = String(p).replace(/\D/g, "");
            // hapus prefix 62 → 08..., atau langsung return tanpa 62
            if (s.startsWith("62")) return s.slice(2);
            if (s.startsWith("0")) return s.slice(1);
            return s;
        };
        const dom = raw.patient_domicile || {};
        return {
            nik: raw.patient_nik || "",
            nama: raw.patient_full_name || "",
            nomor_whatsapp: normalize_phone(raw.patient_mobile_number),
            jenis_kelamin: gender_map[String(raw.patient_gender || "").toUpperCase()] || raw.patient_gender || "",
            tanggal_lahir: raw.patient_born_date || "",
            pekerjaan: raw.patient_job?.name || "",
            domisili: {
                provinsi: dom.province_name || "",
                kabupaten_kota: dom.city_name || "",
                kecamatan: dom.district_name || "",
                kelurahan: dom.sub_district_name || "",
            },
            detail_domisili: dom.address || "",
            // ── field tambahan (opsional, hapus kalau tidak perlu) ──
            nomor_tiket: raw.ticket_number || "",
            tanggal_daftar: raw.register_date || "",
            tanggal_skrining: raw.screening_date || "",
            status_layanan: raw.service_status || "",
            faskes: raw.faskes_name || "",
        };
    };

    // ─── DEDUP: hilangkan duplikat berdasarkan reg_id ──────────────
    const dedup = (arr) =>
    {
        const seen = new Set();
        return arr.filter(item =>
        {
            const key = item.reg_id || (item.patient_nik + "|" + item.register_date);
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    };

    // ─── DOWNLOAD JSON ──────────────────────────────────────────────
    const download_json = (data, filename) =>
    {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    // ─── TABLE SCOPE ────────────────────────────────────────────────
    const findIndividuTable = () =>
    {
        const tables = [...document.querySelectorAll("table")].filter(is_visible);
        return tables.find(tb =>
        {
            const ths = [...tb.querySelectorAll("thead th")].map(th => (th.textContent || "").replace(/\s+/g, " ").trim().toLowerCase());
            return ths.includes("nama") &&
                (ths.includes("tanggal lahir") || ths.includes("tanggallahir")) &&
                (ths.includes("nomor tiket") || ths.includes("nomortiket"));
        }) || null;
    };

    const getCtx = () =>
    {
        const table = findIndividuTable();
        if (!table) return { root: document, table: null };
        let root = table.closest("div") || table.parentElement;
        for (let i = 0; i < 10 && root; i++)
        {
            if (root.querySelector("div.font-extralight") || root.querySelector("ul.vpagination")) break;
            root = root.parentElement;
        }
        return { root: root || document, table };
    };

    const countRows = ctx => { const tb = ctx.table; if (!tb) return 0; return [...tb.querySelectorAll("tbody tr")].filter(is_visible).length; };
    const firstRowKey = ctx =>
    {
        const tb = ctx.table; if (!tb) return "";
        const row = [...tb.querySelectorAll("tbody tr")].find(is_visible); if (!row) return "";
        const fc = row.querySelector("td");
        return (fc?.textContent || row.textContent || "").replace(/\s+/g, " ").trim().slice(0, 40);
    };

    const getPagination = ctx => ctx.root.querySelector("ul.vpagination");

    const getStatusEl = (ctx, ul) =>
    {
        if (ul)
        {
            const box = ul.closest("div.flex.flex-row") || ul.parentElement?.closest("div.flex.flex-row");
            const el = box?.querySelector("div.font-extralight");
            if (el && /Menampilkan/i.test(el.textContent || "")) return el;
        }
        const el2 = ctx.root.querySelector("div.font-extralight");
        if (el2 && /Menampilkan/i.test(el2.textContent || "")) return el2;
        return null;
    };

    const parseStatus = (ctx, ul) =>
    {
        const el = getStatusEl(ctx, ul); if (!el) return null;
        const bs = el.querySelectorAll("b");
        if (bs && bs.length >= 2)
        {
            const m1 = (bs[0].textContent || "").trim().match(/(\d+)\s*-\s*(\d+)/);
            const m2 = (bs[1].textContent || "").trim().match(/(\d+)/);
            if (m1 && m2)
            {
                const from = +m1[1], to = +m1[2], total = +m2[1];
                if (isFinite(from) && isFinite(to) && isFinite(total)) return { from, to, total };
            }
        }
        const m = (el.textContent || "").replace(/\s+/g, " ").trim()
            .match(/Menampilkan\s*(\d+)\s*-\s*(\d+)\s*dari\s*(\d+)\s*data/i);
        return m ? { from: +m[1], to: +m[2], total: +m[3] } : null;
    };

    const getActivePage = ul =>
    {
        const n = parseInt(ul?.querySelector("li.page-item.active a.page-link")?.textContent?.trim() || "", 10);
        return isFinite(n) ? n : 1;
    };

    const clickPageNum = (ul, pageTxt) =>
    {
        const a = [...(ul?.querySelectorAll("a.page-link") || [])].find(x => (x.textContent || "").trim() === pageTxt);
        if (a) click(a);
    };

    const findNextLi = ul => [...(ul?.querySelectorAll("li.page-item") || [])].find(li =>
    {
        if (li.classList.contains("disabled")) return false;
        const t = (li.textContent || "").replace(/\s+/g, " ").trim();
        return t === ">" || t === "»" || /next/i.test(t);
    }) || null;

    const hasNext = ul => !!findNextLi(ul);
    const clickNext = ul =>
    {
        const li = findNextLi(ul);
        const a = li?.querySelector("a.page-link");
        if (a) { a.scrollIntoView({ block: "nearest" }); click(a); }
    };

    const waitPageChanged = async (ctx, ul, prevPage, prevSt, prevRK, prevRC) =>
    {
        return await wait_for(() =>
        {
            const p = getActivePage(ul), st = parseStatus(ctx, ul), rk = firstRowKey(ctx), rc = countRows(ctx);
            if (p !== prevPage) return { p, st, rk, rc };
            if (st && prevSt && (st.from !== prevSt.from || st.to !== prevSt.to || st.total !== prevSt.total)) return { p, st, rk, rc };
            if (rk && rk !== prevRK) return { p, st, rk, rc };
            if (rc !== prevRC && rc > 0) return { p, st, rk, rc };
            return null;
        }, 12000);
    };

    const waitDataFast = async (ctx, ul) =>
    {
        return await wait_for(() =>
        {
            const st = parseStatus(ctx, ul), rc = countRows(ctx);
            if (st) return { st, rc };
            if (rc > 0) return { st: null, rc };
            return null;
        }, 3000);
    };

    const logPage = (rk, p, rc, done, total) =>
        console.log(`[${rk}] p${p}=${rc} | result=${done}/${total} | total_collected=${collected.length}`);

    // ─── PAGINATE DOM ───────────────────────────────────────────────
    const paginateDOM = async (rangeKey) =>
    {
        let ctx = getCtx();
        let ul = getPagination(ctx);

        const ready = await waitDataFast(ctx, ul);
        if (!ready) { console.log(`[${rangeKey}] kosong`); return; }

        if (!ul)
        {
            const st0 = parseStatus(ctx, null);
            const total = st0?.total ?? ready.rc ?? 0;
            const rc = ready.rc ?? 0;
            logPage(rangeKey, 1, rc, Math.min(rc, total), total);
            console.log(`[${rangeKey}] DONE | pages=1 | total=${total}`);
            return;
        }

        clickPageNum(ul, "1");
        await sleep(400);
        let st = await wait_for(() => parseStatus(ctx, ul), 3000);
        if (!st)
        {
            const rc = countRows(ctx);
            logPage(rangeKey, 1, rc, rc, rc);
            console.log(`[${rangeKey}] DONE | pages=1 | total=${rc}`);
            return;
        }

        let page = getActivePage(ul);
        let rc = countRows(ctx);
        let done = (rc > 0 && (st.to - st.from + 1) !== rc) ? Math.min(rc, st.total) : Math.min(st.to, st.total);
        let prevDone = done;

        logPage(rangeKey, page, rc, done, st.total);
        if (!hasNext(ul) || done >= st.total)
        {
            console.log(`[${rangeKey}] DONE | pages=1 | total=${st.total}`);
            return;
        }

        const maxPages = Math.ceil(st.total / Math.max(rc, 1)) + 3;
        let pages = 1;
        let stale_tries = 0;

        while (pages <= maxPages)
        {
            ctx = getCtx(); ul = getPagination(ctx);
            if (!ul || !hasNext(ul)) break;

            const prev_page = getActivePage(ul);
            const prev_st = parseStatus(ctx, ul);
            const prev_rk = firstRowKey(ctx);
            const prev_rc = countRows(ctx);

            clickNext(ul);
            let ch = await waitPageChanged(ctx, ul, prev_page, prev_st, prev_rk, prev_rc);

            if (!ch)
            {
                clickPageNum(ul, String(prev_page + 1));
                ch = await waitPageChanged(ctx, ul, prev_page, prev_st, prev_rk, prev_rc);
            }

            if (!ch)
            {
                stale_tries++;
                if (stale_tries >= 2) { console.log(`[${rangeKey}] stop (stale 2x)`); break; }
                clickPageNum(ul, "1");
                await sleep(600);
                ctx = getCtx(); ul = getPagination(ctx);
                continue;
            }

            stale_tries = 0;
            ctx = getCtx(); ul = getPagination(ctx);
            st = ch.st || parseStatus(ctx, ul);
            page = ch.p || getActivePage(ul);
            rc = ch.rc ?? countRows(ctx);

            if (!st) { console.log(`[${rangeKey}] stop (status hilang)`); break; }

            let new_done = Math.min(st.to, st.total);
            if (new_done <= prevDone) new_done = Math.min(st.from + rc - 1, st.total);
            if (new_done <= prevDone) new_done = Math.min(prevDone + rc, st.total);
            prevDone = new_done;
            pages++;

            logPage(rangeKey, page, rc, new_done, st.total);
            if (new_done >= st.total) break;
        }

        if (pages > maxPages) console.log(`[${rangeKey}] stop (maxPages ${maxPages})`);
        console.log(`[${rangeKey}] DONE | pages=${pages} | total=${st?.total ?? 0} | collected=${collected.length}`);
    };

    // ─── DATEPICKER ─────────────────────────────────────────────────
    const picker_root = () => document.querySelector(".mx-calendar-range");
    const left_panel = () => document.querySelectorAll(".mx-calendar-range .mx-calendar-panel-date")[0] || null;
    const picker_open = () => { const p = picker_root(); return !!(p && is_visible(p)); };

    const open_picker = async () =>
    {
        if (picker_open()) return;
        click(document.querySelector(".mx-input-wrapper"));
        await sleep(350);
    };

    const get_left_ym = () =>
    {
        const p = left_panel(); if (!p) return null;
        const m = parseMonthLabel(p.querySelector(".mx-btn-current-month")?.textContent?.trim());
        const y = parseInt(p.querySelector(".mx-btn-current-year")?.textContent?.trim(), 10);
        return (!m || !y) ? null : { y, m };
    };

    const goto_month_by_arrows = async (target_y, target_m) =>
    {
        await open_picker();
        const p = left_panel(); if (!p) return false;
        const btn_prev = p.querySelector(".mx-btn-icon-left");
        const btn_next = p.querySelector(".mx-btn-icon-right");
        if (!btn_prev || !btn_next) return false;
        for (let i = 0; i < 240; i++)
        {
            const cur = get_left_ym(); if (!cur) return false;
            if (cur.y === target_y && cur.m === target_m) return true;
            (cur.y * 12 + cur.m > target_y * 12 + target_m) ? click(btn_prev) : click(btn_next);
            await sleep(220);
        }
        return false;
    };

    const find_cell = (y, m, d) =>
    {
        const p = left_panel(); if (!p) return null;
        return [...p.querySelectorAll("td.cell")].find(td => td.title === `${y}-${pad2(m)}-${pad2(d)}`) || null;
    };

    const get_max_selectable_day = async (y, m) =>
    {
        if (!await goto_month_by_arrows(y, m)) return null;
        const p = left_panel(); if (!p) return null;
        const prefix = `${y}-${pad2(m)}-`;
        let max_day = null;
        for (const td of p.querySelectorAll("td.cell"))
        {
            const t = td.title || "";
            if (!t.startsWith(prefix) || td.classList.contains("disabled")) continue;
            const day = parseInt(t.slice(prefix.length), 10);
            if (isFinite(day) && (max_day === null || day > max_day)) max_day = day;
        }
        return max_day;
    };

    const adjust_start_end_to_enabled = (y, m, start_d, end_d) =>
    {
        let s = start_d, e = end_d;
        for (let i = 0; i < 40 && s <= e; i++) { const c = find_cell(y, m, s); if (c && !c.classList.contains("disabled")) break; s++; }
        for (let i = 0; i < 40 && e >= s; i++) { const c = find_cell(y, m, e); if (c && !c.classList.contains("disabled")) break; e--; }
        return s > e ? null : { s, e };
    };

    const select_range_with_retry = async (y, m, start_d, end_d) =>
    {
        for (let attempt = 1; attempt <= 3; attempt++)
        {
            await open_picker();
            if (!await goto_month_by_arrows(y, m)) { await sleep(200); continue; }
            const adj = adjust_start_end_to_enabled(y, m, start_d, end_d); if (!adj) return null;
            const s_cell = find_cell(y, m, adj.s), e_cell = find_cell(y, m, adj.e);
            if (!s_cell || !e_cell) { await sleep(200); continue; }
            const before_txt = document.querySelector(".mx-input-wrapper .ml-4")?.textContent?.trim() || "";
            click(s_cell); await sleep(180);
            click(e_cell); await sleep(500);
            const exp = { a: `${pad2(adj.s)} ${monthLabel[m]} ${y}`, b: `${pad2(adj.e)} ${monthLabel[m]} ${y}` };
            const ok_txt = await wait_for(() =>
            {
                const txt = document.querySelector(".mx-input-wrapper .ml-4")?.textContent?.trim() || "";
                if (!txt || txt === before_txt) return null;
                return (txt.includes(exp.a) && txt.includes(exp.b)) ? txt : null;
            }, 2500);
            if (ok_txt) return { s: adj.s, e: adj.e };
            await sleep(300);
        }
        return null;
    };

    const days_in_month = (y, m) => new Date(y, m, 0).getDate();
    const month_iter = (sy, sm, ey, em) =>
    {
        const out = []; let y = sy, m = sm;
        while (y < ey || (y === ey && m <= em)) { out.push({ y, m }); m++; if (m === 13) { m = 1; y++; } }
        return out;
    };

    // ─── INTERCEPT FETCH ────────────────────────────────────────────
    const original_fetch = window.fetch;
    window.fetch = async function (...args)
    {
        const res = await original_fetch.apply(this, args);
        try
        {
            const url = typeof args[0] === "string" ? args[0] : args[0]?.url;
            if (url?.includes("/api/pkg/list-claim"))
            {
                const json = await res.clone().json();
                if (json?.data?.length)
                {
                    collected.push(...json.data);
                    console.log(`[FETCH] +${json.data.length} → total=${collected.length}`);
                }
            }
        } catch (e) { }
        return res;
    };

    // ─── MAIN ────────────────────────────────────────────────────────
    const S = parseYMD(START_YMD);
    const E = parseYMD(END_YMD);
    const months = month_iter(S.y, S.m, E.y, E.m);
    console.log("▶ START:", START_YMD, "→", END_YMD);

    for (const mm of months)
    {
        const { y, m } = mm;
        const dim = days_in_month(y, m);
        const req_start = (y === S.y && m === S.m) ? S.d : 1;
        const req_end = (y === E.y && m === E.m) ? Math.min(E.d, dim) : dim;
        const max_sel = await get_max_selectable_day(y, m);
        if (!max_sel) { console.log(`[${y}-${pad2(m)}] skip (no selectable)`); continue; }
        const month_end = Math.min(req_end, max_sel);

        for (let bs = req_start; bs <= month_end; bs += 7)
        {
            const be = Math.min(bs + 6, month_end);
            const picked = await select_range_with_retry(y, m, bs, be);
            if (!picked) { console.log(`[${y}-${pad2(m)}-${pad2(bs)}] skip (picker fail)`); continue; }
            const range_key = `${y}-${pad2(m)}-${pad2(picked.s)}__${y}-${pad2(m)}-${pad2(picked.e)}`;
            await paginateDOM(range_key);
            await sleep(200);
        }
    }

    // ─── POST PROCESS + DOWNLOAD ─────────────────────────────────────
    console.log("✅ DONE ALL | raw_collected =", collected.length);

    // 1. Dedup
    const unique_raw = dedup(collected);
    console.log("🔍 setelah dedup:", unique_raw.length);

    // 2. Transform ke format output
    const result = unique_raw.map(transform_item);

    // 3. Sort by tanggal_lahir (opsional)
    result.sort((a, b) => a.nama.localeCompare(b.nama, "id"));

    // 4. Summary log
    console.log(`📊 Total unik: ${result.length}`);
    console.table(result.slice(0, 5)); // preview 5 data pertama

    // 5. Download JSON
    const file_name = `data_odng_${START_YMD}_${END_YMD}.json`;
    download_json(result, file_name);
    console.log(`💾 Download: ${file_name}`);

    // 6. Simpan ke window buat akses manual kalau perlu
    window.__odng_result__ = result;
    window.__odng_raw__ = unique_raw;
    console.log("📦 Akses manual: window.__odng_result__ | window.__odng_raw__");
})();
