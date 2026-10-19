<?php
declare(strict_types=1);

function current_user(PDO $pdo): ?array
{
    $id = parse_id($_SESSION['user_id'] ?? null);
    if ($id === null) {
        return null;
    }

    $statement = $pdo->prepare('SELECT id, full_name, email, is_active, date_joined AS created_at, last_login FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $statement->execute([$id]);
    $user = $statement->fetch();
    return $user ?: null;
}

function require_auth(PDO $pdo): array
{
    $user = current_user($pdo);
    if ($user === null) {
        api_error('Authentication is required.', 401);
    }
    return $user;
}

function authenticate_user(PDO $pdo, string $email, string $password): ?array
{
    $statement = $pdo->prepare('SELECT id, full_name, email, password_hash, is_active FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);
    $user = $statement->fetch();
    if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}
