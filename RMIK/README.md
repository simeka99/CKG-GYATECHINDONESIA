# RMIK Build Flow

## Ringkasan

Build dan deploy sekarang tanpa obfuscation.

## Lokasi Penting

1. Source: `RMIK/src` dan `RMIK/electron`
2. Workspace build sementara: `../.build_workspace_rmik/project`
3. Hasil build: `RMIK/dist`

## Alur Deploy

1. `scripts/deploy-release.js` menjalankan `bump:version`
2. Menjalankan `build` atau `build:bundled`
3. Menjalankan `publish`
4. Jika build/publish gagal, versi `package.json` otomatis dikembalikan

## Build Step

1. `scripts/download-browser.js`
2. `scripts/build-production.js`

`scripts/build-production.js` akan:

1. Copy project ke workspace sementara
2. Menjalankan `electron-builder`
3. Copy hasil `dist` ke project utama
4. Hapus workspace sementara

## Command

1. Build ringan:

```powershell
npm run build
```

2. Build dengan browser bundle:

```powershell
npm run build:bundled
```

3. Deploy:

```powershell
npm run deploy
```

4. Deploy channel lisensi:

```powershell
npm run deploy:license -- GYA-XXXXXXXX-XXXXXXXX
```
