<?php
declare(strict_types=1);

/**
 * API front controller. Every /api request enters here: .htaccess rewrites
 * extensionless paths to this file and denies direct access to handlers/,
 * so this stays the only reachable entry point.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/subscriptions.php';

foreach (['audit-logs', 'auth', 'commodities', 'commodity-images', 'markets', 'price-records', 'public-dashboard', 'reports', 'site-settings', 'stats', 'subscriptions', 'users', 'ussd'] as $mpidsHandler) {
    require_once __DIR__ . '/handlers/' . $mpidsHandler . '.php';
}

// Responses are the bare payload: js/api.js passes the body straight to callers,
// so only the detail/errors keys are added on failures.
function json_out(mixed $data = null, int $status = 200): void
{
    json_response(is_array($data) ? $data : ['data' => $data], $status);
}

function error_out(int $status, string $detail, array $extra = []): void
{
    json_response(array_merge(['detail' => $detail], $extra), $status);
}

function audit_log(PDO $pdo, ?int $userId, string $action, string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues, string $ipAddress = ''): void
{
    write_audit($pdo, $userId, $action, $entityType, $entityId, $oldValues, $newValues);
}

const MPIDS_PUBLIC_WRITES = [
    'auth/login',
    // The lookup below builds "$resource/$action", and a null action renders
    // as a trailing slash — 'ussd' (no action) must be spelled 'ussd/' or the
    // USSD endpoint demands a CSRF token from any signed-in browser, which the
    // handset gateway and the in-house simulator never send.
    'ussd/',
    // 'subscriptions/' is the collection itself: POST subscribes, DELETE
    // unsubscribes, and neither carries a token to sign with.
    'subscriptions/',
    'subscriptions/verify',
    'subscriptions/unsubscribe',
];

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$apiBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/');
// Windows (XAMPP) serves URLs case-insensitively: /mpdis/... resolves to the
// MPDIS folder, so the base must be stripped without relying on URL casing.
if ($apiBase !== '' && $apiBase !== '/' && stripos($path, $apiBase) === 0) {
    $path = substr($path, strlen($apiBase));
}

$segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== ''));
$resource = $segments[0] ?? '';
$action = $segments[1] ?? null;

// Liveness/readiness for Render (and any load balancer). Handled before the
// shared db() connection so a broken DATABASE_URL still answers with a clear
// diagnostic instead of an uncaught PDOException page.
if ($resource === 'health') {
    if ($method !== 'GET') {
        error_out(405, 'Method not allowed.');
    }
    $dbOk = false;
    $dbError = null;
    try {
        db()->query('SELECT 1');
        $dbOk = true;
    } catch (Throwable $healthDbError) {
        $dbError = $healthDbError->getMessage();
    }
    json_out([
        'ok' => true,
        'app' => APP_NAME,
        'database' => $dbOk,
        'db_host' => DB_HOST,
        'db_error' => $dbError,
        'time' => date('c'),
    ], 200);
}

$pdo = db();
$user = current_user($pdo);
$isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
$body = $isWrite ? request_data() : [];

// Public verify/unsubscribe/USSD endpoints have no session to sign with, so a
// logged-in session is the only case where a write must carry the CSRF token.
// The API answers 403 here; require_csrf() (419) is reserved for page forms.
if ($user !== null && $isWrite && !in_array($resource . '/' . ($action ?? ''), MPIDS_PUBLIC_WRITES, true)) {
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || $provided === '' || !hash_equals(csrf_token(), $provided)) {
        error_out(403, 'CSRF token is missing or invalid.');
    }
}

switch ($resource) {
    case 'auth':
        if ($action === 'me') {
            require_method('GET');
            json_out(['user' => require_auth($pdo), 'csrf_token' => csrf_token()]);
        }
        if ($action === 'login' || $action === 'logout') {
            handle_auth($method, $action, $body, $user);
        }
        error_out(404, 'Not found.');

    case 'commodities':
    case 'markets':
        // Product image endpoints are authenticated multipart uploads/removals
        // handled before the JSON-only reference-data handlers.
        if ($resource === 'commodities' && $action === 'image') {
            require_auth($pdo);
            handle_commodity_image_upload($user);
        }
        if ($resource === 'commodities' && ($segments[2] ?? '') === 'image') {
            require_auth($pdo);
            if ($method !== 'DELETE') {
                error_out(405, 'Method not allowed.');
            }
            handle_commodity_image_delete($user, (int) $action);
        }
        // Signed-out reference reads are limited to the active-only projection;
        // include_inactive and every write stay behind authentication.
        $publicRead = $user === null;
        if ($publicRead && $method !== 'GET') {
            require_auth($pdo);
        }
        if ($resource === 'commodities') {
            handle_commodities($method, $action, $body, $user, $publicRead);
        }
        handle_markets($method, $action, $body, $user, $publicRead);

    case 'price-records':
        handle_price_records($method, $action, $segments[2] ?? null, $body, require_auth($pdo));

    case 'users':
        handle_users($method, $action, $body, require_auth($pdo));

    case 'public':
        if ($action === 'dashboard') {
            handle_public_dashboard($method, $action);
        }
        if ($action === 'commodities') {
            handle_commodities('GET', null, [], null, true);
        }
        if ($action === 'markets') {
            handle_markets('GET', null, [], null, true);
        }
        error_out(404, 'Not found.');

    case 'dashboard':
    case 'stats':
        require_auth($pdo);
        handle_stats($method);

    case 'reports':
        require_auth($pdo);
        if ($action === 'export') {
            $_GET['export'] = 'csv';
        }
        handle_reports($method);

    case 'settings':
        if ($action === null && $method === 'GET' && $user === null) {
            handle_public_site_settings();
        }
        require_auth($pdo);
        if ($action === 'logo') {
            if ($method === 'POST') {
                handle_site_logo_upload($user);
            }
            if ($method === 'DELETE') {
                handle_site_logo_delete($user);
            }
            error_out(405, 'Method not allowed.');
        }
        handle_site_settings($method, $body, $user);

    case 'audit-logs':
        require_auth($pdo);
        handle_audit_logs($method);

    case 'subscriptions':
        handle_subscriptions($method, $action, $body, $user);

    case 'ussd':
        handle_ussd($method, $body);

    default:
        error_out(404, 'Not found.');
}
