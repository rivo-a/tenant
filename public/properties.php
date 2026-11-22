<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_capability('properties.manage');

$pdo = getDB();
$businessId = current_business_id();
$actorId = (int)($_SESSION['admin_id'] ?? 0);

$success = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Give the property a name.');
            }
            if (mb_strlen($name) > 80) {
                throw new RuntimeException('Keep the property name under 80 characters.');
            }

            $dupe = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE admin_id = :b AND LOWER(name) = LOWER(:name)');
            $dupe->execute([':b' => $businessId, ':name' => $name]);
            if ((int)$dupe->fetchColumn() > 0) {
                throw new RuntimeException('A property with that name already exists.');
            }

            $stmt = $pdo->prepare('INSERT INTO properties (admin_id, name, address) VALUES (:admin_id, :name, :address)');
            $stmt->execute([':admin_id' => $businessId, ':name' => $name, ':address' => $address !== '' ? $address : null]);

            logAudit($actorId, 'PROPERTY_CREATED', "Property {$name} created.", [
                'target_type' => 'property',
                'target_id' => (int)$pdo->lastInsertId(),
                'owner_admin_id' => $businessId,
            ]);

            $success = 'Property created. Now place rooms in it.';
        } elseif ($action === 'rename') {
            $propertyId = (int)($_POST['property_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Give the property a name.');
            }

            $stmt = $pdo->prepare('UPDATE properties SET name = :name, address = :address, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND admin_id = :admin_id');
            $stmt->execute([':name' => $name, ':address' => $address !== '' ? $address : null, ':id' => $propertyId, ':admin_id' => $businessId]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Property not found.');
            }

            logAudit($actorId, 'PROPERTY_UPDATED', "Property #{$propertyId} updated.", [
                'target_type' => 'property',
                'target_id' => $propertyId,
                'owner_admin_id' => $businessId,
            ]);

            $success = 'Property updated.';
        } elseif ($action === 'delete') {
            $propertyId = (int)($_POST['property_id'] ?? 0);

            $check = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE property_id = :id AND admin_id = :admin_id');
            $check->execute([':id' => $propertyId, ':admin_id' => $businessId]);
            if ((int)$check->fetchColumn() > 0) {
                throw new RuntimeException('Move its rooms out first, then delete the property.');
            }

            $stmt = $pdo->prepare('DELETE FROM properties WHERE id = :id AND admin_id = :admin_id');
            $stmt->execute([':id' => $propertyId, ':admin_id' => $businessId]);

            logAudit($actorId, 'PROPERTY_DELETED', "Property #{$propertyId} deleted.", [
                'target_type' => 'property',
                'target_id' => $propertyId,
                'owner_admin_id' => $businessId,
            ]);

            $success = 'Property deleted.';
        } elseif ($action === 'assign') {
            $propertyId = (int)($_POST['property_id'] ?? 0);
            $roomIds = array_map('intval', (array)($_POST['room_ids'] ?? []));
            $roomIds = array_values(array_filter($roomIds, static fn(int $id): bool => $id > 0));

            $owns = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE id = :id AND admin_id = :admin_id');
            $owns->execute([':id' => $propertyId, ':admin_id' => $businessId]);
            if ((int)$owns->fetchColumn() < 1) {
                throw new RuntimeException('Property not found.');
            }
            if (!$roomIds) {
                throw new RuntimeException('Select at least one room.');
            }

            $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
            $stmt = $pdo->prepare(
                "UPDATE rooms SET property_id = ?
                 WHERE admin_id = ? AND id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$propertyId, $businessId], $roomIds));

            logAudit($actorId, 'PROPERTY_ROOMS_ASSIGNED', count($roomIds) . " rooms placed in property #{$propertyId}.", [
                'target_type' => 'property',
                'target_id' => $propertyId,
                'owner_admin_id' => $businessId,
            ]);

            $success = $stmt->rowCount() . ' room(s) placed in the property.';
        } elseif ($action === 'unassign') {
            $roomId = (int)($_POST['room_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE rooms SET property_id = NULL WHERE id = :id AND admin_id = :admin_id');
            $stmt->execute([':id' => $roomId, ':admin_id' => $businessId]);

            $success = 'Room removed from its property.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$properties = $pdo->prepare("
    SELECT pr.id, pr.name, COALESCE(pr.address, '') AS address,
           (SELECT COUNT(*) FROM rooms r WHERE r.property_id = pr.id) AS rooms
    FROM properties pr
    WHERE pr.admin_id = :admin_id
    ORDER BY pr.name
");
$properties->execute([':admin_id' => $businessId]);
$propertyRows = $properties->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rooms = $pdo->prepare("
    SELECT r.id, r.room_number, r.property_id, pr.name AS property_name,
           (SELECT COUNT(*) FROM tenants t
             WHERE t.room_id = r.id AND LOWER(COALESCE(t.status,'')) = 'active' AND t.exit_date IS NULL) AS occupied
    FROM rooms r
    LEFT JOIN properties pr ON pr.id = r.property_id
    WHERE r.admin_id = :admin_id
    ORDER BY pr.name IS NULL DESC, pr.name, r.room_number
");
$rooms->execute([':admin_id' => $businessId]);
$roomRows = $rooms->fetchAll(PDO::FETCH_ASSOC) ?: [];

$unassignedRooms = array_values(array_filter($roomRows, static fn(array $room): bool => empty($room['property_id'])));

$activeNav = 'properties';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Properties · Urbahan</title>
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
<main class="mx-auto max-w-5xl px-4 py-6 sm:px-6">

    <header class="mb-5">
        <h1 class="text-2xl font-bold tracking-tight">Properties</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            Split one business into separate properties so occupancy, income and arrears can be compared side by side.
        </p>
    </header>

    <?php if ($success !== ''): ?>
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Add a property</h2>
        <form method="post" class="mt-3 grid gap-2 sm:grid-cols-4">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <input name="name" required maxlength="80" placeholder="Property name (e.g. Ntinda Block A)" class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
            <input name="address" maxlength="120" placeholder="Address (optional)" class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <button class="min-h-11 rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Create</button>
        </form>
    </section>

    <section class="mb-5 grid gap-3">
        <?php if (!$propertyRows): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                <h2 class="text-base font-semibold">No properties yet</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-600">
                    All <?= count($roomRows) ?> of your rooms are currently unassigned. Create a property above, then place rooms in it.
                </p>
            </div>
        <?php endif; ?>

        <?php foreach ($propertyRows as $property): ?>
            <?php $propertyRooms = array_values(array_filter($roomRows, static fn(array $room): bool => (int)($room['property_id'] ?? 0) === (int)$property['id'])); ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold"><?= e($property['name']) ?></p>
                        <p class="text-xs text-slate-500"><?= e($property['address'] !== '' ? $property['address'] : 'No address') ?> · <?= (int)$property['rooms'] ?> rooms</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <form method="post" class="flex flex-wrap items-end gap-1">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="property_id" value="<?= (int)$property['id'] ?>">
                            <input name="name" value="<?= e($property['name']) ?>" maxlength="80" class="min-h-9 w-40 rounded-lg border border-slate-300 px-2 py-1 text-xs">
                            <input name="address" value="<?= e($property['address']) ?>" maxlength="120" placeholder="Address" class="min-h-9 w-36 rounded-lg border border-slate-300 px-2 py-1 text-xs">
                            <button class="min-h-9 rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">Save</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this property? Its rooms must be moved out first.');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="property_id" value="<?= (int)$property['id'] ?>">
                            <button class="min-h-9 rounded-lg border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">Delete</button>
                        </form>
                    </div>
                </div>

                <?php if ($propertyRooms): ?>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        <?php foreach ($propertyRooms as $room): ?>
                            <li class="inline-flex items-center gap-2 rounded-lg bg-slate-100 px-2 py-1 text-xs">
                                <span class="font-semibold"><?= e($room['room_number']) ?></span>
                                <span class="text-slate-500"><?= (int)$room['occupied'] > 0 ? 'let' : 'vacant' ?></span>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="unassign">
                                    <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
                                    <button class="text-slate-500 hover:text-red-700" aria-label="Remove <?= e($room['room_number']) ?> from this property">✕</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($propertyRows && $unassignedRooms): ?>
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Assign unplaced rooms</h2>
            <p class="mt-1 text-xs text-slate-500"><?= count($unassignedRooms) ?> rooms are not in a property yet.</p>

            <form method="post" class="mt-3 grid gap-3">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="assign">

                <label class="flex max-w-sm flex-col gap-1 text-xs font-semibold text-slate-600">
                    Put selected rooms in
                    <select name="property_id" required class="min-h-11 rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                        <option value="">Choose a property</option>
                        <?php foreach ($propertyRows as $property): ?>
                            <option value="<?= (int)$property['id'] ?>"><?= e($property['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <fieldset class="grid gap-1 sm:grid-cols-3">
                    <legend class="sr-only">Rooms to place</legend>
                    <?php foreach ($unassignedRooms as $room): ?>
                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                            <input type="checkbox" name="room_ids[]" value="<?= (int)$room['id'] ?>">
                            <span class="font-semibold"><?= e($room['room_number']) ?></span>
                            <span class="text-slate-500"><?= (int)$room['occupied'] > 0 ? 'let' : 'vacant' ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <div>
                    <button class="min-h-11 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Place rooms</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
