<?php
declare(strict_types=1);

// 1. SECURE ERROR HANDLING: Only display errors in development
if (getenv('APP_ENV') !== 'production') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/tenant_auth.php';
require_once __DIR__ . '/../../services/MaintenanceService.php';

requireTenantAuth();

$tenantId = getLoggedInTenantId();
$pdo = getDB();
$maintenanceService = new MaintenanceService($pdo);

// Form state preservation
$form = [
    'category'    => '',
    'description' => '',
];
$fieldErrors = [];
$error = '';
$success = '';

// Filtering
$filter = $_GET['filter'] ?? 'all';
$allowedFilters = ['all', 'open', 'resolved'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfValue = $_POST['csrf'] ?? '';
        verify_csrf(is_string($csrfValue) ? $csrfValue : '');

        $form['category'] = trim((string)($_POST['category'] ?? ''));
        $form['description'] = trim((string)($_POST['description'] ?? ''));

        // Validation
        if ($form['category'] === '') {
            $fieldErrors['category'] = 'Please select a category.';
        }
        if ($form['description'] === '') {
            $fieldErrors['description'] = 'Please describe the issue.';
        } elseif (strlen($form['description']) < 10) {
            $fieldErrors['description'] = 'Description must be at least 10 characters.';
        }

        // Secure File Upload Handling
        $attachmentPath = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            
            if ($file['size'] > $maxSize) {
                $fieldErrors['attachment'] = 'File size exceeds the 5MB limit.';
            } elseif (!in_array($file['type'], $allowedTypes, true)) {
                $fieldErrors['attachment'] = 'Invalid file type. Only JPG, PNG, WEBP, or PDF are allowed.';
            } else {
                // Generate secure filename
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $secureName = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
                $uploadDir = __DIR__ . '/../../storage/maintenance/';
                
                // Ensure directory exists
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $destination = $uploadDir . $secureName;
                
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $attachmentPath = 'storage/maintenance/' . $secureName;
                } else {
                    throw new RuntimeException('Failed to save the uploaded file.');
                }
            }
        }

        if ($fieldErrors) {
            throw new RuntimeException('Please correct the highlighted fields.');
        }

        // Pass to service (Update your MaintenanceService to accept $attachmentPath)
        $maintenanceService->createRequest($tenantId, [
            'category'    => $form['category'],
            'description' => $form['description'],
            'attachment'  => $attachmentPath,
        ]);

        $success = 'Maintenance request submitted successfully. Management has been notified.';
        
        // Redirect to clear POST data (PRG Pattern)
        header('Location: /tenant-system/public/portal/maintenance.php?success=1&filter=' . urlencode($filter));
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
        // Keep modal open by passing a flag in the URL or session if needed, 
        // but for simplicity, we'll show errors at the top and repopulate the form.
    }
}

if (isset($_GET['success'])) {
    $success = 'Maintenance request submitted successfully. Management has been notified.';
}

