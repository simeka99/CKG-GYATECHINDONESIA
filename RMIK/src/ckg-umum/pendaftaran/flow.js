import { log } from "../../core/helpers.js";
import
{
    fill_input, select_gender, select_birth_date, select_job,
    select_domisili, fill_detail_address, select_exam_date_today,
    select_status_pernikahan, select_penyandang_disabilitas,
    fill_phone, fill_wali_inline
} from "./form.js";
import
{
    check_and_close_known_modal, close_all_modals,
    wait_for_modal_by_keywords, wait_verifikasi_modal
} from "./modal.js";
import { accept_privacy_if_present } from "../../core/auth.js";
import { SEL } from "./selector_config.js";

const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();

const NO_RETRY = new Set([
    "ERROR_MODAL", "DUKCAPIL_UPDATE", "DUKCAPIL", "SISTEM_MENOLAK",
    "DATA_TIDAK_DITEMUKAN", "SUDAH_TERDAFTAR", "SUDAH_MENERIMA_LAYANAN",
    "NOT_IN_LIST", "DATE_FORMAT", "DATE_MONTH", "DATE_DAY", "DOM_OPTION",
    "RESULT_MODAL", "VALIDASI_TIDAK_VALID", "VALIDASI_PESERTA_WALI_TIDAK_VALID", "EXAM_TODAY_UNAVAILABLE",
    "VALIDASI_DATA_PASIEN_GAGAL", "JOB_SEARCH_NOT_VISIBLE", "JOB_SEARCH_FILL_FAILED",
    "JOB_OPTION_NOT_FOUND", "JOB_NOT_SELECTED", "DOMISILI_SEARCH_NOT_VISIBLE",
    "DOM_VALUE_EMPTY", "OPTION_NOT_FOUND", "PHONE_EMPTY"
]);

function normalize_phone(value)
{
    const raw = String(value || "").trim();
    if (!raw) return "";
    let digits = raw.replace(/\D+/g, "");
    if (!digits) return "";

    // Web Sehat minta format tanpa awalan 0 (contoh: 8123...)
    digits = digits.replace(/^0+/, "");
    if (digits.startsWith("62")) digits = digits.slice(2);
    digits = digits.replace(/^0+/, "");
    return digits;
}

function pick_phone(...candidates)
{
    for (const c of candidates)
    {
        const phone = normalize_phone(c);
        if (phone) return phone;
    }
    return "";
}

async function retry(fn, max, label, page)
{
    let last;

    for (let i = 0; i < max; i++)
    {
        try
        {
            return await fn();
        }
        catch (e)
        {
            last = e;
            const code = e.code || e.message?.split(":")?.[0] || "ERR";

            if (NO_RETRY.has(code)) throw e;

            if (i < max - 1)
            {
                log("WARN", `retry ${i + 1}/${max}`, { label, code });
                await page.waitForTimeout(1000);
            }
        }
    }

    throw last;
}

async function safe_step(name, fn, mandatory)
{
    const t0 = Date.now();
    log("INFO", "step_start", { step: name });

    try
    {
        const out = await fn();
        const ms = Date.now() - t0;
        log("INFO", "step_ok", { step: name, ms });
        return { ok: true, name, ms, out, mandatory };
    }
    catch (e)
    {
        const ms = Date.now() - t0;
        const code = e.code || e.message?.split(":")?.[0] || "ERR";
        log("ERROR", "step_fail", { step: name, code, message: e.message });
        return {
            ok: false,
            name,
            ms,
            error: {
                code,
                message: e.message,
                detail: e?.detail || null
            },
            mandatory
        };
    }
}

async function run_steps(steps)
{
    const results = [];

    for (const s of steps)
    {
        const r = await safe_step(s.name, s.run, s.mandatory);
        results.push(r);

        if (s.mandatory && !r.ok)
            return { ok: false, results, stopped: r };
    }

    return { ok: true, results };
}

function make_error_return(t0, error)
{
    return {
        ok: false,
        duration_ms: Date.now() - t0,
        reg: {
            code: error?.code || "ERR",
            text: error?.message || `ERROR: ${error?.code || "ERR"}`
        },
        attendance: { code: "SKIP", text: "SKIP" }
    };
}

// ─── NIK HELPERS ─────────────────────────────────────────────

const NIK_SEL_CSS = SEL.nik.join(", ");

async function clear_nik_field(page)
{
    await page.evaluate((sel) =>
    {
        const el = document.querySelector(sel);
        if (!el) return;

        el.focus();
        el.value = "";
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
        el.blur();
    }, NIK_SEL_CSS);
}

