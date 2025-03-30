<?php // backend/add_mass_column.php
require_once __DIR__ . '/database.php';
echo "Attempting to add 'mass' column to 'items' table...\n";
$db = getDbConnection();
if(!$db) { echo "DB Connection failed.\n"; exit(1); }
try {
    // Check if column exists first to avoid error on re-run
    $check = $db->query("PRAGMA table_info(items);");
    $columns = $check->fetchAll(PDO::FETCH_COLUMN, 1); // Get column names
    if (!in_array('mass', $columns)) {
        $db->exec("ALTER TABLE items ADD COLUMN mass REAL;"); // Use REAL for potential decimal kg
        echo "'mass' column added successfully.\n";
    } else {
        echo "'mass' column already exists.\n";
    }
} catch (PDOException $e) { echo "Error: " . $e->getMessage() . "\n"; } 
$db = null;
?>