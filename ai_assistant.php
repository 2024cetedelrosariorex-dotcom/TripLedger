<?php

require_once __DIR__ . '/../app/auth.php';

// Authorized Staff only.
$user = requireStaff();

$pageTitle = 'AI Assistant';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="assets/dar-logo.png.webp">
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="csrf-token"
        content="<?= e(csrfToken()) ?>"
    >

    <title>AI Assistant | TripLedger</title>

    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/components.css">
    <link rel="stylesheet" href="assets/chatbot.css">

    <script src="assets/app.js" defer></script>
    <script src="assets/chatbot.js" defer></script>
</head>

<body>
<div class="app-layout">

    <?php
    require __DIR__ . '/../app/partials/sidebar.php';
    ?>

    <div class="tl-content">

        <?php
        require __DIR__ . '/../app/partials/header.php';
        ?>

        <main class="tl-dashboard-body">

            <section class="tl-welcome">
                <h2>Trippy Assistant</h2>

                <p>
                    Ask about trip ticket statistics,
                    outstanding records, and reconciliation.
                    Local lookup uses supported phrases and validated filters.
                    Gemini provides optional explanations; each reply identifies its source.
                </p>
            </section>

            <div class="chatbot-layout">

                <section
                    class="chatbot-panel"
                    aria-label="AI Assistant conversation"
                >

                    <div class="chatbot-panel-header">

                        <div class="chatbot-avatar"
                             aria-hidden="true">
                            AI
                        </div>

                        <div>
                            <h3>TripLedger Assistant</h3>

                            <p>
                                Staff Support · Read-only
                            </p>
                        </div>

                        <span class="chatbot-mode">
                            Prototype
                        </span>

                    </div>

                    <div
                        class="chatbot-messages"
                        id="chatbotMessages"
                        role="log"
                        aria-label="Chat messages"
                        aria-live="polite"
                        aria-relevant="additions"
                    >

                        <div class="chatbot-message assistant">

                            <div class="chatbot-bubble">
                                <strong>
                                    TripLedger Assistant
                                </strong>

                                <p>
                                    Hello! I can help you check
                                    issued, returned, and outstanding
                                    ticket counts, or explain ticket
                                    reconciliation.
                                </p>

                                <p>
                                    What would you like to know?
                                </p>
                            </div>

                        </div>

                    </div>

                    <div
                        class="chatbot-status"
                        id="chatbotStatus"
                        role="status"
                        aria-live="polite"
                    ></div>

                    <form
                        class="chatbot-input-form"
                        id="chatbotForm"
                    >

                        <label
                            for="chatbotInput"
                            class="chatbot-input-label"
                        >
                            Your question
                        </label>

                        <div class="chatbot-compose">

                            <textarea
                                id="chatbotInput"
                                name="message"
                                rows="2"
                                maxlength="500"
                                placeholder="Ask about trip tickets..."
                                required
                            ></textarea>

                            <button
                                type="submit"
                                id="chatbotSend"
                                class="chatbot-send"
                            >
                                Send
                            </button>

                        </div>

                        <p class="chatbot-disclaimer">
                            Do not enter confidential records,
                            passwords, or personal information.
                            This assistant is an early prototype.
                        </p>

                    </form>

                </section>

                <aside class="chatbot-side">

                    <section class="app-card chatbot-help">

                        <h3>Suggested Questions</h3>

                        <p>
                            Select a question to place it
                            in the chat input.
                        </p>

                        <div class="chatbot-suggestions">

                            <button
                                type="button"
                                data-question="Show ticket statistics"
                            >
                                Show ticket statistics
                            </button>

                            <button
                                type="button"
                                data-question="How many outstanding tickets?"
                            >
                                How many outstanding tickets?
                            </button>

                            <button
                                type="button"
                                data-question="How many returned tickets?"
                            >
                                How many returned tickets?
                            </button>

                            <button
                                type="button"
                                data-question="What is ticket reconciliation?"
                            >
                                What is ticket reconciliation?
                            </button>

                            <button
                                type="button"
                                data-question="help"
                            >
                                Show available commands
                            </button>

                        </div>

                    </section>

                    <section class="app-card chatbot-help">

                        <h3>About This Assistant</h3>

                        <p>
                            Ticket statistics are retrieved
                            from the TripLedger database.
                        </p>

                        <p>
                            Gemini helps generate general
                            explanations using approved
                            fictional prompts.
                        </p>

                        <p>
                            The assistant cannot create,
                            update, or delete trip tickets.
                        </p>

                    </section>

                </aside>

            </div>

        </main>

    </div>

</div>
</body>
</html>
