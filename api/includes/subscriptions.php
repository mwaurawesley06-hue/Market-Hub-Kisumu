<?php
declare(strict_types=1);

require_once __DIR__ . '/sms.php';

/**
 * Shared SMS-subscription service.
 *
 * The web form (api/handlers/subscriptions.php) and the USSD simulator
 * (api/handlers/ussd.php) are two doors into the same doorbell, so both call
 * mpids_create_sms_subscription() here instead of owning their own INSERT.
 */

/** A subscription could not be created or refreshed; carries the status to answer with. */
final class MpidsSubscriptionError extends RuntimeException
{
    public function __construct(
        string $detail,
        public readonly int $status = 422,
        public readonly array $errors = [],
        public readonly int $retryAfter = 0
    ) {
        parent::__construct($detail);
    }
}

/** How long a verification code stays usable, in seconds. */
const MPIDS_VERIFICATION_TTL = 1800;

/** Minimum gap between two verification codes for the same subscription, in seconds. */
const MPIDS_VERIFICATION_RESEND_COOLDOWN = 60;

/** Alphanumeric sender id / token helpers, shared by verification and unsubscribe. */
function new_unsubscribe_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function hash_unsubscribe_token(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Validates a subscribe payload against the reference data.
 * Returns [errors, normalized]; errors is empty when the payload is usable.
 */
function mpids_validate_subscription(PDO $pdo, array $body): array
{
    $errors = [];

    $phone = normalize_phone((string) ($body['phone_number'] ?? ''));
    if ($phone === null) {
        $errors['phone_number'] = 'Enter a valid Kenyan phone number (e.g. 0712345678, 0112345678, 254712345678 or +254712345678).';
    }

    $commodityId = $body['commodity'] ?? null;
    $commodity = null;
    if ($commodityId === null || !ctype_digit((string) $commodityId)) {
        $errors['commodity'] = 'Select a commodity.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM commodities WHERE id = ? AND is_active = 1');
        $stmt->execute([(int) $commodityId]);
        $commodity = $stmt->fetch();
        if (!$commodity) {
            $errors['commodity'] = 'Unknown or inactive commodity.';
        }
    }

    $frequency = strtolower(trim((string) ($body['frequency'] ?? '')));
    if (!in_array($frequency, ['daily', 'weekly'], true)) {
        $errors['frequency'] = 'Frequency must be daily or weekly.';
    }

    if ($errors) {
        return [$errors, []];
    }

    return [[], [
        'phone_number' => $phone,
        'commodity_id' => (int) $commodity['id'],
        'commodity_name' => (string) $commodity['name'],
        'frequency' => $frequency,
    ]];
}

/**
 * Creates — or re-issues the code for — one subscription and returns the
 * freshly minted verification code.
 *
 * Throws MpidsSubscriptionError for validation failures, the resend cooldown
 * and duplicate races; callers translate that into their own response format.
 *
 * $normalized comes from mpids_validate_subscription(). $source labels the
 * channel the subscription came from ("web" or "ussd") in the audit trail.
 */
function mpids_create_sms_subscription(PDO $pdo, array $normalized, string $ipAddress = '', string $source = 'web'): array
{
    $phone = (string) $normalized['phone_number'];
    $commodityId = (int) $normalized['commodity_id'];
    $commodityName = (string) $normalized['commodity_name'];
    $frequency = (string) $normalized['frequency'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, is_verified, last_verification_sent_at
             FROM sms_subscriptions
             WHERE phone_number = ? AND commodity_id = ? AND frequency = ?
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$phone, $commodityId, $frequency]);
        $existing = $stmt->fetch();

        if ($existing && (int) $existing['is_verified'] === 1) {
            $subscriptionId = (int) $existing['id'];
            $pdo->commit();
            return [
                'subscription_id' => $subscriptionId,
                'already_verified' => true,
                'code' => null,
                'phone_number' => $phone,
                'commodity_name' => $commodityName,
                'frequency' => $frequency,
            ];
        }

        if ($existing && $existing['last_verification_sent_at'] !== null) {
            $sentAt = strtotime((string) $existing['last_verification_sent_at']);
            $elapsed = $sentAt === false ? MPIDS_VERIFICATION_RESEND_COOLDOWN : max(0, time() - $sentAt);
            if ($elapsed < MPIDS_VERIFICATION_RESEND_COOLDOWN) {
                $retryAfter = max(1, MPIDS_VERIFICATION_RESEND_COOLDOWN - $elapsed);
                $pdo->rollBack();
                throw new MpidsSubscriptionError(
                    'Please wait before requesting another verification code.',
                    429,
                    [],
                    $retryAfter
                );
            }
        }

        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $expires = date('Y-m-d H:i:s', time() + MPIDS_VERIFICATION_TTL);

        if ($existing) {
            $subscriptionId = (int) $existing['id'];
            $stmt = $pdo->prepare(
                'UPDATE sms_subscriptions
                 SET verification_code_hash = ?, verification_expires = ?, unsubscribe_token = NULL,
                     verification_attempts = 0, last_verification_sent_at = NOW(), is_verified = 0,
                     verified_at = NULL, last_alert_at = NULL
                 WHERE id = ?'
            );
            $stmt->execute([$codeHash, $expires, $subscriptionId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO sms_subscriptions
                     (phone_number, commodity_id, frequency, is_verified, verification_code_hash,
                      verification_expires, unsubscribe_token, verification_attempts,
                      last_verification_sent_at, verified_at, last_alert_at)
                 VALUES (?, ?, ?, 0, ?, ?, NULL, 0, NOW(), NULL, NULL)'
            );
            $stmt->execute([$phone, $commodityId, $frequency, $codeHash, $expires]);
            $subscriptionId = (int) $pdo->lastInsertId();
        }

        require_once __DIR__ . '/audit.php';
        audit_log(
            $pdo,
            null,
            $existing ? 'resend' : 'create',
            'sms_subscriptions',
            $subscriptionId,
            $existing ? ['is_verified' => (int) $existing['is_verified']] : null,
            [
                'phone_number' => $phone,
                'commodity' => $commodityName,
                'frequency' => $frequency,
                'status' => 'pending verification',
                'source' => $source,
            ],
            $ipAddress
        );
        $pdo->commit();
    } catch (MpidsSubscriptionError $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            throw new MpidsSubscriptionError(
                'This phone, commodity, and frequency subscription is already being processed.',
                409
            );
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'subscription_id' => $subscriptionId,
        'already_verified' => false,
        'code' => $code,
        'phone_number' => $phone,
        'commodity_name' => $commodityName,
        'frequency' => $frequency,
    ];
}
