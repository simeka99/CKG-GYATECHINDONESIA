const path = require("path")
const { pathToFileURL } = require("url")

const src_dir = process.env.SRC_DIR || __dirname
process.env.WORKER_MODE = "1"
process.argv[1] = path.join(src_dir, "index.js")

if (process.env.PLAYWRIGHT_BROWSERS_PATH)
    process.env.PLAYWRIGHT_BROWSERS_PATH = process.env.PLAYWRIGHT_BROWSERS_PATH

function send_log(text)
{
    const line = String(text).trim()
    if (!line) return
    process.stdout.write(line + "\n")
}

function send_status(status)
{
    try { process.parentPort.postMessage({ type: "status", data: status }) } catch { }
}

console.log = function (...args)
{
    send_log(args.map(a => typeof a === "object" ? JSON.stringify(a) : String(a)).join(" "))
}

console.error = function (...args)
{
    send_log("[ERR] " + args.map(a => typeof a === "object" ? JSON.stringify(a) : String(a)).join(" "))
}

console.warn = function (...args)
{
    send_log("[WARN] " + args.map(a => typeof a === "object" ? JSON.stringify(a) : String(a)).join(" "))
}

import(pathToFileURL(path.join(src_dir, "index.js")).href)
    .catch(err =>
    {
        send_log("[FATAL] " + err.message)
        send_status("error")
    })
