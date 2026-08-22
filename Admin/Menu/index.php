<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/utils.php';
require_setup_or_redirect();

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | Restaurant POS</title>

    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="manifest" href="../../manifest.webmanifest">

    <meta name="theme-color" content="#35AD6B">
    <meta
        name="description"
        content="Brainyte Restaurant POS administration dashboard"
    >

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body class="admin-page">

<!-- =========================================================
     MOBILE / DESKTOP HEADER
========================================================= -->

<header class="admin-topbar">

    <div class="admin-topbar-left">

        <button
            type="button"
            class="mobile-menu-button"
            id="adminMobileMenuButton"
            aria-label="Open navigation"
        >
            <i data-lucide="menu"></i>
        </button>

        <div class="admin-brand">

            <div class="admin-brand-icon">
                <i data-lucide="utensils"></i>
            </div>

            <div>
                <strong>Brainyte POS</strong>
                <span>Administration</span>
            </div>

        </div>

    </div>

    <div class="admin-topbar-right">

        <button
            type="button"
            class="admin-icon-button"
            aria-label="Notifications"
        >
            <i data-lucide="bell"></i>
            <span class="notification-dot"></span>
        </button>

        <div class="admin-user">

            <div class="admin-user-avatar">
                <?= strtoupper(substr(safe_text($username), 0, 1)) ?>
            </div>

            <div class="admin-user-details">
                <strong><?= safe_text($username) ?></strong>
                <span><?= safe_text(ucfirst((string)$role)) ?></span>
            </div>

        </div>

        <a
            href="../../Login/logout.php"
            class="admin-logout"
            title="Logout"
        >
            <i data-lucide="log-out"></i>
        </a>

    </div>

</header>


<!-- =========================================================
     ADMIN APPLICATION
========================================================= -->

