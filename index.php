<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/utils.php';
session_start();

if (!db_config_exists()) {
    header('Location: /Setup/index.php');
    exit;
}

$restaurantName = 'Brainyte POS';
$databaseError = '';
try {
    $pdo = get_db();
    if ((int)$pdo->query('SELECT 1')->fetchColumn() !== 1) {
        throw new RuntimeException('Database health check returned an unexpected result.');
    }

    if (!is_setup_complete($pdo)) {
        header('Location: /Setup/index.php');
        exit;
    }

    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'restaurant_name' LIMIT 1");
    $setting = $stmt->fetch();
    if (!empty($setting['setting_value'])) {
        $restaurantName = (string) $setting['setting_value'];
    }
} catch (Throwable $e) {
    error_log('Landing page database validation failed: ' . $e->getMessage());
    $databaseError = 'We’re having trouble connecting to the restaurant system right now. Please try again shortly or contact support.';
}

$loggedIn = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? null;

// Preserve the existing role-based landing pages.
$roleDestinations = [
    'waiter' => '/Waiter/index.php',
    'kitchen' => '/Kitchen/index.php',
    'bar' => '/Bar/index.php',
    'manager' => '/Manager/index.php',
    'supervisor' => '/Manager/index.php',
    'admin' => '/Admin/Menu/index.php',
    'owner' => '/Admin/Menu/index.php',
    'super_admin' => '/SuperAdmin/index.php',
];
if ($databaseError === '' && is_string($loggedIn) && isset($roleDestinations[$loggedIn])) {
    header('Location: ' . $roleDestinations[$loggedIn]);
    exit;
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= safe_text($restaurantName) ?> | Sign In</title>
    <meta name="theme-color" content="#10251b">
    <meta name="description" content="Sign in to Brainyte POS restaurant management.">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="preload" as="image" href="assets/images/restaurant-background.webp">
    <style>
        :root{--brand:#08b76a;--deep:#07140f;--ink:#10251b;--muted:#66716b}
        *{box-sizing:border-box}
        html,body{min-height:100%;margin:0}
        body{font-family:Inter,"Segoe UI",Arial,sans-serif;color:#fff;background:#101b16}
        .login-shell{min-height:100vh;min-height:100svh;position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(390px,.9fr);align-items:center;gap:clamp(22px,3vw,48px);padding:clamp(18px,2.5vw,38px);overflow:hidden}
        .login-shell:before{content:"";position:absolute;inset:0;z-index:-2;background:url('assets/images/restaurant-background.webp') center center/cover no-repeat;transform:scale(1.015)}
        .login-shell:after{content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(90deg,rgba(4,13,9,.84) 0%,rgba(5,16,11,.66) 47%,rgba(5,14,10,.52) 100%)}
        .brand-panel{max-width:650px;padding:0 0 12px}
        .brand-logo{display:block;width:min(240px,70%);max-height:82px;object-fit:contain;object-position:left center;margin-bottom:18px;filter:drop-shadow(0 5px 16px rgba(0,0,0,.22))}
        .brand-eyebrow{font-size:.78rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#75e4ad;margin:0 0 15px}
        .brand-panel h1{font-size:clamp(2.25rem,4.2vw,4.3rem);line-height:1.06;letter-spacing:-.045em;margin:0 0 20px;font-weight:750;max-width:620px}
        .brand-panel h1 span{color:#24d487}
        .brand-copy{max-width:540px;font-size:clamp(1rem,1.3vw,1.18rem);line-height:1.75;color:rgba(255,255,255,.84);margin:0}
        .feature-row{display:flex;flex-wrap:wrap;gap:12px 26px;margin-top:24px;color:#fff;font-size:.93rem}
        .feature-row span:before{content:"✓";display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:rgba(8,183,106,.2);color:#55e5a0;font-weight:800;margin-right:9px}

        .demo-callout{position:relative;isolation:isolate;display:inline-flex;align-items:center;gap:15px;margin-top:34px;padding:17px 30px 18px 23px;min-height:66px;color:#fff;text-decoration:none;font-weight:750;letter-spacing:.01em;filter:drop-shadow(0 9px 18px rgba(0,0,0,.2));transition:transform .2s ease,filter .2s ease}
        .demo-callout:before{content:"";position:absolute;inset:0 -12px 0 -8px;z-index:-1;background:linear-gradient(105deg,#078d52 0%,#08b76a 48%,#20d486 100%);clip-path:polygon(2% 18%,10% 8%,24% 12%,37% 3%,54% 10%,69% 4%,83% 12%,98% 7%,95% 29%,100% 48%,96% 70%,99% 88%,82% 84%,67% 96%,49% 88%,34% 98%,18% 88%,3% 94%,7% 69%,0 51%,5% 34%)}
        .demo-callout:after{content:"";position:absolute;z-index:-1;left:8px;right:1px;bottom:5px;height:5px;background:rgba(112,255,184,.55);transform:rotate(-2deg);filter:blur(1px);clip-path:polygon(0 40%,18% 0,43% 55%,67% 8%,100% 35%,94% 100%,65% 65%,40% 100%,15% 60%)}
        .demo-callout:hover{transform:translateY(-2px);filter:drop-shadow(0 12px 22px rgba(0,0,0,.28))}
        .demo-callout .demo-label{display:block;font-size:.7rem;line-height:1.2;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.82);margin-bottom:4px}
        .demo-callout .demo-number{display:block;font-size:clamp(1.05rem,1.6vw,1.3rem);line-height:1.2;color:#fff}
        .demo-callout .demo-icon{display:grid;place-items:center;width:42px;height:42px;flex:0 0 42px;border:1px solid rgba(255,255,255,.4);border-radius:50%;background:rgba(0,0,0,.12);font-size:1.25rem}
        .public-nav{position:absolute;top:24px;right:clamp(28px,6vw,88px);z-index:1;display:flex;gap:10px}
        .public-nav a{padding:10px 15px;border:1px solid rgba(255,255,255,.3);border-radius:999px;background:rgba(8,20,14,.36);color:#fff;text-decoration:none;font-size:.9rem;font-weight:650;backdrop-filter:blur(8px)}
        .public-nav a:hover{background:rgba(8,183,106,.82);border-color:rgba(255,255,255,.58)}
        .powered{position:absolute;left:clamp(28px,6vw,88px);bottom:28px;color:rgba(255,255,255,.78);font-size:.86rem;letter-spacing:.02em;text-decoration:none}
        .powered:hover{text-decoration:underline;text-underline-offset:3px}
        .powered strong{color:#43dc91;font-weight:700}
        .login-card{width:100%;max-width:510px;justify-self:end;background:rgba(255,255,255,.94);color:var(--ink);border:1px solid rgba(255,255,255,.65);border-radius:24px;padding:clamp(26px,3vw,40px);box-shadow:0 28px 90px rgba(0,0,0,.32);backdrop-filter:blur(18px)}
        .login-card h2{text-align:center;font-size:clamp(1.9rem,3vw,2.45rem);letter-spacing:-.04em;margin:0 0 9px;color:#102b1e}
        .login-subtitle{text-align:center;color:#69736e;margin:0 0 32px;font-size:.98rem}
        .field{margin-bottom:19px}
        .field label{display:block;font-size:.86rem;font-weight:650;color:#35453c;margin:0 0 8px}
        .field input{width:100%;height:56px;border:1px solid #d9e0dc;border-radius:12px;background:#fff;padding:0 16px;font:inherit;color:#17271e;outline:none;transition:border-color .2s,box-shadow .2s}
        .field input:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(8,183,106,.12)}
        .password-wrap{position:relative}.password-wrap input{padding-right:52px}
        .toggle-password{position:absolute;right:8px;top:8px;width:40px;height:40px;border:0;background:transparent;color:#647169;border-radius:8px;cursor:pointer;font-size:1.05rem}
        .login-options{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:5px 0 25px;font-size:.88rem}
        .remember{display:flex;align-items:center;gap:8px;color:#35453c}.remember input{accent-color:var(--brand);width:17px;height:17px}
        .forgot{color:#079b5a;text-decoration:none;font-weight:650}.forgot:hover{text-decoration:underline}
        .sign-in{width:100%;height:58px;border:0;border-radius:13px;background:linear-gradient(100deg,#08a95f,#10c878);color:#fff;font-size:1.05rem;font-weight:750;cursor:pointer;box-shadow:0 9px 22px rgba(8,183,106,.22);transition:transform .2s,filter .2s}
        .sign-in:hover{filter:brightness(1.04);transform:translateY(-1px)}.sign-in:disabled{opacity:.65;cursor:wait;transform:none}
        .customer-signup{display:block;margin:12px auto 0;color:#079b5a;text-decoration:none;font-size:.9rem;font-weight:700;text-align:center}
        .customer-signup:hover{text-decoration:underline}
        .message{min-height:20px;margin-top:14px;text-align:center;color:#b42318;font-size:.9rem}
        .database-alert{margin:0 0 20px;padding:14px 16px;border:1px solid #f1b6b2;border-radius:12px;background:#fff2f1;color:#8f211b;font-size:.92rem;line-height:1.5}
        @media(max-width:900px){.login-shell{grid-template-columns:1fr;gap:16px;padding:70px 22px 48px;align-content:center}.public-nav{top:18px;right:22px}.brand-panel{max-width:620px;padding:0;margin:0 auto;text-align:center}.brand-logo{object-position:center;margin:0 auto 12px;width:min(180px,58%)}.brand-eyebrow{margin-bottom:7px}.brand-panel h1{font-size:clamp(2rem,7vw,3rem);margin:0 auto 8px}.brand-copy{font-size:.95rem;margin:auto}.feature-row{justify-content:center;margin-top:10px;font-size:.82rem;gap:7px 16px}.login-card{justify-self:center;max-width:500px}.powered{left:0;right:0;bottom:12px;text-align:center}}
        @media(max-width:480px){.login-shell{padding:60px 16px 40px;gap:10px}.public-nav{top:10px;right:14px;gap:6px}.public-nav a{padding:7px 11px;font-size:.78rem}.brand-logo{width:112px;max-height:52px;margin-bottom:8px}.brand-eyebrow{font-size:.62rem;margin-bottom:5px}.brand-panel h1{font-size:1.7rem;margin-bottom:6px}.brand-copy{font-size:.81rem;line-height:1.35}.feature-row{display:none}.login-card{padding:18px 18px;border-radius:17px}.login-card h2{font-size:1.55rem}.login-subtitle{font-size:.83rem;margin-bottom:14px}.field{margin-bottom:11px}.field input{height:45px}.login-options{font-size:.78rem;margin:4px 0 14px}.sign-in{height:47px}.customer-signup{margin-top:7px}}

        @media(max-width:900px){.demo-callout{margin-top:23px}}
        @media(max-width:480px){.demo-callout{margin-top:18px;padding:13px 22px 14px 17px;min-height:58px;gap:11px}.demo-callout .demo-icon{width:36px;height:36px;flex-basis:36px}.demo-callout .demo-number{font-size:1rem}.demo-callout .demo-label{font-size:.62rem}}
        @media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}
    </style>
</head>
<body>
    <main class="login-shell">
        <nav class="public-nav" aria-label="Public pages">
            <a href="/pricing.php">Pricing</a>
            <a href="/contact.php">Contact</a>
        </nav>
        <section class="brand-panel" aria-label="About Brainyte POS">
            <img class="brand-logo" src="assets/images/logo.png" alt="Brainyte POS" onerror="this.style.display='none'">
            <p class="brand-eyebrow">Restaurant management, reimagined</p>
            <h1>Run your restaurant.<br><span>Beautifully.</span></h1>
            <p class="brand-copy">Manage orders, track sales, monitor inventory and coordinate your team — all from one intelligent restaurant platform.</p>
            <div class="feature-row" aria-label="Platform features">
                <span>Manage orders</span><span>Track sales</span><span>Monitor inventory</span><span>Coordinate your team</span>
            </div>
            <a class="demo-callout" href="tel:+2348067519239" aria-label="Call +234 806 751 9239 for demo account details">
                <span class="demo-icon" aria-hidden="true">☎</span>
                <span>
                    <span class="demo-label">Need demo account details? Call</span>
                    <span class="demo-number">+234-806-751-9239</span>
                </span>
            </a>
        </section>

        <section class="login-card" aria-labelledby="loginHeading">
            <h2 id="loginHeading">Welcome Back</h2>
            <p class="login-subtitle">Sign in to access your restaurant workspace.</p>
            <?php if ($databaseError !== ''): ?>
                <div class="database-alert" role="alert"><?= safe_text($databaseError) ?></div>
            <?php endif; ?>
            <form id="loginForm" class="form-grid" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= safe_text($csrf_token) ?>">
                <fieldset style="border:0;padding:0;margin:0;min-width:0">
                <div class="field">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" autocomplete="username" placeholder="you@example.com" required>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="password-wrap">
                        <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                        <button class="toggle-password" type="button" id="togglePassword" aria-label="Show password" title="Show password">◉</button>
                    </div>
                </div>
                <div class="login-options">
                    <label class="remember"><input type="checkbox" name="remember" value="1"> Remember me</label>
                </div>
                <button class="sign-in" type="submit" id="loginSubmit">Sign In&nbsp; →</button>
                <a class="customer-signup" href="/Signup/index.php">Sign up as a customer</a>
                </fieldset>
            </form>
            <div id="loginMessage" class="message" role="status" aria-live="polite"></div>
        </section>
        <a class="powered" href="https://linktr.ee/wellsinteractive" target="_blank" rel="noopener noreferrer">Powered by <strong>Brainyte Nigeria</strong></a>
    </main>

    <!-- Keep the existing application login handler. -->
    <script type="module" src="assets/js/main.js?v=2.1"></script>
    <script src="assets/js/brainyte-popup.js"></script>
    <script>
      document.getElementById('togglePassword')?.addEventListener('click', function () {
        const input = document.getElementById('password');
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        this.title = showing ? 'Show password' : 'Hide password';
      });
    </script>
</body>
</html>
