<?php
declare(strict_types=1);

function handle_audit_logs(string $method): void
{
    if ($method !== 'GET') {
        error_out(405, 'Method not allowed.');
    }

    $where = '';
    $params = [];
    if (!empty($_GET['action'])) {
        $where .= ($where ? ' AND ' : ' WHERE ') . 'a.action = ?';
        $params[] = $_GET['action'];
    }
    if (!empty($_GET['table'])) {
        $where .= ($where ? ' AND ' : ' WHERE ') . 'a.table_name = ?';
        $params[] = $_GET['table'];
    }

    $stmt = db()->prepare(
        "SELECT a.id, a.timestamp, COALESCE(u.full_name, 'System') AS user_name, a.user_id,
                a.action, a.table_name, a.record_id, a.old_value, a.new_value, a.ip_address
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.user_id
         $where
         ORDER BY a.id DESC
         LIMIT 500"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    foreach ($rows as &$row) {
        $row['old_value'] = $row['old_value'] !== null ? json_decode($row['old_value'], true) : null;
        $row['new_value'] = $row['new_value'] !== null ? json_decode($row['new_value'], true) : null;
        $row['record_id'] = $row['record_id'] !== null ? (int) $row['record_id'] : null;
        $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
    }
    unset($row);

    json_out($rows);
}
