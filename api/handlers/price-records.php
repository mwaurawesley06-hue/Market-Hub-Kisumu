<?php
declare(strict_types=1);

function handle_price_records(string $method, ?string $segment1, ?string $segment2, array $body, array $user): void
{
    $pdo = db();

    // POST /api/price-records/ — capture (with duplicate detection + override)
    if ($method === 'POST' && $segment1 === null) {
        capture_price_record($pdo, $body, $user);
    }

    // GET /api/price-records/?status=&commodity=&market=
    if ($method === 'GET' && $segment1 === null) {
        $status = in_array($_GET['status'] ?? '', ['pending', 'published', 'rejected'], true) ? $_GET['status'] : null;
        $filters = [
            'status' => $status,
            'commodity' => optional_id_filter($_GET, 'commodity', 'commodity'),
            'market' => optional_id_filter($_GET, 'market', 'market'),
        ];
        json_out(list_price_records($pdo, $filters));
    }

    if ($segment1 === null) {
        error_out(405, 'Method not allowed.');
    }

    $id = (int) $segment1;
    if ($id <= 0 || !ctype_digit((string) $segment1)) {
        error_out(400, 'Invalid record id.');
    }

    // GET /api/price-records/{id}/ — one record with names (used by the
    // duplicate-warning "View Existing Record" button and the records list).
    if ($method === 'GET' && $segment2 === null) {
        $record = fetch_price_record_detailed($pdo, $id);
        if ($record === null) {
            error_out(404, 'Price record not found.');
        }
        json_out($record);
    }

    // POST /api/price-records/{id}/approve/  body { action: "approve"|"reject", reason }
    if ($method === 'POST' && ($segment2 === 'approve' || $segment2 === 'reject')) {
        decide_price_record($pdo, $id, $segment2, $body, $user);
    }

    // POST /api/price-records/{id}/resubmit/
    if ($method === 'POST' && $segment2 === 'resubmit') {
        resubmit_price_record($pdo, $id, $body, $user);
    }

    error_out(404, 'Not found.');
}

