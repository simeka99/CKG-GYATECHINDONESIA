import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root_dir = path.resolve(__dirname, "..");
const storage_dir = path.join(root_dir, "storage");

if (!fs.existsSync(storage_dir)) {
    console.log("storage directory tidak ditemukan, tidak ada yang dibersihkan.");
    process.exit(0);
}

const files = fs
    .readdirSync(storage_dir, { withFileTypes: true })
    .filter((entry) => entry.isFile() && entry.name.toLowerCase().endsWith(".json"))
    .map((entry) => path.join(storage_dir, entry.name));

let removed = 0;
for (const file_path of files) {
    try {
        fs.unlinkSync(file_path);
        removed += 1;
    } catch (e) {
        console.error(`gagal menghapus ${file_path}: ${e.message}`);
        process.exitCode = 1;
    }
}

console.log(`clean selesai: ${removed} file json dihapus.`);
