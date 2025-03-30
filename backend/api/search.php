<?php // backend/api/search.php

ini_set('display_errors', 1); 
error_reporting(E_ALL); 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
// Note: No OPTIONS check needed for GET

// --- Database Connection ---
require_once __DIR__ . '/../database.php'; 
error_log("Search API: Attempting DB connection...");
$db = getDbConnection(); 

if ($db === null) {
    error_log("Search API: Failed DB connection.");
    http_response_code(500); 
    echo json_encode(['success' => false, 'found' => false, 'message' => 'Database connection failed.']);
    exit; 
}
error_log("Search API: DB connection successful.");
// --- End Database Connection --- 


// --- Response Structure ---
$response = [
    'success' => false, 
    'found' => false,
    // 'item' key added later if found
    // 'retrievalSteps' key added later if found
    'message' => ''
];


// --- Handle GET Request & Query Parameters ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); 
    $response['message'] = 'Invalid request method. Only GET is allowed.';
    error_log("Search API: Received method " . $_SERVER['REQUEST_METHOD']);
    echo json_encode($response);
    exit;
}

$itemIdSearch = $_GET['itemId'] ?? null;
$itemNameSearch = $_GET['itemName'] ?? null;
$userId = $_GET['userId'] ?? null; // Optional user ID for logging

if (empty($itemIdSearch) && empty($itemNameSearch)) {
    http_response_code(400);
    $response['message'] = 'Missing required query parameter: itemId or itemName.';
    error_log("Search API: Missing search term.");
    echo json_encode($response);
    exit;
}
error_log("Search request received. ItemID: $itemIdSearch, ItemName: $itemNameSearch, UserID: $userId");


// --- Find Matching Placed Items in DB ---
$foundItems = [];
$searchTerm = null;
$searchParam = '';
try {
    $sql = "SELECT i.itemId, i.name, i.currentContainerId, i.width, i.depth, i.height, i.pos_w, i.pos_d, i.pos_h, i.status, i.priority, i.remainingUses, i.expiryDate, i.preferredZone, c.zone as containerZone 
            FROM items i
            LEFT JOIN containers c ON i.currentContainerId = c.containerId
            WHERE i.status = 'stowed' AND i.currentContainerId IS NOT NULL"; // Only stowed items with a location

    if (!empty($itemIdSearch)) {
        $sql .= " AND i.itemId = :searchTerm";
        $searchParam = ':searchTerm';
        $searchTerm = $itemIdSearch;
    } else {
        $sql .= " AND i.name = :searchTerm"; // Exact name match
        // Or use LIKE for partial match: $sql .= " AND i.name LIKE :searchTerm";
        // $searchTerm = '%' . $itemNameSearch . '%'; // for LIKE
        $searchParam = ':searchTerm';
        $searchTerm = $itemNameSearch;
    }

    $stmt = $db->prepare($sql);
    if ($searchTerm !== null) { // Bind only if a term was set
       $stmt->bindParam($searchParam, $searchTerm);
    }
    $stmt->execute();
    $foundItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Search API: Found " . count($foundItems) . " potential matches in database.");

    if (empty($foundItems)) {
        $response['success'] = true; 
        $response['found'] = false;
        $response['message'] = "No stowed item found matching the criteria.";
        http_response_code(200); 
        echo json_encode($response);
        $db = null; // Close connection
        exit;
    }

} catch (PDOException $e) {
     http_response_code(500);
     $response['message'] = 'Error searching for items: ' . $e->getMessage();
     error_log("Search DB Error: " . $e->getMessage());
     echo json_encode($response);
     $db = null; // Close connection
     exit;
}


