<?php


$operators = $db->query("SELECT * FROM users WHERE role = 'operator' ORDER BY id DESC")->fetchAll();
?>

<main class="flex-1 p-4 md:p-6 xl:p-8 bg-slate-50/50">
    <div class="w-full max-w-[1820px] mx-auto">

    <div class="mb-7 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center shadow-sm flex-shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 tracking-tight leading-none">Kelola Operator</h2>
                <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Tambah dan atur akun operator serta kuota paket</p>
            </div>
        </div>
        <button onclick="document.getElementById('modalAdd').classList.remove('hidden')"
            class="flex items-center justify-center gap-2 px-4 py-2.5 bg-teal-600 hover:bg-teal-700
                   text-white rounded-xl text-sm font-bold transition-colors shadow-sm active:scale-95">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
            </svg>
            <span>Tambah Operator</span>
        </button>
    </div>

    <?php if (!empty($success)): ?>
        <div class="mb-5 flex items-center gap-3 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700 text-sm font-semibold">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <?= $success ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="mb-5 flex items-center gap-3 px-4 py-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-600 text-sm font-semibold">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[800px]">
                <thead>
                    <tr class="bg-slate-50 text-[11px] font-bold text-slate-400 uppercase tracking-widest">
                        <th class="px-4 py-3 text-left">Operator</th>
                        <th class="px-4 py-3 text-left">Paket</th>
                        <th class="px-4 py-3 text-left">Masa / Kuota</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody id="operatorTableBody" class="divide-y divide-slate-50 relative">
                    <?php require_once __DIR__ . '/table_data.php'; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
</main>

<style>
    .inp {
        width: 100%;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: .75rem;
        padding: .65rem 1rem;
        font-size: .875rem;
        outline: none;
        transition: border-color .15s, background .15s;
        color: #0f172a;
    }

    .inp:focus {
        border-color: #0d9488;
        background: #fff;
    }

    .lbl {
        display: block;
        font-size: .68rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .07em;
        margin-bottom: .375rem;
    }


    .z-row {
        animation: fadeInRow 0.3s ease;
    }

    @keyframes fadeInRow {
        from {
            opacity: 0.95;
        }

        to {
            opacity: 1;
        }
    }
</style>

<?php require_once __DIR__ . '/modal_add.php'; ?>
<?php require_once __DIR__ . '/modal_edit.php'; ?>
<?php require_once __DIR__ . '/modal_quota.php'; ?>

<script src="user/script.js?v=<?= time() ?>"></script>
