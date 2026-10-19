<?php
declare(strict_types=1);

function handle_stats(string $method): void
{
    if ($method !== 'GET') {
        error_out(405, 'Method not allowed.');
    }

    $pdo = db();
    $sql = "SELECT
                SUM(status = 'pending')   AS pending,
                SUM(status = 'published') AS published,
                SUM(status = 'rejected')  AS rejected
            FROM price_records";
    $priceStats = $pdo->query($sql)->fetch() ?: [];

    $counts = [
        'pending' => (int) ($priceStats['pending'] ?? 0),
        'published' => (int) ($priceStats['published'] ?? 0),
        'rejected' => (int) ($priceStats['rejected'] ?? 0),
        'subscriptions' => (int) $pdo->query('SELECT COUNT(*) FROM sms_subscriptions')->fetchColumn(),
        'commodities' => (int) $pdo->query('SELECT COUNT(*) FROM commodities')->fetchColumn(),
        'markets' => (int) $pdo->query('SELECT COUNT(*) FROM markets')->fetchColumn(),
    ];
    json_out($counts);
}
