<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/ArrearsService.php';

require_login();
require_capability('tenants.manage');

$pdo = getDB();
$businessId = current_business_id();
$actorId = (int)($_SESSION['admin_id'] ?? 0);
$tenantId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($tenantId <= 0) {
    deny_access('tenants.manage', 'Missing tenant id on checkout.');
}

$stmt = $pdo->prepare('
    SELECT t.id, t.full_name, t.move_in_date, t.status, t.exit_date,
           r.id AS room_id, r.room_number
    FROM tenants t
    LEFT JOIN rooms r ON r.id = t.room_id
    WHERE t.id = :id AND t.admin_id = :admin_id
    LIMIT 1
');
$stmt->execute([':id' => $tenantId, ':admin_id' => $businessId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    http_response_code(404);
    exit('Tenant not found or not part of this business.');
}

if (strtolower((string)$tenant['status']) !== 'active' || !empty($tenant['exit_date'])) {
    http_response_code(409);
    exit('This tenancy has already been closed.');
}

$error = '';
$exitDateInput = $_POST['exit_date'] ?? date('Y-m-d');
$exitReasonInput = trim((string)($_POST['exit_reason'] ?? ''));
$allowUnclearedInput = isset($_POST['allow_uncleared']);

/**
 * Financial position at the chosen exit date, from the same engine the
 * reports, dashboard and portal use. Nothing is recalculated here.
 *
 * @return array<string, mixed>|null
 */
$positionAt = static function (string $date) use ($tenantId): ?array {
    try {
        return (new ArrearsService(getDB()))->getTenantArrears($tenantId, new DateTimeImmutable($date));
    } catch (Throwable $ignored) {
        return null;
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));

        $exitDate = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)$exitDateInput));
        if (!$exitDate || $exitDate->format('Y-m-d') !== trim((string)$exitDateInput)) {
            throw new RuntimeException('Choose a valid exit date.');
        }

        $today = new DateTimeImmutable('today');
        if ($exitDate > $today) {
            throw new RuntimeException('The exit date cannot be in the future.');
        }

        $moveIn = trim((string)($tenant['move_in_date'] ?? ''));
        if ($moveIn !== '') {
            $moveInDate = DateTimeImmutable::createFromFormat('!Y-m-d', $moveIn);
            if ($moveInDate && $exitDate < $moveInDate) {
                throw new RuntimeException('The exit date cannot be before the move-in date.');
            }
        }

        if ($exitReasonInput === '') {
            throw new RuntimeException('Give a reason for the exit.');
        }

        $position = $positionAt($exitDate->format('Y-m-d'));
        if ($position === null) {
            throw new RuntimeException('The balance could not be calculated for that date.');
        }

        $balance = (float)$position['balance'];

        if ($balance > 0.00001 && !$allowUnclearedInput) {
            throw new RuntimeException(
                'This tenant still owes ' . number_format($balance, 0) . '. Collect it, or tick the confirmation to release the room with arrears.'
            );
        }

        $pdo->beginTransaction();

        $exit = $pdo->prepare('
            INSERT INTO tenant_exits
                (tenant_id, admin_id, exit_date, rent_at_exit, billed_total, paid_total, final_balance, reason, allowed_uncleared, recorded_by)
            VALUES
                (:tenant_id, :admin_id, :exit_date, :rent, :billed, :paid, :balance, :reason, :allowed, :recorded_by)
        ');
        $exit->execute([
            ':tenant_id' => $tenantId,
            ':admin_id' => $businessId,
            ':exit_date' => $exitDate->format('Y-m-d'),
            ':rent' => (float)$position['rent'],
            ':billed' => (float)$position['expected'],
            ':paid' => (float)$position['verified_paid'] + (float)$position['pending_paid'],
            ':balance' => $balance,
            ':reason' => $exitReasonInput,
            ':allowed' => $allowUnclearedInput ? 1 : 0,
            ':recorded_by' => $actorId,
        ]);

        $close = $pdo->prepare("
            UPDATE tenants
            SET status = 'exited',
                exit_date = :exit_date,
                account_status = 'exited',
                session_token = NULL,
                must_change_password = 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND admin_id = :admin_id
        ");
        $close->execute([
            ':exit_date' => $exitDate->format('Y-m-d'),
            ':id' => $tenantId,
            ':admin_id' => $businessId,
        ]);

        if (!empty($tenant['room_id'])) {
            $free = $pdo->prepare("UPDATE rooms SET status = 'free' WHERE id = :room_id AND admin_id = :admin_id");
            $free->execute([':room_id' => (int)$tenant['room_id'], ':admin_id' => $businessId]);
        }

        // Any issued portal link and any live portal session die with the tenancy.
        $revoke = $pdo->prepare('UPDATE tenant_access_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE tenant_id = :id AND revoked_at IS NULL');
        $revoke->execute([':id' => $tenantId]);

        $pdo->commit();

        logAudit(
            $actorId,
            'TENANT_EXITED',
            sprintf(
                "%s checked out on %s. Balance UGX %s%s. Reason: %s",
                $tenant['full_name'],
                $exitDate->format('Y-m-d'),
                number_format($balance, 0),
                $balance > 0.00001 ? ' (arrears permitted)' : '',
                $exitReasonInput
            ),
            [
                'target_type' => 'tenant',
                'target_id' => $tenantId,
                'owner_admin_id' => $businessId,
            ]
        );

        header('Location: tenants.php?exit=success');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$previewDate = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)$exitDateInput)) ?: new DateTimeImmutable('today');
