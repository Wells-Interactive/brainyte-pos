<?php
session_start();
require_once __DIR__ . '/../../includes/utils.php';

$role = $_SESSION['user']['role'] ?? null;
$username = $_SESSION['user']['name'] ?? '';

if ($role !== 'admin') {
    header('Location: ../../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Menu Management | Restaurant POS</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
    <link rel="manifest" href="../../manifest.webmanifest" />
    <meta name="theme-color" content="#1e2d3b" />
    <meta name="description" content="Admin-only menu management page for the restaurant POS." />
</head>
<body>
    <header class="topbar">
        <div class="brand">Restaurant POS</div>
        <nav class="nav-links">
            <a href="../../index.php">Home</a>
            <a href="../../Login/logout.php">Logout</a>
        </nav>
    </header>

    <main class="page-grid">
        <section class="card full-width" id="adminDashboard">
            <h1>Menu Management</h1>
            <p class="message">Create new items and update prices from this admin-only workspace.</p>
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
            </div>

            <div class="card">
                <h3>Highest Selling Items</h3>
                <div id="adminTopItems" class="table-card"></div>
            </div>
            <div id="adminSalesTable" class="table-card"></div>

            <div class="admin-controls">
                <h3>Add Menu Item</h3>
                <form id="adminAddMenuItem" class="form-grid">
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
