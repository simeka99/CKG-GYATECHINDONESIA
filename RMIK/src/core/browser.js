import { chromium } from "playwright";
import { file_exists, log } from "./helpers.js";
import { launch_chromium_browser, get_browser_runtime_config, get_browser_attempts, describe_browser_attempt } from "./browser_launch.js";
import fs from "fs";
import path from "path";

let browser_instance = null;
let context_instance = null;
let page_instance = null;

async function launch_persistent_context(config, launch_options, hooks = {})
{
    const runtime_config = get_browser_runtime_config(config);
    const attempts = get_browser_attempts(runtime_config);
    const storage_dir = String(process.env.STORAGE_DIR || "").trim() || path.join(process.cwd(), "storage");
    const custom_profile_dir = String(config?.browser?.profile_dir || "").trim();
    const profile_dir = custom_profile_dir !== ""
        ? custom_profile_dir
        : path.join(storage_dir, `browser_profile_${runtime_config.channel || "msedge"}`);
    fs.mkdirSync(profile_dir, { recursive: true });

    let last_error = null;
    for (const attempt of attempts)
    {
        const label = describe_browser_attempt(attempt);
        try
        {
            hooks.on_attempt?.(attempt, label);
            const context = await chromium.launchPersistentContext(profile_dir, {
                ...launch_options,
                ...(attempt.type === "channel" ? { channel: attempt.channel } : {})
            });
            hooks.on_success?.(attempt, label);
            return {
                context,
                browser: context.browser(),
                browser_label: label,
                browser_attempt: attempt,
                runtime_config,
                profile_dir
            };
        }
        catch (error)
        {
            last_error = error;
            hooks.on_failure?.(attempt, label, error);
        }
    }

    const message = String(last_error?.message || last_error || "Browser launch failed");
    throw new Error(`Tidak dapat membuka browser persistent. Detail: ${message}`);
}

export async function init_browser(config, cookies_file, force_headless = null)
{
    const timeout_ms = Number(config?.browser?.timeout_ms ?? 120000);
    const slow_mo_ms = Number(config?.browser?.slow_mo_ms ?? 0);
    const viewport = config?.browser?.viewport || { width: 1280, height: 720 };
    const headless = force_headless !== null ? force_headless : Boolean(config?.browser?.headless ?? true);
    const has_session = file_exists(cookies_file);
    const use_persistent_profile = Boolean(config?.browser?.persistent_profile ?? false);

    if (browser_instance && browser_instance.isConnected() && page_instance && !page_instance.isClosed())
    {
        log("INFO", "browser_already_connected_reusing");
        return { browser: browser_instance, context: context_instance, page: page_instance, timeout_ms };
    }
    
    await close_browser();

    log("INFO", "browser_init", { headless, has_session, persistent_profile: use_persistent_profile });

    const args = [
        "--disable-dev-shm-usage",
        "--no-sandbox",
        "--disable-setuid-sandbox",
        "--js-flags=--max-old-space-size=512"
    ];

    if (headless)
    {
        args.push(
            "--disable-gpu",
            "--disable-software-rasterizer",
            "--disable-extensions",
            "--mute-audio"
        );
    }

    if (use_persistent_profile)
    {
        const launched = await launch_persistent_context(config, { headless, slowMo: slow_mo_ms, args, viewport }, {
            on_attempt: (attempt, label) =>
            {
                log("INFO", "browser_launch_attempt", { target: label, mode: attempt?.type || "bundled", profile: "persistent" });
            },
            on_failure: (attempt, label, error) =>
            {
                log("WARN", "browser_launch_failed", { target: label, error: error?.message || String(error) });
            }
        });

        browser_instance = launched.browser;
        context_instance = launched.context;
        log("INFO", "browser_launched", { target: launched.browser_label, profile_dir: launched.profile_dir });
        const pages = context_instance.pages();
        page_instance = pages.length > 0 ? pages[0] : await context_instance.newPage();
    }
    else
    {
        const launched = await launch_chromium_browser(chromium, config, { headless, slowMo: slow_mo_ms, args }, {
            on_attempt: (attempt, label) =>
            {
                log("INFO", "browser_launch_attempt", { target: label, mode: attempt?.type || "bundled" });
            },
            on_failure: (attempt, label, error) =>
            {
                log("WARN", "browser_launch_failed", { target: label, error: error?.message || String(error) });
            }
        });

        browser_instance = launched.browser;
        log("INFO", "browser_launched", { target: launched.browser_label });
        context_instance = await browser_instance.newContext({
            viewport,
            storageState: has_session ? cookies_file : undefined,
        });
        page_instance = await context_instance.newPage();
    }

    return { browser: browser_instance, context: context_instance, page: page_instance, timeout_ms };
}

export async function close_browser()
{
    if (context_instance)
    {
        await context_instance.close().catch(() => { });
        context_instance = null;
        page_instance = null;
    }
    if (browser_instance)
    {
        await browser_instance.close().catch(() => { });
        browser_instance = null;
        log("INFO", "browser_closed");
    }
}
