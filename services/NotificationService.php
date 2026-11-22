<?php
declare(strict_types=1);

/**
 * Tenant notifications.
 *
 * One row per event, deduplicated by `dedupe_key`, so retrying a request can
 * never send the same message twice. Tenants only ever read their own rows.
 */
final class NotificationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function notifyTenant(
        int $tenantId,
        int $adminId,
        string $type,
        string $title,
        string $body,
        string $dedupeKey,
        ?string $targetType = null,
        ?int $targetId = null
    ): void {
        if ($tenantId <= 0 || $adminId <= 0 || $dedupeKey === '') {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT OR IGNORE INTO notifications
                (tenant_id, admin_id, type, title, body, target_type, target_id, dedupe_key)
            VALUES
                (:tenant_id, :admin_id, :type, :title, :body, :target_type, :target_id, :dedupe_key)
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':admin_id' => $adminId,
            ':type' => $type,
            ':title' => $title,
            ':body' => $body !== '' ? $body : null,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':dedupe_key' => $dedupeKey,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function feed(int $tenantId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));

        $stmt = $this->pdo->prepare("
            SELECT id, type, title, body, target_type, target_id, dedupe_key, read_at, created_at
            FROM notifications
            WHERE tenant_id = :tenant_id
            ORDER BY created_at DESC, id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function unreadCount(int $tenantId): int
    {
        if ($tenantId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE tenant_id = :id AND read_at IS NULL');
        $stmt->execute([':id' => $tenantId]);

        return (int)$stmt->fetchColumn();
    }

    public function markRead(int $tenantId, int $notificationId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE notifications SET read_at = CURRENT_TIMESTAMP
            WHERE id = :id AND tenant_id = :tenant_id AND read_at IS NULL
        ');
        $stmt->execute([':id' => $notificationId, ':tenant_id' => $tenantId]);
    }

    public function markAllRead(int $tenantId): int
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE tenant_id = :id AND read_at IS NULL');
        $stmt->execute([':id' => $tenantId]);

        return (int)$stmt->rowCount();
    }
}
