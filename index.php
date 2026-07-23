<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/utils.php';
session_start();

// Dynamic settings - try to load from database
$restaurantName = 'Restaurant POS';
$footerText = 'Powered by Brainyte';
try {
    $pdo = get_db();
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('restaurant_name', 'footer_text')");
    while ($row = $stmt->fetch()) {
        if ($row['setting_key'] === 'restaurant_name' && $row['setting_value']) $restaurantName = $row['setting_value'];
        if ($row['setting_key'] === 'footer_text' && $row['setting_value']) $footerText = $row['setting_value'];
    }
} catch (Throwable $e) {
    // Use defaults
}

$loggedIn = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;
$username = $_SESSION['username'] ?? $_SESSION['user']['name'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0);

if ($loggedIn === 'waiter') {
    header('Location: /Waiter/index.php');
    exit;
}
if ($loggedIn === 'kitchen') {
    header('Location: /Kitchen/index.php');
    exit;
}
if ($loggedIn === 'bar') {
    header('Location: /Bar/index.php');
    exit;
}
if (in_array($loggedIn, ['manager', 'supervisor'], true)) {
    header('Location: /Manager/index.php');
    exit;
}
if (in_array($loggedIn, ['admin', 'owner'], true)) {
    // Stay on index.php for admin dashboard
}
$role = $loggedIn ?: 'guest';
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= safe_text($restaurantName) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=2.0" />
    <link rel="manifest" href="manifest.webmanifest" />
<meta name="theme-color" content="#35AD6B" />
    <meta name="description" content="Mobile-first restaurant POS with waiter, kitchen, bar, and live events." />
