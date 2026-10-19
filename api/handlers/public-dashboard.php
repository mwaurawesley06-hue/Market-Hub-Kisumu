<?php
declare(strict_types=1);

/**
 * GET /api/public/dashboard/ — latest published price per commodity with trend.
 * Public, no login. Only status = 'published' records are ever read here.
 */
function handle_public_dashboard(string $method, ?string $action): void
{
    if ($method !== 'GET' || $action !== 'dashboard') {
        error_out(404, 'Not found.');
    }

    $filters = [
        'commodity' => optional_id_filter($_GET, 'commodity', 'commodity'),
        'market' => optional_id_filter($_GET, 'market', 'market'),
        'date_from' => optional_date_filter($_GET, 'date_from', 'date from'),
        'date_to' => optional_date_filter($_GET, 'date_to', 'date to'),
    ];

    if ($filters['date_from'] !== null && $filters['date_to'] !== null
        && $filters['date_from'] > $filters['date_to']) {
        error_out(400, 'The date from filter cannot be later than the date to filter.');
    }

    $cacheKey = 'public.dashboard';
    $cacheable = $filters['commodity'] === null && $filters['market'] === null
        && $filters['date_from'] === null && $filters['date_to'] === null;

    if ($cacheable) {
        $cached = cache_get($cacheKey);
        if ($cached !== null) {
            json_out($cached);
        }
    }

    $pdo = db();
    $rows = latest_published_per_commodity($pdo, $filters);

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'commodity_id' => (int) $row['commodity_id'],
            'commodity_name' => $row['commodity_name'],
            'category' => $row['category'],
            'unit_of_measure' => $row['unit_of_measure'],
            'image_path' => (!empty($row['image_path']) && is_file(MPIDS_UPLOAD_DIR . '/' . $row['image_path'])) ? $row['image_path'] : null,
            'price' => (float) $row['price'],
            'currency' => $row['currency'],
            'market_name' => $row['market_name'],
            'date_observed' => $row['date_observed'],
            'trend' => price_trend(
                $pdo,
                (int) $row['commodity_id'],
                (int) $row['market_id'],
                (string) $row['date_observed'],
                (int) $row['id'],
                (float) $row['price']
            ),
        ];
    }

    $payload = [
        'rows' => $out,
        'updated_at' => date('c'),
    ];

    if ($cacheable) {
        cache_set($cacheKey, $payload, 60);
    }
    json_out($payload);
}
