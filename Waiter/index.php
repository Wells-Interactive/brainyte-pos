<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/utils.php';
session_start();
$role = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;
$username = $_SESSION['username'] ?? $_SESSION['user']['name'] ?? '';
if ($role !== 'waiter') {
    header('Location: /index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Waiter Dashboard</title>
    <link rel="stylesheet" href="/assets/css/style.css" />
    <link rel="manifest" href="/manifest.webmanifest" />
</head>
<body>
    <header class="topbar">
        <div class="brand">Waiter Dashboard</div>
        <a class="button-link" href="/index.php">Home</a>
        <a class="button-link" href="/Login/logout.php">Logout</a>
    </header>

    <main class="page-grid">
        <section class="card full-width">
            <h1>Waiter Order Entry</h1>
            <div class="split-panel">
                <div class="panel">
                    <h2>Table Selection</h2>
                    <div id="tableGrid" class="table-grid"></div>
                    <div id="selectedTableName" class="info-box">Tap a table to open the order screen</div>
                </div>
                <div class="panel">
                    <h2>Order Summary</h2>
                    <div id="orderSummary" class="order-summary"></div>
                    <div class="summary-totals">
                        <div><span>Subtotal</span><strong id="subtotalAmount">₦0.00</strong></div>
                        <div><span>VAT (0.0%)</span><strong id="vatAmount">₦0.00</strong></div>
                        <div><span>Grand Total</span><strong id="grandTotalAmount">₦0.00</strong></div>
                    </div>
                </div>
            </div>
            <div class="menu-tabs">
                <button class="tab-button" data-category="beer">Beer</button>
                <button class="tab-button" data-category="soft-drinks">Soft Drinks</button>
                <button class="tab-button" data-category="malt">Malts</button>
                <button class="tab-button" data-category="pepper-soup">Pepper Soup</button>
                <button class="tab-button" data-category="grills">Grills</button>
                <button class="tab-button" data-category="soups">Soups</button>
                <button class="tab-button" data-category="swallow">Swallow</button>
                <button class="tab-button active" data-category="water">Water</button>
                <button class="tab-button" data-category="rice">Rice</button>
                <button class="tab-button" data-category="extras">Extras</button>  
                <button class="tab-button" data-category="energy-drinks">Energy Drinks</button>
                <button class="tab-button" data-category="juice">Juice</button>
                <button class="tab-button" data-category="spirits">Spirits</button>
                <button class="tab-button" data-category="ready-to-drink">Ready To Drink</button>
                <button class="tab-button" data-category="cigarettes">Cigarettes</button>
            </div>
            <div id="menuList" class="menu-list"></div>
            <div class="order-controls">
                <label for="instructions">Special Instructions</label>
                <textarea id="instructions" rows="3" placeholder="No pepper, extra onions, serve chilled"></textarea>
                <label for="paymentMethod">Payment Method</label>
                <select id="paymentMethod" name="paymentMethod">
                    <option value="pending">Pending</option>
                    <option value="cash">Cash</option>
                    <option value="pos">POS</option>
                    <option value="transfer">Transfer</option>
                </select>
                <div class="confirmation-actions">
                    <button id="sendOrderButton" class="primary-button" disabled>Send Order</button>
                    <button id="markPaidButton" class="secondary-button" type="button" disabled>Mark Table as Paid</button>
                </div>
                <div id="orderFeedback" class="message"></div>
            </div>
        </section>
    </main>

    <div id="orderConfirmation" class="modal hidden">
        <div class="modal-content">
            <h2>Confirm Order</h2>
            <div id="confirmationDetails" class="confirmation-details"></div>
            <div class="confirmation-actions">
                <button id="cancelConfirmation" type="button" class="secondary-button">Cancel</button>
                <button id="confirmOrderButton" type="button" class="primary-button">Confirm Order</button>
            </div>
        </div>
    </div>

    <footer class="footer">
        <a href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer" class="footer-link">
            <span class="brainyte-icon" aria-hidden="true">B</span>
            <span>Powered by Brainyte</span>
        </a>
    </footer>

    <script type="module" src="/assets/js/waiter.js"></script>
</body>
</html>
