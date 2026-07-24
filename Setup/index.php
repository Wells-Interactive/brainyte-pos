<?php
declare(strict_types=1);
/**
 * Setup Wizard - Web installation page for first-time setup.
 * 
 * This page is shown when no admin/owner user exists in the database.
 * It guides the user through creating the first admin account.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';

$pdo = get_db();
$setupComplete = is_setup_complete($pdo);

// If setup is complete, redirect to login
if ($setupComplete) {
    header('Location: /Login/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Setup | Restaurant POS</title>
    <link rel="stylesheet" href="/assets/css/style.css" />
    <meta name="description" content="First-time setup wizard for Brainyte Restaurant POS." />
    <style>
        .setup-container { max-width: 520px; margin: 40px auto; padding: 0 16px; }
        .setup-card { background: #1e1e2e; border-radius: 16px; padding: 32px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); }
        .setup-card h1 { color: #fff; font-size: 24px; margin-bottom: 8px; text-align: center; }
        .setup-card .subtitle { color: #888; text-align: center; margin-bottom: 28px; font-size: 14px; }
        .setup-card .form-grid { display: flex; flex-direction: column; gap: 16px; }
        .setup-card label { color: #ccc; font-size: 13px; font-weight: 600; }
        .setup-card input, .setup-card select { 
            background: #2a2a3e; border: 1px solid #3a3a4e; color: #fff; padding: 12px 16px;
            border-radius: 10px; font-size: 15px; width: 100%; box-sizing: border-box;
        }
        .setup-card input:focus, .setup-card select:focus { outline: none; border-color: #35AD6B; }
        .setup-card button { 
            background: #35AD6B; color: #fff; border: none; padding: 14px; border-radius: 10px;
            font-size: 16px; font-weight: 700; cursor: pointer; margin-top: 8px; transition: background 0.2s;
        }
        .setup-card button:hover { background: #2d9a5e; }
        .setup-card button:disabled { opacity: 0.6; cursor: not-allowed; }
        .setup-card .error-msg { color: #ff6b6b; font-size: 13px; text-align: center; margin-top: 8px; min-height: 20px; }
        .setup-card .success-msg { color: #35AD6B; font-size: 13px; text-align: center; margin-top: 8px; }
        .setup-footer { text-align: center; color: #666; font-size: 12px; margin-top: 24px; }
        .step-indicator { display: flex; justify-content: center; gap: 8px; margin-bottom: 24px; }
        .step-dot { width: 10px; height: 10px; border-radius: 50%; background: #3a3a4e; }
        .step-dot.active { background: #35AD6B; }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <div class="step-indicator">
                <span class="step-dot active"></span>
                <span class="step-dot"></span>
                <span class="step-dot"></span>
            </div>
            
            <h1>Welcome to Restaurant POS</h1>
            <p class="subtitle">Let's get you started. Create your first administrator account.</p>
            
            <form id="setupForm" class="form-grid">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" placeholder="Enter your full name" required autocomplete="name" />
                
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="admin@restaurant.com" required autocomplete="email" />
                
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Min. 8 characters" required minlength="8" autocomplete="new-password" />
                
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" placeholder="Repeat password" required autocomplete="new-password" />
                
                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="admin">Administrator</option>
                    <option value="owner">Owner</option>
                </select>
                
                <div id="setupMessage" class="error-msg"></div>
                
                <button type="submit" id="setupSubmit">Create Admin Account</button>
            </form>
        </div>
        <div class="setup-footer">
            Powered by Brainyte &bull; Restaurant POS
        </div>

    <script>
        document.getElementById('setupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('setupSubmit');
            const msgEl = document.getElementById('setupMessage');
            
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirm').value;
            const role = document.getElementById('role').value;
            
            // Client-side validation
            if (!name || !email || !password) {
                msgEl.textContent = 'All fields are required.';
                return;
            }
            
            if (password.length < 8) {
                msgEl.textContent = 'Password must be at least 8 characters.';
                return;
            }
            
            if (password !== passwordConfirm) {
                msgEl.textContent = 'Passwords do not match.';
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Setting up...';
            msgEl.textContent = '';
            msgEl.className = 'error-msg';
            
            try {
                const response = await fetch('/API/Setup/index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, email, password, password_confirm: passwordConfirm, role }),
                });
                
                const result = await response.json();
                
                if (result.success) {
                    msgEl.textContent = '✓ Setup complete! Redirecting to login...';
                    msgEl.className = 'success-msg';
                    setTimeout(() => {
                        window.location.href = '/Login/index.php';
                    }, 1500);
                } else {
                    msgEl.textContent = result.error || 'Setup failed. Please try again.';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Create Admin Account';
                }
            } catch (err) {
                msgEl.textContent = 'Network error. Please check your connection.';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Admin Account';
            }
        });
    </script>
</body>
</html>
