<?php
declare(strict_types=1);
ini_set('display_errors', '1'); // <-- ADD THIS
error_reporting(E_ALL);         // <-- ADD THIS

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/tenant_auth.php';
require_once __DIR__ . '/../../services/MaintenanceService.php';

requireTenantAuth();

$tenantId = getLoggedInTenantId();
$pdo = getDB();
$maintenanceService = new MaintenanceService($pdo);

$error = '';
$success = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfValue = $_POST['csrf'] ?? '';
        verify_csrf(is_string($csrfValue) ? $csrfValue : '');

        $requestId = $maintenanceService->createRequest($tenantId, [
            'category'    => $_POST['category'] ?? '',
            'description' => $_POST['description'] ?? '',
        ]);

        $success = 'Maintenance request submitted successfully. Management has been notified.';
        
        // Clear POST data to prevent resubmission on refresh
        header('Location: /tenant-system/public/portal/maintenance.php?success=1');
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Check for success redirect
if (isset($_GET['success'])) {
    $success = 'Maintenance request submitted successfully. Management has been notified.';
}

// Fetch requests
$requests = $maintenanceService->getTenantRequests($tenantId);

// Helper for status badges
function getMaintenanceStatusBadge(string $status): string {
    return match(strtolower($status)) {
        'submitted'     => '<span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10">Submitted</span>',
        'acknowledged'  => '<span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10">Acknowledged</span>',
        'in_progress'   => '<span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-700/10">In Progress</span>',
        'resolved'      => '<span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-700/10">Resolved</span>',
        'closed'        => '<span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/10">Closed</span>',
        default         => '<span class="inline-flex items-center rounded-full bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/10">' . htmlspecialchars($status) . '</span>'
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance — Tenant Portal</title>
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

    <?php require __DIR__ . '/partials/portal_navbar.php'; ?>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight font-alt">Maintenance Requests</h1>
                <p class="mt-1 text-sm text-slate-500">Report an issue in your room and track its progress.</p>
            </div>
            <button onclick="document.getElementById('newRequestModal').classList.remove('hidden')" 
                class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                <svg class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                New Request
            </button>
        </div>

        <?php if ($success): ?>
            <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Requests List -->
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <?php if (empty($requests)): ?>
                <div class="p-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
                    </svg>
                    <h3 class="mt-2 text-sm font-semibold text-slate-900">No requests yet</h3>
                    <p class="mt-1 text-sm text-slate-500">Get started by creating a new maintenance request.</p>
                </div>
            <?php else: ?>
                <ul role="list" class="divide-y divide-slate-100">
                    <?php foreach ($requests as $req): ?>
                        <li class="p-6 hover:bg-slate-50 transition">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($req['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?= getMaintenanceStatusBadge($req['status']) ?>
                                    </div>
                                    <p class="text-sm text-slate-600 mb-3"><?= nl2br(htmlspecialchars($req['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                                    
                                    <?php if ($req['attachment_path']): ?>
                                        <div class="flex items-center gap-2 text-xs text-slate-500 bg-slate-100 inline-flex px-2 py-1 rounded-md">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                                            <span>Attachment included</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <p class="mt-3 text-xs text-slate-400">
                                        Submitted on <?= date('M d, Y \a\t g:i A', strtotime($req['created_at'])) ?>
                                    </p>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </main>

    <!-- New Request Modal -->
    <div id="newRequestModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-slate-900 bg-opacity-75 transition-opacity" onclick="document.getElementById('newRequestModal').classList.add('hidden')"></div>

            <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

            <div class="relative inline-block transform overflow-hidden rounded-2xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <form method="POST" enctype="multipart/form-data" class="p-6">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    
                    <div class="flex justify-between items-center mb-5">
                        <h3 class="text-lg font-bold font-alt text-slate-900" id="modal-title">New Maintenance Request</h3>
                        <button type="button" onclick="document.getElementById('newRequestModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label for="category" class="block text-xs font-semibold text-slate-600">Category</label>
                            <select id="category" name="category" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200">
                                <option value="">Select a category...</option>
                                <option value="Plumbing">Plumbing</option>
                                <option value="Electrical">Electrical</option>
                                <option value="Appliances">Appliances</option>
                                <option value="Furniture">Furniture</option>
                                <option value="General">General / Other</option>
                            </select>
                        </div>

                        <div>
                            <label for="description" class="block text-xs font-semibold text-slate-600">Description</label>
                            <textarea id="description" name="description" rows="4" required placeholder="Please describe the issue in detail..." 
                                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200"></textarea>
                        </div>

                        <div>
                            <label for="attachment" class="block text-xs font-semibold text-slate-600">Attachment (Optional)</label>
                            <input id="attachment" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" 
                                class="mt-2 block w-full text-sm text-slate-500 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                            <p class="mt-1 text-xs text-slate-400">Max 5MB. JPG, PNG, WEBP, or PDF.</p>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" onclick="document.getElementById('newRequestModal').classList.add('hidden')" 
                            class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="submit" 
                            class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>