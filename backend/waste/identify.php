<?php // backend/waste/identify.php (Corrected V2 - Uses 'expired' and 'consumed' statuses)

/**
 * Identifies items considered "waste" based on their status or current state.
 * Waste includes items explicitly marked as 'expired' or 'consumed',
 * or items currently 'stowed' that are past their expiry date or have no uses left.
 *
 * Responds with a JSON object containing a list of identified waste items.
 */

// --- Database Connection ---
require_once __DIR__ . '/../database.php'; // Defines getDbConnection()
$db = null;
error_log("Waste Identify API: Attempting DB connection...");

try {
    $db = getDbConnection();
    if ($db === null) {
        // If connection fails here, we can't proceed.
        error_log("Waste Identify API: Failed DB connection.");
        http_response_code(503); // Service Unavailable
        // Assuming index.php or a wrapper handles basic headers
        echo json_encode(['success' => false, 'wasteItems' => [], 'message' => 'Database connection failed.']);
        exit;
    }
    error_log("Waste Identify API: DB connection successful.");
} catch (Exception $e) {
     // Catch any other potential exceptions during connection setup
     error_log("Waste Identify API: DB connection exception - " . $e->getMessage());
     http_response_code(503);
     echo json_encode(['success' => false, 'wasteItems' => [], 'message' => 'Database connection error.']);
     exit;
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
    echo json_encode($response);
    $db = null; // Close connection if open
    exit;
}
error_log("Waste Identify API: Received GET request.");
// --- End Request Handling ---

// --- Core Logic ---
try {
    // Query for items that are considered waste using the NEW statuses ('expired', 'consumed')
    // Includes:
    // 1. Items already marked with 'expired' or 'consumed' status.
    // 2. Items currently 'stowed' but past their expiry date (real-time check).
    // 3. Items currently 'stowed' with limited uses and remaining uses are zero (real-time check).

    // *** MODIFIED SQL QUERY to use 'expired' and 'consumed' statuses ***
    $sql = "SELECT
                i.itemId, i.name, i.status, i.expiryDate, i.remainingUses, i.usageLimit,
                i.containerId AS currentContainerId,
                i.positionX AS dbPosX, i.positionY AS dbPosY, i.positionZ AS dbPosZ,
                i.placedDimensionW AS dbPlacedW, i.placedDimensionD AS dbPlacedD, i.placedDimensionH AS dbPlacedH,
                -- c.zone as containerZone, -- Uncomment LEFT JOIN below if zone is needed
                CASE
                    WHEN i.status = 'expired' THEN 'Expired'                 -- Check NEW status
                    WHEN i.status = 'consumed' THEN 'Consumed'               -- Check NEW status
                    -- Fallback checks for items still marked 'stowed' but meeting criteria now
                    WHEN i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now', 'localtime') THEN 'Expired'
                    WHEN i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0 THEN 'Consumed'
                    ELSE i.status -- If it's some other status like 'disposed', show that.
                END as reason
            FROM items i
            -- LEFT JOIN containers c ON i.containerId = c.containerId -- Uncomment if zone needed
            WHERE
                -- Check for items already marked by simulation/other processes with NEW waste statuses
                i.status IN ('expired', 'consumed')
                OR
                -- Check for 'stowed' items that meet waste criteria NOW (e.g., if simulation hasn't run)
                (i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now', 'localtime'))
                OR
                (i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0)";

    $stmt = $db->prepare($sql);
    $stmt->execute();

    $wasteItemsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Waste Identify API: Found " . count($wasteItemsResult) . " potential waste items using updated query (checks 'expired', 'consumed').");

    // Format the response array
    foreach ($wasteItemsResult as $item) {
         $positionData = null;
         // Check if all necessary DB position and dimension fields are present and not null/empty
         // Use isset for existence and check if they are numeric before calculation
         if (isset($item['currentContainerId'], $item['dbPosX'], $item['dbPosY'], $item['dbPosZ'],
                   $item['dbPlacedW'], $item['dbPlacedD'], $item['dbPlacedH']) &&
             is_numeric($item['dbPosX']) && is_numeric($item['dbPosY']) && is_numeric($item['dbPosZ']) &&
             is_numeric($item['dbPlacedW']) && is_numeric($item['dbPlacedD']) && is_numeric($item['dbPlacedH']))
         {
              // Convert DB coordinates/dimensions to API format
              $startW = (float)$item['dbPosX'];
              $startD = (float)$item['dbPosY'];
              $startH = (float)$item['dbPosZ'];
              $placedW = (float)$item['dbPlacedW'];
              $placedD = (float)$item['dbPlacedD'];
              $placedH = (float)$item['dbPlacedH'];

              $positionData = [
                 'startCoordinates' => ['width' => $startW, 'depth' => $startD, 'height' => $startH],
                 'endCoordinates' => ['width' => $startW + $placedW, 'depth' => $startD + $placedD, 'height' => $startH + $placedH]
              ];
         } else {
             // Log if placement data is missing but expected based on status
             // Items marked 'expired' or 'consumed' might still have location data if simulation kept it
             if (in_array($item['status'], ['stowed', 'expired', 'consumed'])) {
                 error_log("Waste Identify API: Item {$item['itemId']} (Status: {$item['status']}) identified as waste but missing/invalid numeric placement/dimension data.");
             }
         }

         // Add the formatted item to the response array
         $response['wasteItems'][] = [
             'itemId' => $item['itemId'],
             'name' => $item['name'],
             'status' => $item['status'], // Include the actual status from DB
             'reason' => $item['reason'], // Reason derived from the CASE statement
             'containerId' => $item['currentContainerId'], // Will be null if not set
             'position' => $positionData // Will be null if not calculable
             // Add 'zone': $item['containerZone'] here if needed and LEFT JOIN uncommented
         ];
    }

    // Set final success state and message
    $response['success'] = true;
    if (empty($response['wasteItems'])) {
        $response['message'] = "No waste items identified matching criteria ('expired', 'consumed', or currently stowed & unusable).";
        error_log("Waste Identify API: " . $response['message']);
    } else {
        $response['message'] = "Identified " . count($response['wasteItems']) . " waste items.";
        error_log("Waste Identify API: " . $response['message']);
    }
    http_response_code(200); // OK

} catch (PDOException $e) {
    // Handle potential database errors during query execution
    http_response_code(500); // Internal Server Error
    $response['success'] = false; // Ensure failure state
    $response['wasteItems'] = []; // Clear any partial results
    $response['message'] = 'Database error while identifying waste items.'; // Generic message for user
    error_log("Waste Identify DB Error: " . $e->getMessage() . " SQL: " . $sql); // Log SQL for debugging
    // Ensure JSON is output even on error before exit
    if (!headers_sent()) {
        echo json_encode($response);
    }
    $db = null;
    exit; // Exit on error
} finally {
    // Ensure connection is closed if script didn't exit earlier
    if ($db !== null) {
        $db = null;
        error_log("Waste Identify API: Database connection closed in finally block.");
    }
}
// --- End Core Logic ---

// --- Final Output ---
// Output JSON response if script reached here successfully (errors handled in catch block)
if (!headers_sent()) {
    // Use pretty print for easier debugging if needed, remove for production bandwidth savings
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
error_log("Waste Identify API: Finished processing request.");

?>