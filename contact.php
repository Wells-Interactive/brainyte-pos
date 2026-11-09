<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/utils.php';

$planNames = [
    'small' => 'Small Restaurant',
    'medium' => 'Medium Restaurant',
    'chains' => 'Fast Food Chains / Fine Dining',
];
$requestedPlan = $planNames[(string)($_GET['plan'] ?? '')] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10251b">
    <meta name="description" content="Contact Brainyte about restaurant POS subscriptions, deployment and product demonstrations.">
    <title>Contact | Brainyte POS</title>
    <link rel="stylesheet" href="/assets/css/public-pages.css">
</head>
<body>
    <main class="public-shell">
        <nav class="public-nav" aria-label="Main navigation">
            <a class="public-brand" href="/index.php" aria-label="Brainyte POS home">
                <img src="/assets/images/logo.png" alt="Brainyte POS">
            </a>
            <div class="public-nav-links">
                <a href="/pricing.php">Pricing</a>
                <a href="/contact.php" aria-current="page">Contact</a>
                <a href="/index.php">Sign in</a>
            </div>
        </nav>

        <section class="public-content" aria-labelledby="contactTitle">
            <header class="public-heading">
                <p class="public-eyebrow">We’re ready to help</p>
                <h1 id="contactTitle">Let’s talk about your restaurant</h1>
                <p>Ask us about Brainyte POS, subscription options, implementation, or arranging a product walkthrough.</p>
            </header>

            <div class="contact-card">
                <div>
                    <h2>Tell us what you need</h2>
                    <?php if ($requestedPlan !== ''): ?>
                        <p>You’re enquiring about the <strong><?= safe_text($requestedPlan) ?></strong> package. Contact our team and we’ll help scope the next steps.</p>
                    <?php else: ?>
                        <p>Share your restaurant type and the number of locations you operate. Our team can help identify a suitable package and discuss subscription terms and deployment requirements.</p>
                    <?php endif; ?>
                    <a class="contact-cta" href="mailto:wellsintltd@gmail.com?subject=Brainyte%20Restaurant%20POS%20enquiry">Email Brainyte</a>
                </div>

                <div class="contact-details" aria-label="Contact details">
                    <div class="contact-detail">
                        <span>Email</span>
                        <a href="mailto:wellsintltd@gmail.com">wellsintltd@gmail.com</a>
                    </div>
                    <div class="contact-detail">
                        <span>Phone</span>
                        <a href="tel:+2348067519239">+234 806 751 9239</a>
                    </div>
                    <div class="contact-detail">
                        <span>More about Brainyte</span>
                        <a href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer">Brainyte Nigeria</a>
                    </div>
                </div>
            </div>
        </section>

        <footer class="public-footer">
            <a href="/pricing.php">View subscription packages</a> · <a href="/index.php">Sign in</a>
        </footer>
    </main>
</body>
</html>
