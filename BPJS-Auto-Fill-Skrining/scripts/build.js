const fs = require("fs");
const path = require("path");
const archiver = require("archiver");
const javascript_obfuscator = require("javascript-obfuscator");

const root_dir = path.resolve(__dirname, "..");
const app_root_dir = path.resolve(root_dir, "..");
const package_json_path = path.join(root_dir, "package.json");
const package_lock_path = path.join(root_dir, "package-lock.json");
const source_dir = path.join(root_dir, "src");
const source_manifest_path = path.join(source_dir, "manifest.json");
const build_dir = path.join(root_dir, "build");
const extension_updates_path = path.join(app_root_dir, "config", "extension_updates.json");
const extension_public_base_url = "https://rmik.gyatechindonesia.com";

const js_obfuscator_option = {
    compact: true,
    controlFlowFlattening: true,
    controlFlowFlatteningThreshold: 0.5,
    deadCodeInjection: true,
    deadCodeInjectionThreshold: 0.2,
    debugProtection: false,
    debugProtectionInterval: 0,
    disableConsoleOutput: false,
    identifierNamesGenerator: "mangled-shuffled",
    numbersToExpressions: true,
    renameGlobals: false,
    selfDefending: false,
    simplify: true,
    splitStrings: true,
    splitStringsChunkLength: 3,
    stringArray: true,
    stringArrayEncoding: ["rc4"],
    stringArrayIndexShift: true,
    stringArrayRotate: true,
    stringArrayShuffle: true,
    stringArrayThreshold: 1,
    stringArrayWrappersCount: 1,
    stringArrayWrappersChainedCalls: false,
    stringArrayWrappersParametersMaxCount: 2,
    stringArrayWrappersType: "function",
    stringArrayCallsTransform: true,
    stringArrayCallsTransformThreshold: 0.8,
    target: "browser-no-eval",
    transformObjectKeys: true,
    unicodeEscapeSequence: false,
};

function ensure_dir_sync(dir_path)
{
    fs.mkdirSync(dir_path, { recursive: true });
}

function remove_dir_sync(dir_path)
{
    fs.rmSync(dir_path, { recursive: true, force: true });
}

function read_json_sync(file_path)
{
    return JSON.parse(fs.readFileSync(file_path, "utf-8"));
}

function write_json_sync(file_path, data)
{
    fs.writeFileSync(file_path, `${JSON.stringify(data, null, 2)}\n`, "utf-8");
}

function normalize_version_text(version_text)
{
    return String(version_text || "1.0.0").replace(/^v/i, "");
}

function bump_patch_version(version_text)
{
    const normalized = normalize_version_text(version_text);
    const match = normalized.match(/^(\d+)\.(\d+)\.(\d+)$/);
    if (!match)
        return "1.0.1";

    const major = Number(match[1]);
    const minor = Number(match[2]);
    const patch = Number(match[3]) + 1;
    return `${major}.${minor}.${patch}`;
}

function sync_package_versions(next_version)
{
    const package_json = read_json_sync(package_json_path);
    package_json.version = next_version;
    write_json_sync(package_json_path, package_json);

    if (!fs.existsSync(package_lock_path))
        return;

    const package_lock = read_json_sync(package_lock_path);
    package_lock.version = next_version;
    if (package_lock.packages && package_lock.packages[""])
        package_lock.packages[""].version = next_version;
    write_json_sync(package_lock_path, package_lock);
}

function get_current_package_version()
{
    const package_json = read_json_sync(package_json_path);
    return normalize_version_text(package_json.version);
}

function sync_manifest_version_file(manifest_path, version_text)
{
    if (!fs.existsSync(manifest_path))
        return false;

    const normalized_version = normalize_version_text(version_text);
    const manifest_json = read_json_sync(manifest_path);
    manifest_json.version = normalized_version;
    manifest_json.version_name = `v${normalized_version}`;
    write_json_sync(manifest_path, manifest_json);
    return true;
}

