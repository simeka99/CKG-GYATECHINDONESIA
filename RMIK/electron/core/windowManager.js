let main_window = null

export function set_main_window(win) {
    main_window = win
}

export function get_main_window() {
    return main_window
}

export function send_to_renderer(channel, data) {
    if (!main_window || main_window.isDestroyed()) return
    main_window.webContents.send(channel, data)
}
