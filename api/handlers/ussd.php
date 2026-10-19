<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/subscriptions.php';

/**
 * USSD endpoint for the local simulator and for a provider USSD channel
 * (e.g. Infobip) pointed at this same URL.
 *
 * POST body: { sessionId, phoneNumber?, text }
 * Response : text/plain beginning with CON (keep the session open) or END
 *            (close it). A gateway resends the whole accumulated reply, so the
 *            current answer is the last *-separated segment; a single step is
 *            the same thing with one segment.
 *
 * The conversation lives in ussd_sessions: a gateway hands back a sessionId but
 * no memory, and the same state has to survive the round trip through the
 * browser so the simulator behaves like a real handset.
 *
 * MobileSasa (account.mobilesasa.com) posts each step form-encoded with the
 * X-MobileSasa-Secret / X-MobileSasa-Signature headers. When a webhook secret
 * is configured, mobilesasa_webhook_authorized() rejects anything unsigned.
 */

/** A handset that walks away mid-menu gets its session recycled after this long. */
const MPIDS_USSD_SESSION_TTL_MINUTES = 5;

/** Sessions untouched for this long are swept so the table cannot grow forever. */
const MPIDS_USSD_SESSION_SWEEP_HOURS = 1;

const MPIDS_USSD_COMMODITIES_PER_PAGE = 7;

/**
 * Verifies MobileSasa's webhook headers once MOBILESASA_WEBHOOK_SECRET is set
 * (portal → Settings → Webhooks). No secret configured = verification off, so
 * the local simulator and the smoke suite keep working unchanged.
 *
 * Either header passing is enough:
 *   X-MobileSasa-Secret    – the secret verbatim (equality check), or
 *   X-MobileSasa-Signature – t=<unix>,v1=<hex HMAC-SHA256 of t.rawBody>.
 *
 * Loopback callers are exempt: the in-house USSD simulator on this machine
 * signs nothing, and a remote attacker cannot source a request from 127.0.0.1.
 *
 * $rawBody is only read when a signature must be checked; pass null in tests
 * to use php://input (the bytes exactly as received — re-encoding a form body
 * would reorder fields and change the payload the signature was computed over).
 */
function mobilesasa_webhook_authorized(?string $rawBody = null): bool
{
    $secret = defined('MOBILESASA_WEBHOOK_SECRET') ? (string) MOBILESASA_WEBHOOK_SECRET : (string) env('MOBILESASA_WEBHOOK_SECRET', '');
    if ($secret === '') {
        return true;
    }

    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '::1' || $remote === '127.0.0.1' || str_starts_with($remote, '127.')) {
        return true;
    }

    $provided = trim((string) ($_SERVER['HTTP_X_MOBILESASA_SECRET'] ?? ''));
    if ($provided !== '' && hash_equals($secret, $provided)) {
        return true;
    }

    $signature = trim((string) ($_SERVER['HTTP_X_MOBILESASA_SIGNATURE'] ?? ''));
    if ($signature === '') {
        return false;
    }
    $raw = $rawBody ?? file_get_contents('php://input');
    return $raw !== false && mobilesasa_signature_valid($secret, $signature, $raw, time());
}

/** Checks one t=…,v1=… signature against the raw body; pure, unit-testable. */
function mobilesasa_signature_valid(string $secret, string $signature, string $rawBody, int $now): bool
{
    $parts = [];
    foreach (explode(',', $signature) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $parts[trim($key)] = trim($value);
    }
    $timestamp = (int) ($parts['t'] ?? 0);
    $v1 = (string) ($parts['v1'] ?? '');
    if ($timestamp <= 0 || $v1 === '' || abs($now - $timestamp) > 300) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), $v1);
}

