<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

/**
 * SMS gateways.
 *
 * MobileSasa (account.mobilesasa.com) is the default provider:
 *   MOBILESASA_TOKEN      – the mbs_ API token created in the portal
 *   MOBILESASA_SENDER_ID  – an approved sender ID (defaults to MOBILESASA,
 *                           which every new account may trial)
 *   MOBILESASA_API_URL    – defaults to https://api.mobilesasa.com
 *
 * Infobip (portal.infobip.com) remains available via SMS_PROVIDER=infobip:
 *   INFOBIP_BASE_URL  – your instance base URL, e.g. https://xxxxx.api.infobip.com
 *   INFOBIP_API_KEY   – the API key value
 *   INFOBIP_SENDER_ID – optional approved alphanumeric sender ID
 *
 * When the active provider's credentials are absent the gateway runs in
 * simulation mode: nothing leaves the machine and messages are appended to
 * tmp/logs/sms-sim.log so the workflow stays testable.
 */

/**
 * The provider that sends right now: SMS_PROVIDER wins when set, otherwise
 * MobileSasa is preferred whenever its token is present, then Infobip.
 */
function sms_provider(): string
{
    $explicit = defined('SMS_PROVIDER') ? (string) SMS_PROVIDER : strtolower(env('SMS_PROVIDER', ''));
    if (in_array($explicit, ['mobilesasa', 'infobip'], true)) {
        return $explicit;
    }
    if (defined('MOBILESASA_TOKEN') ? (string) MOBILESASA_TOKEN !== '' : env('MOBILESASA_TOKEN') !== '') {
        return 'mobilesasa';
    }
    if (env('INFOBIP_API_KEY') !== '' && env('INFOBIP_BASE_URL') !== '') {
        return 'infobip';
    }
    return 'mobilesasa';
}

function sms_gateway_simulated(): bool
{
    if (sms_provider() === 'infobip') {
        return env('INFOBIP_API_KEY') === '' || env('INFOBIP_BASE_URL') === '';
    }
    $token = defined('MOBILESASA_TOKEN') ? (string) MOBILESASA_TOKEN : env('MOBILESASA_TOKEN');
    return $token === '';
}

/**
 * Sandbox OTPs and unsubscribe tokens are development secrets: they bypass SMS
 * entirely, so they are only ever echoed back to a loopback client. Any request
 * that reaches the app from a real network address gets no sandbox secrets.
 */
function sms_sandbox_secrets_visible(): bool
{
    if (!sms_gateway_simulated()) {
        return false;
    }
    if (env('MPIDS_SHOW_SANDBOX_SECRETS') === 'true') {
        return true;
    }

    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    $hostname = trim($host, '[]');
    $localHost = $hostname === '' || in_array($hostname, ['localhost', '127.0.0.1', '::1'], true);
    if (!$localHost) {
        return false;
    }

    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remote === ''
        || $remote === '::1'
        || $remote === '127.0.0.1'
        || str_starts_with($remote, '127.');
}

function sms_sim_log(string $phone): void
{
    $dir = dirname(__DIR__) . '/tmp/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/sms-sim.log', '[' . date('c') . '] TO ' . $phone . ': simulated SMS' . PHP_EOL, FILE_APPEND);
}

/**
 * Split a message into parts of <=160 characters (GSM-7 single-SMS limit).
 */
function sms_split_message(string $message): array
{
    $message = trim($message);
    if ($message === '') {
        return [];
    }
    $parts = [];
    foreach (mb_str_split($message, 160) as $part) {
        $parts[] = $part;
    }
    return $parts;
}

/**
 * Sends one message (all parts), retrying failed parts up to SMS_MAX_RETRIES times.
 * Returns ['ok' => bool, 'simulated' => bool, 'error' => ?string].
 */
