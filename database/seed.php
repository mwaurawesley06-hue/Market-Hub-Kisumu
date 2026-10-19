<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config/env.php';
require_once dirname(__DIR__) . '/api/config/config.php';
require_once dirname(__DIR__) . '/api/config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$name = trim((string) (getenv('SEED_ADMIN_NAME') ?: 'MPIDS Administrator'));
$email = strtolower(trim((string) (getenv('SEED_ADMIN_EMAIL') ?: 'admin@mpids.local')));
$password = (string) (getenv('SEED_ADMIN_PASSWORD') ?: '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Set SEED_ADMIN_EMAIL and a SEED_ADMIN_PASSWORD of at least 12 characters.\n");
    exit(1);
}

$pdo = db();
$statement = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), is_active = 1');
$statement->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT)]);
echo "Seeded the MPIDS administrator account.\n";
