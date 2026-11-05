
"use strict";

document.addEventListener("DOMContentLoaded", () => {
    const section = document.getElementById("passwordResetSection");
    const staffIdInput = document.getElementById("resetStaffId");
    const staffName = document.getElementById("resetStaffName");
    const resetForm = document.getElementById("adminResetForm");
    const passwordInput = document.getElementById("new_password");
    const cancelButton = document.getElementById("cancelPasswordReset");

    if (
        !section ||
        !staffIdInput ||
        !staffName ||
        !resetForm ||
        !cancelButton
    ) {
        return;
    }

    document.querySelectorAll("[data-reset-staff-id]").forEach(button => {
        button.addEventListener("click", () => {
            resetForm.reset();

            staffIdInput.value = button.dataset.resetStaffId;
            staffName.textContent = button.dataset.resetStaffName;

            section.hidden = false;
            section.scrollIntoView({
                behavior: "smooth",
                block: "start"
            });

            passwordInput?.focus({
                preventScroll: true
            });
        });
    });

    cancelButton.addEventListener("click", () => {
        resetForm.reset();
        staffIdInput.value = "";
        staffName.textContent = "";
        section.hidden = true;
    });
});
