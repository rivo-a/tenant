<?php
declare(strict_types=1);

/**
 * 008 — negotiated rent overrides, room number uniqueness, normalised phones.
 *
 * Run: php database/migrations/008_rent_overrides_room_uniqueness_phones.php
 *
 * 1. tenants.rent_is_override records that a landlord deliberately set a rent
 *    on the tenancy. Room and room-type rent changes may clear inherited
 *    snapshots but never a deliberate override.
 *    Existing non-null rents are marked 0 (inherited): before this migration
 *    every room change already wiped them, so treating them as overrides would
 *    freeze rents that were never negotiated.
 * 2. One room number per business.
 * 3. tenants.phone_normalized so "+256 7XX", "07XX" and "2567XX" sign in as the
 *    same number, unique per business.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/rent.php';

$pdo = getDB();

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (strtolower((string)$row['name']) === strtolower($column)) {
            return true;
        }
    }

    return false;
}

function index_exists(PDO $pdo, string $index): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :name");
    $stmt->execute([':name' => $index]);

    return (bool)$stmt->fetchColumn();
}

/* ---- 1. rent overrides ---- */
if (!column_exists($pdo, 'tenants', 'rent_is_override')) {
    $pdo->exec('ALTER TABLE tenants ADD COLUMN rent_is_override INTEGER NOT NULL DEFAULT 0');
    echo "added tenants.rent_is_override\n";
} else {
    echo "tenants.rent_is_override already present\n";
}

/* ---- 2. room number uniqueness ---- */
$duplicateRooms = $pdo->query("
    SELECT admin_id, room_number, COUNT(*) AS copies
    FROM rooms
    GROUP BY admin_id, room_number
    HAVING copies > 1
")->fetchAll(PDO::FETCH_ASSOC);

if ($duplicateRooms) {
    echo "SKIPPED room index — duplicate room numbers already exist:\n";
    foreach ($duplicateRooms as $row) {
        echo "  admin {$row['admin_id']} room '{$row['room_number']}' x{$row['copies']}\n";
    }
} elseif (!index_exists($pdo, 'rooms_admin_room_number_unique')) {
    $pdo->exec('CREATE UNIQUE INDEX rooms_admin_room_number_unique ON rooms (admin_id, room_number)');
    echo "created rooms_admin_room_number_unique\n";
} else {
    echo "rooms_admin_room_number_unique already present\n";
}

/* ---- 3. normalised phones ---- */
if (!column_exists($pdo, 'tenants', 'phone_normalized')) {
    $pdo->exec('ALTER TABLE tenants ADD COLUMN phone_normalized TEXT');
    echo "added tenants.phone_normalized\n";
}

$rows = $pdo->query('SELECT id, admin_id, phone, phone_normalized FROM tenants')->fetchAll(PDO::FETCH_ASSOC);
$update = $pdo->prepare('UPDATE tenants SET phone_normalized = :normalized WHERE id = :id');
$changed = 0;
$clashes = [];

foreach ($rows as $row) {
    $normalized = phone_normalize((string)($row['phone'] ?? ''));
    if ($normalized === '') {
        $normalized = null;
    }

    if ($normalized !== $row['phone_normalized']) {
        $update->execute([':normalized' => $normalized, ':id' => (int)$row['id']]);
        $changed++;
    }

    if ($normalized === null) {
        continue;
    }

    $key = (int)$row['admin_id'] . '|' . $normalized;
    if (isset($clashes[$key])) {
        $clashes[$key] = ($clashes[$key] ?? 0) + 1;
        continue;
    }
    $clashes[$key] = 0;
}

echo "normalised {$changed} phone numbers\n";

$duplicatePhones = $pdo->query("
    SELECT admin_id, phone_normalized, COUNT(*) AS copies
    FROM tenants
    WHERE phone_normalized IS NOT NULL AND phone_normalized <> ''
    GROUP BY admin_id, phone_normalized
    HAVING copies > 1
")->fetchAll(PDO::FETCH_ASSOC);

if ($duplicatePhones) {
    echo "SKIPPED phone index — duplicate normalised phones per business:\n";
    foreach ($duplicatePhones as $row) {
        echo "  admin {$row['admin_id']} phone {$row['phone_normalized']} x{$row['copies']}\n";
    }
} elseif (!index_exists($pdo, 'tenants_admin_phone_unique')) {
    $pdo->exec("
        CREATE UNIQUE INDEX tenants_admin_phone_unique
        ON tenants (admin_id, phone_normalized)
        WHERE phone_normalized IS NOT NULL AND phone_normalized <> ''
    ");
    echo "created tenants_admin_phone_unique\n";
} else {
    echo "tenants_admin_phone_unique already present\n";
}

echo "migration 008 complete\n";
