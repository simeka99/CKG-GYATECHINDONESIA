import { log } from "../../core/helpers.js";

const norm = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();

const MODAL_SEL = "div.shadow-gmail";

const MODAL_RULES = [
    {
        code: "SUDAH_TERDAFTAR",
        match: (t) =>
            t.includes("individu sudah terdaftar") ||
            t.includes("sudah terdaftar dengan detail") ||
            (t.includes("sudah terdaftar") && t.includes("puskesmas terdaftar")),
        status: (raw) =>
        {
            const date_match = (String(raw).match(/\d{4}-\d{2}-\d{2}/) || [])[0] || "";
            const pusk_match = String(raw).match(/puskesmas terdaftar:\s*([^\n]+)/i);
            const pusk_name = pusk_match?.[1]?.trim() || "";
            const extra = [pusk_name ? `Puskesmas ${pusk_name}` : "", date_match].filter(Boolean).join(" | ");
            return extra ? `SUDAH TERDAFTAR (${extra})` : "SUDAH TERDAFTAR";
        },
        close: ["Tutup", "Kembali", "Ok", "OK"]
    },
    {
        code: "SUDAH_MENERIMA_LAYANAN",
        match: (t) =>
            t.includes("individu sudah menerima layanan") ||
            t.includes("sudah menerima layanan"),
        status: (raw) =>
        {
            const name_match = String(raw).match(/([A-Za-z\s]+)\s+telah selesai diperiksa/i);
            const nama = name_match?.[1]?.trim() || "";
            return nama ? `SUDAH MENERIMA LAYANAN (${nama})` : "SUDAH MENERIMA LAYANAN";
        },
        close: ["Kembali", "Tutup", "Ok", "OK"]
    },
    {
        code: "DUKCAPIL_UPDATE",
        match: (t) =>
            t.includes("pembaharuan data identitas") ||
            (t.includes("terjadi kesalahan") && t.includes("dukcapil")),
        status: () => "ERROR: DUKCAPIL - UPDATE IDENTITAS",
        close: ["Ok", "OK", "Tutup", "Kembali"]
    },
    {
        code: "SISTEM_MENOLAK",
        match: (t) => t.includes("permintaan anda tidak dapat kami penuhi"),
        status: () => "ERROR: SISTEM MENOLAK - CEK DATA",
        close: ["Ok", "OK", "Tutup", "Kembali"]
    },
    {
        code: "DATA_TIDAK_DITEMUKAN",
        match: (t) =>
            t.includes("data tidak ditemukan") ||
            (t.includes("tidak ditemukan") && t.includes("data")),
        status: () => "ERROR: DATA TIDAK DITEMUKAN",
        close: ["Ok", "OK", "Tutup", "Kembali"]
    },
    {
        code: "DUKCAPIL",
        match: (t) => t.includes("dukcapil"),
        status: () => "ERROR: DUKCAPIL - VALIDASI GAGAL",
        close: ["Ok", "OK", "Tutup", "Kembali"]
    },
    {
        code: "ERROR_MODAL",
        match: (t) => t.includes("terjadi kesalahan"),
        status: () => "ERROR: TERJADI KESALAHAN",
        close: ["Ok", "OK", "Tutup", "Kembali"]
    }
];

export function classify_modal(raw_text)
{
    const t = norm(raw_text);
    return MODAL_RULES.find(r => r.match(t)) || null;
}

async function close_modal(page, modal_locator, button_labels)
{
    for (const label of button_labels)
    {
        const btn = modal_locator.getByRole("button", { name: label, exact: false }).first();
        if (await btn.isVisible().catch(() => false))
        {
            await btn.scrollIntoViewIfNeeded().catch(() => { });
            await btn.click({ force: true }).catch(() => { });
            return true;
        }
    }

    const fallback_btn = modal_locator.locator("button").filter({
        hasText: /^(tutup|ok|kembali)$/i
    }).first();

    if (await fallback_btn.isVisible().catch(() => false))
    {
        await fallback_btn.scrollIntoViewIfNeeded().catch(() => { });
        await fallback_btn.click({ force: true }).catch(() => { });
        return true;
    }

    const x_btn = modal_locator.locator("button.btn-transparent").first();
    if (await x_btn.isVisible().catch(() => false))
    {
        await x_btn.click({ force: true }).catch(() => { });
        return true;
    }

    return false;
}

