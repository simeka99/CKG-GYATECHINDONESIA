<!-- ══ MODAL EDIT ══ -->
<div id="modalEdit" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-start justify-center p-4 overflow-y-auto w-[100vw] h-[100vh]">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md md:max-w-2xl my-6 transition-all">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="font-bold text-slate-800">Edit Operator</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="edSubtitle">@username</p>
            </div>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <form method="POST" action="user/actions.php" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edUserId" value="">
            <input type="hidden" name="keep_sub" id="keepSubVal" value="1">
            <input type="hidden" name="access_type" id="edAccessType" value="dashboard">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Sisi Kiri: Informasi Akun -->
                <div class="space-y-4">
                    <h4 class="font-bold text-sm text-slate-700 border-b border-slate-100 pb-2 mb-3">Informasi Akun</h4>
                    <div>
                        <label class="lbl">Username <span class="text-rose-400">*</span></label>
                        <input type="text" name="edit_username" id="edUsername" required class="inp">
                    </div>
                    <div>
                        <label class="lbl">Nama Lengkap</label>
                        <input type="text" name="full_name" id="edFullName" class="inp" placeholder="Nama / instansi">
                    </div>
                    <div>
                        <label class="lbl">Password Baru <span class="text-slate-400 font-normal normal-case text-[10px]">(opsional)</span></label>
                        <input type="text" name="new_password" id="edPassword" class="inp" placeholder="Isi untuk ganti">
                    </div>
                    <div>
                        <label class="lbl">No Handphone</label>
                        <input type="text" name="no_hp" id="edNoHp" class="inp" placeholder="08...">
                    </div>
                    <div class="border border-slate-200 rounded-xl p-3 space-y-3">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data Wali Default</p>
                        <div>
                            <label class="lbl">Nama Wali</label>
                            <input type="text" name="edit_wali_nama" id="edWaliNama" class="inp" placeholder="Nama wali default">
                        </div>
                        <div>
                            <label class="lbl">NIK Wali</label>
                            <input type="text" name="edit_wali_nik" id="edWaliNik" class="inp" placeholder="NIK wali">
                        </div>
                        <div>
                            <label class="lbl">Instansi Puskesmas</label>
                            <input type="text" name="edit_wali_instansi_puskesmas" id="edWaliInstansi" class="inp" placeholder="Instansi puskesmas">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="lbl">Tgl Lahir Wali</label>
                                <input type="date" name="edit_wali_tanggal_lahir" id="edWaliTgl" class="inp">
                            </div>
                            <div>
                                <label class="lbl">Jenis Kelamin</label>
                                <select name="edit_wali_jenis_kelamin" id="edWaliJk" class="inp">
                                    <option value="Perempuan">Perempuan</option>
                                    <option value="Laki-laki">Laki-laki</option>
                                </select>
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-400">Nilai default untuk lisensi baru/kosong. No HP wali otomatis ikut dari No Handphone utama.</p>
                    </div>
                    <label class="flex items-center gap-3 cursor-pointer mt-2 w-max">
                        <input type="checkbox" name="is_active" id="edIsActive" class="w-4 h-4 accent-teal-600">
                        <span class="text-sm font-semibold text-slate-700">Akun Aktif</span>
                    </label>
                </div>

                <!-- Sisi Kanan: Status Langganan -->
                <div class="space-y-2">
                    <h4 class="font-bold text-sm text-slate-700 border-b border-slate-100 pb-2 mb-3">Status Langganan</h4>
                    <div id="edCurrentPkgBox" class="mb-4 p-4 rounded-xl border bg-slate-50 border-slate-200 transition-colors">
                        <div class="flex items-start justify-between mb-1">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1">Paket Saat Ini</p>
                                <p class="text-lg font-bold text-slate-800 leading-tight" id="edCurrentPkgText">—</p>
                            </div>
                            <div class="bg-white p-2 rounded-xl shadow-sm border border-slate-100 flex-shrink-0">
                                <svg class="w-5 h-5 text-teal-500" id="iconPkgTime" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-blue-500" id="iconPkgQuota" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="text-sm font-medium mt-1 text-slate-600" id="edCurrentPkgSub">—</p>

                        <div id="panelCurrentPkgEdit" class="mt-4 pt-4 border-t border-slate-200/60 flex flex-col gap-4">
                            <div class="hidden" id="edGroupQuotaTot">
                                <label class="lbl">Ubah Total Kuota NIK <span class="text-blue-500 font-normal normal-case text-[10px]">(opsional)</span></label>
                                <input type="number" name="edit_quota_tot" id="edQuotaTot" min="1" class="inp" placeholder="Contoh: 1500" oninput="document.getElementById('keepSubVal').value='1'">
                                <p class="text-[10px] text-slate-500 mt-1.5 leading-snug">Mengubah ini <b>TIDAK mereset</b> pemakaian saat ini. Hanya batas totalnya saja yang berubah.</p>
                            </div>
                            <div class="hidden flex flex-col gap-3" id="edGroupSubEnd">
                                <div>
                                    <label class="lbl">Pilih Tanggal Berakhir Baru <span class="text-teal-500 font-normal normal-case text-[10px]">(opsional)</span></label>
                                    <input type="date" name="edit_sub_end" id="edSubEnd" class="inp w-full" onchange="document.getElementById('keepSubVal').value='1'; document.getElementById('edSubPkg').value='';">
                                </div>
                                <div class="relative flex items-center">
                                    <div class="flex-grow border-t border-slate-200"></div>
                                    <span class="flex-shrink-0 mx-3 text-[10px] font-bold text-slate-400 tracking-wider">ATAU PERPANJANG</span>
                                    <div class="flex-grow border-t border-slate-200"></div>
                                </div>
                                <div>
                                    <select name="edit_sub_pkg" id="edSubPkg" class="inp w-full" onchange="document.getElementById('keepSubVal').value='1'; document.getElementById('edSubEnd').value='';">
                                        <option value="">-- Pilih penambahan waktu --</option>
                                        <option value="1minggu">+ 1 Minggu dari sekarang</option>
                                        <option value="1bulan">+ 1 Bulan dari sekarang</option>
                                        <option value="3bulan">+ 3 Bulan dari sekarang</option>
                                        <option value="6bulan">+ 6 Bulan dari sekarang</option>
                                        <option value="1tahun">+ 1 Tahun dari sekarang</option>
                                    </select>
                                </div>
                            </div>
                            <div id="edLicenseGridDashboard">
                                <p class="lbl mb-2">Kuota License per Jenis <span class="text-slate-400 font-normal normal-case text-[10px]">(0 = tidak boleh)</span></p>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-[10px] text-blue-600 font-bold uppercase tracking-wider block mb-1">Pendaftaran Umum</label>
                                        <input type="number" name="lq_pendaftaran_umum" id="edLqPendU" min="0" value="0" class="inp text-sm" oninput="document.getElementById('keepSubVal').value='1'">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-emerald-600 font-bold uppercase tracking-wider block mb-1">Pelayanan Umum</label>
                                        <input type="number" name="lq_pelayanan_umum" id="edLqPelaU" min="0" value="0" class="inp text-sm" oninput="document.getElementById('keepSubVal').value='1'">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-indigo-600 font-bold uppercase tracking-wider block mb-1">Pendaftaran Sekolah</label>
                                        <input type="number" name="lq_pendaftaran_sekolah" id="edLqPendS" min="0" value="0" class="inp text-sm" oninput="document.getElementById('keepSubVal').value='1'">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-violet-600 font-bold uppercase tracking-wider block mb-1">Pelayanan Sekolah</label>
                                        <input type="number" name="lq_pelayanan_sekolah" id="edLqPelaS" min="0" value="0" class="inp text-sm" oninput="document.getElementById('keepSubVal').value='1'">
                                    </div>
                                </div>
                            </div>
                            <div id="edLicenseGridExtension" class="hidden">
                                <p class="lbl mb-2">Kuota License BPJS Auto Fill Skrining</p>
                                <div>
                                    <label class="text-[10px] text-amber-600 font-bold uppercase tracking-wider block mb-1">Skrining BPJS</label>
                                    <input type="number" name="lq_extension_bpjs" id="edLqExtension" min="0" value="0" class="inp text-sm" oninput="document.getElementById('keepSubVal').value='1'">
                                </div>
                            </div>
                        </div>
                    </div> <!-- /edCurrentPkgBox -->

                    <div class="flex items-center gap-2 mb-3">
                        <button type="button" id="btnTukarPaket" onclick="toggleTukarPaket()" class="text-xs font-bold text-rose-600 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 px-3 py-1.5 rounded-lg transition-colors border border-rose-200">
                            ⚠ Reset & Ganti Paket Baru
                        </button>
                    </div>

                    <div id="panelGantiPaket" class="hidden animate-[fadeIn_0.2s_ease-out]">
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl mb-3">
                            <p class="text-[10px] font-bold text-amber-800 leading-snug">
                                Mengganti paket berarti menghangus-kan paket lama dan mereset tanggal/kuota mulai hari ini.
                            </p>
                        </div>
                        <div class="flex gap-2 mb-4">
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="edit_sub_main" value="time" id="edSubMainTime" class="hidden peer" onchange="toggleSubMain('time', 'Edit'); document.getElementById('keepSubVal').value='0'">
                                <div class="text-center py-2 border-2 border-slate-200 rounded-xl text-xs font-bold text-slate-500 peer-checked:border-teal-500 peer-checked:bg-teal-50 peer-checked:text-teal-700 transition-all">Basis Waktu</div>
                            </label>
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="edit_sub_main" value="quota" id="edSubMainQuota" class="hidden peer" onchange="toggleSubMain('quota', 'Edit'); document.getElementById('keepSubVal').value='0'">
                                <div class="text-center py-2 border-2 border-slate-200 rounded-xl text-xs font-bold text-slate-500 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700 transition-all">Basis NIK</div>
                            </label>
                        </div>

                        <div id="panelTimeEdit" class="hidden">
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-2 md:gap-3 mb-3">
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
                                        <input type="radio" name="edit_sub_pkg" value="<?= $val ?>" class="hidden peer"
                                            <?= $val === '1bulan' ? 'checked' : '' ?> onchange="toggleCustomDays('<?= $val ?>', 'Edit'); document.getElementById('keepSubVal').value='0'">
                                        <div class="text-center px-1 py-1.5 border-2 border-slate-200 rounded-xl text-[10px] sm:text-xs font-bold text-slate-500 peer-checked:border-teal-500 peer-checked:bg-teal-50 peer-checked:text-teal-700 transition-all">
                                            <?= h($label) ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div id="customDaysBoxEdit" class="hidden mb-3">
                                <label class="lbl">Jumlah Hari</label>
                                <input type="number" name="edit_sub_days" min="1" max="3650" value="30" class="inp" oninput="document.getElementById('keepSubVal').value='0'">
                            </div>
                        </div>

                        <div id="panelQuotaEdit" class="hidden space-y-3">
                            <div>
                                <label class="lbl">Jumlah NIK Baru</label>
                                <input type="number" name="edit_new_quota_tot" id="quotaInputEdit" min="1" class="inp" placeholder="Contoh: 1000" oninput="document.getElementById('keepSubVal').value='0'">
                            </div>
                        </div>
                    </div>
                </div> <!-- /Sisi Kanan -->
            </div>

            <div class="flex gap-3 pt-5 mt-3 border-t border-slate-100">
                <button type="button" onclick="closeEditModal()"
                    class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-bold transition-colors">Batal</button>
                <button type="submit" class="flex-1 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold transition-colors">Simpan Rekam</button>
            </div>
        </form>
    </div>
</div>