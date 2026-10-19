<?php
declare(strict_types=1);

const COMMODITY_CATEGORIES = ['cereal', 'legume', 'vegetable', 'livestock'];

function handle_commodities(string $method, ?string $id, array $body, ?array $user, bool $publicRead = false): void
{
    $pdo = db();

    // Drop references whose file is missing so clients fall back to the icon
    // instead of rendering broken images.
    $verifyImages = static function (array $rows): array {
        foreach ($rows as &$row) {
            if (!empty($row['image_path']) && !is_file(MPIDS_UPLOAD_DIR . '/' . $row['image_path'])) {
                $row['image_path'] = null;
            }
        }
        return $rows;
    };

    if ($id === null) {
        if ($method === 'GET') {
            if ($publicRead) {
                $stmt = $pdo->query(
                    'SELECT c.id, c.name, c.category, c.unit_of_measure, c.image_path
                     FROM commodities c
                     WHERE c.is_active = 1
                     ORDER BY c.name ASC'
                );
                json_out($verifyImages($stmt->fetchAll() ?: []));
            }
            $where = '';
            $params = [];
            if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
                $where = 'WHERE c.is_active = ?';
                $params[] = (int) (($_GET['is_active'] === 'true' || $_GET['is_active'] === '1') ? 1 : 0);
            }
            $stmt = $pdo->prepare(
                "SELECT c.*,
                        (SELECT COUNT(*) FROM price_records pr WHERE pr.commodity_id = c.id) AS price_record_count
                 FROM commodities c $where ORDER BY c.name ASC"
            );
            $stmt->execute($params);
            json_out($verifyImages($stmt->fetchAll() ?: []));
        }
        if ($method === 'POST') {
            create_commodity($pdo, $body, $user);
        }
        error_out(405, 'Method not allowed.');
    }

    if (!ctype_digit($id) || (int) $id <= 0) {
        error_out(400, 'Invalid commodity id.');
    }
    $id = (int) $id;

    if ($method === 'PUT' || $method === 'PATCH') {
        update_commodity($pdo, $id, $body, $user);
    }
    if ($method === 'DELETE') {
        soft_delete_commodity($pdo, $id, $user);
    }
    error_out(405, 'Method not allowed.');
}

