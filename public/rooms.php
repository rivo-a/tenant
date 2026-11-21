<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$admin_id = (int)($_SESSION['admin_id'] ?? 0);

/* =============================
   Flash Messages (PRG Safe)
============================= */
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

/* =============================
   Helpers
============================= */
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function expandRooms(string $input): array {
    $rooms = [];

    foreach (array_map('trim', explode(',', $input)) as $part) {
        if ($part === '') continue;

        if (strpos($part, '-') !== false) {
            [$start, $end] = array_map('trim', explode('-', $part));
            preg_match('/^([A-Za-z]*)(\d+)$/', $start, $s);
            preg_match('/^([A-Za-z]*)(\d+)$/', $end, $e);

            if ($s && $e && $s[1] === $e[1]) {
                $len = strlen($s[2]);
                for ($i = (int)$s[2]; $i <= (int)$e[2]; $i++) {
                    $rooms[] = strtoupper($s[1] . str_pad((string)$i, $len, '0', STR_PAD_LEFT));
                }
            }
        } else {
            $rooms[] = strtoupper($part);
        }
    }
    return array_values(array_unique(array_filter($rooms)));
}

function effectiveRent(array $r): int {
    return $r['monthly_rent'] !== null
        ? (int)$r['monthly_rent']
        : (int)$r['default_monthly_rent'];
}

function money($v): string { return number_format((float)$v, 0); }

function chip(string $label, string $tone): string
{
    $map = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'red'   => 'bg-red-50 text-red-700 ring-red-200',
        'blue'  => 'bg-sky-50 text-sky-700 ring-sky-200',
    ];
    $cls = $map[$tone] ?? $map['slate'];
    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset '.$cls.'">'.e($label).'</span>';
}

/* =============================
   POST HANDLERS (PRG)
============================= */

/* ---- Add Custom Room Type ---- */
if (isset($_POST['add_room_type'])) {
    $name = trim((string)($_POST['custom_name'] ?? ''));
    $rent = (int)($_POST['custom_rent'] ?? 0);

    if ($name !== '' && $rent > 0) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO room_types (name, default_monthly_rent, is_active)
                 VALUES (?, ?, 1)"
            );
            $stmt->execute([$name, $rent]);

            try {
                logAudit(
                    $admin_id,
                    'ROOM_TYPE_CREATED',
                    "Room type '{$name}' created with default monthly rent {$rent}."
                );
            } catch (Throwable $ignored) {}

            $_SESSION['success'] = "Room type '{$name}' added successfully.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Room type already exists.";
        }
    } else {
        $_SESSION['error'] = "Room type name and rent are required.";
    }

    redirect('rooms.php');
}

/* ---- Add Rooms ---- */
if (isset($_POST['add_rooms'])) {
    $room_input   = trim((string)($_POST['room_numbers'] ?? ''));
    $room_type_id = (int)($_POST['room_type_id'] ?? 0);
    $notes        = trim((string)($_POST['notes'] ?? ''));

    if ($room_input !== '' && $room_type_id > 0) {
        $roomNumbers = expandRooms($room_input);

        $stmt = $pdo->prepare(
            "INSERT INTO rooms
             (room_number, room_type_id, monthly_rent, notes, status, admin_id)
             VALUES (?, ?, NULL, ?, 'free', ?)"
        );

        $added = [];
        $duplicates = [];

        foreach ($roomNumbers as $room) {
            try {
                $stmt->execute([$room, $room_type_id, $notes, $admin_id]);
                $added[] = $room;
            } catch (PDOException $e) {
                $duplicates[] = $room;
            }
        }

        if ($added) {
            $_SESSION['success'] = "Rooms created: " . implode(', ', $added);
        }
        if ($duplicates) {
            $_SESSION['error'] = "Already exist: " . implode(', ', $duplicates);
        }

        if ($added) {
            try {
                logAudit(
                    $admin_id,
                    'ROOMS_CREATED',
                    'Rooms created: ' . implode(', ', $added)
                );
            } catch (Throwable $ignored) {}
        }
    } else {
        $_SESSION['error'] = "Room numbers and room type are required.";
    }

    redirect('rooms.php');
}

