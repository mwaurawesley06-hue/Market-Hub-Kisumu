<?php
declare(strict_types=1);

/**
 * Canonicalizes a phone number to E.164.
 *
 * The project is Kenyan, so the four shapes a member of the public actually
 * types — 0712345678, 0112345678, 254712345678 and +254712345678 — plus the
 * bare 9-digit form all collapse to the same +254… string. A number that
 * cannot be read as a Kenyan mobile line returns null rather than a
 * best-effort guess, so a transposed digit is rejected at the form instead of
 * becoming a subscription that can never be verified.
 */
function normalize_phone(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $phone = preg_replace('/[\s\-().]/', '', trim($raw));
    if ($phone === null || $phone === '') {
        return null;
    }

    // International Kenyan mobile: +254 followed by 9 digits starting 7 (Safaricom) or 1 (Airtel).
    if (preg_match('/^\+?254([17][0-9]{8})$/', $phone, $matches)) {
        return '+254' . $matches[1];
    }
    // Local Kenyan mobile: 07…/01… (10 digits).
    if (preg_match('/^0([17][0-9]{8})$/', $phone, $matches)) {
        return '+254' . $matches[1];
    }
    // Bare Kenyan mobile without the trunk or country code: 7…/1… (9 digits).
    if (preg_match('/^([17][0-9]{8})$/', $phone, $matches)) {
        return '+254' . $matches[1];
    }

    return null;
}

function validate_email_address(?string $email): bool
{
    return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 120;
}

function is_iso_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** Reads an optional YYYY-MM-DD filter; empty means "no filter", invalid means 400. */
function optional_date_filter(array $source, string $key, string $label): ?string
{
    $raw = trim((string) ($source[$key] ?? ''));
    if ($raw === '') {
        return null;
    }
    if (!is_iso_date($raw)) {
        error_out(400, "Invalid {$label} filter — use the YYYY-MM-DD format.");
    }
    return $raw;
}

/** Reads an optional positive integer id filter; empty means "no filter", invalid means 400. */
function optional_id_filter(array $source, string $key, string $label): ?int
{
    $raw = trim((string) ($source[$key] ?? ''));
    if ($raw === '') {
        return null;
    }
    if (!ctype_digit($raw) || (int) $raw <= 0) {
        error_out(400, "Invalid {$label} filter.");
    }
    return (int) $raw;
}

/**
 * Counts one attempt against a rate-limit bucket and reports the penalty.
 * Returns 0 while the caller is within the limit, otherwise the seconds to wait.
 * An attempt that is already over the limit is rolled back so the window cannot slide.
 */
