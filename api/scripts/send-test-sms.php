<?php
declare(strict_types=1);

/**
 * Sends one real SMS through the configured gateway — the fastest way to confirm
 * that credentials, the username, and the sender ID all work before relying on
 * the subscribe → verify → publish flow.
 *
 *   php scripts/send-test-sms.php 0712345678
 *   php scripts/send-test-sms.php +254712345678 "Custom message"
 *
 * The number is normalised by the same helper the app uses, so it also confirms
 * the gateway accepts the canonical +254… form. Costs one real SMS.
 */

$root = dirname(__DIR__);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require_once $root . '/config/env.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/sms.php';
require_once $root . '/config/config.php';

$raw = trim((string) ($argv[1] ?? ''));
if ($raw === '') {
    exit("Usage: php scripts/send-test-sms.php <phone> [message]\n");
}
$message = trim((string) ($argv[2] ?? '')) ?: 'MPIDS test message. Real SMS is working.';

$phone = normalize_phone($raw);
if ($phone === null) {
    exit("Not a Kenyan mobile number: {$raw}\n");
}

$provider = sms_provider();
$mode = sms_gateway_simulated() ? 'simulation' : 'LIVE';
if ($provider === 'infobip') {
    $endpoint = (defined('INFOBIP_BASE_URL') ? (string) INFOBIP_BASE_URL : '(unset)') . '/sms/2/text/advanced';
    $sender = env('INFOBIP_SENDER_ID') ?: '(default)';
} else {
    $endpoint = (defined('MOBILESASA_API_URL') ? (string) MOBILESASA_API_URL : 'https://api.mobilesasa.com') . '/v1/send/message';
    $sender = defined('MOBILESASA_SENDER_ID') ? (string) MOBILESASA_SENDER_ID : env('MOBILESASA_SENDER_ID', 'MOBILESASA');
}
echo "Provider:{$provider}\n";
echo "Mode:    {$mode}\n";
echo "To:      {$phone}\n";
echo "Sender:  " . ($sender ?: '(default)') . "\n";
echo "Endpoint:{$endpoint}\n\n";

if (sms_gateway_simulated()) {
    if ($provider === 'infobip') {
        echo "INFOBIP_API_KEY / INFOBIP_BASE_URL are empty, so nothing will leave this machine.\n";
        echo "Add your live credentials to .env to send a real message:\n";
        echo "  INFOBIP_BASE_URL=https://<your-instance>.api.infobip.com\n  INFOBIP_API_KEY=<your API key>\n";
    } else {
        echo "MOBILESASA_TOKEN is empty, so nothing will leave this machine.\n";
        echo "Add your MobileSasa account token to .env to send a real message:\n";
        echo "  MOBILESASA_TOKEN=mbs_...   (API -> Tokens at account.mobilesasa.com)\n";
        echo "  MOBILESASA_SENDER_ID=YOURSENDER\n";
    }
    echo "Then re-run this script. See the project's .env.example.\n";
    exit(1);
}

$result = sms_send($phone, $message);
if ($result['ok']) {
    if ($provider === 'infobip') {
        echo "Accepted by Infobip. Delivery is confirmed by the network afterwards —\n";
        echo "check the Infobip portal (Logs) if nothing arrives on the handset.\n";
    } else {
        echo "Accepted by MobileSasa. Delivery is confirmed by the network afterwards —\n";
        echo "check the portal (account.mobilesasa.com) if nothing arrives on the handset.\n";
    }
    exit(0);
}

echo "Failed: " . ($result['error'] ?? 'unknown error') . "\n";
if ($provider === 'infobip') {
    echo "Common causes: a wrong base URL (must end in .api.infobip.com), a revoked\n";
    echo "API key, an unapproved sender ID, or a number outside coverage.\n";
} else {
    echo "Common causes: a revoked token, a sender ID your account does not own,\n";
    echo "an empty wallet (0402), a duplicate send (0409), or a number outside coverage.\n";
}
exit(1);
