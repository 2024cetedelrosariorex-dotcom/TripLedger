
"use strict";

document.addEventListener("DOMContentLoaded", function () {

    const passwordInput = document.getElementById("password");
    const toggleButton = document.getElementById("togglePassword");

    const loginForm = document.getElementById("tripLedgerLoginForm");
    const loginSubmit = document.getElementById("loginSubmit");
    const buttonText = document.getElementById("loginButtonText");

    // Show / Hide Password
    if (passwordInput && toggleButton) {
        toggleButton.addEventListener("click", function () {

            const isHidden = passwordInput.type === "password";

            passwordInput.type = isHidden ? "text" : "password";

            toggleButton.textContent = isHidden ? "Hide" : "Show";
            toggleButton.setAttribute(
                "aria-label",
                isHidden ? "Hide password" : "Show password"
            );
            toggleButton.setAttribute(
                "aria-pressed",
                String(isHidden)
            );

        });
    }

    // Sign In loading indicator
    if (loginForm && loginSubmit && buttonText) {
        loginForm.addEventListener("submit", function () {

            if (!loginForm.checkValidity()) {
                return;
            }

            loginSubmit.disabled = true;
            buttonText.textContent = "Signing In...";

        });

        // Restore state if the browser returns to a cached page
        window.addEventListener("pageshow", function () {
            loginSubmit.disabled = false;
            buttonText.textContent = "Sign In";
        });
    }

});
