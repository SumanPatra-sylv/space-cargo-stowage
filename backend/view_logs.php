<?php // backend/view_logs.php
require_once __DIR__ . '/database.php';
echo "Attempting to view 'logs' table...\n";
$db = getDbConnection();
if(!$db) { echo "DB Connection Failed.\n"; exit(1); }
try {
    $stmt = $db->query("SELECT * FROM logs ORDER BY logId DESC");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if(empty($logs)) { echo "Logs table is empty.\n"; }
    else { print_r($logs); } // Simple print_r for verification
} catch(PDOException $e) { echo "Error: " . $e->getMessage() . "\n"; }
$db=null;
?>