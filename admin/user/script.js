function gid(id)
{
    return document.getElementById(id);
}

function upper_case_text(value)
{
    return String(value || '').toUpperCase();
}

function to_title_case_words(value)
{
    return value.toUpperCase();
}

function normalize_instansi_name(full_name)
{
    const lower_name = String(full_name || '').toLowerCase();
    let cleaned_name = lower_name
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    if (!cleaned_name)
        return '';
    cleaned_name = cleaned_name.replace(/^pkm\b/, 'puskesmas');
    if (!/^puskesmas\b/.test(cleaned_name))
        cleaned_name = 'puskesmas ' + cleaned_name;
    return to_title_case_words(cleaned_name);
}

function sanitize_instansi_name(full_name)
{
    const normalized_name = normalize_instansi_name(full_name);
    return normalized_name
        .toLowerCase()
        .replace(/\bpuskesmas\b/g, 'pkm')
        .replace(/\s+/g, ' ')
        .trim();
}

function build_operator_defaults(full_name)
{
    const sanitized_name = sanitize_instansi_name(full_name);
    const fallback_name = sanitized_name || 'puskesmas';
    const slug_name = fallback_name
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 40);
    const username_value = ('gya_' + (slug_name || 'operator')).slice(0, 48);
    const password_value = username_value;
    return { username_value, password_value };
}

function attach_operator_auto_fill()
{
    const full_name_input = gid('fullNameAdd');
    const username_input = gid('usernameAdd');
    const password_input = gid('passwordAdd');
    const wali_nama_input = gid('waliNamaAdd');
    const wali_instansi_input = gid('waliInstansiAdd');
    if (!full_name_input || !username_input || !password_input)
        return;

    let username_touched = false;
    let password_touched = false;

    const apply_upper_wali_name = () =>
    {
        if (wali_nama_input)
            wali_nama_input.value = upper_case_text(wali_nama_input.value);
    };

    const fill_defaults = () =>
    {
        const defaults = build_operator_defaults(full_name_input.value);
        const normalized_name = normalize_instansi_name(full_name_input.value);
        if (!username_touched || !username_input.value.trim())
            username_input.value = defaults.username_value;
        if (!password_touched || !password_input.value.trim())
            password_input.value = defaults.password_value;
        if (wali_instansi_input && !wali_instansi_input.value.trim())
            wali_instansi_input.value = normalized_name || '';
    };

    full_name_input.addEventListener('input', fill_defaults);
    full_name_input.addEventListener('blur', () =>
    {
        const normalized_name = normalize_instansi_name(full_name_input.value);
        if (normalized_name)
            full_name_input.value = normalized_name;
        fill_defaults();
    });
    username_input.addEventListener('input', () =>
    {
        username_touched = username_input.value.trim().length > 0;
    });
    password_input.addEventListener('input', () =>
    {
        password_touched = password_input.value.trim().length > 0;
    });
    if (wali_nama_input)
    {
        wali_nama_input.addEventListener('input', apply_upper_wali_name);
        wali_nama_input.addEventListener('blur', apply_upper_wali_name);
        apply_upper_wali_name();
    }

    fill_defaults();
}

function attach_edit_wali_name_auto_upper()
{
    const edit_wali_name_input = gid('edWaliNama');
    if (!edit_wali_name_input)
        return;
    const apply_upper_wali_name = () =>
    {
        edit_wali_name_input.value = upper_case_text(edit_wali_name_input.value);
    };
    edit_wali_name_input.addEventListener('input', apply_upper_wali_name);
    edit_wali_name_input.addEventListener('blur', apply_upper_wali_name);
}

function toggleSubMain(mode, prefix)
{
    let pTime = gid('panelTime' + prefix);
    let pQuota = gid('panelQuota' + prefix);
    let qInput = gid('quotaInput' + prefix);
    if (pTime) pTime.classList.toggle('hidden', mode !== 'time');
    if (pQuota) pQuota.classList.toggle('hidden', mode !== 'quota');
    if (qInput) qInput.required = (mode === 'quota');
}

function toggleCustomDays(val, prefix)
{
    let cb = gid('customDaysBox' + prefix);
    if (cb) cb.classList.toggle('hidden', val !== 'custom');
}

