<?php
declare(strict_types=1);

/**
 * Server-side branding helper for page shells. Reads the whitelisted keys from
 * site_settings (60s file cache, same as other hot reads) and exposes them as
 * HTML-safe values plus CSS custom properties. Falls back to the shipped
 * defaults whenever the table or database is unavailable, so a broken settings
 * row can never take the site down.
 */

// cache_get()/cache_set() are used below but only api/index.php required this
// file, so the page shells called undefined functions. That threw an Error
// which the catch blocks turned into the shipped defaults, silently undoing
// every saved setting (including an uploaded logo) on server-rendered pages.
require_once __DIR__ . '/cache.php';

function site_branding(PDO $pdo): array
{
    static $branding = null;
    if ($branding !== null) {
        return $branding;
    }

    $cached = null;
    try {
        $cached = cache_get('site_settings');
    } catch (Throwable) {
        $cached = null;
    }
    if (is_array($cached) && isset($cached['site_name'])) {
        $branding = $cached;
    } else {
        try {
            require_once __DIR__ . '/../handlers/site-settings.php';
            // read_site_settings() always includes site_logo (img/logo.png default).
            $branding = read_site_settings($pdo);
        } catch (Throwable) {
            $branding = [
                'site_name' => 'MPIDS',
                'site_tagline' => 'Market Info Hub, Kisumu',
                'theme_primary' => '#0b6e4f',
                'theme_secondary' => '#f2b445',
                'hero_image' => 'img/market-hero.jpg',
                'site_logo' => 'img/logo.png',
            ];
        }
        // Deliberately outside the try above: a failed cache write must never
        // discard branding that was already read successfully.
        try {
            cache_set('site_settings', $branding);
        } catch (Throwable) {
            // Caching is an optimisation; the page still renders correctly.
        }
    }

    $branding['site_name_html'] = htmlspecialchars((string) ($branding['site_name'] ?? 'MPIDS'), ENT_QUOTES, 'UTF-8');
    $branding['site_tagline_html'] = htmlspecialchars((string) ($branding['site_tagline'] ?? 'Market Info Hub, Kisumu'), ENT_QUOTES, 'UTF-8');
    // Cache-busted so a re-upload (which overwrites the same filename) is not
    // masked by the browser's cached copy. Same helper the API response uses.
    $logoUrl = (string) ($branding['site_logo_url'] ?? '');
    if ($logoUrl === '' && function_exists('site_asset_url')) {
        $logoUrl = site_asset_url((string) ($branding['site_logo'] ?? 'img/logo.png'));
    }
    if ($logoUrl === '') {
        $logoUrl = (string) ($branding['site_logo'] ?? 'img/logo.png');
    }
    $branding['site_logo_url'] = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $branding['theme_css'] = sprintf(
        ':root{--green:%1$s;--brand-accent:%2$s;--gold:%2$s;}',
        htmlspecialchars((string) ($branding['theme_primary'] ?? '#0b6e4f'), ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) ($branding['theme_secondary'] ?? '#f2b445'), ENT_QUOTES, 'UTF-8')
    );
    // PWA / public-assets discovery. read_site_settings() already whitelists and
    // defaults these keys; make them available to the page shells under the names
    // the public HTML already expects.
    $branding['favicon'] = (string) ($branding['site_favicon'] ?? 'img/icons/favicon.ico');
    $branding['manifest'] = (string) ($branding['site_manifest'] ?? 'pwa/manifest.json');
    $branding['sw'] = (string) ($branding['site_service_worker'] ?? 'service-worker.js');
    return $branding;
}
