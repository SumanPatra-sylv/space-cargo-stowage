<?php // backend/clear_placements.php

require_once __DIR__ . '/database.php'; // Include the connection helper (points to backend/database.php)

echo "Attempting to clear placement data (set location to NULL) in 'items' table...\n";
echo "---------------------------------------------------------------------------\n";

$db = getDbConnection();

if ($db === null) {
    echo "Error: Could not connect to the database.\n";
    exit(1);
}

try {
    // SQL to update existing items and remove their location data
    $sql = "UPDATE items 
            SET 
                currentContainerId = NULL, 
                pos_w = NULL, 
                pos_d = NULL, 
                pos_h = NULL 
            WHERE currentContainerId IS NOT NULL"; // Only update items that actually have a placement

    $stmt = $db->prepare($sql);
    $stmt->execute(); 
    
    $updatedRows = $stmt->rowCount(); // Get the number of rows affected

    echo "Successfully cleared placement data for $updatedRows items.\n";

} catch (PDOException $e) {
    echo "Error clearing placement data: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $db = null; // Close connection
}

echo "---------------------------------------------------------------------------\n";
echo "Finished clearing placement data.\n";
exit(0);

?>