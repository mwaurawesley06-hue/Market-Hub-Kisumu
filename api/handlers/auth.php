<?php
declare(strict_types=1);

function handle_auth(string $method, ?string $action, array $body, ?array $user): void
{
    if ($action === 'login' && $method === 'POST') {
        $identifier = trim((string) ($body['username'] ?? $body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $errors = [];
        if ($identifier === '') {
            $errors['username'] = 'Email is required.';
        }
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }
        if ($errors) {
            error_out(422, 'Validation failed.', ['errors' => $errors]);
        }

        $pdo = db();
        rate_limit($pdo, 'login-ip:' . client_ip(), 20, 900);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$identifier]);
        $account = $stmt->fetch();

        // Verify against a dummy hash when the account is unknown so the response
        // time does not reveal which email addresses exist.
        $hash = $account['password_hash'] ?? '$2y$10$MPIDSdummyhashdummyhashdummyhashdummyhashdummyhashe';
        $passwordOk = password_verify($password, $hash);

        if (!$account || !$passwordOk) {
            // Only failed attempts count against the account, so a busy office
            // network cannot lock a real Super Admin out of a correct login.
            $retryAfter = rate_limit_hit($pdo, 'login-account:' . strtolower($identifier), 5, 900);
            if ($retryAfter > 0) {
                header('Retry-After: ' . $retryAfter);
                error_out(429, 'Too many requests. Please try again later.');
            }
            error_out(401, 'Invalid email or password.');
        }
        if ((int) $account['is_active'] !== 1) {
            error_out(403, 'This account has been deactivated. Contact another Super Admin.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['id'];
        $token = csrf_token();

        $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$account['id']]);

        json_out([
            'id' => (int) $account['id'],
            'full_name' => $account['full_name'],
            'email' => $account['email'],
            'phone_number' => $account['phone_number'],
            'is_active' => (int) $account['is_active'],
            'date_joined' => $account['date_joined'],
            'csrf_token' => $token,
        ]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
        json_out(['detail' => 'Logged out successfully.']);
    }

    error_out(404, 'Not found.');
}
