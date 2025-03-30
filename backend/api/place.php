<?php // backend/api/place.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: POST, OPTIONS'); 
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- Database Connection ---
require_once __DIR__ . '/../database.php'; 
error_log("Place API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) { 
    error_log("Place API: Failed DB connection.");
    http_response_code(500); 
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit; 
}
error_log("Place API: DB connection successful.");
// --- End Database Connection --- 

// --- Response Structure ---
$response = ['success' => false, 'message' => ''];

// --- Handle Input ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    error_log("Place API: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null; exit; 
}

$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true); 

// Validate input JSON Structure
if ($requestData === null || 
    !isset($requestData['itemId']) || 
    !isset($requestData['containerId']) || 
    !isset($requestData['position']) || !is_array($requestData['position']) ||
    !isset($requestData['position']['startCoordinates']) || !is_array($requestData['position']['startCoordinates']) ||
    !isset($requestData['position']['startCoordinates']['width']) || 
    !isset($requestData['position']['startCoordinates']['depth']) || 
    !isset($requestData['position']['startCoordinates']['height'])
   ) {
    http_response_code(400); 
    $response['message'] = 'Invalid JSON input. Required fields: itemId, containerId, position{startCoordinates{width, depth, height}}';
    error_log("Place API: Invalid JSON structure received: " . $rawData);
    echo json_encode($response);
    $db = null; exit;
}

// Extract data
$itemId = trim($requestData['itemId']);
$containerId = trim($requestData['containerId']);
$posW = $requestData['position']['startCoordinates']['width'];
$posD = $requestData['position']['startCoordinates']['depth'];
$posH = $requestData['position']['startCoordinates']['height'];
$userId = $requestData['userId'] ?? 'Unknown'; 
$timestamp = $requestData['timestamp'] ?? date('c'); 

// Validate Input Data Types and Values
if (empty($itemId)) { http_response_code(400); $response['message'] = '"itemId" cannot be empty.'; error_log("Place API: Empty itemId received."); echo json_encode($response); $db = null; exit; }
if (empty($containerId)) { http_response_code(400); $response['message'] = '"containerId" cannot be empty.'; error_log("Place API: Empty containerId received."); echo json_encode($response); $db = null; exit; }
if (!is_numeric($posW) || !is_numeric($posD) || !is_numeric($posH) || $posW < 0 || $posD < 0 || $posH < 0) {
     http_response_code(400); 
     $response['message'] = 'Invalid position coordinates. Width, depth, and height must be non-negative numbers.';
     error_log("Place API: Invalid coordinates W:$posW, D:$posD, H:$posH for item $itemId");
     echo json_encode($response); $db = null; exit;
}
// Cast coordinates to float after validation
$posW = (float)$posW; $posD = (float)$posD; $posH = (float)$posH;

error_log("Place API: Request for itemId: $itemId, Container: $containerId, Pos:($posW,$posD,$posH), User: $userId, Timestamp: $timestamp");
    
