import fs from "fs";
import path from "path";
import { log, goto_url } from "../core/helpers.js";
import { ensure_session_active } from "../core/auth.js";
import { handle_pendaftaran } from "./pendaftaran/handler.js";
import { handle_pelayanan } from "./pelayanan/handler.js";
import { ensure_profile_saved } from "./pelayanan/flow.js";

function resolve_cookies_file()
{
    const storage_dir = String(process.env.STORAGE_DIR || "").trim();
    if (storage_dir === "")
        return "";
    return path.join(storage_dir, "cookies.json");
}

function resolve_profile_url(config)
{
    const direct_profile_url = String(config?.urls?.profile || "").trim();
    if (direct_profile_url !== "")
        return direct_profile_url;

    const home_url = String(config?.urls?.home || "").trim();
    if (home_url === "")
        return "https://sehatindonesiaku.kemkes.go.id/profile";

    try
    {
        const u = new URL(home_url);
        return `${u.protocol}//${u.host}/profile`;
    }
    catch
    {
        return "https://sehatindonesiaku.kemkes.go.id/profile";
    }
}

async function safe_goto(page, url, timeout_ms, cookies_file)
{
    try
    {
        await goto_url(page, url, timeout_ms);
    }
    catch (error)
    {
        if (error?.message?.includes("ERR_ABORTED"))
        {
            log("WARN", "navigation_aborted_clearing_session", { url, error: error.message });
            if (cookies_file && fs.existsSync(cookies_file))
            {
                try { fs.unlinkSync(cookies_file); } catch (e) { }
            }
            throw new Error("Sesi habis atau navigasi dibatalkan browser (ERR_ABORTED). Sesi telah dihapus untuk login ulang.");
        }
        throw error;
    }
}

export async function run_ckg_umum(page, action, config)
{
    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000);
    const cookies_file = resolve_cookies_file();

    log("INFO", "ckg_umum_start", {
        action,
        mode: config?.meta?.mode,
        puskesmas: config?.meta?.puskesmas,
    });

    page.on("dialog", async (dialog) =>
    {
        log("WARN", "unexpected_dialog_dismissed", { message: dialog.message() });
        await dialog.dismiss().catch(() => { });
    });

    if (action === "pendaftaran")
    {
        const url = config?.urls?.ckg_umum?.pendaftaran;
        if (!url) throw new Error("URL pendaftaran CKG-UMUM tidak ada di config");

        log("INFO", "navigating_to_pendaftaran", { url });
        await safe_goto(page, url, timeout_ms, cookies_file);
        await ensure_session_active(page, page.context(), config, cookies_file);
        await handle_pendaftaran(page, config);

    } else if (action === "pelayanan")
    {
        const url = config?.urls?.ckg_umum?.pelayanan;
        const profile_url = resolve_profile_url(config);
        if (!url) throw new Error("URL pelayanan CKG-UMUM tidak ada di config");

        if (profile_url !== "")
        {
            log("INFO", "navigating_to_profile_for_verification", { url: profile_url });
            await safe_goto(page, profile_url, timeout_ms, cookies_file);
            await ensure_session_active(page, page.context(), config, cookies_file);
            await ensure_profile_saved(page, timeout_ms);
        }

        log("INFO", "navigating_to_pelayanan", { url });
        await safe_goto(page, url, timeout_ms, cookies_file);
        await ensure_session_active(page, page.context(), config, cookies_file);
        await handle_pelayanan(page, config);

    } else
    {
        throw new Error(`Action tidak valid: ${action}. Harus 'pendaftaran' atau 'pelayanan'`);
    }

    log("INFO", "ckg_umum_done", { action });
}
