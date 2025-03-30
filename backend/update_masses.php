<?php // backend/update_masses.php
require_once __DIR__ . '/database.php';
echo "Attempting to update item masses...\n";
$db = getDbConnection();
if(!$db) { echo "DB Connection failed.\n"; exit(1); }

// --- Define Mass Updates Here ---
$itemMasses = [
    'ITM001' => 1.5,  // Food Pack
    'ITM002' => 0.6,  // Water Bottle
    'ITM003' => 0.8,  // Screwdriver
    'ITM004' => 2.5,  // Sensor Array
    'ITM005' => 1.5,  // First Aid Kit
    'ITM006' => 0.3,  // Spare Battery
    'ITM007' => 0.2,  // Sample Container
    'ITM008' => 0.1,  // Data Cable
    'OTHER01'=> 1.0   // Obstacle
    // Add any other items...
];
// --- End Define Mass Updates ---

try {
    $updateSql = "UPDATE items SET mass = :mass WHERE itemId = :itemId";
    $updateStmt = $db->prepare($updateSql);
    $updatedCount = 0;
    $db->beginTransaction(); // Use transaction

    foreach ($itemMasses as $id => $mass) {
        $updateStmt->bindParam(':mass', $mass);
        $updateStmt->bindParam(':itemId', $id);
        if($updateStmt->execute()) {
            if($updateStmt->rowCount() > 0) {
                echo "Updated mass for $id to $mass kg.\n";
                $updatedCount++;
            } else {
                echo "Item $id not found or mass already set.\n";
            }
        } else {
            echo "Failed to update mass for $id. Error: " . print_r($updateStmt->errorInfo(), true) . "\n";
        }
    }
    $db->commit();
     echo "Finished updating masses for $updatedCount items.\n";

} catch (PDOException $e) { 
    if($db->inTransaction()) { $db->rollBack(); }
    echo "Error updating masses: " . $e->getMessage() . "\n"; 
} 
$db = null;
?>