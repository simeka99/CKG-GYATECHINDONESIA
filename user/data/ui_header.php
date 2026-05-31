<?php if (!empty($success)): ?>
    <div class="flex items-center gap-3 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700 text-sm font-semibold shadow-sm mb-2 flex-shrink-0">
        <div class="w-5 h-5 bg-emerald-100 rounded-full flex items-center justify-center flex-shrink-0">
            <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>
        </div>
        <?= h($success) ?>
    </div>
<?php endif; ?>