async function click_quota_continue_modal(modal_locator, raw_text = "")
{
    const text = norm(raw_text);
    if (!text.includes("kuota pemeriksaan habis") && !text.includes("melanjutkan pendaftaran peserta"))
        return false;

    const lanjut_btn = modal_locator.locator("button").filter({ hasText: /^\s*lanjut\s*$/i }).first();
    if (await lanjut_btn.isVisible().catch(() => false))
    {
        await lanjut_btn.scrollIntoViewIfNeeded().catch(() => { });
        await lanjut_btn.click({ force: true }).catch(() => { });
        return true;
    }

    return false;
}

export async function close_all_modals(page)
{
    for (let round = 0; round < 6; round++)
    {
        const modal_list = page.locator(MODAL_SEL);
        const modal_count = await modal_list.count();

        if (modal_count === 0) break;

        for (let i = 0; i < modal_count; i++)
        {
            const modal = modal_list.nth(i);
            if (!await modal.isVisible().catch(() => false)) continue;
            await close_modal(page, modal, ["Tutup", "Ok", "OK", "Kembali"]);
        }

        await page.waitForFunction(
            (sel) => [...document.querySelectorAll(sel)].every(el => !el.offsetParent),
            MODAL_SEL,
            { timeout: 1500 }
        ).catch(() => { });
    }
}

export async function check_and_close_known_modal(page, timeout_ms = 5000, wait_ms = 800)
{
    try
    {
        await page.waitForSelector(MODAL_SEL, { state: "visible", timeout: wait_ms });
    }
    catch
    {
        return { found: false };
    }

    const modal_list = page.locator(MODAL_SEL);
    const modal_count = await modal_list.count();

    for (let i = 0; i < modal_count; i++)
    {
        const modal = modal_list.nth(i);
        if (!await modal.isVisible().catch(() => false)) continue;

        const raw_text = await modal.innerText().catch(() => "");
        if (await click_quota_continue_modal(modal, raw_text))
        {
            await page.waitForTimeout(150).catch(() => { });
            continue;
        }
        const rule = classify_modal(raw_text);

        if (!rule) continue;

        await close_modal(page, modal, rule.close);
        await close_all_modals(page);

        return {
            found: true,
            code: rule.code,
            status_text: rule.status(raw_text)
        };
    }

    return { found: false };
}

export async function wait_for_modal_by_keywords(page, keywords, timeout_ms = 2200)
{
    try
    {
        await page.waitForFunction(
            ({ sel, kws }) =>
            {
                const normalize = (s) => (s || "").toLowerCase().replace(/\s+/g, " ").trim();

                return [...document.querySelectorAll(sel)]
                    .filter(el => el.offsetParent)
                    .some(m =>
                    {
                        const text = normalize(m.innerText || "");
                        return kws.every(k => text.includes(normalize(k)));
                    });
            },
            { sel: MODAL_SEL, kws: keywords },
            { timeout: timeout_ms, polling: 100 }
        );
    }
    catch
    {
        return null;
    }

    const modal_list = page.locator(MODAL_SEL);
    const modal_count = await modal_list.count();

    for (let i = 0; i < modal_count; i++)
    {
        const modal = modal_list.nth(i);
        if (!await modal.isVisible().catch(() => false)) continue;

        const text = norm(await modal.innerText().catch(() => ""));
        if (keywords.every(k => text.includes(norm(k))))
            return modal;
    }

    return null;
}