function override_justification_error(string $justification): ?string
{
    // Meaningful text: long enough to explain, and at least three DISTINCT
    // alphabetic words, so "..." or a repeated token cannot pass.
    if (mb_strlen($justification) < 10) {
        return 'The justification must be at least 10 characters.';
    }
    $alphaWords = [];
    foreach (preg_split('/\s+/u', $justification, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        if (preg_match('/\p{L}/u', $word)) {
            $alphaWords[mb_strtolower($word)] = true;
        }
    }
    if (count($alphaWords) < 3) {
        return 'The justification must contain at least three real words, not only numbers or symbols.';
    }
    return null;
}

function capture_price_record(PDO $pdo, array $body, array $user): void
{
    $commodity = fetch_commodity_for_capture($pdo, $body['commodity'] ?? null);
    $market = fetch_market_for_capture($pdo, $body['market'] ?? null);

    [$errors, $data] = validate_capture_payload($body, $commodity, $market);
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    $overrideFlag = $body['override'] ?? null;
    $override = $overrideFlag === true || $overrideFlag === 'true' || $overrideFlag === 1;
    $justification = trim((string) ($body['override_justification'] ?? ''));

    $duplicate = find_duplicate_price_record(
        $pdo,
        $data['commodity_id'],
        $data['market_id'],
        $data['date_observed']
    );

    if ($duplicate !== null && !$override) {
        error_out(409, 'A record for this commodity, market and date already exists.', [
            'duplicate' => true,
            'existing' => fetch_price_record_detailed($pdo, (int) $duplicate['id']),
        ]);
    }

    if ($duplicate !== null && $override) {
        $justificationError = override_justification_error($justification);
        if ($justificationError !== null) {
            error_out(422, 'A written justification is required to override a duplicate price entry.', [
                'errors' => ['override_justification' => $justificationError],
            ]);
        }

        $existingId = (int) $duplicate['id'];
        $old = fetch_price_record_detailed($pdo, $existingId);

        // 409 is reserved for "duplicate detected"; a vanished record is a 404.
        if ($old === null) {
            error_out(404, 'The existing record was removed while you were working. Reload the form and try again.', [
                'duplicate_stale' => true,
            ]);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE price_records
                 SET price = ?, source = ?, status = 'pending', captured_by = ?, approved_by = NULL,
                     rejection_reason = NULL, override_justification = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$data['price'], $data['source'], $user['id'], $justification, $existingId]);
            audit_log(
                $pdo,
                (int) $user['id'],
                'duplicate_override',
                'price_records',
                $existingId,
                array_intersect_key($old, array_flip(['price', 'source', 'status', 'override_justification'])),
                [
                    'price' => $data['price'],
                    'source' => $data['source'],
                    'status' => 'pending',
                    'override_justification' => $justification,
                    'commodity' => $old['commodity_name'],
                    'market' => $old['market_name'],
                    'date_observed' => $old['date_observed'],
                ],
                client_ip()
            );
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        json_out(fetch_price_record_detailed($pdo, $existingId));
    }

    // Fresh capture → status pending
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO price_records
                (commodity_id, market_id, price, currency, date_observed, source, status, captured_by)
             VALUES (?, ?, ?, 'KES', ?, ?, 'pending', ?)"
        );
        $stmt->execute([
            $data['commodity_id'],
            $data['market_id'],
            $data['price'],
            $data['date_observed'],
            $data['source'],
            $user['id'],
        ]);
        $newId = (int) $pdo->lastInsertId();
        audit_log(
            $pdo,
            (int) $user['id'],
            'create',
            'price_records',
            $newId,
            null,
            [
                'commodity' => $commodity['name'],
                'market' => $market['name'],
                'price' => $data['price'],
                'date_observed' => $data['date_observed'],
                'source' => $data['source'],
                'status' => 'pending',
            ],
            client_ip()
        );
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            $duplicate = find_duplicate_price_record(
                $pdo,
                $data['commodity_id'],
                $data['market_id'],
                $data['date_observed']
            );
            error_out(409, 'A record for this commodity, market and date already exists.', [
                'duplicate' => true,
                'existing' => $duplicate === null
                    ? null
                    : fetch_price_record_detailed($pdo, (int) $duplicate['id']),
            ]);
        }
        throw $e;
    }

    json_out(fetch_price_record_detailed($pdo, $newId), 201);
}

function decide_price_record(PDO $pdo, int $id, string $action, array $body, array $user): void
{
    $record = fetch_price_record_detailed($pdo, $id);
    if (!$record) {
        error_out(404, 'Price record not found.');
    }
    if ($record['status'] !== 'pending') {
        error_out(409, "Only pending records can be approved or rejected. This record is {$record['status']}.");
    }

    // Body action must agree with the URL segment; segment wins.
    $bodyAction = (string) ($body['action'] ?? $action);
    if (!in_array($bodyAction, ['approve', 'reject'], true)) {
        error_out(422, "Action must be 'approve' or 'reject'.");
    }
    $action = ($bodyAction === 'approve' || $bodyAction === 'reject') ? $bodyAction : $action;
    $reason = trim((string) ($body['reason'] ?? ''));

    if ($action === 'reject' && $reason === '') {
        error_out(422, 'Validation failed.', ['errors' => ['reason' => 'A rejection reason is required.']]);
    }

    $old = ['status' => $record['status']];

    $pdo->beginTransaction();
    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare(
                "UPDATE price_records
                 SET status = 'published', approved_by = ?, rejection_reason = NULL, updated_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$user['id'], $id]);
            $new = ['status' => 'published', 'price' => (float) $record['price']];
        } else {
            $stmt = $pdo->prepare(
                "UPDATE price_records
                 SET status = 'rejected', approved_by = ?, rejection_reason = ?, updated_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$user['id'], $reason, $id]);
            $new = ['status' => 'rejected', 'reason' => $reason];
        }

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Record state changed concurrently.');
        }

        audit_log(
            $pdo,
            (int) $user['id'],
            $action === 'approve' ? 'approve' : 'reject',
            'price_records',
            $id,
            $old,
            $new,
            client_ip()
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException) {
            throw $e;
        }
        error_out(409, 'Record state changed concurrently. Reload the queue.');
    }

    cache_forget('public.dashboard');
    cache_forget('commodities.active');
    cache_forget('ussd.commodities');

    $updated = fetch_price_record_detailed($pdo, $id);
    if ($action === 'approve') {
        // SMS alerts fire exactly once — on the transition into published.
        $updated['sms'] = sms_alert_publication($pdo, $updated, [
            'id' => $updated['commodity_id'],
            'name' => $updated['commodity_name'],
            'unit_of_measure' => $updated['unit_of_measure'],
        ]);
    }
    json_out($updated);
}

