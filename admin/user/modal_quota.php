<!-- ══ MODAL TAMBAH KUOTA ══ -->
<div id="modalQuota" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xs">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-bold text-slate-800">Tambah Kuota NIK</h3>
            <p id="quotaInfoText" class="text-xs text-slate-400 mt-0.5"></p>
        </div>
        <form method="POST" action="user/actions.php" class="p-6 space-y-4">
            <input type="hidden" name="action" value="add_quota">
            <input type="hidden" name="user_id" id="quotaUserId">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div>
                <label class="lbl">Jumlah NIK ditambahkan</label>
                <input type="number" name="add_quota" min="1" value="100" class="inp">
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="document.getElementById('modalQuota').classList.add('hidden')"
                    class="flex-1 py-2.5 bg-slate-100 text-slate-700 rounded-xl text-sm font-bold transition-colors">Batal</button>
                <button type="submit" class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition-colors">Tambah</button>
            </div>
        </form>
    </div>
</div>