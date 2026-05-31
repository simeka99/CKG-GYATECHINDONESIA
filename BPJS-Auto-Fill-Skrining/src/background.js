const API_BASE = "https://rmik.gyatechindonesia.com/api";
const STORAGE_KEYS = {
    session: "rmik_extension_session",
    device: "rmik_extension_device_id",
};

function storageGet(key)
{
    return chrome.storage.local.get(key).then((data) => data[key]);
}

function storageSet(values)
{
    return chrome.storage.local.set(values);
}

function storageRemove(keys)
{
    return chrome.storage.local.remove(keys);
}

async function sha256Hex(text)
{
    const bytes = new TextEncoder().encode(String(text || ""));
    const digest = await crypto.subtle.digest("SHA-256", bytes);
    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, "0"))
        .join("");
}

async function getOrCreateDeviceId()
{
    const existing = await storageGet(STORAGE_KEYS.device);
    if (existing) return existing;

    const seed = [
        crypto.randomUUID ? crypto.randomUUID() : String(Date.now()),
        navigator.userAgent || "extension",
        navigator.platform || "unknown",
        Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
    ].join("|");

    const deviceId = await sha256Hex(seed);
    await storageSet({ [STORAGE_KEYS.device]: deviceId });
    return deviceId;
}

async function getDeviceProfileHash()
{
    const seeds = [
        navigator.userAgent || "ua",
        navigator.platform || "platform",
        navigator.language || "lang",
        Array.isArray(navigator.languages) ? navigator.languages.join(",") : "",
        String(navigator.hardwareConcurrency || ""),
        String(navigator.deviceMemory || ""),
        Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
    ];

    try
    {
        const platformInfo = await chrome.runtime.getPlatformInfo();
        if (platformInfo?.os) seeds.push(platformInfo.os);
        if (platformInfo?.arch) seeds.push(platformInfo.arch);
        if (platformInfo?.nacl_arch) seeds.push(platformInfo.nacl_arch);
    } catch
    {
    }

    return sha256Hex(seeds.join("|"));
}

function normalizeLicenseKey(raw)
{
    return String(raw || "").trim().toUpperCase();
}

async function parseJsonSafe(response)
{
    try
    {
        return await response.json();
    } catch
    {
        return null;
    }
}

async function requestLicenseLogin(licenseKey, deviceId, deviceProfile)
{
    const profileHash = String(deviceProfile || "").trim().toLowerCase();
    const response = await fetch(`${API_BASE}/license/login.php`, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-License-Key": licenseKey,
            "X-Device-Id": deviceId,
            "X-Device-Profile": profileHash,
        },
        body: new URLSearchParams({
            device_id: deviceId,
            device_profile: profileHash,
        }),
    });

    const body = await parseJsonSafe(response);
    if (!response.ok || !body?.ok)
    {
        return {
            ok: false,
            code: body?.code || `HTTP_${response.status}`,
            error: body?.error || `HTTP ${response.status}`,
        };
    }

    const payload = body?.data && typeof body.data === "object" ? body.data : body;
    return {
        ok: true,
        data: {
            license_key: licenseKey,
            device_id: deviceId,
            device_profile: profileHash,
            username: payload?.username || "",
            full_name: payload?.full_name || "",
            pc_label: payload?.pc_label || "",
            mode: payload?.mode || "",
            task_type: payload?.task_type || "",
            subscription_type: payload?.subscription_type || "",
            update_channel: payload?.update_channel || "public",
            validated_at: new Date().toISOString(),
        },
    };
}

async function saveSession(session)
{
    await storageSet({ [STORAGE_KEYS.session]: session });
    return session;
}

async function clearSession()
{
    await storageRemove([STORAGE_KEYS.session]);
    return { ok: true };
}

async function loginWithLicense(rawLicenseKey)
{
    const licenseKey = normalizeLicenseKey(rawLicenseKey);
    if (!licenseKey)
    {
        return { ok: false, error: "License key wajib diisi." };
    }

    const deviceId = await getOrCreateDeviceId();
    const deviceProfile = await getDeviceProfileHash();
    const result = await requestLicenseLogin(licenseKey, deviceId, deviceProfile);

    if (!result.ok)
    {
        return result;
    }

    await saveSession(result.data);
    return result;
}

async function revalidateSession()
{
    const session = await storageGet(STORAGE_KEYS.session);
    if (!session?.license_key)
    {
        return { ok: false, code: "NO_SESSION", error: "Belum login lisensi." };
    }

    const result = await loginWithLicense(session.license_key);
    if (!result.ok && ["LICENSE_REVOKED", "DEVICE_MISMATCH", "NO_KEY", "SUBSCRIPTION_EXPIRED", "SUBSCRIPTION_NOT_SET", "QUOTA_EMPTY"].includes(result.code || ""))
    {
        await clearSession();
    }
    return result;
}

async function getSession()
{
    const session = await storageGet(STORAGE_KEYS.session);
    if (!session)
    {
        return { ok: false, error: "Belum login." };
    }
    return { ok: true, data: session };
}

chrome.runtime.onInstalled.addListener(() =>
{
    getOrCreateDeviceId().catch(() => { });
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) =>
{
    if (message?.type !== "rmik_auth") return false;

    (async () =>
    {
        try
        {
            switch (message.action)
            {
                case "get_device_id":
                    sendResponse({ ok: true, data: { device_id: await getOrCreateDeviceId() } });
                    break;
                case "get_session":
                    sendResponse(await getSession());
                    break;
                case "login":
                    sendResponse(await loginWithLicense(message.license_key));
                    break;
                case "revalidate":
                    sendResponse(await revalidateSession());
                    break;
                case "logout":
                    await clearSession();
                    sendResponse({ ok: true });
                    break;
                default:
                    sendResponse({ ok: false, error: "Aksi tidak dikenal." });
                    break;
            }
        } catch (error)
        {
            sendResponse({
                ok: false,
                error: error?.message || "Terjadi kesalahan pada background extension.",
            });
        }
    })();

    return true;
});
