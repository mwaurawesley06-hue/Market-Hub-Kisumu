<?php
declare(strict_types=1);

function handle_users(string $method, ?string $id, array $body, array $user): void
{
    $pdo = db();

    if ($id === null) {
        if ($method === 'GET') {
            $stmt = $pdo->query(
                'SELECT id, full_name, email, phone_number, is_active, last_login, date_joined
                 FROM users ORDER BY full_name ASC'
            );
            json_out($stmt->fetchAll() ?: []);
        }
        if ($method === 'POST') {
            create_user($pdo, $body, $user);
        }
        error_out(405, 'Method not allowed.');
    }

    if (!ctype_digit($id) || (int) $id <= 0) {
        error_out(400, 'Invalid user id.');
    }
    $id = (int) $id;

    if ($method === 'PUT' || $method === 'PATCH') {
        update_user($pdo, $id, $body, $user);
    }
    if ($method === 'DELETE') {
        deactivate_user($pdo, $id, $user);
    }
    error_out(405, 'Method not allowed.');
}

/**
 * DELETE /api/users/{id} — soft-delete: the account is deactivated, never
 * removed, so its audit history stays intact (matches the UI's Deactivate action).
 */
function deactivate_user(PDO $pdo, int $id, array $user): void
{
    $stmt = $pdo->prepare('SELECT id, full_name, email, is_active FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        error_out(404, 'User not found.');
    }
    if ($id === (int) $user['id']) {
        error_out(422, 'You cannot deactivate your own account.', [
            'errors' => ['is_active' => 'You cannot deactivate your own account.'],
        ]);
    }
    if ((int) $existing['is_active'] === 0) {
        json_out($existing);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        audit_log($pdo, (int) $user['id'], 'delete', 'users', $id, [
            'is_active' => 1,
        ], [
            'is_active' => 0,
            'email' => $existing['email'],
        ], client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stmt = $pdo->prepare('SELECT id, full_name, email, phone_number, is_active, last_login, date_joined FROM users WHERE id = ?');
    $stmt->execute([$id]);
    json_out($stmt->fetch());
}

function validate_user_payload(array $body, bool $require_password, ?array $existing = null): array
{
    $errors = [];
    $data = [];

    $full_name = trim((string) ($body['full_name'] ?? ($existing['full_name'] ?? '')));
    if ($full_name === '') {
        $errors['full_name'] = 'Full name is required.';
    } elseif (mb_strlen($full_name) > 100) {
        $errors['full_name'] = 'Full name must be at most 100 characters.';
    } else {
        $data['full_name'] = $full_name;
    }

    $email = strtolower(trim((string) ($body['email'] ?? ($existing['email'] ?? ''))));
    if (!validate_email_address($email)) {
        $errors['email'] = 'A valid email address is required.';
    } else {
        $data['email'] = $email;
    }

    $phone = $body['phone_number'] ?? ($existing['phone_number'] ?? null);
    if ($phone !== null && trim((string) $phone) !== '') {
        $normalized = normalize_phone((string) $phone);
        if ($normalized === null) {
            $errors['phone_number'] = 'Enter a valid Kenyan phone number (e.g. 0712345678 or +254712345678).';
        } else {
            $data['phone_number'] = $normalized;
        }
    } else {
        $data['phone_number'] = null;
    }

    $password = (string) ($body['password'] ?? '');
    if ($require_password || $password !== '') {
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } else {
            $data['password'] = $password;
        }
    }

    if (array_key_exists('is_active', $body)) {
        $data['is_active'] = (int) (filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
    } elseif ($existing !== null) {
        $data['is_active'] = (int) $existing['is_active'];
    }

    return [$errors, $data];
}

function user_field_taken(PDO $pdo, string $column, ?string $value, ?int $ignoreId): bool
{
    if ($value === null || $value === '') {
        return false;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE $column = ? AND id <> ?");
    $stmt->execute([$value, $ignoreId ?? 0]);
    return (bool) $stmt->fetch();
}

/** Every account created here is a Super Admin — there is no role field. */
function create_user(PDO $pdo, array $body, array $user): void
{
    [$errors, $data] = validate_user_payload($body, true);
    if (!isset($errors['email']) && user_field_taken($pdo, 'email', $data['email'], null)) {
        $errors['email'] = 'An account with this email already exists.';
    }
    if (!isset($errors['phone_number']) && user_field_taken($pdo, 'phone_number', $data['phone_number'] ?? null, null)) {
        $errors['phone_number'] = 'An account with this phone number already exists.';
    }
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone_number, password_hash, is_active)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['full_name'], $data['email'], $data['phone_number'],
            password_hash($data['password'], PASSWORD_BCRYPT), $data['is_active'] ?? 1,
        ]);
        $id = (int) $pdo->lastInsertId();
        audit_log($pdo, (int) $user['id'], 'create', 'users', $id, null, [
            'full_name' => $data['full_name'], 'email' => $data['email'],
        ], client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stmt = $pdo->prepare('SELECT id, full_name, email, phone_number, is_active, last_login, date_joined FROM users WHERE id = ?');
    $stmt->execute([$id]);
    json_out($stmt->fetch(), 201);
}

function update_user(PDO $pdo, int $id, array $body, array $user): void
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        error_out(404, 'User not found.');
    }

    [$errors, $data] = validate_user_payload($body, false, $existing);
    if (!isset($errors['email']) && ($data['email'] ?? '') !== $existing['email'] && user_field_taken($pdo, 'email', $data['email'], $id)) {
        $errors['email'] = 'An account with this email already exists.';
    }
    $newPhone = $data['phone_number'] ?? null;
    if (!isset($errors['phone_number']) && $newPhone !== $existing['phone_number'] && user_field_taken($pdo, 'phone_number', $newPhone, $id)) {
        $errors['phone_number'] = 'An account with this phone number already exists.';
    }
    if (isset($data['is_active']) && $data['is_active'] === 0 && $id === (int) $user['id']) {
        $errors['is_active'] = 'You cannot deactivate your own account.';
    }
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    $old = [
        'full_name' => $existing['full_name'], 'email' => $existing['email'],
        'phone_number' => $existing['phone_number'], 'is_active' => (int) $existing['is_active'],
    ];
    $new = [
        'full_name' => $data['full_name'], 'email' => $data['email'],
        'phone_number' => $data['phone_number'], 'is_active' => $data['is_active'],
    ];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE users SET full_name = ?, email = ?, phone_number = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$data['full_name'], $data['email'], $data['phone_number'], $data['is_active'], $id]);

        if (!empty($data['password'])) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($data['password'], PASSWORD_BCRYPT), $id]);
            $new['password_changed'] = true;
        }

        audit_log($pdo, (int) $user['id'], 'update', 'users', $id, $old, $new, client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stmt = $pdo->prepare('SELECT id, full_name, email, phone_number, is_active, last_login, date_joined FROM users WHERE id = ?');
    $stmt->execute([$id]);
    json_out($stmt->fetch());
}