async function click_daftar_baru(page, timeout_ms)
{
    return await retry(async () =>
    {
        const btn = page.locator("button").filter({ hasText: /daftar baru/i }).first();
        await btn.waitFor({ state: "visible", timeout: timeout_ms });
        await btn.scrollIntoViewIfNeeded();
        await btn.click({ force: true });
        await page.waitForSelector(NIK_SEL_CSS, { state: "visible", timeout: 5000 });
        return true;
    }, 3, "click_daftar_baru", page);
}

async function return_to_pendaftaran_standby(page, timeout_ms = 3500)
{
    const nik_visible = await page.locator(NIK_SEL_CSS).first()
        .isVisible({ timeout: 400 })
        .catch(() => false);

    if (!nik_visible) return true;

    const clicked = await page.evaluate((nik_sel) =>
    {
        const visible = (el) => !!(el && el.offsetParent);
        const nik = [...document.querySelectorAll(nik_sel)].find(visible);
        if (!nik) return false;

        const root =
            nik.closest("form") ||
            nik.closest("div.rounded-lg") ||
            nik.closest("div.bg-white") ||
            nik.closest("div");

        const candidates = [
            root?.querySelector("button.btn-transparent.absolute.right-4.top-3"),
            root?.querySelector("button.btn-transparent"),
            ...document.querySelectorAll("button.btn-transparent.absolute.right-4.top-3")
        ].filter(Boolean);

        const btn = candidates.find(visible);
        if (!btn) return false;

        btn.scrollIntoView({ block: "center" });
        btn.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));
        return true;
    }, NIK_SEL_CSS).catch(() => false);

    if (clicked)
    {
        log("INFO", "standby_reset_clicked");
        await page.waitForTimeout(250);
    }

    await close_all_modals(page).catch(() => { });
    await page.locator("button").filter({ hasText: /daftar baru/i }).first()
        .waitFor({ state: "visible", timeout: timeout_ms })
        .catch(() => { });

    return true;
}

async function fill_nik_only(page, nik, timeout_ms)
{
    await fill_input(page, SEL.nik, nik, "NIK", timeout_ms);
    return true;
}

async function fill_form_step1(page, data, wali_data, timeout_ms)
{
    await fill_input(page, SEL.nama, data.nama, "NAMA", timeout_ms);
    await select_birth_date(page, data.tanggal_lahir, timeout_ms);
    await select_gender(page, data.jenis_kelamin, timeout_ms);

    const peserta_phone = pick_phone(
        data.nomor_whatsapp,
        data.nomor_hp,
        data.no_hp,
        data.whatsapp,
        data.phone
    );
    const wali_phone_fallback = pick_phone(
        wali_data?.no_hp,
        wali_data?.nomor_whatsapp,
        wali_data?.phone
    );
    const phone_to_use = peserta_phone || wali_phone_fallback;

    if (!phone_to_use)
    {
        throw Object.assign(
            new Error("Nomor WhatsApp peserta kosong dan fallback No HP Wali belum diisi"),
            { code: "PHONE_EMPTY" }
        );
    }

    if (!peserta_phone && wali_phone_fallback)
    {
        log("WARN", "phone_fallback_used", {
            nik: data?.nik || "",
            fallback_source: "wali_no_hp"
        });
    }

    await fill_phone(page, phone_to_use, timeout_ms);
    await fill_wali_inline(page, wali_data, timeout_ms);
    await select_exam_date_today(page, timeout_ms);
    return true;
}

async function click_next_button(page, timeout_ms)
{
    return await retry(async () =>
    {
        const btn = page.locator('button[type="button"], button[type="submit"]')
            .filter({ hasText: /selanjutnya/i }).first();

        await btn.waitFor({ state: "visible", timeout: timeout_ms });

        const is_disabled = await btn.evaluate(el =>
            el.disabled ||
            el.classList.contains("cursor-not-allowed") ||
            el.classList.contains("btn-disabled") ||
            el.closest(".cursor-not-allowed") !== null
        );

        if (is_disabled)
        {
            throw Object.assign(
                new Error("Tombol Selanjutnya masih tidak aktif"),
                { code: "NEXT_BTN_DISABLED" }
            );
        }

        await btn.scrollIntoViewIfNeeded();
        await btn.click({ force: true });
        await page.waitForTimeout(250);
        return true;
    }, 3, "click_next_button", page);
}

// ─── VERIFIKASI ──────────────────────────────────────────────

async function click_quota_habis_lanjut(page)
{
    const quota_modal = page.locator("div.shadow-gmail, div.rounded-lg.bg-white")
        .filter({ hasText: /kuota pemeriksaan habis|melanjutkan pendaftaran peserta/i })
        .first();

    if (!await quota_modal.isVisible({ timeout: 900 }).catch(() => false))
        return false;

    const lanjut_btn = quota_modal.locator("button").filter({ hasText: /^\s*lanjut\s*$/i }).first();
    if (!await lanjut_btn.isVisible({ timeout: 900 }).catch(() => false))
    {
        log("WARN", "kuota_habis_lanjut_not_found", { source: "handle_verifikasi" });
        return false;
    }

    await lanjut_btn.scrollIntoViewIfNeeded().catch(() => { });
    await lanjut_btn.click({ force: true }).catch(() => { });
    log("WARN", "kuota_habis_lanjut_clicked", { source: "handle_verifikasi" });
    await page.waitForTimeout(350);
    return true;
}

