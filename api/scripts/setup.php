<?php
declare(strict_types=1);

/**
 * MPIDS setup — creates the database, applies the schema and loads seed data.
 *
 *   php scripts/setup.php           create + seed if empty
 *   php scripts/setup.php --reset   drop everything and reseed (destructive)
 *
 * Works with the .env file at the project root (see .env.example).
 */

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require_once $root . '/config/env.php';

$reset = in_array('--reset', $argv ?? [], true);

$host = (string) env('MPIDS_DB_HOST', '127.0.0.1');
$port = (string) env('MPIDS_DB_PORT', '3306');
$name = (string) env('MPIDS_DB_NAME', 'mpids');
$user = (string) env('MPIDS_DB_USER', 'root');
$pass = (string) env('MPIDS_DB_PASS', '');

echo "MPIDS setup\n";
echo "Connecting to MySQL at {$host}:{$port} as {$user}...\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Start MySQL in the XAMPP control panel, check .env, then retry.\n");
    exit(1);
}

function run_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(1);
    }
    // Strip comment-only lines and the hard-coded USE (the connection selects the database);
    // execute statements one by one.
    $lines = array_filter(
        explode("\n", $sql),
        fn ($line) => !preg_match('/^\s*(--|USE\s)/i', $line)
    );
    $statements = array_filter(array_map('trim', explode(';', implode("\n", $lines))));
    foreach ($statements as $statement) {
        if ($statement === '') {
            continue;
        }
        // query() + fetchAll() drains any result set the statement produced, so the
        // next statement is never blocked by an unbuffered query.
        $pdo->query($statement)->fetchAll();
    }
}

function apply_migrations(PDO $pdo, string $dir): void
{
    $files = glob($dir . '/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        echo 'Applying migration ' . basename($file) . "...\n";
        run_sql_file($pdo, $file);
    }
}

if ($reset) {
    echo "Dropping existing database '{$name}'...\n";
    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$name}`");

echo "Applying schema (database/schema.sql)...\n";
run_sql_file($pdo, $projectRoot . '/database/schema.sql');

apply_migrations($pdo, $projectRoot . '/database/migrations');

$stmt = $pdo->query("SELECT COUNT(*) FROM commodities");
$count = (int) $stmt->fetchColumn();
if ($count === 0) {
    echo "Loading seed data (database/seed.sql)...\n";
    run_sql_file($pdo, $projectRoot . '/database/seed.sql');
    echo "Seeded 2 super admins, 10 commodities, 6 markets, 20 price records, 2 subscriptions.\n";
} else {
    echo "Seed skipped — {$count} commodities already present (use --reset to reseed).\n";
}

echo "Setup complete. Sign in with a Super Admin account from database/seed.sql and change its password immediately.\n";
