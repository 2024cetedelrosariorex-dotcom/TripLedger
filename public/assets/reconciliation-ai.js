
"use strict";

document.addEventListener("DOMContentLoaded", () => {
    const button = document.getElementById("generateAiAnalysis");
    const panel = document.getElementById("aiAnalysisPanel");
    const results = document.getElementById("aiAnalysisResults");
    const status = document.getElementById("aiAnalysisStatus");
    const count = document.getElementById("aiOutstandingCount");
    const csrf = document.querySelector(
        'meta[name="csrf-token"]'
    )?.content;

    if (!button || !panel || !results || !status || !count || !csrf) {
        return;
    }

    let busy = false;

    button.addEventListener("click", async () => {
        if (busy) return;

        busy = true;
        button.disabled = true;
        button.textContent = "Generating Analysis...";

        panel.hidden = false;
        status.textContent = "Analyzing the selected report...";
        results.textContent = "";
        count.textContent = "—";

        const params = new URLSearchParams(window.location.search);

        const filters = {
            plate: params.get("plate") || "",
            date_from: params.get("date_from") || "",
            date_to: params.get("date_to") || ""
        };

        try {
            const response = await fetch("api_reconciliation_ai.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                },
                body: JSON.stringify({
                    csrf_token: csrf,
                    ...filters
                })
            });

            let data;

            try {
                data = await response.json();
            } catch {
                throw new Error(
                    "Unable to read the server response."
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message || "AI analysis request failed."
                );
            }

            // Verified summary, calculated by PHP/MySQL.
            count.textContent = String(
                data.summary.total_outstanding
            );

            // textContent prevents AI output from
            // being interpreted as executable HTML.
            results.textContent = String(data.analysis || "");

           status.textContent =
    data.source === "gemini"
        ? "AI-assisted analysis generated."
        : data.source === "database_fallback"
            ? "Local database summary displayed. Gemini is unavailable or disabled."
            : "Database summary generated.";

            panel.scrollIntoView({
                behavior: "smooth",
                block: "nearest"
            });

        } catch (error) {
            status.textContent = error instanceof Error
                ? error.message
                : "An unexpected error occurred.";
        } finally {
            busy = false;
            button.disabled = false;
            button.textContent = "Generate AI Analysis";
        }
    });
});