async function handle_verifikasi(page, timeout_ms, quota_retry = 0)
{
    let result;

    try
    {
        result = await wait_verifikasi_modal(page, 10000);
    }
    catch
    {
        if (quota_retry < 3 && await click_quota_habis_lanjut(page))
            return await handle_verifikasi(page, timeout_ms, quota_retry + 1);

        const is_tidak_valid_wali = await page.locator("div.shadow-gmail")
            .filter({ hasText: /data peserta atau wali tidak valid/i })
            .isVisible({ timeout: 500 })
            .catch(() => false);

        if (is_tidak_valid_wali)
        {
            await page.getByRole("button", { name: "Periksa Kembali" }).first().click().catch(() => { });
            await page.waitForTimeout(300);
            await return_to_pendaftaran_standby(page);

            throw Object.assign(
                new Error("Data peserta atau wali tidak valid - pastikan data peserta dan wali sesuai KTP/KK."),
                { code: "VALIDASI_PESERTA_WALI_TIDAK_VALID" }
            );
        }

        const is_tidak_valid = await page.locator("div.shadow-gmail")
            .filter({ hasText: /data peserta tidak valid/i })
            .isVisible({ timeout: 500 })
            .catch(() => false);

        if (is_tidak_valid)
        {
            await page.getByRole("button", { name: "Periksa Kembali" }).first().click().catch(() => { });
            await page.waitForTimeout(300);
            await return_to_pendaftaran_standby(page);

            throw Object.assign(
                new Error("Data peserta tidak valid - pastikan nama, NIK, dan tanggal lahir sesuai KTP/KK."),
                { code: "VALIDASI_TIDAK_VALID" }
            );
        }

        log("WARN", "validasi_timeout_skip");
        throw Object.assign(new Error("VALIDASI_DATA_PASIEN_GAGAL"), { code: "VALIDASI_DATA_PASIEN_GAGAL" });
    }

    if (result.kind === "tidak_valid")
    {
        await page.getByRole("button", { name: "Periksa Kembali" }).first().click().catch(() => { });
        await page.waitForTimeout(300);
        await return_to_pendaftaran_standby(page);

        throw Object.assign(
            new Error("Data peserta tidak valid - pastikan nama, NIK, dan tanggal lahir sesuai KTP/KK."),
            { code: "VALIDASI_TIDAK_VALID" }
        );
    }

    if (result.kind === "tidak_valid_wali")
    {
        await page.getByRole("button", { name: "Periksa Kembali" }).first().click().catch(() => { });
        await page.waitForTimeout(300);
        await return_to_pendaftaran_standby(page);

        throw Object.assign(
            new Error("Data peserta atau wali tidak valid - pastikan data peserta dan wali sesuai KTP/KK."),
            { code: "VALIDASI_PESERTA_WALI_TIDAK_VALID" }
        );
    }

    if (result.kind === "sudah_menerima_layanan")
    {
        const detail = await page.evaluate(() =>
        {
            const norm = (s) => (s || "").replace(/\s+/g, " ").trim();
            const modal = [...document.querySelectorAll("div.rounded-lg.bg-white")]
                .find(el => el.offsetParent && el.textContent.includes("sudah menerima layanan"));
            if (!modal) return {};

            const get_pair = (label) =>
            {
                const rows = [...modal.querySelectorAll("div.flex.gap-1.justify-center")];
                const row = rows.find(r => norm(r.querySelector(".font-bold")?.textContent || "").toLowerCase().includes(label));
                const divs = row?.querySelectorAll("div");
                return divs?.[1] ? norm(divs[1].textContent) : null;
            };

            const nama_el = modal.querySelector("div.text-center.text-\\[14px\\].font-400");
            const nama = norm(nama_el?.textContent || "").replace(/telah selesai diperiksa.*/i, "").trim();

            return {
                nama,
                puskesmas: get_pair("puskesmas"),
                tanggal: get_pair("tanggal"),
                layanan: get_pair("layanan")
            };
        });

        log("INFO", "sudah_menerima_layanan_detail", detail);

        await page.getByRole("button", { name: "Kembali" }).first().click();
        await page.waitForTimeout(300);

        const nik_input = page.locator(NIK_SEL_CSS).first();
        await nik_input.waitFor({ state: "visible", timeout: 5000 });
        await nik_input.fill("");
        await nik_input.dispatchEvent("input");

        throw Object.assign(
            new Error("Peserta sudah pernah menerima layanan CKG sebelumnya."),
            { code: "SUDAH_MENERIMA_LAYANAN", detail }
        );
    }

    await retry(async () =>
    {
        const btn = page.locator("button").filter({ hasText: /^lanjutkan/i }).first();
        await btn.waitFor({ state: "visible", timeout: 5000 });
        await btn.scrollIntoViewIfNeeded();
        await btn.click({ force: true });
        await page.waitForTimeout(250);
        return true;
    }, 3, "click_lanjutkan", page);

    return { kind: "valid" };
}

