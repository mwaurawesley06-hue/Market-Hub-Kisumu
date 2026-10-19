<?php
declare(strict_types=1);

/**
 * One-shot admin creator for deployments without phpMyAdmin (Render, etc.).
 *
 *   php api/scripts/create-admin.php "Wesley Mwaura" admin@mpids.local "Str0ngPass!" [+2547...]
 *
 * Safe to re-run: an existing email has its name/phone refreshed and its
 * password reset to the value given on the command line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

[, $fullName, $email, $password, $phone] = array_pad($argv, 5, '');

if ($fullName === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Usage: php api/scripts/create-admin.php \"Full Name\" email@example.com \"password\" [\"+2547XXXXXXXX\"]\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
$phoneNormalized = null;
if ($phone !== '') {
    $phoneNormalized = normalize_phone($phone);
    if ($phoneNormalized === null) {
        fwrite(STDERR, "Invalid Kenyan phone number.\n");
        exit(1);
    }
}

try {
    $pdo = db();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->execute([$email]);
    $row = $existing->fetch();

    if ($row) {
        $pdo->prepare('UPDATE users SET full_name = ?, password_hash = ?, phone_number = ?, is_active = 1 WHERE id = ?')
            ->execute([$fullName, $hash, $phoneNormalized, (int) $row['id']]);
        $id = (int) $row['id'];
        fwrite(STDOUT, "Updated admin #{$id} ({$email}).\n");
    } else {
        $pdo->prepare('INSERT INTO users (full_name, email, phone_number, password_hash, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute([$fullName, $email, $phoneNormalized, $hash]);
        $id = (int) $pdo->lastInsertId();
        fwrite(STDOUT, "Created admin #{$id} ({$email}).\n");
    }
    fwrite(STDOUT, "Sign in at /login with this email and password.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Failed: ' . $error->getMessage() . "\n");
    exit(1);
}