/* ---- Edit Room Rent ---- */
if (isset($_POST['edit_room_id'])) {
    $room_id  = (int)($_POST['edit_room_id'] ?? 0);
    $new_rent = (isset($_POST['monthly_rent']) && $_POST['monthly_rent'] !== '')
        ? (int)$_POST['monthly_rent']
        : null;

    $stmt = $pdo->prepare(
        "UPDATE rooms
         SET monthly_rent = ?
         WHERE id = ? AND admin_id = ?"
    );
    $stmt->execute([$new_rent, $room_id, $admin_id]);

    /*
     * Room rent is the source of truth for active tenants in that room.
     * Clearing their snapshot makes reports, dashboard and payments read
     * the new room rent immediately instead of an old figure.
     */
    $tenantsUpdated = clear_room_tenant_rent_snapshots($pdo, $room_id, $admin_id);

    try {
        logAudit(
            $admin_id,
            'ROOM_RENT_UPDATED',
            "Room #{$room_id} rent override set to " . ($new_rent === null ? 'template default' : (string)$new_rent) . ". Tenants synced: {$tenantsUpdated}."
        );
    } catch (Throwable $ignored) {}

    $_SESSION['success'] = $tenantsUpdated > 0
        ? "Room rent updated. {$tenantsUpdated} active tenant(s) in this room now follow it."
        : "Room rent updated successfully.";
    redirect('rooms.php');
}

/* ---- Bulk Update Rent Per Room Type ---- */
if (isset($_POST['bulk_update_rent'])) {
    $roomTypeId = (int)($_POST['bulk_room_type_id'] ?? 0);
    $newRent    = (int)($_POST['bulk_new_rent'] ?? 0);

    if ($roomTypeId > 0 && $newRent > 0) {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                SELECT name, default_monthly_rent
                FROM room_types
                WHERE id = ?
            ");
            $stmt->execute([$roomTypeId]);
            $type = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$type) throw new RuntimeException("Room type not found.");

            $stmt = $pdo->prepare("
                UPDATE room_types
                SET default_monthly_rent = ?
                WHERE id = ?
            ");
            $stmt->execute([$newRent, $roomTypeId]);

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM rooms
                WHERE room_type_id = ?
                  AND monthly_rent IS NULL
            ");
            $stmt->execute([$roomTypeId]);
            $affected = (int)$stmt->fetchColumn();

            // Active tenants on rooms without their own override follow the new template rent.
            $tenantsUpdated = clear_room_type_tenant_rent_snapshots($pdo, $roomTypeId, $admin_id);

            if (function_exists('logAudit')) {
                try {
                    logAudit(
                        $admin_id,
                        'BULK_RENT_UPDATE',
                        "Room type '{$type['name']}' rent changed from {$type['default_monthly_rent']} to {$newRent}. Affected rooms: {$affected}. Tenants synced: {$tenantsUpdated}."
                    );
                } catch (Throwable $ignored) {}
            }

            $pdo->commit();

            $_SESSION['success'] = "Rent updated. {$affected} rooms affected, {$tenantsUpdated} tenant(s) synced.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Valid room type and rent are required.";
    }

    redirect('rooms.php');
}

