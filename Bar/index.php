<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/utils.php';
session_start();
$role = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;
$username = $_SESSION['username'] ?? $_SESSION['user']['name'] ?? '';
if ($role !== 'bar') {
    header('Location: /index.php');
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Bar Board</title>
<link rel="stylesheet" href="/assets/css/style.css?v=2.0" />
<link rel="manifest" href="/manifest.webmanifest" />
</head>
<body>
<header class="topbar">
<div class="brand">Bar Board</div>
<a class="button-link" href="/index.php">Home</a>
<a class="button-link" href="/Login/logout.php">Logout</a>
</header>
<main class="page-grid">
<section class="card full-width">
<h1>Bar Orders</h1>
<div class="kanban-controls">
<button id="printAllBar" type="button" class="secondary-button">Print All Bar Orders</button>
<span id="lastUpdated" class="last-updated"></span>
</div>

<div class="kanban-board" id="barKanban">
  <div class="kanban-column" data-status="pending">
    <div class="kanban-header"><h2>NEW</h2><span class="kanban-count" id="count-pending">0</span></div>
    <div class="kanban-items" id="kanban-pending"></div>
  <div class="kanban-column" data-status="preparing">
    <div class="kanban-header"><h2>PREPARING</h2><span class="kanban-count" id="count-preparing">0</span></div>
    <div class="kanban-items" id="kanban-preparing"></div>
  <div class="kanban-column" data-status="ready">
    <div class="kanban-header"><h2>READY</h2><span class="kanban-count" id="count-ready">0</span></div>
    <div class="kanban-items" id="kanban-ready"></div>
</div>

<div id="barFeed" class="feed-list" style="display:none;"></div>
</section>
</main>
<footer class="footer">
<a href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer" class="footer-link">
<span class="brainyte-icon" aria-hidden="true">B</span><span>Powered by Brainyte</span>
</a>
</footer>
<script type="module" src="/assets/js/bar.js?v=2.0"></script>
</body>
</html>
