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
            SELECT *
            FROM rooms
            WHERE id = ? AND admin_id = ?
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
            SELECT r.id, r.room_number, rt.name AS room_type,
                   COALESCE(r.monthly_rent, rt.default_monthly_rent) AS rent
            FROM rooms r
            JOIN room_types rt ON r.room_type_id = rt.id
            WHERE r.admin_id = ? AND r.status = 'free'
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

            if (!$roomId) {
                throw new Exception("Room ID is required.");
            }

            $room = $this->assertRoomOwnership($roomId, $adminId);

            if ($room['status'] !== 'free') {
                throw new Exception("Room is not available.");
            }

            // ===== INSERT TENANT WITH admin_id AND rent_due_date =====
            $stmt = $this->pdo->prepare("
                INSERT INTO tenants (
                    admin_id,
                    room_id,
                    full_name,
                    phone,
                    email,
                    move_in_date,
                    rent_due_date,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $adminId,
                $roomId,
                $fullName,
                $phone,
                $email,
                $moveInDate,
                $moveInDate // first rent_due_date
            ]);

            $tenantId = (int)$this->pdo->lastInsertId();

            // ===== MARK ROOM AS OCCUPIED =====
            $this->pdo->prepare("
                UPDATE rooms
                SET status = 'occupied'
                WHERE id = ?
            ")->execute([$roomId]);

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