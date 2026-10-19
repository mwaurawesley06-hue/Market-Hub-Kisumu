<?php
declare(strict_types=1);

/**
 * MPDIS Section 3.5.4 duplicate-detection workflow tests.
 *
 *   MPIDS_PROBE_PASSWORD=<password> php tests/duplicate-workflow.php [base-url]
 *
 * Logs in via the self-deleting loopback probe (_dup_probe_login.php at the
 * project root), runs the 8 spec scenarios over HTTP against a running
 * server, verifies the audit trail, and deletes every price record it created.
 * Audit rows are append-only and are left behind by design.
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1/mpdis', '/');
$passes = 0;
$failures = 0;
$failuresList = [];

function check(string $name, bool $ok, string $note = ''): void
{
    global $passes, $failures, $failuresList;
    if ($ok) {
        $passes++;
        echo "  ok  {$name}\n";
    } else {
        $failures++;
        $failuresList[] = $name . ($note !== '' ? " — {$note}" : '');
        echo "FAIL  {$name}" . ($note !== '' ? " — {$note}" : '') . "\n";
    }
}

/**
 * @return array{0:int,1:array|mixed} status, decoded body
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
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, json_decode((string) $raw, true)];
}

function probe_pdo(): PDO
{
    require_once dirname(__DIR__) . '/config/env.php';
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('MPIDS_DB_HOST', '127.0.0.1'), env('MPIDS_DB_PORT', '3306'), env('MPIDS_DB_NAME', 'mpids')),
        (string) env('MPIDS_DB_USER', 'root'),
        (string) env('MPIDS_DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function fail(string $message): never
{
    fwrite(STDERR, "ABORT: {$message}\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// 0. Preconditions
// ---------------------------------------------------------------------------
if ((string) getenv('MPIDS_PROBE_PASSWORD') === '') {
    fail('set MPIDS_PROBE_PASSWORD (the admin account password) to run this suite');
}
if (!is_file(dirname(__DIR__, 2) . '/_dup_probe_login.php')) {
    fail('missing _dup_probe_login.php probe at the project root');
}

[$pingStatus] = http('GET', "{$base}/api/public/dashboard");
if ($pingStatus === 0) {
    fail("Apache is not reachable at {$base}");
}

echo "MPDIS 3.5.4 duplicate-workflow tests against {$base}\n\n";

// --- login through the self-deleting probe ----------------------------------
[$status, $data] = http('GET', "{$base}/_dup_probe_login.php");
if ($status !== 200 || empty($data['cookie'])) {
    fail("probe login failed ({$status}): " . substr((string) json_encode($data), 0, 200));
}
$cookie = (string) $data['cookie'];
$csrf = (string) ($data['csrf_token'] ?? '');
$officerName = (string) ($data['full_name'] ?? 'Unknown');
echo "logged in as {$officerName}\n\n";

$pdo = probe_pdo();
$marker = 'DupTest ' . date('YmdHis');

// Resolve reference rows by name so the test is seed-independent.
$commodityId = (int) $pdo->query("SELECT id FROM commodities WHERE name LIKE 'Maize%' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$marketKisumu = (int) $pdo->query("SELECT id FROM markets WHERE name LIKE '%Kisumu%' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$commodityId || !$marketKisumu) {
    fail('need an active Maize commodity and a Kisumu market in the seed data');
}
$marketOther = (int) $pdo->query("SELECT id FROM markets WHERE is_active = 1 AND id <> {$marketKisumu} AND name LIKE '%Nairobi%' ORDER BY id LIMIT 1")->fetchColumn();
if (!$marketOther) {
    $marketOther = (int) $pdo->query("SELECT id FROM markets WHERE is_active = 1 AND id <> {$marketKisumu} ORDER BY id LIMIT 1")->fetchColumn();
}
if (!$marketOther) {
    fail('TEST 2 needs a second active market (ideally Nairobi) to prove DF-01');
}

// Pick two past dates that are free for the Kisumu key, so no live record can
// collide with the test and cleanup stays trivial.
$freeDates = [];
for ($offset = 2; $offset <= 20 && count($freeDates) < 2; $offset++) {
    $candidate = date('Y-m-d', strtotime("-{$offset} days"));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM price_records WHERE commodity_id = ? AND market_id = ? AND date_observed = ?');
    $stmt->execute([$commodityId, $marketKisumu, $candidate]);
    if ((int) $stmt->fetchColumn() === 0) {
        $freeDates[] = $candidate;
    }
}
if (count($freeDates) < 2) {
    fail('could not find two collision-free past dates for the Kisumu key');
}
[$testDate, $altDate] = $freeDates;

$haystack = fn(array $body): array => [
    'commodity' => $commodityId,
    'market' => $marketKisumu,
    'price' => 85.00,
    'date_observed' => $testDate,
    'source' => $marker,
    ...$body,
];

// --- baseline records (create audit rows; they are part of the workflow) -----
// Kisumu record on $testDate — the "existing record" for every later test.
[$status, $data] = http('POST', "{$base}/api/price-records", $haystack([]), ['X-CSRF-Token' => $csrf], $cookie);
$existingKisumuId = (int) ($data['id'] ?? 0);
if ($status !== 201 || !$existingKisumuId) {
    fail("could not create the Kisumu baseline record ({$status}): " . substr((string) json_encode($data), 0, 200));
}

echo "DF-01 regression (different market / different date must NOT warn)\n";

// TEST 2 — same commodity, DIFFERENT market, same date: no warning.
[$status, $data] = http('POST', "{$base}/api/price-records", $haystack(['market' => $marketOther]), ['X-CSRF-Token' => $csrf], $cookie);
$baselineOtherMarketId = (int) ($data['id'] ?? 0);
check('TEST 2: same commodity, different market, same date → saved without duplicate warning (DF-01 corrected)', $status === 201 && $baselineOtherMarketId > 0 && empty($data['duplicate']), "status={$status}");

// TEST 3 — same commodity, same market, DIFFERENT date: no warning.
[$status, $data] = http('POST', "{$base}/api/price-records", $haystack(['date_observed' => $altDate]), ['X-CSRF-Token' => $csrf], $cookie);
$baselineOtherDayId = (int) ($data['id'] ?? 0);
check('TEST 3: same commodity+market, different date → saved without duplicate warning', $status === 201 && $baselineOtherDayId > 0 && empty($data['duplicate']), "status={$status}");

// ---------------------------------------------------------------------------
echo "\nDuplicate warning and the three choices\n";

// TEST 1 — same commodity + market + date: the warning.
[$status, $data] = http('POST', "{$base}/api/price-records", $haystack([]), ['X-CSRF-Token' => $csrf], $cookie);
$duplicateInfo = is_array($data['existing'] ?? null) ? $data['existing'] : [];
check('TEST 1: same commodity+market+date → 409 duplicate warning', $status === 409 && !empty($data['duplicate']), "status={$status}");
check('TEST 1: warning names commodity, market, date', isset($duplicateInfo['commodity_name'], $duplicateInfo['market_name'], $duplicateInfo['date_observed']));
check('TEST 1: warning shows the existing price', isset($duplicateInfo['price']));
check('TEST 1: warning names the officer who stored the record', !empty($duplicateInfo['captured_by_name']), 'captured_by_name=' . ($duplicateInfo['captured_by_name'] ?? 'missing'));
check('TEST 1: warning carries the existing record ID', (int) ($duplicateInfo['id'] ?? 0) === $existingKisumuId);

// ---------------------------------------------------------------------------
echo "\nChoice 1 — Abandon Entry\n";

// Abandon is the clerk declining to resubmit — nothing is sent. The provable
// invariant: repeating the entry keeps hitting the warning, the key keeps
// exactly one row, and no new audit entry appears.
$auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

[$status, $data] = http('POST', "{$base}/api/price-records", $haystack([]), ['X-CSRF-Token' => $csrf], $cookie);
check('TEST 4: re-sending without override still shows the duplicate warning', $status === 409 && !empty($data['duplicate']), "status={$status}");

$stmt = $pdo->prepare('SELECT COUNT(*) FROM price_records WHERE commodity_id = ? AND market_id = ? AND date_observed = ?');
$stmt->execute([$commodityId, $marketKisumu, $testDate]);
check('TEST 4: exactly one record exists for the commodity+market+date key', (int) $stmt->fetchColumn() === 1);

$auditAfterAbandon = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
check('TEST 4: no audit entry was created by the abandoned attempts', $auditAfterAbandon === $auditBefore);

// ---------------------------------------------------------------------------
echo "\nChoice 2 — View Existing Record\n";

[$status, $data] = http('GET', "{$base}/api/price-records/{$existingKisumuId}", null, [], $cookie);
check('TEST 5: GET price-records/{id} returns the existing record', $status === 200 && (int) ($data['id'] ?? 0) === $existingKisumuId, "status={$status}");
check('TEST 5: view shows commodity, market, price, officer, timestamp, ID', isset($data['commodity_name'], $data['market_name'], $data['price'], $data['captured_by_name'], $data['created_at'], $data['id']));

[$status] = http('GET', "{$base}/api/price-records/99999999", null, [], $cookie);
check('TEST 5: viewing a missing record answers 404 (spec §13)', $status === 404, "status={$status}");

// ---------------------------------------------------------------------------
echo "\nChoice 3 — Override and Submit (justification enforcement)\n";

[$status, $data] = http('POST', "{$base}/api/price-records", $haystack(['override' => true, 'override_justification' => '']), ['X-CSRF-Token' => $csrf], $cookie);
check('TEST 7: empty justification is blocked (422 + spec message)', $status === 422 && str_contains((string) ($data['detail'] ?? ''), 'A written justification is required'), "status={$status}");

[$status, $data] = http('POST', "{$base}/api/price-records", $haystack(['override' => true, 'override_justification' => 'short']), ['X-CSRF-Token' => $csrf], $cookie);
check('TEST 7: too-short justification is blocked (422)', $status === 422, "status={$status}");

[$status, $data] = http('POST', "{$base}/api/price-records", $haystack(['override' => true, 'override_justification' => '12.50 25.00 ???.']), ['X-CSRF-Token' => $csrf], $cookie);
check('TEST 7: numbers/symbols-only justification is blocked (422)', $status === 422, "status={$status}");

// ---------------------------------------------------------------------------
echo "\nChoice 3 — Override and Submit (valid)\n";

$overrideJustification = 'New consignment arrived and the market price changed during the afternoon trading period.';
[$status, $data] = http('POST', "{$base}/api/price-records", $haystack(['price' => 90.00, 'override' => true, 'override_justification' => $overrideJustification]), ['X-CSRF-Token' => $csrf], $cookie);
check('TEST 6: override succeeds (200) and the record goes back to pending', $status === 200 && ($data['status'] ?? '') === 'pending', "status={$status}");
check('TEST 6: the justification is stored on the record', ($data['override_justification'] ?? '') === $overrideJustification);

$stmt = $pdo->prepare("SELECT id, user_id, old_value, new_value, ip_address, timestamp FROM audit_logs WHERE action = 'duplicate_override' AND table_name = 'price_records' AND record_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$existingKisumuId]);
$audit = $stmt->fetch(PDO::FETCH_ASSOC);
check('TEST 6: audit action duplicate_override was recorded', $audit !== false);
$oldAudit = $audit !== false ? (json_decode((string) $audit['old_value'], true) ?: []) : [];
$newAudit = $audit !== false ? (json_decode((string) $audit['new_value'], true) ?: []) : [];
check('TEST 6: audit stores the existing price', isset($oldAudit['price']) && (float) $oldAudit['price'] === 85.00);
check('TEST 6: audit stores the new price', isset($newAudit['price']) && (float) $newAudit['price'] === 90.00);
check('TEST 6: audit stores the justification', ($newAudit['override_justification'] ?? '') === $overrideJustification);
check('TEST 6: audit stores commodity, market and date', isset($newAudit['commodity'], $newAudit['market'], $newAudit['date_observed']));
check('TEST 6: audit records the authenticated officer from the session', isset($audit['user_id']) && (int) $audit['user_id'] > 0);
check('TEST 6: audit records the IP address', !empty($audit['ip_address']));
check('TEST 6: audit row is served through the audit-logs API', (function () use ($base, $cookie, $existingKisumuId): bool {
    [$s, $rows] = http('GET', "{$base}/api/audit-logs?table=price_records", null, [], $cookie);
    foreach ((array) $rows as $row) {
        if (($row['action'] ?? '') === 'duplicate_override' && (int) ($row['record_id'] ?? 0) === $existingKisumuId) {
            return true;
        }
    }
    return false;
})());

// ---------------------------------------------------------------------------
echo "\nTEST 8 — backend bypass attempts\n";

// Direct backend call WITHOUT override, exactly as a bypassing client would send.
[$status, $data] = http('POST', "{$base}/api/price-records", $haystack([]), ['X-CSRF-Token' => $csrf], $cookie);
check('TEST 8: direct backend save on a duplicate key is refused (409)', $status === 409 && !empty($data['duplicate']), "status={$status}");

[$status, $data] = http('POST', "{$base}/api/price-records", $haystack(['override' => true, 'override_justification' => 'ok']), ['X-CSRF-Token' => $csrf], $cookie);
check('TEST 8: bypass with a weak justification is refused (422)', $status === 422, "status={$status}");

$stmt = $pdo->prepare('SELECT COUNT(*) FROM price_records WHERE commodity_id = ? AND market_id = ? AND date_observed = ?');
$stmt->execute([$commodityId, $marketKisumu, $testDate]);
check('TEST 8: no second row exists for the key after bypass attempts', (int) $stmt->fetchColumn() === 1);

[$status] = http('POST', "{$base}/api/price-records", $haystack([]), ['X-CSRF-Token' => 'bogus'], $cookie);
check('TEST 8: request without a valid CSRF token is rejected (403)', $status === 403, "status={$status}");

[$status] = http('POST', "{$base}/api/price-records", $haystack([]));
check('TEST 8: unauthenticated request is rejected (401)', $status === 401, "status={$status}");

// ---------------------------------------------------------------------------
echo "\nSession handling (spec §13)\n";

[$status] = http('POST', "{$base}/api/auth/logout", [], ['X-CSRF-Token' => $csrf], $cookie);
check('logout succeeds (200)', $status === 200, "status={$status}");
[$status] = http('GET', "{$base}/api/price-records/{$existingKisumuId}", null, [], $cookie);
check('ended session is rejected (401)', $status === 401, "status={$status}");

// ---------------------------------------------------------------------------
// Cleanup — every row this run created
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('DELETE FROM price_records WHERE source = ?');
$stmt->execute([$marker]);
$removed = $stmt->rowCount();

echo "\n  ..  removed {$removed} test record(s)\n";
echo "\n{$passes} passed, {$failures} failed\n";
if ($failures > 0) {
    foreach ($failuresList as $entry) {
        echo "  - {$entry}\n";
    }
}
exit($failures === 0 ? 0 : 1);