function resubmit_price_record(PDO $pdo, int $id, array $body, array $user): void
{
    $record = fetch_price_record_detailed($pdo, $id);
    if (!$record) {
        error_out(404, 'Price record not found.');
    }
    if ($record['status'] !== 'rejected') {
        error_out(409, 'Only rejected records can be edited and resubmitted.');
    }

    $commodity = fetch_commodity_for_capture($pdo, $body['commodity'] ?? $record['commodity_id']);
    $market = fetch_market_for_capture($pdo, $body['market'] ?? $record['market_id']);
    [$errors, $data] = validate_capture_payload($body + [
        'date_observed' => $body['date_observed'] ?? $record['date_observed'],
        'price' => $body['price'] ?? $record['price'],
        'source' => $body['source'] ?? $record['source'],
    ], $commodity, $market);
    if ($errors) {
        error_out(422, 'Validation failed.', ['errors' => $errors]);
    }

    $duplicate = find_duplicate_price_record(
        $pdo,
        $data['commodity_id'],
        $data['market_id'],
        $data['date_observed'],
        $id
    );
    if ($duplicate !== null) {
        error_out(409, 'A record for this commodity, market and date already exists.', [
            'duplicate' => true,
            'existing' => fetch_price_record_detailed($pdo, (int) $duplicate['id']),
        ]);
    }

    $old = [
        'commodity' => $record['commodity_name'],
        'market' => $record['market_name'],
        'price' => (float) $record['price'],
        'date_observed' => $record['date_observed'],
        'source' => $record['source'],
        'status' => $record['status'],
    ];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE price_records
             SET commodity_id = ?, market_id = ?, price = ?, date_observed = ?, source = ?,
                 status = 'pending', captured_by = ?, approved_by = NULL, rejection_reason = NULL,
                 updated_at = NOW()
             WHERE id = ? AND status = 'rejected'"
        );
        $stmt->execute([
            $data['commodity_id'],
            $data['market_id'],
            $data['price'],
            $data['date_observed'],
            $data['source'],
            $user['id'],
            $id,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Record state changed concurrently.');
        }
        audit_log(
            $pdo,
            (int) $user['id'],
            'resubmit',
            'price_records',
            $id,
            $old,
            [
                'commodity' => $commodity['name'],
                'market' => $market['name'],
                'price' => $data['price'],
                'date_observed' => $data['date_observed'],
                'source' => $data['source'],
                'status' => 'pending',
            ],
            client_ip()
        );
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            $duplicate = find_duplicate_price_record(
                $pdo,
                $data['commodity_id'],
                $data['market_id'],
                $data['date_observed'],
                $id
            );
            error_out(409, 'A record for this commodity, market and date already exists.', [
                'duplicate' => true,
                'existing' => $duplicate === null
                    ? null
                    : fetch_price_record_detailed($pdo, (int) $duplicate['id']),
            ]);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_out(409, 'Record state changed concurrently. Reload and try again.');
    }

    json_out(fetch_price_record_detailed($pdo, $id));
}

function fetch_commodity_for_capture(PDO $pdo, mixed $id): ?array
{
    if ($id === null || $id === '' || !ctype_digit((string) $id)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM commodities WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_market_for_capture(PDO $pdo, mixed $id): ?array
{
    if ($id === null || $id === '' || !ctype_digit((string) $id)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM markets WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