function toggle_access_type(access_type, prefix)
{
    const is_extension = access_type === 'extension';
    const account_fields = gid('accountFields' + prefix);
    const extension_hint = gid('extensionHint' + prefix);
    const license_grid_dashboard = gid('licenseGridCkg' + prefix);
    const license_grid_extension = gid('licenseGridExtension' + prefix);
    const username_input = gid('username' + prefix);
    const password_input = gid('password' + prefix);
    const full_name_input = gid('fullName' + prefix);
    const no_hp_input = gid('noHp' + prefix);
    const extension_quota_input = gid('lqExtension' + prefix);

    if (account_fields) account_fields.classList.toggle('hidden', is_extension);
    if (extension_hint) extension_hint.classList.toggle('hidden', !is_extension);
    if (license_grid_dashboard) license_grid_dashboard.classList.toggle('hidden', is_extension);
    if (license_grid_extension) license_grid_extension.classList.toggle('hidden', !is_extension);
    if (username_input) username_input.required = !is_extension;
    if (password_input) password_input.required = !is_extension;
    if (full_name_input) full_name_input.required = is_extension;
    if (no_hp_input) no_hp_input.required = is_extension;
    if (extension_quota_input) extension_quota_input.required = is_extension;
}

function set_edit_access_type(access_type)
{
    const is_extension = access_type === 'extension';
    if (gid('edAccessType')) gid('edAccessType').value = is_extension ? 'extension' : 'dashboard';
    if (gid('edLicenseGridDashboard')) gid('edLicenseGridDashboard').classList.toggle('hidden', is_extension);
    if (gid('edLicenseGridExtension')) gid('edLicenseGridExtension').classList.toggle('hidden', !is_extension);
    if (gid('edLqExtension')) gid('edLqExtension').required = is_extension;
}

function openEditModal(op)
{
    const default_wali_profile = op.default_wali_profile || {};
    if (gid('edUserId')) gid('edUserId').value = op.id;
    if (gid('edUsername')) gid('edUsername').value = op.username;
    if (gid('edFullName')) gid('edFullName').value = op.full_name || '';
    if (gid('edNoHp')) gid('edNoHp').value = op.no_hp || '';
    if (gid('edWaliNama')) gid('edWaliNama').value = upper_case_text(default_wali_profile.wali_nama || '');
    if (gid('edWaliNik')) gid('edWaliNik').value = default_wali_profile.wali_nik || '';
    if (gid('edWaliInstansi')) gid('edWaliInstansi').value = default_wali_profile.wali_instansi_puskesmas || '';
    if (gid('edWaliTgl')) gid('edWaliTgl').value = default_wali_profile.wali_tanggal_lahir || '';
    if (gid('edWaliJk')) gid('edWaliJk').value = default_wali_profile.wali_jenis_kelamin || 'Perempuan';
    if (gid('edIsActive')) gid('edIsActive').checked = (op.is_active == 1);
    if (gid('edSubtitle')) gid('edSubtitle').textContent = '@' + op.username;

    let isQuota = op.subscription_type === 'quota';
    let pEnd = op.subscription_end;
    let pkgBox = gid('edCurrentPkgBox');

    if (pkgBox)
    {
        if (isQuota)
        {
            pkgBox.className = 'mb-4 p-4 rounded-xl border bg-blue-50/50 border-blue-200 transition-colors';
            if (gid('edCurrentPkgText')) gid('edCurrentPkgText').textContent = 'Paket NIK / Quota';
            if (gid('edCurrentPkgSub')) gid('edCurrentPkgSub').textContent = 'Total Kuota: ' + op.quota_total + ' NIK';
            if (gid('edGroupQuotaTot')) gid('edGroupQuotaTot').classList.remove('hidden');
            if (gid('edGroupSubEnd')) gid('edGroupSubEnd').classList.add('hidden');
            if (gid('edQuotaTot')) gid('edQuotaTot').value = op.quota_total;
            let iT = gid('iconPkgTime'); if (iT) iT.style.display = 'none';
            let iQ = gid('iconPkgQuota'); if (iQ) iQ.style.display = 'block';
        } else
        {
            pkgBox.className = 'mb-4 p-4 rounded-xl border bg-teal-50/50 border-teal-200 transition-colors';
            if (gid('edCurrentPkgText')) gid('edCurrentPkgText').textContent = 'Paket Berbasis Waktu';
            if (pEnd)
            {
                let diffDays = Math.ceil((new Date(pEnd.replace(/-/g, '/')) - new Date()) / (1000 * 60 * 60 * 24));
                let sisaHtml = diffDays > 0
                    ? `<span class="font-bold text-teal-700"> (sisa ${diffDays} hari)</span>`
                    : `<span class="font-bold text-rose-600"> (EXPIRED)</span>`;
                if (gid('edCurrentPkgSub')) gid('edCurrentPkgSub').innerHTML = 'Sampai ' + pEnd + sisaHtml;
            } else
            {
                if (gid('edCurrentPkgSub')) gid('edCurrentPkgSub').textContent = 'Belum diset';
            }
            if (gid('edGroupSubEnd')) gid('edGroupSubEnd').classList.remove('hidden');
            if (gid('edGroupQuotaTot')) gid('edGroupQuotaTot').classList.add('hidden');
            let iT = gid('iconPkgTime'); if (iT) iT.style.display = 'block';
            let iQ = gid('iconPkgQuota'); if (iQ) iQ.style.display = 'none';
        }
    }



    const lq = op.license_quota || {};
    const safe = v => parseInt(v) >= 0 ? parseInt(v) : 0;
    const is_extension = parseInt(op.portal_access || 1) === 0;
    set_edit_access_type(is_extension ? 'extension' : 'dashboard');
    if (gid('edLqPendU')) gid('edLqPendU').value = safe(lq.pendaftaran_umum);
    if (gid('edLqPelaU')) gid('edLqPelaU').value = safe(lq.pelayanan_umum);
    if (gid('edLqPendS')) gid('edLqPendS').value = safe(lq.pendaftaran_sekolah);
    if (gid('edLqPelaS')) gid('edLqPelaS').value = safe(lq.pelayanan_sekolah);
    if (gid('edLqExtension')) gid('edLqExtension').value = safe(lq.extension_bpjs);

    if (gid('panelGantiPaket')) gid('panelGantiPaket').classList.add('hidden');
    if (gid('btnTukarPaket')) gid('btnTukarPaket').classList.remove('hidden');
    if (gid('panelCurrentPkgEdit')) gid('panelCurrentPkgEdit').classList.remove('hidden');
    if (gid('keepSubVal')) gid('keepSubVal').value = '1';

    if (gid('modalEdit')) gid('modalEdit').classList.remove('hidden');
}

