<?php
declare(strict_types=1);
/**
 * Setup Wizard - Web installation page for first-time setup.
 * 
 * This page is shown when no admin/owner user exists in the database.
 * If includes/db.php is missing or the database cannot be reached,
 * a Database Configuration form is shown. On success it writes
 * includes/db.php (based on db.example.php) and reloads into the
 * wizard: Database Check → Create Admin → Complete
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// ============================================================
// Handle Database Configuration POST (writes includes/db.php)
// ============================================================
$configError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'configure_db') {
    $host = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $name = trim((string)($_POST['db_name'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');

    $errors = [];
    if ($host === '') {
        $errors[] = 'Database host is required.';
    }
    if ($name === '') {
        $errors[] = 'Database name is required.';
    }
    if ($user === '') {
        $errors[] = 'Database user is required.';
    }

    if (count($errors) === 0) {
        // Test the connection before writing the config file
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
            $test = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
            $test = null;

            // Write includes/db.php from the db.example.php template
            $template = @file_get_contents(__DIR__ . '/../includes/db.example.php');
            if ($template === false) {
                $errors[] = 'Unable to read includes/db.example.php template.';
            } else {
                // Use preg_replace_callback so replacement values are treated
                // literally (avoids $1-style backreference corruption of passwords).
                $content = preg_replace_callback(
                    "/const (DB_HOST|DB_NAME|DB_USER|DB_PASS)\s*=\s*'[^']*';/i",
                    function (array $matches) use ($host, $name, $user, $pass) {
                        $key = strtoupper($matches[1]);
                        $value = $host;
                        if ($key === 'DB_NAME') {
                            $value = $name;
                        } elseif ($key === 'DB_USER') {
                            $value = $user;
                        } elseif ($key === 'DB_PASS') {
                            $value = $pass;
                        }
                        return 'const ' . $key . ' = ' . var_export($value, true) . ';';
                    },
                    $template
                );
                if (@file_put_contents(__DIR__ . '/../includes/db.php', $content) === false) {
                    $errors[] = 'Unable to write includes/db.php. Please check file permissions.';
                } else {
                    header('Location: /Setup/index.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
        }
    }

    $configError = implode(' ', $errors);
}

// ============================================================
// Determine setup state
// ============================================================
$dbExists = db_config_exists();
$dbConnected = false;
$pdo = null;

if ($dbExists) {
    try {
        require_once __DIR__ . '/../includes/db.php';
        $pdo = get_db();
        $dbConnected = true;
    } catch (Throwable $e) {
        $dbConnected = false;
        if ($configError === '') {
            $configError = 'Database connection failed: ' . $e->getMessage();
        }
    }
}

if ($dbConnected) {
    $setupComplete = is_setup_complete($pdo);
    // If setup is complete, redirect to login
    if ($setupComplete) {
        header('Location: /Login/index.php');
        exit;
    }

    	// Database schema is created and maintained by includes/db.php.
	// The legacy SQL migration runner is intentionally not executed here.
	// This prevents conflicts between ensure_database_schema() and the
	// database/migrations/*.sql files.
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
        .setup-card .info-msg { color: #66b0ff; font-size: 12px; text-align: center; margin-top: 4px; }
        .setup-footer { text-align: center; color: #666; font-size: 12px; margin-top: 24px; }
        .step-indicator { display: flex; justify-content: center; gap: 8px; margin-bottom: 24px; }
        .step-dot { width: 10px; height: 10px; border-radius: 50%; background: #3a3a4e; }
        .step-dot.active { background: #35AD6B; }
        .step-dot.completed { background: #2d9a5e; }
        .step-label { display: flex; justify-content: space-between; margin-top: 4px; font-size: 11px; color: #888; }
        .step-label span { text-align: center; flex: 1; }
        .step-label span.active { color: #35AD6B; font-weight: 600; }
        .db-status { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #2a2a3e; border-radius: 10px; margin-bottom: 16px; border: 1px solid #3a3a4e; }
        .db-status.success { border-color: #35AD6B; }
        .db-status.success .status-icon { color: #35AD6B; }
        .db-status.error { border-color: #ff6b6b; }
        .db-status.loading { border-color: #66b0ff; }
        .status-icon { font-size: 18px; }
        .status-text { font-size: 13px; color: #ccc; }
        .step-content { display: none; }
        .step-content.active { display: block; }
        .config-field { margin-bottom: 14px; }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <!-- Step Indicator -->
            <div class="step-indicator">
                <span class="step-dot active" id="dot-1"></span>
                <span class="step-dot" id="dot-2"></span>
                <span class="step-dot" id="dot-3"></span>
            </div>
            <div class="step-label">
                <span class="active" id="label-1">Database</span>
                <span id="label-2">Admin Account</span>
                <span id="label-3">Complete</span>
            </div>

            <!-- Step 1: Database Connection -->
            <div class="step-content active" id="step-1">
                <?php if (!$dbConnected): ?>
                <h1>Database Setup</h1>
                <p class="subtitle">Enter your MySQL database details to configure the connection.</p>

                <form id="dbConfigForm" class="form-grid" method="post" action="/Setup/index.php">
                    <input type="hidden" name="action" value="configure_db" />
                    <div class="config-field">
                        <label for="db_host">Database Host</label>
                        <input type="text" id="db_host" name="db_host" value="<?= safe_text($host ?? '127.0.0.1') ?>" required autocomplete="off" placeholder="127.0.0.1" />
                    </div>
                    <div class="config-field">
                        <label for="db_name">Database Name</label>
                        <input type="text" id="db_name" name="db_name" value="<?= safe_text($name ?? '') ?>" required autocomplete="off" placeholder="restaurant_pos" />
                    </div>
                    <div class="config-field">
                        <label for="db_user">Database User</label>
                        <input type="text" id="db_user" name="db_user" value="<?= safe_text($user ?? '') ?>" required autocomplete="off" placeholder="pos_user" />
                    </div>
                    <div class="config-field">
                        <label for="db_pass">Database Password</label>
                        <input type="password" id="db_pass" name="db_pass" value="<?= safe_text($pass ?? '') ?>" autocomplete="new-password" placeholder="••••••••" />
                    </div>
                    <?php if ($configError !== ''): ?>
                    <div class="error-msg"><?= safe_text($configError) ?></div>
                    <?php endif; ?>
                    <button type="submit" id="dbConfigSubmit">Save &amp; Continue</button>
                </form>
                <?php else: ?>
                <h1>Database Setup</h1>
                <p class="subtitle">Verifying database connection and running migrations.</p>

                <div class="db-status loading" id="dbStatus">
                    <span class="status-icon">⟳</span>
                    <span class="status-text">Checking database connection...</span>
                </div>

                <div style="text-align:center;margin-top:16px;">
                    <button type="button" id="retryDbCheck" class="secondary-button" style="background:#3a3a4e;color:#ccc;border:1px solid #3a3a4e;padding:10px 20px;border-radius:10px;cursor:pointer;display:none;">Retry Connection</button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Step 2: Create Admin Account -->
            <div class="step-content" id="step-2">
                <h1>Create Administrator</h1>
                <p class="subtitle">Set up your first admin account to get started.</p>

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

            <!-- Step 3: Complete -->
            <div class="step-content" id="step-3">
                <h1>✓ Setup Complete!</h1>
                <p class="subtitle">Your Restaurant POS is ready to use.</p>

                <div style="text-align:center;padding:20px 0;">
                    <div style="font-size:64px;margin-bottom:16px;">🎉</div>
                    <p style="color:#ccc;margin-bottom:8px;">Your administrator account has been created.</p>
                    <p style="color:#888;font-size:13px;">Redirecting to login page...</p>
                    <div style="margin-top:16px;color:#666;font-size:12px;">
                        <p>You can now log in and:</p>
                        <ul style="text-align:left;display:inline-block;color:#888;font-size:13px;line-height:1.8;">
                            <li>Add menu items and categories</li>
                            <li>Create user accounts (waiters, kitchen, bar)</li>
                            <li>Manage inventory and stock levels</li>
                            <li>Configure restaurant settings</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="setup-footer">
            Powered by Brainyte
        </div>
    </div>

    <script>
        // ============================================================
        // SETUP WIZARD - Step-by-step
        // ============================================================

        function goToStep(step) {
            // Update dots
            for (let i = 1; i <= 3; i++) {
                const dot = document.getElementById(`dot-${i}`);
                const label = document.getElementById(`label-${i}`);
                dot.className = 'step-dot';
                label.className = '';
                if (i < step) { dot.classList.add('completed'); label.className = 'completed'; }
                if (i === step) { dot.classList.add('active'); label.className = 'active'; }
            }

            // Update content
            for (let i = 1; i <= 3; i++) {
                document.getElementById(`step-${i}`).classList.toggle('active', i === step);
            }
        }

        // Step 1: Auto-check database connection (only shown when db.php exists and connects)
        async function checkDatabase() {
            const dbStatus = document.getElementById('dbStatus');
            const retryBtn = document.getElementById('retryDbCheck');
            if (!dbStatus) {
                return;
            }

            try {
                dbStatus.className = 'db-status loading';
                dbStatus.querySelector('.status-icon').textContent = '⟳';
                dbStatus.querySelector('.status-text').textContent = 'Checking database connection...';
                retryBtn.style.display = 'none';

                const response = await fetch('/API/Status/index.php');
                const result = await response.json();

                if (response.ok) {
                    dbStatus.className = 'db-status success';
                    dbStatus.querySelector('.status-icon').textContent = '✓';
                    dbStatus.querySelector('.status-text').textContent = '✓ Database connected successfully. Proceeding to admin account setup...';

                    // Also check tables
                    setTimeout(() => {
                        dbStatus.querySelector('.status-text').textContent = '✓ Database connected. All tables are ready.';
                    }, 500);

                    // Move to step 2 after a short delay
                    setTimeout(() => {
                        goToStep(2);
                    }, 1500);
                } else {
                    throw new Error(result.error || 'Connection failed');
                }
            } catch (err) {
                dbStatus.className = 'db-status error';
                dbStatus.querySelector('.status-icon').textContent = '✗';
                dbStatus.querySelector('.status-text').textContent = 'Database connection failed: ' + err.message + '. Please check your configuration in includes/db.php.';
                retryBtn.style.display = 'inline-block';
            }
        }

        document.getElementById('retryDbCheck')?.addEventListener('click', checkDatabase);

        // Step 2: Create admin account
        document.getElementById('setupForm')?.addEventListener('submit', async function(e) {
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
                    msgEl.textContent = '✓ Account created!';
                    msgEl.className = 'success-msg';
                    goToStep(3);
                    // Redirect to login
                    setTimeout(() => {
                        window.location.href = '/Admin/Menu/index.php';
                    }, 3000);
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

        // Auto-start database check when already connected
        <?php if ($dbConnected): ?>
        checkDatabase();
        <?php endif; ?>
    </script>
</body>
</html>

