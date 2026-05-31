<?php
$cookie_string = 'cf_clearance=SD4.xO_4YfdpBepI_FeUxq4xueTJniy.U4xgXnKa.xk-1774184046-1.2.1.1-6mWMEvBHgvl_8kn2PSiOBE.Qr9Lk5SoBfXgWzMJWaw3zWvMiLfQHhuh6IoWge4r.qf_8JhKdZ.bLqzptWSg2yAfoFGH8xH23RGaM0_BiOSDgk0EcSgg0EbFCTnQeesz.eBAPc_Qu_dvC4EwT.9e.OQR2oUACzoaJ5GsYHLf4KlWCiGhE_b4cY.aYExAPl38C..dpecTzHjq110ztLRN0YcH.RKVgrxWFam8ZLWHD40k; XSRF-TOKEN=eyJpdiI6InU3M0o2RlFSQ1FyellxWmJPblRCd1E9PSIsInZhbHVlIjoibU1OT0d0V21UcmZpTEhsbmNIaitYSjB1WllKNDc2dFRWWENnbWpqZVRPdXEyczNURzdxWklWaVN4Q3JvWFN6aiIsIm1hYyI6ImU2MmU3ZDhmNmU3ZmFjNTQyYmY3ZWMwOTM3MzMxYTFkNzkzYjNjOWVjN2I2NWU4MGYwOWJmOWQ0MGFmNDY2NWIifQ%3D%3D; laravel_session=eyJpdiI6ImxiYWw4SDJCbjZ2YVRqcFdWSEhaalE9PSIsInZhbHVlIjoiVUxpb1lsck1LR2xOSjJ5cWhyempQUWhwSkhISTZcL3Urak1tXC9uK3RKcEozR2JuRkpMellGZ3Q0ZXNOY0FWb3ZHRjR1eENLMXljN01RNTM3SmNsSVVlYjVwMEZRRmthRGczT001NEd6TUI5V0VWaXhZK09DRGlwZmlqalRSZEFnMiIsIm1hYyI6ImQ5ZjExOGViMjFkOTU3Y2M1NzJlYzM5NTA0MjAyNmY2YWM3ZmUyZjgwMjEwMzE3YWQwOTQ0ZTA2ZGI3NzJhYzYifQ%3D%3D; XSRF-TOKEN=eyJpdiI6Imh1UDVsMjg5aUpjd1Q3dXJkaWhEd2c9PSIsInZhbHVlIjoicFJjK0p4dGtKZHJLQTl1VjhoMzJ6RnpvXC82Q3h5S1pTdWJaZnR5c1hnOUh5em5VU1c1cDNiNGpGVmhEaEQ5UXgiLCJtYWMiOiIyMDAzMTk4Y2Y3OGQzOGNhOGJhZDM2YzQ0MjdhYjgwYWUzNzE2ZWE4ODk1OGQyMDkxZDE3YzIyNDZjYWNjYWZlIn0%3D; laravel_session=eyJpdiI6InZMK3ZNa0YwQkYzVENHNEtYMHIraEE9PSIsInZhbHVlIjoiYWlYOVdBT1N6dkk4QzQzS2hsaVdWUWc1SHkwNHg1YVpqUER3RVBBVUhzakNaODcyczVWcTJ3WWxJalNuSVJMUGlEclEwZkhYMHZvd2FYZEpEM2prRTZZTmdhanQ1Y0lwVFwvbnAzK0JCR09TXC8yeXF6ZHltWXZjUkcxSjhuSlIzcyIsIm1hYyI6IjA5MDczYmI2NmMyN2M4MDFmNDY0ODIzYTk1YTFhMzE5YzZhOTY2NmE2NWVjMGM1MjZkMTZlMzI4ZjA4NjI1MjEifQ%3D%3D';

$base_url    = 'https://tasik.epuskesmas.id/pasien';
$fetch_limit = 100;
$total_ambil = isset($_POST['total']) ? (int) $_POST['total'] : 0;

