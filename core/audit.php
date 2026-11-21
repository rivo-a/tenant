<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Audit writer (single implementation)
|--------------------------------------------------------------------------
| Matches the audit_logs schema:
|   actor_type, actor_id, action, target_type, target_id,
|   details, ip_address, owner_admin_id, created_at
|
| actor_type  who performed the operation  (admin | tenant | system | anonymous)
| actor_id    their own id                  (0 when unauthenticated)
| owner_admin_id  caretaker whose business was affected
|
| Nothing here starts a transaction. Callers decide whether an audit event is
| mandatory (same transaction as the business change) or best-effort.
*/

if (!function_exists('audit_client_ip')) {
    function audit_client_ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($ip) || $ip === '') {
            return null;
        }

        return substr($ip, 0, 45);
    }
}

if (!function_exists('audit_redact')) {
    /**
     * Removes credential material before it can reach the audit table.
     * Words such as password/token/code are masked together with their value,
     * and long opaque strings are dropped even when unlabelled.
     */
    function audit_redact(?string $text, int $maxLength = 1000): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $patterns = [
            // key: value pairs that name a secret
            '/((?:password|passcode|pass\s*code|token|secret|api[\s_-]*key|otp|verification\s*code|access\s*link|temp(?:orary)?\s*password)\s*[:=]?\s*)([^\s,;)]+)/i',
            // bare one-time codes and pins
            '/\b(code|pin|otp)\s*[:=]?\s*\d{4,8}\b/i',
            // opaque tokens and hashes
            '/\b[A-Fa-f0-9]{32,}\b/',
            '/\b[A-Za-z0-9_\-]{40,}\b/',
        ];

        $text = preg_replace($patterns[0], '$1[redacted]', $text) ?? $text;
        $text = preg_replace($patterns[1], '$1 [redacted]', $text) ?? $text;
        $text = preg_replace($patterns[2], '[redacted]', $text) ?? $text;
        $text = preg_replace($patterns[3], '[redacted]', $text) ?? $text;

        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength - 1) . '…';
        }

        return $text;
    }
}

if (!function_exists('audit_actor_for_admin')) {
    /**
     * @return array{type: string, id: int}
     */
    function audit_actor_for_admin(int $adminId): array
    {
        if ($adminId <= 0) {
            return ['type' => 'anonymous', 'id' => 0];
        }

        return ['type' => 'admin', 'id' => $adminId];
    }
}

if (!function_exists('audit_owner_for_admin')) {
    /**
     * The caretaker whose business an action touched.
     * A caretaker acting on their own data is the owner; a super admin is not,
     * so the caller has to say whose business was affected.
     */
    function audit_owner_for_admin(PDO $pdo, int $adminId): ?int
    {
        if ($adminId <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT role FROM admins WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $adminId]);
            $role = $stmt->fetchColumn();
        } catch (Throwable $ignored) {
            return null;
        }

        return strtolower((string)$role) === 'caretaker' ? $adminId : null;
    }
}

if (!function_exists('audit_write')) {
    /**
     * Writes one audit event. Throws when the row cannot be stored so that a
     * caller inside a transaction can roll the business change back.
     *
     * @param array{
     *     action: string,
     *     actor_type?: string,
     *     actor_id?: int,
     *     owner_admin_id?: int|null,
     *     target_type?: string|null,
     *     target_id?: int|null,
     *     details?: string|null,
     *     ip_address?: string|null
     * } $event
     */
    function audit_write(PDO $pdo, array $event): void
    {
        $action = strtoupper(trim((string)($event['action'] ?? '')));
        if ($action === '') {
            throw new RuntimeException('Audit event is missing an action.');
        }

        $actorType = strtolower(trim((string)($event['actor_type'] ?? 'admin')));
        if (!in_array($actorType, ['admin', 'tenant', 'system', 'anonymous'], true)) {
            $actorType = 'admin';
        }

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs
                (actor_type, actor_id, action, target_type, target_id, details, ip_address, owner_admin_id)
            VALUES
                (:actor_type, :actor_id, :action, :target_type, :target_id, :details, :ip_address, :owner_admin_id)
        ");

        $stmt->execute([
            ':actor_type' => $actorType,
            ':actor_id' => max(0, (int)($event['actor_id'] ?? 0)),
            ':action' => $action,
            ':target_type' => isset($event['target_type']) ? (string)$event['target_type'] : null,
            ':target_id' => isset($event['target_id']) ? (int)$event['target_id'] : null,
            ':details' => audit_redact($event['details'] ?? null),
            ':ip_address' => isset($event['ip_address']) ? (string)$event['ip_address'] : audit_client_ip(),
            ':owner_admin_id' => isset($event['owner_admin_id']) ? (int)$event['owner_admin_id'] : null,
        ]);
    }
}

if (!function_exists('audit_log_event')) {
    /**
     * Fail-safe audit for operations that must not be blocked by audit storage
     * (logout, exports, SMS). Failures are reported to the operator log.
     *
     * @param array<string, mixed> $context
     */
    function audit_log_event(PDO $pdo, int $actorId, string $action, string $details = '', array $context = []): void
    {
        $actor = audit_actor_for_admin($actorId);

        try {
            audit_write($pdo, [
                'action' => $action,
                'actor_type' => $context['actor_type'] ?? $actor['type'],
                'actor_id' => $context['actor_id'] ?? $actor['id'],
                'owner_admin_id' => $context['owner_admin_id'] ?? audit_owner_for_admin($pdo, $actorId),
                'target_type' => $context['target_type'] ?? null,
                'target_id' => $context['target_id'] ?? null,
                'details' => $details,
                'ip_address' => $context['ip_address'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log(sprintf(
                'AUDIT WRITE FAILED [%s] actor=%d: %s',
                $action,
                $actorId,
                $e->getMessage()
            ));
        }
    }
}
