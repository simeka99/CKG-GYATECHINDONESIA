import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const configPath = path.resolve(__dirname, '../config.json');

try
{
    const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

    // Reset data_wali
    if (config.config && config.config.data_wali)
    {
        config.config.data_wali.nik = "";
        config.config.data_wali.nama = "";
        config.config.data_wali.tanggal_lahir = "";
        config.config.data_wali.jenis_kelamin = "";
        config.config.data_wali.instansi_puskesmas = "";
    }

    // Reset license_key
    if (config.api)
    {
        config.api.license_key = "";
    }

    // Reset credentials
    if (config.credentials)
    {
        config.credentials.email = "";
        config.credentials.password = "";
    }

    fs.writeFileSync(configPath, JSON.stringify(config, null, 4), 'utf8');
    console.log('[SYSTEM] config.json has been sanitized successfully.');
} catch (error)
{
    console.error('[ERR] Failed to sanitize config.json:', error.message);
}
