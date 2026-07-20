<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/utils.php';
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
    <link rel="stylesheet" href="/assets/css/style.css" />
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
            </div>

            <div class="card">
                <h3>Highest Selling Items</h3>
                <div id="adminTopItems" class="table-card"></div>
            </div>

            <div class="card">
                <h3>Live Table Status</h3>
                <p class="message">Green = Available, Red = Occupied, Blue = Reserved</p>
                <div id="adminLiveTables" class="table-grid"></div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <a href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer" class="footer-link">
            <span class="brainyte-icon" aria-hidden="true">B</span>
            <span>Powered by Brainyte</span>
        </a>
    </footer>

    <script type="module" src="/assets/js/main.js"></script>
</body>
</html>

