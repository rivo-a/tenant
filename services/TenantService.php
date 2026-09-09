<?php
declare(strict_types=1);

class TenantService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /* =====================================================
       CORE OWNERSHIP GUARD
       ===================================================== */
    private function assertTenantOwnership(int $tenantId, int $adminId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*, r.id AS room_id, r.room_number
            FROM tenants t
            LEFT JOIN rooms r ON t.room_id = r.id
            WHERE t.id = ? AND t.admin_id = ?
        ");
        $stmt->execute([$tenantId, $adminId]);

        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) {
            throw new Exception("Unauthorized tenant access.");
        }

        return $tenant;
    }

    private function assertRoomOwnership(int $roomId, int $adminId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                r.*,
                COALESCE(NULLIF(r.monthly_rent, 0), rt.default_monthly_rent) AS effective_monthly_rent,
                EXISTS (
                    SELECT 1
                    FROM tenants t
                    WHERE t.room_id = r.id
                      AND t.status = 'active'
                ) AS has_active_tenant
            FROM rooms r
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            WHERE r.id = ? AND r.admin_id = ?
        ");
        $stmt->execute([$roomId, $adminId]);

        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            throw new Exception("Unauthorized room access.");
        }

        return $room;
    }

    /* =====================================================
       SAFE TENANT QUERIES
       ===================================================== */
    public function getTenantsByAdmin(int $adminId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*, r.room_number, r.status AS room_status
            FROM tenants t
            JOIN rooms r ON t.room_id = r.id
            WHERE r.admin_id = ?
            ORDER BY t.full_name
        ");
        $stmt->execute([$adminId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTenant(int $tenantId, int $adminId): array
    {
        return $this->assertTenantOwnership($tenantId, $adminId);
    }

    /* =====================================================
       ROOM AVAILABILITY
       ===================================================== */
    public function getFreeRooms(int $adminId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                r.id,
                r.room_number,
                rt.name AS room_type,
                COALESCE(NULLIF(r.monthly_rent, 0), rt.default_monthly_rent) AS rent
            FROM rooms r
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            WHERE r.admin_id = ?
              AND r.status = 'free'
              AND NOT EXISTS (
                  SELECT 1
                  FROM tenants t
                  WHERE t.room_id = r.id
                    AND t.status = 'active'
              )
            ORDER BY r.room_number
        ");
        $stmt->execute([$adminId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

        /* =====================================================
       TENANT CREATION (SAFE + PORTAL CREDENTIALS)
       ===================================================== */
    /**
     * @return array{tenant_id: int, temp_password: string}
     */
    public function addTenant(array $data, int $adminId): array
    {
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }

            // Safe defaults
            $fullName    = trim($data['full_name'] ?? '');
            $phone       = trim($data['phone'] ?? '');
            $email       = trim($data['email'] ?? '');
            $roomId      = (int)($data['room_id'] ?? 0);
            $moveInDate  = $data['move_in_date'] ?? date('Y-m-d');

            if ($fullName === '') {
                throw new Exception("Tenant name is required.");
            }

            // NEW: Phone is now strictly required for portal authentication
            if ($phone === '') {
                throw new Exception("Phone number is required for portal access.");
            }
            // Basic phone format validation (adjust regex to match your local format, e.g., Uganda +256 or 07...)
            if (!preg_match('/^[0-9\+\-\s]{7,15}$/', $phone)) {
                throw new Exception("Invalid phone number format.");
            }

            if (!$roomId) {
                throw new Exception("Room ID is required.");
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new Exception("Invalid tenant email address.");
            }

            $moveInDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $moveInDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            $hasDateErrors = $dateErrors !== false && (
                $dateErrors['warning_count'] > 0 ||
                $dateErrors['error_count'] > 0
            );

            if (
                $moveInDateObject === false ||
                $hasDateErrors ||
                $moveInDateObject->format('Y-m-d') !== $moveInDate
            ) {
                throw new Exception("Invalid move-in date.");
            }

            $room = $this->assertRoomOwnership($roomId, $adminId);

            if ($room['status'] !== 'free' || (int)($room['has_active_tenant'] ?? 0) === 1) {
                throw new Exception("Room is not available.");
            }

            $monthlyRent = (float)($room['effective_monthly_rent'] ?? 0);
            $monthlyRentValue = $monthlyRent > 0 ? $monthlyRent : null;

            // ===== GENERATE SECURE TEMPORARY PASSWORD =====
            // Excludes confusing characters like 0, O, 1, l, I
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
            $tempPassword = '';
            for ($i = 0; $i < 8; $i++) {
                $tempPassword .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

            // ===== INSERT TENANT WITH OWNERSHIP, RENT SNAPSHOT, AND PORTAL SECURITY COLUMNS =====
            $stmt = $this->pdo->prepare("
                INSERT INTO tenants (
                    admin_id,
                    room_id,
                    full_name,
                    phone,
                    email,
                    move_in_date,
                    rent_due_date,
                    monthly_rent,
                    status,
                    password_hash,
                    account_status,
                    must_change_password,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, 'password_reset_required', 1, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $adminId,
                $roomId,
                $fullName,
                $phone,
                $email,
                $moveInDate,
                $moveInDate,
                $monthlyRentValue,
                $passwordHash
            ]);

            $tenantId = (int)$this->pdo->lastInsertId();

            // ===== MARK ROOM AS OCCUPIED =====
            $roomUpdate = $this->pdo->prepare("
                UPDATE rooms
                SET status = 'occupied'
                WHERE id = ?
                  AND admin_id = ?
                  AND status = 'free'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM tenants
                      WHERE room_id = ?
                        AND status = 'active'
                        AND id <> ?
                  )
            ");
            $roomUpdate->execute([$roomId, $adminId, $roomId, $tenantId]);

            if ($roomUpdate->rowCount() !== 1) {
                throw new Exception("Room is no longer available.");
            }

            $this->pdo->commit();

            // NEW: Return both the ID and the plain-text password for the admin to see ONCE
            return [
                'tenant_id'     => $tenantId,
                'temp_password' => $tempPassword
            ];

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /* =====================================================
       TENANT UPDATE (SAFE)
       ===================================================== */
    public function updateTenant(int $tenantId, array $data, int $adminId): void
    {
        if ($tenantId <= 0 || $adminId <= 0) {
            throw new RuntimeException('Invalid tenant update request.');
        }

        $this->assertTenantOwnership($tenantId, $adminId);

        $fullName = is_scalar($data['full_name'] ?? null)
            ? trim((string)$data['full_name'])
            : '';
        $phone = is_scalar($data['phone'] ?? null)
            ? trim((string)$data['phone'])
            : '';
        $email = is_scalar($data['email'] ?? null)
            ? trim((string)$data['email'])
            : '';
        $moveInDate = is_scalar($data['move_in_date'] ?? null)
            ? trim((string)$data['move_in_date'])
            : '';

        if ($fullName === '') {
            throw new RuntimeException('Tenant name is required.');
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid tenant email address.');
        }

        $moveInDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $moveInDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $hasDateErrors = $dateErrors !== false && (
            $dateErrors['warning_count'] > 0 ||
            $dateErrors['error_count'] > 0
        );

        if (
            $moveInDateObject === false ||
            $hasDateErrors ||
            $moveInDateObject->format('Y-m-d') !== $moveInDate
        ) {
            throw new RuntimeException('Invalid move-in date.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE tenants
            SET full_name = ?, phone = ?, email = ?, move_in_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND admin_id = ?
        ");
        $updated = $stmt->execute([
            $fullName,
            $phone,
            $email,
            $moveInDate,
            $tenantId,
            $adminId,
        ]);

        if (!$updated) {
            throw new RuntimeException('Tenant details could not be updated.');
        }

        if (function_exists('logAudit')) {
            try {
                logAudit(
                    $adminId,
                    'TENANT_UPDATED',
                    "Tenant #{$tenantId} details updated."
                );
            } catch (Throwable $ignored) {
                // A successful update must not be undone by an audit failure.
            }
        }
    }

    /* =====================================================
       CHECKOUT / VACATE TENANT
       ===================================================== */
    public function checkoutTenant(int $tenantId, int $adminId): void
    {
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }

            $tenant = $this->assertTenantOwnership($tenantId, $adminId);

            $this->pdo->prepare("
                UPDATE rooms
                SET status = 'free'
                WHERE id = ?
            ")->execute([$tenant['room_id']]);

            $this->pdo->prepare("
                UPDATE tenants
                SET room_id = NULL,
                    status = 'vacated'
                WHERE id = ?
            ")->execute([$tenantId]);

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
?>