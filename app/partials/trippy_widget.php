<?php

$trippyAutoOpen = !empty($_SESSION['trippy_auto_open']);
unset($_SESSION['trippy_auto_open']);

$trippyUserName = $user['full_name'] ?? 'Staff';
?>

<div
    id="trippyOverlay"
    class="trippy-overlay"
    data-auto-open="<?= $trippyAutoOpen ? '1' : '0' ?>"
    data-csrf="<?= e(csrfToken()) ?>"
    hidden
>
    <div class="trippy-backdrop" data-trippy-close></div>

    <section
        class="trippy-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="trippyTitle"
        aria-describedby="trippySubtitle"
    >
        <button
            type="button"
            class="trippy-close"
            id="trippyClose"
            aria-label="Close Trippy Assistant"
        >
            ×
        </button>

        <div class="trippy-header">
            <div class="trippy-hero">
                <img
                    src="assets/trippy.png"
                    alt="Trippy AI Assistant"
                    class="trippy-avatar-image"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                >

                <div class="trippy-avatar-fallback" style="display:none;">
                    T
                </div>
            </div>

            <div class="trippy-header-text">
                <p class="trippy-badge">TripLedger AI Assistant</p>
                <h2 id="trippyTitle">Hi, I’m Trippy!</h2>
                <p id="trippySubtitle">
                    Welcome back, <?= e($trippyUserName) ?>.
                    I can help you with trip ticket counts,
                    outstanding records, and reconciliation.
                </p>
            </div>
        </div>

        <div class="trippy-welcome-actions">
            <button
                type="button"
                class="trippy-chip"
                data-trippy-question="Show ticket statistics"
            >
                Show ticket statistics
            </button>

            <button
                type="button"
                class="trippy-chip"
                data-trippy-question="How many outstanding tickets?"
            >
                Outstanding tickets
            </button>

            <button
                type="button"
                class="trippy-chip"
                data-trippy-question="What is ticket reconciliation?"
            >
                Ticket reconciliation
            </button>

            <a
                href="ai_assistant.php"
                class="trippy-chip trippy-chip-link"
            >
                Open full AI Assistant
            </a>
        </div>

        <div
            class="trippy-chat-log"
            id="trippyMessages"
            role="log"
            aria-live="polite"
            aria-relevant="additions"
        >
            <div class="trippy-message trippy-assistant">
                <div class="trippy-bubble">
                    <strong>Trippy</strong>
                    <p>
                        Hello! I’m your TripLedger assistant.
                        Ask me about issued, returned, and outstanding
                        trip tickets.
                    </p>
                </div>
            </div>
        </div>

        <div
            id="trippyStatus"
            class="trippy-status"
            role="status"
            aria-live="polite"
        ></div>

        <form id="trippyForm" class="trippy-form">
            <label for="trippyInput" class="trippy-label">
                Ask Trippy
            </label>

            <div class="trippy-compose">
                <textarea
                    id="trippyInput"
                    name="message"
                    rows="2"
                    maxlength="500"
                    placeholder="Type your question here..."
                    required
                ></textarea>

                <button type="submit" id="trippySend">
                    Send
                </button>
            </div>

            <p class="trippy-note">
                For prototype use only. Do not enter passwords
                or confidential personal information.
            </p>
        </form>
    </section>
</div>

<button
    type="button"
    id="trippyLauncher"
    class="trippy-launcher"
    aria-label="Open Trippy AI Assistant"
>
    <span class="trippy-launcher-avatar">T</span>
    <span class="trippy-launcher-text">
        <strong>Trippy</strong>
        <small>AI Assistant</small>
    </span>
</button>