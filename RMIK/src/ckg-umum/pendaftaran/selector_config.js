export const SEL = {
    nik: ['input#nik[name="NIK"]', 'input[name="NIK"]', 'input#nik'],
    nama: ['input[name="Nama"]', 'input[placeholder*="nama lengkap" i]', 'input#nama'],

    phone: [
        'input[name="Nomor Whatsapp"]',
        'input[id="No Whatsapp"]',
        'input[placeholder*="whatsapp" i]',
        'input[placeholder*="nomor hp" i]',
        'input[placeholder*="no hp" i]',
        'input[type="tel"]'
    ],

    detail_address: [
        'textarea#detail-domisili[name="detail-domisili"]',
        'textarea[name="detail-domisili"]',
        'textarea[placeholder*="jl." i]',
        'textarea[placeholder*="alamat" i]'
    ],

    wali_nik: ['input[name="NIK wali"]', 'input[placeholder*="NIK Wali" i]'],
    wali_nama: ['input[name="Nama Lengkap Wali"]', 'input[placeholder*="Nama Lengkap" i]'],

    gender_trigger_text: ["pilih jenis kelamin", "laki-laki", "perempuan"],
    gender_option: "div.py-2.px-4.cursor-pointer",

    job_trigger_sel: 'div.min-h-\\[2\\.9rem\\].w-full.flex.cursor-pointer',
    job_search_placeholder: 'Cari pekerjaan',
    job_option_sel: 'div.mt-4.px-1 button.py-4.pl-0.w-full.text-left',
    job_option_text_sel: 'div.flex.items-center.justify-between.gap-2',

    domisili_trigger_sel: 'div.min-h-\\[2\\.9rem\\].w-full.flex.cursor-pointer',
    domisili_trigger_text: 'Pilih alamat domisili',
    domisili_option_sel: 'button.py-4.pl-0.w-full.text-left',
    domisili_option_text_sel: 'div.flex.items-center.justify-between.gap-2',
    domisili_steps: [
        { key: "provinsi", placeholder: "Cari Provinsi", list_label: "Daftar Provinsi" },
        { key: "kabupaten_kota", placeholder: "Cari Kabupaten/Kota", list_label: "Daftar Kabupaten/Kota" },
        { key: "kecamatan", placeholder: "Cari Kecamatan", list_label: "Daftar Kecamatan" },
        { key: "kelurahan", placeholder: "Cari Kelurahan", list_label: "Daftar Kelurahan" }
    ],

    status_pernikahan_trigger_sel: 'div.h-\\[2\\.9rem\\].w-full.flex.gap-2.cursor-pointer.items-center.justify-start.overflow-hidden',
    status_pernikahan_trigger_text: [
        "pilih status pernikahan",
        "belum menikah",
        "menikah",
        "cerai hidup",
        "cerai mati"
    ],
    status_pernikahan_option_sel: "div.py-2.px-4.cursor-pointer.text-sm",

    disabilitas_trigger_sel: 'div.h-\\[2\\.9rem\\].w-full.flex.gap-2.cursor-pointer.items-center.justify-start.overflow-hidden',
    disabilitas_trigger_text: [
        "tidak memiliki disabilitas",
        "memiliki disabilitas",
        "memiliki disablilitas"
    ],
    disabilitas_option_sel: "div.py-2.px-4.cursor-pointer.text-sm",

    calendar_wrapper: ".mx-input-wrapper",
    calendar_root: ".mx-calendar",
    calendar_year_panel: ".mx-calendar-panel-year",
    calendar_month_panel: ".mx-calendar-panel-month",
    calendar_date_panel: ".mx-calendar-panel-date",

    exam_grid: [
        "div.shadow-gmail div.grid.grid-cols-7.gap-1.mt-2",
        "div.shadow-gmail div.grid.grid-cols-7",
        "div[class*='shadow'] div.grid.grid-cols-7"
    ]
};