// --- Core Logic ---
$db->beginTransaction(); 
try {
    // 1. Check if Item exists and get its current dimensions (as potentially rotated)
    $itemCheckSql = "SELECT itemId, width, depth, height FROM items WHERE itemId = :itemId";
    $itemStmt = $db->prepare($itemCheckSql);
    $itemStmt->bindParam(':itemId', $itemId);
    $itemStmt->execute();
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) { throw new Exception("Item with ID '$itemId' not found.", 404); }
    
    $itemW = (float)$item['width']; $itemD = (float)$item['depth']; $itemH = (float)$item['height'];
    error_log("Place API: Found item $itemId with dimensions W:$itemW, D:$itemD, H:$itemH");


    // 2. Check if Container exists and get its dimensions
    $contCheckSql = "SELECT containerId, width, depth, height FROM containers WHERE containerId = :containerId";
    $contStmt = $db->prepare($contCheckSql);
    $contStmt->bindParam(':containerId', $containerId);
    $contStmt->execute();
    $container = $contStmt->fetch(PDO::FETCH_ASSOC);
    if (!$container) { throw new Exception("Container with ID '$containerId' not found.", 404); }
    
    $containerW = (float)$container['width']; $containerD = (float)$container['depth']; $containerH = (float)$container['height'];


    // 3. Validate if the item FITS at the specified coordinates within the container bounds
    if (($posW + $itemW > $containerW) || ($posD + $itemD > $containerD) || ($posH + $itemH > $containerH)) {
        throw new Exception("Item '$itemId' (Dims: {$itemW}x{$itemD}x{$itemH}) does not fit at position ($posW, $posD, $posH) in container '$containerId' (Dims: {$containerW}x{$containerD}x{$containerH}).", 400);
    }
    error_log("Place API: Item $itemId fits bounds at specified location in $containerId.");

    // 4. Collision Check with OTHER items in the target container
    $otherItems = fetchOtherItemsInContainer($db, $containerId, $itemId); // Use helper function
    $newItemBox = ['x' => $posW, 'y' => $posD, 'z' => $posH, 'w' => $itemW, 'd' => $itemD, 'h' => $itemH];
    error_log("Place API: Checking for collisions with " . count($otherItems) . " other items in container $containerId.");
    foreach ($otherItems as $otherItem) {
        if (boxesOverlap($newItemBox, $otherItem)) {
             // Collision detected!
             throw new Exception("Placement failed. Collision detected with item '" . $otherItem['id'] . "' at proposed location for item '$itemId' in container '$containerId'.", 409); // 409 Conflict
        }
    }
    error_log("Place API: No collisions detected for item $itemId in container $containerId.");


    // 5. Update Item's Location and Status in DB
    // Note: We DO NOT change the item's dimensions here, only its location.
    // The dimensions reflect its state (potentially rotated) from the last placement.
    $updateSql = "UPDATE items 
                  SET 
                      currentContainerId = :containerId, 
                      pos_w = :pos_w, 
                      pos_d = :pos_d, 
                      pos_h = :pos_h,
                      status = 'stowed' -- Make sure status is stowed
                  WHERE itemId = :itemId";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->bindParam(':containerId', $containerId);
    $updateStmt->bindParam(':pos_w', $posW);
    $updateStmt->bindParam(':pos_d', $posD);
    $updateStmt->bindParam(':pos_h', $posH);
    $updateStmt->bindParam(':itemId', $itemId);

    if (!$updateStmt->execute()) {
         $errorInfo = $updateStmt->errorInfo();
         error_log("Place API: Failed DB update for $itemId. Error Code: " . ($errorInfo[1] ?? 'N/A') . " Msg: " . ($errorInfo[2] ?? 'N/A'));
         throw new Exception("Database update failed for item placement."); // Triggers rollback
    }
    error_log("Place API: Successfully updated location for item $itemId in DB.");

    // 6. Log the Placement Action
    $logSql = "INSERT INTO logs (userId, actionType, itemId, detailsJson, timestamp) VALUES (:userId, 'placement', :itemId, :details, :timestamp)";
    $logStmt = $db->prepare($logSql);
    $detailsArray = [
        'placedBy' => $userId,
        'containerId' => $containerId,
        'position' => ['width' => $posW, 'depth' => $posD, 'height' => $posH],
        'itemDimensionsUsed' => ['width' => $itemW, 'depth' => $itemD, 'height' => $itemH] // Log dims used for placement check
    ];
    $detailsJson = json_encode($detailsArray);
    
    $logStmt->bindParam(':userId', $userId);
    $logStmt->bindParam(':itemId', $itemId);
    $logStmt->bindParam(':details', $detailsJson);
    $logStmt->bindParam(':timestamp', $timestamp); 

    if (!$logStmt->execute()) {
        error_log("Place API Error: Failed to insert placement log for itemId: $itemId. Error: " . print_r($logStmt->errorInfo(), true));
        // Continue even if logging fails for now
    } else {
        error_log("Place API: Successfully logged placement for $itemId by user $userId.");
    }

    // Commit transaction
    $db->commit();
    $response['success'] = true;
    $response['message'] = "Item '$itemId' successfully placed in container '$containerId'.";
    http_response_code(200);

} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); } // Rollback on any error
    // Set HTTP status code based on exception code if available, otherwise 500
    $statusCode = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($statusCode);
    $response['message'] = 'Error processing placement: ' . $e->getMessage();
    error_log("Place API Exception (Code: {$e->getCode()}): " . $e->getMessage());
} finally {
    $db = null; 
}
// --- End Core Logic ---


// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT); 
error_log("Place API: Finished.");


// --- HELPER FUNCTIONS ---

/**
 * Fetches details of other placed items within a specific container.
 * Used for collision checking.
 * 
 * @param PDO $db Database connection object
 * @param string $containerId The ID of the container to check
 * @param string $excludeItemId The ID of the item being placed (to exclude from results)
 * @return array Array of other items, each with keys 'id', 'x', 'y', 'z', 'w', 'd', 'h'
 */
function fetchOtherItemsInContainer(PDO $db, string $containerId, string $excludeItemId): array {
    $sql = "SELECT itemId, pos_w, pos_d, pos_h, width, depth, height 
            FROM items 
            WHERE currentContainerId = :containerId 
            AND itemId != :excludeItemId 
            AND pos_w IS NOT NULL AND pos_d IS NOT NULL AND pos_h IS NOT NULL"; // Ensure they have valid positions
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':containerId', $containerId);
    $stmt->bindParam(':excludeItemId', $excludeItemId);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format for consistency with collision check
    $otherItems = [];
    foreach ($results as $row) {
         $otherItems[] = [
             'id' => $row['itemId'],
             'x' => (float)$row['pos_w'], 'y' => (float)$row['pos_d'], 'z' => (float)$row['pos_h'], 
             'w' => (float)$row['width'], 'd' => (float)$row['depth'], 'h' => (float)$row['height'] 
         ];
    }
    return $otherItems;
}


/**
 * Check if two 3D axis-aligned bounding boxes (AABB) overlap.
 * Uses separating axis theorem concept (no gap = overlap).
 * 
 * @param array $box1 Associative array with 'x', 'y', 'z', 'w', 'd', 'h'
 * @param array $box2 Associative array with 'x', 'y', 'z', 'w', 'd', 'h'
 * @return bool True if boxes overlap, false otherwise
 */
function boxesOverlap(array $box1, array $box2): bool {
    // Ensure all keys exist to avoid warnings
    $keys = ['x', 'y', 'z', 'w', 'd', 'h'];
    foreach ($keys as $key) {
        if (!isset($box1[$key]) || !isset($box2[$key])) {
             error_log("boxesOverlap Warning: Missing key '$key' in input arrays.");
             return true; // Treat missing data as potential overlap to be safe
        }
    }

    $noOverlapX = ($box1['x'] >= $box2['x'] + $box2['w']) || ($box2['x'] >= $box1['x'] + $box1['w']);
    $noOverlapY = ($box1['y'] >= $box2['y'] + $box2['d']) || ($box2['y'] >= $box1['y'] + $box1['d']);
    $noOverlapZ = ($box1['z'] >= $box2['z'] + $box2['h']) || ($box2['z'] >= $box1['z'] + $box1['h']);
    
    return !($noOverlapX || $noOverlapY || $noOverlapZ);
}

?> 