function handle_ussd(string $method, array $body): void
{
    if ($method !== 'POST') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(405);
        echo 'END Method not allowed.';
        exit;
    }

    if (!mobilesasa_webhook_authorized()) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(403);
        echo 'END Unauthorized.';
        exit;
    }

    $pdo = db();
    $sessionId = trim((string) ($body['sessionId'] ?? $body['session_id'] ?? ''));
    $dialingNumber = normalize_phone((string) ($body['phoneNumber'] ?? $body['phone_number'] ?? ''));

    // rate_limit() answers with the JSON envelope, which a USSD gateway cannot
    // render; the buffer callback swaps it for a plain END line.
    $plainRateLimit = static function (string $buffer): string {
        if (http_response_code() === 429) {
            header('Content-Type: text/plain; charset=utf-8', true);
            return 'END Too many requests. Please try again later.';
        }
        return $buffer;
    };

    ob_start($plainRateLimit);
    rate_limit($pdo, 'ussd-ip:' . client_ip(), 60, 60);
    if ($sessionId !== '') {
        rate_limit($pdo, 'ussd-session:' . substr($sessionId, 0, 100), 30, 60);
    }
    ob_end_clean();

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $sessionId = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $sessionId) ?: '', 0, 64);
    $text = trim((string) ($body['text'] ?? ''));

    $session = ussd_session_load($pdo, $sessionId, $dialingNumber);
    if ($dialingNumber !== null) {
        $session['phone_number'] = $dialingNumber;
    }

    ussd_session_sweep($pdo);

    $pending = ussd_pending_steps($text, $session);
    $response = '';
    foreach ($pending as $step) {
        $response = ussd_dispatch($pdo, $session, $step, $dialingNumber);
        if (str_starts_with($response, 'END')) {
            break;
        }
    }

    ussd_session_save($pdo, $sessionId, $session, $text, $response);
    echo $response;
    exit;
}

/**
 * Works out which replies still need answering.
 *
 * A real gateway resends everything the handset has typed so far ("2*1*3"),
 * while the browser simulator sends one answer at a time ("2", then "1").
 * Counting the segments already consumed tells the two apart: a longer string
 * means the client is accumulating, anything else is a single fresh reply.
 */
function ussd_pending_steps(string $text, array &$session): array
{
    $text = trim($text);

    // * and # are control keys, not answers, so they must survive the split:
    // "*" on its own would otherwise be read as an empty reply.
    if ($text === '*' || $text === '#') {
        $session['steps'] = 0;
        return [$text];
    }

    $segments = array_values(array_filter(
        explode('*', $text),
        static fn (string $segment): bool => trim($segment) !== ''
    ));
    $consumed = (int) ($session['steps'] ?? 0);

    if (count($segments) > $consumed) {
        $session['steps'] = count($segments);
        return array_map('trim', array_slice($segments, $consumed));
    }

    $session['steps'] = count($segments);
    return $segments ? [trim((string) end($segments))] : [''];
}

/** Loads the stored step, or starts a fresh one when the session is new/expired. */
function ussd_session_load(PDO $pdo, string $sessionId, ?string $phoneNumber): array
{
    $fresh = [
        'menu' => 'main',
        'steps' => 0,
        'state' => [],
        'phone_number' => $phoneNumber,
    ];
    if ($sessionId === '') {
        return $fresh;
    }

    $stmt = $pdo->prepare('SELECT menu, state_data, phone_number, updated_at FROM ussd_sessions WHERE session_id = ? LIMIT 1');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $fresh;
    }

    $age = time() - (int) (strtotime((string) $row['updated_at']) ?: 0);
    if ($age > MPIDS_USSD_SESSION_TTL_MINUTES * 60) {
        return $fresh;
    }

    $stored = json_decode((string) ($row['state_data'] ?? ''), true);
    $fresh['menu'] = (string) ($row['menu'] ?? 'main');
    $fresh['steps'] = is_array($stored) ? (int) ($stored['steps'] ?? 0) : 0;
    $fresh['state'] = is_array($stored) && is_array($stored['data'] ?? null) ? $stored['data'] : [];
    $fresh['phone_number'] = $phoneNumber ?? ($row['phone_number'] !== null ? (string) $row['phone_number'] : null);
    return $fresh;
}

