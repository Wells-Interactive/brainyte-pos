<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/utils.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10251b">
    <meta name="description" content="Create and verify a Brainyte POS customer account.">
    <title>Customer Sign Up | Brainyte POS</title>
    <link rel="stylesheet" href="/assets/css/public-pages.css">
</head>
<body>
    <main class="public-shell">
        <nav class="public-nav" aria-label="Main navigation">
            <a class="public-brand" href="/index.php" aria-label="Brainyte POS home">
                <img src="/assets/images/logo.png" alt="Brainyte POS">
            </a>
            <div class="public-nav-links">
                <a href="/pricing.php">Pricing</a>
                <a href="/contact.php">Contact</a>
                <a href="/index.php">Sign in</a>
            </div>
        </nav>

        <section class="signup-card" aria-labelledby="signupTitle">
            <header class="signup-heading">
                <p class="public-eyebrow">Customer account</p>
                <h1 id="signupTitle">Create your account</h1>
                <p>Register to use customer services. We’ll send a verification code to your email.</p>
            </header>

            <form id="customerSignupForm" class="signup-form" novalidate>
                <label for="customerName">Full name</label>
                <input id="customerName" name="name" autocomplete="name" required maxlength="80">

                <label for="customerEmail">Email address</label>
                <input id="customerEmail" name="email" type="email" autocomplete="email" required maxlength="120">

                <label for="customerPhone">Phone number</label>
                <input id="customerPhone" name="phone_number" type="tel" autocomplete="tel" required minlength="7">

                <label for="customerPassword">Password</label>
                <input id="customerPassword" name="password" type="password" autocomplete="new-password" required minlength="8">

                <button class="signup-submit" type="submit" id="signupSubmit">Create account</button>
            </form>

            <form id="customerVerifyForm" class="signup-form" hidden novalidate>
                <label for="verificationCode">Email verification code</label>
                <input id="verificationCode" name="code" inputmode="numeric" autocomplete="one-time-code" required minlength="6" maxlength="6">
                <button class="signup-submit" type="submit" id="verifySubmit">Verify email</button>
                <button class="signup-resend" type="button" id="resendCode">Send a new code</button>
            </form>

            <p id="signupMessage" class="signup-message" role="status" aria-live="polite"></p>
            <p class="signup-signin">Already have an account? <a href="/index.php">Sign in</a></p>
        </section>
    </main>

    <script>
        const registrationEndpoint = '/API/v1/auth/customer.php';
        const signupForm = document.getElementById('customerSignupForm');
        const verifyForm = document.getElementById('customerVerifyForm');
        const message = document.getElementById('signupMessage');
        const emailInput = document.getElementById('customerEmail');
        let isSubmitting = false;

        async function sendRequest(payload) {
            const response = await fetch(registrationEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || 'Unable to complete registration.');
            }
            return result;
        }

        async function submitRegistration(payload, button, action) {
            if (isSubmitting) return;
            isSubmitting = true;
            button.disabled = true;
            message.textContent = '';
            try {
                const result = await sendRequest(payload);
                message.textContent = result.data?.message || 'Request completed.';
                if (action === 'register') {
                    signupForm.hidden = true;
                    verifyForm.hidden = false;
                    document.getElementById('verificationCode').focus();
                } else if (action === 'verify') {
                    verifyForm.hidden = true;
                    document.querySelector('.signup-signin').hidden = false;
                }
            } catch (error) {
                message.textContent = error.message || 'Unable to complete registration.';
            } finally {
                isSubmitting = false;
                button.disabled = false;
            }
        }

        signupForm.addEventListener('submit', event => {
            event.preventDefault();
            if (!signupForm.reportValidity()) return;
            const form = new FormData(signupForm);
            submitRegistration({
                action: 'register',
                name: String(form.get('name')).trim(),
                email: String(form.get('email')).trim(),
                phone_number: String(form.get('phone_number')).trim(),
                password: form.get('password'),
            }, document.getElementById('signupSubmit'), 'register');
        });

        verifyForm.addEventListener('submit', event => {
            event.preventDefault();
            if (!verifyForm.reportValidity()) return;
            submitRegistration({
                action: 'verify',
                email: emailInput.value.trim(),
                code: document.getElementById('verificationCode').value.trim(),
            }, document.getElementById('verifySubmit'), 'verify');
        });

        document.getElementById('resendCode').addEventListener('click', event => {
            submitRegistration({
                action: 'resend',
                email: emailInput.value.trim(),
            }, event.currentTarget, 'resend');
        });
    </script>
</body>
</html>