// --- Get ALL Placed Items (for Obstruction Calculation) ---
$allItemsByContainer = []; 
 try {
    // Select item locations and dimensions
    $sqlAll = "SELECT itemId, currentContainerId, pos_w, pos_d, pos_h, width, depth, height FROM items WHERE currentContainerId IS NOT NULL";
    $stmtAll = $db->prepare($sqlAll);
    $stmtAll->execute();
    $allItemsResult = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allItemsResult as $item) {
         $containerId = $item['currentContainerId'];
         if (!isset($allItemsByContainer[$containerId])) { $allItemsByContainer[$containerId] = []; }
         // Store using common keys for easier use later
         $allItemsByContainer[$containerId][$item['itemId']] = [ 
             'id'=> $item['itemId'],
             'x' => (float)$item['pos_w'], 'y' => (float)$item['pos_d'], 'z' => (float)$item['pos_h'], 
             'w' => (float)$item['width'], 'd' => (float)$item['depth'], 'h' => (float)$item['height'] 
         ];
    }
     error_log("Search API: Fetched all " . count($allItemsResult) . " placed item locations for obstruction check.");

} catch (PDOException $e) {
     http_response_code(500);
     $response['message'] = 'Error fetching all item placements: ' . $e->getMessage();
     error_log("Search API: Error fetching all placements - " . $e->getMessage());
     echo json_encode($response);
     $db = null;
     exit;
}


// --- Calculate Retrieval Cost and Steps for Each Found Item ---
$candidates = []; 

foreach ($foundItems as $itemInstance) {
    $targetItemId = $itemInstance['itemId'];
    $containerId = $itemInstance['currentContainerId'];
    $itemsInSameContainer = $allItemsByContainer[$containerId] ?? [];

    $targetItemBox = $allItemsByContainer[$containerId][$targetItemId] ?? null; 
    if ($targetItemBox === null) {
        error_log("Search API Warning: Target item $targetItemId not found in obstruction map for container $containerId. Skipping.");
        continue; // Data inconsistency
    }

    $obstructionCount = 0;
    $retrievalSteps = []; 
    $obstructionItems = []; // Keep track of items to move

    // Check every OTHER item in the same container
    foreach ($itemsInSameContainer as $otherItemId => $otherItemBox) {
        if ($targetItemId === $otherItemId) { continue; } // Skip self

        // Check if 'otherItem' is BLOCKING 'targetItem'
        $isObstruction = false;
        if ($otherItemBox['y'] < $targetItemBox['y']) { // 1. Is it physically in front?
             $xOverlap = ($targetItemBox['x'] < $otherItemBox['x'] + $otherItemBox['w']) && ($targetItemBox['x'] + $targetItemBox['w'] > $otherItemBox['x']);
             $zOverlap = ($targetItemBox['z'] < $otherItemBox['z'] + $otherItemBox['h']) && ($targetItemBox['z'] + $targetItemBox['h'] > $otherItemBox['z']);
             if ($xOverlap && $zOverlap) { $isObstruction = true; }
        }

        if ($isObstruction) {
            error_log("Item $targetItemId obstructed by $otherItemId");
            $obstructionCount++;
            $obstructionItems[] = $otherItemId; // Store ID of item to move
        }
    } // End foreach other item

    // Build retrieval steps array
    $stepCounter = 0;
    // Add removal steps first
    foreach ($obstructionItems as $itemIdToMove) {
         $stepCounter++;
         // Fetch item name if needed (could optimize by joining earlier)
         $itemName = $itemsInSameContainer[$itemIdToMove]['name'] ?? $itemIdToMove; // Fallback to ID
         $retrievalSteps[] = ['step' => $stepCounter, 'action' => 'remove', 'itemId' => $itemIdToMove, 'itemName' => $itemName]; 
    }
    // Add retrieve target step
    $stepCounter++;
    $retrievalSteps[] = ['step' => $stepCounter, 'action' => 'retrieve', 'itemId' => $targetItemId, 'itemName' => $itemInstance['name']];
    // Add place back steps in reverse order of removal
    $placeBackCounter = 0;
    foreach (array_reverse($obstructionItems) as $itemIdToPlaceBack) {
         $stepCounter++;
         $placeBackCounter++;
         $itemName = $itemsInSameContainer[$itemIdToPlaceBack]['name'] ?? $itemIdToPlaceBack;
         // Action 'placeBack' implies putting it back where it was (frontend might handle details)
         $retrievalSteps[] = ['step' => $stepCounter, 'action' => 'placeBack', 'itemId' => $itemIdToPlaceBack, 'itemName' => $itemName];
         error_log("Adding placeBack step $stepCounter for item $itemIdToPlaceBack");
    }

    // Store candidate details
    $expiryTimestamp = $itemInstance['expiryDate'] ? strtotime($itemInstance['expiryDate']) : PHP_INT_MAX; // Convert date to timestamp for sorting
    $candidates[] = [
        'item' => $itemInstance, 
        'obstructionCount' => $obstructionCount, 
        'retrievalSteps' => $retrievalSteps,
        'expiryScore' => $expiryTimestamp // Lower (earlier date) is better
        // Add remainingUses if needed for sorting: 'remainingUsesScore' => $itemInstance['remainingUses'] ?? PHP_INT_MAX 
    ];

} // End foreach found item instance


