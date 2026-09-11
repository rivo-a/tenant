<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/tenant_auth.php';

// 1. STRICT SECURITY: Get ID ONLY from the secure server-side session
$tenantId = getLoggedInTenantId();
$pdo = getDB();

// 2. Fetch Tenant & Room Info (Strictly scoped to this tenant_id)
$stmt = $pdo->prepare("
    SELECT t.full_name, t.phone, t.email, t.move_in_date, t.monthly_rent, t.status as tenant_status,
           r.room_number, rt.name as room_type
    FROM tenants t
    LEFT JOIN rooms r ON t.room_id = r.id
    LEFT JOIN room_types rt ON r.room_type_id = rt.id
    WHERE t.id = :tenant_id
    LIMIT 1
");
$stmt->execute(['tenant_id' => $tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    // Failsafe: If session ID doesn't exist in DB, destroy session immediately
    destroyTenantSession();
    header('Location: /tenant-system/public/portal/login.php?error=invalid');
    exit;
}

// 3. Fetch Financial Summary (Strictly scoped to tenant_id)
// Uses 'verification_status' as per your payments table schema
$financeStmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN verification_status = 'done' OR verification_status = 'confirmed' THEN amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN verification_status = 'pending' THEN amount ELSE 0 END) as total_pending
    FROM payments 
    WHERE tenant_id = :tenant_id
");
$financeStmt->execute(['tenant_id' => $tenantId]);
$finance = $financeStmt->fetch(PDO::FETCH_ASSOC);

$totalPaid = (float)($finance['total_paid'] ?? 0);
$totalPending = (float)($finance['total_pending'] ?? 0);
$monthlyRent = (float)($tenant['monthly_rent'] ?? 0);

// Simple balance calculation: Monthly rent minus what is confirmed/done. Pending doesn't reduce balance yet.
$currentBalance = max(0, $monthlyRent - $totalPaid); 

// 4. Fetch Recent Payments (Last 5)
$recentPaymentsStmt = $pdo->prepare("
    SELECT amount, payment_month, verification_status, created_at 
    FROM payments 
    WHERE tenant_id = :tenant_id 
    ORDER BY created_at DESC 
    LIMIT 5
");
$recentPaymentsStmt->execute(['tenant_id' => $tenantId]);
$recentPayments = $recentPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for status badges
function getStatusBadge(string $status): string {
    $status = strtolower($status);
    return match($status) {
        'done', 'confirmed' => '<span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Completed</span>',
        'pending'           => '<span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending Verification</span>',
        'failed', 'rejected'=> '<span class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">Rejected</span>',
        default             => '<span class="inline-flex items-center rounded-full bg-slate-50 px-2 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/10">' . htmlspecialchars($status) . '</span>'
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — Tenant Portal</title>
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
       <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

    <?php require __DIR__ . '/partials/portal_navbar.php'; ?>
    
    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        
        <!-- Welcome Header -->
        <div class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight font-alt">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">Overview of your tenancy, room, and financial status.</p>
        </div>

        <!-- Top Stats Grid -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-8">
            <!-- Current Balance -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <dt class="truncate text-sm font-medium text-slate-500">Current Balance Due</dt>
                <dd class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">
                    UGX <?= number_format($currentBalance, 0) ?>
                </dd>
                <p class="mt-1 text-xs text-slate-400">Monthly Rent: UGX <?= number_format($monthlyRent, 0) ?></p>
            </div>

            <!-- Room Info -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <dt class="truncate text-sm font-medium text-slate-500">Assigned Room</dt>
                <dd class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">
                    <?= htmlspecialchars($tenant['room_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                </dd>
                <p class="mt-1 text-xs text-slate-400"><?= htmlspecialchars($tenant['room_type'] ?? 'Standard', ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <!-- Pending Payments -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <dt class="truncate text-sm font-medium text-slate-500">Pending Verification</dt>
                <dd class="mt-2 text-3xl font-semibold tracking-tight text-amber-600">
                    UGX <?= number_format($totalPending, 0) ?>
                </dd>
                <p class="mt-1 text-xs text-slate-400">Awaiting admin confirmation</p>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            
            <!-- Tenancy Details -->
            <div class="lg:col-span-1 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold leading-6 text-slate-900 mb-4">Tenancy Details</h3>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="px-0 py-3 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="font-medium text-slate-500">Name</dt>
                        <dd class="mt-1 text-slate-900 sm:col-span-2 sm:mt-0"><?= htmlspecialchars($tenant['full_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <div class="px-0 py-3 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="font-medium text-slate-500">Phone</dt>
                        <dd class="mt-1 text-slate-900 sm:col-span-2 sm:mt-0"><?= htmlspecialchars($tenant['phone'], ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <div class="px-0 py-3 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="font-medium text-slate-500">Move-in Date</dt>
                        <dd class="mt-1 text-slate-900 sm:col-span-2 sm:mt-0"><?= htmlspecialchars($tenant['move_in_date'], ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <div class="px-0 py-3 sm:grid sm:grid-cols-3 sm:gap-4">
                        <dt class="font-medium text-slate-500">Status</dt>
                        <dd class="mt-1 sm:col-span-2 sm:mt-0">
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                                <?= ucfirst(htmlspecialchars($tenant['tenant_status'] ?? 'active', ENT_QUOTES, 'UTF-8')) ?>
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Recent Payments -->
            <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-6 py-4 flex justify-between items-center">
                    <h3 class="text-base font-semibold leading-6 text-slate-900">Recent Payments</h3>
                    <a href="/tenant-system/public/portal/payments.php" class="text-sm font-medium text-blue-600 hover:text-blue-500">View all &rarr;</a>
                </div>
                
                <?php if (empty($recentPayments)): ?>
                    <div class="p-8 text-center text-sm text-slate-500">
                        No payment records found.
                    </div>
                <?php else: ?>
                    <ul role="list" class="divide-y divide-slate-100">
                        <?php foreach ($recentPayments as $payment): ?>
                            <li class="flex items-center justify-between gap-x-6 px-6 py-4 hover:bg-slate-50 transition">
                                <div class="min-w-0">
                                    <div class="flex items-start gap-x-3">
                                        <p class="text-sm font-semibold leading-6 text-slate-900">
                                            UGX <?= number_format((float)$payment['amount'], 0) ?>
                                        </p>
                                        <?= getStatusBadge($payment['verification_status']) ?>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        For: <?= htmlspecialchars($payment['payment_month'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?> 
                                        &bull; <?= date('M d, Y', strtotime($payment['created_at'])) ?>
                                    </p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>