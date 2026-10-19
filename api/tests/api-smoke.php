<?php
declare(strict_types=1);

/**
 * MPIDS API smoke test — exercises the golden path against a running server.
 *
 *   php tests/api-smoke.php [base-url]
 *
 * Default base URL: http://localhost/mpdis
 * Requires the seed data (scripts/setup.php) and Apache/MySQL running.
 * Captures one price record and removes it again at the end, so the test can be
 * re-run without resetting the database. Audit entries are append-only by design
 * and are left behind on purpose.
 */

$base = rtrim($argv[1] ?? 'http://localhost/mpdis', '/');
// Admin password can be overridden without editing the test (the seeded default
// only holds while the database still matches scripts/setup.php).
$adminPassword = (string) (getenv('MPIDS_SMOKE_PASSWORD') ?: 'Admin@123');
$passes = 0;
$failures = 0;

function check(string $name, bool $ok): void
{
    global $passes, $failures;
    if ($ok) {
        $passes++;
        echo "  ok  {$name}\n";
    } else {
        $failures++;
        echo "FAIL  {$name}\n";
    }
}

/**
 * @return array{0:int,1:array|mixed,2:string,3:array} status, decoded body, raw body, response headers
 */
function http(string $method, string $url, ?array $body = null, array $headers = [], ?string $cookie = null): array
{
    $opts = [
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'timeout' => 15,
        ],
    ];
    foreach ($headers as $key => $value) {
        $opts['http']['header'] .= "{$key}: {$value}\r\n";
    }
    if ($cookie !== null) {
        $opts['http']['header'] .= "Cookie: {$cookie}\r\n";
    }
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    $context = stream_context_create($opts);
    $raw = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    $data = json_decode((string) $raw, true);
    return [$status, $data, (string) $raw, $http_response_header ?? []];
}

function session_cookie(array $httpHeaderLines): string
{
    $cookie = '';
    foreach ($httpHeaderLines as $line) {
        if (preg_match_all('~^Set-Cookie:\s*MPIDSSESSID=([^;]+)~i', $line, $matches) > 0) {
            // Login regenerates the session id, so the last Set-Cookie is the live one.
            $cookie = 'MPIDSSESSID=' . end($matches[1]);
        }
    }
    return $cookie;
}

/**
 * Clears the login lockout so the test can be re-run right after a failed run:
 * the wrong-password check consumes the account's five-strikes budget.
 */
function reset_login_lockout(): void
{
    require_once dirname(__DIR__) . '/config/env.php';
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('MPIDS_DB_HOST', '127.0.0.1'), env('MPIDS_DB_PORT', '3306'), env('MPIDS_DB_NAME', 'mpids')),
        (string) env('MPIDS_DB_USER', 'root'),
        (string) env('MPIDS_DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('TRUNCATE TABLE rate_limit_buckets');
}