function sms_send(string $phone, string $message): array
{
    if (sms_gateway_simulated()) {
        sms_sim_log($phone);
        return ['ok' => true, 'simulated' => true, 'error' => null];
    }

    $parts = sms_split_message($message);
    if (!$parts) {
        return ['ok' => false, 'simulated' => false, 'error' => 'Empty message'];
    }

    $lastError = null;
    $maxRetries = defined('SMS_MAX_RETRIES') ? (int) SMS_MAX_RETRIES : 3;
    foreach ($parts as $index => $part) {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $result = sms_send_part($phone, $part, count($parts), $index + 1);
            if ($result === null) {
                continue 2; // part delivered
            }
            $lastError = $result;
            usleep(300000); // small backoff before retry
        }
        return ['ok' => false, 'simulated' => false, 'error' => $lastError ?? 'Send failed'];
    }
    return ['ok' => true, 'simulated' => false, 'error' => null];
}

/** Returns null on success or an error string. */
function sms_send_part(string $phone, string $text, int $total, int $part): ?string
{
    if (sms_provider() === 'infobip') {
        return sms_send_part_infobip($phone, $text, $total, $part);
    }
    return sms_send_part_mobilesasa($phone, $text, $total, $part);
}

/** Returns null on success or an error string. */
function sms_send_part_mobilesasa(string $phone, string $text, int $total, int $part): ?string
{
    $token = defined('MOBILESASA_TOKEN') ? (string) MOBILESASA_TOKEN : (string) env('MOBILESASA_TOKEN', '');
    $sender = defined('MOBILESASA_SENDER_ID') ? (string) MOBILESASA_SENDER_ID : (string) env('MOBILESASA_SENDER_ID', 'MOBILESASA');
    // config/config.php resolves MOBILESASA_API_URL from the .env value, so a
    // missing token never sends traffic anywhere. defined() keeps this file
    // usable from a script that only pulled in sms.php.
    $baseUrl = defined('MOBILESASA_API_URL') ? rtrim((string) MOBILESASA_API_URL, '/') : 'https://api.mobilesasa.com';
    if ($baseUrl === '' || $token === '') {
        return 'MobileSasa token missing (part ' . $part . '/' . $total . ')';
    }
    if ($sender === '') {
        return 'MobileSasa sender ID missing (part ' . $part . '/' . $total . ')';
    }

    $payload = json_encode([
        'senderID' => $sender,
        'phone' => $phone,
        'message' => $text,
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ]),
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($baseUrl . '/v1/send/message', false, $context);
    if ($response === false) {
        return 'Network error contacting MobileSasa (part ' . $part . '/' . $total . ')';
    }
    $json = json_decode($response, true);
    if (!is_array($json)) {
        return 'Malformed MobileSasa response (part ' . $part . '/' . $total . ')';
    }
    // 0200 = accepted by MobileSasa for delivery; the carrier confirms later.
    if (($json['status'] ?? false) === true || (string) ($json['responseCode'] ?? '') === '0200') {
        return null;
    }
    $code = (string) ($json['responseCode'] ?? 'UNKNOWN');
    $message = (string) ($json['message'] ?? 'no description');
    return 'MobileSasa status: ' . $code . ' — ' . $message . ' (part ' . $part . '/' . $total . ')';
}

/** Returns null on success or an error string. */
function sms_send_part_infobip(string $phone, string $text, int $total, int $part): ?string
{
    $apiKey = (string) env('INFOBIP_API_KEY', '');
    $sender = (string) env('INFOBIP_SENDER_ID', '');
    // config/config.php resolves INFOBIP_BASE_URL from the .env value, so a
    // missing base URL never sends traffic anywhere. Defined() keeps this file
    // usable from a script that only pulled in sms.php.
    $baseUrl = defined('INFOBIP_BASE_URL') ? rtrim((string) INFOBIP_BASE_URL, '/') : '';
    if ($baseUrl === '' || $apiKey === '') {
        return 'Infobip credentials missing (part ' . $part . '/' . $total . ')';
    }

    $payload = json_encode([
        'messages' => [
            array_filter([
                'from' => $sender !== '' ? $sender : null,
                'destinations' => [['to' => $phone]],
                'text' => $text,
            ], static fn ($v) => $v !== null),
        ],
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: App ' . $apiKey,
            ]),
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($baseUrl . '/sms/2/text/advanced', false, $context);
    if ($response === false) {
        return 'Network error contacting Infobip (part ' . $part . '/' . $total . ')';
    }
    $json = json_decode($response, true);
    $message = is_array($json) ? ($json['messages'][0] ?? null) : null;
    if (!is_array($message)) {
        return 'Malformed Infobip response (part ' . $part . '/' . $total . ')';
    }
    $status = is_array($message['status'] ?? null) ? $message['status'] : [];
    $groupName = strtoupper((string) ($status['groupName'] ?? ''));
    // PENDING = accepted by Infobip for delivery; DELIVERED = already at the handset.
    if (in_array($groupName, ['PENDING', 'DELIVERED'], true)) {
        return null;
    }
    $name = (string) ($status['name'] ?? 'UNKNOWN');
    $description = (string) ($status['description'] ?? 'no description');
    return 'Infobip status: ' . $name . ' — ' . $description . ' (part ' . $part . '/' . $total . ')';
}