function closeEditModal()
{
    if (gid('modalEdit')) gid('modalEdit').classList.add('hidden');
}

function toggleTukarPaket()
{
    if (gid('keepSubVal')) gid('keepSubVal').value = '0';
    if (gid('panelGantiPaket')) gid('panelGantiPaket').classList.remove('hidden');
    if (gid('btnTukarPaket')) gid('btnTukarPaket').classList.add('hidden');
    if (gid('panelCurrentPkgEdit')) gid('panelCurrentPkgEdit').classList.add('hidden');
    if (gid('edSubMainTime')) gid('edSubMainTime').checked = true;
    toggleSubMain('time', 'Edit');
}

function openAddQuota(id, name, total, used)
{
    if (gid('quotaUserId')) gid('quotaUserId').value = id;
    if (gid('quotaInfoText')) gid('quotaInfoText').textContent = name + ' — terpakai: ' + used + ' / ' + total + ' NIK';
    if (gid('modalQuota')) gid('modalQuota').classList.remove('hidden');
}

function loadTableData()
{
    const mAdd = gid('modalAdd');
    const mEdit = gid('modalEdit');
    const mQuota = gid('modalQuota');
    if (mAdd && !mAdd.classList.contains('hidden')) return;
    if (mEdit && !mEdit.classList.contains('hidden')) return;
    if (mQuota && !mQuota.classList.contains('hidden')) return;

    fetch('user/table_data.php')
        .then(res => res.text())
        .then(html =>
        {
            let tbody = gid('operatorTableBody');
            if (tbody) tbody.innerHTML = html;
        })
        .catch(err => console.error('Error fetching table data:', err));
}

setInterval(loadTableData, 3000);
toggle_access_type('dashboard', 'Add');
attach_operator_auto_fill();
attach_edit_wali_name_auto_upper();
