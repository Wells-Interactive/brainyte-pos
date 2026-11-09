<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/utils.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10251b">
    <meta name="description" content="Brainyte POS subscription packages for small restaurants, medium restaurants, fast food chains and fine dining.">
    <title>Pricing | Brainyte Restaurant POS</title>
    <link rel="stylesheet" href="/assets/css/public-pages.css">
</head>
<body>
    <main class="public-shell">
        <nav class="public-nav" aria-label="Main navigation">
            <a class="public-brand" href="/index.php" aria-label="Brainyte POS home">
                <img src="/assets/images/logo.png" alt="Brainyte POS">
            </a>
            <div class="public-nav-links">
                <a href="/pricing.php" aria-current="page">Pricing</a>
                <a href="/contact.php">Contact</a>
                <a href="/index.php">Sign in</a>
            </div>
        </nav>

        <section class="public-content" aria-labelledby="pricingTitle">
            <header class="public-heading">
                <p class="public-eyebrow">Plans for every service style</p>
                <h1 id="pricingTitle">The right POS for your restaurant</h1>
                <p>Choose a Brainyte POS subscription package built around your restaurant’s size and the way your team operates.</p>
            </header>

            <div class="pricing-grid">
                <article class="price-card">
                    <p class="plan-label">For independent restaurants</p>
                    <h2>Small Restaurant</h2>
                    <p class="plan-scope">1 location · Up to 20 tables</p>
                    <p class="plan-price">₦1,200,000</p>
                    <p class="plan-price-caption">Subscription package</p>
                    <ul class="plan-features">
                        <li>Core point-of-sale features</li>
                        <li>Brainyte POS Mobile app</li>
                        <li>Inventory management</li>
                        <li>Staff management</li>
                        <li>Financial management</li>
                        <li>Initial support included for 6 months</li>
                       </ul>
                    <a class="plan-action" href="/contact.php?plan=small">Discuss this package</a>
                </article>

                <article class="price-card featured">
                    <p class="plan-label">For growing operations</p>
                    <h2>Medium Restaurant</h2>
                    <p class="plan-scope">1–3 locations</p>
                    <p class="plan-price">₦2,200,000</p>
                    <p class="plan-price-caption">Subscription package</p>
                    <ul class="plan-features">
                        <li>Core point-of-sale features</li>
                        <li>Designed for one or two locations</li>
                        <li>Order, sales and team workflows in one system</li>
                        <li>Order, sales and team workflows in one system</li>
                        <li>Contact us to scope your deployment</li>
                    </ul>
                    <a class="plan-action" href="/contact.php?plan=medium">Discuss this package</a>
                </article>

                <article class="price-card">
                    <p class="plan-label">For specialist concepts</p>
                    <h2>Fast Food Chains / Fine Dining</h2>
                    <p class="plan-scope">For fast food chains and fine dining operations</p>
                    <p class="plan-price">From ₦5,000,000</p>
                    <p class="plan-price-caption">Subscription package</p>
                    <ul class="plan-features">
                        <li>Core POS platform for your operation</li>
                        <li>Suitable for fast food and fine dining workflows</li>
                        <li>Deployment scope discussed with your team</li>
                        <li>Contact us for a tailored walkthrough</li>
                    </ul>
                    <a class="plan-action" href="/contact.php?plan=chains">Discuss this package</a>
                </article>
            </div>

            <p class="pricing-note">
                Subscription term, onboarding, and any location-specific requirements are confirmed with you before purchase.
                Contact us to discuss your setup and get a package walkthrough.
            </p>
        </section>

        <footer class="public-footer">
            <a href="/index.php">Sign in</a> · <a href="/contact.php">Contact Brainyte Nigeria</a>
        </footer>
    </main>
</body>
</html>
