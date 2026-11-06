<?php
declare(strict_types=1);

/** Lightweight, append-only audit trail for administrative lifecycle actions. */
final class AuditLogService
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_audit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_user_id BIGINT UNSIGNED NULL,
                action VARCHAR(80) NOT NULL,
                entity_type VARCHAR(80) NOT NULL,
                entity_id BIGINT UNSIGNED NULL,
                details_json JSON NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_system_audit_action_created (action, created_at),
                KEY idx_system_audit_entity (entity_type, entity_id)
            ) ENGINE=InnoDB"
        );
    }

    public function log(?int $actorUserId, string $action, string $entityType, ?int $entityId = null, array $details = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO system_audit_logs (actor_user_id, action, entity_type, entity_id, details_json)
             VALUES (:actor_user_id, :action, :entity_type, :entity_id, :details_json)'
        );
        $stmt->execute([
            'actor_user_id' => $actorUserId && $actorUserId > 0 ? $actorUserId : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId && $entityId > 0 ? $entityId : null,
            'details_json' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