// ─── FORM STEP 2 ─────────────────────────────────────────────

async function wait_form_step2(page, timeout_ms)
{
    await page.waitForFunction(() =>
    {
        const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
        return [...document.querySelectorAll("div")]
            .some(el => el.offsetParent && norm(el.textContent).includes("isi data pendukung"));
    }, { timeout: timeout_ms });

    await page.locator(SEL.job_trigger_sel).first().waitFor({
        state: "visible",
        timeout: timeout_ms
    });

    await page.locator(SEL.domisili_trigger_sel).first().waitFor({
        state: "visible",
        timeout: timeout_ms
    });

    log("INFO", "form_step2_ready");
    return true;
}

async function fill_form_step2(page, data, timeout_ms)
{
    let last_error;

    for (let attempt = 1; attempt <= 2; attempt++)
    {
        try
        {
            log("INFO", "fill_form_step2_try", { attempt });

            await select_job(page, data.pekerjaan, 10000);
            await select_status_pernikahan(page, data.status_pernikahan, 10000);
            await select_penyandang_disabilitas(page, data.penyandang_disabilitas, 10000);
            await select_domisili(page, data.domisili, 10000);
            await fill_detail_address(page, data.detail_domisili, timeout_ms);

            return true;
        }
        catch (e)
        {
            last_error = e;
            log("WARN", "fill_form_step2_retry", {
                attempt,
                code: e.code || "ERR",
                message: e.message
            });

            await page.waitForTimeout(500);
        }
    }

    throw last_error;
}

async function click_next_submit(page, timeout_ms)
{
    return await retry(async () =>
    {
        const btn = page.locator('button[type="submit"]').filter({ hasText: /selanjutnya/i }).first();
        await btn.waitFor({ state: "visible", timeout: timeout_ms });

        const is_disabled = await btn.evaluate(el =>
            el.disabled ||
            el.classList.contains("cursor-not-allowed") ||
            el.classList.contains("btn-disabled") ||
            el.closest(".cursor-not-allowed") !== null
        );

        if (is_disabled)
        {
            throw Object.assign(
                new Error("Tombol Submit Selanjutnya masih tidak aktif"),
                { code: "NEXT_SUBMIT_DISABLED" }
            );
        }

        await btn.scrollIntoViewIfNeeded();
        await btn.click({ force: true });
        await page.waitForTimeout(300);
        return true;
    }, 3, "click_next_submit", page);
}

// ─── MODAL REGISTRASI ────────────────────────────────────────

async function wait_registration_modal(page, timeout_ms)
{
    const t0 = Date.now();
    const max_wait = Math.min(Math.max(timeout_ms, 6000), 15000);

    while (Date.now() - t0 < max_wait)
    {
        const pre = await check_and_close_known_modal(page, timeout_ms, 250);

        if (pre.found && pre.code === "SUDAH_TERDAFTAR")
            return { kind: "already_registered", status_text: pre.status_text };

        if (pre.found)
            throw Object.assign(new Error(pre.status_text), { code: pre.code });

        const modal =
            await wait_for_modal_by_keywords(page, ["formulir pendaftaran", "list data individu"], 500) ||
            await wait_for_modal_by_keywords(page, ["list data individu"], 300) ||
            await wait_for_modal_by_keywords(page, ["formulir pendaftaran"], 300);

        if (modal)
        {
            const dl = Date.now() + 5000;
            while (Date.now() < dl)
            {
                if (await modal.locator(".td-loading, .shimmer").count() === 0) break;
                await page.waitForTimeout(100);
            }

            log("INFO", "registration_modal_ready");
            return { kind: "registration_modal", modal };
        }

        const loading_visible = await page.evaluate(() =>
        {
            return !![...document.querySelectorAll(".td-loading, .shimmer")]
                .find(el => el.offsetParent);
        }).catch(() => false);

        if (loading_visible)
        {
            await page.waitForTimeout(300);
            continue;
        }

        const has_pick_button = await page.locator("button")
            .filter({ hasText: /^(pilih|dipilih)$/i })
            .first()
            .isVisible({ timeout: 200 })
            .catch(() => false);

        if (has_pick_button)
        {
            const fallback_modal = page.locator("body");
            log("INFO", "registration_modal_fallback_by_button");
            return { kind: "registration_modal", modal: fallback_modal };
        }

        await page.waitForTimeout(250);
    }

    const body_text = await page.locator("body").innerText().catch(() => "");
    log("ERROR", "wait_registration_modal_timeout", {
        body_preview: String(body_text).replace(/\s+/g, " ").trim().slice(0, 500)
    });

    throw Object.assign(
        new Error("Halaman konfirmasi pendaftaran tidak muncul"),
        { code: "FORM_MODAL" }
    );
}

