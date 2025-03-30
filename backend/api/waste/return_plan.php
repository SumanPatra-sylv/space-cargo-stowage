<?php // backend/api/waste/return_plan.php

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
require_once __DIR__ . '/../../database.php'; // Up two levels
error_log("Return Plan API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) { 
    error_log("Return Plan API: Failed DB connection.");
    http_response_code(500); 
    echo json_encode(['success' => false, 'message' => 'Database connection failed.', 'returnPlan' => [], 'retrievalSteps' => [], 'returnManifest' => null]);
    exit; 
}
error_log("Return Plan API: DB connection successful.");
// --- End Database Connection --- 


// --- Response Structure ---
$response = [
    'success' => false, 
    'returnPlan' => [],
    'retrievalSteps' => [],
    'returnManifest' => null, // Initialize manifest
    'message' => '',
    'errors' => [] // Keep errors array
];


// --- Handle Input ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only POST is allowed.';
    error_log("Return Plan Error: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response); $db = null; exit; 
}

$rawData = file_get_contents('php://input');
$requestData = json_decode($rawData, true); 

if ($requestData === null || !isset($requestData['undockingContainerId']) || !isset($requestData['undockingDate']) || !isset($requestData['maxWeight'])) {
    http_response_code(400); 
    $response['message'] = 'Invalid JSON input. Required: undockingContainerId, undockingDate, maxWeight.';
    error_log("Return Plan Error: Invalid JSON input: " . $rawData);
    echo json_encode($response); $db = null; exit;
}

$undockingContainerId = trim($requestData['undockingContainerId']);
$undockingDate = trim($requestData['undockingDate']); // TODO: Validate date format if needed
$maxWeight = $requestData['maxWeight'];
$userId = $requestData['userId'] ?? 'Unknown'; // Optional

if (empty($undockingContainerId) || empty($undockingDate) || !is_numeric($maxWeight) || $maxWeight < 0) {
     http_response_code(400); 
     $response['message'] = 'Invalid input values. undockingContainerId/Date cannot be empty, maxWeight must be non-negative number.';
     error_log("Return Plan Error: Invalid input values.");
     echo json_encode($response); $db = null; exit;
}
$maxWeight = (float)$maxWeight; 
error_log("Return Plan request received. Undocking Container: $undockingContainerId, Date: $undockingDate, Max Weight: $maxWeight");
// --- End Input Handling ---


