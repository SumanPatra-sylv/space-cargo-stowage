<?php // backend/waste/identify.php (Modified for central index.php handling)

/* --- REMOVED/COMMENTED OUT - Handled by index.php --- */

// --- Database Connection ---
// Correct path: Up one level from 'waste' to 'backend'
require_once __DIR__ . '/../database.php';
$db = null; // Initialize $db
error_log("Waste Identify API: Attempting DB connection...");

try {
    $db = getDbConnection();
    if ($db === null) {
        error_log("Waste Identify API: Failed DB connection.");
        http_response_code(503); // Service Unavailable is appropriate
        // index.php already set Content-Type
        echo json_encode(['success' => false, 'wasteItems' => [], 'message' => 'Database connection failed.']);
        exit; // Exit on critical failure
    }
    error_log("Waste Identify API: DB connection successful.");
} catch (Exception $e) {
     error_log("Waste Identify API: DB connection exception - " . $e->getMessage());
     http_response_code(503); // Service Unavailable
     // index.php already set Content-Type
     echo json_encode(['success' => false, 'wasteItems' => [], 'message' => 'Database connection error.']);
     exit; // Exit on critical failure
}
// --- End Database Connection ---

// --- Response Structure ---
$response = [
    'success' => false,
    'wasteItems' => [], // Array to hold identified waste items
    'message' => ''
];

// --- Handle GET Request ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    error_log("Waste Identify API: Received method " . $_SERVER['REQUEST_METHOD']);
    // index.php already set Content-Type
    echo json_encode($response);
    $db = null; // Close connection
    exit; // Exit on wrong method
}
error_log("Waste Identify API: Request received.");
// --- End Request Handling ---

// --- Core Logic ---
try {
    // Query for items that are waste
    // 1. Already marked as waste ('waste_expired', 'waste_depleted')
    // 2. Expired (stowed and expiry date is past today)
    // 3. Depleted (stowed and usage limit exists and remaining uses <= 0)
    $sql = "SELECT
                i.itemId, i.name, i.status, i.expiryDate, i.remainingUses, i.usageLimit,
                i.containerId AS currentContainerId,
                i.positionX AS dbPosX, i.positionY AS dbPosY, i.positionZ AS dbPosZ, -- Use clearer DB aliases
                i.placedDimensionW AS dbPlacedW, i.placedDimensionD AS dbPlacedD, i.placedDimensionH AS dbPlacedH, -- Use clearer DB aliases
                -- c.zone as containerZone, -- Join uncommented if zone needed in response
                CASE
                    WHEN i.status = 'waste_expired' THEN 'Expired'
                    WHEN i.status = 'waste_depleted' THEN 'Out of Uses'
                    WHEN i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now', 'localtime') THEN 'Expired'
                    WHEN i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0 THEN 'Out of Uses'
                    ELSE 'Unknown Waste State' -- Should ideally not happen if WHERE clause is correct
                END as reason
            FROM items i
            -- LEFT JOIN containers c ON i.containerId = c.containerId -- Join uncommented if zone needed
            WHERE
                i.status IN ('waste_expired', 'waste_depleted')
                OR
                (i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now', 'localtime'))
                OR
                (i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0)";

    $stmt = $db->prepare($sql);
    $stmt->execute();

    $wasteItemsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Waste Identify API: Found " . count($wasteItemsResult) . " potential waste items.");

    // Format the response according to API spec
    foreach ($wasteItemsResult as $item) {
         $positionData = null;
         // Check if all necessary DB position and dimension fields are present and not null
         if (isset($item['currentContainerId'], $item['dbPosX'], $item['dbPosY'], $item['dbPosZ'],
                   $item['dbPlacedW'], $item['dbPlacedD'], $item['dbPlacedH']))
         {
              // Map DB X,Y,Z to API Width,Depth,Height for position
              $startW = (float)$item['dbPosX']; // Start Width = DB X
              $startD = (float)$item['dbPosY']; // Start Depth = DB Y
              $startH = (float)$item['dbPosZ']; // Start Height = DB Z
              // Map DB Placed W,D,H to API Width,Depth,Height for dimensions
              $placedW = (float)$item['dbPlacedW']; // Placed Width
              $placedD = (float)$item['dbPlacedD']; // Placed Depth
              $placedH = (float)$item['dbPlacedH']; // Placed Height

              $positionData = [
                 'startCoordinates' => ['width' => $startW, 'depth' => $startD, 'height' => $startH],
                 'endCoordinates' => ['width' => $startW + $placedW, 'depth' => $startD + $placedD, 'height' => $startH + $placedH]
              ];
         } else {
             // Only log if item status suggests it *should* have placement data
             if (!in_array($item['status'], ['available', 'disposed', null])) {
                 error_log("Waste Identify API: Item {$item['itemId']} (Status: {$item['status']}) is identified as waste but missing expected placement/dimension data.");
             }
         }

         $response['wasteItems'][] = [
             'itemId' => $item['itemId'],
             'name' => $item['name'],
             'reason' => $item['reason'],
             'containerId' => $item['currentContainerId'], // Keep null if not set
             'position' => $positionData // Keep null if not calculable
             // Add 'zone': $item['containerZone'] here if needed
         ];
    }

    $response['success'] = true;
    if (empty($response['wasteItems'])) {
        $response['message'] = "No waste items identified.";
    } else {
        $response['message'] = "Identified " . count($response['wasteItems']) . " waste items.";
    }
    http_response_code(200); // OK

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    $response['success'] = false; // Ensure failure
    $response['message'] = 'Database error while identifying waste items.'; // Generic message for user
    error_log("Waste Identify DB Error: " . $e->getMessage());
    // Ensure JSON is output even on error before exit
    if (!headers_sent()) {
        echo json_encode($response);
    }
    $db = null;
    exit; // Exit on error
} finally {
    // Ensure connection is closed if script didn't exit earlier
    $db = null;
}
// --- End Core Logic ---

// --- Final Output ---
// Output JSON response if successful (errors handled in catch block)
if (!headers_sent()) {
    // Removed JSON_PRETTY_PRINT for production
    echo json_encode($response);
}
error_log("Waste Identify API: Finished.");

?>