$all_data           = [];
$total_records      = 0;
$total_pages_needed = 0;
$timing_log         = [];

function fetch_page($base_url, $cookie_string, $page, $limit) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $base_url . '?page=' . $page . '&limit=' . $limit,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_HTTPHEADER     => [
            'referer: https://tasik.epuskesmas.id/pasien?broadcastNotif=1',
            'x-requested-with: XMLHttpRequest',
            'Cookie: ' . $cookie_string,
        ],
    ]);

    $t_start   = microtime(true);
    $response  = curl_exec($curl);
    $duration  = round((microtime(true) - $t_start) * 1000, 1);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_code === 401)
        die('<p style="color:red;padding:20px">❌ Cookie expired! Ambil cookie baru dari browser.</p>');

    if ($http_code !== 200)
        die('<p style="color:red;padding:20px">❌ HTTP Error ' . $http_code . '</p>');

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE)
        die('<p style="color:red;padding:20px">❌ Response bukan JSON valid.</p>');

    return ['data' => $decoded, 'duration' => $duration, 'page' => $page];
}

function clean($val) {
    if (is_array($val)) return '';
    return htmlspecialchars(strip_tags((string) ($val ?? '')));
}

function extract_nama($val) {
    if (is_array($val)) return '';
    $val = (string) ($val ?? '');
    foreach (['</br>', '<br>', '<br/>'] as $br) {
        if (strpos($val, $br) !== false) {
            $val = explode($br, $val)[0];
            break;
        }
    }
    return htmlspecialchars(trim(strip_tags($val)));
}

function extract_kronis_from_nama($val) {
    if (is_array($val)) return '';
    preg_match('/flex-sub-kronis[^>]*>(.*?)<\/span>/i', (string) ($val ?? ''), $match);
    return htmlspecialchars(strip_tags($match[1] ?? ''));
}

function get_kecamatan($row) {
    if (isset($row['kecamatan']['nama'])) return $row['kecamatan']['nama'];
    return is_string($row['kecamatan'] ?? null) ? $row['kecamatan'] : '';
}

function get_penyakit_kronis($row) {
    return $row['penyakit_kronis']['value'] ?? '';
}

if ($total_ambil > 0) {
    $result             = fetch_page($base_url, $cookie_string, 1, $fetch_limit);
    $timing_log[]       = ['page' => 1, 'duration' => $result['duration']];
    $total_records      = $result['data']['data']['recordsTotal'] ?? $result['data']['data']['total'] ?? 0;
    $all_data           = array_merge($all_data, $result['data']['data']['records'] ?? []);
    $total_pages_needed = (int) ceil($total_ambil / $fetch_limit);

    for ($page = 2; $page <= $total_pages_needed; $page++) {
        if (count($all_data) >= $total_ambil) break;
        $result       = fetch_page($base_url, $cookie_string, $page, $fetch_limit);
        $timing_log[] = ['page' => $page, 'duration' => $result['duration']];
        $all_data     = array_merge($all_data, $result['data']['data']['records'] ?? []);
        usleep(250000);
    }

    $all_data = array_slice($all_data, 0, $total_ambil);
}

$total_duration = array_sum(array_column($timing_log, 'duration'));

$rows_json = json_encode(array_map(function($row) {
    $nama_raw = $row['nama_pasien'] ?? '';
    return [
        'nik'             => clean($row['nik'] ?? ''),
        'nama_pasien'     => extract_nama($nama_raw),
        'no_telp'         => clean($row['no_telp'] ?? ''),
        'jenis_kelamin'   => clean($row['jenis_kelamin'] ?? ''),
        'tanggal_lahir'   => clean($row['tanggal_lahir'] ?? ''),
        'umur_tahun'      => (int) ($row['umur_tahun'] ?? 0),
        'pekerjaan'       => clean($row['pekerjaan'] ?? ''),
        'alamat'          => clean($row['alamat'] ?? ''),
        'rt'              => clean($row['rt'] ?? ''),
        'rw'              => clean($row['rw'] ?? ''),
        'kelurahan'       => clean($row['kelurahan'] ?? ''),
        'kecamatan'       => clean(get_kecamatan($row)),
        'penyakit_kronis' => extract_kronis_from_nama($nama_raw) ?: clean(get_penyakit_kronis($row)),
    ];
}, $all_data));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Pasien — Epuskesmas Tasikmalaya</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
.fade { animation: fadeIn .15s ease }
@keyframes fadeIn { from { opacity:0; transform:translateY(3px) } to { opacity:1; transform:none } }
</style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased">

