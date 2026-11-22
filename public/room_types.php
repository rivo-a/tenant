<?php
declare(strict_types=1);

/**
 * Room-type pricing templates.
 *
 * Global templates (admin_id NULL) belong to the platform owner. A landlord
 * can create and manage only their own templates, and can read the shared
 * ones. Every mutation is POST with a CSRF token; nothing changes on a GET.
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_capability('rooms.manage');

$pdo = getDB();
$businessId = current_business_id();
$actorId = (int)($_SESSION['admin_id'] ?? 0);
$isPlatformOwner = isSuperAdmin();

$action = (string)($_GET['action'] ?? 'list');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';

/** A template the current account may change. */
$canManage = static function (array $row) use ($businessId, $isPlatformOwner): bool {
    $owner = (int)($row['admin_id'] ?? 0);

    if ($isPlatformOwner) {
        return true;
    }

    return $owner > 0 && $owner === $businessId;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));

        $name = trim((string)($_POST['name'] ?? ''));
        $rent = (float)($_POST['default_monthly_rent'] ?? 0);
        $desc = trim((string)($_POST['description'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $rent <= 0) {
            throw new RuntimeException('Name and a valid rent amount are required.');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare('
                INSERT INTO room_types (name, default_monthly_rent, description, is_active, admin_id)
                VALUES (:name, :rent, :description, 1, :admin_id)
            ');
            $stmt->execute([
                ':name' => $name,
                ':rent' => $rent,
                ':description' => $desc !== '' ? $desc : null,
                ':admin_id' => $isPlatformOwner ? null : $businessId,
            ]);

            $newId = (int)$pdo->lastInsertId();
            logAudit($actorId, 'ROOM_TYPE_CREATED', "Room type '{$name}' created at {$rent}.", [
                'target_type' => 'room_type',
                'target_id' => $newId,
                'owner_admin_id' => $isPlatformOwner ? null : $businessId,
            ]);
        } elseif ($action === 'update') {
            $current = get_room_type($pdo, $id);
            if (!$current || !$canManage($current)) {
                throw new RuntimeException('That template cannot be edited by this account.');
            }

            $stmt = $pdo->prepare('
                UPDATE room_types
                SET name = :name, default_monthly_rent = :rent, description = :description,
                    is_active = :active, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ');
            $stmt->execute([
                ':name' => $name,
                ':rent' => $rent,
                ':description' => $desc !== '' ? $desc : null,
                ':active' => $active,
                ':id' => $id,
            ]);

            logAudit($actorId, 'ROOM_TYPE_UPDATED', "Room type #{$id} set to '{$name}' at {$rent}.", [
                'target_type' => 'room_type',
                'target_id' => $id,
                'owner_admin_id' => $isPlatformOwner ? null : $businessId,
            ]);
        } elseif ($action === 'disable') {
            $current = get_room_type($pdo, $id);
            if (!$current || !$canManage($current)) {
                throw new RuntimeException('That template cannot be disabled by this account.');
            }
            if (strcasecmp((string)$current['name'], 'Default Template') === 0) {
                throw new RuntimeException('The default template cannot be disabled.');
            }

            $stmt = $pdo->prepare('UPDATE room_types SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':id' => $id]);

            logAudit($actorId, 'ROOM_TYPE_DISABLED', "Room type #{$id} disabled.", [
                'target_type' => 'room_type',
                'target_id' => $id,
                'owner_admin_id' => $isPlatformOwner ? null : $businessId,
            ]);
        }

        header('Location: room_types.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$editRoomType = null;
if ($action === 'edit' && $id > 0) {
    $editRoomType = get_room_type($pdo, $id);

    if (!$editRoomType || !$canManage($editRoomType)) {
        $error = 'That template cannot be edited by this account.';
        $action = 'list';
        $editRoomType = null;
    }
}

$stmt = $pdo->prepare('
    SELECT id, name, default_monthly_rent, is_active, admin_id
    FROM room_types
    WHERE admin_id IS NULL OR admin_id = :business_id
    ORDER BY admin_id IS NULL DESC, name ASC
');
$stmt->execute([':business_id' => $businessId]);
$roomTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

function get_room_type(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, default_monthly_rent, description, is_active, admin_id FROM room_types WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Room type templates</title>
    <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<main class="mx-auto max-w-3xl px-4 py-8">

    <header class="mb-5">
        <h1 class="text-2xl font-bold tracking-tight">Room type templates</h1>
        <p class="mt-1 text-sm text-slate-600">
            Default rent used when neither the room nor the tenancy sets its own.
            Shared templates come from the platform owner; your own are listed after them.
        </p>
    </header>

    <?php if ($error !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($action === 'add' || $action === 'edit'): ?>
        <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                <?= $action === 'edit' ? 'Edit template' : 'New template' ?>
            </h2>

            <form method="post" action="room_types.php?action=<?= $action === 'edit' ? 'update&id=' . (int)$editRoomType['id'] : 'create' ?>" class="mt-3 grid gap-3">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                    Name
                    <input type="text" name="name" required maxlength="60" value="<?= e($editRoomType['name'] ?? '') ?>"
                           class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal">
                </label>

                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                    Default monthly rent
                    <input type="number" step="1" min="1" name="default_monthly_rent" required value="<?= e((string)($editRoomType['default_monthly_rent'] ?? '')) ?>"
                           class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal">
                </label>

                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                    Description
                    <textarea name="description" rows="2" class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal"><?= e((string)($editRoomType['description'] ?? '')) ?></textarea>
                </label>

                <?php if ($action === 'edit'): ?>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_active" <?= !empty($editRoomType['is_active']) ? 'checked' : '' ?>>
                        Active
                    </label>
                <?php endif; ?>

                <div class="flex gap-2">
                    <button type="submit" class="min-h-11 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save</button>
                    <a href="room_types.php" class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Templates</h2>
            <a href="room_types.php?action=add" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">
                <?= icon('plus', 'h-4 w-4') ?> Add template
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-semibold">Name</th>
                        <th class="px-4 py-2 font-semibold">Owner</th>
                        <th class="px-4 py-2 font-semibold text-right">Default rent</th>
                        <th class="px-4 py-2 font-semibold">Status</th>
                        <th class="px-4 py-2 font-semibold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($roomTypes as $rt): ?>
                        <?php $manageable = $canManage($rt); ?>
                        <tr>
                            <td class="px-4 py-2 font-medium"><?= e($rt['name']) ?></td>
                            <td class="px-4 py-2 text-slate-600"><?= empty($rt['admin_id']) ? 'Shared' : 'This business' ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= e(number_format((float)$rt['default_monthly_rent'], 0)) ?></td>
                            <td class="px-4 py-2 text-slate-600"><?= !empty($rt['is_active']) ? 'Active' : 'Disabled' ?></td>
                            <td class="px-4 py-2">
                                <?php if (!$manageable): ?>
                                    <span class="block text-right text-xs text-slate-400">—</span>
                                <?php else: ?>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="room_types.php?action=edit&id=<?= (int)$rt['id'] ?>"
                                           class="inline-flex min-h-9 items-center gap-1 rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                            <?= icon('pencil', 'h-3.5 w-3.5') ?> Edit
                                        </a>
                                        <?php if (strcasecmp((string)$rt['name'], 'Default Template') !== 0): ?>
                                            <form method="post" action="room_types.php?action=disable&id=<?= (int)$rt['id'] ?>"
                                                  onsubmit="return confirm('Disable this template?');">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <button class="inline-flex min-h-9 items-center gap-1 rounded-lg border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">
                                                    <?= icon('trash-2', 'h-3.5 w-3.5') ?> Disable
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="mt-4 text-sm">
        <a href="rooms.php" class="font-semibold text-slate-600 hover:text-slate-900">← Rooms</a>
    </p>
</main>
</body>
</html>
