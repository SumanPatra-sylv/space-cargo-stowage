<?php // backend/set_expiry.php
require_once __DIR__ . '/database.php';
echo "Attempting to set expiry date...\n";
$db = getDbConnection();
if(!$db) { echo "DB Connection failed.\n"; exit(1); }

$itemIdToExpire = 'ITM004'; // Choose an item that is NOT waste yet
$pastDate = '2024-01-15';   // Set a date clearly in the past

try {
    $sql = "UPDATE items SET expiryDate = :expiryDate WHERE itemId = :itemId";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':expiryDate', $pastDate);
    $stmt->bindParam(':itemId', $itemIdToExpire);
    
    if ($stmt->execute() && $stmt->rowCount() > 0) {
        echo "Successfully set expiryDate for $itemIdToExpire to $pastDate.\n";
    } elseif ($stmt->rowCount() == 0) {
         echo "Item $itemIdToExpire not found or date already set.\n";
    } else {
        echo "Failed to set expiry date for $itemIdToExpire. Error: " . print_r($stmt->errorInfo(), true) . "\n";
    }
} catch (PDOException $e) {
    echo "Error setting expiry: " . $e->getMessage() . "\n";
}
$db = null;
?>