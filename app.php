<?php
declare(strict_types=1);

require_once __DIR__ . '/api/includes/page-auth.php';
require_once __DIR__ . '/api/config/database.php';
require_once __DIR__ . '/api/includes/settings.php';


$view = strtolower(clean_string($_GET['view'] ?? 'dashboard', 40));

// Views that ship as standalone public .html files (they call public API
// endpoints only, so no auth gate or server-rendered shell applies). Served
// here so direct app.php?view=... hits behave like the rewritten URLs.
$standalonePages = ['ussd', 'subscriptions', 'public-dashboard', 'subscribe'];
if (in_array($view, $standalonePages, true) && is_file(__DIR__ . '/' . $view . '.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/' . $view . '.html');
    exit;
}

$views = [
    'dashboard' => 'Dashboard',
    'capture' => 'Capture price',
    'approval' => 'Approval queue',
    'records' => 'Price records',
    'reports' => 'Reports',
    'commodities' => 'Commodities',
    'markets' => 'Markets',
    'subscribers' => 'SMS subscribers',
    'users' => 'Super Admins',
    'audit-logs' => 'Audit trail',
    'settings' => 'Settings',
];
if (!isset($views[$view])) {
    $view = 'dashboard';
}
$pageTitle = $views[$view];
$pageScript = $view === 'capture' ? 'capture-price.js' : ($view . '.js');
$assetBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
$user = null;
try {
    $pdo = db();
    $user = current_user($pdo);
} catch (Throwable $exception) {
    http_response_code(503);
    $pageTitle = 'Service unavailable';
}
if (!$user && http_response_code() !== 503) {
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    header('Location: ' . $base . 'login');
    exit;
}
$displayName = $user['full_name'] ?? 'Administrator';
$initials = strtoupper(substr((string) ($user['full_name'] ?? 'MP'), 0, 1));
$branding = $user ? site_branding($pdo) : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0b6e4f">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> · <?= $branding['site_name_html'] ?? 'MPIDS' ?></title>
    <?php if ($branding): ?><style><?= $branding['theme_css'] ?></style><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/responsive.css">
    <script src="js/image-upload.js"></script>
</head>
<body class="admin-shell" data-page="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="app-frame">
    <aside class="sidebar" id="sidebar">
        <div class="brand-lockup">
            <div class="brand-mark"><img src="<?= $branding['site_logo_url'] ?? 'img/logo.png' ?>" alt="<?= $branding['site_name_html'] ?? 'MPIDS' ?> logo"></div>
            <div>
                <strong><?= $branding['site_name_html'] ?? 'MPIDS' ?></strong>
                <span><?= $branding['site_tagline_html'] ?? 'Market Info Hub' ?></span>
            </div>
        </div>
        <div class="sidebar-location"><i class="fa-solid fa-location-dot"></i><span>Kisumu, Kenya</span></div>
        <nav class="primary-nav" aria-label="Primary navigation">
            <a data-route="dashboard" href="dashboard"><i class="fa-solid fa-grid-2"></i><span>Overview</span></a>
            <a data-route="capture" href="capture"><i class="fa-solid fa-plus-circle"></i><span>Capture price</span></a>
            <a data-route="approval" href="approval"><i class="fa-solid fa-clipboard-check"></i><span>Approval queue</span></a>
            <a data-route="records" href="records"><i class="fa-solid fa-list-check"></i><span>Price records</span></a>
            <div class="nav-section-label">Manage</div>
            <a data-route="commodities" href="commodities"><i class="fa-solid fa-wheat-awn"></i><span>Commodities</span></a>
            <a data-route="markets" href="markets"><i class="fa-solid fa-store"></i><span>Markets</span></a>
            <a data-route="subscribers" href="subscribers"><i class="fa-solid fa-users-viewfinder"></i><span>SMS subscribers</span></a>
            <a data-route="reports" href="reports"><i class="fa-solid fa-chart-column"></i><span>Reports</span></a>
            <div class="nav-section-label">Channels</div>
            <a href="subscriptions"><i class="fa-solid fa-bell"></i><span>SMS subscriptions</span></a>
            <a href="ussd"><i class="fa-solid fa-phone"></i><span>USSD simulator</span></a>
            <a href="public-dashboard"><i class="fa-solid fa-globe"></i><span>Public dashboard</span></a>
            <div class="nav-section-label">Administration</div>
            <a data-route="users" href="users"><i class="fa-solid fa-users-gear"></i><span>Super Admins</span></a>
            <a data-route="audit-logs" href="audit-logs"><i class="fa-solid fa-shield-halved"></i><span>Audit trail</span></a>
            <a data-route="settings" href="settings"><i class="fa-solid fa-sliders"></i><span>Settings</span></a>
        </nav>
        <div class="sidebar-footer">
            <div class="user-chip">
                <div class="avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="user-chip-copy"><strong><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></strong><span>Super Admin</span></div>
            </div>
            <button class="icon-button sidebar-close" type="button" data-sidebar-toggle aria-label="Close navigation"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-toggle></div>
    <div class="main-column">
        <header class="topbar">
            <div class="topbar-leading">
                <button class="icon-button menu-toggle" type="button" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation"><i class="fa-solid fa-bars"></i></button>
                <div class="breadcrumb"><span>Market Info Hub</span><i class="fa-solid fa-chevron-right"></i><strong><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
            <div class="topbar-actions">
                <a class="topbar-public-link" href="public-dashboard"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>View public site</span></a>
                <button class="icon-button" type="button" data-logout aria-label="Log out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
            </div>
        </header>
        <main class="page-content" id="main-content">
            <?php if ($user): ?>
                <?php require __DIR__ . '/api/views/' . $view . '.php'; ?>
            <?php else: ?>
                <section class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><h1>Database unavailable</h1><p>Import <code>database/schema.sql</code> and check the MySQL connection in <code>.env</code>.</p></section>
            <?php endif; ?>
        </main>
    </div>
</div>
<div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="true"></div>
<script src="js/api.js"></script>
<script src="js/auth.js"></script>
<script src="js/app.js"></script>
<?php if ($user && is_file(__DIR__ . '/js/' . $pageScript)): ?><script src="js/<?= htmlspecialchars($pageScript, ENT_QUOTES, 'UTF-8') ?>"></script><?php endif; ?>
</body>
</html>
