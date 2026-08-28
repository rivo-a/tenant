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
            JOIN rooms r ON t.room_id = r.id
            WHERE t.id = ? AND r.admin_id = ?
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
       TENANT CREATION (SAFE)
       ===================================================== */
    public function addTenant(array $data, int $adminId): int
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

            // ===== INSERT TENANT WITH OWNERSHIP, RENT SNAPSHOT, AND DUE DATE =====
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
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $adminId,
                $roomId,
                $fullName,
                $phone,
                $email,
                $moveInDate,
                $moveInDate,
                $monthlyRentValue
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

            return $tenantId;

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
        $this->assertTenantOwnership($tenantId, $adminId);

        // Safe defaults
        $fullName   = trim($data['full_name'] ?? '');
        $phone      = trim($data['phone'] ?? '');
        $email      = trim($data['email'] ?? '');
        $moveInDate = $data['move_in_date'] ?? null;

        $stmt = $this->pdo->prepare("
            UPDATE tenants
            SET full_name = ?, phone = ?, email = ?, move_in_date = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $fullName,
            $phone,
            $email,
            $moveInDate,
            $tenantId
        ]);

        if (function_exists('logAudit')) {
            try {
                logAudit(
                    $adminId,
                    'TENANT_UPDATED',
                    "Tenant #{$tenantId} details updated."
                );
            } catch (Throwable $ignored) {}
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