function ussd_session_save(PDO $pdo, string $sessionId, array $session, string $input, string $response): void
{
    if ($sessionId === '') {
        return;
    }

    $ended = str_starts_with($response, 'END');
    $stmt = $pdo->prepare(
        'INSERT INTO ussd_sessions
            (session_id, phone_number, menu, state_data, last_input, last_response, is_active, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            phone_number = VALUES(phone_number),
            menu = VALUES(menu),
            state_data = VALUES(state_data),
            last_input = VALUES(last_input),
            last_response = VALUES(last_response),
            is_active = VALUES(is_active),
            updated_at = NOW()'
    );
    $stmt->execute([
        $sessionId,
        $session['phone_number'] ?? null,
        $session['menu'],
        json_encode([
            'steps' => $ended ? 0 : (int) ($session['steps'] ?? 0),
            'data' => $session['state'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        substr($input, 0, 64),
        $response,
        $ended ? 0 : 1,
    ]);
}

/** Drops sessions nobody has touched in a while, on roughly one request in fifty. */
function ussd_session_sweep(PDO $pdo): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM ussd_sessions WHERE updated_at < NOW() - INTERVAL ? HOUR');
    $stmt->execute([MPIDS_USSD_SESSION_SWEEP_HOURS]);
}

/**
 * Advances the conversation one step and returns the text to send back.
 * $session is modified in place so ussd_session_save() persists the new step.
 */
function ussd_dispatch(PDO $pdo, array &$session, string $input, ?string $dialingNumber): string
{
    // # cancels, * steps back. Both are checked before the menu so a handset
    // can always get out of a screen it does not understand.
    if ($input === '#') {
        ussd_reset_to_main($session, $dialingNumber);
        return "END Session cancelled. Thanks for using MPIDS.";
    }
    if ($input === '*' && $session['menu'] !== 'main') {
        $session['menu'] = ussd_parent_menu((string) $session['menu']);
        $session['state'] = [];
        return ussd_render($pdo, $session, (string) $session['menu']);
    }

    return match ($session['menu']) {
        'price_menu' => ussd_step_price_menu($pdo, $session, $input),
        'subscribe_phone' => ussd_step_subscribe_phone($pdo, $session, $input, $dialingNumber),
        'subscribe_commodity' => ussd_step_subscribe_commodity($pdo, $session, $input),
        'subscribe_frequency' => ussd_step_subscribe_frequency($pdo, $session, $input),
        'subscribe_confirm' => ussd_step_subscribe_confirm($pdo, $session, $input),
        default => ussd_step_main($pdo, $session, $input),
    };
}

/** A step's parent, so * walks the menu back out one screen at a time. */
function ussd_parent_menu(string $menu): string
{
    return match ($menu) {
        'subscribe_phone' => 'main',
        'subscribe_commodity' => 'subscribe_phone',
        'subscribe_frequency' => 'subscribe_commodity',
        'subscribe_confirm' => 'subscribe_frequency',
        default => 'main',
    };
}

/**
 * Returns the session to the top menu, keeping the number the handset dialled
 * from and restarting the input count so the next dial starts clean.
 */
function ussd_reset_to_main(array &$session, ?string $phoneNumber = null): void
{
    $session['menu'] = 'main';
    $session['state'] = [];
    $session['steps'] = 0;
    if ($phoneNumber !== null) {
        $session['phone_number'] = $phoneNumber;
    }
}

function ussd_step_main(PDO $pdo, array &$session, string $input): string
{
    if ($input === '') {
        return "CON MPIDS Market Info Hub\n"
            . "1. Check commodity prices\n"
            . "2. Subscribe to SMS price alerts\n"
            . 'Reply with a number.';
    }

    if ($input === '1') {
        $commodities = ussd_commodity_list($pdo);
        if (!$commodities) {
            return 'END MPIDS Market Info Hub: no active commodities yet. Please try again later.';
        }
        $session['menu'] = 'price_menu';
        $session['state'] = ['page' => 0];
        return ussd_commodity_menu($commodities, 0, "CON MPIDS price check\nSelect commodity:");
    }

    if ($input === '2') {
        $session['menu'] = 'subscribe_phone';
        $session['state'] = [];
        return "CON SMS price alerts\nEnter your phone number\n(e.g. 0712345678)";
    }

    ussd_reset_to_main($session);
    return "CON Invalid choice.\n1. Check commodity prices\n2. Subscribe to SMS price alerts\nReply with a number.";
}

// --- price enquiry ----------------------------------------------------------

function ussd_step_price_menu(PDO $pdo, array &$session, string $input): string
{
    $commodities = ussd_commodity_list($pdo);
    if (!$commodities) {
        ussd_reset_to_main($session);
        return 'END MPIDS Market Info Hub: no active commodities yet. Please try again later.';
    }

    $page = max(0, (int) ($session['state']['page'] ?? 0));

    if ($input === '') {
        return ussd_commodity_menu($commodities, $page, "CON MPIDS price check\nSelect commodity:");
    }
    if ($input === '0') {
        $page++;
        $session['state'] = ['page' => $page];
        return ussd_commodity_menu($commodities, $page, "CON MPIDS price check\nSelect commodity:");
    }

    if (!ctype_digit($input)) {
        return "CON Invalid entry. Reply with a number, 0 for more, or * to go back.";
    }

    $commodity = ussd_pick_commodity($commodities, $page, (int) $input);
    if ($commodity === null) {
        return ussd_commodity_menu($commodities, $page, "CON That number is not on this page.\nSelect commodity:");
    }

    ussd_reset_to_main($session);
    return ussd_commodity_price_response($pdo, $commodity);
}

// --- SMS subscription -------------------------------------------------------

function ussd_step_subscribe_phone(PDO $pdo, array &$session, string $input, ?string $dialingNumber): string
{
    $commodities = ussd_commodity_list($pdo);
    if (!$commodities) {
        ussd_reset_to_main($session);
        return 'END MPIDS Market Info Hub: no active commodities yet. Please try again later.';
    }

    $known = $dialingNumber ?? ($session['phone_number'] ?? null);

    // An empty reply takes the number the handset dialled from, which is how a
    // real gateway behaves: the MSISDN is already known to the network.
    if ($input === '' && $known !== null) {
        $session['state'] = ['phone' => $known, 'page' => 0];
        $session['menu'] = 'subscribe_commodity';
        return ussd_commodity_menu($commodities, 0, "CON Alerts for {$known}\nSelect commodity:");
    }
    if ($input === '') {
        return "CON Enter your phone number\n(e.g. 0712345678)";
    }

    $phone = normalize_phone($input);
    if ($phone === null) {
        return "CON Not a Kenyan mobile number.\nTry 0712345678, 0112345678 or +254712345678";
    }

    $session['phone_number'] = $phone;
    $session['state'] = ['phone' => $phone, 'page' => 0];
    $session['menu'] = 'subscribe_commodity';
    return ussd_commodity_menu($commodities, 0, "CON Alerts for {$phone}\nSelect commodity:");
}

function ussd_step_subscribe_commodity(PDO $pdo, array &$session, string $input): string
{
    $commodities = ussd_commodity_list($pdo);
    if (!$commodities) {
        ussd_reset_to_main($session);
        return 'END MPIDS Market Info Hub: no active commodities yet. Please try again later.';
    }

    $page = max(0, (int) ($session['state']['page'] ?? 0));
    $phone = (string) ($session['state']['phone'] ?? '');

    if ($input === '') {
        return ussd_commodity_menu($commodities, $page, "CON Alerts for {$phone}\nSelect commodity:");
    }
    if ($input === '0') {
        $page++;
        $session['state']['page'] = $page;
        return ussd_commodity_menu($commodities, $page, "CON Alerts for {$phone}\nSelect commodity:");
    }
    if (!ctype_digit($input)) {
        return "CON Invalid entry. Reply with a number, 0 for more, or * to go back.";
    }

    $commodity = ussd_pick_commodity($commodities, $page, (int) $input);
    if ($commodity === null) {
        return ussd_commodity_menu($commodities, $page, "CON That number is not on this page.\nSelect commodity:");
    }

    $session['state'] += [
        'commodity_id' => (int) $commodity['id'],
        'commodity_name' => (string) $commodity['name'],
        'unit' => (string) $commodity['unit_of_measure'],
    ];
    $session['menu'] = 'subscribe_frequency';
    return "CON How often should we alert you?\n1. Daily\n2. Weekly\nReply 1 or 2.";
}

function ussd_step_subscribe_frequency(PDO $pdo, array &$session, string $input): string
{
    $state = $session['state'];

    if ($input === '') {
        return "CON How often should we alert you?\n1. Daily\n2. Weekly\nReply 1 or 2.";
    }

    $frequency = match ($input) {
        '1' => 'daily',
        '2' => 'weekly',
        default => null,
    };
    if ($frequency === null) {
        return "CON Invalid frequency. Reply 1 for daily or 2 for weekly.";
    }

    $session['state']['frequency'] = $frequency;
    $session['menu'] = 'subscribe_confirm';
    return "CON Confirm subscription\n"
        . "Commodity: {$state['commodity_name']} ({$state['unit']})\n"
        . "Phone: {$state['phone']}\n"
        . "Frequency: {$frequency}\n"
        . "1. Confirm\n2. Cancel";
}

function ussd_step_subscribe_confirm(PDO $pdo, array &$session, string $input): string
{
    $state = $session['state'];
    $phone = (string) ($state['phone'] ?? '');
    $frequency = (string) ($state['frequency'] ?? 'daily');

    if ($input === '2') {
        ussd_reset_to_main($session);
        return "END Subscription cancelled. Nothing was saved.";
    }
    if ($input === '') {
        return "CON Confirm subscription for {$state['commodity_name']}\n1. Confirm\n2. Cancel";
    }
    if ($input !== '1') {
        return "CON Invalid choice.\n1. Confirm\n2. Cancel";
    }

    try {
        [$errors, $normalized] = mpids_validate_subscription($pdo, [
            'phone_number' => $phone,
            'commodity' => (int) ($state['commodity_id'] ?? 0),
            'frequency' => $frequency,
        ]);
        if ($errors) {
            ussd_reset_to_main($session);
            return 'END ' . reset($errors);
        }

        $result = mpids_create_sms_subscription($pdo, $normalized, client_ip(), 'ussd');
    } catch (MpidsSubscriptionError $e) {
        ussd_reset_to_main($session);
        return 'END ' . $e->getMessage();
    }

    $commodityName = $result['commodity_name'];
    ussd_reset_to_main($session);

    if ($result['already_verified']) {
        return "END You are already subscribed to {$commodityName} {$frequency} alerts on {$phone}.";
    }

    $code = (string) $result['code'];
    $sent = sms_send($phone, "MPIDS: Your verification code is {$code}. Valid for " . intdiv(MPIDS_VERIFICATION_TTL, 60) . ' minutes.');
    if (!$sent['ok']) {
        return "END Saved, but the verification SMS failed.\n"
            . "Please retry from the website to receive your code.";
    }

    $lines = [
        "END Subscribed to {$commodityName} {$frequency} alerts.",
        "Ref {$result['subscription_id']} for {$phone}.",
    ];
    if ($sent['simulated'] && sms_sandbox_secrets_visible()) {
        $lines[] = "Local sandbox code: {$code}";
        $lines[] = 'Verify on the website to activate alerts.';
    } else {
        $lines[] = 'A verification code is on its way by SMS.';
    }
    return implode("\n", $lines);
}

// --- shared menu rendering --------------------------------------------------

/**
 * Rebuilds the screen for a stored step, so the * (back) key never lands the
 * user on a stripped-down prompt with no way to answer it.
 */
function ussd_render(PDO $pdo, array &$session, string $menu): string
{
    $state = $session['state'] ?? [];
    $phone = (string) ($state['phone'] ?? $session['phone_number'] ?? '');
    $page = max(0, (int) ($state['page'] ?? 0));
    $frequency = (string) ($state['frequency'] ?? 'daily');

    return match ($menu) {
        'price_menu' => ussd_commodity_menu(ussd_commodity_list($pdo), $page, "CON MPIDS price check\nSelect commodity:"),
        'subscribe_phone' => "CON SMS price alerts\nEnter your phone number\n(e.g. 0712345678)",
        'subscribe_commodity' => ussd_commodity_menu(ussd_commodity_list($pdo), $page, "CON Alerts for {$phone}\nSelect commodity:"),
        'subscribe_frequency' => "CON How often should we alert you?\n1. Daily\n2. Weekly\nReply 1 or 2.",
        'subscribe_confirm' => "CON Confirm subscription\n"
            . "Commodity: " . (string) ($state['commodity_name'] ?? '') . ' (' . (string) ($state['unit'] ?? '') . ")\n"
            . "Phone: {$phone}\n"
            . "Frequency: {$frequency}\n"
            . "1. Confirm\n2. Cancel",
        default => "CON MPIDS Market Info Hub\n1. Check commodity prices\n2. Subscribe to SMS price alerts\nReply with a number.",
    };
}

/** Only the entries visible on $page may be chosen, so 9 is not "the ninth". */
function ussd_pick_commodity(array $commodities, int $page, int $choice): ?array
{
    if ($choice < 1) {
        return null;
    }
    $start = $page * MPIDS_USSD_COMMODITIES_PER_PAGE;
    $displayed = min(MPIDS_USSD_COMMODITIES_PER_PAGE, count($commodities) - $start);
    if ($displayed <= 0 || $choice > $displayed) {
        return null;
    }
    return $commodities[$start + $choice - 1];
}

function ussd_commodity_menu(array $commodities, int $page, string $heading): string
{
    $totalPages = max(1, (int) ceil(count($commodities) / MPIDS_USSD_COMMODITIES_PER_PAGE));
    $page = max(0, min($page, $totalPages - 1));
    $slice = array_slice($commodities, $page * MPIDS_USSD_COMMODITIES_PER_PAGE, MPIDS_USSD_COMMODITIES_PER_PAGE);

    $lines = [$heading];
    foreach ($slice as $index => $commodity) {
        $lines[] = ($index + 1) . '. ' . $commodity['name'];
    }
    if (($page + 1) < $totalPages) {
        $lines[] = '0. More';
    }
    return implode("\n", $lines);
}

/** Active commodity list, cached for 60s (hot read). */
function ussd_commodity_list(PDO $pdo): array
{
    $cached = cache_get('ussd.commodities');
    if ($cached !== null) {
        return $cached;
    }
    $rows = $pdo->query(
        "SELECT id, name, unit_of_measure FROM commodities WHERE is_active = 1 ORDER BY name ASC LIMIT 100"
    )->fetchAll() ?: [];
    cache_set('ussd.commodities', $rows, 60);
    return $rows;
}

function ussd_commodity_price_response(PDO $pdo, array $commodity): string
{
    $stmt = $pdo->prepare(
        "SELECT pr.price, pr.currency, pr.date_observed, m.name AS market_name
         FROM price_records pr
         JOIN commodities c ON c.id = pr.commodity_id AND c.is_active = 1
         JOIN markets m ON m.id = pr.market_id AND m.is_active = 1
         WHERE pr.commodity_id = ? AND pr.status = 'published'
         ORDER BY pr.date_observed DESC, pr.id DESC
         LIMIT 1"
    );
    $stmt->execute([(int) $commodity['id']]);
    $record = $stmt->fetch();

    if (!$record) {
        return "END No published price for {$commodity['name']} yet.";
    }

    return sprintf(
        "END %s (%s)\n%s %s\n%s\n%s",
        $commodity['name'],
        $commodity['unit_of_measure'],
        $record['currency'],
        number_format((float) $record['price'], 2),
        $record['market_name'],
        date('d M Y', strtotime((string) $record['date_observed']))
    );
}