<nav class="bg-[#0f4c81] text-white px-5 py-3 flex items-center gap-3 shadow-md sticky top-0 z-10">
    <span class="text-xl">🏥</span>
    <div>
        <div class="font-semibold text-sm leading-tight">Epuskesmas Tasikmalaya</div>
        <div class="text-[11px] text-blue-200">Data Pasien</div>
    </div>
</nav>

<div class="max-w-screen-xl mx-auto px-4 py-5 space-y-4">

     Form Tarik Data 
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
        <form method="POST" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Preset</label>
                <select onchange="this.form.total.value=this.value"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">-- Pilih --</option>
                    <option value="10">10 Data</option>
                    <option value="100">100 Data</option>
                    <option value="500">500 Data</option>
                    <option value="1000">1.000 Data</option>
                    <option value="5000">5.000 Data</option>
                    <option value="999999">Semua</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Manual</label>
                <input type="number" name="total" placeholder="misal: 250" min="1"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-36 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit"
                class="bg-[#0f4c81] hover:bg-[#1a6db5] text-white text-sm font-semibold px-5 py-2 rounded-lg transition-colors">
                Tarik Data
            </button>
        </form>
    </div>

    <?php if ($total_ambil > 0): ?>

     Stat Cards 
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <?php
        $stats = [
            ['Total di Server',  number_format($total_records),        'text-blue-700',   'bg-blue-50'],
            ['Diminta',          number_format($total_ambil),           'text-indigo-700', 'bg-indigo-50'],
            ['Berhasil Ditarik', number_format(count($all_data)),       'text-green-700',  'bg-green-50'],
            ['Waktu Request',    number_format($total_duration, 0).'ms','text-orange-700', 'bg-orange-50'],
        ];
        foreach ($stats as [$label, $value, $tc, $bg]): ?>
        <div class="<?= $bg ?> rounded-xl border border-slate-200 p-4">
            <div class="text-[11px] text-slate-500 uppercase tracking-wide font-semibold mb-1"><?= $label ?></div>
            <div class="text-xl font-bold <?= $tc ?>"><?= $value ?></div>
        </div>
        <?php endforeach; ?>
    </div>

     Panel Filter 
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
            <span class="text-sm font-semibold text-slate-700">🔽 Filter Data</span>
            <button onclick="reset_filter()"
                class="text-xs text-red-500 hover:text-red-700 font-semibold transition-colors">
                Reset Filter
            </button>
        </div>
        <div class="px-4 py-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Jenis Kelamin</label>
                <select id="f_gender"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua</option>
                    <option value="laki">Laki-laki</option>
                    <option value="perempuan">Perempuan</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Rentang Umur</label>
                <div class="flex items-center gap-2">
                    <input type="number" id="f_umur_min" placeholder="Min" min="0"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <span class="text-slate-400 text-xs shrink-0">–</span>
                    <input type="number" id="f_umur_max" placeholder="Max" min="0"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Kelurahan</label>
                <select id="f_kelurahan"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Kecamatan</label>
                <select id="f_kecamatan"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua</option>
                </select>
            </div>
        </div>
        <div id="filter_info" class="hidden px-4 pb-3 text-xs text-blue-600 font-semibold"></div>
    </div>

    <?php endif; ?>

     Tabel 
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <span class="text-sm font-semibold text-slate-700">Tabel Pasien</span>
            <div class="flex flex-wrap items-center gap-2">
                <?php if (!empty($all_data)): ?>
                <button onclick="export_xlsx()"
                    class="flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V4"/>
                    </svg>
                    Export XLSX
                </button>
                <?php endif; ?>
                <input type="text" id="search_input" placeholder="Cari nama, NIK, alamat..."
                    class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm w-48 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <select id="per_page_select"
                    class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="25">25 / hal</option>
                    <option value="50">50 / hal</option>
                    <option value="100" selected>100 / hal</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-max">
                <thead>
                    <tr class="bg-[#0f4c81] text-white text-[11px] uppercase tracking-wide">
                        <th class="px-3 py-3 text-center w-10">#</th>
                        <th class="px-3 py-3 text-left">NIK</th>
                        <th class="px-3 py-3 text-left">Nama Pasien</th>
                        <th class="px-3 py-3 text-left">No. Telp</th>
                        <th class="px-3 py-3 text-center">Jenis Kelamin</th>
                        <th class="px-3 py-3 text-left">Tgl. Lahir</th>
                        <th class="px-3 py-3 text-center">Umur</th>
                        <th class="px-3 py-3 text-left">Pekerjaan</th>
                        <th class="px-3 py-3 text-left">Alamat</th>
                        <th class="px-3 py-3 text-center">RT</th>
                        <th class="px-3 py-3 text-center">RW</th>
                        <th class="px-3 py-3 text-left">Kelurahan</th>
                        <th class="px-3 py-3 text-left">Kecamatan</th>
                        <th class="px-3 py-3 text-left">Penyakit Kronis</th>
                    </tr>
                </thead>
                <tbody id="table_body"></tbody>
            </table>
        </div>

        <div class="px-4 py-3 border-t border-slate-100 bg-slate-50 flex flex-wrap items-center justify-between gap-3">
            <span id="info_text" class="text-xs text-slate-500"></span>
            <div id="pagination" class="flex flex-wrap items-center gap-1"></div>
        </div>
    </div>

