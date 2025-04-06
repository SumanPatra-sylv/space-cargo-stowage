<?php // backend/waste/return_plan.php (Modified for central index.php handling)

/* --- REMOVED/COMMENTED OUT - Handled by index.php --- */


// --- Database Connection ---
require_once __DIR__ . '/../database.php'; // Correct path
$db = null; // Initialize $db
error_log("Return Plan API: Attempting DB connection...");

try {
    $db = getDbConnection();
    if ($db === null) {
        error_log("Return Plan API: Failed DB connection.");
        http_response_code(503); // Service Unavailable
        echo json_encode(['success' => false, 'message' => 'Database connection failed.', 'returnPlan' => [], 'retrievalSteps' => [], 'returnManifest' => null, 'errors' => []]);
        exit;
    }
    error_log("Return Plan API: DB connection successful.");
} catch (Exception $e) {
     error_log("Return Plan API: DB connection exception - " . $e->getMessage());
     http_response_code(503); // Service Unavailable
     echo json_encode(['success' => false, 'message' => 'Database connection error.', 'returnPlan' => [], 'retrievalSteps' => [], 'returnManifest' => null, 'errors' => []]);
     exit;
}
// --- End Database Connection ---


// --- Response Structure ---
$response = [
    'success' => false,
    'returnPlan' => [],
    'retrievalSteps' => [],
    'returnManifest' => null,
    'message' => '',
    'errors' => []
];


// --- Handle Input ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    error_log("Return Plan Error: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null; exit;
}

$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true);

// Validate required fields
$requiredFields = ['undockingContainerId', 'undockingDate', 'maxWeight'];
foreach ($requiredFields as $field) {
    if (!isset($requestData[$field])) {
        http_response_code(400); // Bad Request
        $response['message'] = "Invalid JSON input. Missing required field: '$field'.";
        error_log("Return Plan Error: Missing required field '$field'. Input: " . $rawData);
        echo json_encode($response);
        $db = null; exit;
    }
}

$undockingContainerId = trim($requestData['undockingContainerId']);
$undockingDate = trim($requestData['undockingDate']); // Basic validation below
$maxWeight = $requestData['maxWeight'];
$userId = $requestData['userId'] ?? 'System_ReturnPlan'; // Optional user for context

// Validate values
if (empty($undockingContainerId) || empty($undockingDate) || !is_numeric($maxWeight) || $maxWeight < 0) {
     http_response_code(400); // Bad Request
     $response['message'] = 'Invalid input values. undockingContainerId/Date cannot be empty, maxWeight must be a non-negative number.';
     error_log("Return Plan Error: Invalid input values. ID:'$undockingContainerId', Date:'$undockingDate', Weight:'$maxWeight'");
     echo json_encode($response);
     $db = null; exit;
}
// Basic date format check (YYYY-MM-DD) - Adjust regex if needed
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $undockingDate)) {
    http_response_code(400);
    $response['message'] = 'Invalid input value: undockingDate must be in YYYY-MM-DD format.';
    error_log("Return Plan Error: Invalid date format: '$undockingDate'");
    echo json_encode($response);
    $db = null; exit;
}

$maxWeight = (float)$maxWeight;
error_log("Return Plan request received. Undocking Container: $undockingContainerId, Date: $undockingDate, Max Weight: $maxWeight, User: $userId");
// --- End Input Handling ---


