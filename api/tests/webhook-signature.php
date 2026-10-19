<?php
declare(strict_types=1);

/**
 * Unit checks for the MobileSasa webhook gate in api/handlers/ussd.php.
 * No server or database needed:  php tests/webhook-signature.php
 *
 * Covers the two headers MobileSasa can send (X-MobileSasa-Secret and
 * X-MobileSasa-Signature), the five-minute replay window, and the loopback
 * exemption that keeps the in-house USSD simulator working.
 */

putenv('MOBILESASA_WEBHOOK_SECRET=whsec_unit_test');
if (!defined('MOBILESASA_WEBHOOK_SECRET')) {
    define('MOBILESASA_WEBHOOK_SECRET', 'whsec_unit_test');
}
require_once dirname(__DIR__) . '/handlers/ussd.php';

$secret = 'whsec_unit_test';
$body = 'sessionId=ATUid_8d2c31&phoneNumber=254712345678&text=1';
$pass = 0;
$fail = 0;

function expect(string $name, bool $want, bool $got): void
{
    global $pass, $fail;
    if ($want === $got) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

function reset_remote(string $ip = '203.0.113.9'): void
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_MOBILESASA_SECRET'], $_SERVER['HTTP_X_MOBILESASA_SIGNATURE']);
}

// 1. valid HMAC signature over the exact raw body
$t = time();
$sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
reset_remote();
$_SERVER['HTTP_X_MOBILESASA_SIGNATURE'] = $sig;
expect('valid signature accepted', true, mobilesasa_webhook_authorized($body));

// 2. tampered body rejected
reset_remote();
$_SERVER['HTTP_X_MOBILESASA_SIGNATURE'] = $sig;
expect('tampered body rejected', false, mobilesasa_webhook_authorized($body . 'x'));

// 3. expired timestamp rejected (replay window)
$old = time() - 600;
$oldSig = 't=' . $old . ',v1=' . hash_hmac('sha256', $old . '.' . $body, $secret);
reset_remote();
$_SERVER['HTTP_X_MOBILESASA_SIGNATURE'] = $oldSig;
expect('stale timestamp rejected', false, mobilesasa_webhook_authorized($body));

// 4. plain secret header accepted
reset_remote();
$_SERVER['HTTP_X_MOBILESASA_SECRET'] = $secret;
expect('secret header accepted', true, mobilesasa_webhook_authorized($body));

// 5. wrong secret rejected
reset_remote();
$_SERVER['HTTP_X_MOBILESASA_SECRET'] = 'whsec_wrong';
expect('wrong secret rejected', false, mobilesasa_webhook_authorized($body));

// 6. unsigned remote request rejected while a secret is configured
reset_remote();
expect('unsigned remote rejected', false, mobilesasa_webhook_authorized($body));

// 7. loopback (the in-house USSD simulator) stays exempt
reset_remote('127.0.0.1');
expect('loopback exempt', true, mobilesasa_webhook_authorized($body));

// 8. the pure signature helper
$good = hash_hmac('sha256', $t . '.' . $body, $secret);
expect('helper accepts good pair', true, mobilesasa_signature_valid($secret, 't=' . $t . ',v1=' . $good, $body, $t));
expect('helper rejects bad hex', false, mobilesasa_signature_valid($secret, 't=' . $t . ',v1=deadbeef', $body, $t));
expect('helper rejects missing v1', false, mobilesasa_signature_valid($secret, 't=' . $t, $body, $t));
expect('helper rejects replay window', false, mobilesasa_signature_valid($secret, 't=' . ($t - 301) . ',v1=' . $good, $body, $t));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
