<?php // backend/api/waste/identify.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
// GET request, no OPTIONS needed for basic CORS

// --- Database Connection ---
// Note the path change due to being in a subfolder (api/waste)
require_once __DIR__ . '/../../database.php'; // Go up TWO levels to backend/
error_log("Waste Identify API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) { 
    error_log("Waste Identify API: Failed DB connection.");
    http_response_code(500); 
    echo json_encode(['success' => false, 'wasteItems' => [], 'message' => 'Database connection failed.']);
    exit; 
}
error_log("Waste Identify API: DB connection successful.");
// --- End Database Connection --- 

// --- Response Structure ---
$response = [
    'success' => false, 
    'wasteItems' => [], // Array to hold identified waste items
    'message' => ''
];

// --- Handle GET Request ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    error_log("Waste Identify API: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null; exit; 
}
error_log("Waste Identify API: Request received.");
// --- End Request Handling ---

// --- Core Logic ---
try {
    // Get current date in YYYY-MM-DD format using SQLite's date function for reliable comparison
    // $currentDate = date('Y-m-d'); // PHP date could have timezone issues vs DB

    // Query for items that are waste
    // 1. Already marked as waste ('waste_expired', 'waste_depleted')
    // 2. Expired (stowed and expiry date is past today)
    // 3. Depleted (stowed and usage limit exists and remaining uses <= 0)
    $sql = "SELECT 
                i.itemId, i.name, i.status, i.expiryDate, i.remainingUses, i.usageLimit,
                i.currentContainerId, i.pos_w, i.pos_d, i.pos_h, 
                i.width, i.depth, i.height, -- Include dimensions for calculating end coordinates
                c.zone as containerZone,
                -- Determine reason in SQL
                CASE
                    WHEN i.status = 'waste_expired' THEN 'Expired'
                    WHEN i.status = 'waste_depleted' THEN 'Out of Uses'
                    WHEN i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now') THEN 'Expired' -- Check expiryDate is not null
                    WHEN i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0 THEN 'Out of Uses'
                    ELSE 'Unknown' -- Should ideally not happen
                END as reason
            FROM items i
            LEFT JOIN containers c ON i.currentContainerId = c.containerId
            WHERE 
                (i.status = 'waste_expired' OR i.status = 'waste_depleted')
                OR 
                (i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now')) -- Check expiryDate is not null
                OR
                (i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0)";
                
    $stmt = $db->prepare($sql);
    $stmt->execute();
    
    $wasteItemsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Waste Identify API: Found " . count($wasteItemsResult) . " potential waste items.");

    // Format the response according to API spec
    foreach ($wasteItemsResult as $item) {
         $positionData = null;
         // Only include position if item is actually placed
         if ($item['currentContainerId'] !== null && $item['pos_w'] !== null && $item['width'] !== null) { // Check dimensions too
              $posW = (float)$item['pos_w']; $posD = (float)$item['pos_d']; $posH = (float)$item['pos_h'];
              $itemW = (float)$item['width']; $itemD = (float)$item['depth']; $itemH = (float)$item['height'];
              $positionData = [
                 'startCoordinates' => ['width' => $posW, 'depth' => $posD, 'height' => $posH],
                 'endCoordinates' => ['width' => $posW + $itemW, 'depth' => $posD + $itemD, 'height' => $posH + $itemH]
              ];
         }

         $response['wasteItems'][] = [
             'itemId' => $item['itemId'],
             'name' => $item['name'],
             'reason' => $item['reason'], 
             'containerId' => $item['currentContainerId'], // Can be null
             'position' => $positionData // Can be null
             // Optional: Add 'zone': $item['containerZone'] if needed
         ];
         
         // --- Optional: Auto-update status ---
         // If you want this endpoint to automatically update the status of newly found expired/depleted items
         // NOTE: This adds write operations to a GET request, which is sometimes frowned upon.
         //       Consider doing this in the time simulation endpoint instead.
         /* 
         if ($item['status'] === 'stowed' && ($item['reason'] === 'Expired' || $item['reason'] === 'Out of Uses')) {
             $newStatus = ($item['reason'] === 'Expired') ? 'waste_expired' : 'waste_depleted';
             try {
                 $updateSql = "UPDATE items SET status = :newStatus WHERE itemId = :itemId AND status = 'stowed'"; // Check status again
                 $updateStmt = $db->prepare($updateSql);
                 $updateStmt->bindParam(':newStatus', $newStatus);
                 $updateStmt->bindParam(':itemId', $item['itemId']);
                 if ($updateStmt->execute() && $updateStmt->rowCount() > 0) {
                      error_log("Waste Identify API: Auto-updated status for item " . $item['itemId'] . " to " . $newStatus);
                 } elseif ($updateStmt->rowCount() == 0) {
                     error_log("Waste Identify API: Item " . $item['itemId'] . " status already updated, skipping auto-update.");
                 } else {
                      error_log("Waste Identify API: Failed to auto-update status for item " . $item['itemId'] . ". Error: " . print_r($updateStmt->errorInfo(), true));
                 }
             } catch (PDOException $updateExc) {
                  error_log("Waste Identify API: Exception during auto-update for item " . $item['itemId'] . ": " . $updateExc->getMessage());
             }
         }
         */
         // --- End Optional Auto-update ---
    }

    $response['success'] = true;
    $response['message'] = "Found " . count($response['wasteItems']) . " waste items.";
    http_response_code(200);

} catch (PDOException $e) {
    http_response_code(500);
    $response['message'] = 'Error identifying waste items: ' . $e->getMessage();
    error_log("Waste Identify DB Error: " . $e->getMessage());
} finally {
    $db = null; 
}
// --- End Core Logic ---

// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT); 
error_log("Waste Identify API: Finished.");

?>