function purge_smoke_records(): int
{
    require_once dirname(__DIR__) . '/config/env.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('MPIDS_DB_HOST', '127.0.0.1'),
        env('MPIDS_DB_PORT', '3306'),
        env('MPIDS_DB_NAME', 'mpids')
    );
    $pdo = new PDO($dsn, (string) env('MPIDS_DB_USER', 'root'), (string) env('MPIDS_DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $stmt = $pdo->prepare("DELETE FROM price_records WHERE source LIKE 'Smoke test %'");
    $stmt->execute();
    return $stmt->rowCount();
}

/** Opens a PDO handle for the checks that need to inspect or clean up rows. */
function smoke_pdo(): PDO
{
    require_once dirname(__DIR__) . '/config/env.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('MPIDS_DB_HOST', '127.0.0.1'),
        env('MPIDS_DB_PORT', '3306'),
        env('MPIDS_DB_NAME', 'mpids')
    );
    return new PDO($dsn, (string) env('MPIDS_DB_USER', 'root'), (string) env('MPIDS_DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

/** Finds the menu slot number belonging to a commodity name. */
function ussd_slot_for(string $menu, ?string $commodityName): int
{
    if ($commodityName === null) {
        return 0;
    }
    foreach (explode("\n", $menu) as $line) {
        if (preg_match('/^(\d+)\. ' . preg_quote($commodityName, '/') . '$/u', trim($line), $matches)) {
            return (int) $matches[1];
        }
    }
    return 0;
}

/** Sends one USSD request and returns the raw CON/END payload. */
function ussd(string $base, string $sessionId, string $text, ?string $phoneNumber = null, ?string $cookie = null): string
{
    $payload = ['sessionId' => $sessionId, 'text' => $text];
    if ($phoneNumber !== null) {
        $payload['phoneNumber'] = $phoneNumber;
    }
    // http() only attaches a Cookie header when $cookie is non-null; an empty
    // string (failed login) still exercises the signed-out path.
    [, , $raw] = http('POST', "{$base}/api/ussd", $payload, [], $cookie);
    return $raw;
}

/**
 * Sends one USSD request form-encoded — MobileSasa's exact callback contract
 * (application/x-www-form-urlencoded fields, plain CON/END text back).
 */
function ussd_form(string $base, string $sessionId, string $text, ?string $phoneNumber = null): string
{
    $payload = http_build_query([
        'sessionId' => $sessionId,
        'phoneNumber' => $phoneNumber,
        'networkCode' => '1',
        'serviceCode' => '*657#',
        'text' => $text,
    ]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents("{$base}/api/ussd", false, $context);
    return $raw === false ? '' : $raw;
}

echo "MPIDS API smoke test against {$base}\n\n";
reset_login_lockout();

// --- public reads -----------------------------------------------------------
[$s, $data] = http('GET', "{$base}/api/public/dashboard");
check('public dashboard is readable without login (200)', $s === 200 && is_array($data['rows'] ?? null));
check('public dashboard shows seeded prices', count($data['rows'] ?? []) >= 5);

[$s, $data] = http('GET', "{$base}/api/commodities");
check('commodity reference list is public (200)', $s === 200 && count($data) >= 5);

// --- auth -------------------------------------------------------------------
[$s] = http('POST', "{$base}/api/auth/login", ['username' => 'admin@mpids.co.ke', 'password' => 'wrong']);
check('login with wrong password is rejected (401)', $s === 401);

[$s, $data, , $loginHeaders] = http('POST', "{$base}/api/auth/login", ['username' => 'admin@mpids.co.ke', 'password' => $adminPassword]);
check('login succeeds (200)', $s === 200 && !empty($data['csrf_token']));
$cookie = session_cookie($loginHeaders);
$csrf = $data['csrf_token'] ?? '';

[$s] = http('GET', "{$base}/api/stats");
check('stats without a session is rejected (401)', $s === 401);

[$s, $data] = http('GET', "{$base}/api/stats", null, [], $cookie);
check('stats with a session returns the counters', $s === 200 && isset($data['pending'], $data['published']));

// --- capture workflow -------------------------------------------------------
$source = 'Smoke test ' . date('YmdHis');
$capture = ['commodity' => 8, 'market' => 2, 'price' => 170.00, 'date_observed' => date('Y-m-d'), 'source' => $source];

[$s] = http('POST', "{$base}/api/price-records", $capture, ['X-CSRF-Token' => 'bogus'], $cookie);
check('capture without a valid CSRF token is rejected (403)', $s === 403);

[$s, $data] = http('POST', "{$base}/api/price-records", $capture, ['X-CSRF-Token' => $csrf], $cookie);
check('capture creates a pending record (201)', $s === 201 && ($data['status'] ?? '') === 'pending');
$recordId = (int) ($data['id'] ?? 0);

[$s, $data] = http('POST', "{$base}/api/price-records", $capture, ['X-CSRF-Token' => $csrf], $cookie);
check('exact duplicate is refused with a 409 payload', $s === 409 && !empty($data['duplicate']));

$capture['price'] = 172.50;
[$s, $data] = http('POST', "{$base}/api/price-records", $capture + ['override' => true, 'override_justification' => 'Smoke test override'], ['X-CSRF-Token' => $csrf], $cookie);
check('override updates the existing record back to pending', $s === 200 && ($data['status'] ?? '') === 'pending');

[$s, $data] = http('POST', "{$base}/api/price-records/{$recordId}/approve", ['action' => 'approve'], ['X-CSRF-Token' => $csrf], $cookie);
check('approve publishes the record and reports SMS delivery', $s === 200 && ($data['status'] ?? '') === 'published' && isset($data['sms']));

[$s, $data] = http('POST', "{$base}/api/price-records/{$recordId}/approve", ['action' => 'approve'], ['X-CSRF-Token' => $csrf], $cookie);
check('approving a published record is refused (409)', $s === 409);

[$s, $data] = http('GET', "{$base}/api/public/dashboard?commodity=8");
$latest = $data['rows'][0] ?? [];
check('published price is visible on the public dashboard', $s === 200 && ($latest['price'] ?? 0) === 172.5);

// --- SMS subscription lifecycle --------------------------------------------
$phone = '+254799' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
[$s, $data] = http('POST', "{$base}/api/subscriptions", ['phone_number' => $phone, 'commodity' => 8, 'frequency' => 'daily']);
check('subscribe returns an OTP (sandbox mode)', $s === 200 && !empty($data['sandbox_otp']));
$otp = (string) ($data['sandbox_otp'] ?? '');

[$s, $data] = http('POST', "{$base}/api/subscriptions/verify", ['phone_number' => $phone, 'code' => $otp]);
check('OTP verification activates the subscription', $s === 200 && !empty($data['sandbox_unsubscribe_token']));
$token = (string) ($data['sandbox_unsubscribe_token'] ?? '');

[$s, $data] = http('DELETE', "{$base}/api/subscriptions?phone_number=" . urlencode($phone) . "&token={$token}");
check('unsubscribe with the token removes the subscription', $s === 200);

[$s] = http('DELETE', "{$base}/api/subscriptions?phone_number=" . urlencode($phone) . '&token=not-a-token');
check('unsubscribe with a wrong token is refused (404)', $s === 404);

// --- USSD -------------------------------------------------------------------
// The menu is ordered by commodity name, so pick the slot that belongs to the
// commodity this run just published instead of assuming slot 1 has a price.
[$s, $commodities] = http('GET', "{$base}/api/public/commodities");
$smokeCommodityName = null;
foreach ((array) $commodities as $item) {
    if ((int) ($item['id'] ?? 0) === (int) $capture['commodity']) {
        $smokeCommodityName = (string) $item['name'];
        break;
    }
}

$raw = ussd($base, 'smoke-1', '');
check('USSD main menu is a CON response', str_starts_with($raw, 'CON') && str_contains($raw, 'Reply with a number'));

// MobileSasa posts each step form-encoded with extra gateway fields, so the
// front controller must accept application/x-www-form-urlencoded too.
$raw = ussd_form($base, 'smoke-form-' . bin2hex(random_bytes(3)), '', '254712345678');
check('form-encoded USSD (MobileSasa contract) opens the main menu', str_starts_with($raw, 'CON') && str_contains($raw, 'Reply with a number'));

// The in-house simulator posts from a signed-in admin's browser WITHOUT a CSRF
// header (it is a gateway endpoint). Regression: MPIDS_PUBLIC_WRITES must spell
// the action-less route 'ussd/' or a signed-in session gets 403.
$raw = ussd($base, 'smoke-sess-' . bin2hex(random_bytes(3)), '', null, $cookie);
check('USSD works for a signed-in session without a CSRF token', str_starts_with($raw, 'CON') && str_contains($raw, 'Reply with a number'));

$slot = ussd_slot_for(ussd($base, 'smoke-1', '1'), $smokeCommodityName);
check('USSD price menu lists the published commodity', $slot > 0);

$price = $slot > 0 ? ussd($base, 'smoke-1', (string) $slot) : '';
check('USSD selection returns an END price response', str_starts_with($price, 'END') && str_contains($price, 'KES'));

// A real handset resends everything typed so far ("1*4"), so the accumulated
// string has to replay each answer instead of only reading the last segment.
$cumulative = ussd_slot_for(ussd($base, 'smoke-2', '1'), $smokeCommodityName);
check('USSD price menu is reachable from a fresh session', $cumulative > 0);
$raw = $cumulative > 0 ? ussd($base, 'smoke-2', "1*{$cumulative}") : '';
check('cumulative USSD input replays every segment to a price', str_starts_with($raw, 'END') && str_contains($raw, 'KES'));

// --- USSD subscription lifecycle --------------------------------------------
$ussdPhone = '+254733' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$ussdSession = 'smoke-sub-' . bin2hex(random_bytes(4));

$menu = ussd($base, $ussdSession, '2', $ussdPhone);
check('USSD option 2 opens the SMS alert flow', str_starts_with($menu, 'CON') && str_contains($menu, 'phone number'));

$menu = ussd($base, $ussdSession, $ussdPhone);
$ussdSlot = ussd_slot_for($menu, $smokeCommodityName);
check('USSD subscription flow lists the commodity', $ussdSlot > 0);

// Invalid input has to leave the session open so the user can correct it.
$retry = ussd($base, $ussdSession, '99');
check('USSD invalid slot is recoverable rather than fatal', str_starts_with($retry, 'CON') && str_contains($retry, 'not on this page'));

$menu = ussd($base, $ussdSession, (string) $ussdSlot);
check('USSD asks for the alert frequency', str_starts_with($menu, 'CON') && str_contains($menu, 'Daily'));

$menu = ussd($base, $ussdSession, '1');
check('USSD shows the confirmation screen', str_starts_with($menu, 'CON') && str_contains($menu, 'Confirm') && str_contains($menu, $ussdPhone));

$raw = ussd($base, $ussdSession, '1');
check('USSD confirmation creates the subscription and returns an OTP', str_starts_with($raw, 'END') && str_contains($raw, 'Subscribed to') && preg_match('/code:\s*(\d{6})\b/i', $raw, $otpMatch) === 1);
$ussdOtp = (string) ($otpMatch[1] ?? '');

$pdo = smoke_pdo();
$stmt = $pdo->prepare('SELECT id, is_verified, verification_code_hash FROM sms_subscriptions WHERE phone_number = ? AND commodity_id = ? AND frequency = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$ussdPhone, (int) $capture['commodity'], 'daily']);
$ussdRow = $stmt->fetch() ?: [];
check('USSD subscription is stored in sms_subscriptions and starts unverified', $ussdRow !== [] && (int) $ussdRow['is_verified'] === 0 && !empty($ussdRow['verification_code_hash']));

$stmt = $pdo->prepare("SELECT new_value FROM audit_logs WHERE action IN ('create','resend') AND table_name = 'sms_subscriptions' AND record_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([(int) ($ussdRow['id'] ?? 0)]);
$auditMeta = json_decode((string) $stmt->fetchColumn(), true);
check('the audit trail records that the subscription came from USSD', ($auditMeta['source'] ?? null) === 'ussd');

[$s, $data] = http('POST', "{$base}/api/subscriptions/verify", ['phone_number' => $ussdPhone, 'code' => $ussdOtp]);
check('a USSD subscription verifies through the same web endpoint', $s === 200 && !empty($data['subscription_id']));

$raw = ussd($base, 'smoke-dup-' . bin2hex(random_bytes(4)), "2*{$ussdPhone}*{$ussdSlot}*1*1", $ussdPhone);
check('USSD refuses a duplicate subscription and saves nothing', str_starts_with($raw, 'END') && str_contains($raw, 'already subscribed'));

$stmt = $pdo->prepare('SELECT COUNT(*) FROM sms_subscriptions WHERE phone_number = ?');
$stmt->execute([$ussdPhone]);
check('the refused duplicate did not add a second row', (int) $stmt->fetchColumn() === 1);

// * walks back exactly one screen, # abandons the flow. The test starts from
// the commodity screen so "back" has to land on the phone screen, not the menu.
$backSession = 'smoke-back-' . bin2hex(random_bytes(4));
ussd($base, $backSession, '2', $ussdPhone);
ussd($base, $backSession, $ussdPhone);
$raw = ussd($base, $backSession, '*');
check('USSD * walks back one screen to the phone prompt', str_starts_with($raw, 'CON') && str_contains($raw, 'Enter your phone number'));

$raw = ussd($base, $backSession, '*');
check('USSD * from the phone prompt returns to the main menu', str_starts_with($raw, 'CON') && str_contains($raw, 'Check commodity prices'));

// Going back has to rebuild a usable screen, not a bare prompt with no choices.
$raw = ussd($base, $backSession, '2', $ussdPhone);
$raw = ussd($base, $backSession, $ussdPhone);
$raw = ussd($base, $backSession, (string) $ussdSlot);
$raw = ussd($base, $backSession, '*');
check('USSD * from the frequency screen re-lists the commodities', str_starts_with($raw, 'CON') && str_contains($raw, 'Select commodity') && preg_match('/^1\. \S/m', $raw) === 1);

$cancelSession = 'smoke-cancel-' . bin2hex(random_bytes(4));
ussd($base, $cancelSession, '2', $ussdPhone);
$raw = ussd($base, $cancelSession, '#');
check('USSD # cancels the session', str_starts_with($raw, 'END') && str_contains($raw, 'cancelled'));

// --- Kenyan phone formats ---------------------------------------------------
// Every accepted spelling has to land in the same canonical +254… row, so the
// Safaricom (7…) and Airtel (1…) ranges are checked against their own values.
$digits7 = '7' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$digits1 = '1' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$formats = [
    "0{$digits7}" => "+254{$digits7}",
    "254{$digits7}" => "+254{$digits7}",
    "+254{$digits7}" => "+254{$digits7}",
    $digits7 => "+254{$digits7}",
    '0' . $digits1 => "+254{$digits1}",
    "254{$digits1}" => "+254{$digits1}",
    "+254{$digits1}" => "+254{$digits1}",
];
foreach ($formats as $variant => $expected) {
    [$status, $data] = http('POST', "{$base}/api/subscriptions", ['phone_number' => $variant, 'commodity' => 8, 'frequency' => 'weekly']);
    $id = (int) ($data['subscription_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT phone_number FROM sms_subscriptions WHERE id = ?');
    $stmt->execute([$id]);
    $stored = $stmt->fetchColumn();
    check("Kenyan format {$variant} is stored as {$expected}", $id > 0 && $status === 200 && $stored === $expected);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM sms_subscriptions WHERE id = ?')->execute([$id]);
    }
}

[$s] = http('POST', "{$base}/api/subscriptions", ['phone_number' => '245741201983', 'commodity' => 8, 'frequency' => 'daily']);
check('malformed number 245741201983 is refused (422)', $s === 422);

[$s] = http('POST', "{$base}/api/subscriptions", ['phone_number' => '+254812345678', 'commodity' => 8, 'frequency' => 'daily']);
check('non-Kenyan Kenyan-looking prefix is refused (422)', $s === 422);

$stmt = $pdo->prepare('DELETE FROM sms_subscriptions WHERE phone_number = ?');
$stmt->execute([$ussdPhone]);
check('USSD smoke subscription is cleaned up', $stmt->rowCount() >= 1);

$stmt = $pdo->prepare("DELETE FROM ussd_sessions WHERE session_id LIKE 'smoke-%'");
$stmt->execute();

// --- reports + audit --------------------------------------------------------
[$s, , $raw] = http('GET', "{$base}/api/reports?status=published&export=csv", null, [], $cookie);
check('CSV export streams a published-records report', $s === 200 && str_starts_with($raw, 'ID,Commodity'));

[$s, $data] = http('GET', "{$base}/api/audit-logs", null, [], $cookie);
check('audit log lists the workflow actions', $s === 200 && count($data) >= 5);

// --- logout -----------------------------------------------------------------
[$s] = http('POST', "{$base}/api/auth/logout", [], ['X-CSRF-Token' => $csrf], $cookie);
check('logout succeeds (200)', $s === 200);

[$s] = http('GET', "{$base}/api/stats", null, [], $cookie);
check('session is invalid after logout (401)', $s === 401);

$removed = purge_smoke_records();
echo "  ..  removed {$removed} smoke-test price record(s)\n";

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
