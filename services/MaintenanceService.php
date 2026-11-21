<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/audit.php';

/**
 * Maintenance requests, tenant submission through landlord resolution.
 *
 * Ownership: every request carries the tenant's admin_id, so a landlord only
 * ever sees their own business and a tenant only their own requests. All
 * assignment, status and attachment lookups are scoped by those keys.
 *
 * Statuses: submitted -> under_review -> assigned -> in_progress -> completed
 * or rejected. Legacy 'acknowledged' and 'resolved' are still rendered.
 */
final class MaintenanceService
{
    public const CATEGORIES = [
        'Plumbing',
        'Electrical',
        'Appliance',
        'Structural',
        'Pest control',
        'Security',
        'Other',
    ];

    public const URGENCIES = ['low', 'normal', 'urgent'];

    public const STATUSES = ['submitted', 'under_review', 'assigned', 'in_progress', 'completed', 'rejected'];

    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private PDO $pdo;

    private string $uploadDir;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->uploadDir = dirname(__DIR__) . '/storage/maintenance_attachments';
    }

    /* ---------------------------------------------------------------------
       Tenant side
    --------------------------------------------------------------------- */

    /**
     * @param array{category?: string, description?: string, urgency?: string, attachment?: array|null} $data
     */
    public function createRequest(int $tenantId, array $data): int
    {
        $stmt = $this->pdo->prepare("
            SELECT t.room_id, t.admin_id
            FROM tenants t
            WHERE t.id = :id
              AND LOWER(COALESCE(t.status, '')) = 'active'
              AND t.exit_date IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tenant) {
            throw new RuntimeException('This tenancy is not active, so a request cannot be raised.');
        }

        $adminId = (int)($tenant['admin_id'] ?? 0);
        if ($adminId <= 0) {
            throw new RuntimeException('This tenancy is not linked to a landlord.');
        }

        $category = trim((string)($data['category'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $urgency = strtolower(trim((string)($data['urgency'] ?? 'normal')));

        if (!in_array($category, self::CATEGORIES, true)) {
            throw new RuntimeException('Choose a valid issue category.');
        }
        if (mb_strlen($description) < 10) {
            throw new RuntimeException('Describe the problem in at least 10 characters.');
        }
        if (mb_strlen($description) > 2000) {
            throw new RuntimeException('Keep the description under 2000 characters.');
        }
        if (!in_array($urgency, self::URGENCIES, true)) {
            $urgency = 'normal';
        }

        $attachment = $this->storeUpload($data['attachment'] ?? null);

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare("
                INSERT INTO maintenance_requests
                    (tenant_id, admin_id, room_id, category, description, urgency, attachment_path, status, created_at)
                VALUES
                    (:tenant_id, :admin_id, :room_id, :category, :description, :urgency, :attachment, 'submitted', CURRENT_TIMESTAMP)
            ");
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':admin_id' => $adminId,
                ':room_id' => (int)$tenant['room_id'],
                ':category' => $category,
                ':description' => $description,
                ':urgency' => $urgency,
                ':attachment' => $attachment,
            ]);

            $requestId = (int)$this->pdo->lastInsertId();
            $this->logUpdate($requestId, 'tenant', $tenantId, null, 'submitted', 'Request submitted by the tenant.');

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit($tenantId, 'tenant', 'MAINTENANCE_SUBMITTED', "Maintenance request #{$requestId} ({$category}) submitted.", $requestId, $adminId);

        return $requestId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTenantRequests(int $tenantId, string $filter = 'all'): array
    {
        $sql = "
            SELECT mr.id, mr.category, mr.description, mr.urgency, mr.attachment_path,
                   mr.status, mr.created_at, mr.updated_at, mr.closed_at, mr.resolution_note,
                   (SELECT u.message FROM maintenance_request_updates u
                     WHERE u.request_id = mr.id AND u.actor_type = 'admin'
                     ORDER BY u.created_at DESC, u.id DESC LIMIT 1) AS last_admin_message,
                   (SELECT COUNT(*) FROM maintenance_request_updates u WHERE u.request_id = mr.id) AS update_count
            FROM maintenance_requests mr
            WHERE mr.tenant_id = :tenant_id
        ";

        if ($filter === 'open') {
            $sql .= " AND mr.status IN ('submitted','under_review','assigned','in_progress') ";
        } elseif ($filter === 'resolved') {
            $sql .= " AND mr.status IN ('completed','rejected','resolved') ";
        }

        $sql .= ' ORDER BY mr.created_at DESC, mr.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * One request, only when it belongs to the tenant.
     *
     * @return array<string, mixed>|null
     */
    public function getTenantRequest(int $tenantId, int $requestId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, category, description, urgency, attachment_path, status,
                   created_at, updated_at, closed_at, resolution_note
            FROM maintenance_requests
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTimeline(int $requestId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT actor_type, old_status, new_status, message, created_at
            FROM maintenance_request_updates
            WHERE request_id = :request_id
            ORDER BY created_at ASC, id ASC
        ");
        $stmt->execute([':request_id' => $requestId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Attachment details for the owning tenant, or null.
     *
     * @return array{path: string, name: string}|null
     */
    public function attachmentForTenant(int $tenantId, int $requestId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT attachment_path FROM maintenance_requests
            WHERE id = :id AND tenant_id = :tenant_id AND attachment_path IS NOT NULL
            LIMIT 1
        ');
        $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId]);

        return $this->resolveAttachment((string)($stmt->fetchColumn() ?: ''));
    }

    /* ---------------------------------------------------------------------
       Landlord and property manager side
    --------------------------------------------------------------------- */

    /**
     * @param array{status?: string, urgency?: string, assigned?: string, search?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function getBusinessRequests(int $adminId, array $filters = []): array
    {
        $sql = "
            SELECT mr.id, mr.category, mr.description, mr.urgency, mr.status, mr.attachment_path,
                   mr.created_at, mr.updated_at, mr.closed_at, mr.resolution_note,
                   t.full_name AS tenant_name, t.phone AS tenant_phone,
                   r.room_number, rt.name AS room_type,
                   a.full_name AS assigned_name,
                   (SELECT u.message FROM maintenance_request_updates u
                     WHERE u.request_id = mr.id AND u.actor_type = 'admin'
                     ORDER BY u.created_at DESC, u.id DESC LIMIT 1) AS last_admin_message
            FROM maintenance_requests mr
            JOIN tenants t ON t.id = mr.tenant_id
            LEFT JOIN rooms r ON r.id = mr.room_id
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            LEFT JOIN admins a ON a.id = mr.assigned_to
            WHERE mr.admin_id = :admin_id
        ";

        $params = [':admin_id' => $adminId];

        $status = strtolower(trim((string)($filters['status'] ?? '')));
        if ($status === 'open') {
            $sql .= " AND mr.status IN ('submitted','under_review','assigned','in_progress') ";
        } elseif (in_array($status, self::STATUSES, true)) {
            $sql .= ' AND mr.status = :status ';
            $params[':status'] = $status;
        }

        $urgency = strtolower(trim((string)($filters['urgency'] ?? '')));
        if (in_array($urgency, self::URGENCIES, true)) {
            $sql .= ' AND mr.urgency = :urgency ';
            $params[':urgency'] = $urgency;
        }

        if ((string)($filters['assigned'] ?? '') === 'unassigned') {
            $sql .= ' AND mr.assigned_to IS NULL ';
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $sql .= ' AND (t.full_name LIKE :search OR r.room_number LIKE :search OR mr.description LIKE :search) ';
            $params[':search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY CASE mr.urgency WHEN \'urgent\' THEN 0 WHEN \'normal\' THEN 1 ELSE 2 END, mr.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBusinessRequest(int $adminId, int $requestId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT mr.*, t.full_name AS tenant_name, t.phone AS tenant_phone,
                   r.room_number, rt.name AS room_type, a.full_name AS assigned_name
            FROM maintenance_requests mr
            JOIN tenants t ON t.id = mr.tenant_id
            LEFT JOIN rooms r ON r.id = mr.room_id
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            LEFT JOIN admins a ON a.id = mr.assigned_to
            WHERE mr.id = :id AND mr.admin_id = :admin_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $requestId, ':admin_id' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Attachment details for the owning business, or null.
     *
     * @return array{path: string, name: string}|null
     */
    public function attachmentForBusiness(int $adminId, int $requestId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT attachment_path FROM maintenance_requests
            WHERE id = :id AND admin_id = :admin_id AND attachment_path IS NOT NULL
            LIMIT 1
        ');
        $stmt->execute([':id' => $requestId, ':admin_id' => $adminId]);

        return $this->resolveAttachment((string)($stmt->fetchColumn() ?: ''));
    }

    /**
     * Staff of this business who may be assigned work.
     *
     * @return list<array{id: int, full_name: string, role: string}>
     */
    public function assignableStaff(int $adminId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, full_name, role
            FROM admins
            WHERE is_active = 1
              AND (id = :b1 OR landlord_id = :b2)
            ORDER BY full_name
        ");
        $stmt->execute([':b1' => $adminId, ':b2' => $adminId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function assign(int $adminId, int $requestId, int $staffId, int $actorId, string $message = ''): void
    {
        $request = $this->requireRequest($adminId, $requestId);

        $staff = null;
        foreach ($this->assignableStaff($adminId) as $candidate) {
            if ((int)$candidate['id'] === $staffId) {
                $staff = $candidate;
                break;
            }
        }

        if ($staff === null) {
            throw new RuntimeException('That person is not active staff of this business.');
        }

        $message = trim($message);
        if (mb_strlen($message) > 500) {
            throw new RuntimeException('Keep the note under 500 characters.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE maintenance_requests
                SET assigned_to = :staff_id,
                    status = CASE WHEN status IN ('submitted','under_review') THEN 'assigned' ELSE status END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND admin_id = :admin_id
            ");
            $stmt->execute([':staff_id' => $staffId, ':id' => $requestId, ':admin_id' => $adminId]);

            $newStatus = in_array((string)$request['status'], ['submitted', 'under_review'], true)
                ? 'assigned'
                : (string)$request['status'];

            $this->logUpdate(
                $requestId,
                'admin',
                $actorId,
                (string)$request['status'],
                $newStatus,
                'Assigned to ' . $staff['full_name'] . ($message !== '' ? '. ' . $message : '.')
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit($actorId, 'admin', 'MAINTENANCE_ASSIGNED', "Request #{$requestId} assigned to {$staff['full_name']}.", $requestId, $adminId);
    }

    public function updateStatus(
        int $adminId,
        int $requestId,
        string $status,
        int $actorId,
        string $message = '',
        string $resolutionNote = ''
    ): void {
        $request = $this->requireRequest($adminId, $requestId);

        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Unknown maintenance status.');
        }

        $current = (string)$request['status'];
        if ($current === $status) {
            throw new RuntimeException('The request is already in that state.');
        }

        $message = trim($message);
        $resolutionNote = trim($resolutionNote);
        if (mb_strlen($message) > 500) {
            throw new RuntimeException('Keep the note under 500 characters.');
        }
        if ($status === 'rejected' && $message === '') {
            throw new RuntimeException('Give a reason when rejecting a request.');
        }

        $closed = in_array($status, ['completed', 'rejected'], true);

        $this->pdo->beginTransaction();
        try {
            /*
             * Built in PHP rather than CASE WHEN :param = 1: PDO binds every
             * value as a string here, and '1' = 1 is false in SQLite, which
             * silently skipped the resolution note and the closed timestamp.
             */
            $set = ['status = :status', 'updated_at = CURRENT_TIMESTAMP'];
            $params = [
                ':status' => $status,
                ':id' => $requestId,
                ':admin_id' => $adminId,
            ];

            if ($resolutionNote !== '') {
                $set[] = 'resolution_note = :note';
                $params[':note'] = $resolutionNote;
            }

            $set[] = $closed ? 'closed_at = CURRENT_TIMESTAMP' : 'closed_at = NULL';

            $stmt = $this->pdo->prepare(
                'UPDATE maintenance_requests SET ' . implode(', ', $set)
                . ' WHERE id = :id AND admin_id = :admin_id'
            );
            $stmt->execute($params);

            $this->logUpdate(
                $requestId,
                'admin',
                $actorId,
                $current,
                $status,
                $message !== '' ? $message : 'Status changed to ' . str_replace('_', ' ', $status) . '.'
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit(
            $actorId,
            'admin',
            'MAINTENANCE_STATUS_CHANGED',
            "Request #{$requestId}: {$current} -> {$status}." . ($message !== '' ? ' ' . $message : ''),
            $requestId,
            $adminId
        );
    }

    /* ---------------------------------------------------------------------
       Internals
    --------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function requireRequest(int $adminId, int $requestId): array
    {
        $request = $this->getBusinessRequest($adminId, $requestId);

        if ($request === null) {
            throw new RuntimeException('Maintenance request not found.');
        }

        return $request;
    }

    /**
     * @param array<string, mixed>|null $file a single $_FILES entry
     */
    private function storeUpload(?array $file): ?string
    {
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The attachment could not be uploaded.');
        }

        if (!is_uploaded_file((string)$file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new RuntimeException('The attachment could not be verified.');
        }

        if ((int)$file['size'] > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Attachments must be 5MB or smaller.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)$file['tmp_name']);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException('Attach a JPG, PNG, WEBP or PDF file.');
        }

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            throw new RuntimeException('Attachment storage is unavailable.');
        }

        if (!is_file($this->uploadDir . '/.htaccess')) {
            file_put_contents($this->uploadDir . '/.htaccess', "Require all denied\nDeny from all\nphp_flag engine off\n");
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME[$mime];
        $destination = $this->uploadDir . '/' . $name;

        $moved = PHP_SAPI === 'cli'
            ? @rename((string)$file['tmp_name'], $destination)
            : move_uploaded_file((string)$file['tmp_name'], $destination);

        if (!$moved) {
            throw new RuntimeException('The attachment could not be saved.');
        }

        return $name;
    }

    /**
     * @return array{path: string, name: string}|null
     */
    private function resolveAttachment(string $storedName): ?array
    {
        $storedName = basename(trim($storedName));
        if ($storedName === '' || $storedName === '.' || $storedName === '..') {
            return null;
        }

        $path = $this->uploadDir . '/' . $storedName;
        if (!is_file($path)) {
            return null;
        }

        return ['path' => $path, 'name' => $storedName];
    }

    private function logUpdate(int $requestId, string $actorType, int $actorId, ?string $oldStatus, string $newStatus, string $message): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO maintenance_request_updates (request_id, actor_type, actor_id, old_status, new_status, message)
            VALUES (:request_id, :actor_type, :actor_id, :old_status, :new_status, :message)
        ");
        $stmt->execute([
            ':request_id' => $requestId,
            ':actor_type' => $actorType,
            ':actor_id' => $actorId,
            ':old_status' => $oldStatus,
            ':new_status' => $newStatus,
            ':message' => $message,
        ]);
    }

    private function audit(int $actorId, string $actorType, string $action, string $details, int $requestId, int $adminId): void
    {
        try {
            audit_log_event($this->pdo, $actorId, $action, $details, [
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'owner_admin_id' => $adminId > 0 ? $adminId : null,
                'target_type' => 'maintenance_request',
                'target_id' => $requestId,
            ]);
        } catch (Throwable $ignored) {
            // A failed audit write must not lose the tenant's request.
        }
    }
}