/* ---- Sync tenant rents with their room ---- */
if (isset($_POST['sync_tenant_rents'])) {
    $stmt = $pdo->prepare("
        UPDATE tenants
        SET monthly_rent = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE admin_id = ?
          AND LOWER(COALESCE(status, 'active')) = 'active'
          AND exit_date IS NULL
          AND monthly_rent IS NOT NULL
    ");
    $stmt->execute([$admin_id]);
    $synced = (int)$stmt->rowCount();

    try {
        logAudit(
            $admin_id,
            'TENANT_RENT_SYNCED',
            "Tenant rent snapshots cleared for {$synced} active tenant(s); rent now follows the room or room type."
        );
    } catch (Throwable $ignored) {}

    $_SESSION['success'] = $synced > 0
        ? "{$synced} tenant(s) now follow their room rent. Re-enter a negotiated rent on a tenant to override it."
        : "No tenant rent snapshots needed clearing.";
    redirect('rooms.php');
}

/* =============================
   LOAD DATA (ALWAYS FRESH)
============================= */
$roomTypes = $pdo->query("
    SELECT id, name, default_monthly_rent
    FROM room_types
    WHERE is_active = 1
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$roomsStmt = $pdo->prepare("
    SELECT
        r.*,
        rt.name AS room_type,
        rt.default_monthly_rent,
        rt.is_active AS room_type_active
    FROM rooms r
    LEFT JOIN room_types rt ON rt.id = r.room_type_id
    WHERE r.admin_id = ?
    ORDER BY r.room_number
");
$roomsStmt->execute([$admin_id]);
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Occupancy per room type, shown inside each template field.
$roomTypeStats = [];
foreach ($rooms as $roomRow) {
    $rowTypeId = (int)($roomRow['room_type_id'] ?? 0);
    if (!isset($roomTypeStats[$rowTypeId])) {
        $roomTypeStats[$rowTypeId] = ['total' => 0, 'occupied' => 0, 'free' => 0];
    }

    $roomTypeStats[$rowTypeId]['total']++;
    if ((string)($roomRow['status'] ?? '') === 'occupied') {
        $roomTypeStats[$rowTypeId]['occupied']++;
    } else {
        $roomTypeStats[$rowTypeId]['free']++;
    }
}

$active = 'rooms';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Rooms Management</title>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Tailwind (compiled, no CDN) -->
  <link rel="stylesheet" href="assets/css/tailwind.css">

  <style>
    /* small helper so modal content is centered nicely */
    .modal-backdrop { backdrop-filter: blur(6px); }
  </style>

</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

  <?php
  $navPath = __DIR__ . '/partials/navbar.php';
  if (is_file($navPath)) require $navPath;
  ?>

  <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight font-alt">Rooms Management</h1>
        <p class="mt-1 text-sm text-slate-600">Manage room types, create rooms, and adjust rent overrides.</p>
      </div>
    </div>

    <!-- Flash messages -->
    <?php if ($success): ?>
      <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        <?= e($success) ?>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <!-- Room type templates -->
    <section class="mb-6" aria-labelledby="room-templates-heading">
      <div class="flex items-end justify-between gap-3 pb-3">
        <h2 id="room-templates-heading" class="text-lg font-bold font-alt">Room type templates</h2>
        <span class="text-xs font-semibold text-slate-500"><?= count($roomTypes) ?> types</span>
      </div>

      <?php if ($roomTypes): ?>
        <div class="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-4">
          <?php foreach ($roomTypes as $type): ?>
            <?php
              $typeColor = room_type_color((int)$type['id'], (string)$type['name']);
              $typeStats = $roomTypeStats[(int)$type['id']] ?? ['total' => 0, 'occupied' => 0, 'free' => 0];
              $onLightWash = ($typeColor['on'] ?? 'dark') === 'light';
              $fieldLabelClass = $onLightWash ? 'text-white/90' : 'text-slate-900/80';
              $fieldMetaClass = $onLightWash ? 'text-white/70' : 'text-slate-900/55';
              $fieldStatClass = $onLightWash ? 'text-white/80' : 'text-slate-900/65';
            ?>
            <div class="p-4" style="background: <?= e($typeColor['wash']) ?>">
              <div class="flex items-start justify-between gap-2">
                <p class="text-xs font-semibold <?= $fieldLabelClass ?>"><?= e($type['name']) ?></p>
                <p class="text-[11px] font-semibold <?= $fieldMetaClass ?>"><?= (int)$typeStats['total'] ?> rooms</p>
              </div>
              <p class="mt-2 font-alt text-2xl font-bold tabular-nums tracking-tight" style="color: <?= e($typeColor['deep']) ?>">
                UGX <?= e(money($type['default_monthly_rent'])) ?>
              </p>
              <div class="mt-4 flex items-center gap-4 text-[11px] font-semibold <?= $fieldStatClass ?>">
                <span><?= (int)$typeStats['occupied'] ?> occupied</span>
                <span><?= (int)$typeStats['free'] ?> free</span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-700">
          No room types yet. Add one in the panel below.
        </div>
      <?php endif; ?>
    </section>

    <!-- Actions row -->
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 mb-6">

      <!-- Bulk update -->
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-1">
        <h3 class="text-lg font-bold font-alt">Bulk Update Room Type Rent</h3>
        <p class="mt-1 text-sm text-slate-600">Updates the template rent. Rooms without custom overrides will follow.</p>

        <form method="post" class="mt-4 space-y-3"
              data-confirm="This updates the template rent and syncs active tenants on rooms without an override. Continue?">
          <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">Room Type</label>
            <select name="bulk_room_type_id" required
                    class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
              <option value="">Select Room Type</option>
              <?php foreach ($roomTypes as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">New monthly rent (UGX)</label>
            <input type="number" name="bulk_new_rent" required
                   placeholder="e.g. 350000"
                   class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
          </div>

          <button name="bulk_update_rent"
                  class="w-full inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Update Rent
          </button>
        </form>
      </div>

      <!-- Add room type -->
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-1">
        <h3 class="text-lg font-bold font-alt">Add Room Type</h3>
        <p class="mt-1 text-sm text-slate-600">Create a new room type template.</p>

        <form method="post" class="mt-4 space-y-3">
          <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">Name</label>
            <input type="text" name="custom_name" required
                   placeholder="e.g. Single Deluxe"
                   class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
          </div>

          <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">Default monthly rent (UGX)</label>
            <input type="number" name="custom_rent" required
                   placeholder="e.g. 300000"
                   class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
          </div>

          <button name="add_room_type"
                  class="w-full inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
            Add Room Type
          </button>
        </form>
      </div>

      <!-- Add rooms -->
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-1">
        <h3 class="text-lg font-bold font-alt">Add Rooms</h3>
        <p class="mt-1 text-sm text-slate-600">Supports ranges: <span class="font-semibold">A001-A010</span> or lists: <span class="font-semibold">B001,B002</span>.</p>

        <form method="post" class="mt-4 space-y-3">
          <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">Room numbers</label>
            <input name="room_numbers" required
                   placeholder="A001-A010 or B001,B002"
                   class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
          </div>

          <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">Room type</label>
            <select name="room_type_id" required
                    class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
              <option value="">Select Room Type</option>
              <?php foreach ($roomTypes as $t): ?>
                <option value="<?= (int)$t['id'] ?>">
                  <?= e($t['name']) ?> (UGX <?= e(money($t['default_monthly_rent'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">Notes (optional)</label>
            <textarea name="notes" rows="2"
                      placeholder="Internal notes..."
                      class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400"></textarea>
          </div>

          <button name="add_rooms"
                  class="w-full inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Create Rooms
          </button>
        </form>
      </div>

    </div>

    <!-- Rooms table -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-4 py-4 border-b border-slate-200 flex items-center justify-between">
        <div>
          <h2 class="text-lg font-bold font-alt">Rooms</h2>
          <p class="text-sm text-slate-600">Standard rooms use template rent. Custom rooms override it.</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="text-xs text-slate-500">
            Total: <span class="font-semibold"><?= (int)count($rooms) ?></span>
          </div>
          <form method="post" data-confirm="Active tenants will stop using a saved rent and follow their room rent instead. Continue?">
            <button type="submit" name="sync_tenant_rents" value="1"
                    class="inline-flex min-h-9 items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
              Sync tenant rents
            </button>
          </form>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left font-semibold">Room</th>
              <th class="px-4 py-3 text-left font-semibold">Type</th>
              <th class="px-4 py-3 text-left font-semibold">Rent</th>
              <th class="px-4 py-3 text-left font-semibold">Source</th>
              <th class="px-4 py-3 text-left font-semibold">Status</th>
              <th class="px-4 py-3 text-left font-semibold">Action</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
            <?php foreach ($rooms as $r): ?>
              <?php
                $sourceChip = ($r['monthly_rent'] !== null) ? chip('Custom', 'green') : chip('Standard', 'slate');
                $status = (string)($r['status'] ?? '');
                $statusChip = $status === 'occupied' ? chip('Occupied', 'amber') : chip('Free', 'green');
                $rowColor = room_type_color((int)($r['room_type_id'] ?? 0), (string)($r['room_type'] ?? ''));
                $isCustomRent = $r['monthly_rent'] !== null;
              ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-semibold"><?= e($r['room_number'] ?? '') ?></td>
                <td class="px-4 py-3">
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold leading-4"
                        style="background: <?= e($rowColor['wash']) ?>; color: <?= e($rowColor['deep']) ?>">
                    <?= e($r['room_type'] ?? '—') ?>
                  </span>
                </td>
                <td class="px-4 py-3 font-semibold font-alt">
                  <span class="inline-flex items-center gap-2">
                    <?php if (!$isCustomRent): ?>
                      <span class="h-4 w-[3px] shrink-0 rounded-full" style="background: <?= e($rowColor['wash']) ?>" aria-hidden="true"></span>
                    <?php endif; ?>
                    UGX <?= e(money(effectiveRent($r))) ?>
                  </span>
                </td>
                <td class="px-4 py-3"><?= $sourceChip ?></td>
                <td class="px-4 py-3"><?= $statusChip ?></td>
                <td class="px-4 py-3">
                  <button type="button"
                          data-edit-room
                          data-room-id="<?= (int)$r['id'] ?>"
                          data-room-number="<?= e($r['room_number'] ?? '') ?>"
                          data-room-rent="<?= $r['monthly_rent'] !== null ? (int)$r['monthly_rent'] : '' ?>"
                          data-template-rent="<?= (int)($r['default_monthly_rent'] ?? 0) ?>"
                          class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                    Edit rent
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>

        </table>
      </div>
    </div>

    <!-- Modal -->
    <div id="editModal" class="hidden fixed inset-0 z-50" role="dialog" aria-modal="true" aria-labelledby="editRentTitle">
      <div class="absolute inset-0 bg-black/40 modal-backdrop" data-close-modal></div>

      <div class="relative mx-auto mt-24 w-[92%] max-w-md rounded-2xl border border-slate-200 bg-white shadow-lg">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <h3 id="editRentTitle" class="text-lg font-bold font-alt">Edit room rent</h3>
            <p id="editRoomName" class="text-sm text-slate-600">Room</p>
          </div>
          <button type="button"
                  data-close-modal
                  class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
            Close
          </button>
        </div>

        <form method="post" class="p-5 space-y-3">
          <input type="hidden" name="edit_room_id" id="edit_room_id">

          <div>
            <label for="edit_monthly_rent" class="mb-1 block text-xs font-semibold text-slate-600">Monthly rent override (UGX)</label>
            <input type="number" name="monthly_rent" id="edit_monthly_rent" min="0" step="1"
                   placeholder="Leave blank to use the template rent"
                   class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
            <p class="mt-2 text-xs leading-5 text-slate-500">Active tenants in this room follow the saved rent. Leave it blank to fall back to the room type template.</p>
          </div>

          <div class="flex items-center gap-2">
            <button type="submit"
                    class="flex-1 inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
              Save
            </button>
            <button type="button"
                    data-close-modal
                    class="flex-1 inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>

  </main>

  <script nonce="<?= e(csp_nonce()) ?>">
    (() => {
      const modal = document.getElementById('editModal');
      const roomIdInput = document.getElementById('edit_room_id');
      const rentInput = document.getElementById('edit_monthly_rent');
      const roomName = document.getElementById('editRoomName');

      if (!modal || !roomIdInput || !rentInput || !roomName) {
        return;
      }

      let lastTrigger = null;

      const openModal = (trigger) => {
        lastTrigger = trigger;
        roomIdInput.value = trigger.getAttribute('data-room-id') || '';

        const rent = trigger.getAttribute('data-room-rent') || '';
        rentInput.value = rent;

        const templateRent = trigger.getAttribute('data-template-rent') || '';
        rentInput.placeholder = templateRent !== ''
          ? 'Template: UGX ' + Number(templateRent).toLocaleString()
          : 'Leave blank to use the template rent';

        roomName.textContent = 'Room ' + (trigger.getAttribute('data-room-number') || '');
        modal.classList.remove('hidden');
        rentInput.focus();
      };

      const closeModal = () => {
        modal.classList.add('hidden');
        if (lastTrigger) {
          lastTrigger.focus();
        }
      };

      document.addEventListener('click', (event) => {
        const editTrigger = event.target.closest('[data-edit-room]');
        if (editTrigger) {
          event.preventDefault();
          openModal(editTrigger);
          return;
        }

        if (event.target.closest('[data-close-modal]')) {
          event.preventDefault();
          closeModal();
        }
      });

      document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');
        if (form && !window.confirm(form.getAttribute('data-confirm'))) {
          event.preventDefault();
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
          closeModal();
        }
      });
    })();
  </script>
</body>
</html>
