export const MAX_DOM_LINES = 300
export const IMPORTANT_PATTERNS = [
    'user_start', 'user_done', 'batch_done', 'worker_batch_done',
    '[SYSTEM]', '[ERR]', '[FATAL]', '[WARN]',
    'fatal_error', 'worker_run_error', 'heartbeat_error',
    'pelayanan_job_start', 'pelayanan_job_done', 'pelayanan_mulai_clicked',
    'pemeriksaan_mandiri_bank_received',
    'pemeriksaan_mandiri_form_progress',
    'pelayanan_nakes_form_progress',
    'step_ok'
]

export const login_screen = document.getElementById('loginScreen')
export const main_controller = document.getElementById('mainController')
export const license_form = document.getElementById('licenseForm')
export const license_key_input = document.getElementById('licenseKey')
export const btn_submit_login = document.getElementById('btnSubmitLogin') || document.getElementById('submitBtn')
export const btn_text_login = document.getElementById('btnTextLogin') || document.getElementById('btnText')
export const loading_icon_login = document.getElementById('loadingIconLogin') || document.getElementById('loadingIcon')
export const valid_icon = document.getElementById('validIcon')
export const login_status_badge = document.getElementById('loginStatusBadge') || document.getElementById('statusBadge')
export const login_error = document.getElementById('loginError')
export const form_inputs = document.querySelectorAll('#licenseForm input, #licenseForm button')

export const display_user = document.getElementById('displayUser')
export const app_version_label = document.getElementById('appVersionLabel')
export const display_key = document.getElementById('displayKey')
export const display_device = document.getElementById('displayDevice')
export const window_size_label = document.getElementById('windowSize')

export const btn_settings = document.getElementById('btnSettings')
export const btn_theme_toggle = document.getElementById('btnThemeToggle')
export const btn_update_notify = document.getElementById('btnUpdateNotify')
export const badge_update_notif = document.getElementById('badgeUpdateNotif')
export const theme_icon = document.getElementById('themeIcon')
export const clock_label = document.getElementById('clock')
export const btn_logout = document.getElementById('btnLogout')
export const btn_minimize = document.getElementById('btnMinimize')
export const btn_maximize = document.getElementById('btnMaximize')
export const btn_close = document.getElementById('btnClose')

export const btn_login_minimize = document.getElementById('btnLoginMinimize')
export const btn_login_maximize = document.getElementById('btnLoginMaximize')
export const btn_login_close = document.getElementById('btnLoginClose')


export const btn_start = document.getElementById('btnStart')
export const btn_stop = document.getElementById('btnStop')
export const status_indicator = document.getElementById('statusIndicator')
export const footer_dot = document.getElementById('footerDot')

export const session_banner = document.getElementById('sessionBanner')
export const session_dot = document.getElementById('sessionDot')
export const session_label = document.getElementById('sessionLabel')
export const btn_login_si = document.getElementById('btnLoginSI')
export const btn_logout_si = document.getElementById('btnLogoutSI')

export const info_pc_label = document.getElementById('infoPcLabel')
export const info_task_type = document.getElementById('infoTaskType')
export const info_mode = document.getElementById('infoMode')
export const info_pending = document.getElementById('infoPending')
export const info_running = document.getElementById('infoRunning')
export const info_busy = document.getElementById('infoBusy')

export const acc_email_input = document.getElementById('accEmail')
export const acc_pass_input = document.getElementById('accPass')
export const btn_toggle_pass = document.getElementById('btnTogglePass')
export const account_status_label = document.getElementById('accountStatusLabel')
export const btn_save_settings = document.getElementById('btnSaveSettings')
export const btn_load_account = document.getElementById('btnLoadAccount')

export const log_area = document.getElementById('logArea')
export const log_count_label = document.getElementById('logCountLabel')
export const btn_clear = document.getElementById('btnClear')
export const btn_verbose = document.getElementById('btnVerbose')
export const toast_box = document.getElementById('toast')
export const log_status = document.getElementById('logStatus')

export const settings_modal = document.getElementById('settingsModal')
export const settings_inner = settings_modal?.querySelector('.transform')
export const btn_close_settings = document.getElementById('btnCloseSettings')
export const btn_cancel_settings = document.getElementById('btnCancelSettings')
export const btn_save_advanced = document.getElementById('btnSaveAdvanced')
export const btn_reset_data = document.getElementById('btnResetData')

export const cfg_headless = document.getElementById('cfgHeadless')
export const cfg_browser_engine = document.getElementById('cfgBrowserEngine')
export const cfg_browser_channel = document.getElementById('cfgBrowserChannel')
export const cfg_pause = document.getElementById('cfgPause')
export const cfg_slow_mo = document.getElementById('cfgSlowMo')
export const cfg_stop_on_error = document.getElementById('cfgStopOnError')
export const cfg_save_artifacts = document.getElementById('cfgSaveArtifacts')
export const cfg_mandiri_recheck_completed = document.getElementById('cfgMandiriRecheckCompleted')
export const cfg_mandiri_only_index = document.getElementById('cfgMandiriOnlyIndex')
export const cfg_mandiri_refill_answered = document.getElementById('cfgMandiriRefillAnswered')
export const cfg_mandiri_auto_submit = document.getElementById('cfgMandiriAutoSubmit')
export const cfg_session_auto_relogin = document.getElementById('cfgSessionAutoRelogin')
export const cfg_wali_nik = document.getElementById('cfgWaliNik')
export const cfg_wali_nama = document.getElementById('cfgWaliNama')
export const cfg_wali_no_hp = document.getElementById('cfgWaliNoHp')
export const cfg_wali_instansi_puskesmas = document.getElementById('cfgWaliInstansiPuskesmas')
export const cfg_wali_tgl = document.getElementById('cfgWaliTgl')
export const cfg_wali_jk = document.getElementById('cfgWaliJk')

export const btn_help = document.getElementById('btnHelp')
export const help_modal = document.getElementById('helpModal')
export const help_inner = help_modal?.querySelector('.transform')
export const btn_close_help = document.getElementById('btnCloseHelp')
export const btn_understand_help = document.getElementById('btnUnderstandHelp')
