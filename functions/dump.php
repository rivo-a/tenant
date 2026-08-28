<?php
// public/dump_schema.php
require_once __DIR__ . '/../config/config.php';

$pdo = getDB();

echo "<h2>📊 Database Schema Dump</h2>";
echo "<style>body{font-family:monospace; background:#1e1e1e; color:#d4d4d4; padding:20px;} table{border-collapse:collapse; width:100%; margin-bottom:30px;} th,td{border:1px solid #555; padding:8px; text-align:left;} th{background:#333; color:#fff;} h3{color:#4ec9b0; border-bottom:1px solid #555; padding-bottom:5px;}</style>";

// 1. Get all tables
$stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    echo "<h3>📁 Table: <strong>" . htmlspecialchars($table) . "</strong></h3>";
    echo "<table><tr><th>Column Name</th><th>Data Type</th><th>Not Null</th><th>Default</th><th>Primary Key</th></tr>";
    
    // 2. Get columns for each table
    $pragma = $pdo->query("PRAGMA table_info('$table')");
    $columns = $pragma->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        $notNull = $col['notnull'] ? 'YES' : 'NO';
        $pk = $col['pk'] ? 'YES' : 'NO';
        $default = $col['dflt_value'] ?? 'NULL';
        
        echo "<tr>";
        echo "<td style='color:#9cdcfe;'>" . htmlspecialchars($col['name']) . "</td>";
        echo "<td>" . htmlspecialchars($col['type']) . "</td>";
        echo "<td>" . $notNull . "</td>";
        echo "<td>" . htmlspecialchars($default) . "</td>";
        echo "<td>" . $pk . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<p style='color:#ce9178;'>✅ Done! Copy this output and share it.</p>";
?>