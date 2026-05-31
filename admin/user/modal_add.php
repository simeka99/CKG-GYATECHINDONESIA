<!-- ══ MODAL TAMBAH ══ -->
<div id="modalAdd" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-start justify-center p-4 overflow-y-auto w-[100vw] h-[100vh]">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md md:max-w-2xl my-6 transition-all">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-bold text-slate-800">Tambah Operator Baru</h3>
            <button onclick="document.getElementById('modalAdd').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <form method="POST" action="user/actions.php" class="p-6">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Sisi Kiri: Informasi Akun -->
                <div class="space-y-4">
                    <h4 class="font-bold text-sm text-slate-700 border-b border-slate-100 pb-2 mb-3">Informasi Akun</h4>
                    <div>
                        <p class="lbl mb-2">Tipe Akses</p>
                        <div class="flex gap-2">
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="access_type" value="dashboard" class="hidden peer" checked onchange="toggle_access_type('dashboard', 'Add')">
                                <div class="text-center py-2 border-2 border-slate-200 rounded-xl text-xs font-bold text-slate-500 peer-checked:border-teal-500 peer-checked:bg-teal-50 peer-checked:text-teal-700 transition-all">Dashboard RMIK</div>
                            </label>
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="access_type" value="extension" class="hidden peer" onchange="toggle_access_type('extension', 'Add')">
                                <div class="text-center py-2 border-2 border-slate-200 rounded-xl text-xs font-bold text-slate-500 peer-checked:border-amber-500 peer-checked:bg-amber-50 peer-checked:text-amber-700 transition-all">BPJS Auto Fill Skrining</div>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="lbl">Nama Lengkap / Nama Instansi</label>
                        <input type="text" name="full_name" id="fullNameAdd" class="inp" placeholder="Contoh: Cikalong">
                        <p class="text-[10px] text-slate-400 mt-1">Cukup isi nama instansi. Sistem otomatis jadi huruf kapital, lalu username dan password huruf kecil format gya_pkm_...</p>
                    </div>
                    <div>
                        <label class="lbl">No Handphone</label>
                        <input type="text" name="no_hp" id="noHpAdd" class="inp" placeholder="08...">
                    </div>
                    <div class="border border-slate-200 rounded-xl p-3 space-y-3">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data Wali Default</p>
                        <div>
                            <label class="lbl">Nama Wali</label>
                            <input type="text" name="wali_nama" id="waliNamaAdd" class="inp" placeholder="Nama wali default">
                        </div>
                        <div>
                            <label class="lbl">NIK Wali</label>
                            <input type="text" name="wali_nik" id="waliNikAdd" class="inp" placeholder="NIK wali">
                        </div>
                        <div>
                            <label class="lbl">Instansi Puskesmas</label>
                            <input type="text" name="wali_instansi_puskesmas" id="waliInstansiAdd" class="inp" placeholder="Instansi puskesmas">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="lbl">Tgl Lahir Wali</label>
                                <input type="date" name="wali_tanggal_lahir" id="waliTglAdd" class="inp">
                            </div>
                            <div>
                                <label class="lbl">Jenis Kelamin</label>
                                <select name="wali_jenis_kelamin" id="waliJkAdd" class="inp">
                                    <option value="Perempuan">Perempuan</option>
                                    <option value="Laki-laki">Laki-laki</option>
                                </select>
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-400">Dipakai otomatis untuk semua lisensi operator ini. No HP wali otomatis ikut dari No Handphone utama.</p>
                    </div>
                    <div id="accountFieldsAdd" class="space-y-4">
                        <div>
                            <label class="lbl">Username <span class="text-rose-400">*</span></label>
                            <input type="text" name="username" id="usernameAdd" required class="inp" placeholder="username_login">
                        </div>
                        <div>
                            <label class="lbl">Password <span class="text-rose-400">*</span></label>
                            <input type="text" name="password" id="passwordAdd" required class="inp" placeholder="Password awal">
                        </div>
                    </div>
                    <div id="extensionHintAdd" class="hidden p-3 rounded-xl border border-amber-200 bg-amber-50">
                        <p class="text-[11px] font-semibold text-amber-800 leading-relaxed">
                            Mode ini tidak membuat akses login web untuk user. Sistem membuat akun internal otomatis dan user hanya menerima license key skrining BPJS.
                        </p>
                    </div>
                </div>

                <!-- Sisi Kanan: Pengaturan Paket -->
                <div class="space-y-4">
                    <h4 class="font-bold text-sm text-slate-700 border-b border-slate-100 pb-2 mb-3">Pengaturan Paket</h4>

                    <div>
                        <p class="lbl mb-2">Tipe Paket</p>
                        <div class="flex gap-2">
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="sub_main" value="time" class="hidden peer" checked onchange="toggleSubMain('time', 'Add')">
                                <div class="text-center py-2 border-2 border-slate-200 rounded-xl text-xs font-bold text-slate-500 peer-checked:border-teal-500 peer-checked:bg-teal-50 peer-checked:text-teal-700 transition-all">Berbasis Waktu</div>
                            </label>
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="sub_main" value="quota" class="hidden peer" onchange="toggleSubMain('quota', 'Add')">
                                <div class="text-center py-2 border-2 border-slate-200 rounded-xl text-xs font-bold text-slate-500 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700 transition-all">Basis NIK</div>
                            </label>
                        </div>
                    </div>

                    <div id="panelTimeAdd">
                        <div class="grid grid-cols-2 lg:grid-cols-3 gap-2 mb-3">
                            <?php
                            $pkgs = [
                                ['trial1',  'Trial 1 Hari'],
                                ['1minggu', '1 Minggu'],
                                ['1bulan',  '1 Bulan'],
                                ['3bulan',  '3 Bulan'],
                                ['6bulan',  '6 Bulan'],
                                ['1tahun',  '1 Tahun'],
                                ['custom',  'Custom Hari'],
                            ];
                            foreach ($pkgs as $pkg_item): list($val, $label) = $pkg_item; ?>
                                <label class="cursor-pointer">
                                    <input type="radio" name="sub_pkg" value="<?= $val ?>" class="hidden peer"
                                        <?= $val === '1bulan' ? 'checked' : '' ?> onchange="toggleCustomDays('<?= $val ?>', 'Add')">
                                    <div class="text-center px-1 py-1.5 border-2 border-slate-200 rounded-xl text-[10px] sm:text-xs font-bold text-slate-500 peer-checked:border-teal-500 peer-checked:bg-teal-50 peer-checked:text-teal-700 transition-all">
                                        <?= h($label) ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div id="customDaysBoxAdd" class="hidden">
                            <label class="lbl">Jumlah Hari</label>
                            <input type="number" name="sub_days" min="1" max="3650" value="30" class="inp">
                        </div>
                    </div>

                    <div id="panelQuotaAdd" class="hidden">
                        <label class="lbl">Jumlah NIK / Orang <span class="text-rose-400">*</span></label>
                        <input type="number" name="quota_total" min="1" id="quotaInputAdd" class="inp" placeholder="Contoh: 1000">
                    </div>

                    <div id="licenseGridCkgAdd">
                        <p class="lbl mb-2">Kuota License per Jenis <span class="text-slate-400 font-normal normal-case text-[10px]">(0 = tidak boleh)</span></p>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[10px] text-blue-600 font-bold uppercase tracking-wider block mb-1">Pendaftaran Umum</label>
                                <input type="number" name="lq_pendaftaran_umum" min="0" value="0" class="inp text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] text-emerald-600 font-bold uppercase tracking-wider block mb-1">Pelayanan Umum</label>
                                <input type="number" name="lq_pelayanan_umum" min="0" value="0" class="inp text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] text-indigo-600 font-bold uppercase tracking-wider block mb-1">Pendaftaran Sekolah</label>
                                <input type="number" name="lq_pendaftaran_sekolah" min="0" value="0" class="inp text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] text-violet-600 font-bold uppercase tracking-wider block mb-1">Pelayanan Sekolah</label>
                                <input type="number" name="lq_pelayanan_sekolah" min="0" value="0" class="inp text-sm">
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-400 mt-1.5">Jumlah max license key yang bisa dibuat per jenis. Di luar kuota ini tidak bisa generate.</p>
                    </div>
                    <div id="licenseGridExtensionAdd" class="hidden">
                        <p class="lbl mb-2">Kuota License BPJS Auto Fill Skrining</p>
                        <div>
                            <label class="text-[10px] text-amber-600 font-bold uppercase tracking-wider block mb-1">Skrining BPJS</label>
                            <input type="number" name="lq_extension_bpjs" id="lqExtensionAdd" min="0" value="0" class="inp text-sm">
                        </div>
                        <p class="text-[10px] text-slate-400 mt-1.5">Slot license khusus untuk BPJS Auto Fill Skrining.</p>
                    </div>

                </div>
            </div>

            <div class="flex gap-3 pt-5 mt-3 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('modalAdd').classList.add('hidden')"
                    class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-bold transition-colors">Batal</button>
                <button type="submit" class="flex-1 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold transition-colors">Simpan Operator Baru</button>
            </div>
        </form>
    </div>
</div>