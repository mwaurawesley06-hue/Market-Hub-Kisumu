<?php
declare(strict_types=1);

/**
 * Shared branding for the public pages. Public pages are static HTML, so this
 * helper is included by the small prelude at the top of each page (see
 * public-prelude.php), which starts the session, connects to the database, and
 * exposes $branding. If the database is unavailable the helpers fall back to
 * the MPIDS defaults so the public site still renders.
 */
function public_branding(): array
{
    static $branding = null;
    if ($branding !== null) {
        return $branding;
    }

    try {
        require_once __DIR__ . '/../config/database.php';
        $pdo = db();
        require_once __DIR__ . '/settings.php';
        $branding = site_branding($pdo);
    } catch (Throwable) {
        $branding = [
            'site_name_html' => 'MPIDS',
            'site_tagline_html' => 'Market Info Hub, Kisumu',
            'site_logo_url' => 'img/logo.png',
            'hero_image' => 'img/market-hero.jpg',
            'theme_css' => '',
        ];
    }
    return $branding;
}
