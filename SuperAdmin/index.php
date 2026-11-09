<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/utils.php';
require_setup_or_redirect();
session_start();

$role = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;
$username = (string)($_SESSION['username'] ?? $_SESSION['user']['name'] ?? '');
if ($role !== 'super_admin') {
    header('Location: /index.php');
    exit;
}
$csrfToken = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restaurant Management | Brainyte POS</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=2.1">
</head>
<body class="admin-page">
    <header class="admin-topbar">
        <div class="admin-topbar-left">
            <div class="admin-brand">
                <div class="admin-brand-icon">B</div>
                <div><strong>Brainyte POS</strong><span>Platform administration</span></div>
            </div>
        </div>
        <div class="admin-topbar-right">
            <div class="admin-user">
                <div class="admin-user-avatar"><?= safe_text(strtoupper(substr($username, 0, 1))) ?></div>
                <div class="admin-user-details"><strong><?= safe_text($username) ?></strong><span>Super Admin</span></div>
            </div>
            <a href="/Login/logout.php" class="admin-logout" aria-label="Logout">Logout</a>
        </div>
    </header>
    <div class="admin-layout">
        <aside class="admin-sidebar">
            <div class="admin-sidebar-header"><span>PLATFORM</span></div>
            <nav class="admin-navigation">
                <a class="admin-nav-item admin-nav-link active" href="/SuperAdmin/index.php">Restaurants</a>
            </nav>
            <div class="admin-sidebar-footer">
                <div class="admin-powered"><div class="admin-powered-icon">B</div><div><strong>Brainyte</strong><span>Restaurant POS</span></div></div>
            </div>
        </aside>
        <main class="admin-main-content">
            <div class="admin-section-header">
                <div><span class="admin-eyebrow">PLATFORM</span><h1>Restaurants</h1><p>Create branches, group them into chains, and control restaurant access.</p></div>
            </div>
            <section class="admin-panel">
                <div class="admin-panel-header"><div><h2>Add a restaurant</h2><p>Each restaurant gets an owner login. Enter an existing chain name to add a branch to that chain.</p></div></div>
                <form id="restaurantForm" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= safe_text($csrfToken) ?>">
                    <div class="admin-management-grid">
                        <label class="form-field">Restaurant name<input name="name" required maxlength="150"></label>
                        <label class="form-field">Chain name (optional)<input name="chain_name" maxlength="150" placeholder="Use the same name to link branches"></label>
                        <label class="form-field">Restaurant email<input name="email" type="email" maxlength="120"></label>
                        <label class="form-field">Phone number<input name="phone_number" maxlength="30"></label>
                        <label class="form-field">Address<input name="address" maxlength="1000"></label>
                    </div>
                    <h3>Restaurant owner account</h3>
                    <div class="admin-management-grid">
                        <label class="form-field">Owner name<input name="owner_name" required maxlength="80"></label>
                        <label class="form-field">Owner email<input name="owner_email" type="email" required maxlength="120"></label>
                        <label class="form-field">Temporary password<input name="owner_password" type="password" required minlength="8" autocomplete="new-password"></label>
                    </div>
                    <button class="primary-button" type="submit">Add restaurant</button>
                    <p id="restaurantMessage" class="form-status" role="status" aria-live="polite"></p>
                </form>
            </section>
            <section class="admin-panel">
                <div class="admin-panel-header"><div><h2>Restaurant directory</h2><p>Inactive restaurants cannot sign in. Archiving retains their records.</p></div><button id="refreshRestaurants" class="secondary-button" type="button">Refresh</button></div>
                <label class="form-field" for="restaurantSearch">Search restaurants</label>
                <input id="restaurantSearch" type="search" maxlength="120" placeholder="Name, branch, chain, email, phone, or address" autocomplete="off">
                <div id="restaurantList" aria-live="polite">Loading restaurants...</div>
            </section>
        </main>
    </div>
    <script type="module" src="/assets/js/super_admin.js"></script>
</body>
</html>
