import assert from "node:assert/strict";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root_dir = path.resolve(__dirname, "..");

const config_file = path.join(root_dir, "config.json");
assert.ok(fs.existsSync(config_file), "config.json tidak ditemukan");

const config_raw = fs.readFileSync(config_file, "utf-8");
const config = JSON.parse(config_raw);

assert.ok(config?.api?.base_url, "config.api.base_url wajib diisi");
assert.ok(config?.urls?.login, "config.urls.login wajib diisi");
assert.ok(config?.urls?.home, "config.urls.home wajib diisi");

const worker_entry = path.join(__dirname, "worker_entry.cjs");
assert.ok(fs.existsSync(worker_entry), "src/worker_entry.cjs tidak ditemukan");

console.log("Test dasar konfigurasi: OK");