<div class="admin-layout">

    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="admin-sidebar" id="adminSidebar">

        <div class="admin-sidebar-header">
            <span>MAIN MENU</span>
        </div>

        <nav class="admin-navigation">

            <button
                type="button"
                class="admin-nav-item active"
                data-admin-section="dashboard"
            >
                <i data-lucide="layout-dashboard"></i>
                <span>Dashboard</span>
            </button>

            <button
                type="button"
                class="admin-nav-item"
                data-admin-section="orders"
            >
                <i data-lucide="shopping-bag"></i>
                <span>Orders</span>
                <span class="nav-badge" id="adminNavPendingOrders">0</span>
            </button>

            <button
                type="button"
                class="admin-nav-item"
                data-admin-section="menu"
            >
                <i data-lucide="utensils-crossed"></i>
                <span>Menu</span>
            </button>

            <button
                type="button"
                class="admin-nav-item"
                data-admin-section="inventory"
            >
                <i data-lucide="package"></i>
                <span>Inventory</span>
                <span class="nav-badge danger" id="adminNavInventoryAlert">0</span>
            </button>

            <button
                type="button"
                class="admin-nav-item"
                data-admin-section="staff"
            >
                <i data-lucide="users"></i>
                <span>Staff</span>
            </button>

            <button
                type="button"
                class="admin-nav-item"
                data-admin-section="reports"
            >
                <i data-lucide="bar-chart-3"></i>
                <span>Reports</span>
            </button>

            <div class="admin-sidebar-divider"></div>

            <div class="admin-sidebar-header">
                <span>SYSTEM</span>
            </div>

            <button
                type="button"
                class="admin-nav-item"
                data-admin-section="settings"
            >
                <i data-lucide="settings"></i>
                <span>Settings</span>
            </button>

            <a
                href="../../index.php"
                class="admin-nav-item admin-nav-link"
            >
                <i data-lucide="home"></i>
                <span>Back to POS</span>
            </a>

        </nav>

        <div class="admin-sidebar-footer">

            <div class="admin-powered">

                <div class="admin-powered-icon">
                    B
                </div>

                <div>
                    <strong>Brainyte</strong>
                    <span>Restaurant POS</span>
                </div>

            </div>

        </div>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="admin-main-content">

        <!-- =================================================
             DASHBOARD
        ================================================== -->

        <section
            class="admin-section active"
            id="adminSectionDashboard"
            data-section="dashboard"
        >

            <div class="admin-section-header">

                <div>
                    <span class="admin-eyebrow">OVERVIEW</span>

                    <h1>Dashboard</h1>

                    <p>
                        Welcome back,
                        <strong><?= safe_text($username) ?></strong>.
                        Here's what's happening today.
                    </p>
                </div>

                <div class="admin-header-actions">

                    <button
                        type="button"
                        class="secondary-button admin-refresh-button"
                        id="adminRefreshDashboard"
                    >
                        <i data-lucide="refresh-cw"></i>
                        Refresh
                    </button>

                </div>

            </div>


            <!-- STATISTICS -->

            <div class="admin-stat-grid">

                <article class="admin-stat-card revenue">

                    <div class="admin-stat-icon">
                        <i data-lucide="wallet"></i>
                    </div>

                    <div class="admin-stat-content">
                        <span>Total Revenue</span>
                        <strong id="adminTotalRevenue">₦0.00</strong>
                    </div>

                </article>


                <article class="admin-stat-card">

                    <div class="admin-stat-icon">
                        <i data-lucide="check-circle"></i>
                    </div>

                    <div class="admin-stat-content">
                        <span>Completed Orders</span>
                        <strong id="adminCompletedOrders">0</strong>
                    </div>

                </article>


                <article class="admin-stat-card warning">

                    <div class="admin-stat-icon">
                        <i data-lucide="clock-3"></i>
                    </div>

                    <div class="admin-stat-content">
                        <span>Pending Orders</span>
                        <strong id="adminPendingOrders">0</strong>
                    </div>

                </article>


                <article class="admin-stat-card">

                    <div class="admin-stat-icon">
                        <i data-lucide="shopping-basket"></i>
                    </div>

                    <div class="admin-stat-content">
                        <span>Items Sold</span>
                        <strong id="adminItemsSold">0</strong>
                    </div>

                </article>

            </div>


            <!-- SALES SUMMARY -->

            <div class="admin-dashboard-grid">

                <article class="admin-panel admin-sales-summary">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Sales Summary</h2>
                            <p>Restaurant performance</p>
                        </div>

                        <i data-lucide="trending-up"></i>

                    </div>

                    <div class="sales-summary-grid">

                        <div>
                            <span>Today</span>
                            <strong id="adminSummaryDay">₦0.00</strong>
                        </div>

                        <div>
                            <span>This Week</span>
                            <strong id="adminSummaryWeek">₦0.00</strong>
                        </div>

                        <div>
                            <span>This Month</span>
                            <strong id="adminSummaryMonth">₦0.00</strong>
                        </div>

                    </div>

                </article>


                <!-- ORDER CHANNELS -->

                <article class="admin-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Order Activity</h2>
                            <p>Kitchen and bar</p>
                        </div>

                        <i data-lucide="activity"></i>

                    </div>

                    <div class="activity-grid">

                        <div class="activity-item">

                            <div class="activity-icon kitchen">
                                <i data-lucide="chef-hat"></i>
                            </div>

                            <div>
                                <span>Kitchen Orders</span>
                                <strong id="adminKitchenOrders">0</strong>
                            </div>

                        </div>


                        <div class="activity-item">

                            <div class="activity-icon bar">
                                <i data-lucide="wine"></i>
                            </div>

                            <div>
                                <span>Bar Orders</span>
                                <strong id="adminBarOrders">0</strong>
                            </div>

                        </div>

                    </div>

                </article>

            </div>


            <!-- TABLES + TOP ITEMS -->

            <div class="admin-dashboard-grid">

                <article class="admin-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Live Tables</h2>
                            <p>Current restaurant table status</p>
                        </div>

                        <div class="table-status-legend">

                            <span>
                                <i class="legend-dot available"></i>
                                Available
                            </span>

                            <span>
                                <i class="legend-dot occupied"></i>
                                Occupied
                            </span>

                            <span>
                                <i class="legend-dot reserved"></i>
                                Reserved
                            </span>

                        </div>

                    </div>

                    <div
                        id="adminLiveTables"
                        class="admin-table-grid"
                    ></div>

                </article>


                <article class="admin-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Top Selling Items</h2>
                            <p>Best performing menu items</p>
                        </div>

                        <i data-lucide="award"></i>

                    </div>

                    <div
                        id="adminTopItems"
                        class="admin-top-items"
                    ></div>

                </article>

            </div>


            <!-- INVENTORY -->

            <article class="admin-panel">

                <div class="admin-panel-header">

                    <div>
                        <h2>Inventory Alerts</h2>
                        <p>Items that need your attention</p>
                    </div>

                    <button
                        type="button"
                        class="panel-action"
                        data-admin-section-link="inventory"
                    >
                        View Inventory
                        <i data-lucide="arrow-right"></i>
                    </button>

                </div>

                <div id="inventoryAlerts" class="admin-inventory-alerts">
                    Loading inventory alerts...
                </div>

                <div
                    id="inventorySummary"
                    class="inventory-summary"
                ></div>

            </article>


            <!-- RECENT SALES -->

            <article class="admin-panel">

                <div class="admin-panel-header">

                    <div>
                        <h2>Recent Completed Sales</h2>
                        <p>Latest completed restaurant orders</p>
                    </div>

                    <button
                        type="button"
                        class="panel-action"
                        data-admin-section-link="orders"
                    >
                        View Orders
                        <i data-lucide="arrow-right"></i>
                    </button>

                </div>

                <div
                    id="adminSalesTable"
                    class="admin-table-wrapper"
                ></div>

            </article>

        </section>


        <!-- =================================================
             ORDERS
        ================================================== -->

        <section
            class="admin-section"
            id="adminSectionOrders"
            data-section="orders"
        >

            <div class="admin-section-header">

                <div>
                    <span class="admin-eyebrow">OPERATIONS</span>
                    <h1>Orders</h1>
                    <p>Monitor restaurant order activity.</p>
                </div>

                <button
                    type="button"
                    class="secondary-button"
                    id="adminOrdersRefresh"
                >
                    <i data-lucide="refresh-cw"></i>
                    Refresh
                </button>

            </div>

            <div class="order-overview-grid">

                <div class="order-overview-card">
                    <i data-lucide="clock"></i>
                    <span>Pending</span>
                    <strong id="ordersPendingCount">0</strong>
                </div>

                <div class="order-overview-card">
                    <i data-lucide="chef-hat"></i>
                    <span>Kitchen</span>
                    <strong id="ordersKitchenCount">0</strong>
                </div>

                <div class="order-overview-card">
                    <i data-lucide="wine"></i>
                    <span>Bar</span>
                    <strong id="ordersBarCount">0</strong>
                </div>

                <div class="order-overview-card">
                    <i data-lucide="check-circle"></i>
                    <span>Completed</span>
                    <strong id="ordersCompletedCount">0</strong>
                </div>

            </div>

            <article class="admin-panel">

                <div class="admin-panel-header">

                    <div>
                        <h2>Completed Sales</h2>
                        <p>Recent completed orders</p>
                    </div>

                </div>

                <div
                    id="adminOrdersTable"
                    class="admin-table-wrapper"
                ></div>

            </article>

        </section>


        <!-- =================================================
             MENU
        ================================================== -->

        <section
            class="admin-section"
            id="adminSectionMenu"
            data-section="menu"
        >

            <div class="admin-section-header">

                <div>
                    <span class="admin-eyebrow">CATALOGUE</span>
                    <h1>Menu Management</h1>
                    <p>Create menu items and manage pricing.</p>
                </div>

            </div>


            <div class="admin-management-grid">

                <article class="admin-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Add Menu Item</h2>
                            <p>Create a new food or drink item.</p>
                        </div>

                        <i data-lucide="plus-circle"></i>

                    </div>

                    <form
                        id="adminAddMenuItem"
                        class="admin-form"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= $csrf_token ?>"
                        >

                        <div class="form-field">

                            <label for="adminItemName">
                                Name
                            </label>

                            <input
                                id="adminItemName"
                                name="name"
                                type="text"
                                required
                                placeholder="e.g. Jollof Rice"
                            >

                        </div>


                        <div class="form-field">

                            <label for="adminItemDescription">
                                Description
                            </label>

                            <textarea
                                id="adminItemDescription"
                                name="description"
                                rows="3"
                                required
                                placeholder="Describe the menu item"
                            ></textarea>

                        </div>


                        <div class="form-row">

                            <div class="form-field">

                                <label for="adminItemPrice">
                                    Price
                                </label>

                                <div class="input-with-prefix">
                                    <span>₦</span>

                                    <input
                                        id="adminItemPrice"
                                        name="price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        required
                                        placeholder="0.00"
                                    >

                                </div>

                            </div>


                            <div class="form-field">

                                <label for="adminItemCategory">
                                    Category
                                </label>

                                <select
                                    id="adminItemCategory"
                                    name="category"
                                    required
                                >
                                    <option value="">
                                        Select category
                                    </option>
                                </select>

                            </div>

                        </div>


                        <div class="form-field">

                            <label for="adminItemAvailable">
                                Availability
                            </label>

                            <select
                                id="adminItemAvailable"
                                name="available"
                            >
                                <option value="1">Available</option>
                                <option value="0">Unavailable</option>
                            </select>

                        </div>


                        <button
                            type="submit"
                            class="primary-button"
                        >
                            <i data-lucide="plus"></i>
                            Create Menu Item
                        </button>

                    </form>

                    <div
                        id="adminMenuStatus"
                        class="form-status"
                    ></div>

                </article>


                <article class="admin-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Update Price</h2>
                            <p>Quickly change an existing item's price.</p>
                        </div>

                        <i data-lucide="tag"></i>

                    </div>

                    <form
                        id="adminUpdatePrice"
                        class="admin-form"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= $csrf_token ?>"
                        >

                        <div class="form-field">

                            <label for="adminItemSelect">
                                Existing Item
                            </label>

                            <select
                                id="adminItemSelect"
                                name="id"
                                required
                            >
                                <option value="">
                                    Select item
                                </option>
                            </select>

                        </div>


                        <div class="form-field">

                            <label for="adminPriceUpdate">
                                New Price
                            </label>

                            <div class="input-with-prefix">
                                <span>₦</span>

                                <input
                                    id="adminPriceUpdate"
                                    name="price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    placeholder="0.00"
                                >

                            </div>

                        </div>


                        <button
                            type="submit"
                            class="primary-button"
                        >
                            <i data-lucide="save"></i>
                            Update Price
                        </button>

                    </form>

                </article>

            </div>

        </section>


        <!-- =================================================
             INVENTORY
        ================================================== -->

        <section
            class="admin-section"
            id="adminSectionInventory"
            data-section="inventory"
        >

            <div class="admin-section-header">

                <div>
                    <span class="admin-eyebrow">STOCK CONTROL</span>
                    <h1>Inventory</h1>
                    <p>Monitor stock levels and record adjustments.</p>
                </div>

            </div>


            <article class="admin-panel">

                <div class="admin-panel-header">

                    <div>
                        <h2>Inventory Overview</h2>
                        <p>Current stock status</p>
                    </div>

                    <i data-lucide="package"></i>

                </div>

                <div
                    id="inventorySummary"
                    class="inventory-summary inventory-summary-large"
                ></div>

            </article>


            <div class="admin-management-grid">

                <article class="admin-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Stock Alerts</h2>
                            <p>Items requiring attention</p>
                        </div>

                        <i data-lucide="alert-triangle"></i>

                    </div>

                    <div
                        id="inventoryAlerts"
                        class="admin-inventory-alerts"
                    >
                        Loading inventory alerts...
                    </div>

                </article>


                <article class="admin-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Adjust Stock</h2>
                            <p>Add or remove stock.</p>
                        </div>

                        <i data-lucide="boxes"></i>

                    </div>

                    <form
                        id="stockAdjustForm"
                        class="admin-form"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= $csrf_token ?>"
                        >

                        <div class="form-field">

                            <label for="stockItemSelect">
                                Menu Item
                            </label>

                            <select
                                id="stockItemSelect"
                                name="menu_item_id"
                                required
                            >
                                <option value="">
                                    Select item
                                </option>
                            </select>

                        </div>


                        <div class="form-field">

                            <label for="stockQuantity">
                                Quantity
                            </label>

                            <input
                                id="stockQuantity"
                                name="quantity"
                                type="number"
                                step="1"
                                required
                                placeholder="+50 or -5"
                            >

                            <small>
                                Positive numbers add stock.
                                Negative numbers remove stock.
                            </small>

                        </div>


                        <div class="form-field">

                            <label for="stockReason">
                                Reason
                            </label>

                            <textarea
                                id="stockReason"
                                name="reason"
                                rows="3"
                                required
                                placeholder="Supplier delivery, spoilage, damaged goods..."
                            ></textarea>

                        </div>


                        <button
                            type="submit"
                            class="primary-button"
                        >
                            <i data-lucide="package-plus"></i>
                            Adjust Stock
                        </button>

                    </form>

                    <div
                        id="stockAdjustMessage"
                        class="form-status"
                    ></div>

                </article>

            </div>


            <article class="admin-panel">

                <div class="admin-panel-header">

                    <div>
                        <h2>Inventory Audit Trail</h2>
                        <p>Recent stock movements and adjustments.</p>
                    </div>

                    <i data-lucide="history"></i>

                </div>

                <div id="inventoryAuditTrail">
                    Loading...
                </div>

            </article>

        </section>


        <!-- =================================================
             STAFF
        ================================================== -->

        <section
            class="admin-section"
            id="adminSectionStaff"
            data-section="staff"
        >

            <div class="admin-section-header">

                <div>
                    <span class="admin-eyebrow">PEOPLE</span>
                    <h1>Staff Management</h1>
                    <p>Create staff accounts and assign POS roles.</p>
                </div>

            </div>


            <div class="admin-management-grid">

                <article class="admin-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>Add Staff Member</h2>
                            <p>Create a new POS account.</p>
                        </div>

                        <i data-lucide="user-plus"></i>

                    </div>


                    <form
                        id="adminAddUser"
                        class="admin-form"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= $csrf_token ?>"
                        >


                        <div class="form-field">

                            <label for="adminUserName">
                                Full Name
                            </label>

                            <input
                                id="adminUserName"
                                name="name"
                                type="text"
                                required
                                placeholder="Staff member's full name"
                            >

                        </div>


                        <div class="form-field">

                            <label for="adminUserEmail">
                                Email
                            </label>

                            <input
                                id="adminUserEmail"
                                name="email"
                                type="email"
                                required
                                placeholder="staff@example.com"
                            >

                        </div>


                        <div class="form-field">

                            <label for="adminUserPassword">
                                Password
                            </label>

                            <input
                                id="adminUserPassword"
                                name="password"
                                type="password"
                                required
                                placeholder="Temporary password"
                            >

                        </div>


                        <div class="form-field">

                            <label for="adminUserRole">
                                Role
                            </label>

                            <select
                                id="adminUserRole"
                                name="role"
                                required
                            >
                                <option value="">
                                    Select role
                                </option>

                                <option value="waiter">
                                    Waiter
                                </option>

                                <option value="kitchen">
                                    Kitchen
                                </option>

                                <option value="bar">
                                    Bar
                                </option>

                                <option value="manager">
                                    Manager
                                </option>

                                <option value="supervisor">
                                    Supervisor
                                </option>

                                <option value="admin">
                                    Admin
                                </option>

                                <option value="rider">
                                    Rider
                                </option>

                            </select>

                        </div>


                        <button
                            type="submit"
                            class="primary-button"
                        >
                            <i data-lucide="user-plus"></i>
                            Add Staff Member
                        </button>

                    </form>


                    <div
                        id="adminUserStatus"
                        class="form-status"
                    ></div>

                </article>


                <article class="admin-panel staff-role-panel">

                    <div class="admin-panel-header">

                        <div>
                            <h2>POS Roles</h2>
                            <p>Access levels available to staff.</p>
                        </div>

                        <i data-lucide="shield-check"></i>

                    </div>


                    <div class="role-list">

                        <div class="role-item">
                            <i data-lucide="concierge-bell"></i>
                            <div>
                                <strong>Waiter</strong>
                                <span>Tables and customer orders</span>
                            </div>
                        </div>

                        <div class="role-item">
                            <i data-lucide="chef-hat"></i>
                            <div>
                                <strong>Kitchen</strong>
                                <span>Kitchen order preparation</span>
                            </div>
                        </div>

                        <div class="role-item">
                            <i data-lucide="wine"></i>
                            <div>
                                <strong>Bar</strong>
                                <span>Drinks and bar orders</span>
                            </div>
                        </div>

                        <div class="role-item">
                            <i data-lucide="briefcase-business"></i>
                            <div>
                                <strong>Manager</strong>
                                <span>Restaurant operations</span>
                            </div>
                        </div>

                        <div class="role-item">
                            <i data-lucide="shield"></i>
                            <div>
                                <strong>Admin</strong>
                                <span>System administration</span>
                            </div>
                        </div>

                    </div>

                </article>

            </div>

        </section>


        <!-- =================================================
             REPORTS
        ================================================== -->

        <section
            class="admin-section"
            id="adminSectionReports"
            data-section="reports"
        >

            <div class="admin-section-header">

                <div>
                    <span class="admin-eyebrow">ANALYTICS</span>
                    <h1>Reports</h1>
                    <p>Analyse restaurant performance.</p>
                </div>

            </div>


            <article class="admin-panel">

                <div class="admin-panel-header">

                    <div>
                        <h2>Generate Report</h2>
                        <p>Select the report and reporting period.</p>
                    </div>

                    <i data-lucide="bar-chart-3"></i>

                </div>


                <div class="report-controls">

                    <div class="form-field">

                        <label for="reportScope">
                            Period
                        </label>

                        <select id="reportScope">

                            <option value="day">
                                Today
                            </option>

                            <option value="week">
                                This Week
                            </option>

                            <option value="month">
                                This Month
                            </option>

                            <option value="custom">
                                Custom Range
                            </option>

                        </select>

                    </div>


                    <div class="form-field">

                        <label for="reportType">
                            Report Type
                        </label>

                        <select id="reportType">

                            <option value="sales">
                                Sales
                            </option>

                            <option value="products">
                                Products
                            </option>

                            <option value="staff">
                                Staff
                            </option>

                            <option value="payments">
                                Payments
                            </option>

                            <option value="operations">
                                Operations
                            </option>

                        </select>

                    </div>


                    <div class="form-field">

                        <label for="reportStartDate">
                            Start Date
                        </label>

                        <input
                            id="reportStartDate"
                            type="date"
                        >

                    </div>


                    <div class="form-field">

                        <label for="reportEndDate">
                            End Date
                        </label>

                        <input
                            id="reportEndDate"
                            type="date"
                        >

                    </div>


                    <button
                        type="button"
                        id="generateReportBtn"
                        class="primary-button"
                    >
                        <i data-lucide="file-bar-chart"></i>
                        Generate Report
                    </button>

                </div>

            </article>


            <article class="admin-panel report-results-panel">

                <div
                    id="reportResults"
                    class="report-results"
                >
                    <div class="empty-state">

                        <i data-lucide="bar-chart-3"></i>

                        <h3>No report generated</h3>

                        <p>
                            Select your reporting period and report type
                            above.
                        </p>

                    </div>
                </div>

            </article>

        </section>


        <!-- =================================================
             SETTINGS
        ================================================== -->

        <section
            class="admin-section"
            id="adminSectionSettings"
            data-section="settings"
        >

            <div class="admin-section-header">

                <div>
                    <span class="admin-eyebrow">SYSTEM</span>
                    <h1>Settings</h1>
                    <p>Configure restaurant POS behaviour.</p>
                </div>

            </div>


            <div class="settings-grid">


                <!-- PRINTING -->

                <article class="admin-panel setting-card">

                    <div class="setting-card-icon">
                        <i data-lucide="printer"></i>
                    </div>

                    <div class="setting-card-content">

                        <h2>Direct Printing</h2>

                        <p>
                            Send orders directly to Kitchen and Bar
                            thermal printers.
                        </p>

                        <div class="toggle-container">

                            <span class="toggle-label">
                                Direct Printing
                            </span>

                            <div
                                id="directPrintingToggle"
                                class="toggle-switch"
                                role="button"
                                tabindex="0"
                                aria-label="Toggle direct printing"
                            ></div>

                            <span
                                id="directPrintingStatus"
                                class="toggle-status"
                            >
                                Disabled
                            </span>

                        </div>

                    </div>

                </article>


                <!-- DELIVERY -->

                <article class="admin-panel setting-card">

                    <div class="setting-card-icon">
                        <i data-lucide="truck"></i>
                    </div>

                    <div class="setting-card-content">

                        <h2>Home Delivery</h2>

                        <p>
                            Allow customers to request delivery through
                            the restaurant system.
                        </p>

                        <div class="toggle-container">

                            <span class="toggle-label">
                                Accept Delivery
                            </span>

                            <div
                                id="homeDeliveryToggle"
                                class="toggle-switch"
                                role="button"
                                tabindex="0"
                                aria-label="Toggle home delivery"
                            ></div>

                            <span
                                id="homeDeliveryStatus"
                                class="toggle-status"
                            >
                                Disabled
                            </span>

                        </div>

                    </div>

                </article>


            </div>

        </section>

    </main>

