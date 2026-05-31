# Updates Folder

Folder ini dipakai oleh Electron Auto Updater (generic provider).

- URL update: `https://rmik.gyatechindonesia.com/updates/`
- File wajib: `latest.yml`
- File rilis utama: `RMIK Medical Record Setup <version>.exe`
- File opsional: `<exe>.blockmap`

## Konfigurasi Script (package.json)

Gunakan script ini:

```json
"build": "cross-env BUNDLE_PLAYWRIGHT_BROWSER=0 node scripts/download-browser.js && cross-env CSC_IDENTITY_AUTO_DISCOVERY=false electron-builder --win --x64",
"build:bundled": "cross-env BUNDLE_PLAYWRIGHT_BROWSER=1 node scripts/download-browser.js && cross-env CSC_IDENTITY_AUTO_DISCOVERY=false electron-builder --win --x64",
"publish": "node scripts/publish-updates.js",
"deploy": "npm run build && cross-env UPDATES_MOVE_ARTIFACTS=1 UPDATES_CLEAN_DIST=1 npm run publish"
```

Khusus `npm run deploy`:

- default sekarang build ringan tanpa browser bundled
- artefak rilis (`latest.yml`, `.exe`, `.blockmap`) dipindah ke folder `/updates`
- folder `dist` dibersihkan setelah publish + upload sukses
- hasil akhirnya tidak dobel antara `dist` dan `updates`

Jika butuh build penuh dengan Chromium bawaan:

- `npm run deploy:bundled`

## Cara Rilis Update (Lengkap)

1. Naikkan versi aplikasi di `SEHAT-INDONESIAKU/package.json`
2. Jalankan deploy sekali per rilis:
   - `npm run deploy`
3. Script akan otomatis:
   - build installer (`electron-builder`)
   - pindah/copy `latest.yml`, `.exe`, `.blockmap` ke folder lokal `/updates`
   - upload ke server FTP folder `/public_html/rmik.gyatechindonesia.com/updates` (otomatis baca `.vscode/sftp.json`)
4. Pastikan file berikut ada di folder ini:
   - `latest.yml`
   - `versions.json` (untuk daftar versi di lonceng EXE)
   - `RMIK Medical Record Setup <version>.exe`
   - `RMIK Medical Record Setup <version>.exe.blockmap` (jika ada)

## Catatan Upload Otomatis

- Default: upload otomatis aktif.
- Matikan upload otomatis (copy lokal saja):
  - `UPDATES_UPLOAD=0 npm run publish`
- Jika ingin override FTP via env:
  - `UPDATES_FTP_HOST`
  - `UPDATES_FTP_PORT`
  - `UPDATES_FTP_USER`
  - `UPDATES_FTP_PASSWORD`
  - `UPDATES_FTP_BASE_PATH` (contoh: `/public_html/rmik.gyatechindonesia.com`)
  - `UPDATES_REMOTE_DIR` (default: `updates`)

## Auto-Clean File Lama

- Setelah publish, file update lama otomatis dibersihkan supaya tidak menumpuk.
- Default menyimpan `3` versi terbaru (`.exe` + `.blockmap`) di lokal dan server.
- Ubah jumlah versi yang disimpan:
  - `UPDATES_KEEP_VERSIONS=3 npm run publish`

## Hasil di User EXE

Setelah file update tersedia di server:

1. EXE user akan cek update otomatis
2. Ikon lonceng update tampil badge
3. User klik lonceng:
   - bisa download versi terbaru
   - bisa lihat riwayat versi dan download versi lama (downgrade manual)
4. Setelah selesai, user klik install dan app restart otomatis