try { // Wrap main logic in try block

    // --- Identify Waste Items ---
    $allWasteItems = [];
    // Use the same SQL query logic as identify.php, but ensure mass and location data is fetched
    $sqlWaste = "SELECT 
                    i.itemId, i.name, i.mass, i.priority, 
                    i.currentContainerId, i.pos_w, i.pos_d, i.pos_h, 
                    i.width, i.depth, i.height, i.status, i.usageLimit, i.remainingUses, i.expiryDate,
                    CASE
                        WHEN i.status = 'waste_expired' THEN 'Expired'
                        WHEN i.status = 'waste_depleted' THEN 'Out of Uses'
                        WHEN i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now') THEN 'Expired'
                        WHEN i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0 THEN 'Out of Uses'
                        ELSE 'Unknown' 
                    END as reason
                FROM items i
                WHERE 
                    (i.status = 'waste_expired' OR i.status = 'waste_depleted')
                    OR 
                    (i.status = 'stowed' AND i.expiryDate IS NOT NULL AND DATE(i.expiryDate) < DATE('now'))
                    OR
                    (i.status = 'stowed' AND i.usageLimit IS NOT NULL AND i.remainingUses <= 0)
                    -- Crucially, only select items that are actually placed
                    AND i.currentContainerId IS NOT NULL 
                    AND i.pos_w IS NOT NULL"; // Assumes pos_w implies others are also set if placed
                   
    $stmtWaste = $db->prepare($sqlWaste);
    $stmtWaste->execute();
    $allWasteItems = $stmtWaste->fetchAll(PDO::FETCH_ASSOC);
    error_log("Return Plan: Found " . count($allWasteItems) . " total waste items with locations.");

    if (empty($allWasteItems)) {
        $response['success'] = true;
        $response['message'] = "No located waste items found to return.";
        $response['returnManifest'] = ['undockingContainerId'=>$undockingContainerId, 'undockingDate'=>$undockingDate, 'returnItems'=>[], 'totalVolume'=>0, 'totalWeight'=>0];
        http_response_code(200); 
        echo json_encode($response); $db = null; exit;
    }
    // --- End Identify Waste ---


    // --- Select Items for Return (Greedy by Lowest Priority) ---
    usort($allWasteItems, function($a, $b) {
        $prioA = $a['priority'] ?? 50; $prioB = $b['priority'] ?? 50;
        if ($prioA !== $prioB) { return $prioA <=> $prioB; } // Lower priority first
        return 0; // Keep original order if priority is equal
    });
    error_log("Return Plan: Sorted waste items by priority ascending.");

    $selectedWasteItems = [];
    $currentWeight = 0.0;
    $currentVolume = 0.0; 

    foreach ($allWasteItems as $item) {
        $itemMass = (float)($item['mass'] ?? 0); 
        if (($currentWeight + $itemMass) <= $maxWeight) {
            $selectedWasteItems[] = $item; 
            $currentWeight += $itemMass;
            $itemVolume = (float)($item['width'] ?? 0) * (float)($item['depth'] ?? 0) * (float)($item['height'] ?? 0);
            $currentVolume += $itemVolume;
            error_log("Return Plan: Selected item " . $item['itemId'] . " (Mass: $itemMass, Prio: " . ($item['priority']??'N/A') . "). Current Weight: $currentWeight / $maxWeight");
        } else {
             error_log("Return Plan: Skipping item " . $item['itemId'] . " (Mass: $itemMass) - exceeds max weight $maxWeight.");
        }
    }
    error_log("Return Plan: Selected " . count($selectedWasteItems) . " items. Total Weight: $currentWeight, Total Volume: $currentVolume");
    // --- End Selection ---


    // --- Generate Retrieval Steps ---
    $allItemsByContainer = []; 
    try { // Fetch all item locations again
        $sqlAll = "SELECT itemId, name, currentContainerId, pos_w, pos_d, pos_h, width, depth, height FROM items WHERE currentContainerId IS NOT NULL";
        $stmtAll = $db->prepare($sqlAll);
        $stmtAll->execute();
        $allItemsResult = $stmtAll->fetchAll(PDO::FETCH_ASSOC);        
        foreach ($allItemsResult as $item) {
             $containerId = $item['currentContainerId'];
             if (!isset($allItemsByContainer[$containerId])) { $allItemsByContainer[$containerId] = []; }
             $allItemsByContainer[$containerId][$item['itemId']] = [ 
                 'id'=> $item['itemId'], 'name' => $item['name'], 
                 'x' => (float)$item['pos_w'], 'y' => (float)$item['pos_d'], 'z' => (float)$item['pos_h'], 
                 'w' => (float)$item['width'], 'd' => (float)$item['depth'], 'h' => (float)$item['height'] 
             ];
        }
         error_log("Return Plan: Fetched " . count($allItemsResult) . " locations for obstruction check.");
    } catch (PDOException $e) { 
         throw new Exception("Error fetching item locations for retrieval planning: " . $e->getMessage()); 
    }

    $combinedRetrievalSteps = [];
    $masterStepCounter = 0;

    foreach ($selectedWasteItems as $itemToRetrieve) {
        $targetItemId = $itemToRetrieve['itemId'];
        $containerId = $itemToRetrieve['currentContainerId']; 
        $itemsInSameContainer = $allItemsByContainer[$containerId] ?? [];
        $targetItemBox = $allItemsByContainer[$containerId][$targetItemId] ?? null; 

        if ($targetItemBox === null) {
            error_log("Return Plan Warning: Could not find location data for selected waste item $targetItemId in $containerId during retrieval planning.");
             $response['errors'][] = ['itemId' => $targetItemId, 'message' => 'Internal error generating retrieval steps.'];
            continue; 
        }
        error_log("Return Plan: Calculating retrieval steps for $targetItemId from $containerId");

        $obstructionItemsData = []; 
        // Obstruction Check Loop
        foreach ($itemsInSameContainer as $otherItemId => $otherItemBox) {
             if ($targetItemId === $otherItemId) { continue; } 
             $isObstruction = false;
             if ($otherItemBox['y'] < $targetItemBox['y']) { 
                  $xOverlap = ($targetItemBox['x'] < $otherItemBox['x'] + $otherItemBox['w']) && ($targetItemBox['x'] + $targetItemBox['w'] > $otherItemBox['x']);
                  $zOverlap = ($targetItemBox['z'] < $otherItemBox['z'] + $otherItemBox['h']) && ($targetItemBox['z'] + $targetItemBox['h'] > $otherItemBox['z']);
                  if ($xOverlap && $zOverlap) { $isObstruction = true; }
             }
             if ($isObstruction) {
                  error_log("Return Plan: --> Item $targetItemId is obstructed by $otherItemId");
                  $obstructionItemsData[] = ['itemId' => $otherItemId, 'itemName' => $otherItemBox['name']];
             }
        }

        // Build individual steps for this item
        foreach ($obstructionItemsData as $itemToMove) {
             $masterStepCounter++;
             $combinedRetrievalSteps[] = ['step' => $masterStepCounter, 'action' => 'remove', 'itemId' => $itemToMove['itemId'], 'itemName' => $itemToMove['itemName']]; 
             error_log("Adding step $masterStepCounter: remove " . $itemToMove['itemId']);
        }
        $masterStepCounter++;
        // **Action for return plan should arguably be 'moveToUndocking' instead of 'retrieve'**
        // Let's use 'retrieve' for now as per spec example, but consider changing if needed
        $combinedRetrievalSteps[] = ['step' => $masterStepCounter, 'action' => 'retrieve', 'itemId' => $targetItemId, 'itemName' => $itemToRetrieve['name']];
         error_log("Adding step $masterStepCounter: retrieve $targetItemId (for return plan)");
        foreach (array_reverse($obstructionItemsData) as $itemToPlaceBack) {
             $masterStepCounter++;
             $combinedRetrievalSteps[] = ['step' => $masterStepCounter, 'action' => 'placeBack', 'itemId' => $itemToPlaceBack['itemId'], 'itemName' => $itemToPlaceBack['itemName']];
             error_log("Adding step $masterStepCounter: placeBack " . $itemToPlaceBack['itemId']);
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
            'toContainer' => $undockingContainerId 
        ];
        $manifestItems[] = [
            'itemId' => $item['itemId'],
            'name' => $item['name'],
            'reason' => $item['reason'] 
        ];
    }

    $response['returnPlan'] = $returnPlanSteps;
    $response['returnManifest'] = [
        'undockingContainerId' => $undockingContainerId,
        'undockingDate' => $undockingDate, 
        'returnItems' => $manifestItems,
        'totalVolume' => round($currentVolume, 2), // Round volume
        'totalWeight' => round($currentWeight, 2)  // Round weight
    ];
    // --- End Plan & Manifest ---


    // --- Finalize Success Response ---
    $response['success'] = true;
    $response['retrievalSteps'] = $combinedRetrievalSteps; // Add combined steps
    if (empty($selectedWasteItems)) {
         $response['message'] = "No waste items could be selected within the specified weight limit.";
    } elseif (count($selectedWasteItems) < count($allWasteItems)) {
         $response['message'] = "Return plan generated for " . count($selectedWasteItems) . " waste items (limited by weight).";
    } else {
         $response['message'] = "Return plan generated for all " . count($selectedWasteItems) . " located waste items.";
    }
    http_response_code(200);
    // --- End Finalize ---

} catch (Exception $e) { // Catch general exceptions or PDOExceptions
    http_response_code(500); // Use 500 for server-side processing errors
    $response['message'] = 'Error generating return plan: ' . $e->getMessage();
    error_log("Return Plan Exception: " . $e->getMessage());
    // Ensure main arrays are empty on error
    $response['returnPlan'] = []; $response['retrievalSteps'] = []; $response['returnManifest'] = null;
} finally {
    $db = null; // Close connection
}


// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT); 
error_log("Return Plan API: Finished.");


// --- HELPER FUNCTIONS --- 

/**
 * Check if two 3D axis-aligned bounding boxes (AABB) overlap.
 */
function boxesOverlap(array $box1, array $box2): bool {
    $keys = ['x', 'y', 'z', 'w', 'd', 'h'];
    foreach ($keys as $key) { if (!isset($box1[$key]) || !isset($box2[$key])) { error_log("boxesOverlap Warning: Missing key '$key'."); return true; } }
    $noOverlapX = ($box1['x'] >= $box2['x'] + $box2['w']) || ($box2['x'] >= $box1['x'] + $box1['w']);
    $noOverlapY = ($box1['y'] >= $box2['y'] + $box2['d']) || ($box2['y'] >= $box1['y'] + $box1['d']);
    $noOverlapZ = ($box1['z'] >= $box2['z'] + $box2['h']) || ($box2['z'] >= $box1['z'] + $box1['h']);
    return !($noOverlapX || $noOverlapY || $noOverlapZ);
}

?>