<?php
declare(strict_types=1);

/*
 * First-run setup for a new landlord: create the property, then add its rooms.
 * Until a property exists, the rest of the app has nothing to attach rooms,
 * tenants or rent to, so this runs before the dashboard.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_login();

$adminId = current_business_id();
$me = (int)($_SESSION['admin_id'] ?? ($_SESSION['admin']['id'] ?? 0));
$db = getDB();
$error = '';

$properties = $db->prepare('SELECT id, name FROM properties WHERE admin_id = :a ORDER BY id');
$properties->execute([':a' => $adminId]);
$properties = $properties->fetchAll(PDO::FETCH_ASSOC) ?: [];

$propertyId = (int)($_GET['property'] ?? $_SESSION['onboarding_property'] ?? 0);
$current = null;
foreach ($properties as $p) {
    if ((int)$p['id'] === $propertyId) {
        $current = $p;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf(is_scalar($_POST['csrf'] ?? null) ? (string)$_POST['csrf'] : '');
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'property') {
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));

            if ($name === '' || mb_strlen($name) > 80) {
                throw new RuntimeException('Give the property a name of up to 80 characters.');
            }

            $dupe = $db->prepare('SELECT 1 FROM properties WHERE admin_id = :a AND LOWER(name) = LOWER(:n)');
            $dupe->execute([':a' => $adminId, ':n' => $name]);
            if ($dupe->fetchColumn()) {
                throw new RuntimeException('You already have a property with that name.');
            }

            $insert = $db->prepare('INSERT INTO properties (admin_id, name, address, created_at) VALUES (:a, :n, :addr, CURRENT_TIMESTAMP)');
            $insert->execute([':a' => $adminId, ':n' => $name, ':addr' => $address !== '' ? $address : null]);
            $newId = (int)$db->lastInsertId();

            $_SESSION['onboarding_property'] = $newId;
            logAudit($me, 'PROPERTY_CREATED', "Property created during setup: {$name}", ['target_type' => 'property', 'target_id' => $newId]);

            redirect('onboarding.php?property=' . $newId);
        }

        if ($action === 'rooms') {
            $targetProperty = (int)($_POST['property_id'] ?? 0);
            $numbers = trim((string)($_POST['room_numbers'] ?? ''));
            $rent = (int)($_POST['rent'] ?? 0);

            $owns = $db->prepare('SELECT 1 FROM properties WHERE id = :id AND admin_id = :a');
            $owns->execute([':id' => $targetProperty, ':a' => $adminId]);
            if (!$owns->fetchColumn()) {
                throw new RuntimeException('Choose one of your properties.');
            }
            if ($numbers === '') {
                throw new RuntimeException('Enter at least one room number.');
            }
            if ($rent < 0) {
                throw new RuntimeException('Rent cannot be negative.');
            }

            // Rooms need a room type; create a business-owned default once.
            $type = $db->prepare('SELECT id FROM room_types WHERE admin_id = :a ORDER BY id LIMIT 1');
            $type->execute([':a' => $adminId]);
            $roomTypeId = (int)($type->fetchColumn() ?: 0);

            if ($roomTypeId === 0) {
                $newType = $db->prepare("INSERT INTO room_types (name, default_monthly_rent, is_active, admin_id, owner_id) VALUES ('Standard', :r, 1, :a, :a)");
                $newType->execute([':r' => $rent, ':a' => $adminId]);
                $roomTypeId = (int)$db->lastInsertId();
            }

            // "A001-A004" or "A001, A002, B010"
            $list = [];
            foreach (preg_split('/[,;]+/', $numbers) ?: [] as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                if (preg_match('/^([A-Za-z]*)(\d+)\s*-\s*([A-Za-z]*)(\d+)$/', $chunk, $m) && $m[1] === $m[3]) {
                    $from = (int)$m[2];
                    $to = (int)$m[4];
                    if ($to >= $from && ($to - $from) < 200) {
                        $width = strlen($m[2]);
                        for ($i = $from; $i <= $to; $i++) {
                            $list[] = $m[1] . str_pad((string)$i, $width, '0', STR_PAD_LEFT);
                        }
                        continue;
                    }
                }
                $list[] = $chunk;
            }

            $insertRoom = $db->prepare("
                INSERT INTO rooms (room_number, room_type_id, monthly_rent, notes, status, admin_id, property_id)
                VALUES (:n, :t, :r, NULL, 'free', :a, :p)
            ");
            $made = 0;
            $skipped = [];
            foreach ($list as $number) {
                if (mb_strlen($number) > 20) {
                    continue;
                }
                try {
                    $insertRoom->execute([':n' => $number, ':t' => $roomTypeId, ':r' => $rent, ':a' => $adminId, ':p' => $targetProperty]);
                    $made++;
                } catch (PDOException $e) {
                    $skipped[] = $number;
                }
            }

            logAudit($me, 'ROOMS_CREATED', "Rooms added during setup: {$made}", ['target_type' => 'property', 'target_id' => $targetProperty]);
            unset($_SESSION['onboarding_property']);

            if ($made === 0) {
                throw new RuntimeException('Those room numbers already exist: ' . implode(', ', $skipped));
            }

            $_SESSION['success'] = "{$made} rooms added. Add a tenant whenever you are ready.";
            redirect('dashboard.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$step = $current !== null ? 2 : 1;
$roomCount = 0;
if ($current !== null) {
    $countStmt = $db->prepare('SELECT COUNT(*) FROM rooms WHERE property_id = :p AND admin_id = :a');
    $countStmt->execute([':p' => (int)$current['id'], ':a' => $adminId]);
    $roomCount = (int)$countStmt->fetchColumn();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set up your first property · Urbahan</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<main class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
  <div class="flex items-center justify-between gap-3">
    <div class="flex items-center gap-3">
      <img class="w-9" src="/tenant-system/public/assets/urbahan-logo.png" alt="">
      <span class="font-extrabold">Urbahan</span>
    </div>
    <a href="logout.php" class="text-xs font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4">Sign out</a>
  </div>

  <h1 class="mt-8 font-alt text-2xl font-bold tracking-tight sm:text-3xl">
    <?= $step === 1 ? 'Name your first property' : 'Add the rooms in ' . e((string)$current['name']) ?>
  </h1>
  <p class="mt-2 text-sm text-slate-600">
    <?= $step === 1
        ? 'A property is the building or block you collect rent for. Rooms, tenants and rent hang off it.'
        : 'Rooms belong to this property. You can add more later from Rooms.' ?>
  </p>

  <div class="mt-6 flex items-center gap-3 text-xs font-semibold">
    <span class="rounded-full px-3 py-1 <?= $step === 1 ? 'bg-slate-900 text-white' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' ?>">1 · Property</span>
    <span class="text-slate-400">→</span>
    <span class="rounded-full px-3 py-1 <?= $step === 2 ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600' ?>">2 · Rooms</span>
  </div>

  <?php if ($error): ?>
    <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
    <form method="post" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="property">
      <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Property name
        <input name="name" required maxlength="80" placeholder="Kiwatule Block B" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
      </label>
      <label class="mt-4 block text-xs font-bold uppercase tracking-wide text-slate-600">Area or address <span class="font-normal normal-case text-slate-500">(optional)</span>
        <input name="address" maxlength="120" placeholder="Kiwatule, Kampala" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
      </label>
      <button type="submit" class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">
        Save property and continue
      </button>
    </form>

    <?php if ($properties): ?>
      <p class="mt-4 text-xs text-slate-500">
        You already have: <?= e(implode(', ', array_column($properties, 'name'))) ?>.
        <a class="font-semibold text-slate-800 underline decoration-slate-300 underline-offset-4" href="onboarding.php?property=<?= (int)$properties[0]['id'] ?>">Continue with one of them</a>
      </p>
    <?php endif; ?>

  <?php else: ?>
    <form method="post" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="rooms">
      <input type="hidden" name="property_id" value="<?= (int)$current['id'] ?>">
      <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Room numbers
        <input name="room_numbers" required placeholder="A001-A008 or A001, A002, B010" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
        <span class="mt-1 block text-xs font-normal normal-case text-slate-500">A range like A001-A008 adds eight rooms.</span>
      </label>
      <label class="mt-4 block text-xs font-bold uppercase tracking-wide text-slate-600">Rent per month (UGX)
        <input name="rent" type="number" min="0" step="1000" value="300000" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm tabular-nums">
      </label>
      <button type="submit" class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">
        Add rooms and open my dashboard
      </button>
      <?php if ($roomCount > 0): ?>
        <a href="dashboard.php" class="mt-3 block text-center text-xs font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4">
          <?= (int)$roomCount ?> rooms already here — skip to dashboard
        </a>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
