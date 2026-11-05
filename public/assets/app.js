
"use strict";

document.addEventListener("DOMContentLoaded", () => {

    // ==========================================
    // 1. TOAST NOTIFICATIONS
    // ==========================================

    window.tripLedgerToast = function (
        message,
        type = "success"
    ) {
        document.querySelectorAll(".app-toast").forEach(toast => {
            toast.remove();
        });

        const toast = document.createElement("div");

        toast.className = "app-toast";

        if (type === "error") {
            toast.classList.add("app-toast-error");
        }

        toast.textContent = String(message);

        toast.setAttribute(
            "role",
            type === "error" ? "alert" : "status"
        );

        document.body.appendChild(toast);

        window.setTimeout(() => {
            if (toast.isConnected) {
                toast.remove();
            }
        }, 4000);
    };


    // ==========================================
    // 2. CUSTOM CONFIRMATION MODAL
    // ==========================================

    const modal = document.createElement("div");

    modal.className = "tl-confirm-overlay";
    modal.hidden = true;

    modal.innerHTML = `
        <div
            class="tl-confirm-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="tlConfirmTitle"
            aria-describedby="tlConfirmMessage"
            tabindex="-1"
        >
            <div
                class="tl-confirm-icon"
                aria-hidden="true"
            >
                ?
            </div>

            <h2 id="tlConfirmTitle">
                Confirm Action
            </h2>

            <p id="tlConfirmMessage"></p>

            <div class="tl-confirm-actions">

                <button
                    type="button"
                    class="app-button app-button-secondary"
                    data-modal-cancel
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="app-button app-button-primary"
                    data-modal-confirm
                >
                    Confirm
                </button>

            </div>
        </div>
    `;

    document.body.appendChild(modal);

    const dialog = modal.querySelector(
        ".tl-confirm-dialog"
    );

    const title = modal.querySelector(
        "#tlConfirmTitle"
    );

    const message = modal.querySelector(
        "#tlConfirmMessage"
    );

    const cancelButton = modal.querySelector(
        "[data-modal-cancel]"
    );

    const confirmButton = modal.querySelector(
        "[data-modal-confirm]"
    );

    let pendingForm = null;
    let pendingSubmitter = null;
    let previousFocus = null;
    let isSubmitting = false;


    // ==========================================
    // 3. OPEN CONFIRMATION MODAL
    // ==========================================

    function openModal(form, submitter = null) {

        if (isSubmitting) {
            return;
        }

        pendingForm = form;
        pendingSubmitter = submitter;
        previousFocus = document.activeElement;

        const action = form.querySelector(
            "[name='action']"
        )?.value || "";

        const titles = {
            record: "Confirm Physical Return",
            correct: "Confirm Correction",
            reverse: "Confirm Return Reversal"
        };

        const buttonLabels = {
            record: "Confirm Return",
            correct: "Save Correction",
            reverse: "Reverse Return"
        };

        title.textContent =
            form.dataset.confirmTitle ||
            titles[action] ||
            "Confirm Action";

        message.textContent =
            form.dataset.confirm ||
            "Are you sure you want to continue?";

        confirmButton.textContent =
            form.dataset.confirmButton ||
            buttonLabels[action] ||
            "Confirm";

        const isDanger =
            action === "reverse" ||
            form.dataset.confirmDanger === "true";

        confirmButton.className = isDanger
            ? "app-button app-button-danger"
            : "app-button app-button-primary";

        confirmButton.disabled = false;
        cancelButton.disabled = false;

        modal.hidden = false;

        document.body.classList.add(
            "tl-modal-open"
        );

        cancelButton.focus();
    }


    // ==========================================
    // 4. CLOSE CONFIRMATION MODAL
    // ==========================================

    function closeModal() {

        if (isSubmitting) {
            return;
        }

        modal.hidden = true;

        document.body.classList.remove(
            "tl-modal-open"
        );

        confirmButton.disabled = false;
        cancelButton.disabled = false;

        pendingForm = null;
        pendingSubmitter = null;

        if (
            previousFocus &&
            previousFocus.isConnected &&
            typeof previousFocus.focus === "function"
        ) {
            previousFocus.focus();
        }

        previousFocus = null;
    }


    // ==========================================
    // 5. INTERCEPT CONFIRMATION FORMS
    // ==========================================

    document.querySelectorAll(
        "form[data-confirm]"
    ).forEach(form => {

        form.addEventListener("submit", event => {

            // Allow approved submission through once.
            if (
                form.dataset.confirmApproved === "true"
            ) {
                delete form.dataset.confirmApproved;
                return;
            }

            event.preventDefault();

            if (isSubmitting) {
                return;
            }

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            // Avoid reopening an existing dialog.
            if (!modal.hidden) {
                return;
            }

            openModal(
                form,
                event.submitter || null
            );

        });

    });


    // ==========================================
    // 6. CANCEL BUTTON
    // ==========================================

    cancelButton.addEventListener("click", () => {
        closeModal();
    });


    // ==========================================
    // 7. CONFIRM BUTTON
    // ==========================================

    confirmButton.addEventListener("click", () => {

        if (!pendingForm || isSubmitting) {
            return;
        }

        const form = pendingForm;
        const submitter = pendingSubmitter;

        if (!form.checkValidity()) {
            closeModal();
            form.reportValidity();
            return;
        }

        isSubmitting = true;

        confirmButton.disabled = true;
        cancelButton.disabled = true;

        confirmButton.textContent = "Processing...";

        // Hide dialog once staff has confirmed.
        modal.hidden = true;

        document.body.classList.remove(
            "tl-modal-open"
        );

        // Mark the form as approved so requestSubmit()
        // will not trigger another confirmation modal.
        form.dataset.confirmApproved = "true";

        // Request normal form submission so the existing
        // PHP backend receives all form fields and CSRF token.
        try {

            if (
                submitter &&
                submitter.isConnected &&
                (
                    submitter.type === "submit" ||
                    submitter.type === "image"
                )
            ) {
                form.requestSubmit(submitter);
            } else {
                form.requestSubmit();
            }

        } catch (error) {

            console.error(
                "TripLedger submission error:",
                error
            );

            isSubmitting = false;

            delete form.dataset.confirmApproved;

            confirmButton.disabled = false;
            cancelButton.disabled = false;

            modal.hidden = false;

            document.body.classList.add(
                "tl-modal-open"
            );

            window.tripLedgerToast(
                "Unable to submit the form. Please try again.",
                "error"
            );

            cancelButton.focus();
        }

    });


    // ==========================================
    // 8. CLICK OUTSIDE THE MODAL
    // ==========================================

    modal.addEventListener("click", event => {

        if (
            event.target === modal &&
            !isSubmitting
        ) {
            closeModal();
        }

    });


    // ==========================================
    // 9. KEYBOARD SUPPORT
    // ==========================================

    document.addEventListener("keydown", event => {

        if (modal.hidden) {
            return;
        }

        if (
            event.key === "Escape" &&
            !isSubmitting
        ) {
            event.preventDefault();
            closeModal();
            return;
        }

        // Keep keyboard focus inside the dialog.
        if (event.key === "Tab") {

            const buttons = [
                cancelButton,
                confirmButton
            ];

            const currentIndex = buttons.indexOf(
                document.activeElement
            );

            if (
                event.shiftKey &&
                currentIndex === 0
            ) {
                event.preventDefault();
                confirmButton.focus();

            } else if (
                !event.shiftKey &&
                currentIndex === 1
            ) {
                event.preventDefault();
                cancelButton.focus();

            } else if (currentIndex === -1) {
                event.preventDefault();
                cancelButton.focus();
            }
        }

    });


    // ==========================================
    // 10. RESPONSIVE SIDEBAR
    // ==========================================

    const sidebar = document.querySelector(
        "[data-sidebar]"
    );

    const toggle = document.querySelector(
        "[data-sidebar-toggle]"
    );

    if (sidebar && toggle) {

        const overlay = document.createElement("div");

        overlay.className = "tl-sidebar-overlay";

        overlay.setAttribute(
            "aria-hidden",
            "true"
        );

        document.body.appendChild(overlay);

        function closeSidebar() {

            sidebar.classList.remove(
                "is-open"
            );

            overlay.classList.remove(
                "is-visible"
            );

            toggle.setAttribute(
                "aria-expanded",
                "false"
            );
        }

        function openSidebar() {

            sidebar.classList.add(
                "is-open"
            );

            overlay.classList.add(
                "is-visible"
            );

            toggle.setAttribute(
                "aria-expanded",
                "true"
            );
        }

        toggle.addEventListener("click", () => {

            if (
                sidebar.classList.contains(
                    "is-open"
                )
            ) {
                closeSidebar();
            } else {
                openSidebar();
            }

        });

        overlay.addEventListener(
            "click",
            closeSidebar
        );

        document.addEventListener("keydown", event => {

            if (
                event.key === "Escape" &&
                modal.hidden
            ) {
                closeSidebar();
            }

        });

        window.addEventListener("resize", () => {

            if (window.innerWidth > 900) {
                closeSidebar();
            }

        });

        sidebar.querySelectorAll("a").forEach(link => {

            link.addEventListener(
                "click",
                closeSidebar
            );

        });

    }


    // ==========================================
    // 11. RESTORE STATE WHEN PAGE IS REVISITED
    // ==========================================

    window.addEventListener("pageshow", () => {

        isSubmitting = false;

        document.querySelectorAll(
            "form[data-confirm]"
        ).forEach(form => {
            delete form.dataset.confirmApproved;
        });

        confirmButton.disabled = false;
        cancelButton.disabled = false;

        modal.hidden = true;

        document.body.classList.remove(
            "tl-modal-open"
        );

        pendingForm = null;
        pendingSubmitter = null;
        previousFocus = null;

    });

});
