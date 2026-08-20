<?php
// ===============================
// BOOTSTRAP
// ===============================
$pdo = require __DIR__ . '/../config/database.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ===============================
// ROUTING
// ===============================
$action = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$error  = null;

// ===============================
// HELPERS
// ===============================
function redirect_list() {
    header('Location: room_types.php');
    exit;
}

// ===============================
// ACTION HANDLERS
// ===============================

// CREATE
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $rent = $_POST['default_monthly_rent'] ?? '';
    $desc = trim($_POST['description'] ?? '');

    if ($name === '' || !is_numeric($rent) || $rent <= 0) {
        $error = 'Name and a valid rent amount are required.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO room_types (name, default_monthly_rent, description)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$name, $rent, $desc]);
        redirect_list();
    }
}

// UPDATE
if ($action === 'update' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name']);
    $rent   = $_POST['default_monthly_rent'];
    $desc   = trim($_POST['description']);
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || !is_numeric($rent) || $rent <= 0) {
        $error = 'Name and a valid rent amount are required.';
    } else {
        $stmt = $pdo->prepare("
            UPDATE room_types
            SET name = ?, default_monthly_rent = ?, description = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $rent, $desc, $active, $id]);
        redirect_list();
    }
}

// DISABLE (SOFT DELETE)
if ($action === 'disable' && $id) {
    $stmt = $pdo->prepare("SELECT name FROM room_types WHERE id = ?");
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();

    if ($name && $name !== 'Default Template') {
        $stmt = $pdo->prepare("
            UPDATE room_types SET is_active = 0 WHERE id = ?
        ");
        $stmt->execute([$id]);
    }
    redirect_list();
}

// FETCH FOR EDIT
$editRoomType = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM room_types WHERE id = ?");
    $stmt->execute([$id]);
    $editRoomType = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editRoomType) {
        die('Room type not found.');
    }
}

// FETCH ALL FOR LIST
$stmt = $pdo->query("
    SELECT id, name, default_monthly_rent, is_active
    FROM room_types
    ORDER BY name ASC
");
$roomTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Room Type Pricing Templates</title>
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; margin-top: 15px; }
        th, td { border: 1px solid #ccc; padding: 8px; }
        th { background: #f4f4f4; }
        .error { color: red; }
        .btn { padding: 6px 10px; text-decoration: none; background: #007bff; color: #fff; border-radius: 3px; }
        .btn-danger { background: #dc3545; }
        .btn-secondary { background: #6c757d; }
    </style>
</head>
<body>

<h2>Room Type Pricing Templates</h2>

<?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<!-- ===============================
     CREATE / EDIT FORM
=============================== -->
<?php if ($action === 'add' || $action === 'edit'): ?>
<form method="post"
      action="room_types.php?action=<?= $action === 'edit' ? 'update&id='.$editRoomType['id'] : 'create' ?>">

    <p>
        <label>Name</label><br>
        <input type="text" name="name"
               value="<?= htmlspecialchars($editRoomType['name'] ?? '') ?>">
    </p>

    <p>
        <label>Default Monthly Rent</label><br>
        <input type="number" step="0.01" name="default_monthly_rent"
               value="<?= htmlspecialchars($editRoomType['default_monthly_rent'] ?? '') ?>">
    </p>

    <p>
        <label>Description</label><br>
        <textarea name="description"><?= htmlspecialchars($editRoomType['description'] ?? '') ?></textarea>
    </p>

    <?php if ($action === 'edit'): ?>
        <p>
            <label>
                <input type="checkbox" name="is_active"
                    <?= $editRoomType['is_active'] ? 'checked' : '' ?>>
                Active
            </label>
        </p>
    <?php endif; ?>

    <button type="submit" class="btn">Save</button>
    <a href="room_types.php" class="btn btn-secondary">Cancel</a>
</form>

<hr>
<?php endif; ?>

<!-- ===============================
     LIST
=============================== -->
<p>
    <a href="room_types.php?action=add" class="btn">+ Add Room Type</a>
</p>

<table>
    <tr>
        <th>Name</th>
        <th>Default Rent</th>
        <th>Status</th>
        <th>Actions</th>
    </tr>
    <?php foreach ($roomTypes as $rt): ?>
        <tr>
            <td><?= htmlspecialchars($rt['name']) ?></td>
            <td><?= number_format($rt['default_monthly_rent'], 2) ?></td>
            <td><?= $rt['is_active'] ? 'Active' : 'Disabled' ?></td>
            <td>
                <a class="btn" href="room_types.php?action=edit&id=<?= $rt['id'] ?>">Edit</a>
                <?php if ($rt['name'] !== 'Default Template'): ?>
                    <a class="btn btn-danger"
                       href="room_types.php?action=disable&id=<?= $rt['id'] ?>"
                       onclick="return confirm('Disable this room type?')">
                       Disable
                    </a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
