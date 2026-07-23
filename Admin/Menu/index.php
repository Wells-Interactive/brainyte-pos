<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/utils.php';
session_start();
$role = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;
$username = $_SESSION['username'] ?? $_SESSION['user']['name'] ?? '';

if (!in_array($role, ['admin', 'owner'], true)) {
    header('Location: ../../index.php');
    exit;
}
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard | Restaurant POS</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
    <link rel="manifest" href="../../manifest.webmanifest" />
<meta name="theme-color" content="#4CAF50" />
    <meta name="description" content="Admin-only menu management page for the restaurant POS." />
</head>
<body>
    <header class="topbar">
        <div class="brand">Restaurant POS - Admin</div>
        <nav class="nav-links">
            <a href="../../index.php">Home</a>
            <a href="../../Login/logout.php">Logout</a>
        </nav>
    </header>

    <main class="page-grid">
        <section class="card full-width" id="adminDashboard">
            <h1>Admin Dashboard</h1>
            <p class="message">Create new items, update prices and manage users from this admin-only workspace.</p>
            <div class="info-box">Logged in as <strong><?= safe_text($username) ?></strong> (<?= safe_text($role) ?>)</div>

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
            </div>

            <div class="card">
                <h3>Highest Selling Items (Top 10)</h3>
                <div id="adminTopItems" class="table-card"></div>
            </div>

            <div class="card">
                <h3>Live Table Status</h3>
                <p class="message">Green = Available, Red = Occupied, Blue = Reserved</p>
                <div id="adminLiveTables" class="table-grid"></div>
            </div>

            <div id="adminSalesTable" class="table-card"></div>

            <!-- Direct Printing Toggle (Admin/Owner Only) -->
            <div class="card">
                <h3>Print Settings</h3>
                <div class="toggle-container">
                    <span class="toggle-label">Direct Printing</span>
                    <div id="directPrintingToggle" class="toggle-switch" role="button" tabindex="0" aria-label="Toggle direct printing"></div>
                    <span id="directPrintingStatus" class="toggle-status">Disabled</span>
                </div>
                <p class="message" style="margin-top:0;font-size:0.9rem;">When enabled, orders from waiters are sent directly to the Kitchen and Bar thermal printers without appearing on their dashboards.</p>
            </div>

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
            </div>

            <!-- User Management -->
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
            </div>
        </section>
    </main>

    <footer class="footer">
        <a href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer" class="footer-link">
            <span class="brainyte-icon" aria-hidden="true">B</span>
            <span>Powered by Brainyte</span>
        </a>
    </footer>

    <script type="module" src="../../assets/js/main.js"></script>
</body>
</html>
