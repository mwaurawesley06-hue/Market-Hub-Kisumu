<?php
declare(strict_types=1);

function handle_subscriptions(string $method, ?string $action, array $body, ?array $user): void
{
    $pdo = db();

    // POST /api/subscriptions/verify/ — public OTP verification
    if ($action === 'verify') {
        if ($method !== 'POST') {
            error_out(405, 'Method not allowed.');
        }
        verify_subscription($pdo, $body);
    }

    // GET — protected, admin reporting (subscriber phone numbers must never be public)
    if ($method === 'GET') {
        require_auth($pdo);
        $where = '';
        $params = [];
        if (!empty($_GET['commodity'])) {
            $where = 'WHERE s.commodity_id = ?';
            $params[] = (int) $_GET['commodity'];
        }
        $stmt = $pdo->prepare(
            "SELECT s.id, s.phone_number, s.commodity_id, c.name AS commodity_name,
                    s.frequency, s.is_verified, s.created_at
             FROM sms_subscriptions s
             JOIN commodities c ON c.id = s.commodity_id
             $where
             ORDER BY s.created_at DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
        if (($_GET['export'] ?? '') === 'csv') {
            export_subscribers_csv($rows);
        }
        json_out($rows);
    }

    // POST — public subscribe (no login)
    if ($method === 'POST') {
        subscribe_for_alerts($pdo, $body);
    }

    // DELETE — public unsubscribe; requires the subscriber verification token
    if ($method === 'DELETE') {
        $phone = (string) ($_GET['phone_number'] ?? $body['phone_number'] ?? '');
        $token = (string) ($_GET['token'] ?? $body['token'] ?? '');
        unsubscribe_with_token($pdo, $phone, $token);
    }

    error_out(405, 'Method not allowed.');
}

function export_subscribers_csv(array $rows): never
{
    $filename = 'mpids-subscribers-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Phone Number', 'Commodity', 'Frequency', 'Verified', 'Subscribed At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['phone_number'],
            $r['commodity_name'],
            $r['frequency'],
            (int) $r['is_verified'] === 1 ? 'verified' : 'pending',
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

function subscribe_for_alerts(PDO $pdo, array $body): void
{
    [$errors, $normalized] = mpids_validate_subscription($pdo, $body);
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    rate_limit($pdo, 'subscribe-ip:' . client_ip(), 10, 900);
    rate_limit($pdo, 'subscribe-phone:' . $normalized['phone_number'], 5, 3600);

    try {
        $result = mpids_create_sms_subscription($pdo, $normalized, client_ip(), 'web');
    } catch (MpidsSubscriptionError $e) {
        if ($e->retryAfter > 0) {
            header('Retry-After: ' . $e->retryAfter);
        }
        error_out($e->status, $e->getMessage(), $e->errors ? ['errors' => $e->errors] : []);
    }

    if ($result['already_verified']) {
        json_out([
            'detail' => "You are already verified for {$result['commodity_name']} {$result['frequency']} alerts.",
            'subscription_id' => $result['subscription_id'],
            'verification_required' => false,
        ]);
    }

    $phone = $result['phone_number'];
    $code = (string) $result['code'];
    $response = [
        'detail' => "Subscription created. Enter the 6-digit code sent to {$phone} to verify.",
        'subscription_id' => $result['subscription_id'],
        'verification_required' => true,
    ];

    $sent = sms_send($phone, "MPIDS: Your verification code is {$code}. Valid for " . intdiv(MPIDS_VERIFICATION_TTL, 60) . ' minutes.');
    if ($sent['simulated'] && sms_sandbox_secrets_visible()) {
        $response['sandbox_otp'] = $code;
    }

    json_out($response);
}

function verify_subscription(PDO $pdo, array $body): void
{
    $phone = normalize_phone((string) ($body['phone_number'] ?? ''));
    $code = preg_replace('/\D/', '', (string) ($body['code'] ?? $body['otp'] ?? ''));
    $subscriptionId = null;
    if (isset($body['subscription_id']) && $body['subscription_id'] !== '') {
        if (!ctype_digit((string) $body['subscription_id']) || (int) $body['subscription_id'] < 1) {
            $subscriptionId = false;
        } else {
            $subscriptionId = (int) $body['subscription_id'];
        }
    }

    $errors = [];
    if ($phone === null) {
        $errors['phone_number'] = 'Enter a valid phone number.';
    }
    if ($code === null || strlen($code) !== 6) {
        $errors['code'] = 'Enter the 6-digit verification code.';
    }
    if ($subscriptionId === false) {
        $errors['subscription_id'] = 'Enter a valid subscription id.';
    }
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    rate_limit($pdo, 'verify-ip:' . client_ip(), 30, 900);
    rate_limit($pdo, 'verify-phone:' . $phone, 10, 900);
    if ($subscriptionId !== null) {
        rate_limit($pdo, 'verify-subscription:' . $subscriptionId, 10, 900);
    }

    $pdo->beginTransaction();
    try {
        if ($subscriptionId !== null) {
            $sql = 'SELECT s.*, c.name AS commodity_name, c.unit_of_measure
                    FROM sms_subscriptions s
                    JOIN commodities c ON c.id = s.commodity_id
                    WHERE s.phone_number = ? AND s.id = ? AND s.is_verified = 0
                    LIMIT 1 FOR UPDATE';
            $params = [$phone, $subscriptionId];
        } else {
            $sql = 'SELECT s.*, c.name AS commodity_name, c.unit_of_measure
                    FROM sms_subscriptions s
                    JOIN commodities c ON c.id = s.commodity_id
                    WHERE s.phone_number = ? AND s.is_verified = 0
                    ORDER BY COALESCE(s.last_verification_sent_at, s.created_at) DESC, s.id DESC
                    LIMIT 1 FOR UPDATE';
            $params = [$phone];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pending = $stmt->fetch();
        if (!$pending) {
            $pdo->rollBack();
            error_out(404, 'No unverified subscription found for this phone number.');
        }

        $attempts = (int) ($pending['verification_attempts'] ?? 0);
        if ($attempts >= 5) {
            $pdo->rollBack();
            header('Retry-After: 900');
            error_out(429, 'Too many incorrect verification attempts. Request a new code.');
        }

        $expiresAt = $pending['verification_expires'] === null
            ? false
            : strtotime((string) $pending['verification_expires']);
        if ($expiresAt === false || $expiresAt <= time()) {
            $pdo->rollBack();
            error_out(422, 'Validation failed.', [
                'errors' => ['code' => 'This code has expired — subscribe again to receive a new one.'],
            ]);
        }

        if (!password_verify($code, (string) ($pending['verification_code_hash'] ?? ''))) {
            $attempts++;
            $stmt = $pdo->prepare(
                'UPDATE sms_subscriptions SET verification_attempts = ? WHERE id = ?'
            );
            $stmt->execute([$attempts, (int) $pending['id']]);
            $pdo->commit();
            if ($attempts >= 5) {
                header('Retry-After: 900');
                error_out(429, 'Too many incorrect verification attempts. Request a new code.');
            }
            error_out(422, 'Validation failed.', [
                'errors' => ['code' => 'Incorrect verification code.'],
            ]);
        }

        $token = new_unsubscribe_token();
        $stmt = $pdo->prepare(
            'UPDATE sms_subscriptions
             SET is_verified = 1, verified_at = NOW(), verification_code_hash = NULL,
                 verification_expires = NULL, verification_attempts = 0,
                 last_verification_sent_at = NULL, last_alert_at = NULL, unsubscribe_token = ?
             WHERE id = ? AND is_verified = 0'
        );
        $stmt->execute([hash_unsubscribe_token($token), (int) $pending['id']]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            error_out(409, 'Subscription state changed concurrently. Request a new code.');
        }

        audit_log(
            $pdo,
            null,
            'verify',
            'sms_subscriptions',
            (int) $pending['id'],
            ['is_verified' => 0],
            [
                'is_verified' => 1,
                'phone_number' => $pending['phone_number'],
                'commodity' => $pending['commodity_name'],
            ],
            client_ip()
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $message = "MPIDS: {$pending['commodity_name']} {$pending['frequency']} price alerts verified. Unsubscribe token: {$token}";
    $sent = sms_send($pending['phone_number'], $message);

    $response = [
        'detail' => "Verified! You will now receive {$pending['frequency']} {$pending['commodity_name']} price alerts.",
        'subscription_id' => (int) $pending['id'],
    ];
    if ($sent['simulated'] && sms_sandbox_secrets_visible()) {
        $response['sandbox_unsubscribe_token'] = $token;
    }
    json_out($response);
}

function unsubscribe_with_token(PDO $pdo, string $phoneRaw, string $token): void
{
    $phone = normalize_phone($phoneRaw);
    $token = trim($token);

    $errors = [];
    if ($phone === null) {
        $errors['phone_number'] = 'Enter a valid phone number.';
    }
    if ($token === '') {
        $errors['token'] = 'The unsubscribe token from your verification SMS is required.';
    }
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    rate_limit($pdo, 'unsubscribe-ip:' . client_ip(), 20, 900);
    rate_limit($pdo, 'unsubscribe-phone:' . $phone, 10, 900);
    rate_limit($pdo, 'unsubscribe-token:' . hash_unsubscribe_token($token), 20, 900);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT s.*, c.name AS commodity_name
             FROM sms_subscriptions s
             JOIN commodities c ON c.id = s.commodity_id
             WHERE s.phone_number = ?
             ORDER BY s.id
             FOR UPDATE'
        );
        $stmt->execute([$phone]);
        $subscriptions = $stmt->fetchAll() ?: [];
        $providedHash = hash_unsubscribe_token($token);
        $subscription = null;
        foreach ($subscriptions as $candidate) {
            $storedToken = (string) ($candidate['unsubscribe_token'] ?? '');
            if ($storedToken !== ''
                && (hash_equals($storedToken, $providedHash) || hash_equals($storedToken, $token))) {
                $subscription = $candidate;
                break;
            }
        }

        if (!$subscription) {
            $pdo->rollBack();
            error_out(404, 'No subscription matches this phone number and token.');
        }

        $stmt = $pdo->prepare('DELETE FROM sms_subscriptions WHERE id = ?');
        $stmt->execute([(int) $subscription['id']]);
        audit_log($pdo, null, 'delete', 'sms_subscriptions', (int) $subscription['id'], [
            'phone_number' => $subscription['phone_number'],
            'commodity' => $subscription['commodity_name'],
            'frequency' => $subscription['frequency'],
        ], null, client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    json_out(['detail' => "Unsubscribed from {$subscription['commodity_name']} alerts."]);
}
