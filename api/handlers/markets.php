<?php
declare(strict_types=1);

function handle_markets(string $method, ?string $id, array $body, ?array $user, bool $publicRead = false): void
{
    $pdo = db();

    if ($id === null) {
        if ($method === 'GET') {
            if ($publicRead) {
                $stmt = $pdo->query(
                    'SELECT m.id, m.name, m.county, m.sub_county
                     FROM markets m
                     WHERE m.is_active = 1
                     ORDER BY m.name ASC'
                );
                json_out($stmt->fetchAll() ?: []);
            }
            $where = '';
            $params = [];
            if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
                $where = 'WHERE m.is_active = ?';
                $params[] = (int) (($_GET['is_active'] === 'true' || $_GET['is_active'] === '1') ? 1 : 0);
            }
            $stmt = $pdo->prepare(
                "SELECT m.*,
                        (SELECT COUNT(*) FROM price_records pr WHERE pr.market_id = m.id) AS price_record_count
                 FROM markets m $where ORDER BY m.name ASC"
            );
            $stmt->execute($params);
            json_out($stmt->fetchAll() ?: []);
        }
        if ($method === 'POST') {
            create_market($pdo, $body, $user);
        }
        error_out(405, 'Method not allowed.');
    }

    if (!ctype_digit($id) || (int) $id <= 0) {
        error_out(400, 'Invalid market id.');
    }
    $id = (int) $id;

    if ($method === 'PUT' || $method === 'PATCH') {
        update_market($pdo, $id, $body, $user);
    }
    if ($method === 'DELETE') {
        soft_delete_market($pdo, $id, $user);
    }
    error_out(405, 'Method not allowed.');
}

function validate_market_payload(array $body, ?array $existing = null): array
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

    $county = trim((string) ($body['county'] ?? ($existing['county'] ?? '')));
    if ($county === '') {
        $errors['county'] = 'County is required.';
    } elseif (mb_strlen($county) > 50) {
        $errors['county'] = 'County must be at most 50 characters.';
    } else {
        $data['county'] = $county;
    }

    $subCounty = trim((string) ($body['sub_county'] ?? ($existing['sub_county'] ?? '')));
    if ($subCounty === '') {
        $data['sub_county'] = null;
    } elseif (mb_strlen($subCounty) > 50) {
        $errors['sub_county'] = 'Sub-county must be at most 50 characters.';
    } else {
        $data['sub_county'] = $subCounty;
    }

    foreach (['latitude' => 'Latitude', 'longitude' => 'Longitude'] as $key => $label) {
        $value = $body[$key] ?? ($existing[$key] ?? null);
        if ($value === null || $value === '') {
            $data[$key] = null;
            continue;
        }
        if (!is_numeric($value)) {
            $errors[$key] = "$label must be a number.";
        } else {
            $data[$key] = round((float) $value, 6);
        }
    }
    if ($data['latitude'] !== null && ($data['latitude'] < -90 || $data['latitude'] > 90)) {
        $errors['latitude'] = 'Latitude must be between -90 and 90.';
    }
    if ($data['longitude'] !== null && ($data['longitude'] < -180 || $data['longitude'] > 180)) {
        $errors['longitude'] = 'Longitude must be between -180 and 180.';
    }

    if (array_key_exists('is_active', $body)) {
        $data['is_active'] = (int) (filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
    } elseif ($existing !== null) {
        $data['is_active'] = (int) $existing['is_active'];
    }

    return [$errors, $data];
}

function market_name_taken(PDO $pdo, string $name, ?int $ignoreId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM markets WHERE name = ? AND id <> ?');
    $stmt->execute([$name, $ignoreId ?? 0]);
    return (bool) $stmt->fetch();
}

function create_market(PDO $pdo, array $body, array $user): void
{
    [$errors, $data] = validate_market_payload($body);
    if (isset($data['name']) && market_name_taken($pdo, $data['name'], null)) {
        $errors['name'] = 'A market with this name already exists.';
    }
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO markets (name, county, sub_county, latitude, longitude, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'], $data['county'], $data['sub_county'],
            $data['latitude'], $data['longitude'], $data['is_active'] ?? 1,
        ]);
        $id = (int) $pdo->lastInsertId();
        audit_log($pdo, (int) $user['id'], 'create', 'markets', $id, null, [
            'name' => $data['name'], 'county' => $data['county'], 'sub_county' => $data['sub_county'],
        ], client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    cache_forget('public.dashboard');

    $stmt = $pdo->prepare('SELECT *, 0 AS price_record_count FROM markets WHERE id = ?');
    $stmt->execute([$id]);
    json_out($stmt->fetch(), 201);
}

function update_market(PDO $pdo, int $id, array $body, array $user): void
{
    $stmt = $pdo->prepare('SELECT * FROM markets WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        error_out(404, 'Market not found.');
    }

    [$errors, $data] = validate_market_payload($body, $existing);
    if (isset($data['name']) && $data['name'] !== $existing['name'] && market_name_taken($pdo, $data['name'], $id)) {
        $errors['name'] = 'A market with this name already exists.';
    }
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE markets SET name = ?, county = ?, sub_county = ?, latitude = ?, longitude = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['name'], $data['county'], $data['sub_county'],
            $data['latitude'], $data['longitude'], $data['is_active'], $id,
        ]);
        audit_log($pdo, (int) $user['id'], 'update', 'markets', $id, [
            'name' => $existing['name'], 'county' => $existing['county'],
            'sub_county' => $existing['sub_county'], 'is_active' => (int) $existing['is_active'],
        ], [
            'name' => $data['name'], 'county' => $data['county'],
            'sub_county' => $data['sub_county'], 'is_active' => $data['is_active'],
        ], client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    cache_forget('public.dashboard');

    $stmt = $pdo->prepare(
        'SELECT m.*, (SELECT COUNT(*) FROM price_records pr WHERE pr.market_id = m.id) AS price_record_count
         FROM markets m WHERE m.id = ?'
    );
    $stmt->execute([$id]);
    json_out($stmt->fetch());
}

/** DELETE is a soft delete (is_active = false). Rows are never removed, so FK rules hold. */
function soft_delete_market(PDO $pdo, int $id, array $user): void
{
    $stmt = $pdo->prepare('SELECT * FROM markets WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        error_out(404, 'Market not found.');
    }
    if ((int) $existing['is_active'] === 0) {
        json_out(['detail' => 'Market was already inactive.', 'market' => $existing]);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE markets SET is_active = 0 WHERE id = ?')->execute([$id]);
        audit_log($pdo, (int) $user['id'], 'delete', 'markets', $id, [
            'name' => $existing['name'], 'is_active' => 1,
        ], [
            'name' => $existing['name'], 'is_active' => 0, 'mode' => 'soft delete',
        ], client_ip());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    cache_forget('public.dashboard');

    json_out(['detail' => 'Market deactivated.', 'market' => ['id' => $id, 'is_active' => 0]]);
}
