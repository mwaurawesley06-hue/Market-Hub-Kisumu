<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

// The live .env uses the MPIDS_DB_* prefix; the older DB_* spelling is still
// accepted so an .env copied from .env.example keeps working.
function mpids_setting(string $prefixed, string $legacy, string $default): string
{
    $value = env($prefixed, '');
    if ($value === '') {
        $value = env($legacy, '');
    }
    return $value === '' ? $default : $value;
}

define('APP_NAME', env('MPIDS_APP_NAME', env('APP_NAME', 'MPIDS')));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Africa/Nairobi'));
define('SESSION_NAME', env('SESSION_NAME', 'MPIDSSESSID'));
// How long an untouched session may sit idle before PHP's garbage collector may
// delete it. PHP's default is 24 minutes, which signed admins out mid-task (for
// example while they were using a public page such as the SMS subscribe form).
define('SESSION_MAX_LIFETIME', max(300, (int) env('MPIDS_SESSION_MAX_LIFETIME', '28800')));
define('CORS_ORIGIN', env('CORS_ORIGIN', '*'));

// DATABASE_URL support (Render, Railway, Clever Cloud, etc). Platforms that
// provision a managed database hand out a single URL instead of discrete
// DB_* variables. Parse it first, then let MPIDS_DB_* / DB_* override so a
// self-hosted .env keeps working unchanged.
$mpidsDatabaseUrl = rtrim(env('DATABASE_URL', ''), " \t\n\r\0\x0B");
if ($mpidsDatabaseUrl !== '' && preg_match('#^(?:mysql|mysql2)://([^:/@]+)(:[^@]*)?@([^:/]+)(?::([0-9]+))?/([^?\s]+)#i', $mpidsDatabaseUrl, $mpidsDbMatch)) {
    $mpidsDbUser = rawurldecode($mpidsDbMatch[1]);
    $mpidsDbPass = isset($mpidsDbMatch[2]) ? rawurldecode(substr($mpidsDbMatch[2], 1)) : '';
    $mpidsDbHost = $mpidsDbMatch[3];
    $mpidsDbPort = (isset($mpidsDbMatch[4]) && $mpidsDbMatch[4] !== '') ? $mpidsDbMatch[4] : '3306';
    $mpidsDbName = rawurldecode($mpidsDbMatch[5]);
    putenv('MPIDS_DB_HOST=' . $mpidsDbHost);
    putenv('MPIDS_DB_PORT=' . $mpidsDbPort);
    putenv('MPIDS_DB_NAME=' . $mpidsDbName);
    putenv('MPIDS_DB_USER=' . $mpidsDbUser);
    if (getenv('MPIDS_DB_PASS') === false) {
        putenv('MPIDS_DB_PASS=' . $mpidsDbPass);
        $_ENV['MPIDS_DB_PASS'] = $mpidsDbPass;
    }
}

define('DB_HOST', mpids_setting('MPIDS_DB_HOST', 'DB_HOST', '127.0.0.1'));
define('DB_PORT', mpids_setting('MPIDS_DB_PORT', 'DB_PORT', '3306'));
define('DB_DATABASE', mpids_setting('MPIDS_DB_NAME', 'DB_DATABASE', 'mpids'));
define('DB_USERNAME', mpids_setting('MPIDS_DB_USER', 'DB_USER', 'root'));
define('DB_PASSWORD', mpids_setting('MPIDS_DB_PASS', 'DB_PASSWORD', ''));
// Managed MySQL (Render, PlanetScale, TiDB, etc) requires TLS. MPIDS_DB_SSL=1 turns it
// on explicitly; it is also implied automatically for *.render.com and *.tidbcloud.com hosts.
// MPIDS_DB_SSL_CA may point at a CA bundle (Render's ca.pem) when the platform
// pins its own certificate authority.
define('DB_SSL', strtolower(mpids_setting('MPIDS_DB_SSL', 'DB_SSL', '')) === '1' || str_ends_with(DB_HOST, 'render.com') || str_ends_with(DB_HOST, 'tidbcloud.com'));
define('DB_SSL_CA', mpids_setting('MPIDS_DB_SSL_CA', 'DB_SSL_CA', ''));
define('DB_SSL_VERIFY', strtolower(mpids_setting('DB_SSL_VERIFY', 'DB_SSL_VERIFY', '1')) !== '0');

// MobileSasa SMS + USSD gateway (account.mobilesasa.com). MOBILESASA_TOKEN is
// the mbs_ API token from the portal; MOBILESASA_WEBHOOK_SECRET is the signing
// secret from Settings -> Webhooks, which enables verification of the USSD
// callback headers. Empty token = simulation mode for the default provider.
define('MOBILESASA_API_URL', rtrim(env('MOBILESASA_API_URL', 'https://api.mobilesasa.com'), '/'));
define('MOBILESASA_TOKEN', env('MOBILESASA_TOKEN', ''));
define('MOBILESASA_SENDER_ID', env('MOBILESASA_SENDER_ID', 'MOBILESASA'));
define('MOBILESASA_WEBHOOK_SECRET', env('MOBILESASA_WEBHOOK_SECRET', ''));
// Which gateway sends SMS when both are configured: mobilesasa | infobip.
// Unset = auto (MobileSasa wins whenever its token is present).
define('SMS_PROVIDER', strtolower(env('SMS_PROVIDER', '')));

// Infobip SMS gateway (portal.infobip.com). Leave INFOBIP_API_KEY or
// INFOBIP_BASE_URL empty to run the gateway in simulation mode.
define('INFOBIP_BASE_URL', rtrim(env('INFOBIP_BASE_URL', ''), '/'));
define('INFOBIP_API_KEY', env('INFOBIP_API_KEY', ''));
define('INFOBIP_SENDER_ID', env('INFOBIP_SENDER_ID', 'MPIDS'));
define('SMS_MAX_RETRIES', min(3, max(1, (int) env('SMS_MAX_RETRIES', '3'))));
