<?php // backend/waste/return_plan.php (Corrected V3 - Uses 'expired'/'consumed', preserves original structure)

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


// --- Handle Input (Validation remains the same as your provided code) ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    error_log("Return Plan Error: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    $db = null; exit;
}

$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true);

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
$undockingDate = trim($requestData['undockingDate']);
$maxWeight = $requestData['maxWeight'];
$userId = $requestData['userId'] ?? 'System_ReturnPlan';

if (empty($undockingContainerId) || empty($undockingDate) || !is_numeric($maxWeight) || $maxWeight < 0) {
     http_response_code(400); // Bad Request
     $response['message'] = 'Invalid input values. undockingContainerId/Date cannot be empty, maxWeight must be a non-negative number.';
     error_log("Return Plan Error: Invalid input values. ID:'$undockingContainerId', Date:'$undockingDate', Weight:'$maxWeight'");
     echo json_encode($response);
     $db = null; exit;
}
// Validate date format and existence
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $undockingDate) || !checkdate((int)substr($undockingDate, 5, 2), (int)substr($undockingDate, 8, 2), (int)substr($undockingDate, 0, 4))) {
    http_response_code(400);
    // Corrected potential invalid date in user message: 2025-25-06 is invalid.
    $response['message'] = 'Invalid input value: undockingDate must be a valid date in YYYY-MM-DD format.';
    error_log("Return Plan Error: Invalid or non-existent date: '$undockingDate'");
    echo json_encode($response);
    $db = null; exit;
}

$maxWeight = (float)$maxWeight;
error_log("Return Plan request received. Undocking Container: $undockingContainerId, Date: $undockingDate, Max Weight: $maxWeight, User: $userId");
// --- End Input Handling ---


