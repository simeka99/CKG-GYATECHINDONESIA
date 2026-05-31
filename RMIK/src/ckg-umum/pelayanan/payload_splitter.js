function normalize_text_key(value)
{
    return String(value || "")
        .replace(/^\s*\d+\s*[\.\)\-:]\s*/g, "")
        .replace(/\*/g, "")
        .toLowerCase()
        .normalize("NFKD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9]+/g, " ")
        .trim();
}

function pick_payload_object(...candidates)
{
    for (const item of candidates)
    {
        if (!item || typeof item !== "object")
            continue;
        if (Array.isArray(item))
            continue;
        return item;
    }
    return {};
}

function get_items(payload)
{
    return Array.isArray(payload?.items) ? payload.items : [];
}

function get_total_pertanyaan(items)
{
    return items.reduce((sum, item) =>
    {
        const pertanyaan = Array.isArray(item?.pertanyaan) ? item.pertanyaan : [];
        return sum + pertanyaan.length;
    }, 0);
}

function rebuild_payload(base_payload, items)
{
    const safe_items = Array.isArray(items) ? items : [];
    return {
        ...(base_payload && typeof base_payload === "object" ? base_payload : {}),
        total_jenis_pemeriksaan: safe_items.length,
        total_pertanyaan: get_total_pertanyaan(safe_items),
        items: safe_items
    };
}

function is_mandiri_service_name(service_name)
{
    const service_key = normalize_text_key(service_name);
    if (service_key === "")
        return false;

    const mandiri_patterns = [
        /demografi\b/i,
        /^faktor risiko kanker usus\b/,
        /^faktor risiko tb\b/,
        /^hati$/,
        /^kesehatan jiwa$/,
        /^penapisan risiko kanker paru$/,
        /^perilaku merokok$/,
        /^tingkat aktivitas fisik\b/
    ];

    return mandiri_patterns.some((pattern) => pattern.test(service_key));
}

function split_mixed_items(items)
{
    const mandiri_items = [];
    const nakes_items = [];

    for (const item of items)
    {
        const service_name = String(item?.nama || "").trim();
        if (is_mandiri_service_name(service_name))
            mandiri_items.push(item);
        else
            nakes_items.push(item);
    }

    return { mandiri_items, nakes_items };
}

export function resolve_pemeriksaan_payload_pair(source)
{
    const source_object = source && typeof source === "object" ? source : {};
    const raw_pemeriksaan_mandiri = pick_payload_object(
        source_object?.pemeriksaan_mandiri,
        source_object?.skrining_mandiri
    );
    const raw_pemeriksaan_nakes = pick_payload_object(
        source_object?.pemeriksaan_nakes,
        source_object?.pelayanan_nakes,
        source_object?.skrining_nakes
    );

    const mandiri_items = get_items(raw_pemeriksaan_mandiri);
    const nakes_items = get_items(raw_pemeriksaan_nakes);

    if (nakes_items.length > 0 || mandiri_items.length === 0)
    {
        return {
            pemeriksaan_mandiri_payload: rebuild_payload(raw_pemeriksaan_mandiri, mandiri_items),
            pemeriksaan_nakes_payload: rebuild_payload(raw_pemeriksaan_nakes, nakes_items)
        };
    }

    const splitted = split_mixed_items(mandiri_items);
    const merged_meta = {
        package_key: String(raw_pemeriksaan_mandiri?.package_key || raw_pemeriksaan_nakes?.package_key || ""),
        batch_key: String(raw_pemeriksaan_mandiri?.batch_key || raw_pemeriksaan_nakes?.batch_key || "")
    };

    return {
        pemeriksaan_mandiri_payload: rebuild_payload(
            { ...raw_pemeriksaan_mandiri, ...merged_meta },
            splitted.mandiri_items
        ),
        pemeriksaan_nakes_payload: rebuild_payload(
            { ...raw_pemeriksaan_nakes, ...merged_meta },
            splitted.nakes_items
        )
    };
}
