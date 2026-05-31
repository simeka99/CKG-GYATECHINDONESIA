const { contextBridge, ipcRenderer } = require('electron');

const invoke_allowlist = new Set([
    'license_load',
    'license_login',
    'license_clear',
    'account_load',
    'account_save',
    'config_advanced_load',
    'config_advanced_save',
    'set_verbose_mode',
    'app_version',
    'session_check',
    'session_login',
    'session_logout',
    'worker_start',
    'worker_stop',
    'worker_get_state',
    'check_for_updates',
    'start_download_update',
    'install_update',
    'get_update_versions',
    'download_update_asset',
    'open_downloaded_update',
    'open_external_url'
]);

const send_allowlist = new Set([
    'window_minimize',
    'window_maximize',
    'window_close',
    'validate_license',
    'set_status_interval'
]);

const on_allowlist = new Set([
    'worker_log_batch',
    'worker_heartbeat',
    'worker_status',
    'window_resized',
    'app_version_changed',
    'session_status',
    'license_revoked',
    'quota_empty',
    'update_available',
    'update_not_available',
    'update_download_progress',
    'update_ready',
    'update_state',
    'update_error',
    'manual_update_download_progress',
    'manual_update_download_done',
    'manual_update_download_error',
    'license_valid',
    'license_invalid',
    'license_error',
    'config_loaded'
]);

contextBridge.exposeInMainWorld('ipcRenderer', {
    invoke: (channel, ...args) => invoke_allowlist.has(channel) ? ipcRenderer.invoke(channel, ...args) : Promise.reject(new Error('IPC channel not allowed')),
    send: (channel, ...args) => { if (send_allowlist.has(channel)) ipcRenderer.send(channel, ...args); },
    on: (channel, func) => { if (on_allowlist.has(channel)) ipcRenderer.on(channel, (event, ...args) => func(event, ...args)); },
    removeAllListeners: (channel) => { if (on_allowlist.has(channel)) ipcRenderer.removeAllListeners(channel); }
});
