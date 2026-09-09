<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isSecure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /tenant-system/public/portal/login.php');
    exit;
}

$phone    = trim($_POST['phone'] ?? '');
$password = $_POST['password'] ?? '';

if ($phone === '' || $password === '') {
    header('Location: /tenant-system/public/portal/login.php?error=invalid');
    exit;
}

try {
    $pdo = getDB();

    // Fetch tenant by phone
    $stmt = $pdo->prepare("
        SELECT id, full_name, password_hash, account_status, 
               must_change_password, login_attempts, locked_until 
        FROM tenants 
        WHERE phone = :phone 
        LIMIT 1
    ");
    $stmt->execute(['phone' => $phone]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- AUDIT HELPER ---
    $logAudit = function (string $action, ?int $actorId = null, ?string $details = null) use ($pdo): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (actor_type, actor_id, action, target_type, target_id, details, ip_address)
                VALUES ('tenant', :actor_id, :action, 'tenant', :target_id, :details, :ip)
            ");
            $stmt->execute([
                'actor_id'  => $actorId ?? 0,
                'action'    => $action,
                'target_id' => $actorId ?? 0,
                'details'   => $details,
                'ip'        => $ip
            ]);
        } catch (Throwable $ignored) {}
    };

    // --- CHECK IF TENANT EXISTS ---
    if (!$tenant) {
        $logAudit('LOGIN_FAILED_UNKNOWN_PHONE', null, "Phone: " . substr($phone, 0, 4) . '****');
        header('Location: /tenant-system/public/portal/login.php?error=invalid');
        exit;
    }

    $tenantId = (int)$tenant['id'];

    // --- CHECK ACCOUNT STATUS ---
    $status = $tenant['account_status'] ?? 'active';

    if ($status === 'disabled') {
        $logAudit('LOGIN_BLOCKED_DISABLED', $tenantId);
        header('Location: /tenant-system/public/portal/login.php?error=disabled');
        exit;
    }

    if ($status === 'revoked') {
        $logAudit('LOGIN_BLOCKED_REVOKED', $tenantId);
        header('Location: /tenant-system/public/portal/login.php?error=revoked');
        exit;
    }

    // --- CHECK LOCKOUT ---
    $maxAttempts = 5;
    $lockoutMinutes = 15;

    if ($tenant['locked_until'] !== null) {
        $lockedUntil = strtotime($tenant['locked_until']);
        if ($lockedUntil > time()) {
            $logAudit('LOGIN_BLOCKED_LOCKED', $tenantId);
            header('Location: /tenant-system/public/portal/login.php?error=locked');
            exit;
        } else {
            // Lockout expired, reset attempts
            $pdo->prepare("UPDATE tenants SET login_attempts = 0, locked_until = NULL WHERE id = ?")
                ->execute([$tenantId]);
        }
    }

    // --- VERIFY PASSWORD ---
    if (!password_verify($password, $tenant['password_hash'] ?? '')) {
        // Increment failed attempts
        $newAttempts = (int)$tenant['login_attempts'] + 1;
        $lockedUntilValue = null;

        if ($newAttempts >= $maxAttempts) {
            $lockedUntilValue = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
        }

        $pdo->prepare("UPDATE tenants SET login_attempts = ?, locked_until = ? WHERE id = ?")
            ->execute([$newAttempts, $lockedUntilValue, $tenantId]);

        $logAudit('LOGIN_FAILED_WRONG_PASSWORD', $tenantId, "Attempt {$newAttempts}/{$maxAttempts}");

        if ($newAttempts >= $maxAttempts) {
            header('Location: /tenant-system/public/portal/login.php?error=locked');
        } else {
            header('Location: /tenant-system/public/portal/login.php?error=invalid');
        }
        exit;
    }

    // --- SUCCESSFUL LOGIN ---
    // Reset failed attempts
    $pdo->prepare("UPDATE tenants SET login_attempts = 0, locked_until = NULL, last_login = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$tenantId]);

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['tenant_id']           = $tenantId;
    $_SESSION['tenant_role']         = 'tenant';
    $_SESSION['tenant_name']         = $tenant['full_name'];
    $_SESSION['tenant_last_activity'] = time();

    $logAudit('LOGIN_SUCCESS', $tenantId);

    // Force password change if required
    if ((int)$tenant['must_change_password'] === 1 || $status === 'password_reset_required') {
        header('Location: /tenant-system/public/portal/change_password.php');
    } else {
        header('Location: /tenant-system/public/portal/dashboard.php');
    }
    exit;

} catch (Throwable $e) {
    error_log("Tenant login error: " . $e->getMessage());
    header('Location: /tenant-system/public/portal/login.php?error=invalid');
    exit;
}
?>