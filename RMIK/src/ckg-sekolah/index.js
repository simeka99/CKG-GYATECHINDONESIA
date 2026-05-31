import { log, goto_url } from "../core/helpers.js";
import { ensure_session_active } from "../core/auth.js";
import { handle_pendaftaran } from "./pendaftaran.js";
import { handle_pelayanan } from "./pelayanan.js";

export async function run_ckg_sekolah(page, action, config)
{
    log("INFO", "ckg_sekolah_module_start", { action });

    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000);

    if (action === "pendaftaran")
    {
        const url = config?.urls?.ckg_sekolah?.pendaftaran;
        if (!url) throw new Error("URL pendaftaran CKG-SEKOLAH tidak ada di config");

        log("INFO", "navigating_to_pendaftaran_sekolah", { url });
        await goto_url(page, url, timeout_ms);
        await ensure_session_active(page, page.context(), config);
        await handle_pendaftaran(page, config);
    }
    else if (action === "pelayanan")
    {
        const url = config?.urls?.ckg_sekolah?.pelayanan;
        if (!url) throw new Error("URL pelayanan CKG-SEKOLAH tidak ada di config");

        log("INFO", "navigating_to_pelayanan_sekolah", { url });
        await goto_url(page, url, timeout_ms);
        await ensure_session_active(page, page.context(), config);
        await handle_pelayanan(page, config);
    }
    else
    {
        throw new Error(`Action tidak valid: ${action}. Harus 'pendaftaran' atau 'pelayanan'`);
    }

    log("INFO", "ckg_sekolah_module_done");
}
