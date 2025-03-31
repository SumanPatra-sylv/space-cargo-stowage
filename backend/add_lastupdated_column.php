<?php // backend/add_lastupdated_column.php
require_once __DIR__ . '/database.php';
echo "Attempting to add 'lastUpdated' column to 'items' table...\n";
$db = getDbConnection();
if(!$db) { echo "DB Connection failed.\n"; exit(1); }
try {
    $check = $db->query("PRAGMA table_info(items);");
    $columns = $check->fetchAll(PDO::FETCH_COLUMN, 1); // Get column names
    if (!in_array('lastUpdated', $columns)) {
        // Add as TEXT, store ISO8601 or Y-m-d H:i:s format
        $db->exec("ALTER TABLE items ADD COLUMN lastUpdated TEXT;"); 
        echo "'lastUpdated' column added successfully.\n";
    } else {
        echo "'lastUpdated' column already exists.\n";
    }
} catch (PDOException $e) { echo "Error: " . $e->getMessage() . "\n"; } 
$db = null;
?>