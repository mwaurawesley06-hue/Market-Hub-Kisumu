<?php
declare(strict_types=1);

/**
 * Commodity (product) image uploads.
 *
 * Files live in img/commodities/ keyed by commodity id (commodity-3.jpg), so a
 * re-upload simply overwrites and nothing in the DB can point at a stale name.
 * The DB stores only the filename via commodities.image_path.
 */

// Project-root img/commodities, shared with scripts/seed-commodity-images.php
// so seeded photos and admin uploads land in one folder. (Plain __DIR__ concat:
// function calls are not allowed in PHP constant expressions.)
const MPIDS_UPLOAD_DIR = __DIR__ . '/../../img/commodities';
const MPIDS_UPLOAD_MAX_BYTES = 3 * 1024 * 1024; // 3 MB

const MPIDS_UPLOAD_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function commodity_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is larger than the 3 MB limit.',
        UPLOAD_ERR_NO_FILE => 'Choose an image file to upload.',
        default => 'The image upload failed. Please try again.',
    };
}

function commodity_image_dir(): string
{
    if (!is_dir(MPIDS_UPLOAD_DIR) && !mkdir(MPIDS_UPLOAD_DIR, 0775, true) && !is_dir(MPIDS_UPLOAD_DIR)) {
        error_out(500, 'The upload directory is not writable.');
    }
    return MPIDS_UPLOAD_DIR;
}

function commodity_image_disk_path(string $filename): string
{
    return MPIDS_UPLOAD_DIR . '/' . basename($filename);
}

function store_commodity_image(int $commodityId, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        error_out(422, commodity_upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '') || (int) ($file['size'] ?? 0) <= 0) {
        error_out(422, 'The image upload did not complete. Please try again.');
    }
    if ((int) $file['size'] > MPIDS_UPLOAD_MAX_BYTES) {
        error_out(422, 'The image is larger than the 3 MB limit.');
    }

    // Trust the pixel content, not the client-supplied name or type.
    $info = @getimagesize($file['tmp_name']);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!isset(MPIDS_UPLOAD_TYPES[$mime])) {
        error_out(422, 'Only JPG, PNG, or WebP images are supported.');
    }

    $ext = MPIDS_UPLOAD_TYPES[$mime];
    $filename = "commodity-{$commodityId}.{$ext}";

    if (!move_uploaded_file($file['tmp_name'], commodity_image_dir() . '/' . $filename)) {
        error_out(500, 'The image could not be saved. Please try again.');
    }
    @chmod(commodity_image_disk_path($filename), 0664);

    return $filename;
}

function handle_commodity_image_upload(array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_out(405, 'Method not allowed.');
    }

    $id = (int) ($_POST['commodity_id'] ?? 0);
    if ($id <= 0) {
        error_out(422, 'Choose the commodity this image belongs to.');
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM commodities WHERE id = ?');
    $stmt->execute([$id]);
    $commodity = $stmt->fetch();
    if (!$commodity) {
        error_out(404, 'Commodity not found.');
    }

    if (!isset($_FILES['image'])) {
        error_out(422, 'Choose an image file to upload.');
    }

    $filename = store_commodity_image($id, $_FILES['image']);

    $old = $commodity['image_path'] ?? null;
    if ($old !== null && $old !== $filename && is_file(commodity_image_disk_path((string) $old))) {
        @unlink(commodity_image_disk_path((string) $old)); // extension change (jpg→png) would orphan the old file
    }

    $pdo->prepare('UPDATE commodities SET image_path = ? WHERE id = ?')->execute([$filename, $id]);
    audit_log($pdo, (int) $user['id'], 'update', 'commodities', $id, ['image_path' => $old], ['image_path' => $filename], client_ip());

    json_out([
        'id' => $id,
        'image_path' => $filename,
        'image_url' => "img/commodities/{$filename}?v=" . filemtime(commodity_image_disk_path($filename)),
    ]);
}

function handle_commodity_image_delete(array $user, int $id): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM commodities WHERE id = ?');
    $stmt->execute([$id]);
    $commodity = $stmt->fetch();
    if (!$commodity) {
        error_out(404, 'Commodity not found.');
    }
    if (empty($commodity['image_path'])) {
        error_out(404, 'This commodity has no image to remove.');
    }

    $old = (string) $commodity['image_path'];
    if (is_file(commodity_image_disk_path($old))) {
        @unlink(commodity_image_disk_path($old));
    }
    $pdo->prepare('UPDATE commodities SET image_path = NULL WHERE id = ?')->execute([$id]);
    audit_log($pdo, (int) $user['id'], 'update', 'commodities', $id, ['image_path' => $old], ['image_path' => null], client_ip());

    json_out(['detail' => 'Image removed.']);
}
