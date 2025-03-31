<?php // backend/clear_logs_table.php
require_once __DIR__ . '/database.php';
echo "Attempting to clear 'logs' table...\n";
$db = getDbConnection();
if(!$db) { echo "DB Connection Failed.\n"; exit(1); }
try {
    $rowCount = $db->exec("DELETE FROM logs;");
    echo "Cleared $rowCount rows from logs table.\n";
} catch(PDOException $e) { echo "Error: " . $e->getMessage() . "\n"; }
$db=null;
?>