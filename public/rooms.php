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

    try {
        logAudit(
            $admin_id,
            'ROOM_RENT_UPDATED',
            "Room #{$room_id} rent override set to " . ($new_rent === null ? 'template default' : (string)$new_rent) . "."
        );
    } catch (Throwable $ignored) {}

    $_SESSION['success'] = "Room rent updated successfully.";
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

            if (function_exists('logAudit')) {
                try {
                    logAudit(
                        $admin_id,
                        'BULK_RENT_UPDATE',
                        "Room type '{$type['name']}' rent changed from {$type['default_monthly_rent']} to {$newRent}. Affected rooms: {$affected}"
                    );
                } catch (Throwable $ignored) {}
            }

            $pdo->commit();

            $_SESSION['success'] = "Rent updated. {$affected} rooms affected.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Valid room type and rent are required.";
    }

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

$rooms = $pdo->query("
    SELECT r.*, rt.name AS room_type, rt.default_monthly_rent
    FROM rooms r
    JOIN room_types rt ON rt.id = r.room_type_id
    ORDER BY r.room_number
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

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

  <script>
    function openEditModal(id, rent) {
      document.getElementById('edit_room_id').value = id;
      document.getElementById('edit_monthly_rent').value = (rent === null ? '' : rent);
      document.getElementById('editModal').classList.remove('hidden');
    }
    function closeModal() {
      document.getElementById('editModal').classList.add('hidden');
    }
  </script>
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

    <!-- Templates grid -->
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6">
      <div class="flex items-center justify-between gap-2">
        <div>
          <h2 class="text-lg font-bold font-alt">Default Room Fees / Templates</h2>
          <p class="text-sm text-slate-600">These are the standard rents per room type.</p>
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($roomTypes as $type): ?>
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="text-sm font-semibold"><?= e($type['name']) ?></div>
            <div class="mt-2 text-2xl font-black font-alt">UGX <?= e(money($type['default_monthly_rent'])) ?></div>
            <div class="mt-2 text-xs text-slate-500">Standard monthly rent</div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Actions row -->
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 mb-6">

      <!-- Bulk update -->
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-1">
        <h3 class="text-lg font-bold font-alt">Bulk Update Room Type Rent</h3>
        <p class="mt-1 text-sm text-slate-600">Updates the template rent. Rooms without custom overrides will follow.</p>

        <form method="post" class="mt-4 space-y-3">
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
                  onclick="return confirm('This will update all rooms using this template. Continue?')"
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
        <div class="text-xs text-slate-500">
          Total: <span class="font-semibold"><?= (int)count($rooms) ?></span>
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
              ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-semibold"><?= e($r['room_number'] ?? '') ?></td>
                <td class="px-4 py-3"><?= e($r['room_type'] ?? '') ?></td>
                <td class="px-4 py-3 font-semibold font-alt">UGX <?= e(money(effectiveRent($r))) ?></td>
                <td class="px-4 py-3"><?= $sourceChip ?></td>
                <td class="px-4 py-3"><?= $statusChip ?></td>
                <td class="px-4 py-3">
                  <button type="button"
                          onclick="openEditModal(<?= (int)$r['id'] ?>,<?= $r['monthly_rent'] !== null ? (int)$r['monthly_rent'] : 'null' ?>)"
                          class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
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
    <div id="editModal" class="hidden fixed inset-0 z-50">
      <div class="absolute inset-0 bg-black/40 modal-backdrop" onclick="closeModal()"></div>

      <div class="relative mx-auto mt-24 w-[92%] max-w-md rounded-2xl border border-slate-200 bg-white shadow-lg">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold font-alt">Edit Room Rent</h3>
            <p class="text-sm text-slate-600">Leave blank to use the template rent.</p>
          </div>
          <button type="button"
                  onclick="closeModal()"
                  class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50">
            Close
          </button>
        </div>

        <form method="post" class="p-5 space-y-3">
          <input type="hidden" name="edit_room_id" id="edit_room_id">

          <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">Monthly rent override (UGX)</label>
            <input type="number" name="monthly_rent" id="edit_monthly_rent"
                   placeholder="Leave blank to use template"
                   class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
          </div>

          <div class="flex items-center gap-2">
            <button type="submit"
                    class="flex-1 inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
              Save
            </button>
            <button type="button"
                    onclick="closeModal()"
                    class="flex-1 inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>

  </main>
</body>
</html>