async function click_pick_row(page, modal, timeout_ms)
{
    return await retry(async () =>
    {
        const dl = Date.now() + 5000;
        while (Date.now() < dl)
        {
            if (await modal.locator(".td-loading, .shimmer").count() === 0) break;
            await page.waitForTimeout(200);
        }

        if (await modal.locator("button").filter({ hasText: /^dipilih$/i }).first().isVisible().catch(() => false))
        {
            log("INFO", "row_already_selected");
            return true;
        }

        const btn = modal.locator("button").filter({ hasText: /^pilih$/i }).first();

        if (!await btn.isVisible().catch(() => false))
        {
            throw Object.assign(
                new Error("Baris data peserta tidak ditemukan"),
                { code: "ROW_PICK_BTN" }
            );
        }

        await btn.scrollIntoViewIfNeeded();
        await btn.click({ force: true });
        await page.waitForTimeout(200);
        return true;
    }, 3, "click_pick_row", page);
}

async function click_register_with_nik(page, modal, timeout_ms)
{
    return await retry(async () =>
    {
        const btn = modal.locator("button").filter({ hasText: /daftarkan dengan nik/i }).first();
        await btn.waitFor({ state: "visible", timeout: timeout_ms });
        await btn.scrollIntoViewIfNeeded();
        await btn.click({ force: true });
        await page.waitForTimeout(200);
        return true;
    }, 3, "click_register_with_nik", page);
}

async function handle_wali_form(page, wali_data, timeout_ms)
{
    const wali_modal = page.locator("div.shadow-gmail")
        .filter({ has: page.locator(SEL.wali_nik.join(", ")) }).first();

    if (!await wali_modal.isVisible({ timeout: 700 }).catch(() => false))
    {
        log("INFO", "wali_form_not_found");
        return { skipped: true, type: "no_form" };
    }

    const has_no_wali = await wali_modal
        .locator('input#noWali[name="noWali"][type="checkbox"]')
        .isVisible()
        .catch(() => false);

    if (has_no_wali)
    {
        await wali_modal.evaluate(el =>
        {
            const input = el.querySelector('input#noWali[name="noWali"][type="checkbox"]');
            const check_div = [...el.querySelectorAll("#noWali")]
                .find(x => x !== input && x.classList.contains("check"));

            const target = check_div || input;
            if (!target) return;

            target.scrollIntoView({ block: "center" });
            target.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));

            if (input && !input.checked) input.click();
            input?.dispatchEvent(new Event("change", { bubbles: true }));
        });

        log("INFO", "wali_no_wali_checked");
    }
    else
    {
        await wali_modal.evaluate((el, { nik, nama, sel_nik, sel_nama }) =>
        {
            const set_val = (input, val) =>
            {
                if (!input) return;
                input.focus();
                input.value = val;
                input.dispatchEvent(new Event("input", { bubbles: true }));
                input.dispatchEvent(new Event("change", { bubbles: true }));
                input.blur();
            };

            const find = (sels) => sels.map(s => el.querySelector(s)).find(Boolean);
            set_val(find(sel_nik), nik);
            set_val(find(sel_nama), nama);
        }, {
            nik: wali_data.nik,
            nama: wali_data.nama,
            sel_nik: SEL.wali_nik,
            sel_nama: SEL.wali_nama
        });

        await select_birth_date(page, wali_data.tanggal_lahir, timeout_ms, wali_modal);
        await select_gender(page, wali_data.jenis_kelamin, timeout_ms, wali_modal);

        await wali_modal.evaluate(el =>
        {
            const cb = el.querySelector('input[name="Nomor sama dengan peserta"]');
            if (!cb || cb.checked) return;

            const cd = [...el.querySelectorAll("#phone-sama")]
                .find(x => x.classList.contains("check"));

            cd ? cd.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true })) : cb.click();
        });
    }

    await page.waitForFunction(() =>
    {
        const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();
        const btn = [...document.querySelectorAll('button[type="submit"].btn-fill-primary')]
            .filter(el => el.offsetParent)
            .find(b => norm(b.textContent) === "daftar");
        return btn && !btn.disabled;
    }, { timeout: timeout_ms });

    const submit_btn = page.locator('button[type="submit"].btn-fill-primary').filter({ hasText: /^daftar$/i }).first();

    if (!await submit_btn.isVisible({ timeout: 2000 }).catch(() => false))
    {
        throw Object.assign(
            new Error("Tombol Daftar tidak ditemukan"),
            { code: "WALI_SUBMIT_NOT_FOUND" }
        );
    }

    await submit_btn.scrollIntoViewIfNeeded();
    await submit_btn.click({ force: true });

    const post = await check_and_close_known_modal(page, timeout_ms, 1400);
    if (post.found && post.code !== "SUDAH_TERDAFTAR")
        throw Object.assign(new Error(post.status_text), { code: post.code });

    return { skipped: false, type: has_no_wali ? "no_wali_checkbox" : "full_wali_form" };
}

