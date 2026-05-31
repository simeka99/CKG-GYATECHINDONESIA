import { log } from "../core/helpers.js";

export async function handle_pendaftaran(page, config)
{
    log("INFO", "pendaftaran_sekolah_handler_start");

    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000);

    await page.waitForLoadState("load", { timeout: Math.min(timeout_ms, 15000) }).catch(() => { });

    log("INFO", "halaman_pendaftaran_anak_sekolah_ready", { url: page.url() });
    log("INFO", "browser_siap_digunakan_untuk_pendaftaran_sekolah");
}