</div>

<script>
const ALL_ROWS = <?= $rows_json ?>;
let filtered   = [...ALL_ROWS];
let cur_page   = 1;
let per_page   = 100;

const unique_sorted = key => [...new Set(ALL_ROWS.map(r => r[key]).filter(Boolean))].sort();

function init_dropdowns() {
    const fill = (id, values) => {
        const el = document.getElementById(id);
        values.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.toLowerCase();
            opt.textContent = v;
            el.appendChild(opt);
        });
    };
    fill('f_kelurahan', unique_sorted('kelurahan'));
    fill('f_kecamatan', unique_sorted('kecamatan'));
}

function apply_filter() {
    const gender    = document.getElementById('f_gender').value.toLowerCase();
    const umur_min  = parseInt(document.getElementById('f_umur_min').value) || 0;
    const umur_max  = parseInt(document.getElementById('f_umur_max').value) || 999;
    const kelurahan = document.getElementById('f_kelurahan').value.toLowerCase();
    const kecamatan = document.getElementById('f_kecamatan').value.toLowerCase();
    const keyword   = document.getElementById('search_input').value.toLowerCase().trim();

    filtered = ALL_ROWS.filter(r => {
        if (gender && !r.jenis_kelamin.toLowerCase().includes(gender)) return false;
        if (r.umur_tahun < umur_min || r.umur_tahun > umur_max) return false;
        if (kelurahan && r.kelurahan.toLowerCase() !== kelurahan) return false;
        if (kecamatan && r.kecamatan.toLowerCase() !== kecamatan) return false;
        if (keyword && !(
            r.nama_pasien.toLowerCase().includes(keyword) ||
            r.nik.toLowerCase().includes(keyword) ||
            r.alamat.toLowerCase().includes(keyword) ||
            r.kelurahan.toLowerCase().includes(keyword) ||
            r.kecamatan.toLowerCase().includes(keyword) ||
            r.penyakit_kronis.toLowerCase().includes(keyword)
        )) return false;
        return true;
    });

    const active_filters = [
        gender ? `Gender: ${gender}` : '',
        (umur_min || umur_max < 999) ? `Umur: ${umur_min}–${umur_max} th` : '',
        kelurahan ? `Kel: ${kelurahan}` : '',
        kecamatan ? `Kec: ${kecamatan}` : '',
        keyword ? `Cari: "${keyword}"` : '',
    ].filter(Boolean);

    const info = document.getElementById('filter_info');
    if (info) {
        if (active_filters.length) {
            info.textContent = '🔍 Filter aktif: ' + active_filters.join(' · ') + ` — ${filtered.length} data`;
            info.classList.remove('hidden');
        } else {
            info.classList.add('hidden');
        }
    }

    cur_page = 1;
    render();
}

