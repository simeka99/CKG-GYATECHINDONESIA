</div><!-- end main content wrapper -->
</div><!-- end flex -->

<!-- 📱 4. MOBILE BOTTOM NAVIGATION BAR -->
<div class="lg:hidden fixed bottom-5 left-4 right-4 z-40 pb-safe pointer-events-none flex justify-center">
    <div class="relative w-full max-w-sm pointer-events-auto bg-white/80 backdrop-blur-xl rounded-full shadow-[0_8px_32px_-4px_rgba(0,0,0,0.1)] border border-white/60 ring-1 ring-slate-200/50">
        
        <div class="relative flex justify-between items-center h-[60px] px-2 z-10 w-full">
            <?php if (is_admin()): ?>
                <a href="<?= $base ?>/admin/" class="mobile-nav-item flex-1 <?= nav_dashboard_active('admin') ?>">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">Home</span>
                </a>
                <a href="<?= $base ?>/admin/users.php" class="mobile-nav-item flex-1 <?= nav_active('users') ?>">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                            d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">User</span>
                </a>
                <a href="<?= $base ?>/admin/monitor.php" class="mobile-nav-item flex-1 <?= nav_active('monitor') ?>">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">Monitor</span>
                </a>
                <a href="<?= $base ?>/admin/licenses.php" class="mobile-nav-item flex-1 <?= nav_active('licenses') ?>">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                            d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">Keys</span>
                </a>
                <a href="<?= $base ?>/admin/settings.php" class="mobile-nav-item flex-1 <?= nav_active('settings') ?>">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">Settings</span>
                </a>
            <?php else: ?>
                <a href="<?= $base ?>/user/" class="mobile-nav-item flex-1 <?= nav_dashboard_active('user') ?>">
                    <div class="icon-wrap transition-transform duration-300">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                    </div>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">Home</span>
                </a>
                <a href="<?= $base ?>/user/licenses.php" class="mobile-nav-item flex-1 <?= nav_active('licenses') ?>">
                    <div class="icon-wrap transition-transform duration-300">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">Lisensi</span>
                </a>

                <div class="flex-1 flex flex-col justify-center items-center relative h-full">
                    <a href="<?= $base ?>/user/jobs.php"
                        class="absolute -top-7 flex items-center justify-center w-[58px] h-[58px] rounded-full bg-gradient-to-tr from-teal-500 to-teal-600 border-[4px] border-slate-50 text-white shadow-[0_8px_16px_rgba(20,184,166,0.4)] transition-transform active:scale-90 hover:-translate-y-1 <?= nav_active('jobs') ? 'ring-2 ring-teal-200 ring-offset-2 ring-offset-slate-50 bg-teal-600 outline-none' : '' ?>">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </a>
                </div>

                <a href="<?= $base ?>/user/monitor.php" class="mobile-nav-item flex-1 <?= nav_active('monitor') ?>">
                    <div class="icon-wrap transition-transform duration-300">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">Monitor</span>
                </a>
                <button onclick="toggleMobileMenu()" class="mobile-nav-item flex-1">
                    <div class="icon-wrap transition-transform duration-300">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 6h16M4 12h16m-7 6h7" />
                        </svg>
                    </div>
                    <span class="text-[9px] font-bold mt-1 tracking-wide">Menu</span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 📱 5. MOBILE BOTTOM SHEET MENU (Menu Lainnya) -->
