<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in web server — the runtime Render (and `php -S`
 * locally) uses. Mirrors the Apache .htaccess rules:
 *
 *   /api/*            -> api/index.php front controller
 *   /login            -> login.html
 *   existing files    -> served as-is
 *   anything else     -> app.php front controller (admin shell)
 *
 * Start command:  php -S 0.0.0.0:$PORT router.php
 */

$uri = urldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));

// Handlers, config and includes are libraries, never entry points.
if (preg_match('/\.php$/', $uri) && !in_array(basename($uri), ['index.php', 'app.php', 'router.php'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}

// API front controller.
if ($uri === '/api' || strpos($uri, '/api/') === 0) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require __DIR__ . '/api/index.php';
    return true;
}

// Standalone sign-in page (same exemption as the Apache rewrite).
if (preg_match('#^/login/?$#', $uri)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/login.html');
    return true;
}

// Existing files and directories fall through to the server's static handler.
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// Directory index for the site root.
if ($uri === '/' || is_dir(__DIR__ . $uri)) {
    $index = rtrim($uri, '/') . '/index.html';
    if ($uri === '/') {
        $index = '/index.html';
    }
    if (is_file(__DIR__ . $index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . $index);
        return true;
    }
    if ($uri === '/') {
        return false;
    }
}

// Extensionless admin/public pages route through app.php, like Apache's
// `RewriteRule ^(.+)$ app.php?view=$1`. The built-in server never rewrites
// the query string, so derive `view` from the path here — without it every
// admin URL would fall back to the default dashboard view.
$segment = ltrim(rawurldecode($uri), '/');
if (!array_key_exists('view', $_GET) && $segment !== '') {
    $_GET['view'] = $segment;
}
require __DIR__ . '/app.php';
return true;