function reset_filter() {
    document.getElementById('f_gender').value    = '';
    document.getElementById('f_umur_min').value  = '';
    document.getElementById('f_umur_max').value  = '';
    document.getElementById('f_kelurahan').value = '';
    document.getElementById('f_kecamatan').value = '';
    document.getElementById('search_input').value = '';
    filtered = [...ALL_ROWS];
    const info = document.getElementById('filter_info');
    if (info) info.classList.add('hidden');
    cur_page = 1;
    render();
}

const gender_badge = v => {
    if (!v) return '<span class="text-slate-400">-</span>';
    const is_p = v.toLowerCase().includes('per');
    return `<span class="px-2 py-0.5 rounded-full text-[11px] font-semibold ${is_p ? 'bg-pink-100 text-pink-700' : 'bg-blue-100 text-blue-700'}">${v}</span>`;
};

const age_badge = v =>
    v ? `<span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-700">${v} th</span>`
      : '<span class="text-slate-400">-</span>';

const kronis_badge = v =>
    v ? `<span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-700">${v}</span>`
      : '<span class="text-slate-300 text-[11px]">—</span>';

const dash = v => (v !== '' && v !== null && v !== undefined) ? v : '<span class="text-slate-400">-</span>';

function render() {
    const start = (cur_page - 1) * per_page;
    const slice = filtered.slice(start, start + per_page);
    const tbody = document.getElementById('table_body');

    if (!slice.length) {
        tbody.innerHTML = `<tr><td colspan="14" class="py-20 text-center text-slate-400 text-sm">Tidak ada data ditemukan.</td></tr>`;
        document.getElementById('pagination').innerHTML = '';
        document.getElementById('info_text').textContent = '';
        return;
    }

    tbody.innerHTML = slice.map((r, i) => `
        <tr class="border-b border-slate-100 hover:bg-blue-50/40 transition-colors fade ${(start+i)%2===1?'bg-slate-50/60':''}">
            <td class="px-3 py-2.5 text-center text-slate-400 text-xs font-medium">${start+i+1}</td>
            <td class="px-3 py-2.5 font-mono text-xs text-slate-600">${dash(r.nik)}</td>
            <td class="px-3 py-2.5 font-semibold text-slate-800 whitespace-nowrap">${dash(r.nama_pasien)}</td>
            <td class="px-3 py-2.5 text-xs text-slate-600">${dash(r.no_telp)}</td>
            <td class="px-3 py-2.5 text-center">${gender_badge(r.jenis_kelamin)}</td>
            <td class="px-3 py-2.5 text-xs text-slate-600 whitespace-nowrap">${dash(r.tanggal_lahir)}</td>
            <td class="px-3 py-2.5 text-center">${age_badge(r.umur_tahun)}</td>
            <td class="px-3 py-2.5 text-xs text-slate-600">${dash(r.pekerjaan)}</td>
            <td class="px-3 py-2.5 text-xs text-slate-600 max-w-[180px] truncate" title="${r.alamat}">${dash(r.alamat)}</td>
            <td class="px-3 py-2.5 text-center text-xs text-slate-600">${dash(r.rt)}</td>
            <td class="px-3 py-2.5 text-center text-xs text-slate-600">${dash(r.rw)}</td>
            <td class="px-3 py-2.5 text-xs text-slate-600 whitespace-nowrap">${dash(r.kelurahan)}</td>
            <td class="px-3 py-2.5 text-xs text-slate-600 whitespace-nowrap">${dash(r.kecamatan)}</td>
            <td class="px-3 py-2.5">${kronis_badge(r.penyakit_kronis)}</td>
        </tr>
    `).join('');

    render_pagination();

    const end = Math.min(start + per_page, filtered.length);
    document.getElementById('info_text').textContent =
        `${start+1}–${end} dari ${filtered.length} data` +
        (filtered.length < ALL_ROWS.length ? ` (filter dari ${ALL_ROWS.length})` : '');
}

