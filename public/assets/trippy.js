"use strict";

document.addEventListener("DOMContentLoaded", () => {
    const overlay = document.getElementById("trippyOverlay");
    const launcher = document.getElementById("trippyLauncher");
    const closeButton = document.getElementById("trippyClose");
    const form = document.getElementById("trippyForm");
    const input = document.getElementById("trippyInput");
    const sendButton = document.getElementById("trippySend");
    const messages = document.getElementById("trippyMessages");
    const status = document.getElementById("trippyStatus");

    if (
        !overlay ||
        !launcher ||
        !closeButton ||
        !form ||
        !input ||
        !sendButton ||
        !messages ||
        !status
    ) {
        return;
    }

    const csrf = overlay.dataset.csrf || "";
    const autoOpen = overlay.dataset.autoOpen === "1";

    let sending = false;
    let previousFocus = null;

    function openTrippy() {
        previousFocus = document.activeElement;
        overlay.hidden = false;
        document.body.style.overflow = "hidden";
        setTimeout(() => {
            input.focus();
        }, 120);
    }

    function closeTrippy() {
        overlay.hidden = true;
        document.body.style.overflow = "";
        if (previousFocus instanceof HTMLElement) previousFocus.focus();
    }

    function appendMessage(text, type, source = "") {
        const wrapper = document.createElement("div");
        wrapper.className = "trippy-message " + type;

        const bubble = document.createElement("div");
        bubble.className = "trippy-bubble";

        const title = document.createElement("strong");
        title.textContent = type === "user" ? "You" : "Trippy";
        bubble.appendChild(title);

        const paragraph = document.createElement("p");
        paragraph.textContent = text;
        bubble.appendChild(paragraph);

        if (type === "assistant" && source) {
            const meta = document.createElement("small");
            meta.className = "trippy-source";

            const sourceLabels = {
                database: "Source: TripLedger database",
                gemini: "Source: Gemini AI explanation",
                system: "Source: TripLedger Assistant"
            };

            meta.textContent =
                sourceLabels[source] || "Source: Assistant";

            bubble.appendChild(meta);
        }

        wrapper.appendChild(bubble);
        messages.appendChild(wrapper);
        messages.scrollTop = messages.scrollHeight;
    }

    function setSending(value) {
        sending = value;
        input.disabled = value;
        sendButton.disabled = value;
        sendButton.textContent = value ? "Sending..." : "Send";
    }

    async function sendTrippyMessage(question) {
        const text = String(question || "").trim();

        if (!text) {
            status.textContent = "Please enter a question.";
            return;
        }

        if (text.length > 500) {
            status.textContent = "Your question is too long.";
            return;
        }

        appendMessage(text, "user");
        input.value = "";
        setSending(true);
        status.textContent = "Trippy is thinking...";

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

            let data = null;

            try {
                data = await response.json();
            } catch (error) {
                throw new Error(
                    "Could not read the server response."
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    typeof data.message === "string"
                        ? data.message
                        : "Request failed."
                );
            }

            appendMessage(
                String(data.reply || "No response returned."),
                "assistant",
                String(data.source || "system")
            );

            status.textContent = "";
        } catch (error) {
            const message = error instanceof Error
                ? error.message
                : "Something went wrong.";

            appendMessage(message, "assistant", "system");
            status.textContent = "Request could not be completed.";
        } finally {
            setSending(false);
            input.focus();
        }
    }

    launcher.addEventListener("click", () => {
        openTrippy();
    });

    closeButton.addEventListener("click", () => {
        closeTrippy();
    });

    overlay.querySelectorAll("[data-trippy-close]").forEach((element) => {
        element.addEventListener("click", () => {
            closeTrippy();
        });
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Tab" && !overlay.hidden) {
            const items = [...overlay.querySelectorAll('button:not(:disabled), input:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]')]
                .filter(element => element.getClientRects().length);
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault(); last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault(); first?.focus();
            }
        }
        if (event.key === "Escape" && !overlay.hidden) {
            closeTrippy();
        }
    });

    form.addEventListener("submit", (event) => {
        event.preventDefault();

        if (sending) {
            return;
        }

        sendTrippyMessage(input.value);
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

    document.querySelectorAll("[data-trippy-question]").forEach((button) => {
        button.addEventListener("click", () => {
            const question = button.dataset.trippyQuestion || "";
            openTrippy();
            input.value = question;
            input.focus();
        });
    });

    if (autoOpen) {
        setTimeout(() => {
            openTrippy();
        }, 500);
    }
});
