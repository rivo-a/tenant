<?php
declare(strict_types=1);

class MaintenanceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a new maintenance request for a specific tenant.
     */
    public function createRequest(int $tenantId, array $data): int
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Verify tenant owns the room
            $stmt = $this->pdo->prepare("SELECT room_id FROM tenants WHERE id = ? AND status = 'active'");
            $stmt->execute([$tenantId]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                throw new RuntimeException("Invalid tenant or inactive account.");
            }

            $roomId = (int)$tenant['room_id'];
            $category = trim($data['category'] ?? '');
            $description = trim($data['description'] ?? '');

            if ($category === '' || $description === '') {
                throw new RuntimeException("Category and description are required.");
            }

            $attachmentPath = null;

            // 2. Secure File Upload Handling
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['attachment'];
                $maxSize = 5 * 1024 * 1024; // 5MB

                if ($file['size'] > $maxSize) {
                    throw new RuntimeException("File size exceeds 5MB limit.");
                }

                // Validate MIME type strictly
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

                if (!in_array($mimeType, $allowedMimes, true)) {
                    throw new RuntimeException("Invalid file type. Only JPG, PNG, WEBP, or PDF allowed.");
                }

                // Generate secure, random filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);
                $newFilename = bin2hex(random_bytes(16)) . ($safeExtension ? '.' . $safeExtension : '');
                
                // Ensure storage directory exists
                $uploadDir = __DIR__ . '/../storage/maintenance_attachments';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                    // Add .htaccess to prevent PHP execution in this directory
                    file_put_contents($uploadDir . '/.htaccess', "php_flag engine off\nDeny from all");
                }

                $destination = $uploadDir . '/' . $newFilename;
                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    throw new RuntimeException("Failed to save attachment.");
                }

                $attachmentPath = $newFilename;
            }

            // 3. Insert Request
            $stmt = $this->pdo->prepare("
                INSERT INTO maintenance_requests (tenant_id, room_id, category, description, attachment_path, status)
                VALUES (?, ?, ?, ?, ?, 'submitted')
            ");
            $stmt->execute([$tenantId, $roomId, $category, $description, $attachmentPath]);
            $requestId = (int)$this->pdo->lastInsertId();

            // 4. Log initial update
            $this->logUpdate($requestId, 'tenant', $tenantId, null, 'submitted', 'Request submitted.');

            $this->pdo->commit();
            return $requestId;

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get all maintenance requests for a specific tenant (Strictly scoped).
     * Now supports filtering and fetching the latest admin note.
     */
    public function getTenantRequests(int $tenantId, string $filter = 'all'): array
    {
        $sql = "
            SELECT 
                mr.id, 
                mr.category, 
                mr.description, 
                mr.attachment_path, 
                mr.status, 
                mr.created_at, 
                mr.updated_at,
                (SELECT message FROM maintenance_request_updates 
                 WHERE request_id = mr.id AND actor_type = 'admin' 
                 ORDER BY created_at DESC LIMIT 1) as admin_notes
            FROM maintenance_requests mr
            WHERE mr.tenant_id = :tenant_id
        ";
        
        $params = ['tenant_id' => $tenantId];

        if ($filter === 'open') {
            $sql .= " AND mr.status IN ('submitted', 'acknowledged', 'in_progress')";
        } elseif ($filter === 'resolved') {
            $sql .= " AND mr.status IN ('resolved', 'closed')";
        }

        $sql .= " ORDER BY mr.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Log a status change or message in the history table.
     */
    private function logUpdate(int $requestId, string $actorType, int $actorId, ?string $oldStatus, string $newStatus, string $message): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO maintenance_request_updates (request_id, actor_type, actor_id, old_status, new_status, message)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$requestId, $actorType, $actorId, $oldStatus, $newStatus, $message]);
    }
}
?>