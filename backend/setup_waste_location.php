<?php // backend/setup_waste_location.php
require_once __DIR__ . '/database.php';
echo "Attempting to set location for waste item ITM001...\n";
$db = getDbConnection();
if(!$db) { echo "DB Connection failed.\n"; exit(1); }

$itemIdToPlace = 'ITM005';
$targetContainer = 'contE'; // Use contA as the 'undocking' container for test
$targetW = 0.0;
$targetD = 0.0;
$targetH = 0.0;

try {
    // Check if container exists
    $contStmt = $db->prepare("SELECT 1 FROM containers WHERE containerId = :cid");
    $contStmt->execute([':cid' => $targetContainer]);
    if(!$contStmt->fetch()) { throw new Exception("Container $targetContainer not found!"); }

    // Check if item exists and IS WASTE
    $itemStmt = $db->prepare("SELECT status FROM items WHERE itemId = :id");
    $itemStmt->execute([':id' => $itemIdToPlace]);
    $item = $itemStmt->fetch();
    if(!$item) { throw new Exception("Item $itemIdToPlace not found!"); }
    if($item['status'] !== 'waste_depleted' && $item['status'] !== 'waste_expired') {
         throw new Exception("Item $itemIdToPlace is not currently waste (Status: ".$item['status']."). Run /api/retrieve test first.");
    }

    // Update location BUT KEEP WASTE STATUS
    $sql = "UPDATE items 
            SET 
                currentContainerId = :containerId, 
                pos_w = :pos_w, 
                pos_d = :pos_d, 
                pos_h = :pos_h
                -- DO NOT change status here --
            WHERE itemId = :itemId";
    $updateStmt = $db->prepare($sql);
    $updateStmt->bindParam(':containerId', $targetContainer);
    $updateStmt->bindParam(':pos_w', $targetW);
    $updateStmt->bindParam(':pos_d', $targetD);
    $updateStmt->bindParam(':pos_h', $targetH);
    $updateStmt->bindParam(':itemId', $itemIdToPlace);

    if ($updateStmt->execute() && $updateStmt->rowCount() > 0) {
        echo "Successfully updated location of waste item $itemIdToPlace to $targetContainer @ ($targetW, $targetD, $targetH).\n";
    } else {
         echo "Failed to update location for $itemIdToPlace or location already set. Error: " . print_r($updateStmt->errorInfo(), true) . "\n";
    }

} catch (Exception $e) { echo "Error setting up waste location: " . $e->getMessage() . "\n"; exit(1); } 
$db = null;
?>