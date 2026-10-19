<?php
declare(strict_types=1);

/**
 * Generates PWA icons (img/icons/icon-192.png, icon-512.png) and
 * img/favicon.png using a pure-PHP PNG encoder (no GD required).
 * Re-run any time: php scripts/make-icons.php
 */

$root = dirname(__DIR__);
$iconDir = $root . '/img/icons';
if (!is_dir($iconDir)) {
    mkdir($iconDir, 0775, true);
}

function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** $rows: array of rows, each an array of [r, g, b, a] pixels. */
function encode_png(int $w, int $h, array $rows): string
{
    $ihdr = pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0); // 8-bit RGBA
    $raw = '';
    foreach ($rows as $row) {
        $raw .= "\x00"; // filter: none
        foreach ($row as $px) {
            $raw .= pack('CCCC', $px[0], $px[1], $px[2], $px[3]);
        }
    }
    return "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', $ihdr)
        . png_chunk('IDAT', gzdeflate($raw, 6))
        . png_chunk('IEND', '');
}

/** Dark-green rounded square with three rising price bars + trend arrow. */
function draw_icon(int $size): array
{
    $dark = [11, 43, 27, 255];    // #0b2b1b
    $green = [47, 191, 113, 255]; // #2fbf71
    $light = [76, 207, 138, 255]; // #4ccf8a
    $clear = [0, 0, 0, 0];

    $rows = [];
    $corner = (int) round($size * 0.22);
    for ($y = 0; $y < $size; $y++) {
        $row = [];
        for ($x = 0; $x < $size; $x++) {
            $inCorner =
                ($x < $corner && $y < $corner && ($corner - $x) ** 2 + ($corner - $y) ** 2 > $corner ** 2) ||
                ($x >= $size - $corner && $y < $corner && ($x - ($size - $corner - 1)) ** 2 + ($corner - $y) ** 2 > $corner ** 2) ||
                ($x < $corner && $y >= $size - $corner && ($corner - $x) ** 2 + ($y - ($size - $corner - 1)) ** 2 > $corner ** 2) ||
                ($x >= $size - $corner && $y >= $size - $corner && ($x - ($size - $corner - 1)) ** 2 + ($y - ($size - $corner - 1)) ** 2 > $corner ** 2);
            $row[] = $inCorner ? $clear : $dark;
        }
        $rows[] = $row;
    }

    $barW = max(3, (int) round($size * 0.115));
    $gap = max(2, (int) round($size * 0.055));
    $baseY = (int) round($size * 0.76);
    $heights = [0.24, 0.38, 0.52];
    $totalW = $barW * 3 + $gap * 2;
    $x0 = (int) round(($size - $totalW) / 2);
    foreach ($heights as $i => $h) {
        $barH = (int) round($size * $h);
        $bx = $x0 + $i * ($barW + $gap);
        for ($y = $baseY - $barH; $y <= $baseY; $y++) {
            for ($x = $bx; $x < $bx + $barW; $x++) {
                if ($y >= 0 && $y < $size && $x >= 0 && $x < $size) {
                    $rows[$y][$x] = $i === 2 ? $green : $light;
                }
            }
        }
    }

    // Trend arrow: diagonal line with a small head, above the tallest bar.
    $thickness = max(2, (int) round($size * 0.035));
    $ay = (int) round($size * 0.18);
    $ax = $x0 + (int) round($size * 0.02);
    $len = (int) round($size * 0.20);
    for ($i = 0; $i <= $len; $i++) {
        $px = $ax + $i;
        $py = $ay + (int) round($len * 0.45) - (int) round($i * 0.45);
        for ($t = 0; $t < $thickness; $t++) {
            if ($py + $t >= 0 && $py + $t < $size && $px >= 0 && $px < $size) {
                $rows[$py + $t][$px] = $green;
            }
        }
    }
    // Arrow head: horizontal + vertical ticks at the tip.
    $tipX = $ax + $len;
    $tipY = $ay + (int) round($len * 0.45) - (int) round($len * 0.45);
    for ($t = 0; $t < (int) round($size * 0.07); $t++) {
        $hx = $tipX - $t;
        $hy = $tipY + $t;
        for ($d = 0; $d < $thickness; $d++) {
            if ($hy + $d >= 0 && $hy + $d < $size && $hx >= 0 && $hx < $size) $rows[$hy + $d][$hx] = $green;
            if ($tipY + $d >= 0 && $tipY + $d < $size && $tipX + $d < $size) $rows[$tipY + $d][$tipX + $d] = $green;
        }
    }

    return $rows;
}

foreach ([192, 512] as $size) {
    file_put_contents($iconDir . '/icon-' . $size . '.png', encode_png($size, $size, draw_icon($size)));
    echo 'Created img/icons/icon-' . $size . ".png\n";
}
file_put_contents($root . '/img/favicon.png', encode_png(64, 64, draw_icon(64)));
echo "Created img/favicon.png\n";