</div>


<!-- =========================================================
     MOBILE BOTTOM NAVIGATION
========================================================= -->

<nav class="admin-mobile-nav">

    <button
        type="button"
        class="admin-mobile-nav-item active"
        data-admin-section="dashboard"
    >
        <i data-lucide="layout-dashboard"></i>
        <span>Home</span>
    </button>

    <button
        type="button"
        class="admin-mobile-nav-item"
        data-admin-section="orders"
    >
        <i data-lucide="shopping-bag"></i>
        <span>Orders</span>
    </button>

    <button
        type="button"
        class="admin-mobile-nav-item"
        data-admin-section="menu"
    >
        <i data-lucide="utensils-crossed"></i>
        <span>Menu</span>
    </button>

    <button
        type="button"
        class="admin-mobile-nav-item"
        data-admin-section="inventory"
    >
        <i data-lucide="package"></i>
        <span>Stock</span>
    </button>

    <button
        type="button"
        class="admin-mobile-nav-item"
        data-admin-section="settings"
    >
        <i data-lucide="more-horizontal"></i>
        <span>More</span>
    </button>

</nav>


<footer class="footer admin-footer">

    <a
        href="#"
        class="footer-link"
        id="brainyteFooterLink"
    >
        <span class="brainyte-icon">
            B
        </span>

        <span>
            Powered by Brainyte
        </span>

    </a>

