import { safe_wait } from "./helper.js";
import { sel } from "./selector_config.js";

export async function collect_pelayanan_nakes(page, options = {})
{
    const should_prepare = options?.prepare !== false;

    if (should_prepare)
    {
        await page.evaluate(() =>
        {
            window.scrollTo(0, Math.max(0, Math.floor(document.body.scrollHeight * 0.55)));
        }).catch(() => { });
        await safe_wait(page, sel.wait.short_delay_ms);

        await page.evaluate(() =>
        {
            const get_icon_class = (button_node) =>
            {
                const icon_node = button_node?.querySelector("svg");
                if (!icon_node)
                    return "";
                const class_name = icon_node.getAttribute("class");
                return String(class_name || "").toLowerCase();
            };

            const accordion_button_nodes = Array.from(document.querySelectorAll("#tableLayanan > div > button, #tableLayanan button[aria-controls='dropdown-content']"));
            for (const button_node of accordion_button_nodes)
            {
                const icon_class = get_icon_class(button_node);
                const is_opened = icon_class.includes("rotate-180");
                if (is_opened)
                    continue;
                try { button_node.click(); } catch { }
            }
        }).catch(() => { });
        await safe_wait(page, Math.max(140, Math.min(sel.wait.after_open_card_ms, 300)));
    }

    return await page.evaluate(() =>
    {
        const clean_text = (value) => String(value || "").replace(/\s+/g, " ").trim();
        const normalize_text = (value) => clean_text(value).toLowerCase();
        const get_checkbox_state = (row_node) =>
        {
            const checkbox_node = row_node.querySelector("input[type='checkbox']");
            if (!checkbox_node) return "";
            return checkbox_node.checked ? "Ya" : "Tidak";
        };
        const get_status_text = (row_node) =>
        {
            const status_cell = row_node.querySelector("div.text-center.flex.justify-center.items-center");
            const status_node = status_cell?.querySelector("div.text-\\[14px\\]") || status_cell;
            return clean_text(status_node?.textContent || "");
        };
        const get_action_text = (row_node) =>
        {
            const action_node =
                row_node.querySelector("div[id^='rowfrm'] button") ||
                row_node.querySelector("div[id^='rowfrm'] div");
            return clean_text(action_node?.textContent || "");
        };
        const get_action_clickable = (row_node) =>
        {
            const action_button_node = row_node.querySelector("div[id^='rowfrm'] button");
            if (!action_button_node) return false;
            const class_name = String(action_button_node.className || "");
            return !action_button_node.disabled && !class_name.includes("cursor-not-allowed");
        };

        const section_nodes = Array.from(document.querySelectorAll("#tableLayanan"));
        const data = [];
        const items = [];
        let total_pertanyaan = 0;

        for (const section_node of section_nodes)
        {
            const title_node = section_node.querySelector("button span div, button span");
            const kategori = clean_text(title_node?.textContent || "");
            const row_nodes = Array.from(section_node.querySelectorAll("div.w-full.grid.grid-cols-5.gap-2"))
                .filter((row_node) => row_node.querySelector("div[id^='rowfrm']"));
            const pertanyaan = [];

            for (const row_node of row_nodes)
            {
                const layanan_node = row_node.querySelector("div.col-span-2");
                const layanan = clean_text(layanan_node?.textContent || "");
                if (!layanan) continue;

                const row_container = row_node.querySelector("div[id^='rowfrm']");
                const row_id = clean_text(row_container?.id || "");
                const aksi = get_action_text(row_node);
                const status = get_status_text(row_node);
                const status_key = normalize_text(status);
                const item = {
                    kategori,
                    layanan,
                    diperiksa: get_checkbox_state(row_node),
                    status,
                    status_key,
                    aksi,
                    aksi_bisa_klik: get_action_clickable(row_node) ? "ya" : "tidak",
                    row_id,
                    row_index: items.length
                };

                pertanyaan.push(item);
                items.push(item);
            }

            if (!kategori && pertanyaan.length === 0)
                continue;

            total_pertanyaan += pertanyaan.length;
            data.push({
                kategori,
                total_layanan: pertanyaan.length,
                pertanyaan
            });
        }

        return {
            total_kategori: data.length,
            total_pertanyaan,
            data,
            items
        };
    }).catch(() => ({
        total_kategori: 0,
        total_pertanyaan: 0,
        data: [],
        items: []
    }));
}