// ─── MODAL HASIL ─────────────────────────────────────────────

async function wait_result_modal(page, timeout_ms)
{
    const dl = Date.now() + 2500;

    while (Date.now() < dl)
    {
        const known = await check_and_close_known_modal(page, timeout_ms, 200);
        if (known.found) return { kind: "known_modal", code: known.code, status_text: known.status_text };
        await page.waitForTimeout(200);
    }

    const modal =
        await wait_for_modal_by_keywords(page, ["berhasil", "daftar"], 1800) ||
        await wait_for_modal_by_keywords(page, ["individu", "terdaftar"], 700) ||
        await wait_for_modal_by_keywords(page, ["berhasil"], 700);

    if (!modal)
    {
        const last = await check_and_close_known_modal(page, timeout_ms, 900);
        if (last.found) return { kind: "known_modal", code: last.code, status_text: last.status_text };

        throw Object.assign(
            new Error("Hasil pendaftaran tidak muncul"),
            { code: "RESULT_MODAL" }
        );
    }

    return { kind: "result_modal", modal };
}

async function close_result_modal(page, result_payload)
{
    if (result_payload.kind === "known_modal")
        return { reg_code: result_payload.code, reg_text: result_payload.status_text };

    const modal = result_payload.modal;
    const t = norm(await modal.innerText().catch(() => ""));

    const reg_code =
        (t.includes("berhasil") && t.includes("daftar")) ? "TERDAFTAR_BARU"
            : t.includes("sudah terdaftar") ? "SUDAH_TERDAFTAR"
                : t.includes("terdaftar") ? "TERDAFTAR"
                    : "UNKNOWN";

    const btn = modal.locator("button").filter({
        hasText: /^(tutup|ok|kembali)$/i
    }).first();

    if (await btn.isVisible({ timeout: 1200 }).catch(() => false))
        await btn.click({ force: true }).catch(() => { });

    await page.waitForTimeout(120);
    await close_all_modals(page);

    const reg_text_map = {
        TERDAFTAR_BARU: "TERDAFTAR BARU",
        TERDAFTAR: "TERDAFTAR",
        SUDAH_TERDAFTAR: "SUDAH TERDAFTAR",
        UNKNOWN: "UNKNOWN"
    };

    return { reg_code, reg_text: reg_text_map[reg_code] };
}

// ─── ABSENSI ─────────────────────────────────────────────────

async function handle_attendance_modal(page, timeout_ms)
{
    const attendance_modal_selector = "div.shadow-gmail";
    const modal = await wait_for_modal_by_keywords(page, ["tandai hadir"], 7000);
    if (!modal) return { ok: false, code: "ABSEN_MODAL_NOT_FOUND" };

    const verify_input = modal.locator('input#verify[name="verify"][type="checkbox"]').first();
    const verify_check = modal.locator("div#verify.check").first();

    if (await verify_check.isVisible({ timeout: 1200 }).catch(() => false))
        await verify_check.click({ force: true }).catch(() => { });
    else if (await verify_input.isVisible({ timeout: 1200 }).catch(() => false))
        await verify_input.click({ force: true }).catch(() => { });

    await page.waitForFunction((modal_sel) =>
    {
        const root = [...document.querySelectorAll(modal_sel)].find(el => el.offsetParent);
        if (!root) return false;
        const input = root.querySelector('input#verify[name="verify"][type="checkbox"]');
        if (!input) return false;
        const value_ok = String(input.value || "").toLowerCase() === "true";
        return value_ok || input.checked === true;
    }, attendance_modal_selector, { timeout: 4000, polling: 70 }).catch(() => { });

    log("INFO", "attendance_verify_checked");

    await page.waitForFunction((modal_sel) =>
    {
        const root = [...document.querySelectorAll(modal_sel)].find(el => el.offsetParent);
        if (!root) return false;
        const buttons = [...root.querySelectorAll("button")];
        const btn = buttons.find(b => (b.innerText || "").trim().toLowerCase().startsWith("hadir"));
        if (!btn) return false;
        const disabled = btn.disabled || btn.classList.contains("bg-disabled") || btn.classList.contains("cursor-not-allowed");
        return !disabled;
    }, attendance_modal_selector, { timeout: timeout_ms, polling: 70 });

    const hadir_btn = modal.locator("button").filter({ hasText: /^\s*hadir\s*$/i }).first();
    await hadir_btn.click({ force: true });
    log("INFO", "attendance_hadir_clicked");

    await page.getByText("Berhasil Hadir").waitFor({ state: "visible", timeout: 5000 }).catch(() => { });

    const close_btn = page.locator("div.shadow-gmail button.btn-transparent.absolute.right-4.top-3").last();
    if (await close_btn.isVisible({ timeout: 1800 }).catch(() => false))
    {
        await close_btn.click({ force: true }).catch(() => { });
        log("INFO", "attendance_modal_closed");
    }
    else
    {
        const tutup_btn = page.getByRole("button", { name: "Tutup" }).filter({ hasText: /^Tutup/ }).last();
        if (await tutup_btn.isVisible({ timeout: 1200 }).catch(() => false))
            await tutup_btn.click({ force: true }).catch(() => { });
        else
            await close_all_modals(page);
    }

    return { ok: true, code: "ABSEN_BARU" };
}

