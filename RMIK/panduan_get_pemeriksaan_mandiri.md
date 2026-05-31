# Panduan Get Pemeriksaan Mandiri

## Command ini dari mana

Command `npm run get:8l_gt60` diambil dari file `package.json` pada bagian `scripts`.

Script yang dipanggil:

- `scripts/preview_pemeriksaan_mandiri_json.js`

Script itu melakukan request ke server API (`DEV_API_BASE_URL`) lalu menyimpan hasil JSON ke folder:

- `artifacts/debug/`
- file tetap: `artifacts/debug/pemeriksaan_mandiri_preview_latest.json`

## Daftar package_key valid

Sumber backend: `api/job/batch.php` pada fungsi `resolve_package_key_from_demography`.

Laki-laki:

- `6l_lt45_lt40`
- `6l_lt45_gt40`
- `8l_45_59`
- `8l_gt60`

Perempuan:

- `7p_18_29`
- `8p_30_39`
- `7p_40_44`
- `9p_45_59`
- `9p_gt60`

## Arti command

- `npm run get:6l_lt45_lt40`
- `npm run get:6l_lt45_gt40`
- `npm run get:8l_45_59`
- `npm run get:8l_gt60`
- `npm run get:7p_18_29`
- `npm run get:8p_30_39`
- `npm run get:7p_40_44`
- `npm run get:9p_45_59`
- `npm run get:9p_gt60`

## Format kode paket

- Contoh: `8l_gt60`
  - `8` = nomor paket
  - `l` = laki-laki
  - `gt60` = umur lebih dari 60 tahun
- Contoh lain: `7p_18_29`
  - `7` = nomor paket
  - `p` = perempuan
  - `18_29` = umur 18 sampai 29 tahun

## Command umum untuk paket lain

- `npm run get:paket -- --package_key=7p_18_29`
- `npm run get:paket:p -- --package_key=7p_18_29 --usia_tahun=25`
- `npm run get:paket:l -- --package_key=8l_gt60 --usia_tahun=70`

## Cara pakai

Jalankan dari folder `RMIK`.

### 1. Ambil default paket 8L >60

```bash
npm run get:8l_gt60
```

### 2. Ambil dengan umur

```bash
npm run get:8l_gt60 -- --usia_tahun=70
```

### 3. Ambil dengan tanggal lahir

```bash
npm run get:8l_gt60 -- --tanggal_lahir=1955-10-10
```

### 4. Ambil khusus laki-laki

```bash
npm run get:8l_gt60:laki -- --usia_tahun=70
```

### 5. Ambil khusus perempuan

```bash
npm run get:8l_gt60:perempuan -- --usia_tahun=70
```

## Hasil output

Mode sekarang memakai file tetap (overwrite):

- `artifacts/debug/pemeriksaan_mandiri_preview_latest.json`
- `artifacts/debug/web_question_snapshot_latest.json`

Gunakan path file terbaru itu untuk `DEV_PEMERIKSAAN_MANDIRI_FILE` di `.env` jika ingin dipakai saat `npm run start`.
