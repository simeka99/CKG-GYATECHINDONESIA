(async function ()
{
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    function is_ckg_pelayanan_page()
    {
        return String(window.location.href || "").includes("sehatindonesiaku.kemkes.go.id/ckg-pelayanan");
    }

    function sendAuth(action, payload = {})
    {
        return chrome.runtime.sendMessage({
            type: "rmik_auth",
            action,
            ...payload,
        });
    }

    async function ensureAccess()
    {
        try
        {
            const result = await sendAuth("revalidate");
            if (!result?.ok)
            {
                console.info("[RMIK EXT] akses tidak valid:", result?.error || "unknown");
                return null;
            }
            return result.data || null;
        } catch (error)
        {
            console.warn("[RMIK EXT] gagal validasi lisensi:", error?.message || error);
            return null;
        }
    }

    const session = await ensureAccess();
    if (!session?.license_key)
    {
        return;
    }

    console.info("[RMIK EXT] akses aktif:", session.username || session.full_name || session.pc_label || "licensed");

    const fillHeightWeight = async () =>
    {
        const heightInput = document.getElementById("tinggiBadan_txt");
        const weightInput = document.getElementById("beratBadan_txt");
        if (!heightInput || !weightInput) return;

        const height = Math.floor(Math.random() * 26) + 155;
        const bmi = Math.random() * (24.9 - 18.5) + 18.5;
        const weight = Math.round(bmi * Math.pow(height / 100, 2));

        heightInput.value = height;
        weightInput.value = weight;

        ["input", "change"].forEach((eventType) =>
        {
            heightInput.dispatchEvent(new InputEvent(eventType, { bubbles: true }));
            weightInput.dispatchEvent(new InputEvent(eventType, { bubbles: true }));
        });
    };

    const fillAllNo = async () =>
    {
        const radioButtons = document.querySelectorAll('input[type="radio"][value="B"]');
        radioButtons.forEach((radio) =>
        {
            radio.checked = true;
            ["click", "change"].forEach((eventType) =>
                radio.dispatchEvent(new Event(eventType, { bubbles: true }))
            );
        });
    };

    const waitForQuestions = async () =>
    {
        for (let i = 0; i < 30; i++)
        {
            if (document.querySelector("ul.answers-list")) return true;
            await sleep(300);
        }
        return false;
    };

    function find_same_location_checkbox()
    {
        return document.querySelector('input#sameLocation[name="sameLocation"][type="checkbox"]');
    }

    function find_simpan_button()
    {
        const button_list = Array.from(document.querySelectorAll('button[type="submit"]'));
        return button_list.find((button) => /^\s*simpan\s*$/i.test(String(button.textContent || ""))) || null;
    }

    function set_same_location_active(checkbox)
    {
        if (!checkbox) return false;

        if (!checkbox.checked)
            checkbox.click();

        checkbox.checked = true;
        checkbox.value = "true";
        checkbox.setAttribute("value", "true");
        ["input", "change", "click"].forEach((event_type) =>
            checkbox.dispatchEvent(new Event(event_type, { bubbles: true }))
        );
        return checkbox.checked;
    }

    async function submit_ckg_pelayanan_umum()
    {
        for (let index = 0; index < 25; index += 1)
        {
            const checkbox = find_same_location_checkbox();
            const simpan_button = find_simpan_button();
            if (!checkbox || !simpan_button)
            {
                await sleep(350);
                continue;
            }

            if (!set_same_location_active(checkbox))
            {
                await sleep(250);
                continue;
            }

            await sleep(150);
            simpan_button.click();
            return true;
        }
        return false;
    }

    async function main()
    {
        if (is_ckg_pelayanan_page())
        {
            await submit_ckg_pelayanan_umum();
            return;
        }

        await sleep(1200);
        if (document.getElementById("tinggiBadan_txt"))
        {
            await fillHeightWeight();
        }

        const nextButton = document.getElementById("nextGenBtn");
        if (nextButton)
        {
            nextButton.addEventListener("click", async () =>
            {
                await waitForQuestions();
                await fillAllNo();
            });
        }
    }

    window.addEventListener("load", () => setTimeout(main, 1500));
})();
