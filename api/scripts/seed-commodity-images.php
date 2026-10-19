<?php
declare(strict_types=1);

/**
 * Fetches a real product photo for every commodity from Wikimedia Commons and
 * stores it as img/commodities/commodity-{id}.jpg|png|webp, then records the
 * filename in commodities.image_path.
 *
 *   php scripts/seed-commodity-images.php            # fill only missing images
 *   php scripts/seed-commodity-images.php --force    # re-fetch every image
 *
 * CLI only. Sources are Commons file pages (freely licensed); each download is
 * verified with getimagesize before it is trusted.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

const UPLOAD_DIR = __DIR__ . '/../../img/commodities';
const MAX_BYTES = 3 * 1024 * 1024;

/** Commodity id => search terms tried in order on Wikimedia Commons. */
const IMAGE_QUERIES = [
    1 => ['Dried maize cobs', 'Maize cobs harvested', 'Corn cobs dried'],
    2 => ['Rosecoco beans', 'Borlotti beans', 'Phaseolus beans'],
    3 => ['Tomatoes red', 'Tomato harvest'],
    4 => ['Collard greens leaves', 'Kale leaves bundle', 'Kale plant leaves'],
    5 => ['Irish potatoes', 'Potatoes tubers', 'Solanum tuberosum'],
    6 => ['Red onions', 'Onion bulbs'],
    7 => ['Basmati rice grains', 'Aromatic white rice', 'Rice grains'],
    8 => ['Green grams', 'Mung beans', 'Vigna radiata seeds'],
    9 => ['Zebu cattle herd', 'Cattle livestock market', 'Bos indicus zebu'],
    10 => ['Rooster farmyard', 'Chicken portrait', 'Hen backyard poultry'],
    12 => ['Maize kernels dried', 'Corn kernels', 'Dried maize grain'],
    13 => ['Wheat grain closeup', 'Wheat kernels', 'Triticum grain'],
    14 => ['Milled white rice', 'Rice grains bowl', 'White rice'],
    15 => ['Dried beans seeds', 'Phaseolus vulgaris dry beans', 'Common beans'],
    16 => ['Onions bulbs', 'Allium cepa'],
];

const ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function http_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'MPIDS-MarketInfoHub/1.0 (local development seed; contact: admin@mpids.co.ke)',
        CURLOPT_ACCEPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200 || !is_string($body) || $body === '') {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function http_download(string $url, int $maxBytes = MAX_BYTES): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXFILESIZE => $maxBytes,
        CURLOPT_USERAGENT => 'MPIDS-MarketInfoHub/1.0 (local development seed; contact: admin@mpids.co.ke)',
        CURLOPT_ACCEPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200 || !is_string($body) || $body === '' || strlen($body) > $maxBytes) {
        return null;
    }
    return $body;
}

/** Returns the direct file URL of the first Commons hit matching any query, or null. */
function find_commons_image(array $queries): ?array
{
    foreach ($queries as $query) {
        $api = 'https://commons.wikimedia.org/w/api.php?action=query&format=json&prop=imageinfo'
            . '&iiprop=url%7Cmime%7Csize&iiurlwidth=640&generator=search'
            . '&gsrsearch=' . rawurlencode('filetype:bitmap ' . $query)
            . '&gsrnamespace=6&gsrlimit=8';
        $payload = http_json($api);
        if (!$payload || empty($payload['query']['pages'])) {
            continue;
        }
        // The API returns an id-keyed map; keep the API's ranking order.
        $pages = $payload['query']['pages'];
        usort($pages, static fn(array $a, array $b): int => ($a['index'] ?? 99) <=> ($b['index'] ?? 99));
        foreach ($pages as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (!$info || empty($info['url'])) {
                continue;
            }
            if (!in_array($info['mime'] ?? '', array_keys(ALLOWED_MIME), true)) {
                continue;
            }
            if (($info['width'] ?? 0) < 320 || ($info['height'] ?? 0) < 240) {
                continue;
            }
            // Prefer the 640px thumbnail when offered: smaller download, still sharp.
            $url = $info['thumburl'] ?? $info['url'];
            return ['url' => $url, 'mime' => $info['mime'], 'title' => $page['title'] ?? ''];
        }
    }
    return null;
}

function store_image(int $id, string $bytes, string $mime): ?string
{
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || ($info['mime'] ?? '') !== $mime) {
        return null; // server lied about the type or the payload is not an image
    }
    $filename = "commodity-{$id}." . ALLOWED_MIME[$mime];
    $path = UPLOAD_DIR . '/' . $filename;
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        fwrite(STDERR, "Cannot create the upload directory.\n");
        exit(1);
    }
    if (file_put_contents($path, $bytes) === false) {
        return null;
    }
    @chmod($path, 0664);
    return $filename;
}

$pdo = db();
$force = in_array('--force', $argv, true);
@mkdir(UPLOAD_DIR, 0775, true);

$ok = 0;
$skipped = 0;
$failed = [];

foreach (IMAGE_QUERIES as $id => $queries) {
    $stmt = $pdo->prepare('SELECT id, name, image_path FROM commodities WHERE id = ?');
    $stmt->execute([$id]);
    $commodity = $stmt->fetch();
    if (!$commodity) {
        continue;
    }
    $existingPath = UPLOAD_DIR . '/' . (string) $commodity['image_path'];
    if (!$force && !empty($commodity['image_path']) && is_file($existingPath)) {
        echo "  ..  {$commodity['name']}: already has an image\n";
        $skipped++;
        continue;
    }

    $found = find_commons_image($queries);
    if (!$found) {
        echo "FAIL  {$commodity['name']}: no usable Commons result\n";
        $failed[] = $commodity['name'];
        continue;
    }

    $bytes = http_download($found['url']);
    if ($bytes === null) {
        echo "FAIL  {$commodity['name']}: download failed ({$found['title']})\n";
        $failed[] = $commodity['name'];
        continue;
    }

    $filename = store_image((int) $id, $bytes, $found['mime']);
    if ($filename === null) {
        echo "FAIL  {$commodity['name']}: downloaded payload failed validation\n";
        $failed[] = $commodity['name'];
        continue;
    }

    $pdo->prepare('UPDATE commodities SET image_path = ? WHERE id = ?')->execute([$filename, $id]);
    echo "  ok  {$commodity['name']}: {$filename}  <-  {$found['title']}\n";
    $ok++;
}

echo "\n{$ok} stored, {$skipped} already present, " . count($failed) . " failed.\n";
if ($failed) {
    echo 'Missing: ' . implode(', ', $failed) . "\n";
    exit(1);
}