try { // Wrap main logic in try block

    // --- Identify Waste Items (Placed Only - Using CORRECTED Statuses) ---
    // Query for items that are PLACED (have containerId and placement data) AND meet waste criteria.

    // *** MODIFIED SQL QUERY ***
    $sqlWaste = "SELECT
                    i.itemId, i.name, i.mass, i.priority,
                    i.containerId AS currentContainerId,
                    i.positionX AS dbPosX, i.positionY AS dbPosY, i.positionZ AS dbPosZ,
                    i.placedDimensionW AS dbPlacedW, i.placedDimensionD AS dbPlacedD, i.placedDimensionH AS dbPlacedH,
                    i.status, i.usageLimit, i.remainingUses, i.expiryDate,
                    CASE
                        WHEN i.status = 'expired' THEN 'Expired'         -- USE NEW STATUS
                        WHEN i.status = 'consumed' THEN 'Consumed'       -- USE NEW STATUS
                        -- Fallback checks for 'stowed' items meeting criteria now
                        WHEN i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now', 'localtime') THEN 'Expired'
                        WHEN i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0 THEN 'Consumed' -- Use new term here too
                        ELSE i.status -- Show actual status if needed
                    END as reason
                FROM items i
                WHERE
                    ( -- Criteria for being waste:
                      i.status IN ('expired', 'consumed') -- CHECK NEW STATUSES
                      OR
                      -- Or currently stowed but should be waste:
                      (i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now', 'localtime'))
                      OR
                      (i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0)
                    )
                    -- Must be physically placed with complete data to be part of a retrieval plan:
                    AND i.containerId IS NOT NULL
                    AND i.positionX IS NOT NULL AND i.positionY IS NOT NULL AND i.positionZ IS NOT NULL
                    AND i.placedDimensionW IS NOT NULL AND i.placedDimensionD IS NOT NULL AND i.placedDimensionH IS NOT NULL"; // Require all dimensions


    $stmtWaste = $db->prepare($sqlWaste);
    $stmtWaste->execute();
    // *** ADDED LOGGING LINE 1 ***
    error_log("Return Plan Query executed (Checks 'expired'/'consumed'). Row count: " . $stmtWaste->rowCount());

    $allWasteItems = $stmtWaste->fetchAll(PDO::FETCH_ASSOC);
    // *** ADDED LOGGING LINE 2 ***
    if (count($allWasteItems) < 10 && count($allWasteItems) > 0) { // Log details only for small results sets
        error_log("Return Plan Fetched Waste Data (" . count($allWasteItems) . " rows): " . json_encode($allWasteItems));
    } elseif (count($allWasteItems) >= 10) {
        error_log("Return Plan Fetched " . count($allWasteItems) . " potential waste items.");
    }

    // Existing log line (kept for context)
    error_log("Return Plan: Found " . count($allWasteItems) . " total placed waste items meeting criteria.");


    // --- Early Exit if No Placed Waste Found ---
    if (empty($allWasteItems)) {
        $response['success'] = true; // Success, just nothing to do
        $response['message'] = "No placed waste items found matching criteria ('expired', 'consumed', or currently stowed & unusable) to generate a return plan.";
        // Provide empty manifest structure
        $response['returnManifest'] = [
            'undockingContainerId'=>$undockingContainerId,
            'undockingDate'=>$undockingDate,
            'returnItems'=>[],
            'totalItems' => 0, // Add explicit count
            'totalVolume'=>0.0,
            'totalWeight'=>0.0
        ];
        http_response_code(200);
        error_log("Return Plan: " . $response['message']); // Log the reason
        echo json_encode($response);
        $db = null; exit;
    }
    // --- End Identify Waste ---


    // --- Select Items for Return (Logic remains the same as your provided code) ---
    usort($allWasteItems, function($a, $b) {
        $prioA = $a['priority'] ?? 50;
        $prioB = $b['priority'] ?? 50;
        if ($prioA !== $prioB) { return $prioA <=> $prioB; }
        $expiryA = $a['expiryDate'] ?? '9999-12-31';
        $expiryB = $b['expiryDate'] ?? '9999-12-31';
        return strcmp($expiryA, $expiryB);
    });
    error_log("Return Plan: Sorted waste items by priority asc, then expiry asc.");

    $selectedWasteItems = [];
    $currentWeight = 0.0;
    $currentVolume = 0.0;
    $epsilon = 0.001;

    foreach ($allWasteItems as $item) {
        $itemMass = (float)($item['mass'] ?? 0);
        if (($currentWeight + $itemMass) <= ($maxWeight + $epsilon)) {
            // Dimensions were already checked in the SQL WHERE clause
            $selectedWasteItems[] = $item;
            $currentWeight += $itemMass;
            $itemVolume = (float)$item['dbPlacedW'] * (float)$item['dbPlacedD'] * (float)$item['dbPlacedH'];
            $currentVolume += $itemVolume;
            error_log("Return Plan: Selected item " . $item['itemId'] . " (Mass: $itemMass, Prio: " . ($item['priority']??'N/A') . ", Status: {$item['status']}). Current Weight: $currentWeight / $maxWeight");
        } else {
             error_log("Return Plan: Skipping item " . $item['itemId'] . " (Mass: $itemMass, Status: {$item['status']}) - exceeds max weight $maxWeight.");
             $response['errors'][] = ['itemId' => $item['itemId'], 'message' => 'Skipped from plan: Exceeds weight limit.'];
        }
    }

    // --- Early Exit if No Items Selected After Weight Check ---
    if (empty($selectedWasteItems)) {
        $response['success'] = true; // Still success, just nothing selected
        $response['message'] = "Found " . count($allWasteItems) . " placed waste items, but none could be selected within the " . $maxWeight . "kg weight limit.";
        $response['returnManifest'] = [
           'undockingContainerId' => $undockingContainerId,
           'undockingDate' => $undockingDate,
           'returnItems' => [],
           'totalItems' => 0,
           'totalVolume' => 0.0,
           'totalWeight' => 0.0
       ];
        http_response_code(200);
        error_log("Return Plan: " . $response['message']);
        echo json_encode($response);
        $db = null; exit;
   }
   error_log("Return Plan: Selected " . count($selectedWasteItems) . " items for return. Total Weight: $currentWeight, Total Volume: $currentVolume");
    // --- End Selection ---


    // --- Fetch All Locations for Obstruction Check (Logic remains the same) ---
    $allItemsByContainer = [];
    try {
        $sqlAll = "SELECT itemId, name, containerId,
                          positionX, positionY, positionZ,
                          placedDimensionW, placedDimensionD, placedDimensionH
                   FROM items
                   WHERE containerId IS NOT NULL
                     AND positionX IS NOT NULL AND positionY IS NOT NULL AND positionZ IS NOT NULL
                     AND placedDimensionW IS NOT NULL AND placedDimensionD IS NOT NULL AND placedDimensionH IS NOT NULL"; // Only fully placed items
        $stmtAll = $db->prepare($sqlAll);
        $stmtAll->execute();
        $allItemsResult = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allItemsResult as $item) {
             $containerId = $item['containerId'];
             if (!isset($allItemsByContainer[$containerId])) { $allItemsByContainer[$containerId] = []; }
             // Map using internal keys, converting to float early
             $allItemsByContainer[$containerId][$item['itemId']] = [
                 'id' => $item['itemId'], 'name' => $item['name'],
                 'x' => (float)$item['positionX'], 'y' => (float)$item['positionY'], 'z' => (float)$item['positionZ'],
                 'w' => (float)$item['placedDimensionW'], 'd' => (float)$item['placedDimensionD'], 'h' => (float)$item['placedDimensionH']
             ];
        }
         error_log("Return Plan: Built location map for " . count($allItemsResult) . " placed items across " . count($allItemsByContainer) . " containers.");
    } catch (PDOException $e) {
         throw new Exception("Error fetching item locations for retrieval planning: " . $e->getMessage());
    }
    // --- End Location Fetch ---


    // --- Generate Retrieval Steps (Logic remains the same as your provided code) ---
    $combinedRetrievalSteps = [];
    $masterStepCounter = 0;

    foreach ($selectedWasteItems as $itemToRetrieve) {
        $targetItemId = $itemToRetrieve['itemId'];
        $containerId = $itemToRetrieve['currentContainerId'];

        $targetItemBox = $allItemsByContainer[$containerId][$targetItemId] ?? null;

        if ($targetItemBox === null) {
            error_log("Return Plan CRITICAL Warning: Could not find location map entry for selected waste item $targetItemId in $containerId. Skipping retrieval steps.");
            $response['errors'][] = ['itemId' => $targetItemId, 'message' => 'Internal error generating retrieval steps (location missing).'];
            continue;
        }
        error_log("Return Plan: Calculating retrieval steps for waste item $targetItemId from $containerId");

        $obstructionItemsData = [];
        $itemsInSameContainer = $allItemsByContainer[$containerId] ?? [];
        $epsilon = 0.001; // Re-define epsilon if needed locally

        foreach ($itemsInSameContainer as $otherItemId => $otherItemBox) {
             if ($targetItemId === $otherItemId) continue;

             // ** Obstruction Check based on your original logic interpretation:
             // ** If 'other' X > 'target' X (other is further out/closer to front)
             // ** AND they overlap in the YZ plane (width/height on backplane)
             if ($otherItemBox['x'] > ($targetItemBox['x'] + $epsilon)) { // Other item is further out (larger X)
                 if (doBoxesOverlapYZ($targetItemBox, $otherItemBox)) { // Check overlap in YZ plane
                      error_log("Return Plan: --> Item $targetItemId is obstructed by $otherItemId (OtherX: {$otherItemBox['x']}, TargetX: {$targetItemBox['x']})");
                      $obstructionItemsData[] = [
                          'boxData' => $otherItemBox,
                          'itemId' => $otherItemId,
                          'itemName' => $otherItemBox['name']
                      ];
                 }
             }
        }
        // Sort obstructions by X descending (remove outermost/frontmost first)
        usort($obstructionItemsData, fn($a, $b) => $b['boxData']['x'] <=> $a['boxData']['x']);

        // Build Steps for this Item
        foreach ($obstructionItemsData as $obstructor) {
             $masterStepCounter++;
             $combinedRetrievalSteps[] = [
                 'step' => $masterStepCounter, 'action' => 'remove',
                 'itemId' => $obstructor['itemId'], 'itemName' => $obstructor['itemName'],
                 'containerId' => $containerId, 'position' => formatPositionForApi($obstructor['boxData'])
             ];
        }
        $masterStepCounter++;
        $combinedRetrievalSteps[] = [
            'step' => $masterStepCounter, 'action' => 'retrieve',
            'itemId' => $targetItemId, 'itemName' => $itemToRetrieve['name'],
            'containerId' => $containerId, 'position' => formatPositionForApi($targetItemBox)
        ];
        // foreach (array_reverse($obstructionItemsData) as $obstructor) { ... place back steps ... }
    }
    // --- End Generate Retrieval Steps ---


    // --- Generate Return Plan & Manifest (Logic remains the same) ---
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
            'toContainer' => $undockingContainerId
        ];
        // Use the reason determined by the initial SQL query's CASE statement
        $manifestItems[] = [
            'itemId' => $item['itemId'],
            'name' => $item['name'],
            'status' => $item['status'], // Include actual status
            'reason' => $item['reason']
        ];
    }

    $response['returnPlan'] = $returnPlanSteps;
    $response['returnManifest'] = [
        'undockingContainerId' => $undockingContainerId,
        'undockingDate' => $undockingDate,
        'returnItems' => $manifestItems,
        'totalItems' => count($manifestItems), // Added explicit count
        'totalVolume' => round($currentVolume, 3),
        'totalWeight' => round($currentWeight, 3)
    ];
    // --- End Plan & Manifest ---


    // --- Finalize Success Response (Logic remains the same) ---
    $response['success'] = true;
    $response['retrievalSteps'] = $combinedRetrievalSteps;

    if (count($selectedWasteItems) < count($allWasteItems)) {
         $response['message'] = "Return plan generated for " . count($selectedWasteItems) . " of " . count($allWasteItems) . " located waste items (limited by weight).";
    } else {
         $response['message'] = "Return plan generated for all " . count($selectedWasteItems) . " located waste items.";
    }
    if (!empty($response['errors'])) {
         $response['message'] .= " Some items were skipped or encountered errors during planning (see 'errors' array).";
    }

    http_response_code(200); // OK
    // --- End Finalize ---

} catch (Exception $e) { // Catch general exceptions or PDOExceptions
    http_response_code(500);
    $response['success'] = false;
    $response['message'] = 'Error generating return plan: ' . $e->getMessage();
    error_log("Return Plan Exception: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    $response['returnPlan'] = []; $response['retrievalSteps'] = []; $response['returnManifest'] = null;
    if (empty($response['errors'])) { $response['errors'] = [['message' => $e->getMessage()]]; }
    if (!headers_sent()) { echo json_encode($response); }
    $db = null; exit;

} finally {
    if ($db !== null) {
        $db = null;
        error_log("Return Plan API: Database connection closed in finally block.");
    }
}


// --- Final Output ---
if (!headers_sent()) {
    // Use pretty print for debugging, remove for production
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
error_log("Return Plan API: Finished.");


// --- HELPER FUNCTIONS (Preserved from your original code) ---

/**
 * Checks if two boxes overlap in the Y and Z dimensions.
 * Assumes input arrays have keys 'y', 'z', 'd', 'h'.
 * Assumes Y = Depth, Z = Height based on function name, but depends on coordinate system.
 */
function doBoxesOverlapYZ(array $box1, array $box2): bool {
    $keys = ['y', 'z', 'd', 'h'];
    foreach ($keys as $key) {
        if (!isset($box1[$key]) || !isset($box2[$key])) {
            error_log("doBoxesOverlapYZ Warning: Missing key '$key'. Box1: ".json_encode($box1)." Box2: ".json_encode($box2));
            return false;
        }
    }
    $epsilon = 0.001;
    // Check for Y overlap
    $yOverlap = ($box1['y'] < $box2['y'] + $box2['d'] - $epsilon) && ($box1['y'] + $box1['d'] > $box2['y'] + $epsilon);
    // Check for Z overlap
    $zOverlap = ($box1['z'] < $box2['z'] + $box2['h'] - $epsilon) && ($box1['z'] + $box1['h'] > $box2['z'] + $epsilon);
    return $yOverlap && $zOverlap;
}


/**
 * Checks if two boxes overlap in the X (width) and Z (height) dimensions.
 * Assumes input arrays have keys 'x', 'z', 'w', 'h'.
 */
function doBoxesOverlapXZ(array $box1, array $box2): bool {
    // Ensure necessary keys exist
    $keys = ['x', 'z', 'w', 'h'];
    foreach ($keys as $key) {
        if (!isset($box1[$key]) || !isset($box2[$key])) {
             error_log("doBoxesOverlapXZ Warning: Missing key '$key'. Box1: " . print_r($box1, true) . " Box2: " . print_r($box2, true));
             return false; // Cannot determine overlap if keys are missing
        }
    }
    $epsilon = 0.001; // Tolerance for floating point comparisons

    // Check for overlap on X axis
    $xOverlap = ($box1['x'] < $box2['x'] + $box2['w'] - $epsilon) && ($box1['x'] + $box1['w'] > $box2['x'] + $epsilon);

    // Check for overlap on Z axis
    $zOverlap = ($box1['z'] < $box2['z'] + $box2['h'] - $epsilon) && ($box1['z'] + $box1['h'] > $box2['z'] + $epsilon);

    // Overlap occurs if they overlap on BOTH X and Z axes
    return $xOverlap && $zOverlap;
}



/**
 * Formats position data from internal {x,y,z, w,d,h} to API structure {start/end Coordinates}.
 * Uses the mapping defined in your original code (x->width, y->depth, z->height).
 * Returns null if essential data is missing or not numeric.
 */
function formatPositionForApi(?array $internalBoxData): ?array {
     if ($internalBoxData === null) return null;
     $keys = ['x', 'y', 'z', 'w', 'd', 'h'];
     foreach ($keys as $key) {
         if (!isset($internalBoxData[$key]) || !is_numeric($internalBoxData[$key])) {
             error_log("formatPositionForApi: Returning null due to missing/invalid numeric key '$key' in: " . print_r($internalBoxData, true));
             return null;
         }
     }
     $startW = (float)$internalBoxData['x']; $startD = (float)$internalBoxData['y']; $startH = (float)$internalBoxData['z'];
     $placedW = (float)$internalBoxData['w']; $placedD = (float)$internalBoxData['d']; $placedH = (float)$internalBoxData['h'];
     return [
         'startCoordinates' => ['width' => $startW, 'depth' => $startD, 'height' => $startH],
         'endCoordinates' => ['width' => $startW + $placedW, 'depth' => $startD + $placedD, 'height' => $startH + $placedH]
     ];
}


?>