function render_pagination() {
    const total_p = Math.ceil(filtered.length / per_page);
    const el      = document.getElementById('pagination');
    if (total_p <= 1) { el.innerHTML = ''; return; }

    const base   = 'px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors ';
    const active = base + 'bg-[#0f4c81] text-white border-[#0f4c81]';
    const normal = base + 'bg-white text-slate-600 border-slate-300 hover:border-blue-400 hover:text-blue-600 cursor-pointer';
    const off    = base + 'bg-slate-100 text-slate-300 border-slate-200 cursor-not-allowed';

    const btn = (lbl, pg, dis, act) =>
        `<button class="${act?active:dis?off:normal}" ${dis?'disabled':''} onclick="go(${pg})">${lbl}</button>`;

    let s = Math.max(1, cur_page - 2);
    let e = Math.min(total_p, s + 4);
    if (e - s < 4) s = Math.max(1, e - 4);

    let html = btn('‹', cur_page - 1, cur_page === 1, false);
    if (s > 1) html += btn(1, 1, false, false) + (s > 2 ? '<span class="text-slate-400 text-xs px-1">…</span>' : '');
    for (let p = s; p <= e; p++) html += btn(p, p, false, p === cur_page);
    if (e < total_p) html += (e < total_p - 1 ? '<span class="text-slate-400 text-xs px-1">…</span>' : '') + btn(total_p, total_p, false, false);
    html += btn('›', cur_page + 1, cur_page === total_p, false);

    el.innerHTML = html;
}

function go(p) {
    const total_p = Math.ceil(filtered.length / per_page);
    if (p < 1 || p > total_p) return;
    cur_page = p;
    render();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function export_xlsx() {
    const headers = ['No','NIK','Nama Pasien','No. Telp','Jenis Kelamin','Tgl. Lahir','Umur','Pekerjaan','Alamat','RT','RW','Kelurahan','Kecamatan','Penyakit Kronis'];
    const rows = filtered.map((r, i) => [
        i + 1, r.nik, r.nama_pasien, r.no_telp, r.jenis_kelamin,
        r.tanggal_lahir, r.umur_tahun ? r.umur_tahun + ' th' : '',
        r.pekerjaan, r.alamat, r.rt, r.rw,
        r.kelurahan, r.kecamatan, r.penyakit_kronis
    ]);

    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    ws['!cols'] = [
        {wch:5},{wch:18},{wch:30},{wch:15},{wch:14},
        {wch:14},{wch:8},{wch:18},{wch:35},{wch:5},
        {wch:5},{wch:20},{wch:20},{wch:25}
    ];

    const wb  = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Data Pasien');

    const now = new Date();
    const ts  = `${now.getFullYear()}${String(now.getMonth()+1).padStart(2,'0')}${String(now.getDate()).padStart(2,'0')}`;
    XLSX.writeFile(wb, `data_pasien_${ts}.xlsx`);
}

document.getElementById('per_page_select').addEventListener('change', function() {
    per_page = parseInt(this.value);
    cur_page = 1;
    render();
});

let search_timer;
document.getElementById('search_input').addEventListener('input', function() {
    clearTimeout(search_timer);
    search_timer = setTimeout(apply_filter, 250);
});

['f_gender','f_kelurahan','f_kecamatan'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', apply_filter);
});

['f_umur_min','f_umur_max'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => {
        clearTimeout(search_timer);
        search_timer = setTimeout(apply_filter, 400);
    });
});

init_dropdowns();
render();
</script>
</body>
</html>