// Fetch requests (Update your service to accept the $filter parameter)
$requests = $maintenanceService->getTenantRequests($tenantId, $filter);

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
    <link rel="icon" href="data:,"> <!-- Fixes favicon 404 -->
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
    <!-- REMOVED CDN Tailwind to comply with CSP -->
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

    <?php require __DIR__ . '/partials/portal_navbar.php'; ?>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight font-alt">Maintenance Requests</h1>
                <p class="mt-1 text-sm text-slate-500">Report an issue in your room and track its progress.</p>
            </div>
            <button type="button" id="openModalBtn" class="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                <svg class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                New Request
            </button>
        </div>

        <?php if ($success): ?>
            <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 flex items-start gap-3" role="status">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 flex items-start gap-3" role="alert">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Filtering Tabs -->
        <div class="mb-4 border-b border-slate-200">
            <nav class="-mb-px flex space-x-6" aria-label="Tabs">
                <a href="?filter=all" class="<?= $filter === 'all' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' ?> whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition">All Requests</a>
                <a href="?filter=open" class="<?= $filter === 'open' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' ?> whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition">Open</a>
                <a href="?filter=resolved" class="<?= $filter === 'resolved' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' ?> whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition">Resolved</a>
            </nav>
        </div>

        <!-- Requests List -->
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <?php if (empty($requests)): ?>
                <div class="p-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
                    </svg>
                    <h3 class="mt-2 text-sm font-semibold text-slate-900">No requests found</h3>
                    <p class="mt-1 text-sm text-slate-500">Get started by creating a new maintenance request.</p>
                </div>
            <?php else: ?>
                <ul role="list" class="divide-y divide-slate-100">
                    <?php foreach ($requests as $req): ?>
                        <li class="p-6 hover:bg-slate-50/80 transition duration-150">
                            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-3 mb-2">
                                        <span class="text-sm font-bold text-slate-900"><?= e($req['category']) ?></span>
                                        <?= getMaintenanceStatusBadge($req['status']) ?>
                                    </div>
                                    <p class="text-sm text-slate-600 mb-3 leading-relaxed"><?= nl2br(e($req['description'])) ?></p>
                                    
                                    <!-- Admin Reply Mockup (Add this to your DB/service if not present) -->
                                    <?php if (!empty($req['admin_notes'])): ?>
                                        <div class="mt-3 rounded-lg bg-blue-50/50 border border-blue-100 p-3">
                                            <p class="text-xs font-semibold text-blue-800 mb-1">Management Note:</p>
                                            <p class="text-xs text-blue-700"><?= nl2br(e($req['admin_notes'])) ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mt-4 flex flex-wrap items-center gap-4">
                                        <?php if ($req['attachment_path']): ?>
                                            <a href="/tenant-system/public/<?= e($req['attachment_path']) ?>" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 px-2.5 py-1.5 rounded-lg transition">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                                                View Attachment
                                            </a>
                                        <?php endif; ?>
                                        <p class="text-xs text-slate-400">
                                            Submitted on <?= date('M d, Y \a\t g:i A', strtotime($req['created_at'])) ?>
                                        </p>
                                    </div>
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
            <div id="modalBackdrop" class="fixed inset-0 bg-slate-900/75 backdrop-blur-sm transition-opacity"></div>

            <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

            <div class="relative inline-block transform overflow-hidden rounded-2xl bg-white text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <form id="maintenanceForm" method="POST" enctype="multipart/form-data" class="p-6" novalidate>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <!-- Preserve filter state -->
                    <input type="hidden" name="filter" value="<?= e($filter) ?>">
                    
                    <div class="flex justify-between items-center mb-5">
                        <h3 class="text-lg font-bold font-alt text-slate-900" id="modal-title">New Maintenance Request</h3>
                        <button type="button" id="closeModalBtn" class="text-slate-400 hover:text-slate-600 transition rounded-lg p-1 hover:bg-slate-100">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label for="category" class="block text-xs font-semibold text-slate-600">Category <span class="text-red-500">*</span></label>
                            <select id="category" name="category" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-blue-500 focus-visible:ring-4 focus-visible:ring-blue-500/10 <?= isset($fieldErrors['category']) ? 'border-red-300 focus-visible:border-red-500 focus-visible:ring-red-500/10' : '' ?>">
                                <option value="">Select a category...</option>
                                <option value="Plumbing" <?= $form['category'] === 'Plumbing' ? 'selected' : '' ?>>Plumbing</option>
                                <option value="Electrical" <?= $form['category'] === 'Electrical' ? 'selected' : '' ?>>Electrical</option>
                                <option value="Appliances" <?= $form['category'] === 'Appliances' ? 'selected' : '' ?>>Appliances</option>
                                <option value="Furniture" <?= $form['category'] === 'Furniture' ? 'selected' : '' ?>>Furniture</option>
                                <option value="General" <?= $form['category'] === 'General' ? 'selected' : '' ?>>General / Other</option>
                            </select>
                            <?php if (isset($fieldErrors['category'])): ?>
                                <p class="mt-1.5 text-xs font-medium text-red-600"><?= e($fieldErrors['category']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="description" class="block text-xs font-semibold text-slate-600">Description <span class="text-red-500">*</span></label>
                            <textarea id="description" name="description" rows="4" required placeholder="Please describe the issue in detail..." class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-blue-500 focus-visible:ring-4 focus-visible:ring-blue-500/10 <?= isset($fieldErrors['description']) ? 'border-red-300 focus-visible:border-red-500 focus-visible:ring-red-500/10' : '' ?>"><?= e($form['description']) ?></textarea>
                            <?php if (isset($fieldErrors['description'])): ?>
                                <p class="mt-1.5 text-xs font-medium text-red-600"><?= e($fieldErrors['description']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="attachment" class="block text-xs font-semibold text-slate-600">Attachment (Optional)</label>
                            <input id="attachment" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-2 block w-full text-sm text-slate-500 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200 transition <?= isset($fieldErrors['attachment']) ? 'file:bg-red-50 file:text-red-700' : '' ?>">
                            <p class="mt-1 text-xs text-slate-400">Max 5MB. JPG, PNG, WEBP, or PDF.</p>
                            <?php if (isset($fieldErrors['attachment'])): ?>
                                <p class="mt-1.5 text-xs font-medium text-red-600"><?= e($fieldErrors['attachment']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" id="cancelModalBtn" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="submit" id="submitBtn" class="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span id="submitLabel">Submit Request</span>
                            <svg id="submitSpinner" class="hidden ml-2 h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- External JS for CSP Compliance -->
    <script src="/tenant-system/public/assets/js/maintenance.js" defer></script>
</body>
</html>