<div id="mobileMenuSheet"
    class="hidden fixed inset-0 z-50 flex items-end bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0"
    onclick="if(event.target === this) toggleMobileMenu()">
    <div class="bg-white w-full rounded-t-3xl pb-10 pt-4 px-6 transform translate-y-full transition-transform duration-300"
        id="mobileMenuPanel">
        <div class="w-12 h-1.5 bg-slate-200 rounded-full mx-auto mb-6"></div>
        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Navigasi Lainnya</h3>
        <div class="grid grid-cols-4 gap-4">
            <?php if (is_admin()): ?>
                <a href="<?= $base ?>/admin/monitor.php" class="flex flex-col items-center gap-2">
                    <div class="w-12 h-12 rounded-2xl bg-violet-50 text-violet-600 flex items-center justify-center mb-1">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <span class="text-[10px] font-bold text-slate-600 text-center">Monitor</span>
                </a>
                <a href="<?= $base ?>/admin/settings.php" class="flex flex-col items-center gap-2">
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mb-1"><svg
                            class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg></div>
                    <span class="text-[10px] font-bold text-slate-600 text-center">Seting</span>
                </a>
            <?php else: ?>
                <a href="<?= $base ?>/user/upload.php" class="flex flex-col items-center gap-2">
                    <div class="w-12 h-12 rounded-2xl bg-cyan-50 text-cyan-600 flex items-center justify-center mb-1"><svg
                            class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg></div>
                    <span class="text-[10px] font-bold text-slate-600 text-center">Upload Data</span>
                </a>
                <a href="<?= $base ?>/user/data.php" class="flex flex-col items-center gap-2">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mb-1">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 10h18M3 14h18M10 4v16M6 4h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2z" />
                        </svg>
                    </div>
                    <span class="text-[10px] font-bold text-slate-600 text-center">Data Peserta</span>
                </a>
                <a href="<?= $base ?>/user/pelayanan.php" class="flex flex-col items-center gap-2">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-1">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                    </div>
                    <span class="text-[10px] font-bold text-slate-600 text-center">Pelayanan</span>
                </a>
                <a href="<?= $base ?>/user/settings.php" class="flex flex-col items-center gap-2">
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mb-1"><svg
                            class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg></div>
                    <span class="text-[10px] font-bold text-slate-600 text-center">Settings</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    /* ── Sidebar JS ── */
    function toggleTopMenu() {
        const m = document.getElementById('topMenu');
        m.classList.contains('hidden') ? m.classList.remove('hidden') : m.classList.add('hidden');
    }

    function toggleDeskMenu() {
        const m = document.getElementById('deskMenu');
        m.classList.contains('hidden') ? m.classList.remove('hidden') : m.classList.add('hidden');
    }

    function toggleMobileMenu() {
        const sh = document.getElementById('mobileMenuSheet');
        const pn = document.getElementById('mobileMenuPanel');
        if (sh.classList.contains('hidden')) {
            sh.classList.remove('hidden');
            setTimeout(() => {
                sh.classList.remove('opacity-0');
                pn.classList.remove('translate-y-full');
            }, 10);
        } else {
            sh.classList.add('opacity-0');
            pn.classList.add('translate-y-full');
            setTimeout(() => {
                sh.classList.add('hidden');
            }, 300);
        }
    }

    document.addEventListener('click', function(e) {
        var tBtn = document.getElementById('topAvatarBtn'),
            tMenu = document.getElementById('topMenu');
        if (tBtn && tMenu && !tBtn.contains(e.target) && !tMenu.contains(e.target)) tMenu.classList.add('hidden');

        var dBtn = document.getElementById('deskAvatarBtn'),
            dMenu = document.getElementById('deskMenu');
        if (dBtn && dMenu && !dBtn.contains(e.target) && !dMenu.contains(e.target)) dMenu.classList.add('hidden');
    });

    /* ── IDLE TIMER JS ── */
    (function() {
        const IDLE_LIMIT = 60 * 60,
            WARN_AT = IDLE_LIMIT - (5 * 60);
        let idleSeconds = 0,
            warnShown = false;
        const resetIdle = () => {
            idleSeconds = 0;
            warnShown = false;
            const box = document.getElementById('idleWarnBox');
            if (box) box.classList.add('hidden');
        };
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(ev => document.addEventListener(ev, resetIdle, {
            passive: true
        }));
        setInterval(() => {
            idleSeconds++;
            if (idleSeconds >= WARN_AT && !warnShown) {
                warnShown = true;
                const box = document.getElementById('idleWarnBox');
                if (box) box.classList.remove('hidden');
            }
            if (idleSeconds >= IDLE_LIMIT) window.location.href = '/auth/logout.php';
            const cd = document.getElementById('idleCountdown');
            if (cd && warnShown) {
                const sisa = IDLE_LIMIT - idleSeconds;
                cd.textContent = Math.floor(sisa / 60) + ':' + String(sisa % 60).padStart(2, '0');
            }
        }, 1000);
    })();
</script>

<!-- Warning Toast -->
<div id="idleWarnBox"
    class="hidden fixed bottom-24 lg:bottom-5 right-5 z-[9999] max-w-sm bg-amber-50 border border-amber-300 rounded-2xl shadow-xl p-4 flex items-start gap-3">
    <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
    <div class="flex-1">
        <p class="text-sm font-bold text-amber-800">Sesi hampir berakhir</p>
        <p class="text-xs text-amber-700 mt-0.5">Logout otomatis dalam <span id="idleCountdown"
                class="font-bold">5:00</span>. Gerakkan mouse untuk tetap aktif.</p>
    </div>
</div>

</body>

</html>