export async function wait_verifikasi_modal(page, timeout_ms = 12000)
{
    const deadline = Date.now() + timeout_ms;

    while (Date.now() < deadline)
    {
        const modal_list = page.locator(MODAL_SEL);
        const modal_count = await modal_list.count().catch(() => 0);

        for (let i = 0; i < modal_count; i++)
        {
            const modal = modal_list.nth(i);
            if (!await modal.isVisible({ timeout: 100 }).catch(() => false))
                continue;

            const text = norm(await modal.innerText().catch(() => ""));
            if (!text)
                continue;

            if (text.includes("kuota pemeriksaan habis") && text.includes("melanjutkan pendaftaran peserta"))
            {
                const lanjut_btn = modal.locator("button").filter({ hasText: /^\s*lanjut\s*$/i }).first();
                if (await lanjut_btn.isVisible({ timeout: 250 }).catch(() => false))
                {
                    await lanjut_btn.scrollIntoViewIfNeeded().catch(() => { });
                    await lanjut_btn.click({ force: true }).catch(() => { });
                    log("WARN", "kuota_habis_lanjut_clicked", { source: "wait_verifikasi_modal" });
                    await page.waitForTimeout(220).catch(() => { });
                    continue;
                }

                log("WARN", "kuota_habis_lanjut_not_found", { source: "wait_verifikasi_modal" });
                continue;
            }

            if (text.includes("data peserta atau wali tidak valid"))
            {
                log("INFO", "verifikasi_detected", { kind: "tidak_valid_wali" });
                return { kind: "tidak_valid_wali" };
            }

            if (text.includes("data peserta tidak valid"))
            {
                log("INFO", "verifikasi_detected", { kind: "tidak_valid" });
                return { kind: "tidak_valid" };
            }

            if (text.includes("sudah menerima layanan"))
            {
                log("INFO", "verifikasi_detected", { kind: "sudah_menerima_layanan" });
                return { kind: "sudah_menerima_layanan" };
            }

            if (text.includes("data peserta valid"))
            {
                log("INFO", "verifikasi_detected", { kind: "valid" });
                return { kind: "valid" };
            }
        }

        await page.waitForTimeout(100).catch(() => { });
    }

    throw Object.assign(
        new Error("Verifikasi tidak muncul - coba periksa koneksi atau reload halaman."),
        { code: "VERIFIKASI_TIMEOUT" }
    );
}
const STATUS_RULES = [
    { when: (o) => o.reg?.code === "SUDAH_TERDAFTAR", text: (o) => `${o.reg.text} | ${o.attendance.text}` },
    { when: (o) => o.reg?.code === "SUDAH_MENERIMA_LAYANAN", text: (o) => o.reg.text || "SUDAH MENERIMA LAYANAN" },
    { when: (o) => o.reg?.code === "TERDAFTAR_BARU", text: (o) => `TERDAFTAR BARU | ${o.attendance.text}` },
    { when: (o) => o.reg?.code === "TERDAFTAR", text: (o) => `TERDAFTAR | ${o.attendance.text}` },
    { when: (o) => o.reg?.code === "VALIDASI_TIDAK_VALID", text: () => "GAGAL: Data peserta tidak valid - pastikan nama, NIK, dan tanggal lahir sesuai KTP/KK" },
    { when: (o) => o.reg?.code === "VALIDASI_PESERTA_WALI_TIDAK_VALID", text: () => "GAGAL: Data peserta atau wali tidak valid - pastikan data peserta dan wali sesuai KTP/KK" },
    { when: (o) => o.reg?.code === "DUKCAPIL_UPDATE", text: () => "GAGAL: Data Dukcapil perlu diperbarui" },
    { when: (o) => o.reg?.code === "SISTEM_MENOLAK", text: () => "GAGAL: Sistem menolak - peserta tidak memenuhi syarat CKG" },
    { when: (o) => o.reg?.code === "DATA_TIDAK_DITEMUKAN", text: () => "GAGAL: Data peserta tidak ditemukan di sistem" },
    { when: (o) => o.reg?.code === "DUKCAPIL", text: () => "GAGAL: Verifikasi Dukcapil gagal - cek NIK dan nama peserta" },
    { when: (o) => o.reg?.code === "ERROR_MODAL", text: () => "GAGAL: Terjadi kesalahan pada sistem - coba ulangi beberapa saat lagi" },
    { when: (o) => o.reg?.code === "RESULT_MODAL", text: () => "GAGAL: Hasil pendaftaran tidak terbaca - periksa secara manual" },
    { when: (o) => o.reg?.code === "ERROR", text: (o) => o.reg.text || "GAGAL: Error tidak diketahui" },
    { when: (o) => !o.ok, text: (o) => o.reg?.text || "GAGAL: Error tidak diketahui" }
];

export function derive_status_text(out)
{
    const rule = STATUS_RULES.find(r => r.when(out));
    return rule ? rule.text(out) : "SUCCESS";
}