async function confirm_attendance_by_nik(page, nik, timeout_ms)
{
    if (!await page.locator(".table-individu-terdaftar").isVisible({ timeout: 2000 }).catch(() => false))
        return { ok: true, code: "SKIP_NOT_ON_LIST_PAGE" };

    const search = page.getByPlaceholder("Masukkan nomor tiket");
    await search.waitFor({ state: "visible", timeout: timeout_ms });
    await search.fill(nik);
    await search.press("Enter");
    await page.waitForTimeout(1500);

    const body_text = norm(await page.locator("body").innerText().catch(() => ""));

    if (body_text.includes("sudah hadir"))
    {
        await clear_search(page);
        return { ok: true, code: "SUDAH_ABSEN" };
    }

    if (body_text.includes("tidak ditemukan") || body_text.includes("tidak ada data") || body_text.includes("no data"))
    {
        await clear_search(page);
        throw Object.assign(new Error("NOT_IN_LIST"), { code: "NOT_IN_LIST" });
    }

    const konfirmasi = page.getByRole("button", { name: "Konfirmasi Hadir" }).first();
    if (!await konfirmasi.isVisible({ timeout: 3000 }).catch(() => false))
    {
        await clear_search(page);
        return { ok: true, code: "SKIP_NO_KONFIRMASI_BTN" };
    }

    await konfirmasi.click();

    const result = await handle_attendance_modal(page, timeout_ms);
    await clear_search(page);
    return result;
}

async function clear_search(page)
{
    const btn = page.locator("button.bg-error").first();
    if (await btn.isVisible({ timeout: 500 }).catch(() => false))
        await btn.click();
}

async function resolve_attendance(page, nik, timeout_ms)
{
    const map = {
        SUDAH_ABSEN: "SUDAH ABSEN",
        ABSEN_BARU: "ABSEN BARU",
        SKIP_NOT_ON_LIST_PAGE: "SKIP: NOT ON LIST PAGE",
        SKIP_NO_KONFIRMASI_BTN: "SKIP: NO KONFIRMASI BTN"
    };

    try
    {
        const a = await confirm_attendance_by_nik(page, nik, timeout_ms);
        return { code: a.code, text: map[a.code] || a.code };
    }
    catch (e)
    {
        log("ERROR", "attendance_error", {
            nik,
            code: e?.code || "ERR",
            message: e?.message || ""
        });

        if (e.code === "NOT_IN_LIST")
            return { code: "NOT_IN_LIST", text: "SKIP: SUDAH MASUK PELAYANAN (TIDAK ADA DI LIST)" };

        return { code: "ERROR_ABSEN", text: `ERROR_ABSEN: ${e.code || "ERR"}` };
    }
}