</footer>


<script type="module" src="../../assets/js/main.js"></script>
<script src="../../assets/js/brainyte-popup.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {

    /*
     * Initialise Lucide icons.
     */
    if (window.lucide) {
        lucide.createIcons();
    }


    /*
     * Admin section navigation.
     */
    const navItems = document.querySelectorAll(
        '[data-admin-section]'
    );

    const sections = document.querySelectorAll(
        '.admin-section'
    );

    function showAdminSection(sectionName) {

        sections.forEach(section => {
            section.classList.toggle(
                'active',
                section.dataset.section === sectionName
            );
        });

        navItems.forEach(item => {
            item.classList.toggle(
                'active',
                item.dataset.adminSection === sectionName
            );
        });

        /*
         * Close mobile sidebar after navigation.
         */
        const sidebar = document.getElementById('adminSidebar');

        if (sidebar) {
            sidebar.classList.remove('mobile-open');
        }

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }


    navItems.forEach(item => {

        item.addEventListener('click', () => {

            const section =
                item.dataset.adminSection;

            if (section) {
                showAdminSection(section);
            }

        });

    });


    /*
     * Dashboard shortcut buttons.
     */
    document.querySelectorAll(
        '[data-admin-section-link]'
    ).forEach(button => {

        button.addEventListener('click', () => {

            const section =
                button.dataset.adminSectionLink;

            if (section) {
                showAdminSection(section);
            }

        });

    });


    /*
     * Mobile sidebar.
     */
    const menuButton =
        document.getElementById('adminMobileMenuButton');

    const sidebar =
        document.getElementById('adminSidebar');

    if (menuButton && sidebar) {

        menuButton.addEventListener('click', () => {

            sidebar.classList.toggle(
                'mobile-open'
            );

        });

    }


    /*
     * Close sidebar when clicking outside it.
     */
    document.addEventListener('click', event => {

        if (!sidebar || !sidebar.classList.contains('mobile-open')) {
            return;
        }

        if (
            !sidebar.contains(event.target) &&
            !menuButton?.contains(event.target)
        ) {
            sidebar.classList.remove(
                'mobile-open'
            );
        }

    });


    /*
     * Dashboard refresh.
     */
    const refreshButton =
        document.getElementById('adminRefreshDashboard');

    if (refreshButton) {

        refreshButton.addEventListener(
            'click',
            async () => {

                refreshButton.classList.add(
                    'is-loading'
                );

                if (typeof loadAdminStats === 'function') {
                    await loadAdminStats();
                }

                if (typeof loadInventoryAlerts === 'function') {
                    await loadInventoryAlerts();
                }

                refreshButton.classList.remove(
                    'is-loading'
                );

            }
        );

    }

});
</script>

</body>
</html>