$preview = $positionAt($previewDate->format('Y-m-d'));
$previewBalance = (float)($preview['balance'] ?? 0);

$money = static fn($value): string => 'UGX ' . number_format((float)$value, 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exit tenant · Urbahan</title>
    <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<main class="mx-auto max-w-2xl px-4 py-8">

    <header class="mb-4">
        <h1 class="text-2xl font-bold tracking-tight">Exit tenant</h1>
        <p class="mt-1 text-sm text-slate-600">
            <?= e($tenant['full_name']) ?><?= $tenant['room_number'] ? ' · Room ' . e($tenant['room_number']) : '' ?>
            · moved in <?= e($tenant['move_in_date'] ? date('d M Y', strtotime((string)$tenant['move_in_date'])) : '—') ?>
        </p>
    </header>

    <?php if ($error !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Position at <?= e($previewDate->format('d M Y')) ?></h2>
        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-500">Monthly rent</dt>
                <dd class="mt-0.5 font-medium tabular-nums"><?= e($money($preview['rent'] ?? 0)) ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Billed to date</dt>
                <dd class="mt-0.5 font-medium tabular-nums"><?= e($money($preview['expected'] ?? 0)) ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Confirmed paid</dt>
                <dd class="mt-0.5 font-medium tabular-nums"><?= e($money($preview['verified_paid'] ?? 0)) ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Pending</dt>
                <dd class="mt-0.5 font-medium tabular-nums"><?= e($money($preview['pending_paid'] ?? 0)) ?></dd>
            </div>
            <div class="col-span-2 border-t border-slate-100 pt-3">
                <dt class="text-xs text-slate-500">Outstanding balance</dt>
                <dd class="mt-0.5 text-xl font-bold tabular-nums <?= $previewBalance > 0 ? 'text-red-700' : 'text-emerald-700' ?>"><?= e($money($previewBalance)) ?></dd>
            </div>
        </dl>
        <p class="mt-3 text-xs text-slate-500">
            The balance is calculated by the same engine as reports and the tenant portal, at the exit date you choose.
        </p>
    </section>

    <form method="post" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="grid gap-3 sm:grid-cols-2">
            <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                Exit date
                <input type="date" name="exit_date" required max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e($previewDate->format('Y-m-d')) ?>"
                       class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal">
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                Reason
                <input type="text" name="exit_reason" required maxlength="160" placeholder="End of lease, relocation, non-payment…"
                       value="<?= e($exitReasonInput) ?>"
                       class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal">
            </label>
        </div>

        <?php if ($previewBalance > 0): ?>
            <label class="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-3 text-sm text-amber-900">
                <input type="checkbox" name="allow_uncleared" class="mt-0.5" <?= $allowUnclearedInput ? 'checked' : '' ?>>
                <span>
                    Release the room even though <?= e($money($previewBalance)) ?> is outstanding.
                    The arrears stay on the tenant's record and remain reportable.
                </span>
            </label>
        <?php endif; ?>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" class="min-h-11 rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">
                Confirm exit
            </button>
            <a href="tenants.php" class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
        </div>
    </form>
</main>
</body>
</html>
