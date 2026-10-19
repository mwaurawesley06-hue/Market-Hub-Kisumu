<?php
declare(strict_types=1);

function mpids_json(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return '{}';
    }
    return $encoded;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo mpids_json($payload);
    exit;
}

function api_success(mixed $data = null, int $status = 200): void
{
    json_response(['status' => 'success', 'data' => $data], $status);
}

function api_error(string $detail, int $status = 400, array $errors = [], array $extra = []): void
{
    json_response([
        'status' => 'error',
        'data' => array_merge(['detail' => $detail], $errors, $extra),
    ], $status);
}

function request_data(): array
{
    // MobileSasa's USSD gateway (and any classic HTML form) posts
    // application/x-www-form-urlencoded; PHP has already parsed that body into
    // $_POST, and re-reading it as JSON would reject the request outright.
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/x-www-form-urlencoded')
        || str_contains($contentType, 'multipart/form-data')) {
        return array_merge($_GET, $_POST);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        api_error('Request body must be valid JSON.', 400);
    }
    return array_merge($_GET, $decoded);
}

function request_query(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function require_method(string ...$methods): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, array_map('strtoupper', $methods), true)) {
        header('Allow: ' . implode(', ', $methods));
        api_error('Method not allowed.', 405);
    }
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function clean_string(mixed $value, int $maxLength = 255): string
{
    $value = trim((string) $value);
    return mb_substr($value, 0, $maxLength);
}

function clean_phone(mixed $value): string
{
    return preg_replace('/[^0-9+]/', '', (string) $value) ?: '';
}

function parse_id(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : (int) $id;
}

function parse_limit(mixed $value, int $default = 50, int $max = 200): int
{
    $limit = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
    return $limit === false ? $default : (int) $limit;
}

function parse_offset(mixed $value): int
{
    $offset = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    return $offset === false ? 0 : (int) $offset;
}
