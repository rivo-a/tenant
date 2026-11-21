<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();

/*
 * Landlords manage everyone in their business. A property manager may only
 * create rent collectors. The platform owner sees every business.
 */
$canManageStaff = can('staff.manage');
$canManageCollectors = can('staff.manage_collectors');

if (!$canManageStaff && !$canManageCollectors) {
    deny_access('staff.manage', 'Staff management requires a landlord or property manager account.');
}

$pdo = getDB();
$businessId = current_business_id();
$actorId = (int)($_SESSION['admin_id'] ?? 0);
$actorRole = auth_role();
$isPlatformOwner = isSuperAdmin();

$success = '';
$error = '';

$creatableRoles = $canManageStaff
    ? ['property_manager', 'rent_collector']
    : ['rent_collector'];

$assignableRoles = $canManageStaff
    ? ['property_manager', 'rent_collector']
    : [];

/* -------------------------------------------------------------------------
   Create staff
------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['create_staff'])) {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));

        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'rent_collector');

        if (!in_array($role, $creatableRoles, true)) {
            throw new RuntimeException('You cannot create that role.');
        }

        if ($fullName === '' || $username === '' || $password === '') {
            throw new RuntimeException('Name, username and password are required.');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('Use a password of at least 8 characters.');
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            throw new RuntimeException('Username must be 3-40 letters, digits, dot, dash or underscore.');
        }

        if ($businessId <= 0) {
            throw new RuntimeException('No business is linked to this account.');
        }

        $exists = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = :username');
        $exists->execute([':username' => $username]);
        if ((int)$exists->fetchColumn() > 0) {
            throw new RuntimeException('That username is already taken.');
        }

        $stmt = $pdo->prepare('
            INSERT INTO admins (full_name, username, password, role, is_active, landlord_id)
            VALUES (:full_name, :username, :password, :role, 1, :landlord_id)
        ');
        $stmt->execute([
            ':full_name' => $fullName,
            ':username' => $username,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':landlord_id' => $businessId,
        ]);

        $newId = (int)$pdo->lastInsertId();

        logAudit(
            $actorId,
            'STAFF_CREATED',
            auth_role_label($role) . " {$username} created for this business.",
            [
                'target_type' => 'admin',
                'target_id' => $newId,
                'owner_admin_id' => $businessId,
            ]
        );

        $success = auth_role_label($role) . ' account created. Give them the username and password directly.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* -------------------------------------------------------------------------
   Change role (landlord only)
------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['update_role']) && $canManageStaff) {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));

        $targetId = (int)($_POST['admin_id'] ?? 0);
        $role = (string)($_POST['role'] ?? '');

        if (!in_array($role, $assignableRoles, true)) {
            throw new RuntimeException('That role cannot be assigned.');
        }

        $target = admin_in_business($pdo, $targetId, $businessId, $isPlatformOwner);
        if ($target === null) {
            throw new RuntimeException('Account not found in this business.');
        }

        if ((int)$target['id'] === $actorId) {
            throw new RuntimeException('You cannot change your own role.');
        }

        if (auth_normalise_role((string)$target['role']) === 'landlord') {
            throw new RuntimeException('The landlord account role cannot be changed here.');
        }

        $stmt = $pdo->prepare('UPDATE admins SET role = :role WHERE id = :id');
        $stmt->execute([':role' => $role, ':id' => $targetId]);

        logAudit(
            $actorId,
            'STAFF_ROLE_CHANGED',
            "Account {$target['username']} changed to " . auth_role_label($role) . '.',
            [
                'target_type' => 'admin',
                'target_id' => $targetId,
                'owner_admin_id' => $businessId,
            ]
        );

        $success = 'Role updated.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* -------------------------------------------------------------------------
   Suspend / restore
------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['toggle_status'])) {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));

        $targetId = (int)($_POST['admin_id'] ?? 0);
        $target = admin_in_business($pdo, $targetId, $businessId, $isPlatformOwner);

        if ($target === null) {
            throw new RuntimeException('Account not found in this business.');
        }

        if ((int)$target['id'] === $actorId) {
            throw new RuntimeException('You cannot suspend your own account.');
        }

        if (auth_normalise_role((string)$target['role']) === 'landlord' && !$isPlatformOwner) {
            throw new RuntimeException('Only the platform owner can suspend a landlord account.');
        }

        if (!$canManageStaff && auth_normalise_role((string)$target['role']) !== 'rent_collector') {
            throw new RuntimeException('Property managers can only suspend rent collectors.');
        }

        $stmt = $pdo->prepare('UPDATE admins SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id');
        $stmt->execute([':id' => $targetId]);

        $nowActive = (int)$pdo->query('SELECT is_active FROM admins WHERE id = ' . (int)$targetId)->fetchColumn();

        logAudit(
            $actorId,
            $nowActive === 1 ? 'STAFF_RESTORED' : 'STAFF_SUSPENDED',
            "Account {$target['username']} " . ($nowActive === 1 ? 'restored' : 'suspended') . '.',
            [
                'target_type' => 'admin',
                'target_id' => $targetId,
                'owner_admin_id' => $businessId,
            ]
        );

        $success = $nowActive === 1 ? 'Account restored.' : 'Account suspended. Their next request will be rejected.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* -------------------------------------------------------------------------
   Listing, scoped to the business
------------------------------------------------------------------------- */
if ($isPlatformOwner) {
    $staffStmt = $pdo->prepare('
        SELECT a.id, a.full_name, a.username, a.role, a.is_active, a.landlord_id,
               l.full_name AS landlord_name
        FROM admins a
        LEFT JOIN admins l ON l.id = a.landlord_id
        ORDER BY a.landlord_id IS NULL DESC, a.landlord_id, a.role, a.full_name
    ');
    $staffStmt->execute();
} else {
    $staffStmt = $pdo->prepare('
        SELECT a.id, a.full_name, a.username, a.role, a.is_active, a.landlord_id,
               NULL AS landlord_name
        FROM admins a
        WHERE a.id = :business_id
           OR a.landlord_id = :business_id
        ORDER BY a.role, a.full_name
    ');
    $staffStmt->execute([':business_id' => $businessId]);
}
$staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$activeNav = 'staff';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff accounts</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<?php
$navPath = __DIR__ . '/partials/navbar.php';
if (is_file($navPath)) {
    require $navPath;
}
?>
<main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">

  <header class="mb-6">
    <h1 class="text-2xl font-bold tracking-tight">Staff accounts</h1>
    <p class="mt-1 max-w-2xl text-sm text-slate-600">
      Property managers run day-to-day operations without seeing audit history.
      Rent collectors can only see overdue tenants to collect rent in person.
    </p>
  </header>

  <?php if ($success !== ''): ?>
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">
      <?= e($success) ?>
    </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
      <?= e($error) ?>
    </div>
  <?php endif; ?>

  <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Add a staff account</h2>

    <form method="post" class="mt-4 grid gap-3 sm:grid-cols-2">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">
        Full name
        <input name="full_name" required autocomplete="off"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
      </label>

      <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">
        Username
        <input name="username" required autocomplete="off" pattern="[A-Za-z0-9._-]{3,40}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
      </label>

      <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">
        Temporary password
        <input name="password" type="password" required minlength="8" autocomplete="new-password"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
        <span class="text-xs font-normal text-slate-500">At least 8 characters. Share it outside the app.</span>
      </label>

      <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">
        Role
        <select name="role" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900">
          <?php foreach ($creatableRoles as $role): ?>
            <option value="<?= e($role) ?>"><?= e(auth_role_label($role)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="sm:col-span-2">
        <button name="create_staff" value="1"
                class="inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
          Create account
        </button>
      </div>
    </form>
  </section>

  <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
        <?= $isPlatformOwner ? 'All accounts' : 'This business' ?>
      </h2>
      <span class="text-xs text-slate-500"><?= count($staff) ?> account<?= count($staff) === 1 ? '' : 's' ?></span>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-5 py-2 font-semibold">Name</th>
            <th class="px-5 py-2 font-semibold">Username</th>
            <th class="px-5 py-2 font-semibold">Role</th>
            <?php if ($isPlatformOwner): ?>
              <th class="px-5 py-2 font-semibold">Business</th>
            <?php endif; ?>
            <th class="px-5 py-2 font-semibold">Status</th>
            <th class="px-5 py-2 font-semibold text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <?php foreach ($staff as $member): ?>
            <?php
              $memberRole = auth_normalise_role((string)$member['role']);
              $isSelf = (int)$member['id'] === $actorId;
              $isLandlordRow = $memberRole === 'landlord';
            ?>
            <tr>
              <td class="px-5 py-3 font-medium text-slate-900">
                <?= e($member['full_name']) ?><?= $isSelf ? ' (you)' : '' ?>
              </td>
              <td class="px-5 py-3 text-slate-600"><?= e($member['username']) ?></td>
              <td class="px-5 py-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?=
                  match ($memberRole) {
                      'super_admin' => 'bg-slate-900 text-white ring-slate-900',
                      'landlord' => 'bg-sky-50 text-sky-700 ring-sky-200',
                      'property_manager' => 'bg-amber-50 text-amber-700 ring-amber-200',
                      default => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                  }
                ?>">
                  <?= e(auth_role_label($memberRole)) ?>
                </span>
              </td>
              <?php if ($isPlatformOwner): ?>
                <td class="px-5 py-3 text-slate-600">
                  <?= e($member['landlord_name'] ?? ($isLandlordRow ? $member['full_name'] : '—')) ?>
                </td>
              <?php endif; ?>
              <td class="px-5 py-3">
                <?php if ((int)($member['is_active'] ?? 0) === 1): ?>
                  <span class="text-emerald-700">Active</span>
                <?php else: ?>
                  <span class="text-red-700">Suspended</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3">
                <?php if ($isSelf || $isLandlordRow): ?>
                  <span class="block text-right text-xs text-slate-400">—</span>
                <?php else: ?>
                  <div class="flex flex-wrap items-center justify-end gap-2">
                    <?php if ($canManageStaff): ?>
                      <form method="post" class="flex items-center gap-1">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="admin_id" value="<?= (int)$member['id'] ?>">
                        <select name="role" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs">
                          <?php foreach ($assignableRoles as $role): ?>
                            <option value="<?= e($role) ?>" <?= $role === $memberRole ? 'selected' : '' ?>>
                              <?= e(auth_role_label($role)) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <button name="update_role" value="1"
                                class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                          Save
                        </button>
                      </form>
                    <?php endif; ?>

                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="admin_id" value="<?= (int)$member['id'] ?>">
                      <button name="toggle_status" value="1"
                              class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        <?= (int)($member['is_active'] ?? 0) === 1 ? 'Suspend' : 'Restore' ?>
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</main>
</body>
</html>
<?php

/**
 * Fetch a staff row only when it belongs to the caller's business.
 *
 * @return array<string, mixed>|null
 */
function admin_in_business(PDO $pdo, int $targetId, int $businessId, bool $isPlatformOwner): ?array
{
    if ($targetId <= 0) {
        return null;
    }

    if ($isPlatformOwner) {
        $stmt = $pdo->prepare('SELECT id, username, role, is_active, landlord_id FROM admins WHERE id = :id');
        $stmt->execute([':id' => $targetId]);
    } else {
        $stmt = $pdo->prepare('
            SELECT id, username, role, is_active, landlord_id
            FROM admins
            WHERE id = :id
              AND (id = :business_id OR landlord_id = :business_id)
        ');
        $stmt->execute([':id' => $targetId, ':business_id' => $businessId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