try { // Wrap main logic in try block

    // --- Identify Waste Items (Placed Only) ---
    // The $sqlWaste query below matches the requirements, no changes needed here.
    $sqlWaste = "SELECT
                    i.itemId, i.name, i.mass, i.priority,
                    i.containerId AS currentContainerId,
                    i.positionX AS dbPosX, i.positionY AS dbPosY, i.positionZ AS dbPosZ,
                    i.placedDimensionW AS dbPlacedW, i.placedDimensionD AS dbPlacedD, i.placedDimensionH AS dbPlacedH,
                    i.status, i.usageLimit, i.remainingUses, i.expiryDate,
                    CASE
                        WHEN i.status = 'waste_expired' THEN 'Expired'
                        WHEN i.status = 'waste_depleted' THEN 'Out of Uses'
                        WHEN i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now', 'localtime') THEN 'Expired'
                        WHEN i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0 THEN 'Out of Uses'
                        ELSE 'Unknown Waste State'
                    END as reason
                FROM items i
                WHERE
                    ( i.status IN ('waste_expired', 'waste_depleted')
                      OR
                      (i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now', 'localtime'))
                      OR
                      (i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0)
                    )
                    AND i.containerId IS NOT NULL -- Must be in a container
                    AND i.positionX IS NOT NULL"; // Check one position coord implies placed

    $stmtWaste = $db->prepare($sqlWaste);
    $stmtWaste->execute();
    // *** ADDED LOGGING LINE 1 ***
    error_log("Return Plan Query executed. Row count: " . $stmtWaste->rowCount());

    $allWasteItems = $stmtWaste->fetchAll(PDO::FETCH_ASSOC);
    // *** ADDED LOGGING LINE 2 ***
    error_log("Return Plan Fetched Data (" . count($allWasteItems) . " rows): " . json_encode($allWasteItems));

    // Existing log line (kept for context, slightly redundant now but harmless)
    error_log("Return Plan: Found " . count($allWasteItems) . " total placed waste items.");


    if (empty($allWasteItems)) {
        $response['success'] = true;
        $response['message'] = "No placed waste items found to generate a return plan.";
        $response['returnManifest'] = ['undockingContainerId'=>$undockingContainerId, 'undockingDate'=>$undockingDate, 'returnItems'=>[], 'totalVolume'=>0, 'totalWeight'=>0];
        http_response_code(200);
        echo json_encode($response);
        $db = null; exit;
    }
    // --- End Identify Waste ---


    // --- Select Items for Return (Greedy: lowest priority, then earliest expiry, within weight limit) ---
    usort($allWasteItems, function($a, $b) {
        $prioA = $a['priority'] ?? 50; // Default priority if null
        $prioB = $b['priority'] ?? 50;
        if ($prioA !== $prioB) { return $prioA <=> $prioB; } // Lower number = lower priority = return first

        $expiryA = $a['expiryDate'] ?? '9999-12-31'; // Treat null expiry as far future
        $expiryB = $b['expiryDate'] ?? '9999-12-31';
        return strcmp($expiryA, $expiryB); // Earlier date first
    });
    error_log("Return Plan: Sorted waste items by priority asc, then expiry asc.");

    $selectedWasteItems = [];
    $currentWeight = 0.0;
    $currentVolume = 0.0;
    $epsilon = 0.001; // Tolerance for float comparison

    foreach ($allWasteItems as $item) {
        $itemMass = (float)($item['mass'] ?? 0);
        if (($currentWeight + $itemMass) <= ($maxWeight + $epsilon)) {
            // Check if placement dimensions are available to calculate volume
            if (isset($item['dbPlacedW'], $item['dbPlacedD'], $item['dbPlacedH'])) {
                $selectedWasteItems[] = $item; // Select the item
                $currentWeight += $itemMass;
                $itemVolume = (float)$item['dbPlacedW'] * (float)$item['dbPlacedD'] * (float)$item['dbPlacedH'];
                $currentVolume += $itemVolume;
                error_log("Return Plan: Selected item " . $item['itemId'] . " (Mass: $itemMass, Prio: " . ($item['priority']??'N/A') . "). Current Weight: $currentWeight / $maxWeight");
            } else {
                 error_log("Return Plan: Skipping item " . $item['itemId'] . " due to missing placed dimension data needed for volume calculation.");
                 $response['errors'][] = ['itemId' => $item['itemId'], 'message' => 'Skipped from plan: Missing dimension data.'];
            }
        } else {
             error_log("Return Plan: Skipping item " . $item['itemId'] . " (Mass: $itemMass) - exceeds max weight $maxWeight.");
             // Optionally add to errors if you want to notify user about items skipped due to weight
             // $response['errors'][] = ['itemId' => $item['itemId'], 'message' => 'Skipped from plan: Exceeds weight limit.'];
        }
    }
    error_log("Return Plan: Selected " . count($selectedWasteItems) . " items. Total Weight: $currentWeight, Total Volume: $currentVolume");
    // --- End Selection ---


    // --- Fetch All Locations for Obstruction Check ---
    $allItemsByContainer = []; // Map: containerId -> itemId -> {id, name, x, y, z, w, d, h}
    try {
        $sqlAll = "SELECT itemId, name, containerId,
                          positionX, positionY, positionZ,
                          placedDimensionW, placedDimensionD, placedDimensionH
                   FROM items
                   WHERE containerId IS NOT NULL AND positionX IS NOT NULL"; // Placed items only
        $stmtAll = $db->prepare($sqlAll);
        $stmtAll->execute();
        $allItemsResult = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allItemsResult as $item) {
             $containerId = $item['containerId'];
             if (!isset($allItemsByContainer[$containerId])) { $allItemsByContainer[$containerId] = []; }

             // Check if all necessary fields exist before adding
             if (isset($item['itemId'], $item['name'], $item['positionX'], $item['positionY'], $item['positionZ'],
                       $item['placedDimensionW'], $item['placedDimensionD'], $item['placedDimensionH']))
             {
                 // Store using internal consistent keys for overlap check
                 $allItemsByContainer[$containerId][$item['itemId']] = [
                     'id' => $item['itemId'], 'name' => $item['name'],
                     'x' => (float)$item['positionX'], 'y' => (float)$item['positionY'], 'z' => (float)$item['positionZ'],
                     'w' => (float)$item['placedDimensionW'], 'd' => (float)$item['placedDimensionD'], 'h' => (float)$item['placedDimensionH']
                 ];
             } else {
                  error_log("Return Plan Warning: Missing location/dimension data for item {$item['itemId']} during obstruction map build.");
             }
        }
         error_log("Return Plan: Built location map for " . count($allItemsResult) . " items across " . count($allItemsByContainer) . " containers.");
    } catch (PDOException $e) {
         throw new Exception("Error fetching item locations for retrieval planning: " . $e->getMessage());
    }


    // --- Generate Retrieval Steps (Including Position) ---
    $combinedRetrievalSteps = [];
    $masterStepCounter = 0;

    foreach ($selectedWasteItems as $itemToRetrieve) {
        $targetItemId = $itemToRetrieve['itemId'];
        $containerId = $itemToRetrieve['currentContainerId']; // Container of the waste item

        // Get target item's box data from the pre-fetched map
        $targetItemBox = $allItemsByContainer[$containerId][$targetItemId] ?? null;

        if ($targetItemBox === null) {
            error_log("Return Plan Warning: Could not find location data for selected waste item $targetItemId in $containerId during retrieval step generation.");
            $response['errors'][] = ['itemId' => $targetItemId, 'message' => 'Internal error generating retrieval steps (missing location map entry).'];
            continue; // Skip steps for this item
        }
        error_log("Return Plan: Calculating retrieval steps for $targetItemId from $containerId");

        // --- Find Obstructions for $targetItemBox ---
        $obstructionItemsData = []; // Store { boxData, itemId, itemName }
        $itemsInSameContainer = $allItemsByContainer[$containerId] ?? [];

        foreach ($itemsInSameContainer as $otherItemId => $otherItemBox) {
             if ($targetItemId === $otherItemId) continue; // Skip self

             // Check if 'other' is IN FRONT of 'target' (higher X)
             if ($otherItemBox['x'] > ($targetItemBox['x'] + $epsilon)) { // Use tolerance
                 // Now check for Y/Z overlap using helper function
                 if (doBoxesOverlapYZ($targetItemBox, $otherItemBox)) {
                      error_log("Return Plan: --> Item $targetItemId is obstructed by $otherItemId");
                      $obstructionItemsData[] = [ // Store full box data for later use
                          'boxData' => $otherItemBox,
                          'itemId' => $otherItemId,
                          'itemName' => $otherItemBox['name']
                      ];
                 }
             }
        }
        // Sort obstructions by X coordinate descending (remove front-most first)
        usort($obstructionItemsData, fn($a, $b) => $b['boxData']['x'] <=> $a['boxData']['x']);

        // --- Build Steps for this Item ---
        // 1. Remove Obstructions
        foreach ($obstructionItemsData as $obstructor) {
             $masterStepCounter++;
             $combinedRetrievalSteps[] = [
                 'step' => $masterStepCounter,
                 'action' => 'remove',
                 'itemId' => $obstructor['itemId'],
                 'itemName' => $obstructor['itemName'],
                 'containerId' => $containerId, // Obstructor is in the same container
                 'position' => formatPositionForApi($obstructor['boxData']) // Format position
             ];
             error_log("Adding step $masterStepCounter: remove " . $obstructor['itemId']);
        }

        // 2. Retrieve Target Waste Item
        $masterStepCounter++;
        $combinedRetrievalSteps[] = [
            'step' => $masterStepCounter,
            'action' => 'retrieve',
            'itemId' => $targetItemId,
            'itemName' => $itemToRetrieve['name'],
            'containerId' => $containerId,
            'position' => formatPositionForApi($targetItemBox) // Format position
        ];
         error_log("Adding step $masterStepCounter: retrieve $targetItemId (for return plan)");

        // 3. Place Back Obstructions (in reverse order of removal)
        foreach (array_reverse($obstructionItemsData) as $obstructor) {
             $masterStepCounter++;
             // Note: 'placeBack' might require knowing the *original* position if complex moves are needed.
             // For simplicity, we just list the item to place back. Actual placement logic isn't determined here.
             $combinedRetrievalSteps[] = [
                 'step' => $masterStepCounter,
                 'action' => 'placeBack', // User needs to figure out where to put it temporarily/permanently
                 'itemId' => $obstructor['itemId'],
                 'itemName' => $obstructor['itemName'],
                 'containerId' => $containerId,
                 'position' => formatPositionForApi($obstructor['boxData']) // Provide original position as context
             ];
             error_log("Adding step $masterStepCounter: placeBack " . $obstructor['itemId']);
        }
    } // End foreach selectedWasteItem
    // --- End Generate Retrieval Steps ---


    // --- Generate Return Plan & Manifest ---
    $returnPlanSteps = [];
    $manifestItems = [];
    $planStepCounter = 0;

    foreach ($selectedWasteItems as $item) {
        $planStepCounter++;
        $returnPlanSteps[] = [
            'step' => $planStepCounter,
            'itemId' => $item['itemId'],
            'itemName' => $item['name'],
            'fromContainer' => $item['currentContainerId'],
            'toContainer' => $undockingContainerId // Target undocking container
        ];
        $manifestItems[] = [
            'itemId' => $item['itemId'],
            'name' => $item['name'],
            'reason' => $item['reason'] // Reason for being waste
        ];
    }

    $response['returnPlan'] = $returnPlanSteps;
    $response['returnManifest'] = [
        'undockingContainerId' => $undockingContainerId,
        'undockingDate' => $undockingDate,
        'returnItems' => $manifestItems,
        'totalVolume' => round($currentVolume, 3), // Round for display
        'totalWeight' => round($currentWeight, 3)  // Round for display
    ];
    // --- End Plan & Manifest ---


    // --- Finalize Success Response ---
    $response['success'] = true;
    $response['retrievalSteps'] = $combinedRetrievalSteps; // Add the detailed steps

    if (empty($selectedWasteItems)) {
         $response['message'] = "No waste items could be selected within the specified weight limit.";
    } elseif (count($selectedWasteItems) < count($allWasteItems)) {
         $response['message'] = "Return plan generated for " . count($selectedWasteItems) . " waste items (limited by weight or missing data).";
    } else {
         $response['message'] = "Return plan generated for all " . count($selectedWasteItems) . " located and dimensioned waste items.";
    }
    if (!empty($response['errors'])) {
         $response['message'] .= " Some items were skipped or encountered errors during planning (see 'errors' array).";
    }

    http_response_code(200); // OK
    // --- End Finalize ---

} catch (Exception $e) { // Catch general exceptions or PDOExceptions
    http_response_code(500); // Use 500 for server-side processing errors
    $response['success'] = false; // Ensure failure
    $response['message'] = 'Error generating return plan: ' . $e->getMessage();
    error_log("Return Plan Exception: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    // Clear potentially partially filled arrays on error
    $response['returnPlan'] = []; $response['retrievalSteps'] = []; $response['returnManifest'] = null;
    // Add specific error details if not already set
    if (empty($response['errors'])) {
        $response['errors'] = [['message' => $e->getMessage()]];
    }
    // Ensure JSON is output even on error before exit
    if (!headers_sent()) {
        echo json_encode($response);
    }
    $db = null; exit; // Exit on error

} finally {
    $db = null; // Close connection
}


// --- Final Output ---
if (!headers_sent()) {
    // Removed JSON_PRETTY_PRINT for production
    echo json_encode($response);
}
error_log("Return Plan API: Finished.");


// --- HELPER FUNCTIONS ---

/**
 * Checks if two boxes overlap in the Y and Z dimensions.
 * Assumes input arrays have keys 'x', 'y', 'z', 'w', 'd', 'h'.
 * Y = Depth, Z = Height.
 */
function doBoxesOverlapYZ(array $box1, array $box2): bool {
    $keys = ['y', 'z', 'd', 'h']; // Only need these dimensions/positions for YZ check
    foreach ($keys as $key) {
        // Check both boxes have the required keys
        if (!isset($box1[$key]) || !isset($box2[$key])) {
            error_log("doBoxesOverlapYZ Warning: Missing key '$key'. Box1: ".json_encode($box1)." Box2: ".json_encode($box2));
            return false; // Cannot determine overlap if data is missing
        }
    }

    $epsilon = 0.001; // Tolerance

    // Check for Y overlap (Depth)
    $yOverlap = ($box1['y'] < $box2['y'] + $box2['d'] - $epsilon) && ($box1['y'] + $box1['d'] > $box2['y'] + $epsilon);

    // Check for Z overlap (Height)
    $zOverlap = ($box1['z'] < $box2['z'] + $box2['h'] - $epsilon) && ($box1['z'] + $box1['h'] > $box2['z'] + $epsilon);

    return $yOverlap && $zOverlap;
}

/**
 * Formats position data from internal {x,y,z, w,d,h} to API structure.
 * Maps internal x,y,z to API width, depth, height axes for coordinates.
 */
function formatPositionForApi(array $internalBoxData): array {
     // Map internal keys (x,y,z, w,d,h) to API coordinate names (width, depth, height)
     $startW = $internalBoxData['x']; // Start Width = internal x
     $startD = $internalBoxData['y']; // Start Depth = internal y
     $startH = $internalBoxData['z']; // Start Height = internal z
     $placedW = $internalBoxData['w']; // Placed Width = internal w
     $placedD = $internalBoxData['d']; // Placed Depth = internal d
     $placedH = $internalBoxData['h']; // Placed Height = internal h

     return [
         'startCoordinates' => ['width' => $startW, 'depth' => $startD, 'height' => $startH],
         'endCoordinates' => ['width' => $startW + $placedW, 'depth' => $startD + $placedD, 'height' => $startH + $placedH]
     ];
}


?>