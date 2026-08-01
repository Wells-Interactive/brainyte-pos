<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/utils.php';
require_setup_or_redirect();
session_start();
$role = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;
$username = $_SESSION['username'] ?? $_SESSION['user']['name'] ?? '';

if (!in_array($role, ['manager', 'supervisor'], true)) {
    header('Location: /index.php');
    exit;
}
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manager Dashboard | Restaurant POS</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=2.0" />
    <link rel="manifest" href="/manifest.webmanifest" />
</head>
<body>
    <header class="topbar">
        <div class="brand">Management Dashboard</div>
        <nav class="nav-links">
            <a href="/index.php">Home</a>
            <a href="/Waiter/index.php">Waiter</a>
            <a href="/Kitchen/index.php">Kitchen</a>
            <a href="/Bar/index.php">Bar</a>
            <a href="/Login/logout.php">Logout</a>
        </nav>
    </header>

    <main class="page-grid">
        <section class="hero-card full-width">
            <h1>Management Dashboard</h1>
            <div class="info-box">Logged in as <strong><?= safe_text($username) ?></strong> (<?= safe_text($role) ?>)</div>
        </section>

        <section class="card full-width" id="managerDashboard">
            <h2>Overview</h2>
            <div class="admin-grid">
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
                    <h3>Total Revenue</h3>
                    <p id="adminTotalRevenue">₦0.00</p>
                </div>
                <div class="card admin-stat">
                    <h3>Completed Orders</h3>
                    <p id="adminCompletedOrders">0</p>
                </div>

            <div class="card">
                <h3>Highest Selling Items</h3>
                <div id="adminTopItems" class="table-card"></div>

            <div class="card">
                <h3>Live Table Status</h3>
                <p class="message">Green = Available, Red = Occupied, Blue = Reserved</p>
                <div id="adminLiveTables" class="table-grid"></div>

            <!-- Reports Section -->
            <div class="card">
                <h3>Reports</h3>
                <div class="report-controls" style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">
                    <select id="reportScope" style="flex:1;min-width:120px;">
                        <option value="day">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="year">This Year</option>
                        <option value="custom">Custom Range</option>
                    </select>
                    <input type="date" id="reportStartDate" style="flex:1;min-width:120px;" />
                    <input type="date" id="reportEndDate" style="flex:1;min-width:120px;" />
                    <select id="reportType" style="flex:1;min-width:120px;">
                        <option value="sales">Sales Report</option>
                        <option value="products">Products Report</option>
                        <option value="staff">Staff Report</option>
                        <option value="payments">Payments Report</option>
                        <option value="operations">Operations Report</option>
                    </select>
                    <button type="button" id="generateReportBtn" class="primary-button">Generate Report</button>
                </div>
                <div id="reportResults" class="table-card" style="overflow-x:auto;">
                    <p class="message">Select report type and date range, then click "Generate Report".</p>
                </div>

            <!-- Inventory Alerts -->
            <div class="card">
                <h3>Inventory Alerts</h3>
                <div id="inventoryAlerts" class="message">Loading inventory alerts...</div>
                <div class="inventory-summary" id="inventorySummary"></div>

            <!-- Settings Management -->
            <div class="card">
                <h3>Restaurant Settings</h3>
                <div class="settings-grid">
                    <div class="setting-group">
                        <h3>General</h3>
                        <div class="form-grid">
                            <label>VAT Rate (%)</label>
                            <input type="number" id="setting-vat_rate" step="0.01" min="0" max="100" placeholder="0.00" />
                            <button type="button" class="primary-button" onclick="updateSetting('vat_rate')">Save VAT</button>
                        </div>
                    <div class="setting-group">
                        <h3>Print & Branding</h3>
                        <div class="form-grid">
                            <label>Footer Text</label>
                            <input type="text" id="setting-footer_text" placeholder="Footer text for receipts" />
                            <button type="button" class="primary-button" onclick="updateSetting('footer_text')">Save Footer</button>
                        </div>
                </div>
                <div id="settingsMessage" class="message"></div>

            <!-- Direct Printing Toggle -->
            <div class="card">
                <h3>Print Settings</h3>
                <div class="toggle-container">
                    <span class="toggle-label">Direct Printing</span>
                    <div id="directPrintingToggle" class="toggle-switch" role="button" tabindex="0" aria-label="Toggle direct printing"></div>
                    <span id="directPrintingStatus" class="toggle-status">Disabled</span>
                </div>
                <p class="message" style="margin-top:0;font-size:0.9rem;">When enabled, orders from waiters are sent directly to the Kitchen and Bar thermal printers without appearing on their dashboards.</p>
            </div>
        </section>
    </main>

    <footer class="footer">
        <a href="#" class="footer-link" id="brainyteFooterLink">
            <span class="brainyte-icon" aria-hidden="true">B</span>
            <span>Powered by Brainyte</span>
        </a>
    </footer>

    <script type="module" src="/assets/js/main.js?v=2.0"></script>
    <script src="/assets/js/brainyte-popup.js"></script>
</body>
</html>
