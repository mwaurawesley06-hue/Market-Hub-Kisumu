<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_DATABASE);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        PDO::MYSQL_ATTR_CONNECT_TIMEOUT => 8,
        PDO::MYSQL_ATTR_READ_TIMEOUT => 15,
    ];
    if (DB_SSL) {
        // Managed MySQL (Render) terminates TLS. With a CA bundle, verify the
        // server certificate against it; without one, still encrypt the
        // connection but skip certificate pinning so the default endpoint works.
        if (DB_SSL_CA !== '' && is_file(DB_SSL_CA)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = DB_SSL_VERIFY;
        } else {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
    // MariaDB/MySQL reject unknown time zones; fall back silently where the
    // tz tables are not loaded (common on managed instances).
    try {
        $pdo->exec("SET time_zone = '+03:00'");
    } catch (Throwable $tzException) {
        // Querying still works; PHP's date_default_timezone_set covers display.
    }

    return $pdo;
}
