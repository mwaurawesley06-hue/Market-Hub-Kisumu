<?php
declare(strict_types=1);

/**
 * GET /api/reports/?commodity=&market=&date_from=&date_to=&export=csv
 * JSON: { summary: { count, average, highest, lowest, change_pct }, records: [...] }
 * With export=csv a CSV file of the records is returned instead.
 */
function handle_reports(string $method): void
{
    if ($method !== 'GET') {
        error_out(405, 'Method not allowed.');
    }

    $filters = [
        'commodity' => optional_id_filter($_GET, 'commodity', 'commodity'),
        'market' => optional_id_filter($_GET, 'market', 'market'),
        'date_from' => optional_date_filter($_GET, 'date_from', 'date from'),
        'date_to' => optional_date_filter($_GET, 'date_to', 'date to'),
        'status' => in_array($_GET['status'] ?? '', ['pending', 'published', 'rejected'], true) ? $_GET['status'] : null,
    ];

    if ($filters['date_from'] !== null && $filters['date_to'] !== null
        && $filters['date_from'] > $filters['date_to']) {
        error_out(400, 'The date from filter cannot be later than the date to filter.');
    }

    $pdo = db();
    $records = list_price_records($pdo, $filters);

    // Summary + % change across the period (first → latest by date_observed, then id).
    $count = count($records);
    if ($count > 0) {
        $prices = array_map(fn ($r) => (float) $r['price'], $records);
        $summary = [
            'count' => $count,
            'average' => round(array_sum($prices) / $count, 2),
            'highest' => max($prices),
            'lowest' => min($prices),
            'change_pct' => null,
        ];

        $ordered = $records;
        usort($ordered, fn ($a, $b) => [$a['date_observed'], $a['id']] <=> [$b['date_observed'], $b['id']]);
        $first = (float) $ordered[0]['price'];
        $last = (float) $ordered[$count - 1]['price'];
        if ($count >= 2 && $first > 0) {
            $summary['change_pct'] = round((($last - $first) / $first) * 100, 1);
        }
    } else {
        $summary = ['count' => 0, 'average' => null, 'highest' => null, 'lowest' => null, 'change_pct' => null];
    }

    if (($_GET['export'] ?? '') === 'csv') {
        export_report_csv($records);
    }

    json_out(['summary' => $summary, 'records' => $records]);
}

function export_report_csv(array $records): never
{
    $filename = 'mpids-report-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Commodity', 'Category', 'Unit', 'Market', 'County', 'Price', 'Currency',
        'Date Observed', 'Source', 'Status', 'Captured By', 'Approved By', 'Created At']);
    foreach ($records as $r) {
        fputcsv($out, [
            $r['id'],
            $r['commodity_name'],
            $r['commodity_category'],
            $r['unit_of_measure'],
            $r['market_name'],
            $r['market_county'],
            number_format((float) $r['price'], 2, '.', ''),
            $r['currency'],
            $r['date_observed'],
            $r['source'],
            $r['status'],
            $r['captured_by_name'],
            $r['approved_by_name'] ?? '',
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}
