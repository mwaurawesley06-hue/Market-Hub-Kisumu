<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require_once $root . '/config/env.php';
require_once $root . '/config/config.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/sms.php';

/** POSTs/GETs JSON to MobileSasa and returns [httpStatus, rawBody]. */
function ms_http(string $method, string $url, array $headers, ?string $payload = null): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload ?? '',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, $raw === false ? '' : $raw];
}

function ms_token(): string
{
    return defined('MOBILESASA_TOKEN') ? (string) MOBILESASA_TOKEN : (string) env('MOBILESASA_TOKEN', '');
}

function ms_missing_token(): never
{
    fwrite(
        STDERR,
        "MOBILESASA_TOKEN is empty, so there is no link to check.\n" .
        "Create an API token at https://account.mobilesasa.com/dashboard\n" .
        "(API -> Tokens), then set MOBILESASA_TOKEN=mbs_... in .env.\n"
    );
    exit(1);
}

$command = strtolower((string) ($argv[1] ?? ''));

// --- status + balance -------------------------------------------------------
if ($command === '') {
    $token = ms_token();
    $secret = defined('MOBILESASA_WEBHOOK_SECRET') ? (string) MOBILESASA_WEBHOOK_SECRET : (string) env('MOBILESASA_WEBHOOK_SECRET', '');

    echo "Provider: " . sms_provider() . "\n";
    echo "Mode:     " . (sms_gateway_simulated() ? 'simulation (no token)' : 'LIVE') . "\n";
    echo "API:      " . (defined('MOBILESASA_API_URL') ? (string) MOBILESASA_API_URL : 'https://api.mobilesasa.com') . "\n";
    echo "Sender:   " . (defined('MOBILESASA_SENDER_ID') ? (string) MOBILESASA_SENDER_ID : env('MOBILESASA_SENDER_ID', 'MOBILESASA')) . "\n";
    echo "Token:    " . ($token !== '' ? 'set (' . substr($token, 0, 4) . '…)' : 'NOT SET') . "\n";
    echo "Webhook:  " . ($secret !== '' ? 'signature verification ON' : 'verification off (set MOBILESASA_WEBHOOK_SECRET)') . "\n";
    echo "USSD:     callback URL must point at https://<your-host>/api/ussd\n\n";

    if ($token === '') {
        ms_missing_token();
    }

    $api = defined('MOBILESASA_API_URL') ? rtrim((string) MOBILESASA_API_URL, '/') : 'https://api.mobilesasa.com';
    [$status, $raw] = ms_http('GET', $api . '/v1/get-balance/', [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ]);
    $json = json_decode($raw, true);
    if ($status === 200 && is_array($json) && ($json['status'] ?? false) === true) {
        echo 'Balance:  ' . (int) ($json['balance'] ?? 0) . " SMS parts\n";
        echo "Token accepted by MobileSasa — the SMS side of the link is live.\n";
        exit(0);
    }
    echo "Balance check FAILED (HTTP {$status}): " . trim($raw) . "\n";
    echo "Common causes: a revoked or mistyped token (must start with mbs_).\n";
    exit(1);
}

// --- one real SMS -----------------------------------------------------------
if ($command === 'sms') {
    if (ms_token() === '') {
        ms_missing_token();
    }
    $raw = trim((string) ($argv[2] ?? ''));
    if ($raw === '') {
        exit("Usage: php scripts/check-mobilesasa.php sms <phone> [message]\n");
    }
    $phone = normalize_phone($raw);
    if ($phone === null) {
        exit("Not a Kenyan mobile number: {$raw}\n");
    }
    $message = trim((string) ($argv[3] ?? '')) ?: 'MPIDS test message. MobileSasa SMS is working.';

    $result = sms_send($phone, $message);
    if ($result['ok']) {
        echo ($result['simulated'] ? "Simulation mode — nothing left this machine. Set MOBILESASA_TOKEN.\n" : "Accepted by MobileSasa for {$phone}.\n");
        exit($result['simulated'] ? 1 : 0);
    }
    echo 'Failed: ' . ($result['error'] ?? 'unknown error') . "\n";
    exit(1);
}

// --- signed USSD test-callback ----------------------------------------------
if ($command === 'ussd') {
    if (ms_token() === '') {
        ms_missing_token();
    }
    $url = trim((string) ($argv[2] ?? ''));
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        exit("Usage: php scripts/check-mobilesasa.php ussd https://your.domain/api/ussd\n");
    }

    $api = defined('MOBILESASA_API_URL') ? rtrim((string) MOBILESASA_API_URL, '/') : 'https://api.mobilesasa.com';
    [$status, $raw] = ms_http('POST', $api . '/api/v1/ussd/test-callback', [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . ms_token(),
    ], json_encode(['callback_url' => $url]));

    $json = json_decode($raw, true);
    $data = is_array($json) ? ($json['data'] ?? null) : null;
    if ($status === 200 && is_array($data) && ($data['ok'] ?? false) === true) {
        echo "Callback reachable: HTTP {$data['status_code']} in {$data['ms']} ms\n";
        echo "Rendered screen:\n" . (string) ($data['body'] ?? '') . "\n";
        echo "Your USSD endpoint answered MobileSasa — the USSD link is live.\n";
        echo "Make sure the same URL is saved on the USSD code/extension page in the portal.\n";
        exit(0);
    }
    echo "USSD test FAILED (HTTP {$status}): " . trim($raw) . "\n";
    echo "ok=false means the URL was unreachable, answered non-2xx, or took over\n";
    echo "10 seconds — check that the host is public HTTPS and /api/ussd is live.\n";
    exit(1);
}

exit("Usage: php scripts/check-mobilesasa.php [sms <phone> [message] | ussd <callback-url>]\n");
