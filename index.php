<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/utils.php';
session_start();
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
    <title>Restaurant POS</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <link rel="manifest" href="manifest.webmanifest" />
    <meta name="theme-color" content="#1e2d3b" />
    <meta name="description" content="Mobile-first restaurant POS with waiter, kitchen, bar, and live events." />
</head>
<body>
    <header class="topbar">
        <div class="brand">Restaurant POS</div>
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
            <h1>6th June POS</h1>
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
            </div>
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
            </div>
        </section>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <a href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer" class="footer-link">
            <span class="brainyte-icon" aria-hidden="true">B</span>
            <span>Powered by Brainyte</span>
        </a>
    </footer>

    <script type="module" src="assets/js/main.js"></script>
</body>
</html>
