<?php
declare(strict_types=1);
session_start();
$role = $_SESSION['user']['role'] ?? null;
if ($role !== 'kitchen') {
    header('Location: /index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Kitchen Board</title>
    <link rel="stylesheet" href="/assets/css/style.css" />
    <link rel="manifest" href="/manifest.webmanifest" />
</head>
<body>
    <header class="topbar">
        <div class="brand">Kitchen Board</div>
        <a class="button-link" href="/index.php">Home</a>
        <a class="button-link" href="/Login/logout.php">Logout</a>
    </header>

    <main class="page-grid">
        <section class="card full-width">
            <h1>Kitchen Orders</h1>
            <div id="kitchenFeed" class="feed-list"></div>
        </section>
    </main>

    <footer class="footer">
        <a href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer" class="footer-link">
            <span class="brainyte-icon" aria-hidden="true">B</span>
            <span>Powered by Brainyte</span>
        </a>
    </footer>

    <script type="module" src="/assets/js/kitchen.js"></script>
</body>
</html>