function sync_extension_update_config(version_text, build_output_name)
{
    const normalized_version = normalize_version_text(version_text);
    let config_json = {};
    if (fs.existsSync(extension_updates_path))
    {
        try {
            config_json = read_json_sync(extension_updates_path);
        } catch {
            config_json = {};
        }
    }
    if (!config_json || typeof config_json !== "object")
        config_json = {};

    const download_url = `${extension_public_base_url}/BPJS-Auto-Fill-Skrining/build/${build_output_name}.zip`;
    config_json.bpjs_auto_screening = {
        latest_version: normalized_version,
        download_url,
        notes: `Update extension v${normalized_version} tersedia.`,
        updated_at: new Date().toISOString(),
    };

    ensure_dir_sync(path.dirname(extension_updates_path));
    write_json_sync(extension_updates_path, config_json);
}

function copy_tree_sync(src_dir, dst_dir)
{
    ensure_dir_sync(dst_dir);
    const row_list = fs.readdirSync(src_dir, { withFileTypes: true });
    for (const row of row_list)
    {
        const src_path = path.join(src_dir, row.name);
        const dst_path = path.join(dst_dir, row.name);
        if (row.isDirectory())
        {
            copy_tree_sync(src_path, dst_path);
            continue;
        }
        fs.copyFileSync(src_path, dst_path);
    }
}

function walk_file_sync(target_dir, handle_file)
{
    const row_list = fs.readdirSync(target_dir, { withFileTypes: true });
    for (const row of row_list)
    {
        const full_path = path.join(target_dir, row.name);
        if (row.isDirectory())
        {
            walk_file_sync(full_path, handle_file);
            continue;
        }
        handle_file(full_path);
    }
}

function obfuscate_js_file_sync(file_path)
{
    if (path.extname(file_path).toLowerCase() !== ".js")
        return;

    const source_code = fs.readFileSync(file_path, "utf-8");
    const result = javascript_obfuscator.obfuscate(source_code, js_obfuscator_option);
    fs.writeFileSync(file_path, result.getObfuscatedCode(), "utf-8");
}

function obfuscate_all_js_sync(output_dir)
{
    walk_file_sync(output_dir, obfuscate_js_file_sync);
}

function create_zip_from_dir(zip_path, source_dir)
{
    return new Promise((resolve, reject) =>
    {
        ensure_dir_sync(path.dirname(zip_path));
        const output = fs.createWriteStream(zip_path);
        const zip = archiver("zip", { zlib: { level: 9 } });

        output.on("close", () => resolve(zip.pointer()));
        output.on("error", reject);
        zip.on("error", reject);

        zip.pipe(output);
        zip.directory(source_dir, false);
        zip.finalize();
    });
}

async function run()
{
    const arg_list = process.argv.slice(2);
    const skip_obfuscate = arg_list.includes("--no-obfuscate");
    const zip_only = arg_list.includes("--zip-only");
    const keep_version = arg_list.includes("--keep-version");

    if (!fs.existsSync(source_dir))
        throw new Error("Folder src belum ada.");

    const current_version = get_current_package_version();
    const package_version = zip_only || keep_version
        ? current_version
        : bump_patch_version(current_version);

    if (!zip_only && !keep_version)
        sync_package_versions(package_version);

    if (!zip_only)
        sync_manifest_version_file(source_manifest_path, package_version);

    const build_output_name = `BPJS-Auto-Fill-Skrining-v${package_version}`;
    const output_dir = path.join(build_dir, build_output_name);
    const zip_file = path.join(build_dir, `${build_output_name}.zip`);

    if (!zip_only)
    {
        remove_dir_sync(build_dir);
        ensure_dir_sync(output_dir);
        copy_tree_sync(source_dir, output_dir);
        sync_manifest_version_file(path.join(output_dir, "manifest.json"), package_version);
        if (!skip_obfuscate)
            obfuscate_all_js_sync(output_dir);
    } else {
        if (!fs.existsSync(output_dir))
            throw new Error("Folder build versi belum ada. Jalankan npm run build dulu.");
        sync_manifest_version_file(path.join(output_dir, "manifest.json"), package_version);
    }

    const zip_size = await create_zip_from_dir(zip_file, output_dir);
    sync_extension_update_config(package_version, build_output_name);

    console.log(`Build selesai.`);
    console.log(`Version  : ${package_version}`);
    console.log(`Source   : ${source_dir}`);
    console.log(`Folder   : ${output_dir}`);
    console.log(`Zip      : ${zip_file}`);
    console.log(`Zip size : ${zip_size} bytes`);
}

run().catch((error) =>
{
    console.error(error?.message || error);
    process.exit(1);
});