function validate_commodity_payload(array $body, ?array $existing = null): array
{
    $errors = [];
    $data = [];

    $name = trim((string) ($body['name'] ?? ($existing['name'] ?? '')));
    if ($name === '') {
        $errors['name'] = 'Name is required.';
    } elseif (mb_strlen($name) > 80) {
        $errors['name'] = 'Name must be at most 80 characters.';
    } else {
        $data['name'] = $name;
    }

    $category = strtolower(trim((string) ($body['category'] ?? ($existing['category'] ?? ''))));
    if ($category === '') {
        $errors['category'] = 'Category is required.';
    } elseif (!in_array($category, COMMODITY_CATEGORIES, true)) {
        $errors['category'] = 'Category must be one of: ' . implode(', ', COMMODITY_CATEGORIES) . '.';
    } else {
        $data['category'] = $category;
    }

    $unit = trim((string) ($body['unit_of_measure'] ?? ($existing['unit_of_measure'] ?? '')));
    if ($unit === '') {
        $errors['unit_of_measure'] = 'Unit of measure is required.';
    } elseif (mb_strlen($unit) > 20) {
        $errors['unit_of_measure'] = 'Unit of measure must be at most 20 characters.';
    } else {
        $data['unit_of_measure'] = $unit;
    }

    $min = $body['min_price'] ?? ($existing['min_price'] ?? null);
    $max = $body['max_price'] ?? ($existing['max_price'] ?? null);
    if ($min === null || $min === '' || !is_numeric($min) || (float) $min <= 0) {
        $errors['min_price'] = 'Minimum price must be a positive number.';
    } else {
        $data['min_price'] = round((float) $min, 2);
    }
    if ($max === null || $max === '' || !is_numeric($max)) {
        $errors['max_price'] = 'Maximum price is required.';
    } else {
        $data['max_price'] = round((float) $max, 2);
    }
    if (!isset($errors['min_price']) && !isset($errors['max_price']) && $data['max_price'] < $data['min_price']) {
        $errors['max_price'] = 'Maximum price must be greater than or equal to the minimum price.';
    }

    if (array_key_exists('is_active', $body)) {
        $data['is_active'] = (int) (filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
    } elseif ($existing !== null) {
        $data['is_active'] = (int) $existing['is_active'];
    }

    return [$errors, $data];
}

function commodity_name_taken(PDO $pdo, string $name, ?int $ignoreId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM commodities WHERE name = ? AND id <> ?');
    $stmt->execute([$name, $ignoreId ?? 0]);
    return (bool) $stmt->fetch();
}

function create_commodity(PDO $pdo, array $body, array $user): void
{
    [$errors, $data] = validate_commodity_payload($body);
    if (isset($data['name']) && commodity_name_taken($pdo, $data['name'], null)) {
        $errors['name'] = 'A commodity with this name already exists.';
    }
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO commodities (name, category, unit_of_measure, min_price, max_price, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'], $data['category'], $data['unit_of_measure'],
            $data['min_price'], $data['max_price'], $data['is_active'] ?? 1,
        ]);
        $id = (int) $pdo->lastInsertId();
        audit_log($pdo, (int) $user['id'], 'create', 'commodities', $id, null, [
            'name' => $data['name'], 'category' => $data['category'],
            'unit_of_measure' => $data['unit_of_measure'],
            'min_price' => $data['min_price'], 'max_price' => $data['max_price'],
        ], client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    cache_forget('commodities.active');
    cache_forget('ussd.commodities');

    $stmt = $pdo->prepare('SELECT *, 0 AS price_record_count FROM commodities WHERE id = ?');
    $stmt->execute([$id]);
    json_out($stmt->fetch(), 201);
}

function update_commodity(PDO $pdo, int $id, array $body, array $user): void
{
    $stmt = $pdo->prepare('SELECT * FROM commodities WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        error_out(404, 'Commodity not found.');
    }

    [$errors, $data] = validate_commodity_payload($body, $existing);
    if (isset($data['name']) && $data['name'] !== $existing['name'] && commodity_name_taken($pdo, $data['name'], $id)) {
        $errors['name'] = 'A commodity with this name already exists.';
    }
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE commodities SET name = ?, category = ?, unit_of_measure = ?, min_price = ?, max_price = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['name'], $data['category'], $data['unit_of_measure'],
            $data['min_price'], $data['max_price'], $data['is_active'], $id,
        ]);
        audit_log($pdo, (int) $user['id'], 'update', 'commodities', $id, [
            'name' => $existing['name'], 'category' => $existing['category'],
            'unit_of_measure' => $existing['unit_of_measure'],
            'min_price' => (float) $existing['min_price'], 'max_price' => (float) $existing['max_price'],
            'is_active' => (int) $existing['is_active'],
        ], [
            'name' => $data['name'], 'category' => $data['category'],
            'unit_of_measure' => $data['unit_of_measure'],
            'min_price' => $data['min_price'], 'max_price' => $data['max_price'],
            'is_active' => $data['is_active'],
        ], client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    cache_forget('commodities.active');
    cache_forget('ussd.commodities');

    $stmt = $pdo->prepare(
        'SELECT c.*, (SELECT COUNT(*) FROM price_records pr WHERE pr.commodity_id = c.id) AS price_record_count
         FROM commodities c WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    json_out($stmt->fetch());
}

/** DELETE is a soft delete (is_active = false). Referential rules stay intact because rows are never removed. */
function soft_delete_commodity(PDO $pdo, int $id, array $user): void
{
    $stmt = $pdo->prepare('SELECT * FROM commodities WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        error_out(404, 'Commodity not found.');
    }
    if ((int) $existing['is_active'] === 0) {
        json_out(['detail' => 'Commodity was already inactive.', 'commodity' => $existing]);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE commodities SET is_active = 0 WHERE id = ?')->execute([$id]);
        audit_log($pdo, (int) $user['id'], 'delete', 'commodities', $id, [
            'name' => $existing['name'], 'is_active' => 1,
        ], [
            'name' => $existing['name'], 'is_active' => 0, 'mode' => 'soft delete',
        ], client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    cache_forget('commodities.active');
    cache_forget('ussd.commodities');

    json_out(['detail' => 'Commodity deactivated.', 'commodity' => ['id' => $id, 'is_active' => 0]]);
}
