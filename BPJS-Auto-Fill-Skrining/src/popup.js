const api_base = "https://rmik.gyatechindonesia.com/api";
const current_extension_version = String(chrome.runtime.getManifest()?.version || "0.0.0");

const license_input = document.getElementById("licenseKey");
const login_button = document.getElementById("loginBtn");
const logout_button = document.getElementById("logoutBtn");
const status_box = document.getElementById("statusBox");
const login_area = document.getElementById("login-area");
const active_area = document.getElementById("active-area");
const active_name = document.getElementById("activeName");
const active_user = document.getElementById("activeUser");
const active_device = document.getElementById("activeDevice");
const active_license = document.getElementById("activeLicense");
const active_pc = document.getElementById("activePc");
const update_bell = document.getElementById("updateBell");
const update_dot = document.getElementById("updateDot");
const update_box = document.getElementById("updateBox");
const update_version = document.getElementById("updateVersion");
const update_note = document.getElementById("updateNote");
const update_download_button = document.getElementById("updateDownloadBtn");

let latest_update_data = null;

function send_auth(action, payload = {})
{
    return chrome.runtime.sendMessage({
        type: "rmik_auth",
        action,
        ...payload,
    });
}

function mask_middle(value, head = 8, tail = 6)
{
    const text = String(value || "");
    if (text.length <= head + tail) return text;
    return `${text.slice(0, head)}...${text.slice(-tail)}`;
}

function show_status(message, type = "")
{
    status_box.textContent = message;
    status_box.className = `status-message ${type}`.trim();
    status_box.classList.remove("hidden");
}

function hide_status()
{
    status_box.classList.add("hidden");
}

function set_loading(loading)
{
    login_button.disabled = loading;
    login_button.innerHTML = loading
        ? '<span class="loading"></span>Memverifikasi...'
        : "Masuk dengan License";
}

function show_login()
{
    login_area.classList.remove("hidden");
    active_area.classList.add("hidden");
}

function show_active(session)
{
    const name = session?.full_name || session?.username || "User";
    active_name.textContent = name;
    active_user.textContent = session?.username ? `@${session.username}` : "Lisensi aktif";
    active_device.textContent = mask_middle(session?.device_id || "-");
    active_license.textContent = mask_middle(session?.license_key || "-");
    active_pc.textContent = session?.pc_label || "-";
    login_area.classList.add("hidden");
    active_area.classList.remove("hidden");
}

async function hydrate_device_hint()
{
    const response = await send_auth("get_device_id");
    const hint = document.getElementById("deviceHint");
    if (response?.ok && hint)
        hint.textContent = `Device ID: ${mask_middle(response.data?.device_id || "-", 10, 8)}`;
}

function normalize_semver(version_text)
{
    const text = String(version_text || "0.0.0").trim().replace(/^v/i, "");
    const match = text.match(/^(\d+)\.(\d+)\.(\d+)$/);
    if (!match) return [0, 0, 0];
    return [Number(match[1]), Number(match[2]), Number(match[3])];
}

function semver_is_greater(left, right)
{
    const left_parts = normalize_semver(left);
    const right_parts = normalize_semver(right);
    for (let index = 0; index < 3; index += 1)
    {
        if (left_parts[index] > right_parts[index]) return true;
        if (left_parts[index] < right_parts[index]) return false;
    }
    return false;
}

async function fetch_update_data()
{
    const query = new URLSearchParams({
        app: "bpjs_auto_screening",
        current_version: current_extension_version,
        ts: String(Date.now()),
    });

    try
    {
        const response = await fetch(`${api_base}/extension/update_info.php?${query.toString()}`, {
            method: "GET",
            cache: "no-store",
        });
        const body = await response.json().catch(() => null);
        if (!response.ok || !body?.ok)
            return null;

        const payload = body?.data || {};
        const latest_version = String(payload.latest_version || current_extension_version);
        const has_update = Boolean(payload.has_update) || semver_is_greater(latest_version, current_extension_version);

        return {
            has_update,
            latest_version,
            current_version: String(payload.current_version || current_extension_version),
            download_url: String(payload.download_url || ""),
            notes: String(payload.notes || ""),
        };
    } catch
    {
        return null;
    }
}

function show_update_box()
{
    update_box.classList.remove("hidden");
}

function hide_update_box()
{
    update_box.classList.add("hidden");
}

function apply_update_data()
{
    if (!latest_update_data || !latest_update_data.has_update)
    {
        update_dot.classList.add("hidden");
        hide_update_box();
        return;
    }

    update_dot.classList.remove("hidden");
    update_version.textContent = `v${latest_update_data.latest_version}`;
    update_note.textContent = latest_update_data.notes || `Versi terbaru v${latest_update_data.latest_version} siap diunduh.`;
    show_update_box();
}

async function check_update_status()
{
    latest_update_data = await fetch_update_data();
    apply_update_data();
}

function handle_update_bell_click()
{
    if (!latest_update_data || !latest_update_data.has_update)
    {
        show_status(`Versi extension sudah terbaru (v${current_extension_version}).`, "pending");
        return;
    }

    if (update_box.classList.contains("hidden"))
        show_update_box();
    else
        hide_update_box();
}

function handle_update_download_click()
{
    if (!latest_update_data?.download_url)
    {
        show_status("Link unduhan update belum tersedia.", "error");
        return;
    }

    chrome.tabs.create({ url: latest_update_data.download_url });
    show_status("Halaman unduhan update dibuka. Setelah unduh, pasang versi terbaru extension.", "pending");
}

async function restore_session()
{
    const current = await send_auth("get_session");
    if (!current?.ok)
    {
        show_login();
        return;
    }

    show_status("Memeriksa ulang lisensi...", "pending");
    const recheck = await send_auth("revalidate");
    if (!recheck?.ok)
    {
        show_login();
        show_status(recheck?.error || "Lisensi tidak valid. Login ulang diperlukan.", "error");
        return;
    }

    hide_status();
    show_active(recheck.data);
}

login_button.addEventListener("click", async () =>
{
    const license_key = String(license_input.value || "").trim().toUpperCase();
    if (!license_key)
    {
        show_status("License key wajib diisi.", "error");
        return;
    }

    set_loading(true);
    hide_status();

    const result = await send_auth("login", { license_key });
    set_loading(false);

    if (!result?.ok)
    {
        show_status(result?.error || "Login lisensi gagal.", "error");
        return;
    }

    hide_status();
    license_input.value = license_key;
    show_active(result.data);
});

logout_button.addEventListener("click", async () =>
{
    await send_auth("logout");
    show_login();
    show_status("Session extension dibersihkan.", "pending");
});

update_bell.addEventListener("click", handle_update_bell_click);
update_download_button.addEventListener("click", handle_update_download_click);

document.addEventListener("DOMContentLoaded", async () =>
{
    await hydrate_device_hint();
    await restore_session();
    await check_update_status();
});
