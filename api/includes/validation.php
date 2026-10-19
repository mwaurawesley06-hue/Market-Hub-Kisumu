<?php
declare(strict_types=1);

function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function valid_phone(string $phone): bool
{
    return (bool) preg_match('/^\+?[0-9]{7,15}$/', $phone);
}

function valid_date_range(?string $from, ?string $to): bool
{
    if ($from !== null && $from !== '' && !valid_date($from)) {
        return false;
    }
    if ($to !== null && $to !== '' && !valid_date($to)) {
        return false;
    }
    if ($from !== null && $to !== null && $from !== '' && $to !== '' && $from > $to) {
        return false;
    }
    return true;
}

function valid_price(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $normalized = trim((string) $value);
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $normalized)) {
        return null;
    }
    $price = (float) $normalized;
    return is_finite($price) && $price > 0 ? round($price, 2) : null;
}

function normalize_source(mixed $value): string
{
    $source = trim((string) $value);
    return mb_strlen($source) <= 120 ? $source : '';
}
