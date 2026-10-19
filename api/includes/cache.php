<?php
declare(strict_types=1);

/**
 * Simple file-based cache for hot reads (commodity list, public dashboard) with a 60s TTL.
 */
function cache_dir(): string
{
    $dir = dirname(__DIR__) . '/tmp/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function cache_get(string $key): mixed
{
    $path = cache_dir() . '/' . md5($key) . '.cache';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $payload = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($payload) || !isset($payload['expires_at'], $payload['value'])) {
        return null;
    }
    if ($payload['expires_at'] < time()) {
        @unlink($path);
        return null;
    }
    return $payload['value'];
}

function cache_set(string $key, mixed $value, int $ttl_seconds = 60): void
{
    $path = cache_dir() . '/' . md5($key) . '.cache';
    @file_put_contents($path, serialize([
        'expires_at' => time() + $ttl_seconds,
        'value' => $value,
    ]));
}

function cache_forget(string $key): void
{
    @unlink(cache_dir() . '/' . md5($key) . '.cache');
}
