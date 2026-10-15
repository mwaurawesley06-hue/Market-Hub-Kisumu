<?php
putenv("MPIDS_DB_HOST=127.0.0.1");
putenv("MPIDS_DB_PORT=3306");
putenv("MPIDS_DB_USER=root");
putenv("MPIDS_DB_PASS=");
$root = "C:/xampp/htdocs/MPDIS";
require_once "{$root}/api/config/env.php";
require_once "{$root}/api/config/config.php";
require_once "{$root}/api/config/database.php";
require_once "{$root}/api/includes/helpers.php";
require_once "{$root}/api/includes/cache.php";
require_once "{$root}/api/handlers/site-settings.php";

echo "PROBE_START\n";
$pdo = db();

echo "RAW_SITE_SETTINGS_TABLE:\n";
$rows = $pdo->query("SELECT setting_key, setting_value FROM site_settings ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "  (empty)\n"; }
foreach ($rows as $r) {
    echo "  {$r["setting_key"]} = " . var_export($r["setting_value"], true) . "\n";
}

echo "\nREAD_SITE_SETTINGS_RETURN:\n";
$branding = read_site_settings($pdo);
if (!$branding || !is_array($branding)) { echo "  (not an array / empty)\n"; }
foreach (array_keys($branding) as $k) {
    echo "  $k = " . var_export($branding[$k], true) . "\n";
}

echo "\nPROBE_END\n";
