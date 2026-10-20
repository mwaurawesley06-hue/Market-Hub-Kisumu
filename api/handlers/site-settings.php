<?php
declare(strict_types=1);

/**
 * Site settings: editable branding/theme configuration stored in the
 * site_settings table. Keys are whitelisted; anything outside the list is
 * ignored, so the API can never be used to plant arbitrary configuration.
 */

const MPIDS_SETTING_KEYS = [
    'site_name' => ['max' => 60, 'default' => 'MPIDS'],
    'site_tagline' => ['max' => 90, 'default' => 'Market Info Hub, Kisumu'],
    'theme_primary' => ['max' => 9, 'default' => '#0b6e4f'],
    'theme_secondary' => ['max' => 9, 'default' => '#f2b445'],
    'hero_image' => ['max' => 120, 'default' => 'img/market-hero.jpg'],
    'sms_image' => ['max' => 120, 'default' => 'img/photos/trader-phone.jpg'],
    // PWA / public asset discovery. These are editable in the settings workspace
    // (and exposed on the public JSON endpoint) so the same row drives both the
    // admin UI and the <head> of every public page.
    'site_favicon' => ['max' => 120, 'default' => 'img/icons/favicon.ico'],
    'site_manifest' => ['max' => 120, 'default' => 'pwa/manifest.json'],
    'site_service_worker' => ['max' => 120, 'default' => 'service-worker.js'],
];

const MPIDS_SETTING_UPLOAD_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const MPIDS_SETTING_UPLOAD_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function site_settings_upload_dir(): string
{
    $dir = __DIR__ . '/../../img/branding';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_out(500, 'The branding upload directory is not writable.');
    }
    return $dir;
}

/**
 * Cache-busted URL for a stored image path.
 *
 * Uploads always overwrite the same filename (img/branding/logo.png), so a
 * plain path lets the browser keep serving the previously cached image after
 * an administrator uploads a new one. Appending the file mtime gives each
 * upload its own URL. Shared by the API response and the page shells so the
 * two can never disagree.
 */
function site_asset_url(string $relativePath): string
{
    if ($relativePath === '' || str_contains($relativePath, '?')) {
        return $relativePath;
    }
    $path = dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/');
    if (is_file($path)) {
        $relativePath .= '?v=' . filemtime($path);
    }
    return $relativePath;
}

function read_site_settings(PDO $pdo): array
{
    $rows = $pdo->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
    $stored = [];
    foreach ($rows as $row) {
        $stored[$row['setting_key']] = $row['setting_value'];
    }
    $settings = [];
    foreach (MPIDS_SETTING_KEYS as $key => $meta) {
        $value = $stored[$key] ?? null;
        $settings[$key] = ($value === null || $value === '') ? $meta['default'] : (string) $value;
    }
    // The brand logo lives in the same table but is managed through its own
    // upload/remove endpoints, so it is surfaced here for every reader
    // (admin GET, public GET, and the page-shell branding helper).
    $logo = (string) ($stored['site_logo'] ?? '');
    $settings['site_logo'] = ($logo === '') ? 'img/logo.png' : $logo;
    $settings['site_logo_url'] = site_asset_url($settings['site_logo']);
    return $settings;
}

function validate_site_settings_payload(array $body, array $existing): array
{
    $errors = [];
    $data = [];
    foreach (MPIDS_SETTING_KEYS as $key => $meta) {
        if (!array_key_exists($key, $body)) {
            continue; // partial updates are allowed
        }
        $value = trim((string) $body[$key]);
        if ($value === '' && $key !== 'site_name') {
            $data[$key] = $meta['default'];
            continue;
        }
        if (mb_strlen($value) > $meta['max']) {
            $errors[$key] = 'Must be at most ' . $meta['max'] . ' characters.';
            continue;
        }
        if (str_starts_with($key, 'theme_')) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                $errors[$key] = 'Enter a hex colour such as #0b6e4f.';
                continue;
            }
            $value = strtolower($value);
        }
        if ($key === 'hero_image' || $key === 'sms_image') {
            // Must reference an image inside the project assets; no traversal.
            if (preg_match('/^img\/[a-z0-9\/._-]+\.(jpg|jpeg|png|webp)$/i', $value) !== 1
                || str_contains($value, '..')) {
                $errors[$key] = 'Use an image path under img/, for example img/market-hero.jpg.';
                continue;
            }
        }
        $data[$key] = $value;
    }
    return [$errors, $data];
}