/**
 * Alerts verified subscribers of a commodity about a newly published price.
 * Fires only on the transition into published. Failures are recorded in the
 * append-only audit log for admin attention.
 */
function sms_alert_publication(PDO $pdo, array $record, array $commodity): array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.phone_number, s.frequency, s.last_alert_at
         FROM sms_subscriptions s
         WHERE s.commodity_id = ? AND s.is_verified = 1'
    );
    $stmt->execute([(int) $record['commodity_id']]);
    $subscribers = $stmt->fetchAll() ?: [];

    $results = [
        'sent' => 0,
        'failed' => 0,
        'simulated' => sms_gateway_simulated(),
        'subscribers' => count($subscribers),
        'eligible' => 0,
        'claimed' => 0,
        'skipped' => 0,
        'restore_failures' => 0,
    ];

    if (!$subscribers) {
        return $results;
    }

    $eligibility = "is_verified = 1 AND (
            last_alert_at IS NULL
            OR (frequency = 'daily' AND last_alert_at <= NOW() - INTERVAL 1 DAY)
            OR (frequency = 'weekly' AND last_alert_at <= NOW() - INTERVAL 7 DAY)
        )";
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sms_subscriptions
         WHERE commodity_id = ? AND $eligibility"
    );
    $stmt->execute([(int) $record['commodity_id']]);
    $results['eligible'] = (int) $stmt->fetchColumn();

    require_once __DIR__ . '/audit.php';
    $message = sprintf(
        'MPIDS %s price: %s %s/%s at %s on %s',
        $commodity['name'],
        number_format((float) $record['price'], 2),
        $record['currency'],
        $commodity['unit_of_measure'],
        $record['market_name'],
        date('d M Y', strtotime($record['date_observed']))
    );

    $claimedAt = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $claim = $pdo->prepare(
        "UPDATE sms_subscriptions
         SET last_alert_at = ?
         WHERE id = ? AND $eligibility"
    );
    $restore = $pdo->prepare(
        'UPDATE sms_subscriptions
         SET last_alert_at = ?
         WHERE id = ? AND is_verified = 1 AND last_alert_at = ?'
    );

    foreach ($subscribers as $sub) {
        $claim->execute([$claimedAt, (int) $sub['id']]);
        if ($claim->rowCount() !== 1) {
            $results['skipped']++;
            continue;
        }
        $results['claimed']++;

        try {
            $send = sms_send($sub['phone_number'], $message);
            $delivered = (bool) $send['ok'];
            $error = $send['error'];
        } catch (Throwable $e) {
            $delivered = false;
            $error = 'SMS gateway raised an error.';
        }

        if ($delivered) {
            $results['sent']++;
            continue;
        }

        $restore->execute([$sub['last_alert_at'], (int) $sub['id'], $claimedAt]);
        if ($restore->rowCount() !== 1) {
            $results['restore_failures']++;
        }
        $results['failed']++;
        audit_log(
            $pdo,
            null,
            'sms.send_failed',
            'sms_subscriptions',
            (int) $sub['id'],
            null,
            ['phone_number' => $sub['phone_number'], 'error' => $error, 'message' => $message],
            $_SERVER['REMOTE_ADDR'] ?? null
        );
    }
    return $results;
}