// --- Select the Best Candidate ---
if (empty($candidates)) {
    // This case might occur if found items somehow weren't in the allItems map
    $response['success'] = true; 
    $response['found'] = false;
    $response['message'] = "Item located, but internal error occurred calculating retrieval.";
    error_log("Search API: No valid candidates generated despite finding items initially.");
    http_response_code(500);
} else {
    // Sort candidates: Primary: obstructionCount (ASC), Secondary: expiryScore (ASC)
    usort($candidates, function($a, $b) {
        if ($a['obstructionCount'] !== $b['obstructionCount']) {
            return $a['obstructionCount'] <=> $b['obstructionCount']; // Fewer obstructions first
        }
        // Secondary sort: earlier expiry date first
        if ($a['expiryScore'] !== $b['expiryScore']) {
            return $a['expiryScore'] <=> $b['expiryScore']; 
        }
        // Optional tertiary: Lower priority items first if others equal? Or fewer remaining uses?
        // if (($a['item']['priority'] ?? 0) !== ($b['item']['priority'] ?? 0)) {
        //      return ($a['item']['priority'] ?? 0) <=> ($b['item']['priority'] ?? 0); // Lower priority first?
        // }
        return 0; // Keep original order if counts/scores are equal
    });

    $bestCandidate = $candidates[0]; // The first one after sorting is the best

    // Prepare response based on API spec
    $response['success'] = true;
    $response['found'] = true;
    $response['item'] = [
        'itemId' => $bestCandidate['item']['itemId'],
        'name' => $bestCandidate['item']['name'],
        'containerId' => $bestCandidate['item']['currentContainerId'],
        'zone' => $bestCandidate['item']['containerZone'] ?? 'Unknown', // Use zone fetched earlier
        'position' => [
            'startCoordinates' => [
                'width' => (float)$bestCandidate['item']['pos_w'],
                'depth' => (float)$bestCandidate['item']['pos_d'],
                'height' => (float)$bestCandidate['item']['pos_h']
            ],
             'endCoordinates' => [ // Use dimensions as stored in DB for this item
                'width' => (float)$bestCandidate['item']['pos_w'] + (float)$bestCandidate['item']['width'],
                'depth' => (float)$bestCandidate['item']['pos_d'] + (float)$bestCandidate['item']['depth'],
                'height' => (float)$bestCandidate['item']['pos_h'] + (float)$bestCandidate['item']['height']
            ]
        ]
        // Add other useful item details for display if needed
         // 'priority': $bestCandidate['item']['priority'],
         // 'remainingUses': $bestCandidate['item']['remainingUses'],
         // 'expiryDate': $bestCandidate['item']['expiryDate']
    ];
    // API spec uses 'retrievalSteps' at the top level
    $response['retrievalSteps'] = $bestCandidate['retrievalSteps']; 
    $response['message'] = "Best retrieval option found with " . $bestCandidate['obstructionCount'] . " obstructions.";
    http_response_code(200);
    error_log("Search API: Best candidate found: Item " . $bestCandidate['item']['itemId'] . " in " . $bestCandidate['item']['currentContainerId'] . " with " . $bestCandidate['obstructionCount'] . " obstructions.");
}

// --- Final Output ---
echo json_encode($response, JSON_PRETTY_PRINT); 
$db = null; // Close connection
error_log("Search API: Finished.");

?>