function handle_site_settings(string $method, array $body, array $user): void
{
    $pdo = db();

    if ($method === 'GET') {
        json_out(read_site_settings($pdo));
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $existing = read_site_settings($pdo);
        [$errors, $data] = validate_site_settings_payload($body, $existing);
        if ($errors) {
            error_out(422, 'Validation failed.', ['errors' => $errors]);
        }
        if (!$data) {
            json_out($existing);
        }

        $statement = $pdo->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        foreach ($data as $key => $value) {
            $statement->execute([$key, $value, (int) $user['id']]);
            audit_log($pdo, (int) $user['id'], 'update', 'site_settings', null, [$key => $existing[$key]], [$key => $value]);
        }

        foreach (array_keys($data) as $key) {
            cache_forget('site_settings');
        }
        json_out(read_site_settings($pdo));
    }

    error_out(405, 'Method not allowed.');
}

function handle_public_site_settings(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        error_out(405, 'Method not allowed.');
    }
    json_out(read_site_settings(db())); // all keys are public-safe branding values
}

function handle_site_logo_upload(array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_out(405, 'Method not allowed.');
    }
    $file = $_FILES['logo'] ?? null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        error_out(422, 'Choose a logo image to upload.');
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '') || (int) ($file['size'] ?? 0) <= 0) {
        error_out(422, 'The upload did not complete. Please try again.');
    }
    if ((int) $file['size'] > MPIDS_SETTING_UPLOAD_MAX_BYTES) {
        error_out(422, 'The logo is larger than the 2 MB limit.');
    }

    // Trust the pixel content, not the client-supplied name or type.
    $info = @getimagesize($file['tmp_name']);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!isset(MPIDS_SETTING_UPLOAD_TYPES[$mime])) {
        error_out(422, 'Only JPG, PNG, or WebP images are supported.');
    }

    $ext = MPIDS_SETTING_UPLOAD_TYPES[$mime];
    $filename = 'logo.' . $ext;
    $target = site_settings_upload_dir() . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        error_out(500, 'The logo could not be saved. Please try again.');
    }
    @chmod($target, 0664);

    $pdo = db();
    $existing = read_site_settings($pdo);
    $statement = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $statement->execute(['site_logo', 'img/branding/' . $filename, (int) $user['id']]);
    audit_log($pdo, (int) $user['id'], 'update', 'site_settings', null, ['site_logo' => $existing['site_logo'] ?? null], ['site_logo' => 'img/branding/' . $filename]);

    cache_forget('site_settings');
    json_out([
        'site_logo' => 'img/branding/' . $filename,
        'logo_url' => 'img/branding/' . $filename . '?v=' . filemtime($target),
    ]);
}

function handle_site_logo_delete(array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        error_out(405, 'Method not allowed.');
    }
    $pdo = db();
    $row = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_logo'");
    $row->execute();
    $value = $row->fetchColumn();
    if ($value === false || (string) $value === '') {
        error_out(404, 'No custom logo is configured.');
    }

    $disk = site_settings_upload_dir() . '/' . basename((string) $value);
    if (is_file($disk)) {
        @unlink($disk);
    }
    $pdo->prepare("DELETE FROM site_settings WHERE setting_key = 'site_logo'")->execute();
    audit_log($pdo, (int) $user['id'], 'update', 'site_settings', null, ['site_logo' => $value], ['site_logo' => null]);

    cache_forget('site_settings');
    json_out(['detail' => 'Custom logo removed. The default MPIDS logo is in use.']);
}
