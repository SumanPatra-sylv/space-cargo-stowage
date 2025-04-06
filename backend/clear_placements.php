<?php // backend/clear_placements.php (CORRECTED)

require_once __DIR__ . '/database.php'; // Include the connection helper

echo "Attempting to clear placement data and reset status in 'items' table...\n";
echo "---------------------------------------------------------------------------\n";

$db = getDbConnection();

if ($db === null) {
    echo "Error: Could not connect to the database.\n";
    exit(1);
}

try {
    // SQL to update items, clear placement data, and reset status
    // Using correct column names from your schema
    $sql = "UPDATE items
            SET
                containerId = NULL,      -- CORRECTED
                positionX = NULL,        -- CORRECTED
                positionY = NULL,        -- CORRECTED
                positionZ = NULL,        -- CORRECTED
                placedDimensionW = NULL, -- Added
                placedDimensionD = NULL, -- Added
                placedDimensionH = NULL, -- Added
                status = 'available',    -- Added: Reset status
                lastUpdated = :currentTime -- Added: Update timestamp
            WHERE containerId IS NOT NULL OR status = 'stowed'"; // CORRECTED & Expanded WHERE

    $stmt = $db->prepare($sql);

    // Bind current time for lastUpdated
    $currentTime = date('Y-m-d H:i:s');
    $stmt->bindParam(':currentTime', $currentTime);

    $stmt->execute();

    $updatedRows = $stmt->rowCount(); // Get the number of rows affected

    echo "Successfully cleared placement data and reset status for $updatedRows items.\n";

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