function rate_limit_hit(PDO $pdo, string $key, int $limit, int $windowSeconds): int
{
    if ($limit < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException('Rate-limit values must be positive.');
    }

    $bucket = hash('sha256', $key);
    $pdo->beginTransaction();
    try {
        $seed = $pdo->prepare(
            'INSERT INTO rate_limit_buckets (bucket, window_started_at, attempts)
             VALUES (?, NOW(), 0)
             ON DUPLICATE KEY UPDATE bucket = VALUES(bucket)'
        );
        $seed->execute([$bucket]);

        $stmt = $pdo->prepare(
            'SELECT UNIX_TIMESTAMP(window_started_at) AS started_unix,
                    UNIX_TIMESTAMP(CURRENT_TIMESTAMP) AS now_unix,
                    attempts
             FROM rate_limit_buckets
             WHERE bucket = ?
             FOR UPDATE'
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch();
        $startedUnix = $row === false ? false : (int) $row['started_unix'];
        $nowUnix = $row === false ? time() : (int) $row['now_unix'];
        $elapsed = $startedUnix > 0 ? max(0, $nowUnix - $startedUnix) : $windowSeconds;

        if ($elapsed >= $windowSeconds) {
            $update = $pdo->prepare(
                'UPDATE rate_limit_buckets SET window_started_at = NOW(), attempts = 1 WHERE bucket = ?'
            );
            $update->execute([$bucket]);
            $attempts = 1;
        } else {
            $attempts = (int) $row['attempts'] + 1;
            $update = $pdo->prepare(
                'UPDATE rate_limit_buckets SET attempts = ? WHERE bucket = ?'
            );
            $update->execute([$attempts, $bucket]);
        }

        if ($attempts > $limit) {
            $retryAfter = max(1, $windowSeconds - $elapsed);
            $pdo->rollBack();
            return $retryAfter;
        }

        $pdo->commit();
        return 0;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Enforces a rate limit: records the attempt and answers 429 once the limit is passed. */
function rate_limit(PDO $pdo, string $key, int $limit, int $windowSeconds): void
{
    $retryAfter = rate_limit_hit($pdo, $key, $limit, $windowSeconds);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        error_out(429, 'Too many requests. Please try again later.');
    }
}

/**
 * Validates a price-capture payload against completeness, date and range rules.
 * Returns [errors, normalized] — errors is an empty array when the payload is valid.
 *
 * $commodity and $market must be the already-fetched reference rows (active).
 */
function validate_capture_payload(array $body, ?array $commodity, ?array $market): array
{
    $errors = [];
    $normalized = [];

    $commodityId = $body['commodity'] ?? null;
    if ($commodity === null) {
        $errors['commodity'] = $commodityId === null ? 'Select a commodity.' : 'Unknown or inactive commodity.';
    } else {
        $normalized['commodity_id'] = (int) $commodity['id'];
    }

    $marketId = $body['market'] ?? null;
    if ($market === null) {
        $errors['market'] = $marketId === null ? 'Select a market.' : 'Unknown or inactive market.';
    } else {
        $normalized['market_id'] = (int) $market['id'];
    }

    $date = trim((string) ($body['date_observed'] ?? ''));
    if ($date === '') {
        $errors['date_observed'] = 'Date observed is required.';
    } else {
        $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date)
            : false;
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            $errors['date_observed'] = 'Enter a valid date (YYYY-MM-DD).';
        } elseif ($date > date('Y-m-d')) {
            $errors['date_observed'] = 'Date observed cannot be in the future.';
        } else {
            $normalized['date_observed'] = $date;
        }
    }

    $priceRaw = $body['price'] ?? null;
    if ($priceRaw === null || $priceRaw === '') {
        $errors['price'] = 'Price is required.';
    } elseif (!is_numeric($priceRaw) || (float) $priceRaw <= 0) {
        $errors['price'] = 'Price must be a positive number.';
    } else {
        $price = round((float) $priceRaw, 2);
        if ($commodity !== null && ($price < (float) $commodity['min_price'] || $price > (float) $commodity['max_price'])) {
            $errors['price'] = sprintf(
                'Price must be between KES %s and %s for %s (%s).',
                number_format((float) $commodity['min_price'], 2),
                number_format((float) $commodity['max_price'], 2),
                $commodity['name'],
                $commodity['unit_of_measure']
            );
        } else {
            $normalized['price'] = $price;
        }
    }

    $source = trim((string) ($body['source'] ?? ''));
    if ($source === '') {
        $errors['source'] = 'Source is required (e.g. Trader interviews).';
    } elseif (mb_strlen($source) > 120) {
        $errors['source'] = 'Source must be at most 120 characters.';
    } else {
        $normalized['source'] = $source;
    }

    return [$errors, $normalized];
}