</head>
<body>
    <header class="topbar">
        <div class="brand"><?= safe_text($restaurantName) ?></div>
        <nav class="nav-links">
            <a href="index.php">Home</a>
            <?php if (!$loggedIn): ?>
            <a href="Login/index.php">Login</a>
            <?php endif; ?>
            <a href="Waiter/index.php">Waiter</a>
            <a href="Kitchen/index.php">Kitchen</a>
            <a href="Bar/index.php">Bar</a>
            <?php if ($loggedIn): ?>
            <a href="Login/logout.php">Logout</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="page-grid">
        <section class="hero-card">
            <h1><?= safe_text($restaurantName) ?></h1>
            <p>Touch-friendly, mobile-first point of sale system.</p>
            <div class="status-pill">Current session: <strong><?= safe_text($role) ?></strong></div>
            <?php if ($loggedIn): ?>
                <div class="info-box">Logged in as <strong><?= safe_text($username) ?></strong> (<?= safe_text($role) ?>). <a href="Login/logout.php">Logout</a></div>
            <?php endif; ?>
        </section>

        <?php if (!$loggedIn): ?>
        <section class="card">
            <h2>Login</h2>
            <form id="loginForm" class="form-grid">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" autocomplete="username" required />
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required />
                <button type="submit">Sign In</button>
            </form>
            <div id="loginMessage" class="message"></div>
            <div class="example-accounts">
                <strong>Demo accounts</strong>
                <div>Waiter: waiter@restaurant.local / waiter123</div>
                <div>Kitchen: kitchen@restaurant.local / kitchen123</div>
                <div>Bar: bar@restaurant.local / bar123</div>
                <div>Manager: manager@restaurant.local / manager123</div>
                <div>Supervisor: supervisor@restaurant.local / supervisor123</div>
                <div>Admin: admin@restaurant.local / admin123</div>
                <div>Owner: owner@restaurant.local / owner123</div>
        </section>
        <?php endif; ?>

        <section class="card">
            <h2>Quick Access</h2>
            <div class="link-grid">
                <a class="card-link" href="Waiter/index.php">Waiter Dashboard</a>
                <a class="card-link" href="Kitchen/index.php">Kitchen Board</a>
                <a class="card-link" href="Bar/index.php">Bar Board</a>
            </div>
        </section>

        <?php if (in_array($loggedIn, ['admin', 'owner'], true)): ?>
        <section class="card full-width" id="adminDashboard">
            <h2>Admin Dashboard</h2>
            <div class="admin-actions">
                <a class="button-link" href="Admin/Menu/index.php">Open Menu Management</a>
            </div>
            <div class="admin-grid">
                <div class="card admin-stat">
                    <h3>Total Revenue</h3>
                    <p id="adminTotalRevenue">₦0.00</p>
                </div>
                <div class="card admin-stat">
                    <h3>Completed Orders</h3>
                    <p id="adminCompletedOrders">0</p>
                </div>
                <div class="card admin-stat">
                    <h3>Items Sold</h3>
                    <p id="adminItemsSold">0</p>
                </div>
                <div class="card admin-stat">
                    <h3>Total Bar Orders</h3>
                    <p id="adminBarOrders">0</p>
                </div>
                <div class="card admin-stat">
                    <h3>Total Kitchen Orders</h3>
                    <p id="adminKitchenOrders">0</p>
                </div>
                <div class="card admin-stat">
                    <h3>Pending Orders</h3>
                    <p id="adminPendingOrders">0</p>
                </div>
                <div class="card admin-stat">
                    <h3>Summary (Day)</h3>
                    <p id="adminSummaryDay">₦0.00</p>
                </div>
                <div class="card admin-stat">
                    <h3>Summary (Week)</h3>
                    <p id="adminSummaryWeek">₦0.00</p>
                </div>
                <div class="card admin-stat">
                    <h3>Summary (Month)</h3>
                    <p id="adminSummaryMonth">₦0.00</p>
                </div>

            <div class="card">
                <h3>Highest Selling Items (Top 10)</h3>
                <div id="adminTopItems" class="table-card"></div>

            <div class="card">
                <h3>Live Table Status</h3>
                <p class="message">Green = Available, Red = Occupied, Blue = Reserved</p>
                <div id="adminLiveTables" class="table-grid"></div>

            <div id="adminSalesTable" class="table-card"></div>

            <!-- Settings Management -->
            <div class="card">
                <h3>Restaurant Settings</h3>
                <div class="settings-grid" id="settingsGrid">
                    <div class="setting-group">
                        <h3>General</h3>
                        <div class="form-grid">
                            <label>Restaurant Name</label>
                            <input type="text" id="setting-restaurant_name" placeholder="Enter restaurant name" />
                            <label>VAT Rate (%)</label>
                            <input type="number" id="setting-vat_rate" step="0.01" min="0" max="100" placeholder="0.00" />
                            <label>Currency</label>
                            <select id="setting-currency">
                                <option value="NGN">NGN (₦)</option>
                                <option value="USD">USD ($)</option>
                                <option value="GBP">GBP (£)</option>
                                <option value="EUR">EUR (€)</option>
                            </select>
                            <label>Timezone</label>
                            <select id="setting-timezone">
                                <option value="Africa/Lagos">Africa/Lagos</option>
                                <option value="Africa/Accra">Africa/Accra</option>
                                <option value="Africa/Nairobi">Africa/Nairobi</option>
                                <option value="Africa/Cairo">Africa/Cairo</option>
                                <option value="Europe/London">Europe/London</option>
                                <option value="America/New_York">America/New_York</option>
                            </select>
                            <button type="button" class="primary-button" onclick="updateSetting('restaurant_name')">Save Name</button>
                            <button type="button" class="primary-button" onclick="updateSetting('vat_rate')">Save VAT</button>
                            <button type="button" class="primary-button" onclick="updateSetting('currency')">Save Currency</button>
                            <button type="button" class="primary-button" onclick="updateSetting('timezone')">Save Timezone</button>
                        </div>
                    <div class="setting-group">
                        <h3>Print & Branding</h3>
                        <div class="form-grid">
                            <label>Printer Type</label>
                            <select id="setting-printer_type">
                                <option value="thermal">Thermal (80mm)</option>
                                <option value="a4">A4 Printer</option>
                                <option value="receipt">Receipt (58mm)</option>
                            </select>
                            <label>Footer Text</label>
                            <input type="text" id="setting-footer_text" placeholder="Footer text for receipts" />
                            <label>Logo URL</label>
                            <input type="text" id="setting-logo_url" placeholder="URL to logo image" />
                            <button type="button" class="primary-button" onclick="updateSetting('printer_type')">Save Printer</button>
                            <button type="button" class="primary-button" onclick="updateSetting('footer_text')">Save Footer</button>
                            <button type="button" class="primary-button" onclick="updateSetting('logo_url')">Save Logo</button>
                        </div>
                </div>
                <div id="settingsMessage" class="message"></div>

            <div class="admin-controls">
                <h3>Add Menu Item</h3>
                <form id="adminAddMenuItem" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
                    <label for="adminItemName">Name</label>
                    <input id="adminItemName" name="name" type="text" required />
                    <label for="adminItemDescription">Description</label>
                    <textarea id="adminItemDescription" name="description" rows="3" required></textarea>
                    <label for="adminItemPrice">Price</label>
                    <input id="adminItemPrice" name="price" type="number" step="0.01" min="0" required />
                    <label for="adminItemCategory">Category</label>
                    <select id="adminItemCategory" name="category" required>
                        <option value="">Select category</option>
                    </select>
                    <label for="adminItemAvailable">Available</label>
                    <select id="adminItemAvailable" name="available">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                    <button type="submit" class="primary-button">Create Item</button>
                </form>

                <h3>Update Item Price</h3>
                <form id="adminUpdatePrice" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
                    <label for="adminItemSelect">Existing Item</label>
                    <select id="adminItemSelect" name="id" required>
                        <option value="">Select item</option>
                    </select>
                    <label for="adminPriceUpdate">New Price</label>
                    <input id="adminPriceUpdate" name="price" type="number" step="0.01" min="0" required />
                    <button type="submit" class="primary-button">Update Price</button>
                </form>

                <div id="adminMenuStatus" class="message"></div>

            <!-- User Management (Admin/Owner Only) -->
            <div class="admin-controls">
                <h3>Add User</h3>
                <form id="adminAddUser" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
                    <label for="adminUserName">Full Name</label>
                    <input id="adminUserName" name="name" type="text" required />
                    <label for="adminUserEmail">Email</label>
                    <input id="adminUserEmail" name="email" type="email" required />
                    <label for="adminUserPassword">Password</label>
                    <input id="adminUserPassword" name="password" type="text" required />
                    <label for="adminUserRole">Role</label>
                    <select id="adminUserRole" name="role" required>
                        <option value="">Select role</option>
                        <option value="waiter">Waiter</option>
                        <option value="kitchen">Kitchen</option>
                        <option value="bar">Bar</option>
                        <option value="manager">Manager</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button type="submit" class="primary-button">Add User</button>
                </form>
                <div id="adminUserStatus" class="message"></div>

            <!-- Direct Printing -->
            <div class="admin-controls">
                <h3>Print Settings</h3>
                <div class="toggle-container">
                    <span class="toggle-label">Direct Printing</span>
                    <div id="directPrintingToggle" class="toggle-switch" role="button" tabindex="0" aria-label="Toggle direct printing"></div>
                    <span id="directPrintingStatus" class="toggle-status">Disabled</span>
                </div>
                <p class="message" style="margin-top:0;font-size:0.9rem;">When enabled, orders from waiters are sent directly to the Kitchen and Bar thermal printers without appearing on their dashboards.</p>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <a href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer" class="footer-link">
            <span class="brainyte-icon" aria-hidden="true">B</span>
            <span><?= safe_text($footerText) ?></span>
        </a>
    </footer>

    <script type="module" src="assets/js/main.js?v=2.0"></script>
</body>
</html>
