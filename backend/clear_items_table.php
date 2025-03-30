<?php // backend/clear_items_table.php

require_once 'database.php';

echo "Attempting to clear the 'items' table...\n";

$db = getDbConnection();

if ($db === null) {
    echo "Error: Could not connect to the database.\n";
    exit(1);
}

try {
    // Disable foreign keys temporarily if needed, though DELETE FROM shouldn't violate them here
    // $db->exec('PRAGMA foreign_keys = OFF;'); 

    $sql = "DELETE FROM items"; // SQL to delete all rows
    $stmt = $db->prepare($sql);
    $rowCount = $stmt->execute(); // Execute returns true on success, or use rowCount()

    // You can optionally use rowCount() if your PDO driver supports it for DELETE
    // $deletedRows = $stmt->rowCount(); 
    // echo "Successfully deleted $deletedRows rows from the 'items' table.\n";
    
    echo "Successfully executed DELETE FROM items query.\n";

    // Re-enable foreign keys if they were disabled
    // $db->exec('PRAGMA foreign_keys = ON;');

} catch (PDOException $e) {
    echo "Error clearing 'items' table: " . $e->getMessage() . "\n";
    // Re-enable foreign keys in case of error too
    // $db->exec('PRAGMA foreign_keys = ON;');
    exit(1);
} finally {
    $db = null; // Close connection
}

echo "'items' table cleared successfully.\n";
exit(0);

?>