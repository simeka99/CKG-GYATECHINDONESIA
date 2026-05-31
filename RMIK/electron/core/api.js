import { storage_dir, config_file, license_file } from './paths.js'
import { read_json, write_json, get_device_id, ensure_dir, clear_all_user_data } from './utils.js'
import { logout_web } from './session.js'

export async function license_load()
{
    const local_data = read_json(license_file)
    if (!local_data || !local_data.license_key) return null

    try
    {
        const config = read_json(config_file)
        const base_url = (config?.api?.base_url || 'https://rmik.gyatechindonesia.com/api').replace(/\/+$/, '')
        const device_id = get_device_id()

        const { default: fetch } = await import('node-fetch')
        const res = await fetch(`${base_url}/license/login.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-License-Key': local_data.license_key,
                'X-Device-Id': device_id,
            },
            body: new URLSearchParams({ device_id }),
        })

        const data = await res.json().catch(() => null)

        if (!res.ok || !data)
            return null

        if (!data.ok)
        {
            clear_all_user_data()
            return null
        }

        const payload = data?.data && typeof data.data === 'object' ? data.data : data
        const device_token = String(payload?.device_token || '').trim()

        ensure_dir(storage_dir)
        write_json(license_file, {
            ...local_data,
            username: payload?.username || '',
            full_name: payload?.full_name || '',
            pc_label: payload?.pc_label || '',
            mode: payload?.mode || '',
            task_type: payload?.task_type || '',
            puskesmas: payload?.puskesmas || '',
            subscription_type: payload?.subscription_type || '',
            update_channel: payload?.update_channel || local_data?.update_channel || 'public',
            device_id: device_id,
            device_token: device_token || String(local_data?.device_token || '').trim(),
            validated_at: new Date().toISOString(),
        })

        return {
            ...local_data,
            ...payload,
            license_key: local_data.license_key,
            update_channel: payload?.update_channel || local_data?.update_channel || 'public',
            device_token: device_token || String(local_data?.device_token || '').trim(),
            device_id
        }
    } catch (e)
    {
        return null
    }
}

export async function license_login(license_key)
{
    try
    {
        const config = read_json(config_file)
        const base_url = (config?.api?.base_url || 'https://rmik.gyatechindonesia.com/api').replace(/\/+$/, '')
        const device_id = get_device_id()

        const { default: fetch } = await import('node-fetch')
        const res = await fetch(`${base_url}/license/login.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-License-Key': license_key,
                'X-Device-Id': device_id,
            },
            body: new URLSearchParams({ device_id }),
        })

        const data = await res.json().catch(() => null)

        if (!res.ok || !data?.ok)
            return { ok: false, error: data?.error || `Login gagal (HTTP ${res.status})` }

        const payload = data?.data && typeof data.data === 'object' ? data.data : data
        const device_token = String(payload?.device_token || '').trim()

        ensure_dir(storage_dir)
        write_json(license_file, {
            license_key,
            username: payload?.username || '',
            full_name: payload?.full_name || '',
            pc_label: payload?.pc_label || '',
            mode: payload?.mode || '',
            task_type: payload?.task_type || '',
            puskesmas: payload?.puskesmas || '',
            subscription_type: payload?.subscription_type || '',
            update_channel: payload?.update_channel || 'public',
            device_id: device_id,
            device_token: device_token,
            logged_in_at: new Date().toISOString(),
            validated_at: new Date().toISOString(),
        })

        return {
            ok: true,
            data: {
                username: payload?.username || '',
                full_name: payload?.full_name || '',
                pc_label: payload?.pc_label || '',
                mode: payload?.mode || '',
                task_type: payload?.task_type || '',
                puskesmas: payload?.puskesmas || '',
                subscription_type: payload?.subscription_type || '',
                update_channel: payload?.update_channel || 'public',
                device_token: device_token,
                device_id: device_id,
                license_key: license_key
            }
        }
    } catch (e)
    {
        return { ok: false, error: 'Koneksi ke server gagal: ' + e.message }
    }
}

export async function license_clear()
{
    try
    {
        await logout_web().catch(() => { })
        clear_all_user_data()
        return { ok: true }
    } catch (e)
    {
        return { ok: false, error: e.message }
    }
}
