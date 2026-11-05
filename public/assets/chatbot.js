
"use strict";

document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("chatbotForm");
    const input = document.getElementById("chatbotInput");
    const sendButton = document.getElementById("chatbotSend");
    const messages = document.getElementById("chatbotMessages");
    const status = document.getElementById("chatbotStatus");

    const csrf = document.querySelector(
        'meta[name="csrf-token"]'
    )?.content;

    if (
        !form ||
        !input ||
        !sendButton ||
        !messages ||
        !status ||
        !csrf
    ) {
        return;
    }

    let sending = false;

    function addMessage(text, type, source = "") {
        const wrapper = document.createElement("div");
        wrapper.className = "chatbot-message " + type;

        const bubble = document.createElement("div");
        bubble.className = "chatbot-bubble";

        if (type === "assistant") {
            const title = document.createElement("strong");
            title.textContent = "Trippy";
            bubble.appendChild(title);
        }

        const paragraph = document.createElement("p");
        paragraph.textContent = text;
        bubble.appendChild(paragraph);

        if (source && type === "assistant") {
            const sourceLabel = document.createElement("small");
            sourceLabel.className = "chatbot-source";

            const labels = {
                database: "Source: TripLedger database",
                gemini: "Source: Gemini AI explanation",
                system: "Source: TripLedger Assistant"
            };

            sourceLabel.textContent =
                labels[source] || "Source: Assistant";

            bubble.appendChild(sourceLabel);
        }

        wrapper.appendChild(bubble);
        messages.appendChild(wrapper);

        messages.scrollTop = messages.scrollHeight;
    }

    function setSending(value) {
        sending = value;
        sendButton.disabled = value;
        input.disabled = value;
        sendButton.textContent = value ? "Sending..." : "Send";
    }

    async function sendMessage(question) {
        if (sending) {
            return;
        }

        const text = question.trim();

        if (!text || text.length > 500) {
            status.textContent =
                "Enter a question of up to 500 characters.";
            return;
        }

        addMessage(text, "user");

        input.value = "";
        setSending(true);
        status.textContent = "Assistant is responding...";

        try {
            const response = await fetch("api_chatbot.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                },
                body: JSON.stringify({
                    csrf_token: csrf,
                    message: text
                })
            });

            let data;

            try {
                data = await response.json();
            } catch {
                throw new Error(
                    "Unable to read the server response. " +
                    "Please refresh the page."
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    typeof data.message === "string"
                        ? data.message
                        : "Request failed."
                );
            }

            addMessage(
                String(data.reply || "No response returned."),
                "assistant",
                data.source || ""
            );

            status.textContent = "";

        } catch (error) {
            const message = error instanceof Error
                ? error.message
                : "Something went wrong.";

            addMessage(
                message,
                "assistant",
                "system"
            );

            status.textContent = "Request could not be completed.";

        } finally {
            setSending(false);
            input.focus();
        }
    }

    form.addEventListener("submit", (event) => {
        event.preventDefault();
        sendMessage(input.value);
    });

    input.addEventListener("keydown", (event) => {
        if (
            event.key === "Enter" &&
            !event.shiftKey &&
            !event.isComposing
        ) {
            event.preventDefault();

            if (!sending) {
                form.requestSubmit();
            }
        }
    });

    document.querySelectorAll("[data-question]").forEach(
        (button) => {
            button.addEventListener("click", () => {
                if (sending) {
                    return;
                }

                input.value = button.dataset.question || "";
                input.focus();
            });
        }
    );
});
