<?php
declare(strict_types=1);

function write_audit(PDO $pdo, ?int $userId, string $action, string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues): void
{
    $statement = $pdo->prepare('INSERT INTO audit_logs (user_id, action, table_name, record_id, old_value, new_value, ip_address, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $statement->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $oldValues === null ? null : mpids_json($oldValues),
        $newValues === null ? null : mpids_json($newValues),
        client_ip(),
    ]);
}