export async function run_one_user(page, data, wali_data, timeout_ms)
{
    await accept_privacy_if_present(page, timeout_ms).catch(() => {});
    await close_all_modals(page);
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" }));
    const t0 = Date.now();

    const step1_base = await run_steps([
        { name: "click_daftar_baru", mandatory: true, run: () => click_daftar_baru(page, timeout_ms) },
        { name: "fill_nik_only", mandatory: true, run: () => fill_nik_only(page, data.nik, timeout_ms) },
        { name: "fill_form_step1", mandatory: true, run: () => fill_form_step1(page, data, wali_data, timeout_ms) }
    ]);

    if (!step1_base.ok)
        return make_error_return(t0, step1_base.stopped.error);

    const step1_next = await run_steps([
        { name: "click_next_button", mandatory: true, run: () => click_next_button(page, timeout_ms) },
        { name: "handle_verifikasi", mandatory: true, run: () => handle_verifikasi(page, timeout_ms) },
        { name: "wait_form_step2", mandatory: true, run: () => wait_form_step2(page, timeout_ms) },
        { name: "fill_form_step2", mandatory: true, run: () => fill_form_step2(page, data, timeout_ms) },
        { name: "click_next_submit", mandatory: true, run: () => click_next_submit(page, timeout_ms) },
        { name: "wait_registration_modal", mandatory: true, run: () => wait_registration_modal(page, timeout_ms) }
    ]);

    if (!step1_next.ok)
    {
        const stopped_code = step1_next.stopped.error?.code || "ERR";

        if (stopped_code === "SUDAH_MENERIMA_LAYANAN")
        {
            const layanan_detail = step1_next.stopped.error.detail || null;
            const layanan_text = layanan_detail?.puskesmas
                ? `SUDAH MENERIMA LAYANAN (Puskesmas ${layanan_detail.puskesmas})`
                : "SUDAH MENERIMA LAYANAN";

            return {
                ok: true,
                duration_ms: Date.now() - t0,
                reg: {
                    code: "SUDAH_MENERIMA_LAYANAN",
                    text: layanan_text,
                    detail: layanan_detail
                },
                attendance: { code: "SKIP", text: "SKIP" }
            };
        }

        if (stopped_code === "VALIDASI_DATA_PASIEN_GAGAL")
        {
            log("WARN", "validasi_api_timeout_skip", { nik: data.nik });

            return {
                ok: false,
                duration_ms: Date.now() - t0,
                reg: {
                    code: "VALIDASI_DATA_PASIEN_GAGAL",
                    text: "VALIDASI DATA PASIEN GAGAL (TIMEOUT)"
                },
                attendance: { code: "SKIP", text: "SKIP" }
            };
        }

        if (stopped_code === "VALIDASI_TIDAK_VALID")
        {
            return {
                ok: true,
                duration_ms: Date.now() - t0,
                reg: { code: "VALIDASI_TIDAK_VALID", text: "DATA PESERTA TIDAK VALID" },
                attendance: { code: "SKIP", text: "SKIP" }
            };
        }

        if (stopped_code === "VALIDASI_PESERTA_WALI_TIDAK_VALID")
        {
            return {
                ok: true,
                duration_ms: Date.now() - t0,
                reg: { code: "VALIDASI_PESERTA_WALI_TIDAK_VALID", text: "DATA PESERTA ATAU WALI TIDAK VALID" },
                attendance: { code: "SKIP", text: "SKIP" }
            };
        }

        return make_error_return(t0, step1_next.stopped.error);
    }

    const reg_payload = step1_next.results.find(r => r.name === "wait_registration_modal")?.out;

    if (reg_payload?.kind === "already_registered")
    {
        const attendance = await resolve_attendance(page, data.nik, timeout_ms);
        await clear_nik_field(page);

        return {
            ok: true,
            duration_ms: Date.now() - t0,
            reg: { code: "SUDAH_TERDAFTAR", text: reg_payload.status_text },
            attendance
        };
    }

    const step2 = await run_steps([
        { name: "click_pick_row", mandatory: true, run: () => click_pick_row(page, reg_payload.modal, timeout_ms) },
        { name: "click_register_with_nik", mandatory: true, run: () => click_register_with_nik(page, reg_payload.modal, timeout_ms) },
        { name: "handle_wali_form", mandatory: false, run: () => handle_wali_form(page, wali_data, timeout_ms) },
        { name: "wait_result_modal", mandatory: true, run: () => wait_result_modal(page, timeout_ms) }
    ]);

    if (!step2.ok)
        return make_error_return(t0, step2.stopped.error);

    const result_payload = step2.results.find(r => r.name === "wait_result_modal")?.out;
    const { reg_code, reg_text } = await close_result_modal(page, result_payload);
    const reg = { code: reg_code, text: reg_text };

    const can_attend = new Set(["TERDAFTAR_BARU", "TERDAFTAR", "SUDAH_TERDAFTAR"]);
    const attendance = can_attend.has(reg.code)
        ? await resolve_attendance(page, data.nik, timeout_ms)
        : { code: "SKIP", text: "SKIP" };

    const completed = reg.code === "TERDAFTAR_BARU"
        ? attendance.code === "ABSEN_BARU" || attendance.code === "SUDAH_ABSEN"
        : true;

    await clear_nik_field(page);

    return {
        ok: true,
        duration_ms: Date.now() - t0,
        reg,
        attendance,
        completed
    };
}