function find_duplicate_price_record(PDO $pdo, int $commodity_id, int $market_id, string $date_observed, ?int $ignore_id = null): ?array
{
    $sql = 'SELECT id FROM price_records
            WHERE commodity_id = ? AND market_id = ? AND date_observed = ?';
    $params = [$commodity_id, $market_id, $date_observed];
    if ($ignore_id !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $ignore_id;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_price_record_detailed(PDO $pdo, int $id): ?array
{
    $sql = 'SELECT pr.*,
                   c.name AS commodity_name, c.unit_of_measure, c.category AS commodity_category,
                   m.name AS market_name, m.county AS market_county,
                   uc.full_name AS captured_by_name,
                   ua.full_name AS approved_by_name
            FROM price_records pr
            JOIN commodities c ON c.id = pr.commodity_id
            JOIN markets m ON m.id = pr.market_id
            JOIN users uc ON uc.id = pr.captured_by
            LEFT JOIN users ua ON ua.id = pr.approved_by
            WHERE pr.id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function list_price_records(PDO $pdo, array $filters): array
{
    $sql = 'SELECT pr.*,
                   c.name AS commodity_name, c.unit_of_measure, c.category AS commodity_category,
                   m.name AS market_name, m.county AS market_county,
                   uc.full_name AS captured_by_name,
                   ua.full_name AS approved_by_name
            FROM price_records pr
            JOIN commodities c ON c.id = pr.commodity_id
            JOIN markets m ON m.id = pr.market_id
            JOIN users uc ON uc.id = pr.captured_by
            LEFT JOIN users ua ON ua.id = pr.approved_by
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['status'])) {
        $sql .= ' AND pr.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['commodity'])) {
        $sql .= ' AND pr.commodity_id = ?';
        $params[] = (int) $filters['commodity'];
    }
    if (!empty($filters['market'])) {
        $sql .= ' AND pr.market_id = ?';
        $params[] = (int) $filters['market'];
    }
    if (!empty($filters['date_from'])) {
        $sql .= ' AND pr.date_observed >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $sql .= ' AND pr.date_observed <= ?';
        $params[] = $filters['date_to'];
    }
    $sql .= ' ORDER BY pr.date_observed DESC, pr.id DESC LIMIT 1000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/** Latest published record per commodity (optionally within one market and date range). */
function latest_published_per_commodity(PDO $pdo, array $filters): array
{
    $where = "pr.status = 'published'";
    $params = [];
    if (!empty($filters['commodity'])) {
        $where .= ' AND pr.commodity_id = ?';
        $params[] = (int) $filters['commodity'];
    }
    if (!empty($filters['market'])) {
        $where .= ' AND pr.market_id = ?';
        $params[] = (int) $filters['market'];
    }
    if (!empty($filters['date_from'])) {
        $where .= ' AND pr.date_observed >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where .= ' AND pr.date_observed <= ?';
        $params[] = $filters['date_to'];
    }

    $sql = "SELECT pr.id, pr.commodity_id, pr.market_id, pr.price, pr.currency, pr.date_observed,
                   c.name AS commodity_name, c.category, c.unit_of_measure, c.image_path,
                   m.name AS market_name
            FROM price_records pr
            JOIN commodities c ON c.id = pr.commodity_id
            JOIN markets m ON m.id = pr.market_id
            WHERE $where
              AND c.is_active = 1
              AND m.is_active = 1
              AND pr.id = (
                    SELECT pr2.id FROM price_records pr2
                    WHERE pr2.commodity_id = pr.commodity_id AND pr2.status = 'published'
                      AND pr2.market_id = pr.market_id
                    ORDER BY pr2.date_observed DESC, pr2.id DESC
                    LIMIT 1
                  )
              AND NOT EXISTS (
                    SELECT 1 FROM price_records newer
                    WHERE newer.commodity_id = pr.commodity_id AND newer.status = 'published'
                      AND newer.market_id = pr.market_id
                      AND (newer.date_observed > pr.date_observed
                           OR (newer.date_observed = pr.date_observed AND newer.id > pr.id))
                  )
            ORDER BY c.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    // Keep only the newest row per commodity when several markets are in play.
    $byCommodity = [];
    foreach ($rows as $row) {
        $cid = (int) $row['commodity_id'];
        if (!isset($byCommodity[$cid])
            || $row['date_observed'] > $byCommodity[$cid]['date_observed']
            || ($row['date_observed'] === $byCommodity[$cid]['date_observed'] && $row['id'] > $byCommodity[$cid]['id'])) {
            $byCommodity[$cid] = $row;
        }
    }
    return array_values($byCommodity);
}

/** Trend of $current vs the previous published record for the same commodity + market. */
function price_trend(PDO $pdo, int $commodity_id, int $market_id, string $date_observed, int $current_id, float $current_price): string
{
    $stmt = $pdo->prepare(
        "SELECT price FROM price_records
         WHERE commodity_id = ? AND market_id = ? AND status = 'published'
           AND (date_observed < ? OR (date_observed = ? AND id < ?))
         ORDER BY date_observed DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$commodity_id, $market_id, $date_observed, $date_observed, $current_id]);
    $prev = $stmt->fetch();
    if (!$prev) {
        return 'same';
    }
    $prevPrice = (float) $prev['price'];
    if ($current_price > $prevPrice) {
        return 'up';
    }
    if ($current_price < $prevPrice) {
        return 'down';
    }
    return 'same';
}
